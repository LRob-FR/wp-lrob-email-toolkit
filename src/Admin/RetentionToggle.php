<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

/**
 * Shared "auto-delete after N days" widget. Renders a checkbox + number
 * input + unit label, backed by a hidden field with `data-key` that the
 * consuming module's auto-save listener picks up.
 *
 * Storage contract: the hidden value is the canonical setting.
 *   - 0  = auto-cleanup disabled (the checkbox is unchecked).
 *   - >0 = auto-cleanup enabled with that many days.
 *
 * The visible number input always shows a usable value (the stored days
 * when enabled, otherwise the renderer's default-days fallback) so admins
 * can flip the checkbox on without having to type a number first.
 *
 * Runtime in admin/js/etk-retention-toggle.js (plugin-wide). The widget
 * exposes a marker class on the checkbox + number so the JS finds them
 * via event delegation.
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
