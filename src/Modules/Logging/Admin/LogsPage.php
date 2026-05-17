<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging\Admin;

use LRob\EmailToolkit\Admin\ModuleToggle;
use LRob\EmailToolkit\Modules\Logging\LogRepository;
use LRob\EmailToolkit\Modules\Logging\RetentionCron;
use LRob\EmailToolkit\Modules\ModuleInterface;

/**
 * Email logs landing page. States:
 *   1. Disabled + no logs   → CTA card asking the user to enable.
 *   2. Disabled with logs   → toggle bar + frozen list + cleanup tools.
 *   3. Enabled              → toggle bar + list + retention/cleanup tools.
 */
final class LogsPage
{
    public function __construct(
        private ModuleInterface $module,
        private LogRepository $repository,
    ) {
    }

    public function render(): void
    {
        $notice = PageController::pop_flash('notice');
        $errors = PageController::pop_flash('errors');
        $enabled = $this->module->is_enabled();
        $log_count = $this->repository->count();

        ?>
        <div class="wrap lrob-etk">
            <header class="lrob-etk-page-header">
                <h1 class="lrob-etk-page-title"><?php esc_html_e('Email Logs', 'lrob-email-toolkit'); ?></h1>
                <?php ModuleToggle::render_inline($this->module); ?>
            </header>

            <?php $this->render_flash($notice, $errors); ?>
            <?php $this->render_toggle_notice(); ?>

            <?php if (!$enabled && $log_count === 0) : ?>
                <p class="lrob-etk-disabled-message">
                    <?php esc_html_e('Enable email logging to capture every outgoing email and let you audit deliverability, retry failed sends, and (later) archive copies to your IMAP Sent folder.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else : ?>
                <?php $this->render_table(); ?>
                <?php $this->render_settings_section(); ?>
                <?php $this->render_cleanup_section(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_table(): void
    {
        $table = new LogsListTable($this->repository);
        $table->prepare_items();
        ?>
        <form method="get" class="lrob-etk-logs-search">
            <input type="hidden" name="page" value="<?php echo esc_attr(PageController::SLUG); ?>">
            <?php if (isset($_GET['status'])) : ?>
                <input type="hidden" name="status" value="<?php echo esc_attr((string) $_GET['status']); ?>">
            <?php endif; ?>
            <?php $table->views(); ?>
            <?php $table->search_box(__('Search emails', 'lrob-email-toolkit'), 'lrob-etk-logs-search'); ?>
            <?php $table->display(); ?>
        </form>
        <?php
    }

    private function render_settings_section(): void
    {
        $action_url = admin_url('admin-post.php');
        $retention = (int) get_option(RetentionCron::OPTION_RETENTION_DAYS, RetentionCron::DEFAULT_RETENTION_DAYS);
        ?>
        <h2 class="lrob-etk-section-title"><?php esc_html_e('Retention', 'lrob-email-toolkit'); ?></h2>
        <form method="post" action="<?php echo esc_url($action_url); ?>" class="lrob-etk-settings-form">
            <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_SAVE_SETTINGS); ?>">
            <?php wp_nonce_field(PageController::ACTION_SAVE_SETTINGS, '_lrob_etk_nonce'); ?>

            <p>
                <label>
                    <?php esc_html_e('Keep logs for', 'lrob-email-toolkit'); ?>
                    <input type="number" name="retention_days" class="small-text" min="0" max="3650"
                           value="<?php echo (int) $retention; ?>">
                    <?php esc_html_e('days', 'lrob-email-toolkit'); ?>
                </label>
                <span class="description">
                    <?php esc_html_e('0 = keep forever. Older entries are deleted daily by a cron event.', 'lrob-email-toolkit'); ?>
                </span>
            </p>

            <?php submit_button(__('Save', 'lrob-email-toolkit'), 'secondary', 'submit', false); ?>
        </form>
        <?php
    }

    private function render_cleanup_section(): void
    {
        $action_url = admin_url('admin-post.php');
        ?>
        <h2 class="lrob-etk-section-title"><?php esc_html_e('Manual cleanup', 'lrob-email-toolkit'); ?></h2>

        <div class="lrob-etk-cleanup-row">
            <form method="post" action="<?php echo esc_url($action_url); ?>" class="lrob-etk-cleanup-form">
                <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_PURGE); ?>">
                <input type="hidden" name="purge_mode" value="older_than">
                <?php wp_nonce_field(PageController::ACTION_PURGE, '_lrob_etk_nonce'); ?>
                <label>
                    <?php esc_html_e('Delete logs older than', 'lrob-email-toolkit'); ?>
                    <input type="number" name="purge_days" class="small-text" min="1" max="3650" value="30">
                    <?php esc_html_e('days', 'lrob-email-toolkit'); ?>
                </label>
                <button type="submit" class="button button-secondary"
                        onclick="return confirm('<?php echo esc_js(__('Delete log entries older than the specified number of days?', 'lrob-email-toolkit')); ?>');">
                    <?php esc_html_e('Delete', 'lrob-email-toolkit'); ?>
                </button>
            </form>

            <form method="post" action="<?php echo esc_url($action_url); ?>" class="lrob-etk-cleanup-form">
                <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_PURGE); ?>">
                <input type="hidden" name="purge_mode" value="all">
                <?php wp_nonce_field(PageController::ACTION_PURGE, '_lrob_etk_nonce'); ?>
                <button type="submit" class="button button-link-delete"
                        onclick="return confirm('<?php echo esc_js(__('Permanently delete ALL log entries? This cannot be undone.', 'lrob-email-toolkit')); ?>');">
                    <?php esc_html_e('Delete everything', 'lrob-email-toolkit'); ?>
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * @param string|array<int, string>|null $notice
     * @param string|array<int, string>|null $errors
     */
    private function render_flash($notice, $errors): void
    {
        if (is_string($notice) && $notice !== '') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
        }
        if (is_array($errors)) {
            foreach ($errors as $error) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html((string) $error) . '</p></div>';
            }
        }
    }

    private function render_toggle_notice(): void
    {
        if (!isset($_GET['toggled'])) {
            return;
        }
        $toggled = sanitize_key((string) $_GET['toggled']);
        $message = $toggled === 'on'
            ? __('Email logging is now active.', 'lrob-email-toolkit')
            : __('Email logging is now off. Existing logs are preserved.', 'lrob-email-toolkit');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
