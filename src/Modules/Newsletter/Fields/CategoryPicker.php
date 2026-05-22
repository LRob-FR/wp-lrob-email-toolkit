<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;
use LRob\EmailToolkit\Forms\FormContext;
use LRob\EmailToolkit\Modules\Newsletter\CategoryRepository;

/**
 * "Pick which kinds of emails you want" field. Renders as a checkbox
 * group with one option per category. Ticked = subscribed to that
 * category; unticked = opt-out.
 *
 * Default state for new subscribers when this field IS present:
 * un-ticked categories become opt-outs immediately. When the field
 * is ABSENT from the form, the submitter inherits the global default
 * (opted in to everything) — see SubmitHandler.
 *
 * Options come from CategoryRepository at render time so a freshly-
 * added category appears in existing forms automatically without
 * needing to re-edit them. Stored values aren't the category IDs but
 * the SLUGS, mirroring the subscribers.category_opt_outs storage.
 */
final class CategoryPicker implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'category_picker';
    }

    public function label(): string
    {
        return __('Category picker', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $base = FieldRenderHelpers::normalize_base_keys($field, $this->slug());
        // No type-specific keys for now. Future: opt-in to default-
        // all-checked vs default-none-checked, max-columns layout, etc.
        return $base;
    }

    public function render(array $attrs): string
    {
        if (!FormContext::is_active()) {
            return '';
        }
        $slug = FieldRenderHelpers::normalize_slug($attrs);
        if ($slug === '') {
            $slug = 'categories';
        }
        $label = isset($attrs['label']) ? (string) $attrs['label'] : __('Categories', 'lrob-email-toolkit');
        $helper = isset($attrs['helper']) ? (string) $attrs['helper'] : '';

        $categories = (new CategoryRepository())->list_all();
        if ($categories === []) {
            return FieldRenderHelpers::wrap_field(
                'category-picker',
                $slug,
                $label,
                $helper,
                false,
                '<p class="lrob-etk-form-helper">' . esc_html__('No categories available.', 'lrob-email-toolkit') . '</p>',
                FormContext::field_id($slug)
            );
        }

        $name = FormContext::field_name($slug, true); // [] suffix — checkboxes are multi-value
        $group_id = FormContext::field_id($slug);

        $items = '';
        foreach ($categories as $idx => $cat) {
            $cat_slug = (string) ($cat['slug'] ?? '');
            $cat_name = (string) ($cat['name'] ?? $cat_slug);
            if ($cat_slug === '') {
                continue;
            }
            $opt_id = $group_id . '-' . $idx;
            // Default-checked — subscribers opt OUT by unticking.
            // Future "default unchecked" mode is a per-field config.
            $items .= sprintf(
                '<label class="lrob-etk-form-option" for="%s"><input type="checkbox" id="%s" name="%s" value="%s" checked><span>%s</span></label>',
                esc_attr($opt_id),
                esc_attr($opt_id),
                esc_attr($name),
                esc_attr($cat_slug),
                esc_html($cat_name)
            );
        }

        $helper_html = FieldRenderHelpers::helper_html($helper);
        $error_html = '<p class="lrob-etk-form-error" data-field-error hidden></p>';

        if (FormContext::is_editor()) {
            $shown = $label !== '' ? esc_html($label) : '<span class="lrob-etk-form-label-empty">' . esc_html__('(field label)', 'lrob-email-toolkit') . '</span>';
            $legend_inner = sprintf(
                '<span class="lrob-etk-form-label-text" contenteditable="plaintext-only" data-edit="label" spellcheck="false">%s</span>%s',
                $shown,
                FieldRenderHelpers::required_toggle_html(false)
            );
        } else {
            $legend_inner = esc_html($label);
        }

        return sprintf(
            '<fieldset class="lrob-etk-form-field lrob-etk-form-field--category-picker" data-field="%s" id="%s"><legend class="lrob-etk-form-label">%s</legend><div class="lrob-etk-form-options">%s</div>%s%s</fieldset>',
            esc_attr($slug),
            esc_attr($group_id),
            $legend_inner,
            $items,
            $helper_html,
            $error_html
        );
    }
}
