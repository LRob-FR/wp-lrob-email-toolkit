<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms\Fields;

use LRob\EmailToolkit\Forms\CountryData;
use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;
use LRob\EmailToolkit\Forms\FormContext;

final class PhoneField implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'phone';
    }

    public function label(): string
    {
        return __('Phone', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $base = FieldRenderHelpers::normalize_base_keys($field, $this->slug());
        if (isset($field['pattern']) && $field['pattern'] !== '') {
            $base['pattern'] = (string) $field['pattern'];
        }
        if (!empty($field['country_picker'])) {
            $base['country_picker'] = true;
        }
        if (isset($field['country_default'])) {
            $iso = strtoupper(trim((string) $field['country_default']));
            if ($iso !== '' && CountryData::is_known($iso)) {
                $base['country_default'] = $iso;
            }
        }
        if (!empty($field['country_auto_detect'])) {
            $base['country_auto_detect'] = true;
        }
        return $base;
    }

    public function render(array $attrs): string
    {
        if (empty($attrs['country_picker'])) {
            return FieldRenderHelpers::render_input('tel', $attrs, [
                'inputmode' => 'tel',
                'pattern'   => isset($attrs['pattern']) && is_string($attrs['pattern']) && $attrs['pattern'] !== '' ? $attrs['pattern'] : null,
            ]);
        }

        if (!FormContext::is_active()) {
            return '';
        }
        $slug = FieldRenderHelpers::normalize_slug($attrs);
        if ($slug === '') {
            return '';
        }

        $label       = isset($attrs['label']) ? (string) $attrs['label'] : '';
        $helper      = isset($attrs['helper']) ? (string) $attrs['helper'] : '';
        $placeholder = isset($attrs['placeholder']) ? (string) $attrs['placeholder'] : '';
        $required    = !empty($attrs['required']);
        $pattern     = isset($attrs['pattern']) && is_string($attrs['pattern']) ? (string) $attrs['pattern'] : '';
        $maxlen      = isset($attrs['maxLength']) ? (int) $attrs['maxLength'] : 0;
        $auto_detect = !empty($attrs['country_auto_detect']);

        $admin_default = isset($attrs['country_default']) ? (string) $attrs['country_default'] : '';
        $country = CountryData::resolve_default($admin_default);
        $dial    = CountryData::dial($country);
        $flag    = CountryData::flag_emoji($country);

        $id           = FormContext::field_id($slug);
        $name         = FormContext::field_name($slug);
        $country_name = FormContext::field_name($slug . '_country');

        $extra = '';
        if ($placeholder !== '') {
            $extra .= ' placeholder="' . esc_attr($placeholder) . '"';
        }
        if ($maxlen > 0) {
            $extra .= ' maxlength="' . $maxlen . '"';
        }
        if ($pattern !== '') {
            $extra .= ' pattern="' . esc_attr($pattern) . '"';
        }
        if ($required) {
            $extra .= ' required aria-required="true"';
        }

        $globe = "\xF0\x9F\x8C\x90";

        $control = '<div class="lrob-etk-form-phone" data-country-picker'
            . ($auto_detect ? ' data-auto-detect="1"' : '')
            . ' data-default-country="' . esc_attr($country) . '"'
            . ' data-dial="' . esc_attr($dial) . '"'
            . ' data-input-id="' . esc_attr($id) . '">'
            . '<button type="button" class="lrob-etk-form-phone-trigger" data-phone-trigger'
            .     ' aria-haspopup="listbox" aria-expanded="false"'
            .     ' aria-label="' . esc_attr__('Choose country code', 'lrob-email-toolkit') . '">'
            .   '<span class="lrob-etk-form-phone-flag" data-phone-flag>' . ($flag !== '' ? $flag : $globe) . '</span>'
            .   '<span class="lrob-etk-form-phone-dial" data-phone-dial>' . ($dial !== '' ? '+' . esc_html($dial) : '+') . '</span>'
            .   '<span class="lrob-etk-form-phone-caret dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>'
            . '</button>'
            . '<input type="hidden" name="' . esc_attr($country_name) . '" value="' . esc_attr($country) . '" data-phone-country>'
            . '<input type="tel" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"'
            .     ' autocomplete="tel-national" inputmode="tel" data-phone-number'
            .     $extra
            .     FieldRenderHelpers::describedby_attr($id, $helper !== '')
            . '>'
            . '</div>';

        return FieldRenderHelpers::wrap_field('phone', $slug, $label, $helper, $required, $control, $id);
    }
}
