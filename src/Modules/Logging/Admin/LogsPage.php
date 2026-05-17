<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging\Admin;

use LRob\EmailToolkit\Modules\Logging\LogRepository;
use LRob\EmailToolkit\Modules\Logging\RetentionCron;

/**
 * List page: WP_List_Table of log entries + a small retention-settings form.
 */
final class LogsPage
{
    public function __construct(private LogRepository $repository)
    {
    }

    public function render(): void
    {
        $table = new LogsListTable($this->repository);
        $table->prepare_items();

        $notice = PageController::pop_flash('notice');
        $errors = PageController::pop_flash('errors');

        $action_url = admin_url('admin-post.php');
        $retention = (int) get_option(RetentionCron::OPTION_RETENTION_DAYS, RetentionCron::DEFAULT_RETENTION_DAYS);
        ?>
        <div class="wrap lrob-etk">
            <h1><?php esc_html_e('Email Toolkit — Logs', 'lrob-email-toolkit'); ?></h1>

            <?php $this->render_flash($notice, $errors); ?>

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(PageController::SLUG); ?>">
                <?php if (isset($_GET['status'])) : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr((string) $_GET['status']); ?>">
                <?php endif; ?>
                <?php $table->views(); ?>
                <?php $table->search_box(__('Search emails', 'lrob-email-toolkit'), 'lrob-etk-logs-search'); ?>
                <?php $table->display(); ?>
            </form>

            <h2 class="title"><?php esc_html_e('Retention', 'lrob-email-toolkit'); ?></h2>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_SAVE_SETTINGS); ?>">
                <?php wp_nonce_field(PageController::ACTION_SAVE_SETTINGS, '_lrob_etk_nonce'); ?>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="lrob-etk-retention"><?php esc_html_e('Keep logs for', 'lrob-email-toolkit'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="lrob-etk-retention" name="retention_days" class="small-text"
                                       min="0" max="3650" value="<?php echo (int) $retention; ?>">
                                <?php esc_html_e('days', 'lrob-email-toolkit'); ?>
                                <p class="description">
                                    <?php esc_html_e('0 = keep forever. Older entries are deleted daily by a cron event.', 'lrob-email-toolkit'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save', 'lrob-email-toolkit')); ?>
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
}
