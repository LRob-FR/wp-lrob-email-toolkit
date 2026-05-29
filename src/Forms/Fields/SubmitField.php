<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;
use LRob\EmailToolkit\Forms\FormContext;

final class SubmitField implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'submit';
    }

    public function label(): string
    {
        return __('Submit button', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $text = isset($field['text']) ? sanitize_text_field((string) $field['text']) : '';
        if ($text === '') {
            $text = __('Send', 'lrob-email-toolkit');
        }
        $align = isset($field['align']) ? (string) $field['align'] : 'right';
        if (!in_array($align, ['left', 'center', 'right', 'stretch'], true)) {
            $align = 'right';
        }
        return [
            'id'    => isset($field['id']) && is_string($field['id']) && $field['id'] !== ''
                ? sanitize_key($field['id'])
                : FieldRenderHelpers::gen_id('f'),
            'type'  => $this->slug(),
            'nth'   => isset($field['nth']) ? max(0, (int) $field['nth']) : 0,
            'text'  => $text,
            'align' => $align,
        ];
    }

    public function render(array $attrs): string
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

        if (FormContext::is_editor()) {
            return sprintf(
                '<div class="lrob-etk-form-field lrob-etk-form-field--submit is-align-%s"><button type="button" class="lrob-etk-form-submit"><span class="lrob-etk-form-submit-label" contenteditable="plaintext-only" data-edit="submit-text">%s</span></button></div>',
                esc_attr($align),
                esc_html($text)
            );
        }

        return sprintf(
            '<div class="lrob-etk-form-field lrob-etk-form-field--submit is-align-%s"><button type="submit" class="lrob-etk-form-submit"><span class="lrob-etk-form-submit-label">%s</span><span class="lrob-etk-form-submit-spinner" aria-hidden="true"></span></button></div>',
            esc_attr($align),
            esc_html($text)
        );
    }
}
