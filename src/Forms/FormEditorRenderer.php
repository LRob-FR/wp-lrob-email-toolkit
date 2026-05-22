<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

use LRob\EmailToolkit\Plugin;

/**
 * Renders a form for the admin WYSIWYG editor. The form looks IDENTICAL to
 * the frontend (same field renderers, same CSS) — the editor JS overlays
 * hover-revealed controls and inline editing on top.
 *
 * Differences from frontend:
 *   - Wraps in `<div class="lrob-etk-form is-editor">` (not `<form>`),
 *     so the admin card is one form and the previewed contact form doesn't
 *     accidentally submit anything.
 *   - Starts FormContext with editor=true, which makes field renderers emit
 *     contenteditable labels + helpers and a `type="button"` submit.
 *   - Wraps each row / column / field with a thin shell carrying data
 *     attributes the editor JS reads to drag, delete, open the gear popup,
 *     and serialize back to JSON.
 *   - Emits "+" insertion zones between every pair of rows / fields, plus
 *     a column "+" at the end of each row when more columns are allowed.
 *   - Does NOT emit honeypot / nonce / hidden submission fields.
 *
 * Host-neutral: the caller passes its field-name prefix + DOM id prefix so
 * the editor's "preview" inputs scope correctly per-CPT (Contact Form
 * passes `lrob_etk_cf`, Newsletter will pass `lrob_etk_nl`).
 */
final class FormEditorRenderer
{
    public static function render(int $form_id, string $name_prefix, string $id_prefix): string
    {
        $structure = FormStructure::load($form_id);
        $cpt_slug = (string) get_post_type($form_id);

        // Deterministic instance id so editor previews don't shift around
        // as fields are added/removed (no submission, no need for entropy).
        $instance = 'editor';
        FormContext::start($form_id, $instance, $name_prefix, $id_prefix, true);

        try {
            $html  = sprintf(
                '<div class="lrob-etk-form is-editor lrob-etk-form-preset--default" data-form-id="%d" data-editor>',
                $form_id
            );
            $html .= '<div class="lrob-etk-form-body" data-editor-body>';

            $html .= self::insert_zone('row', __('Add a new row here', 'lrob-email-toolkit'));

            foreach ($structure['rows'] as $row) {
                $html .= self::render_row($row, $cpt_slug);
                $html .= self::insert_zone('row', __('Add a new row here', 'lrob-email-toolkit'));
            }

            // Soft warning if the user has no submit anywhere.
            if (!FormStructure::has_field_of_type($structure, 'submit')) {
                $html .= sprintf(
                    '<p class="lrob-etk-form-editor-warn">%s</p>',
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
    private static function render_row(array $row, string $cpt_slug): string
    {
        $cols = max(1, count($row['columns']));
        $html  = sprintf(
            '<div class="lrob-etk-form-row" data-cols="%d" data-row-id="%s" data-draggable-type="row" draggable="true">',
            $cols,
            esc_attr($row['id'])
        );

        $html .= self::row_overlay();

        foreach ($row['columns'] as $col) {
            $html .= self::render_column($col, $cpt_slug);
        }

        // Column-insert "+" at the right end of the row when there's still
        // room for another column.
        if ($cols < 4) {
            $html .= self::insert_zone('column', __('Add a column to this row', 'lrob-email-toolkit'));
        }

        $html .= '</div>';
        return $html;
    }

    /** @param array{id:string, fields:array<int, array>} $col */
    private static function render_column(array $col, string $cpt_slug): string
    {
        $html  = sprintf(
            '<div class="lrob-etk-form-col" data-col-id="%s" data-draggable-type="col" draggable="true">',
            esc_attr($col['id'])
        );
        $html .= self::col_overlay();

        $html .= self::insert_zone('field', __('Add a field here', 'lrob-email-toolkit'));

        foreach ($col['fields'] as $field) {
            $html .= self::render_field($field, $cpt_slug);
            $html .= self::insert_zone('field', __('Add a field here', 'lrob-email-toolkit'));
        }

        $html .= '</div>';
        return $html;
    }

    /** @param array<string, mixed> $field */
    private static function render_field(array $field, string $cpt_slug): string
    {
        $type = (string) ($field['type'] ?? '');
        $id   = (string) ($field['id'] ?? '');

        $registry = self::registry();
        $field_type = $registry !== null ? $registry->get($cpt_slug, $type) : null;
        if ($field_type === null) {
            return '';
        }
        $inner = $field_type->render($field);
        if ($inner === '') {
            return '';
        }

        return sprintf(
            '<div class="lrob-etk-form-edit-shell" data-field-id="%s" data-field-type="%s" data-draggable-type="field" draggable="true">%s%s</div>',
            esc_attr($id),
            esc_attr($type),
            self::field_overlay(),
            $inner
        );
    }

    /**
     * Insertion zone — collapsed by default, expanded by the editor JS when
     * the cursor approaches. Row and field zones carry text labels ("+
     * Field") so the user can tell which kind of insert they'd get; the
     * column zone is a tighter "+" pill anchored to the row's right edge.
     */
    private static function insert_zone(string $kind, string $aria): string
    {
        // Body-level inserts (kind=row in DOM terms) read as "+ Field" to
        // the user — they're inserting a field into the form; the fact that
        // the markup wraps it in a single-column row is an implementation
        // detail. Inside a multi-column block, kind=field has the same
        // label since the user is also just inserting a field. The
        // kind=column zone stays a bare "+" since it's clearly the row's
        // add-column affordance.
        $plus = '<span class="lrob-etk-form-insert-plus" aria-hidden="true">+</span>';
        $body = match ($kind) {
            'row', 'field' => $plus . '<span class="lrob-etk-form-insert-label">' . esc_html__('Field', 'lrob-email-toolkit') . '</span>',
            default        => $plus,
        };
        return sprintf(
            '<button type="button" class="lrob-etk-form-insert lrob-etk-form-insert--%s" data-insert="%s" aria-label="%s">%s</button>',
            esc_attr($kind),
            esc_attr($kind),
            esc_attr($aria),
            $body
        );
    }

    private static function row_overlay(): string
    {
        return '<div class="lrob-etk-form-overlay lrob-etk-form-overlay--row" aria-hidden="true">'
            . '<span class="lrob-etk-form-overlay-handle dashicons dashicons-move" title="' . esc_attr__('Drag to reorder row', 'lrob-email-toolkit') . '"></span>'
            . '<button type="button" class="lrob-etk-form-overlay-btn" data-action="delete-row" title="' . esc_attr__('Delete row', 'lrob-email-toolkit') . '">'
            . '<span class="dashicons dashicons-trash"></span></button>'
            . '</div>';
    }

    private static function col_overlay(): string
    {
        return '<div class="lrob-etk-form-overlay lrob-etk-form-overlay--col" aria-hidden="true">'
            . '<span class="lrob-etk-form-overlay-handle dashicons dashicons-move" title="' . esc_attr__('Drag to reorder column', 'lrob-email-toolkit') . '"></span>'
            . '<button type="button" class="lrob-etk-form-overlay-btn" data-action="delete-col" title="' . esc_attr__('Delete column', 'lrob-email-toolkit') . '">'
            . '<span class="dashicons dashicons-trash"></span></button>'
            . '</div>';
    }

    private static function field_overlay(): string
    {
        return '<div class="lrob-etk-form-overlay lrob-etk-form-overlay--field" aria-hidden="true">'
            . '<span class="lrob-etk-form-overlay-handle dashicons dashicons-move" title="' . esc_attr__('Drag to reorder field', 'lrob-email-toolkit') . '"></span>'
            . '<button type="button" class="lrob-etk-form-overlay-btn lrob-etk-form-overlay-btn--delete" data-action="delete-field" title="' . esc_attr__('Delete field', 'lrob-email-toolkit') . '">'
            . '<span class="dashicons dashicons-trash"></span></button>'
            . '</div>';
    }

    private static function registry(): ?FieldTypeRegistry
    {
        $container = Plugin::instance()->container();
        $registry = $container->get(FieldTypeRegistry::class);
        return $registry instanceof FieldTypeRegistry ? $registry : null;
    }
}
