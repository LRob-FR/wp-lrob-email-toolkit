<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

/**
 * Hidden honeypot input bots happily fill but humans never see. CSS-
 * hidden + offscreen + tabindex out of order + autocomplete off, plus
 * a plausible field name (`website`) so dumb crawlers fill it.
 *
 * Host-neutral — both Contact Form and Newsletter submit pipelines
 * inject this same field. Renamed from the contact-form-specific
 * `_lrob_etk_cf_hp_website` to the neutral `_lrob_etk_form_hp_website`;
 * the honeypot renders fresh per request so no persistence
 * compatibility concern.
 */
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
