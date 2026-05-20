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
        // Special field types — the submit button and the anti-spam captcha
        // live INSIDE the rows now so the user can place them anywhere.
        'submit', 'captcha',
    ];

    /** @return array{version:int, rows:array<int, array>} */
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

        $normalized = self::normalize($decoded);

        // One-time migration: if the structure still has the pre-WYSIWYG
        // top-level `submit` key and no submit field exists in the rows yet,
        // append one carrying its text/align so the user doesn't lose them.
        if (isset($decoded['submit']) && is_array($decoded['submit']) && !self::has_field_of_type($normalized, 'submit')) {
            $normalized['rows'][] = [
                'id'      => self::gen_id('row'),
                'columns' => [[
                    'id'     => self::gen_id('col'),
                    'fields' => [self::normalize_field([
                        'id'    => self::gen_id('f'),
                        'type'  => 'submit',
                        'text'  => (string) ($decoded['submit']['text'] ?? __('Send', 'lrob-email-toolkit')),
                        'align' => (string) ($decoded['submit']['align'] ?? 'right'),
                    ])],
                ]],
            ];
            self::save($form_id, $normalized);
        }

        return $normalized;
    }

    /** @param array $structure */
    public static function save(int $form_id, array $structure): void
    {
        $clean = self::normalize($structure);
        // `wp_update_post` runs `wp_unslash` on its inputs, so anything we
        // pass must be `wp_slash`-ed to survive a round-trip. `wp_json_encode`
        // defaults to escaping non-ASCII characters (e.g. "—" → "—"),
        // and the internal unslash would strip the backslash from each
        // `—`, persisting literal "u2014" text in the DB. `JSON_
        // UNESCAPED_UNICODE` keeps multibyte chars as themselves (no
        // backslash to strip); `wp_slash` then handles the few characters
        // JSON does escape (e.g. `\"`, `\\`).
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
     * client update doesn't wipe the form.
     *
     * @param array<string, mixed> $structure
     * @return array{version:int, rows:array<int, array>}
     */
    public static function normalize(array $structure): array
    {
        $out = self::empty_structure();

        if (!isset($structure['rows']) || !is_array($structure['rows'])) {
            return $out;
        }

        foreach ($structure['rows'] as $row) {
            $clean_row = self::normalize_row($row);
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
     * slugs by suffixing collisions with `_2`, `_3`, ... — a safety net
     * in case two fields somehow end up with the same `<type>_<label>_
     * <nth>` shape (e.g. legacy data with hand-typed slugs).
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
            // Stable creation-order index — survives reordering and
            // deletions of other fields so slugs (`<type>_<label>_<nth>`)
            // stay attached to their original field across edits. 0 here
            // means "not yet assigned"; the post-pass in normalize()
            // backfills any 0 with the next free index.
            'nth'         => isset($field['nth']) ? max(0, (int) $field['nth']) : 0,
            'label'       => isset($field['label']) ? self::recover_unicode_escapes(sanitize_text_field((string) $field['label'])) : '',
            'helper'      => isset($field['helper']) ? self::recover_unicode_escapes(sanitize_text_field((string) $field['helper'])) : '',
            'placeholder' => isset($field['placeholder']) ? self::recover_unicode_escapes(sanitize_text_field((string) $field['placeholder'])) : '',
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
                $clean['options']  = self::normalize_options($field['options'] ?? []);
                // A native <select> is always single-choice in this plugin —
                // multi-pick lists belong in the checkbox field type. Cap
                // defaults at one entry regardless of what's stored.
                $clean['defaults'] = self::normalize_defaults($field['defaults'] ?? [], $clean['options'], false);
                break;
            case 'radio':
                $clean['options'] = self::normalize_options($field['options'] ?? []);
                break;
            case 'checkbox':
                $clean['multiple'] = !isset($field['multiple']) || !empty($field['multiple']);
                $clean['options']  = self::normalize_options($field['options'] ?? []);
                break;
            case 'submit':
                $text = isset($field['text']) ? sanitize_text_field((string) $field['text']) : '';
                if ($text === '') {
                    $text = __('Send', 'lrob-email-toolkit');
                }
                $align = isset($field['align']) ? (string) $field['align'] : 'right';
                if (!in_array($align, ['left', 'center', 'right', 'stretch'], true)) {
                    $align = 'right';
                }
                $clean['text']  = $text;
                $clean['align'] = $align;
                // Submit has no slug/label/helper/placeholder/required.
                unset($clean['slug'], $clean['label'], $clean['helper'], $clean['placeholder'], $clean['required']);
                break;
            case 'captcha':
                // The challenge method itself is resolved from per-form
                // META_CHALLENGE_KIND (a routing key) → CaptchaService at
                // render time; the only per-field attr is the visual
                // alignment (left / center / right — no stretch since
                // hCaptcha's iframe is fixed-width).
                $captcha_align = isset($clean['align']) ? (string) $clean['align'] : 'center';
                $clean['align'] = in_array($captcha_align, ['left', 'center', 'right'], true)
                    ? $captcha_align
                    : 'center';
                unset($clean['slug'], $clean['label'], $clean['helper'], $clean['placeholder'], $clean['required']);
                break;
        }
        return $clean;
    }

    private static function gen_id(string $prefix): string
    {
        return $prefix . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /**
     * Recover literal "uXXXX" sequences (and "\uXXXX" if any survived)
     * that were left in stored strings by forms saved before wp_update_
     * post's internal unslash was compensated for. The unslash stripped
     * the leading backslash from each `\uXXXX` JSON escape produced by
     * `wp_json_encode`'s default unicode escaping, persisting plain
     * "u2014" / "u2026" / etc. as text in the DB. Loose match — bounded
     * by non-alphanumeric neighbours so naturally-occurring words aren't
     * touched.
     */
    private static function recover_unicode_escapes(string $s): string
    {
        if ($s === '' || strpos($s, 'u') === false) {
            return $s;
        }
        return (string) preg_replace_callback(
            '/(?<![A-Za-z0-9])\\\\?u([0-9a-fA-F]{4})(?![A-Za-z0-9])/',
            function ($m) {
                return mb_convert_encoding(pack('n', hexdec($m[1])), 'UTF-8', 'UTF-16BE');
            },
            $s
        );
    }

    /**
     * Sanitise the `defaults` list (option values that start pre-selected on
     * select fields). Drops anything not present in $options. Caps the list
     * to a single entry when $multiple is false.
     *
     * @param array<int, array{value:string, label:string}> $options
     * @return array<int, string>
     */
    private static function normalize_defaults(mixed $raw, array $options, bool $multiple): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $valid = array_column($options, 'value');
        $out = [];
        foreach ($raw as $v) {
            if (!is_scalar($v)) {
                continue;
            }
            $v = (string) $v;
            if ($v !== '' && in_array($v, $valid, true) && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        if (!$multiple && count($out) > 1) {
            $out = [$out[0]];
        }
        return $out;
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
}
