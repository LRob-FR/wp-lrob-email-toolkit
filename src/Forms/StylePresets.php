<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

// Docs: docs/forms.md → "Form theming". Presets are DATA (var maps), resolved
// to inline `--lrob-etk-cf-*` vars by StyleResolver — no per-preset CSS classes.
final class StylePresets
{
    public const DEFAULT_SLUG = 'default';

    /**
     * Catalogue of styleable vars: schemaKey → {var, type, label}. Single
     * source of truth for the presets below, StyleResolver's sanitiser, and
     * (later) the per-form "Customize" knobs.
     *
     * @return array<string, array{var:string, type:string, label:string}>
     */
    public static function schema(): array
    {
        return [
            'accent'    => ['var' => '--lrob-etk-cf-accent',    'type' => 'color', 'label' => __('Accent', 'lrob-email-toolkit')],
            'accent_fg' => ['var' => '--lrob-etk-cf-accent-fg', 'type' => 'color', 'label' => __('Button text', 'lrob-email-toolkit')],
            'bg'        => ['var' => '--lrob-etk-cf-bg',        'type' => 'color', 'label' => __('Field background', 'lrob-email-toolkit')],
            'input_fg'  => ['var' => '--lrob-etk-cf-input-fg',  'type' => 'color', 'label' => __('Field text', 'lrob-email-toolkit')],
            'fg'        => ['var' => '--lrob-etk-cf-fg',        'type' => 'color', 'label' => __('Label text', 'lrob-email-toolkit')],
            'muted'     => ['var' => '--lrob-etk-cf-muted',     'type' => 'color', 'label' => __('Helper text', 'lrob-email-toolkit')],
            'border'    => ['var' => '--lrob-etk-cf-border',    'type' => 'color', 'label' => __('Border', 'lrob-email-toolkit')],
            'radius'    => ['var' => '--lrob-etk-cf-radius',    'type' => 'size',  'label' => __('Corner radius', 'lrob-email-toolkit')],
            'spacing'   => ['var' => '--lrob-etk-cf-spacing',   'type' => 'size',  'label' => __('Field spacing', 'lrob-email-toolkit')],
            'font_size' => ['var' => '--lrob-etk-cf-font-size', 'type' => 'size',  'label' => __('Font size', 'lrob-email-toolkit')],
        ];
    }

    /**
     * slug → {label, vars}. `default` carries no vars — it inherits the active
     * theme (FSE palette) via the CSS fallback chain. Every other preset is a
     * pure var swap over the existing contract (no structural CSS).
     *
     * @return array<string, array{label:string, vars:array<string,string>}>
     */
    private static function presets(): array
    {
        return [
            self::DEFAULT_SLUG => ['label' => __('Default', 'lrob-email-toolkit'), 'vars' => []],
            'dark'    => ['label' => __('Dark', 'lrob-email-toolkit'), 'vars' => [
                'bg' => '#0f172a', 'input_fg' => '#e5e7eb', 'fg' => '#e5e7eb',
                'muted' => '#94a3b8', 'border' => '#334155', 'accent' => '#3b82f6', 'accent_fg' => '#ffffff',
            ]],
            'minimal' => ['label' => __('Minimal', 'lrob-email-toolkit'), 'vars' => [
                'radius' => '3px', 'border' => '#e2e8f0',
            ]],
            'rounded' => ['label' => __('Rounded', 'lrob-email-toolkit'), 'vars' => [
                'radius' => '16px', 'accent' => '#8b5cf6', 'accent_fg' => '#ffffff',
            ]],
            'sharp'   => ['label' => __('Sharp', 'lrob-email-toolkit'), 'vars' => [
                'radius' => '0', 'accent' => '#0f172a', 'accent_fg' => '#ffffff', 'border' => '#0f172a',
            ]],
            'ocean'   => ['label' => __('Ocean', 'lrob-email-toolkit'), 'vars' => [
                'accent' => '#0891b2', 'accent_fg' => '#ffffff', 'radius' => '10px', 'border' => '#a5d8e6',
            ]],
        ];
    }

    /** @return array<string, string> slug → translated label (for pickers). */
    public static function all(): array
    {
        $out = [];
        foreach (self::presets() as $slug => $p) {
            $out[$slug] = $p['label'];
        }
        return $out;
    }

    public static function label_for(string $slug): string
    {
        return self::all()[$slug] ?? self::all()[self::DEFAULT_SLUG];
    }

    public static function is_valid(string $slug): bool
    {
        return isset(self::presets()[$slug]);
    }

    /** @return array<string,string> schemaKey → value (raw; StyleResolver sanitises). */
    public static function vars_for(string $slug): array
    {
        $presets = self::presets();
        return isset($presets[$slug]) ? $presets[$slug]['vars'] : [];
    }

    /**
     * For the JS live preview: { presets: { slug: {cssVar: value} }, vars: [cssVar,…] }.
     * `vars` is the full css-var list the preview clears before applying a preset.
     *
     * @return array{presets:array<string,array<string,string>>, vars:list<string>}
     */
    public static function js_data(): array
    {
        $schema = self::schema();
        $presets = [];
        foreach (self::presets() as $slug => $p) {
            $vars = [];
            foreach ($p['vars'] as $key => $val) {
                if (isset($schema[$key])) {
                    $vars[$schema[$key]['var']] = $val;
                }
            }
            $presets[$slug] = $vars;
        }
        $all_vars = [];
        foreach ($schema as $entry) {
            $all_vars[] = $entry['var'];
        }
        return ['presets' => $presets, 'vars' => $all_vars];
    }
}
