<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;
use LRob\EmailToolkit\Forms\FormContext;
use LRob\EmailToolkit\Modules\Newsletter\ListRepository;

/**
 * "Which lists would you like to join?" field. Renders as a checkbox
 * group with one option per existing list. Ticked = becomes a member
 * of that list; unticked = not added.
 *
 * Unlike CategoryPicker (where default IS subscribed and unticking
 * opts out), the list picker is opt-IN: subscribers must explicitly
 * tick to join. Default state for new subscribers without a list-
 * picker field is "no lists" (or the form's default list if
 * configured per-form, set on the form card's settings panel).
 *
 * Options come from ListRepository at render time. Submitted values
 * are list IDs.
 */
final class ListPicker implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'list_picker';
    }

    public function label(): string
    {
        return __('List picker', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        return FieldRenderHelpers::normalize_base_keys($field, $this->slug());
    }

    public function render(array $attrs): string
    {
        if (!FormContext::is_active()) {
            return '';
        }
        $slug = FieldRenderHelpers::normalize_slug($attrs);
        if ($slug === '') {
            $slug = 'lists';
        }
        $label = isset($attrs['label']) ? (string) $attrs['label'] : __('Choose your lists', 'lrob-email-toolkit');
        $helper = isset($attrs['helper']) ? (string) $attrs['helper'] : '';

        $lists = (new ListRepository())->list_all();
        if ($lists === []) {
            return FieldRenderHelpers::wrap_field(
                'list-picker',
                $slug,
                $label,
                $helper,
                false,
                '<p class="lrob-etk-form-helper">' . esc_html__('No lists available yet.', 'lrob-email-toolkit') . '</p>',
                FormContext::field_id($slug)
            );
        }

        $name = FormContext::field_name($slug, true);
        $group_id = FormContext::field_id($slug);

        $items = '';
        foreach ($lists as $idx => $row) {
            $list_id = (int) ($row['id'] ?? 0);
            $list_name = (string) ($row['name'] ?? '');
            if ($list_id <= 0 || $list_name === '') {
                continue;
            }
            $opt_id = $group_id . '-' . $idx;
            $items .= sprintf(
                '<label class="lrob-etk-form-option" for="%s"><input type="checkbox" id="%s" name="%s" value="%d"><span>%s</span></label>',
                esc_attr($opt_id),
                esc_attr($opt_id),
                esc_attr($name),
                $list_id,
                esc_html($list_name)
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
            '<fieldset class="lrob-etk-form-field lrob-etk-form-field--list-picker" data-field="%s" id="%s"><legend class="lrob-etk-form-label">%s</legend><div class="lrob-etk-form-options">%s</div>%s%s</fieldset>',
            esc_attr($slug),
            esc_attr($group_id),
            $legend_inner,
            $items,
            $helper_html,
            $error_html
        );
    }
}
