<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Providers;

/**
 * hCaptcha provider — privacy-respecting alternative to Google reCAPTCHA.
 *
 * Ships the standard visible "checkbox" widget. Invisible / passive variants
 * may be added later as a per-identity option (see project memory
 * [[project-captcha-architecture-next]]).
 *
 * The site key + secret key are stored in the identity's encrypted credentials
 * (Captcha\Identity::decrypted_credentials) and reach this provider via
 * $context['credentials'] (injected by CaptchaService::resolve).
 *
 * Vendor references:
 *  - Widget: https://docs.hcaptcha.com/configuration
 *  - Verify: https://docs.hcaptcha.com/#verify-the-user-response-server-side
 */
final class HCaptcha implements ProviderInterface
{
    public const SLUG = 'hcaptcha';

    public const CREDENTIAL_SITE_KEY = 'site_key';

    public const CREDENTIAL_SECRET_KEY = 'secret_key';

    public const POST_RESPONSE_FIELD = 'h-captcha-response';

    public const SCRIPT_URL = 'https://js.hcaptcha.com/1/api.js';

    public const VERIFY_URL = 'https://api.hcaptcha.com/siteverify';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function label(): string
    {
        return __('hCaptcha', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __('Privacy-respecting hosted captcha. Visitors see a checkbox widget; bots are filtered by hCaptcha\'s risk engine. Requires a free or paid hCaptcha account.', 'lrob-email-toolkit');
    }

    public function logo_html(): string
    {
        // hCaptcha brand mark — stylized "h" inside a rounded rectangle in
        // their primary blue (#0074bf). Sized for the card chip; inherits no
        // text color so the mark stays on-brand even on dark admin themes.
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" width="20" height="20" aria-hidden="true" focusable="false">'
            . '<rect width="36" height="36" rx="6" fill="#0074bf"/>'
            . '<path d="M10 8v20M10 18h16M26 18v10" stroke="#fff" stroke-width="3.2" stroke-linecap="round" fill="none"/>'
            . '</svg>';
    }

    public function credential_fields(): array
    {
        return [
            [
                'key'         => self::CREDENTIAL_SITE_KEY,
                'label'       => __('Site key', 'lrob-email-toolkit'),
                'type'        => 'text',
                'required'    => true,
                'description' => __('Public "Site key" from your hCaptcha dashboard.', 'lrob-email-toolkit'),
            ],
            [
                'key'         => self::CREDENTIAL_SECRET_KEY,
                'label'       => __('Secret key', 'lrob-email-toolkit'),
                'type'        => 'password',
                'required'    => true,
                'description' => __('Private "Secret key", stored AES-256-encrypted at rest.', 'lrob-email-toolkit'),
            ],
        ];
    }

    public function validate_credentials(array $values): array
    {
        $errors = [];
        $clean = [];

        $site = isset($values[self::CREDENTIAL_SITE_KEY]) ? trim($values[self::CREDENTIAL_SITE_KEY]) : '';
        if ($site === '') {
            $errors[self::CREDENTIAL_SITE_KEY] = __('Site key is required.', 'lrob-email-toolkit');
        }

        $secret = isset($values[self::CREDENTIAL_SECRET_KEY]) ? trim($values[self::CREDENTIAL_SECRET_KEY]) : '';
        if ($secret === '') {
            $errors[self::CREDENTIAL_SECRET_KEY] = __('Secret key is required.', 'lrob-email-toolkit');
        }

        if ($errors === []) {
            $clean[self::CREDENTIAL_SITE_KEY] = $site;
            $clean[self::CREDENTIAL_SECRET_KEY] = $secret;
        }

        return ['credentials' => $clean, 'errors' => $errors];
    }

    public function render(array $context = []): string
    {
        $creds = isset($context['credentials']) && is_array($context['credentials']) ? $context['credentials'] : [];
        $site_key = isset($creds[self::CREDENTIAL_SITE_KEY]) && is_string($creds[self::CREDENTIAL_SITE_KEY])
            ? $creds[self::CREDENTIAL_SITE_KEY]
            : '';

        if ($site_key === '') {
            // Misconfigured identity — render an inline notice for admins
            // (visible to logged-in admins only) and nothing for anonymous
            // visitors. verify() will still fail closed. We surface enough
            // diagnostics to tell apart the three failure modes:
            //   1. route doesn't carry credentials (homemade:hcaptcha bug),
            //   2. identity row exists but credentials_encrypted is '',
            //   3. AUTH_KEY changed so the stored blob no longer decrypts.
            if (current_user_can('manage_options')) {
                $has_creds_key = isset($context['credentials']);
                $creds_count = $has_creds_key && is_array($context['credentials']) ? count($context['credentials']) : 0;
                $diagnosis = $has_creds_key
                    ? sprintf(
                        /* translators: %d: number of credential keys present */
                        __('CaptchaService passed %d credential key(s) but no site_key. Either the stored credentials were saved with a different key name, or decryption returned an empty array (AUTH_KEY may have changed since save).', 'lrob-email-toolkit'),
                        $creds_count
                    )
                    : __('CaptchaService did NOT inject credentials. The routing key probably resolves to a homemade challenge slug rather than an identity row — pick an "hCaptcha · &lt;name&gt;" entry in the Captcha settings instead of the generic provider name.', 'lrob-email-toolkit');
                return '<div class="lrob-etk-cf-field lrob-etk-form-field--challenge"><p class="lrob-etk-cf-error" style="display:block;">'
                    . '<strong>' . esc_html__('hCaptcha: site key is empty.', 'lrob-email-toolkit') . '</strong><br>'
                    . esc_html($diagnosis)
                    . '</p></div>';
            }
            return '';
        }

        // Admin preview emits the same widget shape as the frontend — the
        // editor JS (form-fields-editor + captcha-admin) loads the
        // vendor script and calls hcaptcha.render() on the injected divs.
        // hCaptcha supports multiple widgets on the same page, so several
        // form-card previews can each carry their own live widget. The
        // <script> tag is dropped for preview-emitted HTML since browsers
        // don't execute scripts injected via innerHTML; loading is handled
        // by the editor JS instead.
        $is_preview = isset($context['context']) && $context['context'] === 'preview';
        if ($is_preview && $site_key === '') {
            // Identity registered but no credentials yet — used by the
            // option list for "Configure hCaptcha first" entries.
            return '<div class="lrob-etk-cf-field lrob-etk-form-field--challenge lrob-etk-hcaptcha-preview" data-field="_challenge">' .
                '<div class="lrob-etk-hcaptcha-preview-box">' .
                    '<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>' .
                    '<span>' . esc_html__('hCaptcha widget will appear here', 'lrob-email-toolkit') . '</span>' .
                '</div>' .
            '</div>';
        }

        $widget_html = sprintf(
            '<div class="lrob-etk-cf-field lrob-etk-form-field--challenge lrob-etk-cf-challenge lrob-etk-hcaptcha" data-field="_challenge">' .
                '<div class="h-captcha" data-sitekey="%s"></div>' .
                '<p class="lrob-etk-cf-error" data-field-error hidden></p>' .
            '</div>',
            esc_attr($site_key)
        );

        if ($is_preview) {
            // Editor JS is responsible for the vendor script + render call.
            return $widget_html;
        }

        return $widget_html . sprintf(
            '<script src="%s" async defer></script>',
            esc_url(self::SCRIPT_URL)
        );
    }

    public function verify(array $post, array $context = []): array
    {
        $token = isset($post[self::POST_RESPONSE_FIELD]) && is_string($post[self::POST_RESPONSE_FIELD])
            ? $post[self::POST_RESPONSE_FIELD]
            : '';
        if ($token === '') {
            return [false, __('Please complete the hCaptcha challenge.', 'lrob-email-toolkit')];
        }

        $creds = isset($context['credentials']) && is_array($context['credentials']) ? $context['credentials'] : [];
        $secret = isset($creds[self::CREDENTIAL_SECRET_KEY]) && is_string($creds[self::CREDENTIAL_SECRET_KEY])
            ? $creds[self::CREDENTIAL_SECRET_KEY]
            : '';
        if ($secret === '') {
            return [false, __('Anti-spam check is misconfigured (missing secret key).', 'lrob-email-toolkit')];
        }

        $body = [
            'secret'   => $secret,
            'response' => $token,
        ];
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        if ($ip !== '') {
            $body['remoteip'] = $ip;
        }

        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 10,
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            return [false, __('Anti-spam check could not be reached. Please retry.', 'lrob-email-toolkit')];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return [false, __('Anti-spam check failed.', 'lrob-email-toolkit')];
        }

        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($payload) || empty($payload['success'])) {
            return [false, __('Wrong answer to the anti-spam challenge.', 'lrob-email-toolkit')];
        }

        return [true, null];
    }
}
