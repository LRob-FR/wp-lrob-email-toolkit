<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Support;

/**
 * HMAC-SHA256 token signer for Newsletter tracking URLs.
 *
 * Token shape (URL-safe): the first 32 characters of a base64url-encoded
 * HMAC-SHA256 of a canonical payload string. The payload is reconstructed
 * server-side from the URL query parameters; the token only proves that
 * a request to /track/{purpose}/{token} carries parameters the sender
 * actually signed. Tamper with any parameter and the recomputed HMAC
 * differs.
 *
 * The 32-character truncation gives ~190 bits of effective security
 * against forgery — plenty for an open/click endpoint where the attack
 * surface is "make our stats look weird", not "exfiltrate credentials".
 *
 * Key material: derived from AUTH_KEY via HKDF-SHA256 with the info tag
 * `lrob_etk_tracking_v1`. Separate from Encryption's `lrob_etk_v1` info
 * tag so a leaked tracking secret can't be used to decrypt credentials.
 *
 * If AUTH_KEY rotates, all in-flight tracking URLs stop validating —
 * tracking stats for already-sent newsletters become un-attributable.
 * Acceptable: AUTH_KEY rotation is rare, and the data loss is bounded
 * to "open/click events from emails sent before the rotation".
 */
final class TrackingToken
{
    private const HKDF_INFO = 'lrob_etk_tracking_v1';

    private const TOKEN_LENGTH = 32;

    /**
     * Canonical payload format. Stable: changing this is a breaking
     * change for every tracking URL in flight.
     */
    public const PURPOSE_IMAGE = 'img';

    public const PURPOSE_CLICK = 'click';

    /**
     * Sign a tracking URL's parameter bundle. Returns the URL-safe
     * token; bake it into the path component of the tracking URL.
     */
    public static function sign(
        string $purpose,
        int $newsletter_id,
        string $recipient_kind,
        int $recipient_id,
        int $item_id
    ): string {
        $payload = self::payload($purpose, $newsletter_id, $recipient_kind, $recipient_id, $item_id);
        $mac = hash_hmac('sha256', $payload, self::derive_key(), true);
        return substr(self::base64url_encode($mac), 0, self::TOKEN_LENGTH);
    }

    /**
     * Constant-time verify of a tracking token against its parameter
     * bundle. Returns true on a valid signature; false on anything else
     * (bad token, AUTH_KEY rotated, replayed across different params).
     */
    public static function verify(
        string $token,
        string $purpose,
        int $newsletter_id,
        string $recipient_kind,
        int $recipient_id,
        int $item_id
    ): bool {
        // Quick length / charset gate before the expensive HMAC.
        if (!preg_match('/^[A-Za-z0-9_\-]{' . self::TOKEN_LENGTH . '}$/', $token)) {
            return false;
        }
        try {
            $expected = self::sign($purpose, $newsletter_id, $recipient_kind, $recipient_id, $item_id);
        } catch (\RuntimeException) {
            return false;
        }
        return hash_equals($expected, $token);
    }

    /**
     * Same canonical-payload construction on both sign + verify. Keep
     * the field order + delimiter byte stable.
     */
    private static function payload(string $purpose, int $newsletter_id, string $recipient_kind, int $recipient_id, int $item_id): string
    {
        return implode('|', [
            $purpose,
            (string) $newsletter_id,
            $recipient_kind,
            (string) $recipient_id,
            (string) $item_id,
        ]);
    }

    private static function derive_key(): string
    {
        $material = defined('AUTH_KEY') ? (string) AUTH_KEY : '';
        if ($material === '' || str_contains($material, 'put your unique phrase here')) {
            throw new \RuntimeException(
                'lrob-etk: AUTH_KEY is not configured; cannot sign tracking tokens'
            );
        }
        return hash_hkdf('sha256', $material, 32, self::HKDF_INFO, '');
    }

    /**
     * URL-safe base64 (RFC 4648 §5): replace `+`/`/` and strip padding.
     */
    private static function base64url_encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
