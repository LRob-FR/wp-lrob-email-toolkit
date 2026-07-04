<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

// Docs: docs/forms.md → "Form theming". Everything is DATA: the styleable-var
// catalogue (schema), the named sub-preset axes (colour/roundness/spacing/…),
// and the full presets. StyleResolver turns a chosen set into inline
// `--lrob-etk-cf-*` vars; StyleControls renders the dropdown-per-axis editor.
final class StylePresets
{
    public const DEFAULT_SLUG = 'default';

    /**
     * Every styleable property: schemaKey → metadata. `group` buckets the
     * per-axis Edit popovers; `type` picks the control; `options` (select) are
     * value → label. Single source for axes, presets, resolver + editor.
     *
     * @return array<string, array{var:string, type:string, group:string, label:string, options?:array<string,string>}>
     */
    public static function schema(): array
    {
        $fonts = self::font_options();
        return [
            // colours
            'accent'       => ['var' => '--lrob-etk-cf-accent',      'type' => 'color', 'group' => 'color', 'label' => __('Accent (focus, links)', 'lrob-email-toolkit')],
            'btn_bg'       => ['var' => '--lrob-etk-cf-btn-bg',      'type' => 'color', 'group' => 'color', 'label' => __('Button background', 'lrob-email-toolkit')],
            'accent_fg'    => ['var' => '--lrob-etk-cf-accent-fg',   'type' => 'color', 'group' => 'color', 'label' => __('Button text', 'lrob-email-toolkit')],
            'form_bg'      => ['var' => '--lrob-etk-cf-form-bg',     'type' => 'color', 'group' => 'color', 'label' => __('Form background', 'lrob-email-toolkit')],
            'bg'           => ['var' => '--lrob-etk-cf-bg',          'type' => 'color', 'group' => 'color', 'label' => __('Field background', 'lrob-email-toolkit')],
            'input_fg'     => ['var' => '--lrob-etk-cf-input-fg',    'type' => 'color', 'group' => 'color', 'label' => __('Field text', 'lrob-email-toolkit')],
            'fg'           => ['var' => '--lrob-etk-cf-fg',          'type' => 'color', 'group' => 'color', 'label' => __('Label text', 'lrob-email-toolkit')],
            'muted'        => ['var' => '--lrob-etk-cf-muted',       'type' => 'color', 'group' => 'color', 'label' => __('Helper text', 'lrob-email-toolkit')],
            'border'       => ['var' => '--lrob-etk-cf-border',      'type' => 'color', 'group' => 'color', 'label' => __('Field border', 'lrob-email-toolkit')],
            'form_border'  => ['var' => '--lrob-etk-cf-form-border', 'type' => 'color', 'group' => 'color', 'label' => __('Form border', 'lrob-email-toolkit')],
            // roundness
            'radius'       => ['var' => '--lrob-etk-cf-radius',       'type' => 'size', 'group' => 'roundness', 'label' => __('Field corners', 'lrob-email-toolkit')],
            'btn_radius'   => ['var' => '--lrob-etk-cf-btn-radius',   'type' => 'size', 'group' => 'roundness', 'label' => __('Button corners', 'lrob-email-toolkit')],
            'border_width' => ['var' => '--lrob-etk-cf-border-width', 'type' => 'size', 'group' => 'roundness', 'label' => __('Border width', 'lrob-email-toolkit')],
            // spacing
            'spacing'      => ['var' => '--lrob-etk-cf-spacing',  'type' => 'size', 'group' => 'spacing', 'label' => __('Space between fields', 'lrob-email-toolkit')],
            'form_pad'     => ['var' => '--lrob-etk-cf-form-pad', 'type' => 'size', 'group' => 'spacing', 'label' => __('Form padding', 'lrob-email-toolkit')],
            // font size
            'font_size'    => ['var' => '--lrob-etk-cf-font-size', 'type' => 'size', 'group' => 'font_size', 'label' => __('Font size', 'lrob-email-toolkit')],
            // body font
            'font'         => ['var' => '--lrob-etk-cf-font', 'type' => 'select', 'group' => 'body_font', 'label' => __('Body font', 'lrob-email-toolkit'), 'options' => $fonts],
            // label font + emphasis
            'label_font'   => ['var' => '--lrob-etk-cf-label-font',   'type' => 'select', 'group' => 'label_font', 'label' => __('Label font', 'lrob-email-toolkit'), 'options' => $fonts],
            'label_weight' => ['var' => '--lrob-etk-cf-label-weight', 'type' => 'select', 'group' => 'label_style', 'label' => __('Label weight', 'lrob-email-toolkit'), 'options' => self::weight_options()],
            'label_style'  => ['var' => '--lrob-etk-cf-label-style',  'type' => 'select', 'group' => 'label_style', 'label' => __('Label style', 'lrob-email-toolkit'), 'options' => self::style_options()],
        ];
    }

    /** @return array<string,string> font-family value → label. '' = inherit. */
    public static function font_options(): array
    {
        return [
            ''                                       => __('Theme default', 'lrob-email-toolkit'),
            'system-ui, sans-serif'                  => __('System sans-serif', 'lrob-email-toolkit'),
            'Georgia, "Times New Roman", serif'      => __('Serif (Georgia)', 'lrob-email-toolkit'),
            '"Helvetica Neue", Arial, sans-serif'    => __('Helvetica / Arial', 'lrob-email-toolkit'),
            '"Trebuchet MS", "Segoe UI", sans-serif' => __('Trebuchet', 'lrob-email-toolkit'),
            'Verdana, Geneva, sans-serif'            => __('Verdana', 'lrob-email-toolkit'),
            '"Courier New", monospace'               => __('Monospace', 'lrob-email-toolkit'),
        ];
    }

    /** @return array<string,string> */
    private static function weight_options(): array
    {
        return ['' => __('Default', 'lrob-email-toolkit'), '400' => __('Regular', 'lrob-email-toolkit'), '500' => __('Medium', 'lrob-email-toolkit'), '600' => __('Semibold', 'lrob-email-toolkit'), '700' => __('Bold', 'lrob-email-toolkit')];
    }

    /** @return array<string,string> */
    private static function style_options(): array
    {
        return ['' => __('Default', 'lrob-email-toolkit'), 'normal' => __('Upright', 'lrob-email-toolkit'), 'italic' => __('Italic', 'lrob-email-toolkit')];
    }

    /**
     * Named sub-preset axes. Each axis owns a set of schema keys and a list of
     * one-click options (slug → vars). Picking an option sets its vars and
     * clears the axis's other keys (single-select). 'auto' = inherit (no vars).
     *
     * @return array<string, array{label:string, keys:list<string>, options:array<string, array{label:string, vars:array<string,string>}>}>
     */
    public static function axes(): array
    {
        return [
            'color' => [
                'label' => __('Colour scheme', 'lrob-email-toolkit'),
                'keys'  => ['form_bg', 'bg', 'input_fg', 'fg', 'muted', 'border', 'form_border', 'accent', 'btn_bg', 'accent_fg', 'form_pad'],
                'options' => [
                    'auto'      => ['label' => __('Auto (theme)', 'lrob-email-toolkit'), 'vars' => []],
                    'white'     => ['label' => __('White', 'lrob-email-toolkit'),        'vars' => self::scheme('#ffffff', '#ffffff', '#111827', '#1f2937', '#6b7280', '#d1d5db', '#e5e7eb', '#2563eb')],
                    'dark'      => ['label' => __('Dark', 'lrob-email-toolkit'),         'vars' => self::scheme('#0f172a', '#1e293b', '#e5e7eb', '#e5e7eb', '#94a3b8', '#334155', '#334155', '#3b82f6')],
                    'ocean'     => ['label' => __('Ocean', 'lrob-email-toolkit'),        'vars' => self::scheme('#ecfeff', '#ffffff', '#0e7490', '#164e63', '#5b8a9a', '#a5d8e6', '#bae6fd', '#0891b2')],
                    'forest'    => ['label' => __('Forest', 'lrob-email-toolkit'),       'vars' => self::scheme('#f0fdf4', '#ffffff', '#14532d', '#166534', '#5f8f6f', '#bbf7d0', '#d1fae5', '#16a34a')],
                    'sunset'    => ['label' => __('Sunset', 'lrob-email-toolkit'),       'vars' => self::scheme('#fff7ed', '#ffffff', '#7c2d12', '#9a3412', '#b08968', '#fed7aa', '#fde6cf', '#ea580c')],
                    'deep_blue' => ['label' => __('Deep blue', 'lrob-email-toolkit'),    'vars' => self::scheme('#0c1e3a', '#14274e', '#dbeafe', '#dbeafe', '#93b4d8', '#2a4a7a', '#2a4a7a', '#60a5fa')],
                    'purple'    => ['label' => __('Purple party', 'lrob-email-toolkit'), 'vars' => self::scheme('#faf5ff', '#ffffff', '#581c87', '#6b21a8', '#9d7bb0', '#e9d5ff', '#f0e6fb', '#9333ea')],
                    'pink'      => ['label' => __('Pink', 'lrob-email-toolkit'),         'vars' => self::scheme('#fdf2f8', '#ffffff', '#831843', '#9d174d', '#b8728f', '#fbcfe8', '#fce7f3', '#db2777')],
                ],
            ],
            'roundness' => [
                'label' => __('Roundness', 'lrob-email-toolkit'),
                'keys'  => ['radius', 'btn_radius'],
                'options' => [
                    'sharp'  => ['label' => __('Sharp edges', 'lrob-email-toolkit'), 'vars' => ['radius' => '0', 'btn_radius' => '0']],
                    'soft'   => ['label' => __('Soft edges', 'lrob-email-toolkit'),  'vars' => ['radius' => '6px', 'btn_radius' => '6px']],
                    'roundy' => ['label' => __('Roundy', 'lrob-email-toolkit'),      'vars' => ['radius' => '14px', 'btn_radius' => '14px']],
                    'round'  => ['label' => __('Round', 'lrob-email-toolkit'),       'vars' => ['radius' => '22px', 'btn_radius' => '999px']],
                ],
            ],
            'spacing' => [
                'label' => __('Spacing', 'lrob-email-toolkit'),
                'keys'  => ['spacing'],
                'options' => [
                    'compact'     => ['label' => __('Compact', 'lrob-email-toolkit'),     'vars' => ['spacing' => '.5rem']],
                    'average'     => ['label' => __('Average', 'lrob-email-toolkit'),     'vars' => ['spacing' => '.9rem']],
                    'comfortable' => ['label' => __('Comfortable', 'lrob-email-toolkit'), 'vars' => ['spacing' => '1.3rem']],
                    'spacious'    => ['label' => __('Spacious', 'lrob-email-toolkit'),    'vars' => ['spacing' => '1.8rem']],
                ],
            ],
            'font_size' => [
                'label' => __('Font size', 'lrob-email-toolkit'),
                'keys'  => ['font_size'],
                'options' => [
                    'auto'   => ['label' => __('Auto (theme)', 'lrob-email-toolkit'), 'vars' => []],
                    'small'  => ['label' => __('Small', 'lrob-email-toolkit'),        'vars' => ['font_size' => '.875rem']],
                    'medium' => ['label' => __('Medium', 'lrob-email-toolkit'),       'vars' => ['font_size' => '1rem']],
                    'big'    => ['label' => __('Big', 'lrob-email-toolkit'),          'vars' => ['font_size' => '1.15rem']],
                    'xbig'   => ['label' => __('Extra-big', 'lrob-email-toolkit'),    'vars' => ['font_size' => '1.35rem']],
                ],
            ],
            'label_font' => [
                'label' => __('Label font', 'lrob-email-toolkit'),
                'keys'  => ['label_font'],
                'options' => self::font_axis_options('label_font'),
            ],
            'body_font' => [
                'label' => __('Body font', 'lrob-email-toolkit'),
                'keys'  => ['font'],
                'options' => self::font_axis_options('font'),
            ],
            'label_style' => [
                'label' => __('Label emphasis', 'lrob-email-toolkit'),
                'keys'  => ['label_weight', 'label_style'],
                'options' => [
                    'auto'        => ['label' => __('Default', 'lrob-email-toolkit'),     'vars' => []],
                    'regular'     => ['label' => __('Regular', 'lrob-email-toolkit'),     'vars' => ['label_weight' => '400', 'label_style' => 'normal']],
                    'bold'        => ['label' => __('Bold', 'lrob-email-toolkit'),        'vars' => ['label_weight' => '700', 'label_style' => 'normal']],
                    'italic'      => ['label' => __('Italic', 'lrob-email-toolkit'),      'vars' => ['label_weight' => '400', 'label_style' => 'italic']],
                    'bold_italic' => ['label' => __('Bold italic', 'lrob-email-toolkit'), 'vars' => ['label_weight' => '700', 'label_style' => 'italic']],
                ],
            ],
        ];
    }

    /** Build a colour-scheme var bundle from its key colours. Colour schemes
     * apply a form background, so they also pad the form so the fields don't sit
     * flush against the coloured edge. */
    private static function scheme(string $form_bg, string $bg, string $fg, string $input_fg, string $muted, string $border, string $form_border, string $accent): array
    {
        return [
            'form_bg' => $form_bg, 'bg' => $bg, 'fg' => $fg, 'input_fg' => $input_fg,
            'muted' => $muted, 'border' => $border, 'form_border' => $form_border,
            'accent' => $accent, 'btn_bg' => $accent, 'accent_fg' => '#ffffff',
            'form_pad' => '1.25rem',
        ];
    }

    /** @return array<string, array{label:string, vars:array<string,string>}> font axis options for a given schema key. */
    private static function font_axis_options(string $key): array
    {
        $out = [];
        foreach (self::font_options() as $value => $label) {
            $slug = $value === '' ? 'auto' : sanitize_key(preg_replace('/[^a-z0-9]+/i', '_', $label) ?? $label);
            $out[$slug] = ['label' => $label, 'vars' => $value === '' ? [] : [$key => $value]];
        }
        return $out;
    }

    /**
     * Full presets (the top "Global preset" dropdown): slug → {label, vars}.
     * `default` = inherit theme. A preset bulk-sets the axes below it.
     *
     * @return array<string, array{label:string, vars:array<string,string>}>
     */
    private static function presets(): array
    {
        $axes = self::axes();
        $compose = static function (array $picks) use ($axes): array {
            $vars = [];
            foreach ($picks as $axis => $slug) {
                $vars = array_merge($vars, $axes[$axis]['options'][$slug]['vars'] ?? []);
            }
            return $vars;
        };
        return [
            self::DEFAULT_SLUG => ['label' => __('Default', 'lrob-email-toolkit'), 'vars' => []],
            'dark'    => ['label' => __('Dark', 'lrob-email-toolkit'),    'vars' => $compose(['color' => 'dark', 'roundness' => 'soft', 'spacing' => 'comfortable'])],
            'minimal' => ['label' => __('Minimal', 'lrob-email-toolkit'), 'vars' => $compose(['color' => 'white', 'roundness' => 'sharp', 'spacing' => 'average'])],
            'card'    => ['label' => __('Card', 'lrob-email-toolkit'),    'vars' => $compose(['color' => 'white', 'roundness' => 'roundy', 'spacing' => 'comfortable'])],
            'ocean'   => ['label' => __('Ocean', 'lrob-email-toolkit'),   'vars' => $compose(['color' => 'ocean', 'roundness' => 'roundy', 'spacing' => 'comfortable'])],
            'forest'  => ['label' => __('Forest', 'lrob-email-toolkit'),  'vars' => $compose(['color' => 'forest', 'roundness' => 'soft', 'spacing' => 'comfortable'])],
            'sunset'  => ['label' => __('Sunset', 'lrob-email-toolkit'),  'vars' => $compose(['color' => 'sunset', 'roundness' => 'round', 'spacing' => 'spacious'])],
        ];
    }

    /** @return array<string, string> slug → label (global preset dropdown). */
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

    /** @return array<string,string> schemaKey → value for a full preset. */
    public static function vars_for(string $slug): array
    {
        $presets = self::presets();
        return isset($presets[$slug]) ? $presets[$slug]['vars'] : [];
    }

    /**
     * Localized for the editor JS. presets + axis options stay in schemaKey
     * space (the override-map's space); `keyToVar` converts to cssVars for the
     * live preview, and `vars` is the full cssVar list to clear before repaint.
     *
     * @return array{presets:array<string,array<string,string>>, axes:array<string,array{keys:list<string>,options:array<string,array<string,string>>}>, vars:list<string>, keyToVar:array<string,string>}
     */
    public static function js_data(): array
    {
        $key_to_var = [];
        foreach (self::schema() as $key => $entry) {
            $key_to_var[$key] = $entry['var'];
        }

        $presets = [];
        foreach (self::presets() as $slug => $p) {
            $presets[$slug] = $p['vars'];
        }
        $axes = [];
        foreach (self::axes() as $axis_key => $axis) {
            $opts = [];
            foreach ($axis['options'] as $slug => $opt) {
                $opts[$slug] = (object) $opt['vars']; // {} stays an object in JSON, not []
            }
            $axes[$axis_key] = ['keys' => $axis['keys'], 'options' => $opts];
        }

        return ['presets' => $presets, 'axes' => $axes, 'vars' => array_values($key_to_var), 'keyToVar' => $key_to_var];
    }
}
