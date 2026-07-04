<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

// Docs: docs/forms.md → "Form theming". Renders the per-axis editor: one
// dropdown per sub-preset (colour scheme / roundness / spacing / …) with an
// Edit button revealing that axis's individual knobs. The global preset
// dropdown lives in the host card; persistence + live preview are handled by
// admin/js/form-style-controls.js via the hidden [data-style-json] field.
final class StyleControls
{
    /**
     * @param string               $field_class autosave hook class for the host module
     * @param string               $meta_key    the per-form META_STYLE_VARS key
     * @param array<string,string>  $current     stored override map (schemaKey → value)
     */
    public static function render(string $field_class, string $meta_key, array $current): string
    {
        $schema = StylePresets::schema();
        $axes = StylePresets::axes();

        $rows = '';
        foreach ($axes as $axis_key => $axis) {
            $rows .= self::render_axis($axis_key, $axis, $schema, $current);
        }

        // Global preset dropdown (shows "Custom" once the map diverges).
        $active_preset = self::match_preset($current);
        $preset_opts = '<option value="custom" hidden>' . esc_html__('Custom', 'lrob-email-toolkit') . '</option>';
        foreach (StylePresets::all() as $slug => $label) {
            $preset_opts .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $slug),
                selected($active_preset, $slug, false),
                esc_html((string) $label)
            );
        }

        $json = (string) wp_json_encode($current ?: (object) []);

        return sprintf(
            '<div class="lrob-etk-style-customize" data-style-customize>' .
                '<input type="hidden" class="%1$s" data-key="%2$s" data-style-json value="%3$s">' .
                '<div class="lrob-etk-style-head">' .
                    '<label class="lrob-etk-style-axis-label">%4$s</label>' .
                    '<span class="lrob-etk-style-axis-controls">' .
                        '<select class="lrob-etk-style-axis-select" data-style-preset>%5$s</select>' .
                        '<button type="button" class="lrob-etk-style-edit" data-style-toggle aria-expanded="false">%6$s</button>' .
                    '</span>' .
                '</div>' .
                '<div class="lrob-etk-style-axes" data-style-axes hidden>%7$s</div>' .
            '</div>',
            esc_attr($field_class),
            esc_attr($meta_key),
            esc_attr($json),
            esc_html__('Preset', 'lrob-email-toolkit'),
            $preset_opts,
            esc_html__('Customize', 'lrob-email-toolkit'),
            $rows
        );
    }

    /** Which full preset the current map equals, or 'custom'. */
    private static function match_preset(array $current): string
    {
        $cur = self::normalize($current);
        foreach (array_keys(StylePresets::all()) as $slug) {
            if (self::normalize(StylePresets::vars_for((string) $slug)) === $cur) {
                return (string) $slug;
            }
        }
        return 'custom';
    }

    /**
     * @param array<string,mixed> $vars
     * @return array<string,string>
     */
    private static function normalize(array $vars): array
    {
        $out = [];
        foreach ($vars as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $out[(string) $key] = (string) $value;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * @param array{label:string, keys:list<string>, options:array<string,array{label:string,vars:array<string,string>}>} $axis
     * @param array<string,array{var:string,type:string,group:string,label:string,options?:array<string,string>}> $schema
     * @param array<string,string> $current
     */
    private static function render_axis(string $axis_key, array $axis, array $schema, array $current): string
    {
        // Which named option matches the current map for this axis (else "custom").
        $active = self::active_option($axis, $current);

        $options = '<option value="custom" hidden>' . esc_html__('Custom', 'lrob-email-toolkit') . '</option>';
        foreach ($axis['options'] as $slug => $opt) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($slug),
                selected($active, $slug, false),
                esc_html((string) $opt['label'])
            );
        }

        // Per-axis knob popover: one control per schema key this axis owns.
        $knobs = '';
        foreach ($axis['keys'] as $key) {
            if (isset($schema[$key])) {
                $knobs .= self::render_row($key, $schema[$key], $current);
            }
        }

        return sprintf(
            '<div class="lrob-etk-style-axis" data-style-axis="%1$s">' .
                '<label class="lrob-etk-style-axis-label">%2$s</label>' .
                '<span class="lrob-etk-style-axis-controls">' .
                    '<select class="lrob-etk-style-axis-select" data-style-axis-select>%3$s</select>' .
                    '<button type="button" class="lrob-etk-style-edit" data-style-edit aria-expanded="false" title="%4$s">%4$s</button>' .
                '</span>' .
                '<div class="lrob-etk-style-axis-edit" data-style-axis-edit hidden>%5$s</div>' .
            '</div>',
            esc_attr($axis_key),
            esc_html((string) $axis['label']),
            $options,
            esc_attr__('Edit', 'lrob-email-toolkit'),
            $knobs
        );
    }

    /**
     * @param array{keys:list<string>, options:array<string,array{label:string,vars:array<string,string>}>} $axis
     * @param array<string,string> $current
     */
    private static function active_option(array $axis, array $current): string
    {
        // The map restricted to this axis's keys.
        $sub = [];
        foreach ($axis['keys'] as $k) {
            if (array_key_exists($k, $current) && $current[$k] !== '') {
                $sub[$k] = (string) $current[$k];
            }
        }
        foreach ($axis['options'] as $slug => $opt) {
            $vars = [];
            foreach ($opt['vars'] as $k => $v) {
                $vars[$k] = (string) $v;
            }
            ksort($vars);
            $cmp = $sub;
            ksort($cmp);
            if ($vars === $cmp) {
                return $slug;
            }
        }
        return 'custom';
    }

    /**
     * @param array{var:string, type:string, group:string, label:string, options?:array<string,string>} $entry
     * @param array<string,string> $current
     */
    private static function render_row(string $key, array $entry, array $current): string
    {
        $has = array_key_exists($key, $current) && $current[$key] !== '';
        $value = $has ? (string) $current[$key] : '';

        $attrs = sprintf(
            'data-style-key="%s" data-style-type="%s" data-style-var="%s"',
            esc_attr($key),
            esc_attr((string) $entry['type']),
            esc_attr((string) $entry['var'])
        );

        if ($entry['type'] === 'color') {
            $swatch = $value !== '' ? $value : '#888888';
            $control = sprintf(
                '<input type="color" class="lrob-etk-style-color" data-style-input value="%s">' .
                '<button type="button" class="lrob-etk-style-clear" data-style-clear%s>%s</button>',
                esc_attr($swatch),
                $has ? '' : ' hidden',
                esc_html__('Reset', 'lrob-email-toolkit')
            );
        } elseif ($entry['type'] === 'select') {
            $options = isset($entry['options']) && is_array($entry['options']) ? $entry['options'] : [];
            $opts = '';
            foreach ($options as $opt_value => $opt_label) {
                $opts .= sprintf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr((string) $opt_value),
                    selected($value, (string) $opt_value, false),
                    esc_html((string) $opt_label)
                );
            }
            $control = '<select class="lrob-etk-style-select" data-style-input>' . $opts . '</select>';
        } else { // size
            $control = sprintf(
                '<input type="text" class="lrob-etk-style-size" data-style-input value="%s" placeholder="%s" inputmode="decimal">' .
                '<button type="button" class="lrob-etk-style-clear" data-style-clear%s>%s</button>',
                esc_attr($value),
                esc_attr__('auto', 'lrob-email-toolkit'),
                $has ? '' : ' hidden',
                esc_html__('Reset', 'lrob-email-toolkit')
            );
        }

        return sprintf(
            '<div class="lrob-etk-style-row%s" %s><label class="lrob-etk-style-label">%s</label>' .
                '<span class="lrob-etk-style-control">%s</span></div>',
            $has ? ' is-set' : '',
            $attrs,
            esc_html((string) $entry['label']),
            $control
        );
    }
}
