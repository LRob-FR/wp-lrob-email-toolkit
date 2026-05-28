<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Providers;

/**
 * Shared implementation for vendor "drop-in widget + siteverify" captchas
 * (hCaptcha, Cloudflare Turnstile, Google reCAPTCHA). They differ only by a
 * handful of constants + branding; everything else — the credential fields,
 * validation, widget render (with global theme/size + auto resolution), and
 * the server-side token verification — lives here.
 *
 * Concrete providers MUST define these constants (read via late static
 * binding, and surfaced to the admin JS by reflection):
 *   - SCRIPT_URL          : vendor widget JS URL
 *   - VERIFY_URL          : vendor siteverify endpoint
 *   - POST_RESPONSE_FIELD : the form field the widget writes its token into
 *   - WIDGET_CLASS        : the container CSS class the vendor script renders into
 *   - WIDGET_GLOBAL       : the JS global exposing render() (e.g. "hcaptcha")
 *
 * …and implement slug(), label(), description(), logo_html(), vendor_label().
 */
abstract class AbstractHostedCaptcha implements ProviderInterface
{
    public const CREDENTIAL_SITE_KEY = 'site_key';

    public const CREDENTIAL_SECRET_KEY = 'secret_key';

    abstract public function slug(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    abstract public function logo_html(): string;

    /** Vendor name shown in credential-field hints, e.g. "hCaptcha". */
    abstract protected function vendor_label(): string;

    public function credential_fields(): array
    {
        return [
            [
                'key'         => self::CREDENTIAL_SITE_KEY,
                'label'       => __('Site key', 'lrob-email-toolkit'),
                'type'        => 'text',
                'required'    => true,
                'description' => sprintf(
                    /* translators: %s: provider name (e.g. hCaptcha, Cloudflare) */
                    __('Public "Site key" from your %s dashboard.', 'lrob-email-toolkit'),
                    $this->vendor_label()
                ),
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
            return $this->render_misconfigured_notice($context);
        }

        $widget_class = (string) static::WIDGET_CLASS;

        // Global appearance (theme/size) injected by CaptchaService::render.
        $display = isset($context['display']) && is_array($context['display']) ? $context['display'] : [];
        $theme = isset($display['theme']) ? (string) $display['theme'] : 'auto';
        $size  = isset($display['size'])  ? (string) $display['size']  : 'normal';
        $size_attr  = $size === 'compact' ? ' data-size="compact"' : ' data-size="normal"';
        // Native themes are light/dark; "auto" is resolved client-side below
        // from prefers-color-scheme.
        $theme_attr = ($theme === 'light' || $theme === 'dark') ? ' data-theme="' . esc_attr($theme) . '"' : '';

        $widget_html = sprintf(
            '<div class="lrob-etk-cf-field lrob-etk-form-field--challenge lrob-etk-cf-challenge" data-field="_challenge">' .
                '<div class="%1$s" data-sitekey="%2$s"%3$s%4$s></div>' .
                '<p class="lrob-etk-cf-error" data-field-error hidden></p>' .
            '</div>',
            esc_attr($widget_class),
            esc_attr($site_key),
            $size_attr,
            $theme_attr
        );

        $is_preview = isset($context['context']) && $context['context'] === 'preview';
        if ($is_preview) {
            // Editor / settings JS owns the vendor script + render call.
            return $widget_html;
        }

        // Resolve "auto" client-side: set data-theme from the visitor's OS
        // colour scheme before the (async) vendor script renders the widget.
        $auto_script = $theme === 'auto'
            ? '<script>(function(){try{var d=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches;'
                . 'var n=document.querySelectorAll(".' . esc_js($widget_class) . ':not([data-theme])");'
                . 'for(var i=0;i<n.length;i++){n[i].setAttribute("data-theme",d?"dark":"light");}}catch(e){}})();</script>'
            : '';

        return $widget_html . $auto_script . sprintf(
            '<script src="%s" async defer></script>',
            esc_url((string) static::SCRIPT_URL)
        );
    }

    public function verify(array $post, array $context = []): array
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

        $body = ['secret' => $secret, 'response' => $token];
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        if ($ip !== '') {
            $body['remoteip'] = $ip;
        }

        $response = wp_remote_post((string) static::VERIFY_URL, [
            'timeout' => 10,
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            return [false, __('Anti-spam check could not be reached. Please retry.', 'lrob-email-toolkit')];
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return [false, __('Anti-spam check failed.', 'lrob-email-toolkit')];
        }

        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($payload) || empty($payload['success'])) {
            return [false, __('Wrong answer to the anti-spam challenge.', 'lrob-email-toolkit')];
        }

        return [true, null];
    }

    /**
     * Admin-only inline notice when the site key is missing, with enough
     * diagnostics to tell apart the failure modes. Visitors see nothing;
     * verify() still fails closed.
     *
     * @param array<string, mixed> $context
     */
    private function render_misconfigured_notice(array $context): string
    {
        if (!current_user_can('manage_options')) {
            return '';
        }
        $has_creds_key = isset($context['credentials']);
        $creds_count = $has_creds_key && is_array($context['credentials']) ? count($context['credentials']) : 0;
        $diagnosis = $has_creds_key
            ? sprintf(
                /* translators: %d: number of credential keys present */
                __('CaptchaService passed %d credential key(s) but no site_key. Either the stored credentials were saved with a different key name, or decryption returned an empty array (AUTH_KEY may have changed since save).', 'lrob-email-toolkit'),
                $creds_count
            )
            : __('CaptchaService did NOT inject credentials. The routing key probably resolves to a homemade challenge slug rather than an identity row — pick a hosted-provider entry in the Captcha settings instead of the generic provider name.', 'lrob-email-toolkit');

        return '<div class="lrob-etk-cf-field lrob-etk-form-field--challenge"><p class="lrob-etk-cf-error" style="display:block;">'
            . '<strong>' . esc_html(sprintf(
                /* translators: %s: provider name */
                __('%s: site key is empty.', 'lrob-email-toolkit'),
                $this->label()
            )) . '</strong><br>'
            . esc_html($diagnosis)
            . '</p></div>';
    }
}
