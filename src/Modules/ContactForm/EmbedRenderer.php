<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use LRob\EmailToolkit\Forms\FieldTypeRegistry;
use LRob\EmailToolkit\Forms\FormContext;
use LRob\EmailToolkit\Forms\FormStructure;
use LRob\EmailToolkit\Forms\Honeypot;
use LRob\EmailToolkit\Plugin;

// Docs: docs/contact-form.md — returns nothing on public frontend for missing/unpublished forms.
final class EmbedRenderer
{
    /** @param array<string, mixed> $attrs */
    public static function render(array $attrs): string
    {
        $form_id = isset($attrs['formId']) ? (int) $attrs['formId'] : 0;
        // '' means "inherit per-form / global default". Non-empty = explicit override on this block.
        $preset = isset($attrs['preset']) && is_string($attrs['preset']) && $attrs['preset'] !== ''
            ? (string) $attrs['preset']
            : Settings::effective_style_preset($form_id);
        $overrides = isset($attrs['overrides']) && is_array($attrs['overrides']) ? $attrs['overrides'] : [];

        if ($form_id <= 0) {
            return self::placeholder(__('No contact form selected.', 'lrob-email-toolkit'));
        }

        $post = get_post($form_id);
        if (!$post || $post->post_type !== CPT::POST_TYPE || $post->post_status !== 'publish') {
            return self::placeholder(__('Contact form not found or not published.', 'lrob-email-toolkit'));
        }

        Frontend::enqueue_assets();

        $instance = substr(bin2hex(random_bytes(5)), 0, 10);
        FormContext::start($form_id, $instance, CPT::FIELD_NAME_PREFIX, CPT::FIELD_ID_PREFIX);

        try {
            $structure = FormStructure::load($form_id);
            $registry = self::registry();

            // Honeypot added unconditionally after all rows; captcha is rendered inline by its field type.
            $honeypot_html = Settings::effective_honeypot($form_id) ? Honeypot::render() : '';

            $form_attrs = self::compute_form_root_attrs($form_id, $instance, $preset, $overrides);
            $html = '<form ' . $form_attrs . '>';
            $html .= '<div class="lrob-etk-form-body">';

            foreach ($structure['rows'] as $row) {
                $html .= self::render_row($row, $registry);
            }

            // Fallback: if admin removed the submit field, append a generic one.
            if (!FormStructure::has_field_of_type($structure, 'submit') && $registry !== null) {
                $submit = $registry->get(CPT::POST_TYPE, 'submit');
                if ($submit !== null) {
                    $html .= $submit->render(['text' => __('Send', 'lrob-email-toolkit'), 'align' => 'right']);
                }
            }

            $html .= $honeypot_html;
            $html .= self::render_hidden_fields($form_id, $instance);
            $html .= '</div>';
            // Status sits below the form: the submit button is always at the
            // bottom, so the message lands where the user just clicked (on a
            // long form the top would scroll out of view).
            $html .= '<div class="lrob-etk-form-status" data-form-status role="status" aria-live="polite" hidden></div>';
            $html .= '</form>';

            return $html;
        } finally {
            FormContext::end();
        }
    }

    /**
     * @param array{id:string, columns:array<int, array{id:string, fields:array<int, array>}>} $row
     */
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
        $field_type = $registry->get(CPT::POST_TYPE, $type);
        return $field_type !== null ? $field_type->render($field) : '';
    }

    private static function compute_form_root_attrs(int $form_id, string $instance, string $preset, array $overrides): string
    {
        $classes = [
            'lrob-etk-form',
            'lrob-etk-form-preset--' . sanitize_html_class($preset, CPT::STYLE_DEFAULT),
        ];
        $style = self::style_inline($overrides);
        return sprintf(
            'class="%s" data-form-id="%d" data-instance="%s" data-submit-url="%s" data-nonce="%s" novalidate%s',
            esc_attr(implode(' ', $classes)),
            $form_id,
            esc_attr($instance),
            esc_url(admin_url('admin-ajax.php')),
            esc_attr(wp_create_nonce('lrob_etk_cf_submit')),
            $style !== '' ? ' style="' . esc_attr($style) . '"' : ''
        );
    }

    // Per-block override → global default → CSS fallback (FSE theme variable chain in contact-form.css).
    private static function style_inline(array $overrides): string
    {
        $globals = Settings::all();
        $map = [
            'radius'    => ['var' => '--lrob-etk-cf-radius',    'global' => (string) ($globals[Settings::KEY_RADIUS] ?? '')],
            'accent'    => ['var' => '--lrob-etk-cf-accent',    'global' => (string) ($globals[Settings::KEY_ACCENT] ?? '')],
            'font_size' => ['var' => '--lrob-etk-cf-font-size', 'global' => (string) ($globals[Settings::KEY_FONT_SIZE] ?? '')],
        ];
        $out = [];
        foreach ($map as $key => $cfg) {
            $val = isset($overrides[$key]) ? trim((string) $overrides[$key]) : '';
            if ($val === '') {
                $val = trim($cfg['global']);
            }
            if ($val !== '') {
                $out[] = $cfg['var'] . ':' . $val;
            }
        }
        return implode(';', $out);
    }

    private static function render_hidden_fields(int $form_id, string $instance): string
    {
        $started = time();
        return sprintf(
            '<input type="hidden" name="_wpnonce" value="%s">' .
            '<input type="hidden" name="_lrob_etk_cf_form_id" value="%d">' .
            '<input type="hidden" name="_lrob_etk_cf_instance" value="%s">' .
            '<input type="hidden" name="_lrob_etk_cf_started" value="%d">' .
            '<input type="hidden" name="action" value="lrob_etk_cf_submit">',
            esc_attr(wp_create_nonce(SubmitHandler::NONCE_ACTION)),
            $form_id,
            esc_attr($instance),
            $started
        );
    }

    // Renders only in block editor context; returns empty string on public frontend.
    private static function placeholder(string $message): string
    {
        if (!is_admin() && !self::is_block_editor_context()) {
            return '';
        }
        return '<div class="lrob-etk-cf-placeholder" style="border:1px dashed #c00;padding:1em;background:#fff8f8;color:#600">' .
            esc_html($message) . '</div>';
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
