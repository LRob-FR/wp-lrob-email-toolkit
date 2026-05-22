<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Modules\Newsletter\Admin\PageController;

/**
 * Registers the page-side `lrob-etk/newsletter-subscribe` Gutenberg
 * block — the picker that embeds a chosen subscribe form into any
 * page/post via the block editor. Same pattern as Contact Form's
 * `lrob-etk/contact-form` block; restricted to FormCPT::POST_TYPE
 * posts via the editor JS's REST query.
 *
 * Render is server-side via EmbedRenderer — block content stays a
 * single attribute (formId), no HTML in post_content so swapping
 * the form picks up automatically.
 */
final class Blocks
{
    public function register(): void
    {
        add_action('init', [$this, 'register_blocks'], 20);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_shortcode('lrob_etk_nl_subscribe', [$this, 'render_shortcode']);
    }

    public function register_blocks(): void
    {
        register_block_type('lrob-etk/newsletter-subscribe', [
            'attributes' => [
                'formId' => ['type' => 'integer', 'default' => 0],
            ],
            'render_callback' => [EmbedRenderer::class, 'render'],
            'supports'        => ['html' => false, 'inserter' => true, 'align' => ['wide', 'full']],
        ]);
    }

    public function enqueue_editor_assets(): void
    {
        $deps_js  = ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-data', 'wp-api-fetch'];
        $deps_css = ['wp-edit-blocks'];

        wp_enqueue_script(
            'lrob-etk-nl-block-editor',
            LROB_ETK_URL . 'admin/js/newsletter-block-editor.js',
            $deps_js,
            self::asset_version('admin/js/newsletter-block-editor.js'),
            true
        );
        wp_enqueue_style(
            'lrob-etk-cf-editor',
            LROB_ETK_URL . 'admin/css/contact-form-editor.css',
            $deps_css,
            self::asset_version('admin/css/contact-form-editor.css')
        );

        wp_localize_script('lrob-etk-nl-block-editor', 'lrobEtkNlBlock', [
            'restRoot'     => esc_url_raw(rest_url('wp/v2/lrob-etk-nl-forms')),
            'editFormBase' => admin_url(
                'admin.php?page=' . PageController::SLUG . '&view=forms#form-'
            ),
        ]);

        wp_set_script_translations('lrob-etk-nl-block-editor', 'lrob-email-toolkit');
    }

    /**
     * Shortcode wrapper — for sites that aren't on the block editor
     * (Classic Editor plugin, page builders that pass shortcodes
     * through, etc.). Same render path as the block; only the
     * attribute name changes (`id` is conventional for shortcodes).
     *
     * @param array<string, mixed>|string $atts
     */
    public function render_shortcode($atts): string
    {
        $atts = shortcode_atts(['id' => 0], is_array($atts) ? $atts : [], 'lrob_etk_nl_subscribe');
        return EmbedRenderer::render(['formId' => (int) $atts['id']]);
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
