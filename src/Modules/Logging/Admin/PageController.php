<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Menu as MainMenu;
use LRob\EmailToolkit\Modules\Logging\LogRepository;
use LRob\EmailToolkit\Modules\Logging\Resender;
use LRob\EmailToolkit\Modules\Logging\RetentionCron;
use LRob\EmailToolkit\Modules\ModuleInterface;

/**
 * Logging admin submenu. Routes between the list view and per-entry detail
 * view based on the `action` query arg; POST actions go through admin-post.php.
 */
final class PageController
{
    public const SLUG = 'lrob-etk-logs';

    public const ACTION_RESEND = 'lrob_etk_logging_resend';

    public const ACTION_DELETE = 'lrob_etk_logging_delete';

    public const ACTION_SAVE_SETTINGS = 'lrob_etk_logging_save_settings';

    public const ACTION_PURGE = 'lrob_etk_logging_purge_manual';

    public function __construct(
        private ModuleInterface $module,
        private LogRepository $repository,
        private Resender $resender,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_submenu'], 21);

        add_action('admin_post_' . self::ACTION_RESEND,        [$this, 'handle_resend']);
        add_action('admin_post_' . self::ACTION_DELETE,        [$this, 'handle_delete']);
        add_action('admin_post_' . self::ACTION_SAVE_SETTINGS, [$this, 'handle_save_settings']);
        add_action('admin_post_' . self::ACTION_PURGE,         [$this, 'handle_purge']);
    }

    public function add_submenu(): void
    {
        add_submenu_page(
            MainMenu::SLUG,
            __('Email Logs', 'lrob-email-toolkit'),
            __('Email Logs', 'lrob-email-toolkit'),
            Activator::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }

        $action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';
        if ($action === 'view') {
            $id = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
            $entry = $id > 0 ? $this->repository->find($id) : null;
            (new LogViewPage())->render($entry);
            return;
        }

        (new LogsPage($this->module, $this->repository))->render();
    }

    public function handle_resend(): void
    {
        $this->guard(self::ACTION_RESEND);

        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        if ($id <= 0) {
            $this->redirect_to_list();
        }

        $result = $this->resender->resend($id);
        if ($result['success']) {
            $msg = __('Email re-sent successfully.', 'lrob-email-toolkit');
            if ($result['attachments_dropped']) {
                $msg .= ' ' . __('Attachments were not re-sent (the original files are no longer available).', 'lrob-email-toolkit');
            }
            $this->store_flash('notice', $msg);
        } else {
            $error = $result['error'] ?? __('Unknown error.', 'lrob-email-toolkit');
            $this->store_flash('errors', [sprintf(
                /* translators: %s: error message */
                __('Resend failed: %s', 'lrob-email-toolkit'),
                $error
            )]);
        }

        $this->redirect_to_view($id);
    }

    public function handle_delete(): void
    {
        $this->guard(self::ACTION_DELETE);

        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        if ($id > 0) {
            $this->repository->delete($id);
            $this->store_flash('notice', __('Log entry deleted.', 'lrob-email-toolkit'));
        }
        $this->redirect_to_list();
    }

    public function handle_save_settings(): void
    {
        $this->guard(self::ACTION_SAVE_SETTINGS);

        $days = isset($_POST['retention_days']) ? max(0, (int) $_POST['retention_days']) : RetentionCron::DEFAULT_RETENTION_DAYS;
        update_option(RetentionCron::OPTION_RETENTION_DAYS, $days);

        $this->store_flash('notice', __('Logging settings saved.', 'lrob-email-toolkit'));
        $this->redirect_to_list();
    }

    /**
     * Manual cleanup: "delete older than X days" or "delete all". Posted from
     * the cleanup section at the bottom of the logs page.
     */
    public function handle_purge(): void
    {
        $this->guard(self::ACTION_PURGE);

        $mode = isset($_POST['purge_mode']) ? sanitize_key((string) $_POST['purge_mode']) : '';

        if ($mode === 'older_than') {
            $days = isset($_POST['purge_days']) ? max(0, (int) $_POST['purge_days']) : 0;
            if ($days <= 0) {
                $this->store_flash('errors', [__('Enter a positive number of days.', 'lrob-email-toolkit')]);
                $this->redirect_to_list();
            }
            $cutoff = new \DateTimeImmutable('-' . $days . ' days', new \DateTimeZone('UTC'));
            $deleted = $this->repository->delete_older_than($cutoff);
            $this->store_flash('notice', sprintf(
                /* translators: 1: number of entries deleted, 2: number of days */
                _n(
                    'Deleted %1$d entry older than %2$d days.',
                    'Deleted %1$d entries older than %2$d days.',
                    $deleted,
                    'lrob-email-toolkit'
                ),
                $deleted,
                $days
            ));
        } elseif ($mode === 'all') {
            $cutoff = new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC'));
            $deleted = $this->repository->delete_older_than($cutoff);
            $this->store_flash('notice', sprintf(
                /* translators: %d: number of entries deleted */
                _n('Deleted all %d log entry.', 'Deleted all %d log entries.', $deleted, 'lrob-email-toolkit'),
                $deleted
            ));
        } else {
            $this->store_flash('errors', [__('Unknown purge mode.', 'lrob-email-toolkit')]);
        }

        $this->redirect_to_list();
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

    private function redirect_to_list(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    private function redirect_to_view(int $id): void
    {
        $url = add_query_arg(
            ['page' => self::SLUG, 'action' => 'view', 'id' => $id],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    /** @param string|array<int, string> $value */
    private function store_flash(string $key, $value): void
    {
        $user_id = get_current_user_id();
        set_transient('lrob_etk_logging_flash_' . $key . '_' . $user_id, $value, 60);
    }

    /** @return string|array<int, string>|null */
    public static function pop_flash(string $key)
    {
        $user_id = get_current_user_id();
        $transient_key = 'lrob_etk_logging_flash_' . $key . '_' . $user_id;
        $value = get_transient($transient_key);
        if ($value !== false) {
            delete_transient($transient_key);
            return $value;
        }
        return null;
    }
}
