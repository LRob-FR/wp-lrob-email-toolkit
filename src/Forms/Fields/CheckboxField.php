<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;
use LRob\EmailToolkit\Forms\FormContext;

final class CheckboxField implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'checkbox';
    }

    public function label(): string
    {
        return __('Checkbox', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $base = FieldRenderHelpers::normalize_base_keys($field, $this->slug());
        $base['multiple'] = !isset($field['multiple']) || !empty($field['multiple']);
        $base['options']  = FieldRenderHelpers::normalize_options($field['options'] ?? []);
        return $base;
    }

    public function render(array $attrs): string
    {
        $multiple = !isset($attrs['multiple']) || !empty($attrs['multiple']);
        if ($multiple) {
            return FieldRenderHelpers::render_option_group('checkbox', $attrs);
        }
        // Single checkbox: a single boolean field with the label inline next
        // to the input. No outer <label>+<input> wrap pattern — the label
        // text is inside the same <label> as the checkbox so clicking the
        // text toggles it.
        if (!FormContext::is_active()) {
            return '';
        }
        $slug = FieldRenderHelpers::normalize_slug($attrs);
        if ($slug === '') {
            return '';
        }
        $label = isset($attrs['label']) ? (string) $attrs['label'] : '';
        $helper = isset($attrs['helper']) ? (string) $attrs['helper'] : '';
        $required = !empty($attrs['required']);
        $id = FormContext::field_id($slug);
        $name = FormContext::field_name($slug);

        $control = sprintf(
            '<label class="lrob-etk-form-checkbox-inline" for="%s"><input type="checkbox" id="%s" name="%s" value="1"%s><span>%s%s</span></label>',
            esc_attr($id),
            esc_attr($id),
            esc_attr($name),
            $required ? ' required aria-required="true"' : '',
            esc_html($label),
            $required ? ' <span class="lrob-etk-form-required" aria-hidden="true">*</span>' : ''
        );

        $helper_html = $helper !== '' ? '<p class="lrob-etk-form-helper">' . esc_html($helper) . '</p>' : '';
        $error_html = '<p class="lrob-etk-form-error" data-field-error hidden></p>';
        return sprintf(
            '<div class="lrob-etk-form-field lrob-etk-form-field--checkbox lrob-etk-form-field--checkbox-single" data-field="%s">%s%s%s</div>',
            esc_attr($slug),
            $control,
            $helper_html,
            $error_html
        );
    }
}
