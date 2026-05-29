<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

/**
 * Shared admin combobox renderer. See docs/admin-ui.md for both variants + auto-save wiring.
 * Runtime: admin/js/etk-controls.js.
 */
final class Combobox
{
    /** @param array<int, array{value:string, label:string}> $options */
    public static function render_fixed_select(
        string $meta_key,
        string $current_value,
        array $options,
        string $inherit_value = '',
        string $auto_save_marker = ''
    ): void {
        $default_label = '';
        $current_label = '';
        foreach ($options as $opt) {
            if ((string) $opt['value'] === $inherit_value) {
                $default_label = (string) $opt['label'];
            }
            if ((string) $opt['value'] === (string) $current_value) {
                $current_label = (string) $opt['label'];
            }
        }
        $is_inheriting = ((string) $current_value === $inherit_value);
        $input_value = $is_inheriting ? '' : $current_label;
        $placeholder = $default_label;

        $combo_id = 'lrob-etk-combo-' . md5($meta_key . wp_generate_uuid4());
        ?>
        <div class="lrob-etk-combo lrob-etk-combo--select"
             data-options="<?php echo esc_attr((string) wp_json_encode($options)); ?>"
             data-inherit-value="<?php echo esc_attr($inherit_value); ?>"
             data-default-placeholder="<?php echo esc_attr($placeholder); ?>">
            <input type="text"
                   id="<?php echo esc_attr($combo_id); ?>"
                   class="lrob-etk-combo-input"
                   value="<?php echo esc_attr($input_value); ?>"
                   placeholder="<?php echo esc_attr($placeholder); ?>"
                   readonly
                   autocomplete="off">
            <button type="button" class="lrob-etk-combo-toggle" tabindex="-1"
                    aria-label="<?php esc_attr_e('Open options', 'lrob-email-toolkit'); ?>">
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
            <ul class="lrob-etk-combo-menu" role="listbox" hidden></ul>
            <input type="hidden"
                   name="<?php echo esc_attr($meta_key); ?>"
                   class="lrob-etk-combo-value<?php echo $auto_save_marker !== '' ? ' ' . esc_attr($auto_save_marker) : ''; ?>"
                   data-key="<?php echo esc_attr($meta_key); ?>"
                   value="<?php echo esc_attr((string) $current_value); ?>">
        </div>
        <?php
    }

    /** @param array<int, array{value:string, label:string}> $suggestions */
    public static function render_free_text(
        string $meta_key,
        string $current_value,
        array $suggestions,
        string $placeholder = '',
        string $auto_save_marker = ''
    ): void {
        ?>
        <div class="lrob-etk-combo lrob-etk-combo--free"
             data-options="<?php echo esc_attr((string) wp_json_encode($suggestions)); ?>">
            <input type="text"
                   name="<?php echo esc_attr($meta_key); ?>"
                   class="lrob-etk-combo-input<?php echo $auto_save_marker !== '' ? ' ' . esc_attr($auto_save_marker) : ''; ?>"
                   data-key="<?php echo esc_attr($meta_key); ?>"
                   value="<?php echo esc_attr($current_value); ?>"
                   placeholder="<?php echo esc_attr($placeholder); ?>"
                   autocomplete="off">
            <button type="button" class="lrob-etk-combo-toggle" tabindex="-1"
                    aria-label="<?php esc_attr_e('Open suggestions', 'lrob-email-toolkit'); ?>">
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
            <ul class="lrob-etk-combo-menu" role="listbox" hidden></ul>
        </div>
        <?php
    }
}
