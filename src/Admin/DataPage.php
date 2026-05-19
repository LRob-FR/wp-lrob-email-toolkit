<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\ModuleManager;

/**
 * Plugin-wide data management page. Three sections:
 *
 *   1. Per-module data wipe — call $module->uninstall() to drop tables +
 *      options for one module, then disable that module so the user must
 *      re-enable to recreate fresh state. Confirm requires typing the
 *      module slug.
 *   2. Reset entire plugin — same but for every module, plus options /
 *      capability / cron events. Confirm requires typing the plugin slug.
 *   3. Uninstall behaviour — picks the lrob_etk_uninstall_mode option
 *      (keep / archive / wipe) that uninstall.php branches on.
 *
 * Service modules (Captcha) appear in the list but their wipe action only
 * resets their settings option; tables, if any, are scoped by the same
 * uninstall() pattern.
 */
final class DataPage
{
    public const SLUG = 'lrob-etk-data';

    public const ACTION_WIPE_MODULE = 'lrob_etk_wipe_module';

    public const ACTION_RESET_ALL = 'lrob_etk_reset_all';

    public const ACTION_SAVE_UNINSTALL_MODE = 'lrob_etk_save_uninstall_mode';

    public function __construct(private ModuleManager $manager)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_submenu'], 90);
        add_action('admin_head', [$this, 'hide_menu_link']);
        add_action('admin_post_' . self::ACTION_WIPE_MODULE,         [$this, 'handle_wipe_module']);
        add_action('admin_post_' . self::ACTION_RESET_ALL,           [$this, 'handle_reset_all']);
        add_action('admin_post_' . self::ACTION_SAVE_UNINSTALL_MODE, [$this, 'handle_save_uninstall_mode']);
    }

    /**
     * Register the page as a normal submenu so WP's access check
     * (user_can_access_admin_page()) finds it in $submenu — the entry
     * point is a button at the bottom of the Dashboard, the link itself
     * is CSS-hidden in hide_menu_link(). Earlier attempts to use
     * `add_submenu_page(null, ...)` or `remove_submenu_page` after add
     * both have failure modes — null parent triggers PHP 8.1+
     * deprecations, and remove_submenu_page makes WP refuse access with
     * "Sorry, you are not allowed to access this page." See
     * [[project-wp-hidden-submenu-pattern]] memory for the long version.
     */
    public function add_submenu(): void
    {
        add_submenu_page(
            Menu::SLUG,
            __('Plugin data', 'lrob-email-toolkit'),
            __('Plugin data', 'lrob-email-toolkit'),
            Activator::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    /**
     * Hide the submenu link in the left-hand admin menu without removing
     * the page from $submenu (which would break user_can_access_admin_page).
     */
    public function hide_menu_link(): void
    {
        echo '<style>#adminmenu a[href$="page=' . esc_attr(self::SLUG) . '"]{display:none !important;}</style>';
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $modules = $this->manager->all();
        $action_url = admin_url('admin-post.php');
        $current_mode = (string) get_option(Activator::OPTION_UNINSTALL_MODE, Activator::UNINSTALL_MODE_DEFAULT);
        $last_action = (string) get_option('lrob_etk_data_last_action', '');
        $notice = isset($_GET['done']) ? (string) $_GET['done'] : '';
        ?>
        <div class="lrob-etk wrap">
            <div class="lrob-etk-page-header">
                <h1 class="lrob-etk-page-title"><?php esc_html_e('Plugin data', 'lrob-email-toolkit'); ?></h1>
            </div>

            <?php if ($notice !== '') : ?>
                <div class="lrob-etk-flash"><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div></div>
            <?php endif; ?>

            <p class="description" style="max-width: 760px;">
                <?php esc_html_e('Manage what the toolkit stores in your database. Wipes are immediate, destructive, and not undoable — there is no trash bin.', 'lrob-email-toolkit'); ?>
            </p>

            <?php if ($last_action !== '') : ?>
                <p style="color: var(--etk-muted); font-size: 12px;">
                    <?php
                    printf(
                        /* translators: %s: last destructive action description and timestamp */
                        esc_html__('Last destructive action: %s', 'lrob-email-toolkit'),
                        '<strong>' . esc_html($last_action) . '</strong>'
                    );
                    ?>
                </p>
            <?php endif; ?>

            <h2 class="lrob-etk-section-title"><?php esc_html_e('Per-module data', 'lrob-email-toolkit'); ?></h2>
            <div class="lrob-etk-form-section" style="background:#fff; border:1px solid var(--etk-line); border-radius: var(--etk-radius); padding: 8px 0; max-width: 880px;">
                <?php foreach ($modules as $module) :
                    $summary = $module->data_summary();
                    $slug = $module->slug();
                ?>
                    <div style="display: grid; grid-template-columns: 200px 1fr auto; gap: 12px; align-items: center; padding: 12px 20px; border-bottom: 1px solid var(--etk-soft);">
                        <strong><?php echo esc_html($module->name()); ?></strong>
                        <span style="color: var(--etk-muted); font-size: 13px;">
                            <?php echo $summary !== '' ? esc_html($summary) : esc_html__('No stored data.', 'lrob-email-toolkit'); ?>
                        </span>
                        <?php if ($summary !== '') : ?>
                            <details>
                                <summary class="button" style="cursor: pointer;"><?php esc_html_e('Wipe…', 'lrob-email-toolkit'); ?></summary>
                                <form method="post" action="<?php echo esc_url($action_url); ?>" style="margin-top: 8px; padding: 10px; background: var(--etk-danger-bg); border-radius: 4px;">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_WIPE_MODULE); ?>">
                                    <input type="hidden" name="module" value="<?php echo esc_attr($slug); ?>">
                                    <?php wp_nonce_field(self::ACTION_WIPE_MODULE, '_lrob_etk_nonce'); ?>
                                    <p style="margin: 0 0 8px; color: #8a0000; font-size: 12px;">
                                        <?php
                                        printf(
                                            /* translators: %s: module slug the user must type to confirm */
                                            esc_html__('This drops all data for this module and disables it. To confirm, type the module slug: %s', 'lrob-email-toolkit'),
                                            '<code>' . esc_html($slug) . '</code>'
                                        );
                                        ?>
                                    </p>
                                    <input type="text" name="confirm" required pattern="<?php echo esc_attr($slug); ?>" placeholder="<?php echo esc_attr($slug); ?>" style="width: 200px;">
                                    <button type="submit" class="button button-secondary" style="color: #8a0000; border-color: #8a0000;"><?php esc_html_e('Wipe data', 'lrob-email-toolkit'); ?></button>
                                </form>
                            </details>
                        <?php else : ?>
                            <span style="color: var(--etk-line-strong); font-size: 12px;"><?php esc_html_e('Nothing to wipe', 'lrob-email-toolkit'); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <h2 class="lrob-etk-section-title"><?php esc_html_e('Reset entire plugin', 'lrob-email-toolkit'); ?></h2>
            <div class="lrob-etk-form-section" style="background: var(--etk-danger-bg); border: 1px solid var(--etk-danger); border-radius: var(--etk-radius); padding: 14px 18px; max-width: 880px;">
                <p style="margin: 0 0 10px; color: #8a0000;">
                    <strong><?php esc_html_e('Dangerous!', 'lrob-email-toolkit'); ?></strong>
                    <?php esc_html_e('Drops every plugin table, deletes every setting, removes the capability, and clears all scheduled events. Same effect as deleting the plugin in "wipe" mode but you stay activated.', 'lrob-email-toolkit'); ?>
                </p>
                <details>
                    <summary class="button" style="cursor: pointer;"><?php esc_html_e('Reset entire plugin…', 'lrob-email-toolkit'); ?></summary>
                    <form method="post" action="<?php echo esc_url($action_url); ?>" style="margin-top: 10px; padding: 12px; background: #fff; border-radius: 4px;">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RESET_ALL); ?>">
                        <?php wp_nonce_field(self::ACTION_RESET_ALL, '_lrob_etk_nonce'); ?>
                        <p style="margin: 0 0 8px; color: #8a0000; font-size: 12px;">
                            <?php
                            printf(
                                /* translators: %s: literal text "lrob-email-toolkit" the user must type */
                                esc_html__('Type %s to confirm. After this completes, the page will redirect to the dashboard with the plugin in a freshly-installed state.', 'lrob-email-toolkit'),
                                '<code>lrob-email-toolkit</code>'
                            );
                            ?>
                        </p>
                        <input type="text" name="confirm" required pattern="lrob-email-toolkit" placeholder="lrob-email-toolkit" style="width: 240px;">
                        <button type="submit" class="button button-secondary" style="color: #8a0000; border-color: #8a0000;"><?php esc_html_e('Reset everything', 'lrob-email-toolkit'); ?></button>
                    </form>
                </details>
            </div>

            <h2 class="lrob-etk-section-title"><?php esc_html_e('Uninstall behaviour', 'lrob-email-toolkit'); ?></h2>
            <div class="lrob-etk-form-section" style="background:#fff; border:1px solid var(--etk-line); border-radius: var(--etk-radius); padding: 16px 20px; max-width: 880px;">
                <p style="margin: 0 0 12px; color: var(--etk-muted);">
                    <?php esc_html_e('What should happen if someone deletes this plugin from the WordPress "Plugins" page?', 'lrob-email-toolkit'); ?>
                </p>
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SAVE_UNINSTALL_MODE); ?>">
                    <?php wp_nonce_field(self::ACTION_SAVE_UNINSTALL_MODE, '_lrob_etk_nonce'); ?>
                    <?php
                    $options = [
                        'keep'    => [
                            __('Keep everything (default, safest)', 'lrob-email-toolkit'),
                            __('Data, settings, and capability all stay. Reinstalling picks up exactly where you left off.', 'lrob-email-toolkit'),
                        ],
                        'archive' => [
                            __('Archive: drop settings + capability + cron, keep data tables', 'lrob-email-toolkit'),
                            __('Reinstalling gives you a clean slate for configuration but your logs, identities, contact forms, and submissions are still there.', 'lrob-email-toolkit'),
                        ],
                        'wipe'    => [
                            __('Wipe everything', 'lrob-email-toolkit'),
                            __('Drops every table, deletes every option, removes the capability. No way back from a deletion.', 'lrob-email-toolkit'),
                        ],
                    ];
                    foreach ($options as $value => [$label, $help]) : ?>
                        <label style="display:block; padding: 10px 0; border-bottom: 1px solid var(--etk-soft); cursor: pointer;">
                            <input type="radio" name="mode" value="<?php echo esc_attr($value); ?>" <?php checked($current_mode, $value); ?>>
                            <strong style="margin-left: 6px;"><?php echo esc_html($label); ?></strong>
                            <p style="margin: 4px 0 0 24px; color: var(--etk-muted); font-size: 12px;">
                                <?php echo esc_html($help); ?>
                            </p>
                        </label>
                    <?php endforeach; ?>
                    <p style="margin-top: 12px;">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save uninstall behaviour', 'lrob-email-toolkit'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    public function handle_wipe_module(): void
    {
        $this->guard(self::ACTION_WIPE_MODULE);
        $slug = isset($_POST['module']) ? sanitize_key((string) $_POST['module']) : '';
        $confirm = isset($_POST['confirm']) ? (string) wp_unslash($_POST['confirm']) : '';
        $module = $this->manager->get($slug);

        if (!$module instanceof ModuleInterface || $confirm !== $slug) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
            exit;
        }

        $module->uninstall();
        // For non-service modules, also flip the enable state off so the user
        // explicitly re-enables to recreate tables. Service modules stay on.
        if (!$module->is_service_module()) {
            $module->disable();
        }
        // Reset the db_version option so a re-enable triggers a fresh install.
        delete_option($module->db_version_option_key());

        $this->record_last_action(sprintf(
            /* translators: 1: module name, 2: ISO date+time */
            __('Wiped %1$s data on %2$s', 'lrob-email-toolkit'),
            $module->name(),
            current_time('mysql')
        ));

        wp_safe_redirect(add_query_arg(
            'done',
            rawurlencode(sprintf(
                /* translators: %s: module name */
                __('%s data wiped.', 'lrob-email-toolkit'),
                $module->name()
            )),
            admin_url('admin.php?page=' . self::SLUG)
        ));
        exit;
    }

    public function handle_reset_all(): void
    {
        $this->guard(self::ACTION_RESET_ALL);
        $confirm = isset($_POST['confirm']) ? (string) wp_unslash($_POST['confirm']) : '';
        if ($confirm !== 'lrob-email-toolkit') {
            wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
            exit;
        }

        foreach ($this->manager->all() as $module) {
            $module->uninstall();
            delete_option($module->db_version_option_key());
        }

        // Clear module-state + cap (but NOT the uninstall_mode preference —
        // the user's preference for what happens on plugin delete stays).
        delete_option(Activator::OPTION_MODULES);
        delete_option(Activator::OPTION_DB_VERSION);
        $role = get_role('administrator');
        if ($role instanceof \WP_Role && $role->has_cap(Activator::CAPABILITY)) {
            $role->remove_cap(Activator::CAPABILITY);
        }
        // Immediately re-seed cap + module state so the user isn't locked
        // out of the admin and the dashboard works.
        Activator::activate();

        $this->record_last_action(sprintf(
            /* translators: %s: ISO date+time */
            __('Reset entire plugin on %s', 'lrob-email-toolkit'),
            current_time('mysql')
        ));

        wp_safe_redirect(add_query_arg(
            'done',
            rawurlencode(__('Plugin reset to a fresh state.', 'lrob-email-toolkit')),
            admin_url('admin.php?page=' . Menu::SLUG)
        ));
        exit;
    }

    public function handle_save_uninstall_mode(): void
    {
        $this->guard(self::ACTION_SAVE_UNINSTALL_MODE);
        $mode = isset($_POST['mode']) ? sanitize_key((string) $_POST['mode']) : '';
        if (!in_array($mode, ['keep', 'archive', 'wipe'], true)) {
            $mode = Activator::UNINSTALL_MODE_DEFAULT;
        }
        update_option(Activator::OPTION_UNINSTALL_MODE, $mode);

        wp_safe_redirect(add_query_arg(
            'done',
            rawurlencode(__('Uninstall behaviour saved.', 'lrob-email-toolkit')),
            admin_url('admin.php?page=' . self::SLUG)
        ));
        exit;
    }

    private function guard(string $action): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $nonce = isset($_POST['_lrob_etk_nonce']) ? (string) $_POST['_lrob_etk_nonce'] : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }
    }

    private function record_last_action(string $description): void
    {
        update_option('lrob_etk_data_last_action', $description);
    }
}
