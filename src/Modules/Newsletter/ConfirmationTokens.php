<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Stateless HMAC-signed tokens for the confirm / refuse URLs sent in
 * double-opt-in emails. Format: `<subscriber_id>.<base64url(hmac)>`.
 *
 *   - Subject of the HMAC is `<id>:<action>` where action is 'confirm'
 *     or 'refuse'. Two distinct token families per subscriber so a
 *     confirm token can't be replayed as a refuse and vice versa.
 *   - Secret derives from AUTH_KEY via HKDF-SHA256 with a newsletter-
 *     specific info tag. Rotating AUTH_KEY invalidates every
 *     outstanding token — acceptable since the recipient can request
 *     a new confirmation by re-signing up.
 *   - 30-byte signature truncated to 22 base64url chars. Plenty of
 *     entropy to make brute-force impossible within the token's life;
 *     short enough that the URL stays paste-friendly.
 */
final class ConfirmationTokens
{
    public const ACTION_CONFIRM = 'confirm';

    public const ACTION_REFUSE  = 'refuse';

    public static function generate(int $subscriber_id, string $action): string
    {
        if ($subscriber_id <= 0) {
            return '';
        }
        $sig = self::sign($subscriber_id, $action);
        return $subscriber_id . '.' . $sig;
    }

    /**
     * Verify a token + action and return the subscriber id it points
     * at, or 0 on any failure (bad format, bad signature, unknown
     * action). hash_equals guards against timing attacks even though
     * the secret is short.
     */
    public static function verify(string $token, string $action): int
    {
        if ($token === '') {
            return 0;
        }
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return 0;
        }
        $subscriber_id = (int) $parts[0];
        if ($subscriber_id <= 0) {
            return 0;
        }
        $given_sig = (string) $parts[1];
        $expected_sig = self::sign($subscriber_id, $action);
        if (!hash_equals($expected_sig, $given_sig)) {
            return 0;
        }
        return $subscriber_id;
    }

    private static function sign(int $subscriber_id, string $action): string
    {
        $secret = self::secret();
        $material = $subscriber_id . ':' . $action;
        $hmac = hash_hmac('sha256', $material, $secret, true);
        return rtrim(strtr(base64_encode($hmac), '+/', '-_'), '=');
    }

    /**
     * Derive a stable per-deployment secret from AUTH_KEY. Falls back
     * to NONCE_SALT if AUTH_KEY is the WP-default placeholder, and
     * raises if neither is configured — the URLs would be forgeable
     * otherwise.
     */
    private static function secret(): string
    {
        $material = defined('AUTH_KEY') ? (string) AUTH_KEY : '';
        if ($material === '' || str_contains($material, 'put your unique phrase here')) {
            $material = defined('NONCE_SALT') ? (string) NONCE_SALT : '';
        }
        if ($material === '' || str_contains($material, 'put your unique phrase here')) {
            // Should not happen on a real install. Throw rather than
            // sign with a known-weak secret — the URLs would be
            // forgeable, defeating the whole point of HMAC.
            throw new \RuntimeException('lrob-etk-nl: cannot derive token secret — AUTH_KEY/NONCE_SALT not configured.');
        }
        return hash_hkdf('sha256', $material, 32, 'lrob_etk_nl_confirm_v1', '');
    }
}
