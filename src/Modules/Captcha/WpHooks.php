<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

use LRob\EmailToolkit\Modules\ContactForm\Frontend;

// Docs: docs/captcha.md → "WP-native context hooks"
final class WpHooks
{
    public function __construct(private CaptchaService $service)
    {
    }

    public function register(): void
    {
        $this->maybe_register_comments();
        $this->maybe_register_login();
        $this->maybe_register_lost_password();
        $this->maybe_register_registration();

        // Frontend form CSS not loaded by WP-native surfaces — enqueue where captcha shows.
        if (Routing::effective_route(Routing::CONTEXT_COMMENTS) !== Routing::ROUTE_NONE) {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_comment_style']);
        }
        $wp_login_active = $this->login_context_active()
            || Routing::effective_route(Routing::CONTEXT_LOST_PASSWORD) !== Routing::ROUTE_NONE
            || (Routing::effective_route(Routing::CONTEXT_REGISTRATION) !== Routing::ROUTE_NONE
                && (bool) get_option('users_can_register', false));
        if ($wp_login_active) {
            add_action('login_enqueue_scripts', [$this, 'enqueue_login_style']);
        }
    }

    /** Read raw map instead of effective_route() — absent entry must not fall through to the site default. */
    private function login_context_active(): bool
    {
        $map = Routing::context_map();
        $route = isset($map[Routing::CONTEXT_LOGIN]) ? $map[Routing::CONTEXT_LOGIN] : Routing::ROUTE_NONE;
        if ($route === Routing::ROUTE_NONE) {
            return false;
        }
        if ($route === Routing::ROUTE_INHERIT) {
            return Routing::default_route() !== Routing::ROUTE_NONE;
        }
        return true;
    }

    private function maybe_register_login(): void
    {
        if (!$this->login_context_active()) {
            return;
        }
        add_action('login_form', [$this, 'render_login_captcha']);
        add_filter('authenticate', [$this, 'verify_login_captcha'], 30, 1);
        // WooCommerce hooks never fire without WC — safe to register unconditionally.
        add_action('woocommerce_login_form', [$this, 'render_wc_login_captcha']);
        add_filter('woocommerce_process_login_errors', [$this, 'verify_wc_login_captcha'], 10, 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_wc_login_style']);
    }

    public function enqueue_comment_style(): void
    {
        if (!is_singular() || !comments_open()) {
            return;
        }
        $this->enqueue_frontend_style();
    }

    public function enqueue_login_style(): void
    {
        $this->enqueue_frontend_style();
    }

    public function enqueue_wc_login_style(): void
    {
        if (function_exists('is_account_page') && is_account_page()) {
            $this->enqueue_frontend_style();
        }
    }

    private function enqueue_frontend_style(): void
    {
        $handle = Frontend::HANDLE_CSS;
        if (!wp_style_is($handle, 'registered')) {
            $rel = 'assets/css/contact-form.css';
            $version = LROB_ETK_VERSION;
            $full = LROB_ETK_PATH . $rel;
            if (is_file($full)) {
                $version .= '.' . filemtime($full);
            }
            wp_register_style($handle, LROB_ETK_URL . $rel, [], $version);
        }
        wp_enqueue_style($handle);
    }

    private function maybe_register_comments(): void
    {
        if (Routing::effective_route(Routing::CONTEXT_COMMENTS) === Routing::ROUTE_NONE) {
            return;
        }
        add_action('comment_form_after_fields', [$this, 'render_comment_captcha']);
        // after_fields doesn't fire for logged-in users; this hook covers both.
        add_action('comment_form_logged_in_after', [$this, 'render_comment_captcha']);
        add_action('pre_comment_on_post', [$this, 'verify_comment_captcha']);
    }

    private function maybe_register_lost_password(): void
    {
        if (Routing::effective_route(Routing::CONTEXT_LOST_PASSWORD) === Routing::ROUTE_NONE) {
            return;
        }
        add_action('lostpassword_form', [$this, 'render_lost_password_captcha']);
        add_action('lostpassword_post', [$this, 'verify_lost_password_captcha'], 10, 1);
    }

    private function maybe_register_registration(): void
    {
        if (Routing::effective_route(Routing::CONTEXT_REGISTRATION) === Routing::ROUTE_NONE) {
            return;
        }
        if (!(bool) get_option('users_can_register', false)) {
            return;
        }
        add_action('register_form', [$this, 'render_registration_captcha']);
        add_filter('registration_errors', [$this, 'verify_registration_captcha'], 10, 1);
    }

    // -----------------------------------------------------------------
    // Render hooks
    // -----------------------------------------------------------------

    public function render_comment_captcha(): void
    {
        if (current_user_can('moderate_comments')) {
            return;
        }
        $html = $this->service->render(['context' => Routing::CONTEXT_COMMENTS]);
        if ($html !== '') {
            echo '<div class="lrob-etk-form lrob-etk-captcha-wrap lrob-etk-captcha-wrap--comments">' . $html . '</div>';
        }
    }

    public function render_login_captcha(): void
    {
        $html = $this->service->render(['context' => Routing::CONTEXT_LOGIN]);
        if ($html !== '') {
            // --narrow: wp-login columns are ~270px; scale hosted widgets to fit.
            echo '<div class="lrob-etk-form lrob-etk-captcha-wrap lrob-etk-captcha-wrap--login lrob-etk-captcha-wrap--narrow">' . $html . '</div>';
        }
    }

    public function render_wc_login_captcha(): void
    {
        $html = $this->service->render(['context' => Routing::CONTEXT_LOGIN]);
        if ($html !== '') {
            echo '<div class="lrob-etk-form lrob-etk-captcha-wrap lrob-etk-captcha-wrap--wc-login">' . $html . '</div>';
        }
    }

    public function render_lost_password_captcha(): void
    {
        $html = $this->service->render(['context' => Routing::CONTEXT_LOST_PASSWORD]);
        if ($html !== '') {
            echo '<div class="lrob-etk-form lrob-etk-captcha-wrap lrob-etk-captcha-wrap--lost-password lrob-etk-captcha-wrap--narrow">' . $html . '</div>';
        }
    }

    public function render_registration_captcha(): void
    {
        $html = $this->service->render(['context' => Routing::CONTEXT_REGISTRATION]);
        if ($html !== '') {
            echo '<div class="lrob-etk-form lrob-etk-captcha-wrap lrob-etk-captcha-wrap--registration lrob-etk-captcha-wrap--narrow">' . $html . '</div>';
        }
    }

    // -----------------------------------------------------------------
    // Verify hooks
    // -----------------------------------------------------------------

    public function verify_comment_captcha(): void
    {
        if (current_user_can('moderate_comments')) {
            return;
        }
        [$ok, $error] = $this->service->verify($_POST, ['context' => Routing::CONTEXT_COMMENTS]);
        if (!$ok) {
            wp_die(
                esc_html($error ?? __('Anti-spam check failed.', 'lrob-email-toolkit')),
                esc_html__('Comment submission blocked', 'lrob-email-toolkit'),
                ['back_link' => true, 'response' => 403]
            );
        }
    }

    /**
     * Gated to wp-login.php POST (`log`/`pwd`) — never touches XML-RPC / REST / app passwords.
     * @param \WP_User|\WP_Error|null $user
     * @return \WP_User|\WP_Error|null
     */
    public function verify_login_captcha($user)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return $user;
        }
        if (empty($_POST['log']) && empty($_POST['pwd'])) {
            return $user;
        }
        [$ok, $error] = $this->service->verify($_POST, ['context' => Routing::CONTEXT_LOGIN]);
        if (!$ok) {
            return new \WP_Error('lrob_etk_captcha', $error ?? __('Anti-spam check failed.', 'lrob-email-toolkit'));
        }
        return $user;
    }

    /** @param \WP_Error $validation_error @return \WP_Error */
    public function verify_wc_login_captcha(\WP_Error $validation_error): \WP_Error
    {
        [$ok, $error] = $this->service->verify($_POST, ['context' => Routing::CONTEXT_LOGIN]);
        if (!$ok) {
            $validation_error->add('lrob_etk_captcha', $error ?? __('Anti-spam check failed.', 'lrob-email-toolkit'));
        }
        return $validation_error;
    }

    public function verify_lost_password_captcha(\WP_Error $errors): void
    {
        [$ok, $error] = $this->service->verify($_POST, ['context' => Routing::CONTEXT_LOST_PASSWORD]);
        if (!$ok) {
            $errors->add('lrob_etk_captcha', $error ?? __('Anti-spam check failed.', 'lrob-email-toolkit'));
        }
    }

    /** @return \WP_Error */
    public function verify_registration_captcha(\WP_Error $errors): \WP_Error
    {
        [$ok, $error] = $this->service->verify($_POST, ['context' => Routing::CONTEXT_REGISTRATION]);
        if (!$ok) {
            $errors->add('lrob_etk_captcha', $error ?? __('Anti-spam check failed.', 'lrob-email-toolkit'));
        }
        return $errors;
    }
}
