<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Registers the page-side `lrob-etk/contact-form` Gutenberg block — the
 * picker that embeds a chosen form into any page/post via the block editor.
 *
 * Field blocks (lrob-etk/field-*) used to live here too. They were
 * removed when the form CPT moved off Gutenberg and onto the custom inline
 * editor on the Contact Forms admin page (FormsPage). The CPT itself is
 * still REST-exposed so the embed block can query it.
 */
final class Blocks
{
    public function register(): void
    {
        add_action('init', [$this, 'register_blocks'], 20);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
    }

    public function register_blocks(): void
    {
        register_block_type('lrob-etk/contact-form', [
            'attributes' => [
                'formId'    => ['type' => 'integer', 'default' => 0],
                // Empty string = inherit the per-form/global preset.
                'preset'    => ['type' => 'string',  'default' => ''],
                'overrides' => ['type' => 'object',  'default' => new \stdClass()],
            ],
            'render_callback' => [EmbedRenderer::class, 'render'],
            'supports'        => ['html' => false, 'inserter' => true, 'align' => ['wide', 'full']],
        ]);
    }

    public function enqueue_editor_assets(): void
    {
        $deps_js  = ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-data', 'wp-api-fetch'];

        wp_enqueue_script(
            'lrob-etk-cf-editor',
            LROB_ETK_URL . 'admin/js/contact-form-editor.js',
            $deps_js,
            self::asset_version('admin/js/contact-form-editor.js'),
            true
        );
        // The editor canvas isn't under the admin `.lrob-etk` wrap, so the
        // design tokens (defined in admin-base.css on `.lrob-etk`) must be
        // loaded here too — the preview placeholder carries a `.lrob-etk`
        // class so the `var(--etk-*)` references resolve inside the canvas.
        wp_enqueue_style(
            'lrob-etk-base',
            LROB_ETK_URL . 'admin/css/admin-base.css',
            ['wp-edit-blocks'],
            self::asset_version('admin/css/admin-base.css')
        );
        wp_enqueue_style(
            'lrob-etk-cf-editor',
            LROB_ETK_URL . 'admin/css/contact-form-editor.css',
            ['lrob-etk-base'],
            self::asset_version('admin/css/contact-form-editor.css')
        );

        wp_localize_script('lrob-etk-cf-editor', 'lrobEtkCfEditor', [
            'cptSlug'        => CPT::POST_TYPE,
            'restRoot'       => esc_url_raw(rest_url('wp/v2/' . 'lrob-etk-contact-forms')),
            'editFormBase'   => admin_url('admin.php?page=' . \LRob\EmailToolkit\Modules\ContactForm\Admin\FormsPage::SLUG . '#form-'),
            'globalDefaults' => [
                'style_preset' => (string) (Settings::all()[Settings::KEY_STYLE_PRESET] ?? CPT::STYLE_DEFAULT),
            ],
        ]);

        wp_set_script_translations('lrob-etk-cf-editor', 'lrob-email-toolkit');
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
