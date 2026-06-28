<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Forms\FieldTypeRegistry;
use LRob\EmailToolkit\Forms\FormContext;
use LRob\EmailToolkit\Forms\FormStructure;
use LRob\EmailToolkit\Forms\Honeypot;
use LRob\EmailToolkit\Forms\StylePresets;
use LRob\EmailToolkit\Plugin;

/**
 * Renders a newsletter subscribe form on the public side. Parallel to
 * Contact Form's EmbedRenderer — opens `<form class="lrob-etk-form">`
 * with the form's preset class, walks the row/column/field structure
 * via FieldTypeRegistry, and appends honeypot + hidden fields (nonce,
 * form_id, instance, started timestamp, AJAX action).
 *
 * No per-block style overrides yet (Contact Form's embed block lets
 * the editor tweak radius/accent/font_size per-instance; Newsletter
 * defers that since the block lands without a settings sidebar in
 * step 3b). All forms inherit the per-form style preset; vars can
 * be configured globally in a future Newsletter settings page.
 */
final class EmbedRenderer
{
    /**
     * @param array<string, mixed> $attrs Block / shortcode attributes — uses `formId`.
     */
    public static function render(array $attrs): string
    {
        $form_id = isset($attrs['formId']) ? (int) $attrs['formId'] : 0;
        if ($form_id <= 0) {
            return self::placeholder(__('No newsletter form selected.', 'lrob-email-toolkit'));
        }
        $post = get_post($form_id);
        if (!$post instanceof \WP_Post || $post->post_type !== FormCPT::POST_TYPE || $post->post_status !== 'publish') {
            return self::placeholder(__('Newsletter form not found or not published.', 'lrob-email-toolkit'));
        }

        // Block rendering may fire after wp_enqueue_scripts is done (e.g.
        // REST preview during block editor use). Frontend::enqueue_assets
        // is idempotent, safe to call here.
        Frontend::enqueue_assets();

        $instance = substr(bin2hex(random_bytes(5)), 0, 10);
        FormContext::start($form_id, $instance, FormCPT::FIELD_NAME_PREFIX, FormCPT::FIELD_ID_PREFIX);

        try {
            $structure = FormStructure::load($form_id);
            $registry = self::registry();

            $form_attrs = self::compute_form_root_attrs($form_id, $instance);
            $html  = '<form ' . $form_attrs . '>';
            $html .= '<div class="lrob-etk-form-body">';

            foreach ($structure['rows'] as $row) {
                $html .= self::render_row($row, $registry);
            }

            // Safety net: if the admin removed the submit field, append
            // one so the form is at least submittable.
            if (!FormStructure::has_field_of_type($structure, 'submit') && $registry !== null) {
                $submit = $registry->get(FormCPT::POST_TYPE, 'submit');
                if ($submit !== null) {
                    $html .= $submit->render([
                        'text'  => __('Subscribe', 'lrob-email-toolkit'),
                        'align' => 'right',
                    ]);
                }
            }

            // Honeypot lives outside the visible structure — invisible
            // to humans, irresistible to bots.
            $html .= Honeypot::render();
            $html .= self::render_hidden_fields($form_id, $instance);
            $html .= '</div>';
            // Status below the form — see ContactForm\EmbedRenderer.
            $html .= '<div class="lrob-etk-form-status" data-form-status role="status" aria-live="polite" hidden></div>';
            $html .= '</form>';

            return $html;
        } finally {
            FormContext::end();
        }
    }

    /** @param array{id:string, columns:array<int, array{id:string, fields:array<int, array>}>} $row */
    private static function render_row(array $row, ?FieldTypeRegistry $registry): string
    {
        $col_count = max(1, count($row['columns']));
        $html = sprintf('<div class="lrob-etk-form-row" data-cols="%d">', $col_count);
        foreach ($row['columns'] as $col) {
            $html .= '<div class="lrob-etk-form-col">';
            foreach ($col['fields'] as $field) {
                $html .= self::render_field($field, $registry);
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /** @param array<string, mixed> $field */
    private static function render_field(array $field, ?FieldTypeRegistry $registry): string
    {
        if ($registry === null) {
            return '';
        }
        $type = (string) ($field['type'] ?? '');
        $field_type = $registry->get(FormCPT::POST_TYPE, $type);
        return $field_type !== null ? $field_type->render($field) : '';
    }

    /**
     * Root <form> attributes: class (with preset modifier), data
     * attributes JS reads to find the form (form-id, instance), the
     * submit URL + nonce, and `novalidate` so client-side HTML5
     * messages don't compete with our own.
     */
    private static function compute_form_root_attrs(int $form_id, string $instance): string
    {
        $preset = self::effective_style_preset($form_id);
        $classes = [
            'lrob-etk-form',
            'lrob-etk-form-preset--' . sanitize_html_class($preset, StylePresets::DEFAULT_SLUG),
        ];
        return sprintf(
            'class="%s" data-form-id="%d" data-instance="%s" data-submit-url="%s" data-nonce="%s" novalidate',
            esc_attr(implode(' ', $classes)),
            $form_id,
            esc_attr($instance),
            esc_url(admin_url('admin-ajax.php')),
            esc_attr(wp_create_nonce(SubmitHandler::NONCE_ACTION))
        );
    }

    /**
     * Per-form preset resolution. Empty meta = fall back to the shared
     * registry's default slug. Global newsletter-side defaults land
     * with a future Settings page; for now there's no global override.
     */
    private static function effective_style_preset(int $form_id): string
    {
        $per_form = (string) get_post_meta($form_id, FormCPT::META_STYLE_PRESET, true);
        if ($per_form !== '' && StylePresets::is_valid($per_form)) {
            return $per_form;
        }
        return StylePresets::DEFAULT_SLUG;
    }

    /**
     * Hidden form fields the JS-driven submit relies on: nonce,
     * form_id, instance (per-render id, prevents replay across two
     * embeds of the same form), started timestamp (time-trap), and
     * the WP AJAX action name.
     */
    private static function render_hidden_fields(int $form_id, string $instance): string
    {
        $started = time();
        return sprintf(
            '<input type="hidden" name="_wpnonce" value="%s">' .
            '<input type="hidden" name="_lrob_etk_nl_form_id" value="%d">' .
            '<input type="hidden" name="_lrob_etk_nl_instance" value="%s">' .
            '<input type="hidden" name="_lrob_etk_nl_started" value="%d">' .
            '<input type="hidden" name="action" value="%s">',
            esc_attr(wp_create_nonce(SubmitHandler::NONCE_ACTION)),
            $form_id,
            esc_attr($instance),
            $started,
            esc_attr(SubmitHandler::ACTION)
        );
    }

    /** Editor-only fallback. On the public frontend a missing form is silent. */
    private static function placeholder(string $message): string
    {
        if (!is_admin() && !self::is_block_editor_context()) {
            return '';
        }
        return '<div class="lrob-etk-form-placeholder" style="border:1px dashed #c00;padding:1em;background:#fff8f8;color:#600">'
            . esc_html($message) . '</div>';
    }

    private static function is_block_editor_context(): bool
    {
        return defined('REST_REQUEST') && REST_REQUEST;
    }

    private static function registry(): ?FieldTypeRegistry
    {
        $registry = Plugin::instance()->container()->get(FieldTypeRegistry::class);
        return $registry instanceof FieldTypeRegistry ? $registry : null;
    }
}
