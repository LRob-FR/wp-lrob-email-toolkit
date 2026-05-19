<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Registers the frontend form CSS/JS. They are *registered* on
 * `wp_enqueue_scripts` but only actually *enqueued* by EmbedRenderer when a
 * form is rendered on the page — so pages with no form don't pull a kilobyte
 * of unused JS.
 */
final class Frontend
{
    public const HANDLE_CSS = 'lrob-etk-cf-frontend';

    public const HANDLE_JS = 'lrob-etk-cf-frontend';

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_assets(): void
    {
        wp_register_style(
            self::HANDLE_CSS,
            LROB_ETK_URL . 'assets/css/contact-form.css',
            [],
            self::asset_version('assets/css/contact-form.css')
        );

        wp_register_script(
            self::HANDLE_JS,
            LROB_ETK_URL . 'assets/js/contact-form.js',
            [],
            self::asset_version('assets/js/contact-form.js'),
            true
        );

        wp_localize_script(self::HANDLE_JS, 'lrobEtkCf', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'i18n'    => [
                'sending'      => __('Sending…', 'lrob-email-toolkit'),
                'unknownError' => __('Something went wrong. Please try again.', 'lrob-email-toolkit'),
                'success'      => __('Thanks! Your message has been sent.', 'lrob-email-toolkit'),
                'invalidEmail' => __('Please enter a valid email address.', 'lrob-email-toolkit'),
                'required'     => __('This field is required.', 'lrob-email-toolkit'),
            ],
        ]);
    }

    public static function enqueue_assets(): void
    {
        if (!wp_style_is(self::HANDLE_CSS, 'registered')) {
            // Block render happens late — re-run the registration if we didn't
            // hit wp_enqueue_scripts (e.g. REST preview during block editing).
            (new self())->register_assets();
        }
        wp_enqueue_style(self::HANDLE_CSS);
        wp_enqueue_script(self::HANDLE_JS);
    }

    private static function asset_version(string $relative): string
    {
        $version = LROB_ETK_VERSION;
        $full = LROB_ETK_PATH . ltrim($relative, '/');
        if (is_file($full)) {
            $version .= '.' . filemtime($full);
        }
        return $version;
    }
}
