<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Renders each field block on the frontend. Called from the registered
 * block render_callbacks via Blocks::dispatch_field_render(). Outputs
 * nothing when FormContext is inactive (i.e. when a field block was
 * somehow rendered outside an embed-block-driven form).
 *
 * Every method takes the block's $attrs and returns final HTML — no echo.
 * Markup conventions:
 *   - Root: &lt;div class="lrob-etk-cf-field lrob-etk-cf-field--{type}" data-field="{slug}"&gt;
 *   - Label: &lt;label for=…&gt; for single-input fields, &lt;span class="lrob-etk-cf-label"&gt; for option groups
 *   - Helper: &lt;p class="lrob-etk-cf-helper"&gt;
 *   - Error placeholder: &lt;p class="lrob-etk-cf-error" data-field-error&gt; (filled by JS)
 */
final class FieldRenderer
{
    /** @param array<string, mixed> $attrs */
    public static function text(array $attrs): string
    {
        return self::render_input('text', $attrs);
    }

    /** @param array<string, mixed> $attrs */
    public static function email(array $attrs): string
    {
        return self::render_input('email', $attrs);
    }

    /** @param array<string, mixed> $attrs */
    public static function number(array $attrs): string
    {
        return self::render_input('number', $attrs, [
            'min'  => $attrs['min'] ?? null,
            'max'  => $attrs['max'] ?? null,
            'step' => $attrs['step'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    public static function phone(array $attrs): string
    {
        return self::render_input('tel', $attrs, [
            'inputmode' => 'tel',
            'pattern'   => isset($attrs['pattern']) && is_string($attrs['pattern']) && $attrs['pattern'] !== '' ? $attrs['pattern'] : null,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    public static function date(array $attrs): string
    {
        return self::render_input('date', $attrs, [
            'min' => $attrs['min'] ?? null,
            'max' => $attrs['max'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    public static function textarea(array $attrs): string
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
        $rows = isset($attrs['rows']) ? max(2, (int) $attrs['rows']) : 5;
        $max = isset($attrs['maxLength']) ? (int) $attrs['maxLength'] : 0;

        $id = FormContext::field_id($slug);
        $name = FormContext::field_name($slug);

        $attrs_html = sprintf(
            ' id="%s" name="%s" rows="%d"%s%s%s',
            esc_attr($id),
            esc_attr($name),
            $rows,
            $placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '',
            $max > 0 ? ' maxlength="' . $max . '"' : '',
            $required ? ' required aria-required="true"' : ''
        );

        return self::wrap_field(
            'textarea',
            $slug,
            $label,
            $helper,
            $required,
            '<textarea' . $attrs_html . '></textarea>',
            $id
        );
    }

    /** @param array<string, mixed> $attrs */
    public static function select(array $attrs): string
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
        $options = self::normalize_options($attrs['options'] ?? []);

        $id = FormContext::field_id($slug);
        $name = FormContext::field_name($slug);

        $opts_html = '';
        if ($placeholder !== '') {
            $opts_html .= '<option value="">' . esc_html($placeholder) . '</option>';
        }
        foreach ($options as $opt) {
            $opts_html .= sprintf(
                '<option value="%s">%s</option>',
                esc_attr($opt['value']),
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

        return self::wrap_field('select', $slug, $label, $helper, $required, $control, $id);
    }

    /** @param array<string, mixed> $attrs */
    public static function radio(array $attrs): string
    {
        return self::render_option_group('radio', $attrs);
    }

    /** @param array<string, mixed> $attrs */
    public static function checkbox(array $attrs): string
    {
        $multiple = !isset($attrs['multiple']) || !empty($attrs['multiple']);
        if (!$multiple) {
            // Single checkbox: treat as a single boolean field with the label inline.
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
            $id = FormContext::field_id($slug);
            $name = FormContext::field_name($slug);

            $control = sprintf(
                '<label class="lrob-etk-cf-checkbox-inline" for="%s"><input type="checkbox" id="%s" name="%s" value="1"%s><span>%s%s</span></label>',
                esc_attr($id),
                esc_attr($id),
                esc_attr($name),
                $required ? ' required aria-required="true"' : '',
                esc_html($label),
                $required ? ' <span class="lrob-etk-cf-required" aria-hidden="true">*</span>' : ''
            );

            $helper_html = $helper !== '' ? '<p class="lrob-etk-cf-helper">' . esc_html($helper) . '</p>' : '';
            $error_html = '<p class="lrob-etk-cf-error" data-field-error hidden></p>';
            return sprintf(
                '<div class="lrob-etk-cf-field lrob-etk-cf-field--checkbox lrob-etk-cf-field--checkbox-single" data-field="%s">%s%s%s</div>',
                esc_attr($slug),
                $control,
                $helper_html,
                $error_html
            );
        }
        return self::render_option_group('checkbox', $attrs);
    }

    /** @param array<string, mixed> $attrs */
    public static function submit(array $attrs): string
    {
        if (!FormContext::is_active()) {
            return '';
        }
        $text = isset($attrs['text']) && is_string($attrs['text']) && $attrs['text'] !== ''
            ? (string) $attrs['text']
            : __('Send', 'lrob-email-toolkit');
        $align = isset($attrs['align']) && in_array($attrs['align'], ['left', 'center', 'right', 'stretch'], true)
            ? (string) $attrs['align']
            : 'right';

        return sprintf(
            '<div class="lrob-etk-cf-field lrob-etk-cf-field--submit is-align-%s"><button type="submit" class="lrob-etk-cf-submit"><span class="lrob-etk-cf-submit-label">%s</span><span class="lrob-etk-cf-submit-spinner" aria-hidden="true"></span></button></div>',
            esc_attr($align),
            esc_html($text)
        );
    }

    /**
     * Shared renderer for plain &lt;input&gt; field types.
     *
     * @param array<string, mixed> $attrs
     * @param array<string, scalar|null> $extra Additional HTML attributes (null values are dropped).
     */
    private static function render_input(string $input_type, array $attrs, array $extra = []): string
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
        $autocomplete = isset($attrs['autocomplete']) && is_string($attrs['autocomplete']) ? (string) $attrs['autocomplete'] : self::guess_autocomplete($input_type, $slug);

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
            '<input type="%s" id="%s" name="%s"%s%s%s%s%s>',
            esc_attr($input_type),
            esc_attr($id),
            esc_attr($name),
            $placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '',
            $max > 0 ? ' maxlength="' . $max . '"' : '',
            $required ? ' required aria-required="true"' : '',
            $autocomplete !== '' ? ' autocomplete="' . esc_attr($autocomplete) . '"' : '',
            $extra_html
        );

        return self::wrap_field($input_type, $slug, $label, $helper, $required, $control, $id);
    }

    /**
     * Renders radio / multi-checkbox: a labelled group of input+label pairs.
     *
     * @param array<string, mixed> $attrs
     */
    private static function render_option_group(string $kind, array $attrs): string
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
                '<label class="lrob-etk-cf-option" for="%s"><input type="%s" id="%s" name="%s" value="%s"%s><span>%s</span></label>',
                esc_attr($opt_id),
                $input_type,
                esc_attr($opt_id),
                esc_attr($name),
                esc_attr($opt['value']),
                $required && $kind === 'radio' && $idx === 0 ? ' required aria-required="true"' : '',
                esc_html($opt['label'])
            );
        }

        $helper_html = $helper !== '' ? '<p class="lrob-etk-cf-helper">' . esc_html($helper) . '</p>' : '';
        $error_html = '<p class="lrob-etk-cf-error" data-field-error hidden></p>';
        $required_marker = $required ? ' <span class="lrob-etk-cf-required" aria-hidden="true">*</span>' : '';

        return sprintf(
            '<fieldset class="lrob-etk-cf-field lrob-etk-cf-field--%s" data-field="%s" id="%s"><legend class="lrob-etk-cf-label">%s%s</legend><div class="lrob-etk-cf-options">%s</div>%s%s</fieldset>',
            $kind,
            esc_attr($slug),
            esc_attr($group_id),
            esc_html($label),
            $required_marker,
            $items,
            $helper_html,
            $error_html
        );
    }

    /** Wrap a single-control field (text/email/textarea/select/etc.) with label + helper + error markup. */
    private static function wrap_field(
        string $type_class,
        string $slug,
        string $label,
        string $helper,
        bool $required,
        string $control_html,
        string $control_id
    ): string {
        $label_html = $label !== ''
            ? sprintf(
                '<label class="lrob-etk-cf-label" for="%s">%s%s</label>',
                esc_attr($control_id),
                esc_html($label),
                $required ? ' <span class="lrob-etk-cf-required" aria-hidden="true">*</span>' : ''
            )
            : '';
        $helper_html = $helper !== '' ? '<p class="lrob-etk-cf-helper">' . esc_html($helper) . '</p>' : '';
        $error_html = '<p class="lrob-etk-cf-error" data-field-error hidden></p>';

        return sprintf(
            '<div class="lrob-etk-cf-field lrob-etk-cf-field--%s" data-field="%s">%s%s%s%s</div>',
            esc_attr($type_class),
            esc_attr($slug),
            $label_html,
            $control_html,
            $helper_html,
            $error_html
        );
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function normalize_slug(array $attrs): string
    {
        $raw = isset($attrs['slug']) && is_string($attrs['slug']) ? (string) $attrs['slug'] : '';
        $slug = sanitize_key($raw);
        return $slug;
    }

    /**
     * Coerce options into a list of [value, label] pairs. Accepts either
     * the block-attribute shape (objects with value/label) or a plain list
     * of strings.
     *
     * @param mixed $raw
     * @return array<int, array{value:string, label:string}>
     */
    private static function normalize_options(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $value = isset($item['value']) && is_scalar($item['value']) ? (string) $item['value'] : '';
                $label = isset($item['label']) && is_scalar($item['label']) ? (string) $item['label'] : $value;
                if ($label === '' && $value === '') {
                    continue;
                }
                if ($value === '') {
                    $value = sanitize_title($label);
                }
                $out[] = ['value' => $value, 'label' => $label];
                continue;
            }
            if (is_string($item) && $item !== '') {
                $out[] = ['value' => sanitize_title($item), 'label' => $item];
            }
        }
        return $out;
    }

    /** Guess a sensible browser autocomplete value for common field slugs. */
    private static function guess_autocomplete(string $input_type, string $slug): string
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
}
