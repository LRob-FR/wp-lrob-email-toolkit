<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;
use LRob\EmailToolkit\Forms\FormContext;
use LRob\EmailToolkit\Modules\Newsletter\SubscriberFields;

/**
 * Dedicated `gender` field type for newsletter subscribe forms.
 * Wraps a `<select>` with the canonical `SubscriberFields::GENDER_VALUES`
 * options (female / male / other / prefer_not_to_say) — admins don't
 * have to configure options or set the `maps_to` attribute themselves.
 *
 * Compared to the generic `select` + `maps_to=gender` approach:
 *   - One click in the field picker to drop a fully-wired gender input.
 *   - Options translate at render time via SubscriberFields::gender_label
 *     so a locale change updates the labels without admin re-saving.
 *   - SubmitHandler routes the submission to subscribers.gender via
 *     the form-builder's `maps_to` round-trip — same path as other
 *     mapped fields, no special-casing needed.
 */
final class GenderField implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'gender';
    }

    public function label(): string
    {
        return __('Gender', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $base = FieldRenderHelpers::normalize_base_keys($field, $this->slug());
        // Lock maps_to to 'gender' regardless of what the form-builder
        // editor passes in — the field exists to map there. Admins can
        // still un-map by switching to a generic select if needed.
        $base['maps_to'] = 'gender';
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
        $label = isset($attrs['label']) ? (string) $attrs['label'] : __('Gender', 'lrob-email-toolkit');
        $helper = isset($attrs['helper']) ? (string) $attrs['helper'] : '';
        $required = !empty($attrs['required']);

        $id = FormContext::field_id($slug);
        $name = FormContext::field_name($slug);

        // Empty option = "no answer chosen". Required forms force the
        // visitor to pick a value; non-required forms allow leaving blank.
        $opts_html = '<option value="">' . esc_html__('—', 'lrob-email-toolkit') . '</option>';
        foreach (SubscriberFields::GENDER_VALUES as $value) {
            $opts_html .= sprintf(
                '<option value="%s">%s</option>',
                esc_attr($value),
                esc_html(SubscriberFields::gender_label($value))
            );
        }

        $control = sprintf(
            '<select id="%s" name="%s"%s>%s</select>',
            esc_attr($id),
            esc_attr($name),
            $required ? ' required aria-required="true"' : '',
            $opts_html
        );

        return FieldRenderHelpers::wrap_field('gender', $slug, $label, $helper, $required, $control, $id);
    }
}
