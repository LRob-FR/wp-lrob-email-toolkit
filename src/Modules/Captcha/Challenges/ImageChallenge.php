<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Challenges;

use LRob\EmailToolkit\Modules\ContactForm\FormContext;

/**
 * "Pick the picture of a tree" with 6 small SVG icons. The user clicks
 * one; the server verifies the chosen position matches an HMAC-signed
 * expected position. 20 concepts in the pool — target + 5 random decoys
 * picked per render, all shuffled into 6 positions.
 *
 * SVGs are inline (no separate assets), single-color (currentColor), and
 * carry no machine-readable labels (aria-hidden) so OCR/automation can't
 * just read the answer from accessibility metadata. Trade-off: this is
 * NOT accessible to screen-reader users — admins serving such audiences
 * should pick MathChallenge instead. Admin description flags this.
 *
 * Token format: base64url("$position:$nonce") || '.' || hex(HMAC-SHA256).
 * Same signing-key fallback chain as MathChallenge.
 */
final class ImageChallenge implements ChallengeInterface
{
    public const TOKEN_FIELD = '_lrob_etk_cf_img_token';

    public const ANSWER_FIELD = '_lrob_etk_cf_img_answer';

    private const OPTION_COUNT = 6;

    public function slug(): string
    {
        return 'image';
    }

    public function label(): string
    {
        return __('Picture recognition', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __('Visitors click one icon out of six (e.g. "Click the picture of a tree"). More resistant to OCR than text, picks from a pool of 20 concepts. Not accessible to screen-reader users — pick the math challenge if accessibility matters.', 'lrob-email-toolkit');
    }

    public function render(array $context = []): string
    {
        $pool = $this->concept_pool();
        $keys = array_keys($pool);
        shuffle($keys);
        $target_key = $keys[0];
        $decoy_keys = array_slice($keys, 1, self::OPTION_COUNT - 1);
        $shuffled = array_merge([$target_key], $decoy_keys);
        shuffle($shuffled);
        $correct_position = array_search($target_key, $shuffled, true);
        if ($correct_position === false) {
            $correct_position = 0;
        }

        $nonce = bin2hex(random_bytes(8));
        $token = self::sign((int) $correct_position, $nonce);

        $instance = class_exists(FormContext::class) && FormContext::is_active()
            ? FormContext::instance()
            : substr(bin2hex(random_bytes(4)), 0, 8);
        $name = 'lrob-etk-cf-img-' . $instance;

        $options_html = '';
        foreach ($shuffled as $i => $key) {
            $svg = $pool[$key]['svg'];
            $id = $name . '-' . $i;
            $options_html .= sprintf(
                '<label class="lrob-etk-cf-image-option" for="%1$s">' .
                '<input type="radio" id="%1$s" name="%2$s" value="%3$d" required aria-required="true">' .
                '<span class="lrob-etk-cf-image-svg" aria-hidden="true">%4$s</span>' .
                '</label>',
                esc_attr($id),
                esc_attr(self::ANSWER_FIELD),
                $i,
                // SVG strings are authored in this file and contain no user input.
                $svg
            );
        }

        $prompt = sprintf(
            /* translators: %s: name of an object the user must pick (translated, lower-case noun). */
            __('Click the picture of a %s', 'lrob-email-toolkit'),
            $pool[$target_key]['label']
        );

        return sprintf(
            '<div class="lrob-etk-cf-field lrob-etk-cf-field--challenge lrob-etk-cf-challenge lrob-etk-cf-challenge--image" data-field="_challenge">' .
            '<label class="lrob-etk-cf-label">%1$s <span class="lrob-etk-cf-required" aria-hidden="true">*</span></label>' .
            '<div class="lrob-etk-cf-image-options" role="radiogroup" aria-label="%2$s">%3$s</div>' .
            '<input type="hidden" name="%4$s" value="%5$s">' .
            '<p class="lrob-etk-cf-helper">%6$s</p>' .
            '<p class="lrob-etk-cf-error" data-field-error hidden></p>' .
            '</div>',
            esc_html($prompt),
            esc_attr__('Anti-spam picture challenge', 'lrob-email-toolkit'),
            $options_html,
            esc_attr(self::TOKEN_FIELD),
            esc_attr($token),
            esc_html__('Quick anti-spam check.', 'lrob-email-toolkit')
        );
    }

    public function verify(array $post, array $context = []): array
    {
        $token = $post[self::TOKEN_FIELD] ?? '';
        $answer = $post[self::ANSWER_FIELD] ?? '';
        if (!is_string($token) || $token === '') {
            return [false, __('Anti-spam check failed.', 'lrob-email-toolkit')];
        }
        if (!is_scalar($answer) || !preg_match('/^\d{1,2}$/', (string) $answer)) {
            return [false, __('Please pick one of the pictures.', 'lrob-email-toolkit')];
        }
        $position = (int) $answer;
        if ($position < 0 || $position >= self::OPTION_COUNT) {
            return [false, __('Please pick one of the pictures.', 'lrob-email-toolkit')];
        }
        $expected = self::unsign($token);
        if ($expected === null) {
            return [false, __('Anti-spam check failed.', 'lrob-email-toolkit')];
        }
        if ($position !== $expected) {
            return [false, __('That is not the right picture. Try again.', 'lrob-email-toolkit')];
        }
        return [true, null];
    }

    private static function sign(int $position, string $nonce): string
    {
        $payload = $position . ':' . $nonce;
        $sig = hash_hmac('sha256', $payload, self::key());
        return self::b64url($payload) . '.' . $sig;
    }

    private static function unsign(string $token): ?int
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
        $bits = explode(':', $payload, 2);
        if (count($bits) !== 2 || !ctype_digit($bits[0])) {
            return null;
        }
        return (int) $bits[0];
    }

    private static function key(): string
    {
        if (defined('AUTH_KEY') && is_string(AUTH_KEY) && AUTH_KEY !== '') {
            return 'lrob_etk_captcha_img|' . AUTH_KEY;
        }
        if (defined('NONCE_SALT') && is_string(NONCE_SALT) && NONCE_SALT !== '') {
            return 'lrob_etk_captcha_img|' . NONCE_SALT;
        }
        return 'lrob_etk_captcha_img|fallback|' . (string) get_option('siteurl');
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

    /**
     * Pool of 20 distinct concepts, each with a translatable label and an
     * inline SVG silhouette. SVGs use `currentColor` so the editor's
     * accent inherits the theme, viewBox 0 0 24 24 keeps them crisp at any
     * size we display them. Adding a 21st: append here, no other changes.
     *
     * @return array<string, array{label:string, svg:string}>
     */
    private function concept_pool(): array
    {
        $svg = static fn(string $body): string =>
            '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor">' . $body . '</svg>';

        return [
            'house'  => ['label' => __('house',     'lrob-email-toolkit'), 'svg' => $svg('<path d="M2 12 L12 3 L22 12 L20 12 L20 21 L14 21 L14 14 L10 14 L10 21 L4 21 L4 12 Z"/>')],
            'tree'   => ['label' => __('tree',      'lrob-email-toolkit'), 'svg' => $svg('<path d="M12 2 L5 10 L8 10 L4 15 L9 15 L3 21 L11 21 L11 23 L13 23 L13 21 L21 21 L15 15 L20 15 L16 10 L19 10 Z"/>')],
            'sun'    => ['label' => __('sun',       'lrob-email-toolkit'), 'svg' => $svg('<circle cx="12" cy="12" r="4"/><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/><line x1="2" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="22" y2="12"/><line x1="4.5" y1="4.5" x2="6.6" y2="6.6"/><line x1="17.4" y1="17.4" x2="19.5" y2="19.5"/><line x1="4.5" y1="19.5" x2="6.6" y2="17.4"/><line x1="17.4" y1="6.6" x2="19.5" y2="4.5"/></g>')],
            'moon'   => ['label' => __('moon',      'lrob-email-toolkit'), 'svg' => $svg('<path d="M15 3 A9 9 0 1 0 21 15 A7 7 0 0 1 15 3 Z"/>')],
            'star'   => ['label' => __('star',      'lrob-email-toolkit'), 'svg' => $svg('<path d="M12 2 L14.6 9 L22 9.5 L16.2 14.2 L18.2 21.5 L12 17.3 L5.8 21.5 L7.8 14.2 L2 9.5 L9.4 9 Z"/>')],
            'heart'  => ['label' => __('heart',     'lrob-email-toolkit'), 'svg' => $svg('<path d="M12 21 C5 16 3 12 3 8.5 A4.5 4.5 0 0 1 12 7 A4.5 4.5 0 0 1 21 8.5 C21 12 19 16 12 21 Z"/>')],
            'cloud'  => ['label' => __('cloud',     'lrob-email-toolkit'), 'svg' => $svg('<path d="M7 18 A4 4 0 0 1 7 10 A5 5 0 0 1 17 9 A4 4 0 0 1 19 18 Z"/>')],
            'flag'   => ['label' => __('flag',      'lrob-email-toolkit'), 'svg' => $svg('<path d="M5 3 L7 3 L7 21 L5 21 Z M7 4 L19 4 L17 8 L19 12 L7 12 Z"/>')],
            'bell'   => ['label' => __('bell',      'lrob-email-toolkit'), 'svg' => $svg('<path d="M11 3 L13 3 L13 4.3 A6 6 0 0 1 18 10 L18 16 L20 18 L20 19 L4 19 L4 18 L6 16 L6 10 A6 6 0 0 1 11 4.3 Z M10 20 L14 20 A2 2 0 0 1 10 20 Z"/>')],
            'key'    => ['label' => __('key',       'lrob-email-toolkit'), 'svg' => $svg('<path d="M9 8 A4 4 0 1 1 9 16 A4 4 0 0 1 9 8 Z M9 10 A2 2 0 1 0 9 14 A2 2 0 0 0 9 10 Z M13 12 L22 12 L22 15 L20 15 L20 13.5 L18 13.5 L18 15 L16 15 L16 13.5 L13 13.5 Z"/>')],
            'lock'   => ['label' => __('lock',      'lrob-email-toolkit'), 'svg' => $svg('<path d="M7 10 L7 7 A5 5 0 0 1 17 7 L17 10 L15 10 L15 7 A3 3 0 0 0 9 7 L9 10 Z M5 10 L19 10 L19 21 L5 21 Z"/>')],
            'leaf'   => ['label' => __('leaf',      'lrob-email-toolkit'), 'svg' => $svg('<path d="M4 20 C4 11 11 4 20 4 C20 13 13 20 4 20 Z"/><path d="M4 20 L18 6" stroke="currentColor" stroke-width="1.5" fill="none"/>')],
            'drop'   => ['label' => __('water drop','lrob-email-toolkit'), 'svg' => $svg('<path d="M12 2 C7 9 5 13 5 16 A7 7 0 0 0 19 16 C19 13 17 9 12 2 Z"/>')],
            'fish'   => ['label' => __('fish',      'lrob-email-toolkit'), 'svg' => $svg('<path d="M2 12 L7 8 L7 10 C10 9 13 9 17 11 L21 8 L21 16 L17 13 C13 15 10 15 7 14 L7 16 Z"/><circle cx="9" cy="11.5" r="0.8" fill="#fff"/>')],
            'bird'   => ['label' => __('bird',      'lrob-email-toolkit'), 'svg' => $svg('<path d="M3 14 Q9 6 12 13 Q15 6 21 14" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>')],
            'apple'  => ['label' => __('apple',     'lrob-email-toolkit'), 'svg' => $svg('<ellipse cx="12" cy="14" rx="7" ry="6.5"/><path d="M12 7 V4 L15 5" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>')],
            'boat'   => ['label' => __('boat',      'lrob-email-toolkit'), 'svg' => $svg('<path d="M3 16 L21 16 L18 20 L6 20 Z M11 14 L11 4 L18 14 Z"/>')],
            'gift'   => ['label' => __('gift box',  'lrob-email-toolkit'), 'svg' => $svg('<path d="M3 8 L21 8 L21 12 L3 12 Z M5 12 L19 12 L19 21 L5 21 Z M11 8 L11 21 L13 21 L13 8 Z M8 8 C5 4 8 3 12 8 C16 3 19 4 16 8 Z"/>')],
            'note'   => ['label' => __('music note','lrob-email-toolkit'), 'svg' => $svg('<path d="M10 18 A2.5 2.5 0 1 1 10 18 Z M12 18 L12 6 L19 4 L19 16 A2.5 2.5 0 1 1 17 16 L17 8 L14 8.6 Z"/>')],
            'camera' => ['label' => __('camera',    'lrob-email-toolkit'), 'svg' => $svg('<path d="M3 8 L7 8 L9 5 L15 5 L17 8 L21 8 L21 19 L3 19 Z M12 9 A4 4 0 1 0 12 17 A4 4 0 0 0 12 9 Z M12 11 A2 2 0 1 1 12 15 A2 2 0 0 1 12 11 Z"/>')],
        ];
    }
}
