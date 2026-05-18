<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Owns the form's row/column/field structure. Stored as JSON in the CPT's
 * `post_content` so it's a single round-trip read, plays nicely with
 * revisions, and survives `wp_delete_post` cleanups.
 *
 * Shape (version 1):
 *   {
 *     "version": 1,
 *     "submit": { "text": "Send", "align": "right" },
 *     "rows": [
 *       { "id": "row_…", "columns": [
 *         { "id": "col_…", "fields": [
 *           { "id": "f_…", "type": "text", "slug": "name", "label": "Your name", "required": true, ...type-specific }
 *         ]}
 *       ]}
 *     ]
 *   }
 *
 * Any post_content that doesn't decode to this shape (e.g. legacy Gutenberg
 * block markup, plain text, garbage) is treated as empty — by design. The
 * user accepted "no migration, clear if any" for the Gutenberg→JSON switch.
 *
 * normalize() is the single sanitizer: it drops malformed entries silently
 * rather than rejecting the whole payload. That means an out-of-date editor
 * JS or a corrupted save can never lock the user out of their form.
 */
final class FormStructure
{
    public const VERSION = 1;

    private const MAX_COLUMNS_PER_ROW = 4;

    private const FIELD_TYPES = [
        'text', 'email', 'textarea', 'number', 'phone', 'date',
        'select', 'radio', 'checkbox',
    ];

    /** @return array{version:int, submit:array{text:string, align:string}, rows:array<int, array>} */
    public static function load(int $form_id): array
    {
        $content = (string) get_post_field('post_content', $form_id);
        if ($content === '') {
            return self::empty_structure();
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['rows'])) {
            // Legacy block content or non-JSON — start fresh. The user
            // explicitly opted into "clear, no migration".
            return self::empty_structure();
        }
        return self::normalize($decoded);
    }

    /** @param array $structure */
    public static function save(int $form_id, array $structure): void
    {
        $clean = self::normalize($structure);
        wp_update_post([
            'ID'           => $form_id,
            'post_content' => (string) wp_json_encode($clean),
        ]);
    }

    /** @return array{version:int, submit:array{text:string, align:string}, rows:array<int, array>} */
    public static function empty_structure(): array
    {
        return [
            'version' => self::VERSION,
            'submit'  => ['text' => __('Send', 'lrob-email-toolkit'), 'align' => 'right'],
            'rows'    => [],
        ];
    }

    /**
     * Sanitize an arbitrary input into the canonical shape. Drops invalid
     * entries (rather than rejecting the whole payload) so a partially-bad
     * client update doesn't wipe the form.
     *
     * @param array<string, mixed> $structure
     * @return array{version:int, submit:array{text:string, align:string}, rows:array<int, array>}
     */
    public static function normalize(array $structure): array
    {
        $out = self::empty_structure();

        if (isset($structure['submit']) && is_array($structure['submit'])) {
            $text = isset($structure['submit']['text']) ? sanitize_text_field((string) $structure['submit']['text']) : '';
            if ($text === '') {
                $text = __('Send', 'lrob-email-toolkit');
            }
            $align = isset($structure['submit']['align']) ? (string) $structure['submit']['align'] : 'right';
            if (!in_array($align, ['left', 'center', 'right', 'stretch'], true)) {
                $align = 'right';
            }
            $out['submit'] = ['text' => $text, 'align' => $align];
        }

        if (!isset($structure['rows']) || !is_array($structure['rows'])) {
            return $out;
        }

        foreach ($structure['rows'] as $row) {
            $clean_row = self::normalize_row($row);
            if ($clean_row !== null) {
                $out['rows'][] = $clean_row;
            }
        }

        return $out;
    }

    /** @return array{id:string, columns:array<int, array{id:string, fields:array<int, array>}>}|null */
    private static function normalize_row(mixed $row): ?array
    {
        if (!is_array($row) || !isset($row['columns']) || !is_array($row['columns'])) {
            return null;
        }
        $id = isset($row['id']) && is_string($row['id']) && $row['id'] !== ''
            ? sanitize_key($row['id'])
            : self::gen_id('row');
        $cols = [];
        $count = 0;
        foreach ($row['columns'] as $col) {
            if ($count >= self::MAX_COLUMNS_PER_ROW) {
                break;
            }
            $clean_col = self::normalize_column($col);
            if ($clean_col !== null) {
                $cols[] = $clean_col;
                $count++;
            }
        }
        if ($cols === []) {
            return null;
        }
        return ['id' => $id, 'columns' => $cols];
    }

    /** @return array{id:string, fields:array<int, array>}|null */
    private static function normalize_column(mixed $col): ?array
    {
        if (!is_array($col)) {
            return null;
        }
        $id = isset($col['id']) && is_string($col['id']) && $col['id'] !== ''
            ? sanitize_key($col['id'])
            : self::gen_id('col');
        $fields = [];
        if (isset($col['fields']) && is_array($col['fields'])) {
            foreach ($col['fields'] as $field) {
                $clean = self::normalize_field($field);
                if ($clean !== null) {
                    $fields[] = $clean;
                }
            }
        }
        return ['id' => $id, 'fields' => $fields];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalize_field(mixed $field): ?array
    {
        if (!is_array($field)) {
            return null;
        }
        $type = isset($field['type']) ? (string) $field['type'] : '';
        if (!in_array($type, self::FIELD_TYPES, true)) {
            return null;
        }
        $clean = [
            'id'          => isset($field['id']) && is_string($field['id']) && $field['id'] !== ''
                ? sanitize_key($field['id'])
                : self::gen_id('f'),
            'type'        => $type,
            'slug'        => isset($field['slug']) ? sanitize_key((string) $field['slug']) : '',
            'label'       => isset($field['label']) ? sanitize_text_field((string) $field['label']) : '',
            'helper'      => isset($field['helper']) ? sanitize_text_field((string) $field['helper']) : '',
            'placeholder' => isset($field['placeholder']) ? sanitize_text_field((string) $field['placeholder']) : '',
            'required'    => !empty($field['required']),
        ];
        // Type-specific keys.
        switch ($type) {
            case 'text':
            case 'email':
                if (isset($field['maxLength'])) {
                    $clean['maxLength'] = max(0, (int) $field['maxLength']);
                }
                break;
            case 'textarea':
                if (isset($field['rows'])) {
                    $clean['rows'] = max(2, (int) $field['rows']);
                }
                if (isset($field['maxLength'])) {
                    $clean['maxLength'] = max(0, (int) $field['maxLength']);
                }
                break;
            case 'number':
                foreach (['min', 'max', 'step'] as $k) {
                    if (isset($field[$k]) && $field[$k] !== '') {
                        $clean[$k] = sanitize_text_field((string) $field[$k]);
                    }
                }
                break;
            case 'phone':
                if (isset($field['pattern']) && $field['pattern'] !== '') {
                    $clean['pattern'] = (string) $field['pattern'];
                }
                break;
            case 'date':
                foreach (['min', 'max'] as $k) {
                    if (isset($field[$k]) && $field[$k] !== '') {
                        $clean[$k] = sanitize_text_field((string) $field[$k]);
                    }
                }
                break;
            case 'select':
            case 'radio':
                $clean['options'] = self::normalize_options($field['options'] ?? []);
                break;
            case 'checkbox':
                $clean['multiple'] = !isset($field['multiple']) || !empty($field['multiple']);
                $clean['options']  = self::normalize_options($field['options'] ?? []);
                break;
        }
        return $clean;
    }

    /** @return array<int, array{value:string, label:string}> */
    private static function normalize_options(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $opt) {
            if (is_string($opt) && $opt !== '') {
                $out[] = ['value' => sanitize_title($opt), 'label' => sanitize_text_field($opt)];
                continue;
            }
            if (is_array($opt)) {
                $label = isset($opt['label']) ? sanitize_text_field((string) $opt['label']) : '';
                $value = isset($opt['value']) ? sanitize_title((string) $opt['value']) : '';
                if ($label === '' && $value === '') {
                    continue;
                }
                if ($value === '') {
                    $value = sanitize_title($label);
                }
                $out[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
            }
        }
        return $out;
    }

    private static function gen_id(string $prefix): string
    {
        return $prefix . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
