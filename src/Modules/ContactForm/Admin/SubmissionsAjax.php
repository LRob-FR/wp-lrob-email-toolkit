<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\ContactForm\FileRepository;
use LRob\EmailToolkit\Modules\ContactForm\SubmissionRepository;

/**
 * AJAX endpoints for the Submissions inbox:
 *
 *  - `lrob_etk_cf_submissions_filter` — re-renders the swap-able list region
 *    (summary + table + pagination, or empty state) for the current filter
 *    set. Lets the inbox page update without a full reload.
 *  - `lrob_etk_cf_submissions_bulk` — performs spam or delete on a list of
 *    submission IDs. Delete cascades to attached files via FileRepository.
 *
 * Both share `lrob_etk_cf_submissions_ajax` as the nonce action and require
 * `manage_lrob_etk`. Heavy operations are wrapped in a transaction-like
 * pattern (best-effort: files deleted before rows so a partial failure
 * leaves orphan rows rather than orphan files).
 */
final class SubmissionsAjax
{
    public const ACTION_FILTER   = 'lrob_etk_cf_submissions_filter';
    public const ACTION_BULK     = 'lrob_etk_cf_submissions_bulk';
    public const ACTION_DETAIL   = 'lrob_etk_cf_submissions_detail';
    public const ACTION_PURGE    = 'lrob_etk_cf_submissions_purge';
    public const NONCE_ACTION    = 'lrob_etk_cf_submissions_ajax';

    public function __construct(
        private SubmissionsPage $page,
        private SubmissionRepository $submissions,
        private ?FileRepository $files = null,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_FILTER,   [$this, 'handle_filter']);
        add_action('wp_ajax_' . self::ACTION_BULK,     [$this, 'handle_bulk']);
        add_action('wp_ajax_' . self::ACTION_DETAIL,   [$this, 'handle_detail']);
        add_action('wp_ajax_' . self::ACTION_PURGE,    [$this, 'handle_purge']);
    }

    /**
     * One-shot manual cleanup. POST params:
     *   - days (int, >= 1)
     *   - statuses (array of slugs: 'received', 'delivered', 'failed', 'spam_blocked')
     *
     * Deletes all submissions in the chosen statuses older than $days,
     * cascading file deletion as the retention cron does. Returns the
     * total count purged.
     */
    public function handle_purge(): void
    {
        $this->guard();
        $days = isset($_POST['days']) ? (int) $_POST['days'] : 0;
        if ($days < 1) {
            wp_send_json_error(['message' => __('Day count must be at least 1.', 'lrob-email-toolkit')], 400);
        }
        $statuses = isset($_POST['statuses']) ? (array) $_POST['statuses'] : [];
        $allowed = [
            SubmissionRepository::STATUS_RECEIVED,
            SubmissionRepository::STATUS_DELIVERED,
            SubmissionRepository::STATUS_FAILED,
            SubmissionRepository::STATUS_SPAM_BLOCKED,
        ];
        $statuses = array_values(array_intersect($statuses, $allowed));
        if ($statuses === []) {
            wp_send_json_error(['message' => __('Select at least one status to purge.', 'lrob-email-toolkit')], 400);
        }

        $total = 0;
        try {
            $cutoff = new \DateTimeImmutable('-' . $days . ' days', new \DateTimeZone('UTC'));
        } catch (\Exception) {
            wp_send_json_error(['message' => __('Invalid age.', 'lrob-email-toolkit')], 400);
        }
        foreach ($statuses as $status) {
            $ids = $this->submissions->list_ids_by_status_older_than($status, $cutoff);
            if ($this->files !== null) {
                foreach ($ids as $id) {
                    $this->files->delete_by_submission($id);
                }
            }
            $total += $this->submissions->delete_by_status_older_than($status, $cutoff);
        }

        wp_send_json_success([
            'count'   => $total,
            'message' => sprintf(
                /* translators: %d: number of submissions purged */
                _n('%d submission purged.', '%d submissions purged.', $total, 'lrob-email-toolkit'),
                $total
            ),
        ]);
    }

    /**
     * Render the body markup for a single submission, used by the
     * in-page detail modal (prev/next navigation). Returns
     *   { id, status, title, html, prev_label, next_label }
     * — the surrounding modal chrome is JS-built; we just supply the
     * inner content + a few hints for the header strip.
     */
    public function handle_detail(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Missing submission id.', 'lrob-email-toolkit')], 400);
        }
        $entry = $this->submissions->find($id);
        if ($entry === null) {
            wp_send_json_error(['message' => __('Submission not found.', 'lrob-email-toolkit')], 404);
        }
        ob_start();
        $this->page->render_detail_body($entry);
        $html = (string) ob_get_clean();
        wp_send_json_success([
            'id'     => $id,
            'status' => $entry->status,
            'title'  => $this->page->detail_title($entry),
            'html'   => $html,
        ]);
    }

    public function handle_filter(): void
    {
        $this->guard();
        $filters = SubmissionsPage::parse_filters($_POST);
        $page = isset($_POST['paged']) ? max(1, (int) $_POST['paged']) : 1;

        ob_start();
        $this->page->render_list_region_for_filters($filters, $page);
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    public function handle_bulk(): void
    {
        $this->guard();
        $op = isset($_POST['op']) ? sanitize_key((string) $_POST['op']) : '';
        $raw_ids = isset($_POST['ids']) ? (array) $_POST['ids'] : [];
        $ids = [];
        foreach ($raw_ids as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $ids[] = $i;
            }
        }
        if ($ids === []) {
            wp_send_json_error(['message' => __('No submissions selected.', 'lrob-email-toolkit')], 400);
        }
        if (!in_array($op, ['spam', 'unspam', 'delete'], true)) {
            wp_send_json_error(['message' => __('Unknown bulk action.', 'lrob-email-toolkit')], 400);
        }

        $count = 0;
        if ($op === 'spam') {
            foreach ($ids as $id) {
                if ($this->submissions->flag_as_spam($id)) {
                    $count++;
                }
            }
        } elseif ($op === 'unspam') {
            foreach ($ids as $id) {
                if ($this->submissions->restore_from_spam($id, 'manual_unspam_from_bulk')) {
                    $count++;
                }
            }
        } else {
            // delete — files first so a partial failure leaves orphan rows
            // rather than orphan disk files (which would accumulate).
            foreach ($ids as $id) {
                if ($this->files !== null) {
                    $this->files->delete_by_submission($id);
                }
                $this->submissions->delete_by_id($id);
                $count++;
            }
        }

        $message = match ($op) {
            'spam' => sprintf(
                /* translators: %d: number of submissions marked as spam */
                _n('%d submission marked as spam.', '%d submissions marked as spam.', $count, 'lrob-email-toolkit'),
                $count
            ),
            'unspam' => sprintf(
                /* translators: %d: number of submissions restored from spam */
                _n('%d submission restored.', '%d submissions restored.', $count, 'lrob-email-toolkit'),
                $count
            ),
            default => sprintf(
                /* translators: %d: number of submissions deleted */
                _n('%d submission deleted.', '%d submissions deleted.', $count, 'lrob-email-toolkit'),
                $count
            ),
        };

        wp_send_json_success([
            'op'      => $op,
            'count'   => $count,
            'message' => $message,
        ]);
    }

    private function guard(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'lrob-email-toolkit')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, '_ajax_nonce');
    }
}
