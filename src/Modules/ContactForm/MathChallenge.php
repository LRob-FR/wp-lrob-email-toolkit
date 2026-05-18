<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Trivial arithmetic challenge: "How much is a + b?" with a stateless,
 * HMAC-signed token. The expected answer is NOT sent to the client; the
 * server recomputes it from the token's a/b values and compares.
 *
 * Picks variety in the operation (addition / subtraction / multiplication
 * with small operands) so the prompt isn't always the same. Operands chosen
 * so the answer is always a single non-negative integer ≤ 20.
 *
 * Token format: base64url( "$a:$b:$op" ) || '.' || base64url(HMAC-SHA256)
 * Signing key: AUTH_KEY (already required by Support\Encryption). If AUTH_KEY
 * is unset we silently fall back to NONCE_SALT, then to a constant — the
 * challenge becomes weak but doesn't crash the form. (Same posture as
 * wp_create_nonce.)
 */
final class MathChallenge
{
    public const TOKEN_FIELD = '_lrob_etk_cf_math_token';

    public const ANSWER_FIELD = '_lrob_etk_cf_math_answer';

    public static function render(): string
    {
        [$a, $b, $op] = self::pick();
        $token = self::sign($a, $b, $op);
        $prompt = self::prompt_text($a, $b, $op);

        return sprintf(
            '<div class="lrob-etk-cf-field lrob-etk-cf-field--challenge lrob-etk-cf-challenge" data-field="_challenge">' .
            '<label class="lrob-etk-cf-label" for="%1$s">%2$s <span class="lrob-etk-cf-required" aria-hidden="true">*</span></label>' .
            '<input type="text" inputmode="numeric" id="%1$s" name="%3$s" autocomplete="off" required aria-required="true" maxlength="3" pattern="[0-9-]*">' .
            '<input type="hidden" name="%4$s" value="%5$s">' .
            '<p class="lrob-etk-cf-helper">%6$s</p>' .
            '<p class="lrob-etk-cf-error" data-field-error hidden></p>' .
            '</div>',
            esc_attr('lrob-etk-cf-challenge-' . FormContext::instance()),
            esc_html($prompt),
            esc_attr(self::ANSWER_FIELD),
            esc_attr(self::TOKEN_FIELD),
            esc_attr($token),
            esc_html__('Quick anti-spam check.', 'lrob-email-toolkit')
        );
    }

    /** @return array{0:bool, 1:?string} success flag + error message */
    public static function verify(array $post): array
    {
        $answer_raw = $post[self::ANSWER_FIELD] ?? '';
        $token = $post[self::TOKEN_FIELD] ?? '';
        if (!is_string($answer_raw) || !is_string($token) || $token === '') {
            return [false, __('Anti-spam check failed.', 'lrob-email-toolkit')];
        }
        $answer_raw = trim($answer_raw);
        if ($answer_raw === '' || !preg_match('/^-?\d{1,3}$/', $answer_raw)) {
            return [false, __('Please answer the anti-spam question.', 'lrob-email-toolkit')];
        }

        $parsed = self::unsign($token);
        if ($parsed === null) {
            return [false, __('Anti-spam check failed.', 'lrob-email-toolkit')];
        }
        [$a, $b, $op] = $parsed;
        $expected = self::compute($a, $b, $op);
        if ((int) $answer_raw !== $expected) {
            return [false, __('Wrong answer to the anti-spam question.', 'lrob-email-toolkit')];
        }
        return [true, null];
    }

    /** @return array{0:int, 1:int, 2:string} */
    private static function pick(): array
    {
        $ops = ['+', '-', '×'];
        $op = $ops[random_int(0, 2)];
        return match ($op) {
            '+' => [random_int(1, 9), random_int(1, 9), '+'],
            '-' => (static function (): array {
                $a = random_int(2, 12);
                $b = random_int(1, $a - 1);
                return [$a, $b, '-'];
            })(),
            '×' => [random_int(2, 5), random_int(2, 5), '×'],
            default => [random_int(1, 9), random_int(1, 9), '+'],
        };
    }

    private static function compute(int $a, int $b, string $op): int
    {
        return match ($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '×' => $a * $b,
            default => 0,
        };
    }

    private static function prompt_text(int $a, int $b, string $op): string
    {
        return sprintf(
            /* translators: %1$d and %2$d are small integers, %3$s is the math operator (+, -, ×). */
            __('How much is %1$d %3$s %2$d?', 'lrob-email-toolkit'),
            $a,
            $b,
            $op
        );
    }

    private static function sign(int $a, int $b, string $op): string
    {
        $payload = $a . ':' . $b . ':' . $op;
        $sig = hash_hmac('sha256', $payload, self::key());
        return self::b64url($payload) . '.' . $sig;
    }

    /** @return array{0:int, 1:int, 2:string}|null */
    private static function unsign(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload_b64, $sig] = $parts;
        $payload = self::b64url_decode($payload_b64);
        if ($payload === null) {
            return null;
        }
        $expected = hash_hmac('sha256', $payload, self::key());
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $bits = explode(':', $payload);
        if (count($bits) !== 3) {
            return null;
        }
        [$a, $b, $op] = $bits;
        if (!ctype_digit($a) || !ctype_digit($b) || !in_array($op, ['+', '-', '×'], true)) {
            return null;
        }
        return [(int) $a, (int) $b, $op];
    }

    private static function key(): string
    {
        if (defined('AUTH_KEY') && is_string(AUTH_KEY) && AUTH_KEY !== '') {
            return 'lrob_etk_cf|' . AUTH_KEY;
        }
        if (defined('NONCE_SALT') && is_string(NONCE_SALT) && NONCE_SALT !== '') {
            return 'lrob_etk_cf|' . NONCE_SALT;
        }
        return 'lrob_etk_cf|fallback|' . (string) get_option('siteurl');
    }

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $s): ?string
    {
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($s, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
