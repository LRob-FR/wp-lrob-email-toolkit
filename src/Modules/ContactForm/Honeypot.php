<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Hidden field bots happily fill but humans never see. CSS-hidden, off-screen,
 * tab-index out of order, autocomplete off — and given a plausible name
 * (`website`) so dumb crawlers will recognize and fill it.
 *
 * A naive `display:none` is easy for headless browsers to spot, so we hide via
 * `position:absolute; left:-9999px` plus aria-hidden plus tabindex=-1.
 */
final class Honeypot
{
    public const FIELD_NAME = '_lrob_etk_cf_hp_website';

    public static function render(): string
    {
        return sprintf(
            '<div class="lrob-etk-cf-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden">' .
            '<label>%s<input type="text" name="%s" tabindex="-1" autocomplete="off" value=""></label>' .
            '</div>',
            esc_html__('Website (leave blank)', 'lrob-email-toolkit'),
            esc_attr(self::FIELD_NAME)
        );
    }

    /** Returns true when the honeypot was triggered (i.e. submission is bot-like). */
    public static function tripped(array $post): bool
    {
        $value = $post[self::FIELD_NAME] ?? '';
        if (!is_string($value)) {
            return true;
        }
        return trim($value) !== '';
    }
}
