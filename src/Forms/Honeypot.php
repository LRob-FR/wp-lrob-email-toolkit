<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

// Docs: docs/forms.md
final class Honeypot
{
    public const FIELD_NAME = '_lrob_etk_form_hp_website';

    public static function render(): string
    {
        return sprintf(
            '<div class="lrob-etk-form-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden">' .
            '<label>%s<input type="text" name="%s" tabindex="-1" autocomplete="off" value=""></label>' .
            '</div>',
            esc_html__('Website (leave blank)', 'lrob-email-toolkit'),
            esc_attr(self::FIELD_NAME)
        );
    }

    public static function tripped(array $post): bool
    {
        $value = $post[self::FIELD_NAME] ?? '';
        if (!is_string($value)) {
            return true;
        }
        return trim($value) !== '';
    }
}
