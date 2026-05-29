<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Menu as MainMenu;
use LRob\EmailToolkit\Modules\Logging\LogRepository;
use LRob\EmailToolkit\Modules\Logging\RetentionCron;
use LRob\EmailToolkit\Modules\ModuleInterface;

// Docs: docs/logging.md
final class PageController
{
    public const SLUG = 'lrob-etk-logs';

    public const ACTION_SAVE_SETTINGS = 'lrob_etk_logging_save_settings';

    public const ACTION_PURGE = 'lrob_etk_logging_purge_manual';

    public function __construct(
        private ModuleInterface $module,
        private LogRepository $repository,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_submenu'], 21);

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

        (new LogsPage($this->module, $this->repository))->render();
    }

    public function handle_save_settings(): void
    {
        $this->guard(self::ACTION_SAVE_SETTINGS);

        $days = isset($_POST['retention_days']) ? max(0, (int) $_POST['retention_days']) : RetentionCron::DEFAULT_RETENTION_DAYS;
        update_option(RetentionCron::OPTION_RETENTION_DAYS, $days);

        $this->store_flash('notice', __('Logging settings saved.', 'lrob-email-toolkit'));
        $this->redirect_to_list();
    }

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
