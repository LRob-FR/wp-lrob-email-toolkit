<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

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
        $this->maybe_register_lost_password();
        $this->maybe_register_registration();
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
            echo '<p class="lrob-etk-captcha-wrap lrob-etk-captcha-wrap--comments">' . $html . '</p>';
        }
    }

    public function render_lost_password_captcha(): void
    {
        $html = $this->service->render(['context' => Routing::CONTEXT_LOST_PASSWORD]);
        if ($html !== '') {
            echo '<p class="lrob-etk-captcha-wrap lrob-etk-captcha-wrap--lost-password">' . $html . '</p>';
        }
    }

    public function render_registration_captcha(): void
    {
        $html = $this->service->render(['context' => Routing::CONTEXT_REGISTRATION]);
        if ($html !== '') {
            echo '<p class="lrob-etk-captcha-wrap lrob-etk-captcha-wrap--registration">' . $html . '</p>';
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
