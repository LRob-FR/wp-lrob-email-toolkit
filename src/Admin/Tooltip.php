<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

/**
 * Info-bubble tooltip (position:fixed via JS). See docs/admin-ui.md.
 */
final class Tooltip
{
    public static function render(string $content, string $icon = 'info-outline'): void
    {
        echo self::html($content, $icon);
    }

    public static function html(string $content, string $icon = 'info-outline'): string
    {
        $icon_class = 'dashicons-' . sanitize_html_class($icon);
        return sprintf(
            '<span class="lrob-etk-tip" tabindex="0" role="button" aria-label="%1$s"><span class="dashicons %2$s" aria-hidden="true"></span><span class="lrob-etk-tip-text" role="tooltip">%3$s</span></span>',
            esc_attr__('More info', 'lrob-email-toolkit'),
            esc_attr($icon_class),
            esc_html($content)
        );
    }
}
