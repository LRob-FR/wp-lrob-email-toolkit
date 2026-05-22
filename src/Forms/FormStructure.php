<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

use LRob\EmailToolkit\Plugin;

/**
 * Owns the form's row/column/field structure. Stored as JSON in the host
 * CPT's `post_content` — single round-trip read, plays nicely with
 * revisions, survives `wp_delete_post` cleanups, host-CPT-agnostic.
 *
 * Shape (version 1):
 *   {
 *     "version": 1,
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
 * block markup, plain text, garbage) is treated as empty — by design.
 *
 * Field-type sanitisation is delegated to the FieldTypeInterface
 * implementations registered with FieldTypeRegistry for the form's CPT.
 * Unknown types are dropped silently rather than rejecting the whole
 * payload — that means an out-of-date editor JS or a corrupted save can
 * never lock the user out of their form.
 */
final class FormStructure
{
    public const VERSION = 1;

    private const MAX_COLUMNS_PER_ROW = 4;

    /** @return array{version:int, rows:array<int, array>} */
    public static function load(int $form_id): array
    {
        $content = (string) get_post_field('post_content', $form_id);
        if ($content === '') {
            return self::empty_structure();
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['rows'])) {
            return self::empty_structure();
        }

        $cpt_slug = (string) get_post_type($form_id);
        $normalized = self::normalize($decoded, $cpt_slug);

        // One-time legacy migration: forms saved before submit became an
        // in-row field had a top-level `submit` key. If that's still here
        // and no submit field has been added in the rows, append one.
        if (
            isset($decoded['submit']) && is_array($decoded['submit'])
            && !self::has_field_of_type($normalized, 'submit')
        ) {
            $registry = self::registry();
            $submit_type = $registry !== null ? $registry->get($cpt_slug, 'submit') : null;
            if ($submit_type !== null) {
                $submit_field = $submit_type->normalize([
                    'type'  => 'submit',
                    'text'  => (string) ($decoded['submit']['text'] ?? __('Send', 'lrob-email-toolkit')),
                    'align' => (string) ($decoded['submit']['align'] ?? 'right'),
                ]);
                if ($submit_field !== null) {
                    $normalized['rows'][] = [
                        'id'      => FieldRenderHelpers::gen_id('row'),
                        'columns' => [[
                            'id'     => FieldRenderHelpers::gen_id('col'),
                            'fields' => [$submit_field],
                        ]],
                    ];
                    self::save($form_id, $normalized);
                }
            }
        }

        return $normalized;
    }

    /** @param array $structure */
    public static function save(int $form_id, array $structure): void
    {
        $cpt_slug = (string) get_post_type($form_id);
        $clean = self::normalize($structure, $cpt_slug);
        // wp_update_post runs wp_unslash on its inputs, so anything we pass
        // must be wp_slash-ed to survive a round-trip. JSON_UNESCAPED_UNICODE
        // keeps multibyte chars as themselves (no backslash for unslash to
        // strip), and wp_slash handles the few characters JSON does escape.
        $json = (string) wp_json_encode($clean, JSON_UNESCAPED_UNICODE);
        wp_update_post([
            'ID'           => $form_id,
            'post_content' => wp_slash($json),
        ]);
    }

    /** @return array{version:int, rows:array<int, array>} */
    public static function empty_structure(): array
    {
        return [
            'version' => self::VERSION,
            'rows'    => [],
        ];
    }

    /**
     * Flat slug → {label, type} index of every field in the structure. Used
     * by the submissions inbox to render stored values with their human
     * labels (submissions store slugs, not labels — labels can change).
     *
     * @return array<string, array{label:string, type:string}>
     */
    public static function fields_index(array $structure): array
    {
        $out = [];
        if (!isset($structure['rows']) || !is_array($structure['rows'])) {
            return $out;
        }
        foreach ($structure['rows'] as $row) {
            if (!is_array($row['columns'] ?? null)) {
                continue;
            }
            foreach ($row['columns'] as $col) {
                if (!is_array($col['fields'] ?? null)) {
                    continue;
                }
                foreach ($col['fields'] as $f) {
                    if (!is_array($f)) {
                        continue;
                    }
                    $slug = (string) ($f['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $out[$slug] = [
                        'label' => (string) ($f['label'] ?? $slug),
                        'type'  => (string) ($f['type'] ?? 'text'),
                    ];
                }
            }
        }
        return $out;
    }

    /** True if the structure contains at least one field of the given type. */
    public static function has_field_of_type(array $structure, string $type): bool
    {
        if (!isset($structure['rows']) || !is_array($structure['rows'])) {
            return false;
        }
        foreach ($structure['rows'] as $row) {
            if (!is_array($row['columns'] ?? null)) {
                continue;
            }
            foreach ($row['columns'] as $col) {
                if (!is_array($col['fields'] ?? null)) {
                    continue;
                }
                foreach ($col['fields'] as $f) {
                    if (is_array($f) && (string) ($f['type'] ?? '') === $type) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Sanitize an arbitrary input into the canonical shape. Drops invalid
     * entries (rather than rejecting the whole payload) so a partially-bad
     * client update doesn't wipe the form. Field-type normalization is
     * delegated to the FieldTypeInterface registered for the CPT.
     *
     * @param array<string, mixed> $structure
     * @return array{version:int, rows:array<int, array>}
     */
    public static function normalize(array $structure, string $cpt_slug): array
    {
        $out = self::empty_structure();

        if (!isset($structure['rows']) || !is_array($structure['rows'])) {
            return $out;
        }

        foreach ($structure['rows'] as $row) {
            $clean_row = self::normalize_row($row, $cpt_slug);
            if ($clean_row !== null) {
                $out['rows'][] = $clean_row;
            }
        }

        self::enforce_unique_nths_and_slugs($out);

        return $out;
    }

    /**
     * Post-pass over the normalised tree. Walks every field, assigns a
     * creation-order `nth` to anything that doesn't have one (in DOM
     * order), forces duplicate nths apart, and finally guarantees unique
     * slugs by suffixing collisions with `_2`, `_3`, ... — safety net in
     * case two fields somehow end up with the same `<type>_<label>_<nth>`
     * shape (e.g. legacy data with hand-typed slugs).
     *
     * @param array{rows:array<int, array{columns:array<int, array{fields:array<int, array>}>}>} $out
     */
    private static function enforce_unique_nths_and_slugs(array &$out): void
    {
        $max_nth = 0;
        // Pass 1: discover the highest existing nth.
        foreach ($out['rows'] as $row) {
            foreach ($row['columns'] as $col) {
                foreach ($col['fields'] as $f) {
                    if (isset($f['nth']) && (int) $f['nth'] > $max_nth) {
                        $max_nth = (int) $f['nth'];
                    }
                }
            }
        }
        // Pass 2: backfill missing or duplicated nths.
        $seen_nths = [];
        foreach ($out['rows'] as $ri => $row) {
            foreach ($row['columns'] as $ci => $col) {
                foreach ($col['fields'] as $fi => $f) {
                    $nth = (int) ($f['nth'] ?? 0);
                    if ($nth <= 0 || isset($seen_nths[$nth])) {
                        $nth = ++$max_nth;
                    }
                    $seen_nths[$nth] = true;
                    $out['rows'][$ri]['columns'][$ci]['fields'][$fi]['nth'] = $nth;
                }
            }
        }
        // Pass 3: enforce slug uniqueness across the form.
        $seen_slugs = [];
        foreach ($out['rows'] as $ri => $row) {
            foreach ($row['columns'] as $ci => $col) {
                foreach ($col['fields'] as $fi => $f) {
                    $slug = (string) ($f['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $base = $slug;
                    $n = 2;
                    while (isset($seen_slugs[$slug])) {
                        $slug = $base . '_' . $n;
                        $n++;
                    }
                    $seen_slugs[$slug] = true;
                    $out['rows'][$ri]['columns'][$ci]['fields'][$fi]['slug'] = $slug;
                }
            }
        }
    }

    /** @return array{id:string, columns:array<int, array{id:string, fields:array<int, array>}>}|null */
    private static function normalize_row(mixed $row, string $cpt_slug): ?array
    {
        if (!is_array($row) || !isset($row['columns']) || !is_array($row['columns'])) {
            return null;
        }
        $id = isset($row['id']) && is_string($row['id']) && $row['id'] !== ''
            ? sanitize_key($row['id'])
            : FieldRenderHelpers::gen_id('row');
        $cols = [];
        $count = 0;
        foreach ($row['columns'] as $col) {
            if ($count >= self::MAX_COLUMNS_PER_ROW) {
                break;
            }
            $clean_col = self::normalize_column($col, $cpt_slug);
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
    private static function normalize_column(mixed $col, string $cpt_slug): ?array
    {
        if (!is_array($col)) {
            return null;
        }
        $id = isset($col['id']) && is_string($col['id']) && $col['id'] !== ''
            ? sanitize_key($col['id'])
            : FieldRenderHelpers::gen_id('col');
        $fields = [];
        if (isset($col['fields']) && is_array($col['fields'])) {
            foreach ($col['fields'] as $field) {
                $clean = self::normalize_field($field, $cpt_slug);
                if ($clean !== null) {
                    $fields[] = $clean;
                }
            }
        }
        return ['id' => $id, 'fields' => $fields];
    }

    /** @return array<string, mixed>|null */
    private static function normalize_field(mixed $field, string $cpt_slug): ?array
    {
        if (!is_array($field)) {
            return null;
        }
        $type = isset($field['type']) ? (string) $field['type'] : '';
        if ($type === '') {
            return null;
        }
        $registry = self::registry();
        if ($registry === null) {
            return null;
        }
        $field_type = $registry->get($cpt_slug, $type);
        if ($field_type === null) {
            // Unknown type for this CPT — drop silently. Prevents a
            // contact-form field type from leaking into a Newsletter form
            // and vice versa.
            return null;
        }
        return $field_type->normalize($field);
    }

    private static function registry(): ?FieldTypeRegistry
    {
        $container = Plugin::instance()->container();
        $registry = $container->get(FieldTypeRegistry::class);
        return $registry instanceof FieldTypeRegistry ? $registry : null;
    }
}
