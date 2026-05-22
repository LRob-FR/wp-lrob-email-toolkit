<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Companion-table operations for the `lrob_etk_newsletter` CPT. The
 * row is keyed by post_id and carries hot runtime state (status,
 * send-loop counters, started_at/last_tick_at) so the post_meta hot
 * path stays cool during sending.
 *
 * Status enum: draft | scheduled | sending | paused | sent | failed |
 * aborted. The composer (step 6) only writes draft and scheduled;
 * the send pipeline (step 7) drives the rest.
 *
 * ensure_row() is called on every save_post for a newsletter CPT — it
 * INSERT IGNOREs the row, so re-saves are cheap and crash-safe.
 */
final class NewsletterRepository
{
    public const STATUS_DRAFT    = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING   = 'sending';

    public const STATUS_PAUSED    = 'paused';

    public const STATUS_SENT      = 'sent';

    public const STATUS_FAILED    = 'failed';

    public const STATUS_ABORTED   = 'aborted';

    /**
     * INSERT IGNORE a draft row for the given post_id. Safe to call
     * on every save — the UNIQUE PRIMARY KEY on post_id makes the
     * second call a no-op.
     */
    public function ensure_row(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }
        global $wpdb;
        $table = Schema::newsletters_table();
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `$table` (post_id, status) VALUES (%d, %s)",
            $post_id,
            self::STATUS_DRAFT
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_by_post_id(int $post_id): ?array
    {
        if ($post_id <= 0) {
            return null;
        }
        global $wpdb;
        $table = Schema::newsletters_table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `$table` WHERE post_id = %d LIMIT 1", $post_id),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public function update_status(int $post_id, string $status): void
    {
        if ($post_id <= 0) {
            return;
        }
        global $wpdb;
        $wpdb->update(
            Schema::newsletters_table(),
            ['status' => $status],
            ['post_id' => $post_id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Drop the companion row + any per-recipient state when a
     * newsletter post is deleted (called from before_delete_post).
     * The `newsletter_recipients` rows would be orphaned without
     * this cleanup since they share no FK with wp_posts.
     */
    public function delete_for_post(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }
        global $wpdb;
        $wpdb->delete(Schema::newsletters_table(), ['post_id' => $post_id], ['%d']);
        $wpdb->delete(Schema::newsletter_recipients_table(), ['newsletter_id' => $post_id], ['%d']);
    }

    /**
     * Join wp_posts + the companion table so the admin view gets
     * post fields (title, post_status, post_date) and runtime state
     * (status, counters) in one query. Ordered newest-first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_all(int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $cpt = NewsletterCPT::POST_TYPE;
        $newsletters_table = Schema::newsletters_table();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS post_id,
                    p.post_title,
                    p.post_status AS wp_status,
                    p.post_date_gmt,
                    p.post_modified_gmt,
                    c.status,
                    c.total_recipients,
                    c.sent_count,
                    c.failed_count,
                    c.skipped_count,
                    c.started_at,
                    c.completed_at,
                    c.last_tick_at
               FROM {$wpdb->posts} p
               LEFT JOIN `$newsletters_table` c ON c.post_id = p.ID
              WHERE p.post_type = %s
                AND p.post_status NOT IN ('auto-draft','trash')
              ORDER BY p.post_modified_gmt DESC
              LIMIT %d OFFSET %d",
            $cpt,
            $limit,
            $offset
        ), ARRAY_A);
    }

    public function count_all(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
              WHERE post_type = %s
                AND post_status NOT IN ('auto-draft','trash')",
            NewsletterCPT::POST_TYPE
        ));
    }
}
