<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Logging\LogRepository;
use LRob\EmailToolkit\Modules\Logging\Resender;

// Docs: docs/logging.md
final class AjaxController
{
    public const NONCE_ACTION = 'lrob_etk_logging_ajax';

    public const ACTION_DELETE      = 'lrob_etk_logging_ajax_delete';

    public const ACTION_BULK_DELETE = 'lrob_etk_logging_ajax_bulk_delete';

    public const ACTION_PURGE       = 'lrob_etk_logging_ajax_purge';

    public const ACTION_RESEND      = 'lrob_etk_logging_ajax_resend';

    /** Per-key auto-save endpoint backing the Storage modal's card. */
    public const ACTION_SAVE_SETTING = 'lrob_etk_logging_ajax_save_setting';

    public const ACTION_LIST_FILTER   = 'lrob_etk_logging_ajax_list_filter';

    public const ACTION_DETAIL        = 'lrob_etk_logging_ajax_detail';

    public const OPTION_PER_PAGE = 'lrob_etk_logging_per_page';

    public const DEFAULT_PER_PAGE = 20;

    public function __construct(
        private LogRepository $repository,
        private Resender $resender,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_DELETE,      [$this, 'ajax_delete']);
        add_action('wp_ajax_' . self::ACTION_BULK_DELETE, [$this, 'ajax_bulk_delete']);
        add_action('wp_ajax_' . self::ACTION_PURGE,       [$this, 'ajax_purge']);
        add_action('wp_ajax_' . self::ACTION_RESEND,      [$this, 'ajax_resend']);
        add_action('wp_ajax_' . self::ACTION_SAVE_SETTING, [$this, 'ajax_save_setting']);
        add_action('wp_ajax_' . self::ACTION_LIST_FILTER,   [$this, 'ajax_list_filter']);
        add_action('wp_ajax_' . self::ACTION_DETAIL,        [$this, 'ajax_detail']);
    }

    public function ajax_detail(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Missing log id.', 'lrob-email-toolkit')], 400);
        }
        $entry = $this->repository->find($id);
        if ($entry === null) {
            wp_send_json_error(['message' => __('Log entry not found.', 'lrob-email-toolkit')], 404);
        }
        $view = new LogDetailRenderer();
        ob_start();
        $view->render_detail_body($entry);
        $html = (string) ob_get_clean();
        wp_send_json_success([
            'id'     => $id,
            'status' => $entry->status,
            'title'  => $view->detail_title($entry),
            'html'   => $html,
        ]);
    }

    public function ajax_list_filter(): void
    {
        $this->guard();
        $filters = LogsPage::parse_filters($_POST);
        $page = isset($_POST['paged']) ? max(1, (int) $_POST['paged']) : 1;

        $logs_page = new LogsPage(null, $this->repository);
        ob_start();
        $logs_page->render_list_region_for_filters($filters, $page);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    public function ajax_save_setting(): void
    {
        $this->guard();
        $key = isset($_POST['key']) ? sanitize_key((string) $_POST['key']) : '';
        $raw = $_POST['value'] ?? '';

        switch ($key) {
            case 'retention_days':
                $days = max(0, min(3650, (int) $raw));
                update_option(\LRob\EmailToolkit\Modules\Logging\RetentionCron::OPTION_RETENTION_DAYS, $days);
                wp_send_json_success(['key' => $key, 'stored' => $days]);

            default:
                wp_send_json_error(['message' => __('Unknown setting.', 'lrob-email-toolkit')], 400);
        }
    }

    public function ajax_delete(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid log entry.', 'lrob-email-toolkit')]);
        }
        $this->repository->delete($id);
        wp_send_json_success(['message' => __('Log entry deleted.', 'lrob-email-toolkit')]);
    }

    public function ajax_bulk_delete(): void
    {
        $this->guard();
        $ids = isset($_POST['ids']) && is_array($_POST['ids'])
            ? array_map('intval', wp_unslash($_POST['ids']))
            : [];
        $deleted = $this->repository->bulk_delete($ids);
        wp_send_json_success([
            'deleted' => $deleted,
            'message' => sprintf(
                /* translators: %d: number of entries deleted */
                _n('Deleted %d entry.', 'Deleted %d entries.', $deleted, 'lrob-email-toolkit'),
                $deleted
            ),
        ]);
    }

    public function ajax_purge(): void
    {
        $this->guard();
        $mode = isset($_POST['mode']) ? sanitize_key((string) $_POST['mode']) : '';
        if ($mode === 'older_than') {
            $days = isset($_POST['days']) ? max(0, (int) $_POST['days']) : 0;
            if ($days <= 0) {
                wp_send_json_error(['message' => __('Enter a positive number of days.', 'lrob-email-toolkit')]);
            }
            $cutoff = new \DateTimeImmutable('-' . $days . ' days', new \DateTimeZone('UTC'));
            $deleted = $this->repository->delete_older_than($cutoff);
            wp_send_json_success([
                'deleted' => $deleted,
                'message' => sprintf(
                    /* translators: 1: number of entries deleted, 2: number of days */
                    _n(
                        'Deleted %1$d entry older than %2$d days.',
                        'Deleted %1$d entries older than %2$d days.',
                        $deleted,
                        'lrob-email-toolkit'
                    ),
                    $deleted,
                    $days
                ),
            ]);
        }
        if ($mode === 'all') {
            $cutoff = new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC'));
            $deleted = $this->repository->delete_older_than($cutoff);
            wp_send_json_success([
                'deleted' => $deleted,
                'message' => sprintf(
                    /* translators: %d: number of entries deleted */
                    _n('Deleted all %d log entry.', 'Deleted all %d log entries.', $deleted, 'lrob-email-toolkit'),
                    $deleted
                ),
            ]);
        }
        wp_send_json_error(['message' => __('Unknown purge mode.', 'lrob-email-toolkit')]);
    }

    public function ajax_resend(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid log entry.', 'lrob-email-toolkit')]);
        }
        $result = $this->resender->resend($id);
        if ($result['success']) {
            $msg = __('Email re-sent successfully.', 'lrob-email-toolkit');
            $sent = (int) ($result['attachments_sent'] ?? 0);
            $total = (int) ($result['attachments_total'] ?? 0);
            if ($total > 0) {
                $msg .= ' ' . sprintf(
                    /* translators: 1: number re-attached, 2: total at send time */
                    __('Attachments: %1$d of %2$d re-attached (others no longer on disk).', 'lrob-email-toolkit'),
                    $sent,
                    $total
                );
            }
            wp_send_json_success(['message' => $msg]);
        }
        wp_send_json_error([
            'message' => $result['error'] ?? __('Resend failed.', 'lrob-email-toolkit'),
        ]);
    }

    private function guard(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'lrob-email-toolkit')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, '_nonce');
    }
}
