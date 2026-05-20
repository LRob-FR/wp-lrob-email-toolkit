<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Renders a form for the admin WYSIWYG editor. The form looks IDENTICAL to
 * the frontend (same FieldRenderer, same CSS) — the editor JS overlays
 * hover-revealed controls and inline editing on top.
 *
 * Differences from EmbedRenderer:
 *   - Wraps in `<div class="lrob-etk-cf-form is-editor">` (not `<form>`),
 *     so the admin card is one form and the previewed contact form doesn't
 *     accidentally submit anything.
 *   - Starts FormContext with editor=true, which makes FieldRenderer emit
 *     contenteditable labels + helpers and a `type="button"` submit.
 *   - Wraps each row / column / field with a thin shell carrying data
 *     attributes the editor JS reads to drag, delete, open the gear popup,
 *     and serialize back to JSON.
 *   - Emits "+" insertion zones between every pair of rows / fields, plus
 *     a column "+" at the end of each row when more columns are allowed.
 *   - Does NOT emit honeypot / nonce / hidden submission fields.
 */
final class FormEditorRenderer
{
    public static function render(int $form_id): string
    {
        $structure = FormStructure::load($form_id);

        // Use a deterministic instance id so editor previews don't shift
        // around as fields are added/removed (no submission, no need for
        // entropy).
        $instance = 'editor';
        FormContext::start($form_id, $instance, true);

        try {
            $html  = sprintf(
                '<div class="lrob-etk-cf-form is-editor lrob-etk-cf-preset--default" data-form-id="%d" data-editor>',
                $form_id
            );
            $html .= '<div class="lrob-etk-cf-body" data-editor-body>';

            $html .= self::insert_zone('row', __('Add a new row here', 'lrob-email-toolkit'));

            foreach ($structure['rows'] as $row) {
                $html .= self::render_row($row);
                $html .= self::insert_zone('row', __('Add a new row here', 'lrob-email-toolkit'));
            }

            // Soft warning if the user has no submit anywhere — they need
            // to add one for the form to be usable.
            if (!FormStructure::has_field_of_type($structure, 'submit')) {
                $html .= sprintf(
                    '<p class="lrob-etk-cf-editor-warn">%s</p>',
                    esc_html__('This form has no Submit button — visitors won\'t be able to send it. Add one with the "+" button.', 'lrob-email-toolkit')
                );
            }

            $html .= '</div>';
            $html .= '</div>';

            return $html;
        } finally {
            FormContext::end();
        }
    }

    /** @param array{id:string, columns:array<int, array{id:string, fields:array<int, array>}>} $row */
    private static function render_row(array $row): string
    {
        $cols = max(1, count($row['columns']));
        $html  = sprintf(
            '<div class="lrob-etk-cf-row" data-cols="%d" data-row-id="%s" data-draggable-type="row" draggable="true">',
            $cols,
            esc_attr($row['id'])
        );

        // Row-level overlay (drag handle + delete). The CSS hides it until
        // the row is hovered.
        $html .= self::row_overlay();

        foreach ($row['columns'] as $col) {
            $html .= self::render_column($col);
        }

        // Column-insert "+" sits at the right end of the row when there's
        // still room for another column.
        if ($cols < 4) {
            $html .= self::insert_zone('column', __('Add a column to this row', 'lrob-email-toolkit'));
        }

        $html .= '</div>';
        return $html;
    }

    /** @param array{id:string, fields:array<int, array>} $col */
    private static function render_column(array $col): string
    {
        $html  = sprintf(
            '<div class="lrob-etk-cf-col" data-col-id="%s" data-draggable-type="col" draggable="true">',
            esc_attr($col['id'])
        );
        $html .= self::col_overlay();

        $html .= self::insert_zone('field', __('Add a field here', 'lrob-email-toolkit'));

        foreach ($col['fields'] as $field) {
            $html .= self::render_field($field);
            $html .= self::insert_zone('field', __('Add a field here', 'lrob-email-toolkit'));
        }

        $html .= '</div>';
        return $html;
    }

    /** @param array<string, mixed> $field */
    private static function render_field(array $field): string
    {
        $type = (string) ($field['type'] ?? '');
        $id   = (string) ($field['id'] ?? '');

        // Delegate to FieldRenderer in editor mode (FormContext::is_editor()
        // is true). Identical markup to frontend, plus the editor-specific
        // contenteditable bits inside.
        $inner = match ($type) {
            'text'     => FieldRenderer::text($field),
            'email'    => FieldRenderer::email($field),
            'textarea' => FieldRenderer::textarea($field),
            'number'   => FieldRenderer::number($field),
            'phone'    => FieldRenderer::phone($field),
            'date'     => FieldRenderer::date($field),
            'select'   => FieldRenderer::select($field),
            'radio'    => FieldRenderer::radio($field),
            'checkbox' => FieldRenderer::checkbox($field),
            'submit'   => FieldRenderer::submit($field),
            'captcha'  => FieldRenderer::captcha($field),
            default    => '',
        };

        if ($inner === '') {
            return '';
        }

        return sprintf(
            '<div class="lrob-etk-cf-edit-shell" data-field-id="%s" data-field-type="%s" data-draggable-type="field" draggable="true">%s%s</div>',
            esc_attr($id),
            esc_attr($type),
            self::field_overlay(),
            $inner
        );
    }

    /**
     * Insertion zone — collapsed by default, expanded by the editor JS when
     * the cursor approaches. Row and field zones carry text labels ("+ Row"
     * / "+ Field") so the user can tell at a glance which kind of insert
     * they'd get; the column zone is a tighter "+" pill anchored to the
     * row's right edge.
     */
    private static function insert_zone(string $kind, string $aria): string
    {
        // Body-level inserts (kind=row in DOM terms) read as "+ Field" to the
        // user — they're inserting a field into the form; the fact that the
        // markup wraps it in a single-column row is an implementation detail.
        // Inside a multi-column block, kind=field has the same label since the
        // user is also just inserting a field. The kind=column zone stays a
        // bare "+" since it's clearly the row's add-column affordance.
        $plus = '<span class="lrob-etk-cf-insert-plus" aria-hidden="true">+</span>';
        $body = match ($kind) {
            'row', 'field' => $plus . '<span class="lrob-etk-cf-insert-label">' . esc_html__('Field', 'lrob-email-toolkit') . '</span>',
            default        => $plus,
        };
        return sprintf(
            '<button type="button" class="lrob-etk-cf-insert lrob-etk-cf-insert--%s" data-insert="%s" aria-label="%s">%s</button>',
            esc_attr($kind),
            esc_attr($kind),
            esc_attr($aria),
            $body
        );
    }

    private static function row_overlay(): string
    {
        return '<div class="lrob-etk-cf-overlay lrob-etk-cf-overlay--row" aria-hidden="true">'
            . '<span class="lrob-etk-cf-overlay-handle dashicons dashicons-move" title="' . esc_attr__('Drag to reorder row', 'lrob-email-toolkit') . '"></span>'
            . '<button type="button" class="lrob-etk-cf-overlay-btn" data-action="delete-row" title="' . esc_attr__('Delete row', 'lrob-email-toolkit') . '">'
            . '<span class="dashicons dashicons-trash"></span></button>'
            . '</div>';
    }

    private static function col_overlay(): string
    {
        return '<div class="lrob-etk-cf-overlay lrob-etk-cf-overlay--col" aria-hidden="true">'
            . '<span class="lrob-etk-cf-overlay-handle dashicons dashicons-move" title="' . esc_attr__('Drag to reorder column', 'lrob-email-toolkit') . '"></span>'
            . '<button type="button" class="lrob-etk-cf-overlay-btn" data-action="delete-col" title="' . esc_attr__('Delete column', 'lrob-email-toolkit') . '">'
            . '<span class="dashicons dashicons-trash"></span></button>'
            . '</div>';
    }

    private static function field_overlay(): string
    {
        return '<div class="lrob-etk-cf-overlay lrob-etk-cf-overlay--field" aria-hidden="true">'
            . '<span class="lrob-etk-cf-overlay-handle dashicons dashicons-move" title="' . esc_attr__('Drag to reorder field', 'lrob-email-toolkit') . '"></span>'
            . '<button type="button" class="lrob-etk-cf-overlay-btn lrob-etk-cf-overlay-btn--delete" data-action="delete-field" title="' . esc_attr__('Delete field', 'lrob-email-toolkit') . '">'
            . '<span class="dashicons dashicons-trash"></span></button>'
            . '</div>';
    }
}
