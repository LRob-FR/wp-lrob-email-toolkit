<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

use LRob\EmailToolkit\Modules\ModuleInterface;

/**
 * Renders the module enable/disable UI. Three shapes:
 *
 *   - render_inline()  — compact switch placed inline with the page H1 title.
 *                        Submits on change via JS.
 *   - render_bar()     — wider toggle pill (legacy; used by Logging until it
 *                        gets the inline treatment).
 *   - render_cta()     — full-card call-to-action with a big enable button.
 *
 * All POST to admin-post.php with action = $module->toggle_action() and a
 * matching nonce; AbstractModule::handle_toggle() owns the response.
 */
final class ModuleToggle
{
    /**
     * Inline switch + status word. Sits next to the page title. Form
     * auto-submits on toggle change — module on/off triggers a reload anyway
     * (hooks load/unload), so AJAX would gain little.
     */
    public static function render_inline(ModuleInterface $module): void
    {
        $enabled = $module->is_enabled();
        $action_url = admin_url('admin-post.php');
        ?>
        <form method="post" action="<?php echo esc_url($action_url); ?>" class="lrob-etk-page-toggle-form">
            <input type="hidden" name="action" value="<?php echo esc_attr($module->toggle_action()); ?>">
            <?php wp_nonce_field($module->toggle_action(), '_lrob_etk_nonce'); ?>
            <label class="lrob-etk-page-toggle">
                <input type="checkbox" name="enable" value="1" <?php checked($enabled); ?>
                       onchange="this.form.submit()">
                <span class="lrob-etk-switch-track"></span>
                <span class="lrob-etk-page-toggle-state">
                    <?php echo $enabled
                        ? esc_html__('Enabled', 'lrob-email-toolkit')
                        : esc_html__('Disabled', 'lrob-email-toolkit'); ?>
                </span>
            </label>
        </form>
        <?php
    }

    public static function render_bar(ModuleInterface $module): void
    {
        $enabled = $module->is_enabled();
        $action_url = admin_url('admin-post.php');
        $state_label = $enabled
            ? __('Active', 'lrob-email-toolkit')
            : __('Inactive', 'lrob-email-toolkit');
        ?>
        <div class="lrob-etk-toggle-bar <?php echo $enabled ? 'is-on' : 'is-off'; ?>">
            <span class="lrob-etk-toggle-dot" aria-hidden="true"></span>
            <span class="lrob-etk-toggle-state"><?php echo esc_html($state_label); ?></span>
            <span class="lrob-etk-toggle-name"><?php echo esc_html($module->name()); ?></span>
            <form method="post" action="<?php echo esc_url($action_url); ?>" class="lrob-etk-toggle-form">
                <input type="hidden" name="action" value="<?php echo esc_attr($module->toggle_action()); ?>">
                <?php wp_nonce_field($module->toggle_action(), '_lrob_etk_nonce'); ?>
                <?php if ($enabled) : ?>
                    <button type="submit" class="button button-link lrob-etk-toggle-button">
                        <?php esc_html_e('Disable', 'lrob-email-toolkit'); ?>
                    </button>
                <?php else : ?>
                    <input type="hidden" name="enable" value="1">
                    <button type="submit" class="button button-primary lrob-etk-toggle-button">
                        <?php esc_html_e('Enable', 'lrob-email-toolkit'); ?>
                    </button>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    /**
     * CTA card for the disabled-and-empty state. The $cta_label customizes the
     * primary action wording ("Configure SMTP", "Enable logging", etc.).
     */
    public static function render_cta(ModuleInterface $module, string $cta_label, string $description): void
    {
        $action_url = admin_url('admin-post.php');
        ?>
        <div class="lrob-etk-cta">
            <div class="lrob-etk-cta-icon dashicons dashicons-email-alt"></div>
            <h2 class="lrob-etk-cta-title"><?php echo esc_html($module->name()); ?></h2>
            <p class="lrob-etk-cta-description"><?php echo esc_html($description); ?></p>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr($module->toggle_action()); ?>">
                <input type="hidden" name="enable" value="1">
                <?php wp_nonce_field($module->toggle_action(), '_lrob_etk_nonce'); ?>
                <button type="submit" class="button button-primary button-hero">
                    <?php echo esc_html($cta_label); ?>
                </button>
            </form>
        </div>
        <?php
    }
}
