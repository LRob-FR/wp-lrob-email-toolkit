<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Captcha\Appearance;
use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\Captcha\Identity;
use LRob\EmailToolkit\Modules\Captcha\IdentityRepository;
use LRob\EmailToolkit\Modules\Captcha\Providers\ProviderInterface;
use LRob\EmailToolkit\Modules\Captcha\Routing;

/**
 * admin-ajax endpoints for the Captcha settings page. One shared nonce
 * (lrob_etk_captcha_ajax); endpoints distinguish themselves by WP-AJAX
 * action name.
 *
 * Returns JSON; the SettingsPage JS auto-saves identity cards on blur and
 * routing dropdowns on change.
 */
final class AjaxController
{
    public const NONCE_ACTION = 'lrob_etk_captcha_ajax';

    public const ACTION_SAVE_IDENTITY   = 'lrob_etk_captcha_save_identity';

    public const ACTION_DELETE_IDENTITY = 'lrob_etk_captcha_delete_identity';

    public const ACTION_SAVE_ROUTING    = 'lrob_etk_captcha_save_routing';

    public const ACTION_TEST_IDENTITY   = 'lrob_etk_captcha_test_identity';

    public const ACTION_SET_DEFAULT     = 'lrob_etk_captcha_set_default';

    public function __construct(
        private CaptchaService $service,
        private IdentityRepository $identities,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_SAVE_IDENTITY,   [$this, 'ajax_save_identity']);
        add_action('wp_ajax_' . self::ACTION_DELETE_IDENTITY, [$this, 'ajax_delete_identity']);
        add_action('wp_ajax_' . self::ACTION_SAVE_ROUTING,    [$this, 'ajax_save_routing']);
        add_action('wp_ajax_' . self::ACTION_TEST_IDENTITY,   [$this, 'ajax_test_identity']);
        add_action('wp_ajax_' . self::ACTION_SET_DEFAULT,     [$this, 'ajax_set_default']);
    }

    /**
     * Verify a captcha token against a saved identity's credentials. The
     * admin solves the captcha widget rendered inside the card; JS forwards
     * the resulting token here for siteverify. This is the "Captcha works"
     * round-trip check.
     */
    public function ajax_test_identity(): void
    {
        $this->guard();

        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        $token = $this->post_str('token');
        if ($id <= 0 || $token === '') {
            wp_send_json_error(['message' => __('Solve the captcha first to run a test.', 'lrob-email-toolkit')]);
        }

        $identity = $this->identities->find($id);
        if ($identity === null) {
            wp_send_json_error(['message' => __('Identity not found.', 'lrob-email-toolkit')]);
        }

        $providers = $this->service->hosted_providers();
        if (!isset($providers[$identity->provider_slug])) {
            wp_send_json_error(['message' => __('Provider class not available.', 'lrob-email-toolkit')]);
        }
        $provider = $providers[$identity->provider_slug];

        try {
            $credentials = $identity->decrypted_credentials();
        } catch (\RuntimeException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        // hCaptcha's response field name is provider-specific; we look it up
        // from the provider class so this stays generic for future providers.
        $response_field = defined($provider::class . '::POST_RESPONSE_FIELD')
            ? constant($provider::class . '::POST_RESPONSE_FIELD')
            : 'token';
        $post = [$response_field => $token];

        [$ok, $error] = $provider->verify($post, ['credentials' => $credentials, 'context' => 'admin_test']);
        if ($ok) {
            wp_send_json_success(['message' => __('Captcha works!', 'lrob-email-toolkit')]);
        }
        wp_send_json_error(['message' => $error ?: __('Verification failed.', 'lrob-email-toolkit')]);
    }

    public function ajax_save_identity(): void
    {
        $this->guard();

        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        $provider_slug = $this->post_str('provider_slug');
        $providers = $this->service->hosted_providers();
        if (!isset($providers[$provider_slug])) {
            wp_send_json_error(['message' => __('Unknown captcha provider.', 'lrob-email-toolkit')]);
        }
        /** @var ProviderInterface $provider */
        $provider = $providers[$provider_slug];

        $label = $this->post_str('label');
        if ($label === '') {
            wp_send_json_error([
                'message' => __('Label is required.', 'lrob-email-toolkit'),
                'fields'  => ['label' => __('Label is required.', 'lrob-email-toolkit')],
            ]);
        }

        // Raw credential values come in as `credentials[<key>]`. Empty
        // values on EDIT mean "keep what was stored" for sensitive fields
        // (mirrors SMTP's password semantics); on CREATE empty triggers
        // validate_credentials() and surfaces a per-field error.
        $raw_credentials = [];
        if (isset($_POST['credentials']) && is_array($_POST['credentials'])) {
            foreach ($_POST['credentials'] as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $raw_credentials[$key] = (string) wp_unslash((string) $value);
                }
            }
        }

        $existing = $id > 0 ? $this->identities->find($id) : null;
        $is_create = $existing === null;

        if (!$is_create) {
            // On edit, treat fully-empty credential fields as "keep existing".
            // We only re-validate the supplied (non-empty) ones, and merge
            // with the decrypted existing set so validate_credentials() sees
            // a complete picture.
            try {
                $stored = $existing->decrypted_credentials();
            } catch (\RuntimeException $e) {
                wp_send_json_error(['message' => $e->getMessage()]);
            }
            foreach ($raw_credentials as $key => $value) {
                if ($value === '') {
                    // Keep existing value untouched.
                    continue;
                }
                $stored[$key] = $value;
            }
            $merged_for_validation = $stored;
        } else {
            $merged_for_validation = $raw_credentials;
        }

        $validated = $provider->validate_credentials($merged_for_validation);
        if (!empty($validated['errors'])) {
            wp_send_json_error([
                'message' => __('Please fix the highlighted fields.', 'lrob-email-toolkit'),
                'fields'  => $validated['errors'],
            ]);
        }
        /** @var array<string, string> $clean_credentials */
        $clean_credentials = $validated['credentials'];

        $is_active = !isset($_POST['is_active']) || !empty($_POST['is_active']);
        $theme = Appearance::normalize_theme(isset($_POST['theme']) ? (string) wp_unslash($_POST['theme']) : '');
        $size  = Appearance::normalize_size(isset($_POST['size']) ? (string) wp_unslash($_POST['size']) : '');

        $identity = new Identity(
            id: $id > 0 ? $id : null,
            provider_slug: $provider->slug(),
            label: $label,
            credentials_encrypted: $existing?->credentials_encrypted ?? '',
            is_active: $is_active,
            theme: $theme,
            size: $size,
        );

        try {
            $saved_id = $this->identities->save($identity, $clean_credentials);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        // Re-load to get the derived slug + reflect the merged credentials
        // back to the client for the inline preview widget.
        $saved = $this->identities->find($saved_id);
        $site_key = '';
        if ($saved !== null) {
            try {
                $creds = $saved->decrypted_credentials();
                $site_key = isset($creds['site_key']) ? (string) $creds['site_key'] : '';
            } catch (\RuntimeException) {
                $site_key = '';
            }
        }

        wp_send_json_success([
            'id'        => $saved_id,
            'route_key' => Routing::identity($saved_id),
            'slug'      => $saved !== null ? $saved->derived_slug() : '',
            'site_key'  => $site_key,
            'message'   => $is_create
                ? __('Captcha created.', 'lrob-email-toolkit')
                : __('Captcha saved.', 'lrob-email-toolkit'),
        ]);
    }

    public function ajax_delete_identity(): void
    {
        $this->guard();
        $this->guard_action(self::ACTION_DELETE_IDENTITY);

        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid identity.', 'lrob-email-toolkit')]);
        }

        $route_key = Routing::identity($id);
        $this->identities->delete($id);

        // Sweep the routing map: any reference to the deleted identity becomes
        // 'inherit' (per-context) or the homemade fallback (default). Keeps
        // forms working without manual cleanup.
        $map = Routing::context_map();
        $changed = false;
        foreach ($map as $key => $value) {
            if ($value !== $route_key) {
                continue;
            }
            if ($key === Routing::KEY_DEFAULT) {
                $map[$key] = Routing::homemade('math');
            } else {
                $map[$key] = Routing::ROUTE_INHERIT;
            }
            $changed = true;
        }
        if ($changed) {
            Routing::replace_map($map);
        }

        wp_send_json_success(['message' => __('Identity deleted.', 'lrob-email-toolkit')]);
    }

    public function ajax_save_routing(): void
    {
        $this->guard();
        $this->guard_action(self::ACTION_SAVE_ROUTING);

        $submitted = isset($_POST['routing']) && is_array($_POST['routing'])
            ? wp_unslash($_POST['routing'])
            : [];

        $known_contexts = Routing::known_contexts();
        $known_keys = array_merge([Routing::KEY_DEFAULT], $known_contexts);

        $clean = [];
        foreach ($known_keys as $key) {
            $value = isset($submitted[$key]) && is_string($submitted[$key]) ? $submitted[$key] : '';
            $clean[$key] = $this->sanitize_route($value, $key);
        }

        Routing::replace_map($clean);

        wp_send_json_success([
            'message' => __('Routing saved.', 'lrob-email-toolkit'),
            'map'     => $clean,
        ]);
    }

    /**
     * Validate a routing key. Allowed shapes:
     *  - 'none' or 'inherit'
     *  - 'homemade:<slug>' (slug must be a registered homemade challenge)
     *  - 'identity:<int>'  (id must point at an existing identity)
     *
     * `inherit` is only meaningful in per-context entries — coerce to
     * 'none' when it appears under the 'default' key.
     */
    private function sanitize_route(string $route, string $key): string
    {
        $is_default = $key === Routing::KEY_DEFAULT;
        // The default row must always resolve to a real challenge: the
        // UI doesn't offer "none" or "inherit" there, and a forged POST
        // setting the default to either of those would silently turn
        // captcha off site-wide. Force the math fallback.
        if ($route === Routing::ROUTE_NONE) {
            return $is_default ? $this->default_fallback() : Routing::ROUTE_NONE;
        }
        if ($route === Routing::ROUTE_INHERIT) {
            return $is_default ? $this->default_fallback() : Routing::ROUTE_INHERIT;
        }
        $parsed = Routing::parse($route);
        if ($parsed['kind'] === Routing::KIND_HOMEMADE) {
            $homemade = $this->service->homemade_challenges();
            if (isset($homemade[$parsed['value']])) {
                return Routing::homemade($parsed['value']);
            }
        }
        if ($parsed['kind'] === Routing::KIND_IDENTITY) {
            $id = (int) $parsed['value'];
            if ($id > 0 && $this->identities->find($id) !== null) {
                return Routing::identity($id);
            }
        }
        return $is_default ? $this->default_fallback() : Routing::ROUTE_INHERIT;
    }

    public function ajax_set_default(): void
    {
        $this->guard();
        $this->guard_action(self::ACTION_SET_DEFAULT);
        $route = isset($_POST['route']) ? sanitize_text_field(wp_unslash((string) $_POST['route'])) : '';
        $sanitized = $this->sanitize_route($route, Routing::KEY_DEFAULT);
        if ($sanitized === Routing::ROUTE_NONE) {
            wp_send_json_error(['message' => __('Invalid challenge.', 'lrob-email-toolkit')]);
        }
        Routing::set_default($sanitized);
        wp_send_json_success(['route' => $sanitized]);
    }

    private function default_fallback(): string
    {
        $homemade = $this->service->homemade_challenges();
        if (isset($homemade['math'])) {
            return Routing::homemade('math');
        }
        $first = array_key_first($homemade);
        return $first !== null ? Routing::homemade($first) : Routing::ROUTE_NONE;
    }

    private function guard(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'lrob-email-toolkit')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, '_nonce');
    }

    /**
     * Extra per-action nonce check for destructive endpoints (delete
     * identity, save routing, set default). Layered on top of guard()'s
     * module-wide nonce so a nonce stolen from a non-destructive form
     * can't be replayed against them.
     */
    private function guard_action(string $action): void
    {
        $nonce = isset($_POST['_action_nonce']) ? (string) $_POST['_action_nonce'] : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload and retry.', 'lrob-email-toolkit')], 403);
        }
    }

    private function post_str(string $key, string $default = ''): string
    {
        if (!isset($_POST[$key])) {
            return $default;
        }
        return sanitize_text_field((string) wp_unslash($_POST[$key]));
    }
}
