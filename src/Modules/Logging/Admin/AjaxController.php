<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Logging\LogRepository;
use LRob\EmailToolkit\Modules\Logging\Resender;

/**
 * admin-ajax endpoints for the Logging module. One shared nonce; JSON
 * responses drive the redesigned logs page.
 */
final class AjaxController
{
    public const NONCE_ACTION = 'lrob_etk_logging_ajax';

    public const ACTION_DELETE      = 'lrob_etk_logging_ajax_delete';

    public const ACTION_BULK_DELETE = 'lrob_etk_logging_ajax_bulk_delete';

    public const ACTION_PURGE       = 'lrob_etk_logging_ajax_purge';

    public const ACTION_RESEND      = 'lrob_etk_logging_ajax_resend';

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
                    /* translators: 1: deleted count, 2: days */
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
                    /* translators: %d: deleted count */
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
            if ($result['attachments_dropped']) {
                $msg .= ' ' . __('Attachments were not re-sent (the original files are no longer available).', 'lrob-email-toolkit');
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
