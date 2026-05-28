<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

use LRob\EmailToolkit\Modules\ContactForm\Frontend;

/**
 * Wires the Captcha service into WP's built-in form surfaces:
 *
 *  - Comments       → comment_form_after_fields (render) + preprocess_comment (verify)
 *  - Lost password  → lostpassword_form         (render) + lostpassword_post  (verify)
 *  - Registration   → register_form             (render) + registration_errors (verify)
 *
 * Each context is conditionally registered: if the admin has set the
 * context route to `none` (the default), we skip the hooks entirely so
 * the captcha service does zero work on those forms. The check happens
 * at boot time (register()), so toggling the route requires no manual
 * action beyond saving the settings page.
 *
 * Admin bypasses:
 *  - Comments: users with the `moderate_comments` capability skip the
 *    captcha (admins, editors). Logged-out commenters always see it.
 *  - Registration: no bypass — registration is for logged-out users by
 *    definition. Also short-circuits when `users_can_register` is off.
 *  - Lost password: no bypass — logged-in users wouldn't normally hit
 *    this form, and password-reset is the textbook bot-replay target.
 *
 * Multisite signup (`wpmu_signup_*`) is a backlog item — single-site
 * only for v1.
 */
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

        // The captcha output reuses the frontend form CSS (homemade-challenge
        // tiles, hosted-widget constraints, theme vars). WP-native surfaces
        // don't otherwise load it, so enqueue it where a captcha will show —
        // without it the picture-recognition radios show raw, options span
        // full width, and hosted widgets overflow.
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

    /**
     * Login is opt-in like the other WP-native contexts, but it's a *new*
     * context — older installs have no map entry for it, and a bare
     * `effective_route()` would fall through to the site default and silently
     * turn login captcha on everywhere. So treat absent / 'none' as off.
     */
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
        // wp-login.php sign-in form.
        add_action('login_form', [$this, 'render_login_captcha']);
        add_filter('authenticate', [$this, 'verify_login_captcha'], 30, 1);
        // WooCommerce account login (front). These hooks never fire without
        // WooCommerce, so registering them unconditionally is harmless.
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
        // Logged-in users skip the after-fields hook (it doesn't fire),
        // so we attach a second renderer to the logged-in equivalent so
        // either way the captcha shows. Admin-bypass logic in both.
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
            // .lrob-etk-form host so the challenge/widget gets the same layout,
            // theme vars and width constraints as the plugin's own forms.
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
        // WooCommerce account login lives on a normal front page (wider column),
        // so no --narrow scaling here.
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

    /**
     * Verify before WP processes the comment. wp_die on failure — same
     * pattern WP itself uses for required-field misses. Skips admins.
     */
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
     * Login verify on the `authenticate` filter. Gated to a real wp-login.php
     * credential POST (the form posts `log`/`pwd`) so it never touches
     * XML-RPC / application-password / REST auth — and WooCommerce login,
     * which posts `username`/`password`, is handled separately below. On
     * failure return a WP_Error so WP blocks the sign-in.
     *
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

    /**
     * WooCommerce account-login verify. WC runs this filter before wp_signon;
     * add our error to the running WP_Error and WC halts the login.
     *
     * @param \WP_Error $validation_error
     * @return \WP_Error
     */
    public function verify_wc_login_captcha(\WP_Error $validation_error): \WP_Error
    {
        [$ok, $error] = $this->service->verify($_POST, ['context' => Routing::CONTEXT_LOGIN]);
        if (!$ok) {
            $validation_error->add('lrob_etk_captcha', $error ?? __('Anti-spam check failed.', 'lrob-email-toolkit'));
        }
        return $validation_error;
    }

    /**
     * Lost-password verify: WP passes a WP_Error by reference so we
     * just add our error to it; the form re-renders with the message.
     */
    public function verify_lost_password_captcha(\WP_Error $errors): void
    {
        [$ok, $error] = $this->service->verify($_POST, ['context' => Routing::CONTEXT_LOST_PASSWORD]);
        if (!$ok) {
            $errors->add('lrob_etk_captcha', $error ?? __('Anti-spam check failed.', 'lrob-email-toolkit'));
        }
    }

    /**
     * Registration verify: filter on registration_errors — add our
     * error to the WP_Error and WP halts registration + re-renders
     * with the message.
     *
     * @return \WP_Error
     */
    public function verify_registration_captcha(\WP_Error $errors): \WP_Error
    {
        [$ok, $error] = $this->service->verify($_POST, ['context' => Routing::CONTEXT_REGISTRATION]);
        if (!$ok) {
            $errors->add('lrob_etk_captcha', $error ?? __('Anti-spam check failed.', 'lrob-email-toolkit'));
        }
        return $errors;
    }
}
