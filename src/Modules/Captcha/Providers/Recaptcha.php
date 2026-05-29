<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Providers;

/**
 * Google reCAPTCHA — one provider, two versions chosen per identity:
 *
 *  - v3 (default): score-based, no visible widget. The script is loaded with
 *    `?render=<site_key>`; on submit the frontend calls
 *    `grecaptcha.execute(site_key, {action})`, drops the token in a hidden
 *    field, and the server checks `success` AND `score >= threshold`.
 *  - v2: the classic checkbox / invisible widget — identical drop-in shape to
 *    hCaptcha / Turnstile, so it reuses AbstractHostedCaptcha's render/verify.
 *
 * The version + v3 score threshold are non-secret per-identity config but ride
 * inside the (encrypted) credentials blob — one JSON dict, no extra schema
 * column. Google issues *separate* site/secret key pairs for v2 vs v3, so
 * switching version means re-entering keys; that's on the admin.
 *
 * Vendor references:
 *  - v2: https://developers.google.com/recaptcha/docs/display
 *  - v3: https://developers.google.com/recaptcha/docs/v3
 *  - Verify: https://developers.google.com/recaptcha/docs/verify
 */
final class Recaptcha extends AbstractHostedCaptcha
{
    public const SLUG = 'recaptcha';

    public const POST_RESPONSE_FIELD = 'g-recaptcha-response';

    public const SCRIPT_URL = 'https://www.google.com/recaptcha/api.js';

    public const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public const WIDGET_CLASS = 'g-recaptcha';

    public const WIDGET_GLOBAL = 'grecaptcha';

    public const VERSION_V2 = 'v2';

    public const VERSION_V3 = 'v3';

    public const DEFAULT_SCORE = '0.5';

    /** reCAPTCHA v3 action name (alphanumeric / _ / / only). */
    public const V3_ACTION = 'lrob_etk_form';

    public function sort_order(): int
    {
        return 30;
    }

    /** v2 supports an invisible widget size; v3 is invisible by nature. */
    public function supports_invisible(): bool
    {
        return true;
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function label(): string
    {
        return __('Google reCAPTCHA', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __('Google\'s captcha. v3 (default) scores visitors invisibly; v2 shows the classic "I\'m not a robot" checkbox (or an invisible variant). Pick the version inside the card. Requires a matching site key + secret from the Google reCAPTCHA admin console.', 'lrob-email-toolkit');
    }

    protected function vendor_label(): string
    {
        return __('Google reCAPTCHA', 'lrob-email-toolkit');
    }

    public function credential_fields(): array
    {
        return [
            [
                'key'      => 'version',
                'label'    => __('Version', 'lrob-email-toolkit'),
                'type'     => 'select',
                'required' => true,
                'default'  => self::VERSION_V3,
                'options'  => [
                    ['value' => self::VERSION_V3, 'label' => __('v3 — invisible, score-based (recommended)', 'lrob-email-toolkit')],
                    ['value' => self::VERSION_V2, 'label' => __('v2 — checkbox / invisible widget', 'lrob-email-toolkit')],
                ],
                'description' => __('v3 is Google\'s current default. v2 and v3 use different key pairs — re-enter keys if you switch.', 'lrob-email-toolkit'),
            ],
            [
                'key'         => self::CREDENTIAL_SITE_KEY,
                'label'       => __('Site key', 'lrob-email-toolkit'),
                'type'        => 'text',
                'required'    => true,
                'description' => __('Public "Site key" from your Google reCAPTCHA admin console.', 'lrob-email-toolkit'),
            ],
            [
                'key'         => self::CREDENTIAL_SECRET_KEY,
                'label'       => __('Secret key', 'lrob-email-toolkit'),
                'type'        => 'password',
                'required'    => true,
                'description' => __('Private "Secret key", stored AES-256-encrypted at rest.', 'lrob-email-toolkit'),
            ],
            [
                'key'         => 'score_threshold',
                'label'       => __('Minimum score', 'lrob-email-toolkit'),
                'type'        => 'number',
                'required'    => false,
                'default'     => self::DEFAULT_SCORE,
                'min'         => '0',
                'max'         => '1',
                'step'        => '0.1',
                'description' => __('Reject submissions scoring below this. 0 = lax, 1 = strict; 0.5 is a sensible default.', 'lrob-email-toolkit'),
            ],
        ];
    }

    public function validate_credentials(array $values): array
    {
        $errors = [];
        $clean = [];

        $version = isset($values['version']) ? trim((string) $values['version']) : self::VERSION_V3;
        if (!in_array($version, [self::VERSION_V2, self::VERSION_V3], true)) {
            $version = self::VERSION_V3;
        }

        $site = isset($values[self::CREDENTIAL_SITE_KEY]) ? trim((string) $values[self::CREDENTIAL_SITE_KEY]) : '';
        if ($site === '') {
            $errors[self::CREDENTIAL_SITE_KEY] = __('Site key is required.', 'lrob-email-toolkit');
        }

        $secret = isset($values[self::CREDENTIAL_SECRET_KEY]) ? trim((string) $values[self::CREDENTIAL_SECRET_KEY]) : '';
        if ($secret === '') {
            $errors[self::CREDENTIAL_SECRET_KEY] = __('Secret key is required.', 'lrob-email-toolkit');
        }

        $score = isset($values['score_threshold']) ? trim((string) $values['score_threshold']) : '';
        if ($score !== '') {
            $score_f = (float) $score;
            if ($score_f < 0 || $score_f > 1) {
                $errors['score_threshold'] = __('Score must be between 0 and 1.', 'lrob-email-toolkit');
            }
        }

        if ($errors === []) {
            $clean['version'] = $version;
            $clean[self::CREDENTIAL_SITE_KEY] = $site;
            $clean[self::CREDENTIAL_SECRET_KEY] = $secret;
            $clean['score_threshold'] = $score !== '' ? $score : self::DEFAULT_SCORE;
        }

        return ['credentials' => $clean, 'errors' => $errors];
    }

    public function render(array $context = []): string
    {
        if ($this->version_of($context) === self::VERSION_V3) {
            return $this->render_v3($context);
        }
        return parent::render($context);
    }

    public function verify(array $post, array $context = []): array
    {
        if ($this->version_of($context) === self::VERSION_V3) {
            return $this->verify_v3($post, $context);
        }
        return parent::verify($post, $context);
    }

    /** @param array<string, mixed> $context */
    private function version_of(array $context): string
    {
        $creds = isset($context['credentials']) && is_array($context['credentials']) ? $context['credentials'] : [];
        $version = isset($creds['version']) ? (string) $creds['version'] : self::VERSION_V3;
        return $version === self::VERSION_V2 ? self::VERSION_V2 : self::VERSION_V3;
    }

    /** @param array<string, mixed> $context */
    private function render_v3(array $context): string
    {
        $creds = isset($context['credentials']) && is_array($context['credentials']) ? $context['credentials'] : [];
        $site_key = isset($creds[self::CREDENTIAL_SITE_KEY]) && is_string($creds[self::CREDENTIAL_SITE_KEY])
            ? $creds[self::CREDENTIAL_SITE_KEY]
            : '';
        if ($site_key === '') {
            return $this->render_misconfigured_notice($context);
        }

        $is_preview = isset($context['context']) && $context['context'] === 'preview';
        if ($is_preview) {
            // v3 has no visible widget; tell the admin what it does instead.
            return '<div class="lrob-etk-cf-field lrob-etk-form-field--challenge lrob-etk-cf-challenge"><p class="lrob-etk-cf-helper">'
                . esc_html__('reCAPTCHA v3 runs invisibly — no widget is shown; a risk score is checked when the form is submitted.', 'lrob-email-toolkit')
                . '</p></div>';
        }

        // The hidden field receives the token from grecaptcha.execute() (driven
        // by form-submit.js on submit); the markers tell that script how.
        $field = sprintf(
            '<div class="lrob-etk-cf-field lrob-etk-form-field--challenge lrob-etk-cf-challenge lrob-etk-cf-challenge--v3" data-field="_challenge"'
                . ' data-lrob-etk-recaptcha-v3="1" data-sitekey="%1$s" data-action="%2$s">'
                . '<input type="hidden" name="%3$s" value="">'
                . '<p class="lrob-etk-cf-error" data-field-error hidden></p>'
            . '</div>',
            esc_attr($site_key),
            esc_attr(self::V3_ACTION),
            esc_attr((string) static::POST_RESPONSE_FIELD)
        );

        return $field . sprintf(
            '<script src="%s" async defer></script>',
            esc_url(self::SCRIPT_URL . '?render=' . rawurlencode($site_key))
        );
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $context
     * @return array{0: bool, 1: ?string}
     */
    private function verify_v3(array $post, array $context): array
    {
        $field = (string) static::POST_RESPONSE_FIELD;
        $token = isset($post[$field]) && is_string($post[$field]) ? $post[$field] : '';
        if ($token === '') {
            return [false, __('Please complete the anti-spam challenge.', 'lrob-email-toolkit')];
        }

        $creds = isset($context['credentials']) && is_array($context['credentials']) ? $context['credentials'] : [];
        $secret = isset($creds[self::CREDENTIAL_SECRET_KEY]) && is_string($creds[self::CREDENTIAL_SECRET_KEY])
            ? $creds[self::CREDENTIAL_SECRET_KEY]
            : '';
        if ($secret === '') {
            return [false, __('Anti-spam check is misconfigured (missing secret key).', 'lrob-email-toolkit')];
        }

        [$payload, $error] = $this->siteverify($secret, $token);
        if ($payload === null) {
            return [false, $error];
        }
        if (empty($payload['success'])) {
            return [false, __('Anti-spam verification failed.', 'lrob-email-toolkit')];
        }

        $score = isset($payload['score']) ? (float) $payload['score'] : 0.0;
        if ($score < $this->threshold_from($creds)) {
            // Score below threshold = bot-likely. Generic message — don't leak the score.
            return [false, __('Your submission looks automated. Please try again.', 'lrob-email-toolkit')];
        }

        return [true, null];
    }

    /**
     * Admin-only: run a real v3 siteverify and surface the raw score so the
     * settings page can show "score 0.9 — pass at 0.5" and let the admin
     * calibrate the threshold + confirm the keys without a live form.
     *
     * @param array<string, string> $credentials
     * @return array{ok: bool, score: float, threshold: float, error: ?string}
     */
    public function test_score(string $token, array $credentials): array
    {
        $threshold = $this->threshold_from($credentials);
        $secret = isset($credentials[self::CREDENTIAL_SECRET_KEY]) ? (string) $credentials[self::CREDENTIAL_SECRET_KEY] : '';
        if ($token === '') {
            return ['ok' => false, 'score' => 0.0, 'threshold' => $threshold, 'error' => __('No token to test.', 'lrob-email-toolkit')];
        }
        if ($secret === '') {
            return ['ok' => false, 'score' => 0.0, 'threshold' => $threshold, 'error' => __('Missing secret key.', 'lrob-email-toolkit')];
        }

        [$payload, $error] = $this->siteverify($secret, $token);
        if ($payload === null) {
            return ['ok' => false, 'score' => 0.0, 'threshold' => $threshold, 'error' => $error];
        }
        if (empty($payload['success'])) {
            $codes = isset($payload['error-codes']) && is_array($payload['error-codes'])
                ? implode(', ', array_map('strval', $payload['error-codes']))
                : __('verification failed', 'lrob-email-toolkit');
            return ['ok' => false, 'score' => 0.0, 'threshold' => $threshold, 'error' => $codes];
        }

        $score = isset($payload['score']) ? (float) $payload['score'] : 0.0;
        return ['ok' => $score >= $threshold, 'score' => $score, 'threshold' => $threshold, 'error' => null];
    }

    /** @param array<string, mixed> $credentials */
    private function threshold_from(array $credentials): float
    {
        return isset($credentials['score_threshold']) && $credentials['score_threshold'] !== ''
            ? (float) $credentials['score_threshold']
            : (float) self::DEFAULT_SCORE;
    }

    /**
     * POST to Google's siteverify. Returns [decoded payload, null] on a clean
     * 200 JSON response, or [null, translated error] otherwise.
     *
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function siteverify(string $secret, string $token): array
    {
        $body = ['secret' => $secret, 'response' => $token];
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        if ($ip !== '') {
            $body['remoteip'] = $ip;
        }

        $response = wp_remote_post(self::VERIFY_URL, ['timeout' => 10, 'body' => $body]);
        if (is_wp_error($response)) {
            return [null, __('Anti-spam check could not be reached. Please retry.', 'lrob-email-toolkit')];
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return [null, __('Anti-spam check failed.', 'lrob-email-toolkit')];
        }
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($payload)) {
            return [null, __('Anti-spam verification failed.', 'lrob-email-toolkit')];
        }
        return [$payload, null];
    }

    public function logo_html(): string
    {
        // Google "G" — the official four-colour mark, instantly recognizable.
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="20" height="20" aria-hidden="true" focusable="false">'
            . '<path fill="#4285F4" d="M45.12 24.5c0-1.56-.14-3.06-.4-4.5H24v8.51h11.84c-.51 2.75-2.06 5.08-4.39 6.64v5.52h7.11c4.16-3.83 6.56-9.47 6.56-16.17z"/>'
            . '<path fill="#34A853" d="M24 46c5.94 0 10.92-1.97 14.56-5.33l-7.11-5.52c-1.97 1.32-4.49 2.1-7.45 2.1-5.73 0-10.58-3.87-12.31-9.07H4.34v5.7C7.96 41.07 15.4 46 24 46z"/>'
            . '<path fill="#FBBC05" d="M11.69 28.18C11.25 26.86 11 25.45 11 24s.25-2.86.69-4.18v-5.7H4.34C2.85 17.09 2 20.45 2 24s.85 6.91 2.34 9.88l7.35-5.7z"/>'
            . '<path fill="#EA4335" d="M24 10.75c3.23 0 6.13 1.11 8.41 3.29l6.31-6.31C34.91 4.18 29.93 2 24 2 15.4 2 7.96 6.93 4.34 14.12l7.35 5.7c1.73-5.2 6.58-9.07 12.31-9.07z"/>'
            . '</svg>';
    }
}
