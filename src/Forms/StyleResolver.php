<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

// Docs: docs/forms.md → "Form theming". Merges the style tiers into the inline
// `--lrob-etk-cf-*` declaration emitted on the <form>. Precedence (later wins):
// preset baseline < global default < per-form override < block override.
final class StyleResolver
{
    /**
     * @param array<string,string> $per_form schemaKey → value (per-form meta)
     * @param array<string,string> $global   schemaKey → value (module defaults)
     * @param array<string,string> $block    schemaKey → value (Gutenberg block attrs)
     */
    public static function inline_style(string $preset, array $per_form = [], array $global = [], array $block = []): string
    {
        $schema = StylePresets::schema();
        $merged = array_merge(
            StylePresets::vars_for($preset),
            self::clean($global, $schema),
            self::clean($per_form, $schema),
            self::clean($block, $schema)
        );

        $out = [];
        foreach ($merged as $key => $raw) {
            if (!isset($schema[$key])) {
                continue;
            }
            $value = self::sanitize_value((string) $schema[$key]['type'], (string) $raw);
            if ($value !== '') {
                $out[] = $schema[$key]['var'] . ':' . $value;
            }
        }
        return implode(';', $out);
    }

    /**
     * @param array<string,mixed> $vars
     * @param array<string,array{var:string,type:string,label:string}> $schema
     * @return array<string,string>
     */
    private static function clean(array $vars, array $schema): array
    {
        $out = [];
        foreach ($vars as $key => $value) {
            if (isset($schema[$key]) && is_scalar($value) && trim((string) $value) !== '') {
                $out[$key] = trim((string) $value);
            }
        }
        return $out;
    }

    /**
     * Validate a stored override map (schemaKey → value): drop unknown keys and
     * values that fail the colour/size sanitiser. Returns JSON ('' when empty).
     * Shared by the Contact Form + Newsletter save handlers.
     */
    public static function sanitize_map(mixed $value): string
    {
        $decoded = is_string($value) ? json_decode($value, true) : (is_array($value) ? $value : null);
        if (!is_array($decoded)) {
            return '';
        }
        $schema = StylePresets::schema();
        $clean = [];
        foreach ($decoded as $key => $raw) {
            if (!isset($schema[$key]) || !is_scalar($raw)) {
                continue;
            }
            $safe = self::sanitize_value((string) $schema[$key]['type'], (string) $raw);
            if ($safe !== '') {
                $clean[$key] = $safe;
            }
        }
        return $clean === [] ? '' : (string) wp_json_encode($clean);
    }

    /**
     * Allow only safe colour/size tokens — blocks `;`, `}`, `url(`, etc. so a
     * stored value can't break out of the inline style declaration.
     */
    public static function sanitize_value(string $type, string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if ($type === 'color') {
            if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $raw)) {
                return $raw;
            }
            if (preg_match('#^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s/]+\)$#', $raw)) {
                return $raw;
            }
            if (preg_match('/^[a-z]{3,20}$/i', $raw)) {
                return strtolower($raw); // CSS named colour keyword
            }
            return '';
        }
        if ($type === 'size') {
            if (preg_match('/^(?:0|[0-9]*\.?[0-9]+(?:px|rem|em|%|vw|vh))$/', $raw)) {
                return $raw;
            }
            return '';
        }
        return '';
    }
}
