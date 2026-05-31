<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;
use LRob\EmailToolkit\Forms\FormContext;

final class TextareaField implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'textarea';
    }

    public function label(): string
    {
        return __('Multi-line text', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $base = FieldRenderHelpers::normalize_base_keys($field, $this->slug());
        if (isset($field['rows'])) {
            $base['rows'] = max(2, (int) $field['rows']);
        }
        if (isset($field['maxLength'])) {
            $base['maxLength'] = max(0, (int) $field['maxLength']);
        }
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
        $rows = isset($attrs['rows']) ? max(2, (int) $attrs['rows']) : 5;
        $max = isset($attrs['maxLength']) ? (int) $attrs['maxLength'] : 0;

        $id = FormContext::field_id($slug);
        $name = FormContext::field_name($slug);

        $attrs_html = sprintf(
            ' id="%s" name="%s" rows="%d"%s%s%s%s',
            esc_attr($id),
            esc_attr($name),
            $rows,
            $placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '',
            $max > 0 ? ' maxlength="' . $max . '"' : '',
            $required ? ' required aria-required="true"' : '',
            FieldRenderHelpers::describedby_attr($id, $helper !== '')
        );

        return FieldRenderHelpers::wrap_field(
            'textarea',
            $slug,
            $label,
            $helper,
            $required,
            '<textarea' . $attrs_html . '></textarea>',
            $id
        );
    }
}
