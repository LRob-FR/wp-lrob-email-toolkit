<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;
use LRob\EmailToolkit\Forms\FormContext;

final class SelectField implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'select';
    }

    public function label(): string
    {
        return __('Dropdown', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $base = FieldRenderHelpers::normalize_base_keys($field, $this->slug());
        $base['options'] = FieldRenderHelpers::normalize_options($field['options'] ?? []);
        // A native <select> is always single-choice in this plugin —
        // multi-pick lists belong in the checkbox field type. Cap defaults
        // at one entry regardless of what's stored.
        $base['defaults'] = FieldRenderHelpers::normalize_defaults($field['defaults'] ?? [], $base['options'], false);
        return $base;
    }

    public function render(array $attrs): string
    {
        if (!FormContext::is_active()) {
            return '';
        }
        $slug = FieldRenderHelpers::normalize_slug($attrs);
        if ($slug === '') {
            return '';
        }
        $label = isset($attrs['label']) ? (string) $attrs['label'] : '';
        $helper = isset($attrs['helper']) ? (string) $attrs['helper'] : '';
        $placeholder = isset($attrs['placeholder']) ? (string) $attrs['placeholder'] : '';
        $required = !empty($attrs['required']);
        $options = FieldRenderHelpers::normalize_options($attrs['options'] ?? []);
        $defaults = isset($attrs['defaults']) && is_array($attrs['defaults'])
            ? array_map('strval', $attrs['defaults'])
            : [];

        $id = FormContext::field_id($slug);
        $name = FormContext::field_name($slug);

        // Resolve which option (if any) starts selected. The placeholder
        // option is ALWAYS emitted so the user can pick "nothing" to clear
        // any default — selecting it amounts to "no default chosen".
        $selected_value = '';
        foreach ($defaults as $v) {
            $exists = false;
            foreach ($options as $opt) {
                if ((string) $opt['value'] === $v) { $exists = true; break; }
            }
            if ($exists) { $selected_value = $v; break; }
        }

        $opts_html = sprintf(
            '<option value=""%s>%s</option>',
            $selected_value === '' ? ' selected' : '',
            esc_html($placeholder !== '' ? $placeholder : '— select —')
        );
        foreach ($options as $opt) {
            $opts_html .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($opt['value']),
                ((string) $opt['value']) === $selected_value ? ' selected' : '',
                esc_html($opt['label'])
            );
        }

        $control = sprintf(
            '<select id="%s" name="%s"%s>%s</select>',
            esc_attr($id),
            esc_attr($name),
            $required ? ' required aria-required="true"' : '',
            $opts_html
        );

        return FieldRenderHelpers::wrap_field('select', $slug, $label, $helper, $required, $control, $id);
    }
}
