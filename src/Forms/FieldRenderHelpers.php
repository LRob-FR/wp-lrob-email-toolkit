<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

// Docs: docs/forms.md
final class FieldRenderHelpers
{
    public static function gen_id(string $prefix): string
    {
        return $prefix . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    public static function normalize_base_keys(array $field, string $type): array
    {
        $base = [
            'id'          => isset($field['id']) && is_string($field['id']) && $field['id'] !== ''
                ? sanitize_key($field['id'])
                : self::gen_id('f'),
            'type'        => $type,
            'slug'        => isset($field['slug']) ? sanitize_key((string) $field['slug']) : '',
            // Stable creation-order index — survives reordering and
            // deletions of other fields so slugs (`<type>_<label>_<nth>`)
            // stay attached to their original field across edits. 0 here
            // means "not yet assigned"; FormStructure's post-pass
            // backfills any 0 with the next free index.
            // nth 0 = not yet assigned; FormStructure's post-pass backfills it.
            'nth'         => isset($field['nth']) ? max(0, (int) $field['nth']) : 0,
            'label'       => isset($field['label']) ? self::recover_unicode_escapes(sanitize_text_field((string) $field['label'])) : '',
            'helper'      => isset($field['helper']) ? self::recover_unicode_escapes(sanitize_text_field((string) $field['helper'])) : '',
            'placeholder' => isset($field['placeholder']) ? self::recover_unicode_escapes(sanitize_text_field((string) $field['placeholder'])) : '',
            'required'    => !empty($field['required']),
        ];
        if (isset($field['maps_to'])) {
            $maps = sanitize_key((string) $field['maps_to']);
            if ($maps !== '') {
                $base['maps_to'] = $maps;
            }
        }
        return $base;
    }

    /** @return array<int, array{value:string, label:string}> */
    public static function normalize_options(mixed $raw): array
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

    /**
     * @param array<int, array{value:string, label:string}> $options
     * @return array<int, string>
     */
    public static function normalize_defaults(mixed $raw, array $options, bool $multiple): array
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

    public static function wrap_field(
        string $type_class,
        string $slug,
        string $label,
        string $helper,
        bool $required,
        string $control_html,
        string $control_id
    ): string {
        $label_html = $label !== '' || FormContext::is_editor()
            ? self::label_html($label, $required, $control_id)
            : '';
        // Wire helper + error to the control via aria-describedby (ids derived
        // from the control id). The control itself carries the matching
        // aria-describedby — added by render_input / the field-type builders.
        $helper_html = self::helper_html($helper, $control_id . '-help');
        $error_html = sprintf(
            '<p class="lrob-etk-form-error" id="%s" data-field-error hidden></p>',
            esc_attr($control_id . '-err')
        );

        return sprintf(
            '<div class="lrob-etk-form-field lrob-etk-form-field--%s" data-field="%s">%s%s%s%s</div>',
            esc_attr($type_class),
            esc_attr($slug),
            $label_html,
            $control_html,
            $helper_html,
            $error_html
        );
    }

    public static function label_html(string $label, bool $required, string $for_id): string
    {
        if (FormContext::is_editor()) {
            $shown = $label !== '' ? esc_html($label) : '<span class="lrob-etk-form-label-empty">' . esc_html__('(field label)', 'lrob-email-toolkit') . '</span>';
            return sprintf(
                '<div class="lrob-etk-form-label"><span class="lrob-etk-form-label-text" contenteditable="plaintext-only" data-edit="label" spellcheck="false">%s</span>%s</div>',
                $shown,
                self::required_toggle_html($required)
            );
        }
        $required_marker = $required ? ' <span class="lrob-etk-form-required" aria-hidden="true">*</span>' : '';
        return sprintf(
            '<label class="lrob-etk-form-label" for="%s">%s%s</label>',
            esc_attr($for_id),
            esc_html($label),
            $required_marker
        );
    }

    public static function required_toggle_html(bool $required): string
    {
        return sprintf(
            '<span class="lrob-etk-form-required-control">'
            . '<span class="lrob-etk-form-required-star%1$s" aria-hidden="true">*</span>'
            . '<label class="lrob-etk-form-required-check">'
            . '<input type="checkbox" data-required-toggle%2$s>'
            . '<span>%3$s</span>'
            . '</label>'
            . '</span>',
            $required ? ' is-on' : '',
            $required ? ' checked' : '',
            esc_html__('Required', 'lrob-email-toolkit')
        );
    }

    public static function helper_html(string $helper, string $id = ''): string
    {
        if (FormContext::is_editor()) {
            $shown = $helper !== '' ? esc_html($helper) : esc_html__('(optional helper text)', 'lrob-email-toolkit');
            $cls = $helper !== '' ? 'lrob-etk-form-helper' : 'lrob-etk-form-helper lrob-etk-form-helper-empty';
            return sprintf(
                '<p class="%s" contenteditable="plaintext-only" data-edit="helper" spellcheck="false">%s</p>',
                esc_attr($cls),
                $shown
            );
        }
        if ($helper === '') {
            return '';
        }
        $id_attr = $id !== '' ? ' id="' . esc_attr($id) . '"' : '';
        return '<p class="lrob-etk-form-helper"' . $id_attr . '>' . esc_html($helper) . '</p>';
    }

    /**
     * Builds the ` aria-describedby="…"` attribute (leading space included)
     * linking a control to its helper + error `<p>` — ids derived from the
     * control id, matching what wrap_field / render_option_group emit. The
     * error id is always referenced (the `<p>` always exists; while empty +
     * hidden it contributes no description, and gets announced once the JS
     * fills it on a failed submit).
     */
    public static function describedby_attr(string $control_id, bool $has_helper): string
    {
        $ids = ($has_helper ? $control_id . '-help ' : '') . $control_id . '-err';
        return ' aria-describedby="' . esc_attr($ids) . '"';
    }

    public static function normalize_slug(array $attrs): string
    {
        $raw = isset($attrs['slug']) && is_string($attrs['slug']) ? (string) $attrs['slug'] : '';
        return sanitize_key($raw);
    }

    public static function guess_autocomplete(string $input_type, string $slug): string
    {
        if ($input_type === 'email') {
            return 'email';
        }
        if ($input_type === 'tel') {
            return 'tel';
        }
        $map = [
            'name'      => 'name',
            'firstname' => 'given-name',
            'lastname'  => 'family-name',
            'phone'     => 'tel',
            'company'   => 'organization',
            'website'   => 'url',
            'url'       => 'url',
            'city'      => 'address-level2',
            'country'   => 'country-name',
            'zip'       => 'postal-code',
            'postcode'  => 'postal-code',
        ];
        return $map[$slug] ?? '';
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, scalar|null> $extra Additional input attributes (null/empty dropped).
     */
    public static function render_input(string $input_type, array $attrs, array $extra = []): string
    {
        if (!FormContext::is_active()) {
            return '';
        }
        $slug = self::normalize_slug($attrs);
        if ($slug === '') {
            return '';
        }
        $label = isset($attrs['label']) ? (string) $attrs['label'] : '';
        $helper = isset($attrs['helper']) ? (string) $attrs['helper'] : '';
        $placeholder = isset($attrs['placeholder']) ? (string) $attrs['placeholder'] : '';
        $required = !empty($attrs['required']);
        $max = isset($attrs['maxLength']) ? (int) $attrs['maxLength'] : 0;
        $autocomplete = isset($attrs['autocomplete']) && is_string($attrs['autocomplete'])
            ? (string) $attrs['autocomplete']
            : self::guess_autocomplete($input_type, $slug);

        $id = FormContext::field_id($slug);
        $name = FormContext::field_name($slug);

        $extra_html = '';
        foreach ($extra as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $extra_html .= ' ' . $k . '="' . esc_attr((string) $v) . '"';
        }

        $control = sprintf(
            '<input type="%s" id="%s" name="%s"%s%s%s%s%s%s>',
            esc_attr($input_type),
            esc_attr($id),
            esc_attr($name),
            $placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '',
            $max > 0 ? ' maxlength="' . $max . '"' : '',
            $required ? ' required aria-required="true"' : '',
            $autocomplete !== '' ? ' autocomplete="' . esc_attr($autocomplete) . '"' : '',
            self::describedby_attr($id, $helper !== ''),
            $extra_html
        );

        $css_type = $input_type === 'tel' ? 'phone' : $input_type;
        return self::wrap_field($css_type, $slug, $label, $helper, $required, $control, $id);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public static function render_option_group(string $kind, array $attrs): string
    {
        if (!FormContext::is_active()) {
            return '';
        }
        $slug = self::normalize_slug($attrs);
        if ($slug === '') {
            return '';
        }
        $label = isset($attrs['label']) ? (string) $attrs['label'] : '';
        $helper = isset($attrs['helper']) ? (string) $attrs['helper'] : '';
        $required = !empty($attrs['required']);
        $options = self::normalize_options($attrs['options'] ?? []);

        if ($options === []) {
            return '';
        }

        $input_type = $kind === 'radio' ? 'radio' : 'checkbox';
        $name = FormContext::field_name($slug, $kind === 'checkbox');
        $group_id = FormContext::field_id($slug);

        $items = '';
        foreach ($options as $idx => $opt) {
            $opt_id = $group_id . '-' . $idx;
            $items .= sprintf(
                '<label class="lrob-etk-form-option" for="%s"><input type="%s" id="%s" name="%s" value="%s"%s><span>%s</span></label>',
                esc_attr($opt_id),
                $input_type,
                esc_attr($opt_id),
                esc_attr($name),
                esc_attr($opt['value']),
                $required && $kind === 'radio' && $idx === 0 ? ' required aria-required="true"' : '',
                esc_html($opt['label'])
            );
        }

        $helper_html = self::helper_html($helper, $group_id . '-help');
        $error_html = sprintf(
            '<p class="lrob-etk-form-error" id="%s" data-field-error hidden></p>',
            esc_attr($group_id . '-err')
        );

        if (FormContext::is_editor()) {
            $shown = $label !== '' ? esc_html($label) : '<span class="lrob-etk-form-label-empty">' . esc_html__('(field label)', 'lrob-email-toolkit') . '</span>';
            $legend_inner = sprintf(
                '<span class="lrob-etk-form-label-text" contenteditable="plaintext-only" data-edit="label" spellcheck="false">%s</span>%s',
                $shown,
                self::required_toggle_html($required)
            );
        } else {
            $required_marker = $required ? ' <span class="lrob-etk-form-required" aria-hidden="true">*</span>' : '';
            $legend_inner = esc_html($label) . $required_marker;
        }

        // describedby on the fieldset (not in editor — contenteditable preview).
        $describedby = FormContext::is_editor() ? '' : self::describedby_attr($group_id, $helper !== '');

        return sprintf(
            '<fieldset class="lrob-etk-form-field lrob-etk-form-field--%s" data-field="%s" id="%s"%s><legend class="lrob-etk-form-label">%s</legend><div class="lrob-etk-form-options">%s</div>%s%s</fieldset>',
            $kind,
            esc_attr($slug),
            esc_attr($group_id),
            $describedby,
            $legend_inner,
            $items,
            $helper_html,
            $error_html
        );
    }

    /** Repairs legacy uXXXX sequences from before the wp_slash compensation. */
    public static function recover_unicode_escapes(string $s): string
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
}
