<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Renders the page-side `lrob-etk/contact-form` block. Loads the referenced
 * form CPT, opens a &lt;form&gt; element with a per-render instance id, walks
 * the form's parsed blocks, injects anti-bot fields (challenge before the
 * submit button; honeypot + nonce + form/instance metadata at the end), and
 * returns the resulting HTML.
 *
 * Returns an admin-visible placeholder when the referenced form is missing
 * or unpublished, and nothing on the public frontend in those cases.
 */
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

        // Mark this render so the frontend asset enqueuer (or do-it-once filter)
        // knows it needs the assets. We enqueue directly here as a safety net
        // because block-rendering happens late.
        Frontend::enqueue_assets();

        $instance = substr(bin2hex(random_bytes(5)), 0, 10);
        FormContext::start($form_id, $instance);

        try {
            $structure = FormStructure::load($form_id);

            $form_attrs = self::compute_form_root_attrs($form_id, $instance, $preset, $overrides);
            $html = '<form ' . $form_attrs . '>';
            $html .= '<div class="lrob-etk-cf-status" data-form-status hidden></div>';
            $html .= '<div class="lrob-etk-cf-body">';

            foreach ($structure['rows'] as $row) {
                $html .= self::render_row($row);
            }

            // Anti-bot challenge sits just before the submit button.
            $html .= self::render_anti_bot($form_id);
            $html .= FieldRenderer::submit($structure['submit']);

            $html .= self::render_hidden_fields($form_id, $instance);
            $html .= '</div>';
            $html .= '</form>';

            return $html;
        } finally {
            FormContext::end();
        }
    }

    /**
     * @param array{id:string, columns:array<int, array{id:string, fields:array<int, array>}>} $row
     */
    private static function render_row(array $row): string
    {
        $col_count = max(1, count($row['columns']));
        $html = sprintf('<div class="lrob-etk-cf-row" data-cols="%d">', $col_count);
        foreach ($row['columns'] as $col) {
            $html .= '<div class="lrob-etk-cf-col">';
            foreach ($col['fields'] as $field) {
                $html .= self::render_field($field);
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /** @param array<string, mixed> $field */
    private static function render_field(array $field): string
    {
        $type = (string) ($field['type'] ?? '');
        return match ($type) {
            'text'     => FieldRenderer::text($field),
            'email'    => FieldRenderer::email($field),
            'textarea' => FieldRenderer::textarea($field),
            'number'   => FieldRenderer::number($field),
            'phone'    => FieldRenderer::phone($field),
            'date'     => FieldRenderer::date($field),
            'select'   => FieldRenderer::select($field),
            'radio'    => FieldRenderer::radio($field),
            'checkbox' => FieldRenderer::checkbox($field),
            default    => '',
        };
    }

    private static function compute_form_root_attrs(int $form_id, string $instance, string $preset, array $overrides): string
    {
        $classes = [
            'lrob-etk-cf-form',
            'lrob-etk-cf-preset--' . sanitize_html_class($preset, CPT::STYLE_DEFAULT),
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

    /**
     * Build the inline `style` for the form root. For each style var, the
     * per-block override on this embed wins; if absent, the global default
     * from Settings is used; if that's empty too, the CSS rule falls back to
     * the FSE theme variable chain in contact-form.css.
     */
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

    private static function render_anti_bot(int $form_id): string
    {
        $challenge = Settings::effective_challenge($form_id) === CPT::CHALLENGE_MATH
            ? MathChallenge::render()
            : '';
        $honeypot = Settings::effective_honeypot($form_id) ? Honeypot::render() : '';
        return $challenge . $honeypot;
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

    /** Editor-only fallback. On the public frontend we render nothing. */
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
}
