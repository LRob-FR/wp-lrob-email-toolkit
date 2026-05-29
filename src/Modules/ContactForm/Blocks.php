<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

// Registers only the lrob-etk/contact-form embed block (CPT stays REST-exposed for block queries).
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
                'preset'    => ['type' => 'string',  'default' => ''], // '' = inherit per-form/global preset
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
        // Tokens defined on .lrob-etk; the preview placeholder carries that class so var(--etk-*) resolves.
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
