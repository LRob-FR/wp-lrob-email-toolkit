<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\ModuleManager;

/**
 * Admin page that toggles each module on/off. POST handler runs at
 * admin_post_lrob_etk_toggle_modules and redirects back with a status arg.
 */
final class ModulesPage
{
    private const NONCE_ACTION = 'lrob_etk_toggle_modules';

    private const NONCE_FIELD = '_lrob_etk_nonce';

    public function __construct(private ModuleManager $manager)
    {
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage Email Toolkit modules.', 'lrob-email-toolkit'));
        }

        $modules = $this->manager->all();
        $action_url = admin_url('admin-post.php');
        $updated = isset($_GET['updated']) && $_GET['updated'] === '1';
        ?>
        <div class="wrap lrob-etk">
            <h1><?php esc_html_e('Email Toolkit — Modules', 'lrob-email-toolkit'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Module settings updated.', 'lrob-email-toolkit'); ?></p>
                </div>
            <?php endif; ?>

            <p class="description">
                <?php esc_html_e('Enable only the modules you need. Each module is independent — your data is preserved if you disable and re-enable later.', 'lrob-email-toolkit'); ?>
            </p>

            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="action" value="lrob_etk_toggle_modules">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <table class="form-table lrob-etk-modules-table">
                    <tbody>
                        <?php foreach ($modules as $module) :
                            $slug = $module->slug();
                            $enabled = $this->manager->is_enabled($slug);
                            ?>
                            <tr>
                                <th scope="row">
                                    <label for="lrob-etk-module-<?php echo esc_attr($slug); ?>">
                                        <?php echo esc_html($module->name()); ?>
                                    </label>
                                </th>
                                <td>
                                    <label>
                                        <input
                                            type="checkbox"
                                            id="lrob-etk-module-<?php echo esc_attr($slug); ?>"
                                            name="modules[<?php echo esc_attr($slug); ?>]"
                                            value="1"
                                            <?php checked($enabled); ?>
                                        >
                                        <?php esc_html_e('Enable this module', 'lrob-email-toolkit'); ?>
                                    </label>
                                    <p class="description"><?php echo esc_html($module->description()); ?></p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button(__('Save changes', 'lrob-email-toolkit')); ?>
            </form>
        </div>
        <?php
    }

    public function handle_toggle(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage Email Toolkit modules.', 'lrob-email-toolkit'));
        }

        $nonce = isset($_POST[self::NONCE_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }

        $submitted = isset($_POST['modules']) && is_array($_POST['modules'])
            ? array_map('boolval', wp_unslash($_POST['modules']))
            : [];

        foreach ($this->manager->all() as $slug => $_module) {
            if (!empty($submitted[$slug])) {
                $this->manager->enable($slug);
            } else {
                $this->manager->disable($slug);
            }
        }

        $redirect = add_query_arg(
            ['page' => Menu::SLUG_MODULES, 'updated' => '1'],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }
}
