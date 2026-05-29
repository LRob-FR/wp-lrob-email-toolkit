<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use LRob\EmailToolkit\Forms\CountryData;

// Assets registered here but enqueued by EmbedRenderer only when a form appears on the page.
// form-submit.js is host-neutral — Newsletter reuses the same handle; WP dedupes.
final class Frontend
{
    public const HANDLE_CSS = 'lrob-etk-form-frontend';

    public const HANDLE_JS = 'lrob-etk-form-submit';

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
            LROB_ETK_URL . 'assets/js/form-submit.js',
            [],
            self::asset_version('assets/js/form-submit.js'),
            true
        );

        wp_localize_script(self::HANDLE_JS, 'lrobEtkForm', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'countries' => CountryData::all_translated(),
            'i18n'      => [
                'sending'          => __('Sending…', 'lrob-email-toolkit'),
                'unknownError'     => __('Something went wrong. Please try again.', 'lrob-email-toolkit'),
                'success'          => __('Thanks! Your message has been sent.', 'lrob-email-toolkit'),
                'invalidEmail'     => __('Please enter a valid email address.', 'lrob-email-toolkit'),
                'required'         => __('This field is required.', 'lrob-email-toolkit'),
                'searchCountry'    => __('Search country…', 'lrob-email-toolkit'),
                'tooManyFiles'     => __('Too many files', 'lrob-email-toolkit'),
                'fileTooLarge'     => __('File too large', 'lrob-email-toolkit'),
                'fileTypeRejected' => __('Type not allowed', 'lrob-email-toolkit'),
                'totalTooLarge'    => __('Combined size exceeds the limit', 'lrob-email-toolkit'),
                'captchaUnavailable' => __('Anti-spam check is unavailable. Please reload and try again.', 'lrob-email-toolkit'),
                'captchaFailed'    => __('Anti-spam check failed. Please try again.', 'lrob-email-toolkit'),
            ],
        ]);
    }

    public static function enqueue_assets(): void
    {
        if (!wp_style_is(self::HANDLE_CSS, 'registered')) {
            // Block render can fire after wp_enqueue_scripts (e.g. REST block preview).
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
