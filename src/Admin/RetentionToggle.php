<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

/**
 * "Auto-delete after N days" widget. 0 stored = disabled; >0 = enabled days.
 * Runtime: admin/js/etk-retention-toggle.js. See docs/admin-ui.md.
 */
final class RetentionToggle
{
    /**
     * @param array{
     *     key: string,
     *     value: int,
     *     auto_save_marker?: string,
     *     default_days?: int,
     *     max_days?: int,
     *     label?: string,
     * } $args
     */
    public static function render(array $args): void
    {
        $key = (string) $args['key'];
        $value = max(0, (int) ($args['value'] ?? 0));
        $marker = (string) ($args['auto_save_marker'] ?? '');
        $default_days = max(1, (int) ($args['default_days'] ?? 365));
        $max_days = max($default_days, (int) ($args['max_days'] ?? 3650));
        $label = (string) ($args['label'] ?? __('Auto-delete after', 'lrob-email-toolkit'));

        $enabled = $value > 0;
        $display_days = $enabled ? $value : $default_days;
        ?>
        <div class="lrob-etk-retention-toggle" data-retention-toggle>
            <label class="lrob-etk-retention-toggle-check">
                <input type="checkbox" data-retention-enable<?php echo $enabled ? ' checked' : ''; ?>>
                <span class="lrob-etk-retention-toggle-label"><?php echo esc_html($label); ?></span>
            </label>
            <input type="number"
                   class="small-text lrob-etk-retention-toggle-days"
                   data-retention-days
                   min="1"
                   max="<?php echo (int) $max_days; ?>"
                   value="<?php echo (int) $display_days; ?>"
                   data-default-days="<?php echo (int) $default_days; ?>"
                   <?php echo $enabled ? '' : 'disabled'; ?>>
            <span class="lrob-etk-retention-toggle-unit"><?php esc_html_e('days', 'lrob-email-toolkit'); ?></span>
            <input type="hidden"
                   class="lrob-etk-retention-toggle-value<?php echo $marker !== '' ? ' ' . esc_attr($marker) : ''; ?>"
                   name="<?php echo esc_attr($key); ?>"
                   data-key="<?php echo esc_attr($key); ?>"
                   value="<?php echo (int) $value; ?>">
        </div>
        <?php
    }
}
