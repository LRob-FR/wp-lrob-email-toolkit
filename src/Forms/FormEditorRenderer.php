<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

use LRob\EmailToolkit\Plugin;

// Docs: docs/forms.md — editor rendering. DOM contract: docs/form-builder.md.
final class FormEditorRenderer
{
    public static function render(int $form_id, string $name_prefix, string $id_prefix): string
    {
        $structure = FormStructure::load($form_id);
        $cpt_slug = (string) get_post_type($form_id);

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

        $maps_to_attr = '';
        if (!empty($field['maps_to'])) {
            $maps_to_attr = ' data-attr-maps_to="' . esc_attr((string) $field['maps_to']) . '"';
        }
        return sprintf(
            '<div class="lrob-etk-form-edit-shell" data-field-id="%s" data-field-type="%s" data-draggable-type="field" draggable="true"%s>%s%s</div>',
            esc_attr($id),
            esc_attr($type),
            $maps_to_attr,
            self::field_overlay(),
            $inner
        );
    }

    private static function insert_zone(string $kind, string $aria): string
    {
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
