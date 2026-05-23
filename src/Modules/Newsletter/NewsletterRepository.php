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
     * Atomically set status + pause_reason. Used by the SMTP circuit-breaker
     * to record *why* a send was paused, so the card UI can surface the
     * right banner ("we paused because SMTP went bad" vs admin-pause).
     * Pass `null` for $reason to clear the field (e.g. on resume).
     */
    public function update_status_with_reason(int $post_id, string $status, ?string $reason): void
    {
        if ($post_id <= 0) {
            return;
        }
        global $wpdb;
        $wpdb->update(
            Schema::newsletters_table(),
            ['status' => $status, 'pause_reason' => $reason],
            ['post_id' => $post_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Flip every `failed` recipient row back to `pending`, clear its
     * failure_code, and decrement the companion's failed_count by the
     * number of rows affected. If the companion was already in a terminal
     * status (sent / failed) because the previous pass completed with
     * failures, the caller can flip it back to sending separately.
     *
     * Returns the count of rows that were re-queued.
     */
    public function retry_failed_recipients(int $post_id): int
    {
        if ($post_id <= 0) {
            return 0;
        }
        global $wpdb;
        $recipients = Schema::newsletter_recipients_table();
        $newsletters = Schema::newsletters_table();

        $affected = (int) $wpdb->query($wpdb->prepare(
            "UPDATE `$recipients`
                SET status = 'pending', failure_code = ''
              WHERE newsletter_id = %d AND status = 'failed'",
            $post_id
        ));
        if ($affected <= 0) {
            return 0;
        }
        // Counter bump uses GREATEST so a stale failed_count never
        // underflows into negative territory (varies by site, recovery
        // imports, etc.).
        $wpdb->query($wpdb->prepare(
            "UPDATE `$newsletters`
                SET failed_count = GREATEST(0, CAST(failed_count AS SIGNED) - %d)
              WHERE post_id = %d",
            $affected,
            $post_id
        ));
        return $affected;
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

    /** Statuses considered "in preparation" (still editable, not yet sent). */
    private const TAB_IN_PREP_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
    ];

    /** Statuses considered "sent or sending" (locked, send pipeline owns them). */
    private const TAB_SENT_STATUSES = [
        self::STATUS_SENDING,
        self::STATUS_PAUSED,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_ABORTED,
    ];

    public const TAB_IN_PREP = 'in_prep';

    public const TAB_SENT    = 'sent';

    public const TAB_TRASH   = 'trash';

    /**
     * Build the WHERE clause that selects rows belonging to a given
     * tab. Returns just the AND-suffix (no `WHERE`, no leading AND).
     * The tab value is validated by the caller — this helper trusts
     * its input and string-concatenates the IN list.
     */
    private static function where_for_tab(string $tab): string
    {
        if ($tab === self::TAB_TRASH) {
            return "p.post_status = 'trash'";
        }
        $statuses = $tab === self::TAB_SENT ? self::TAB_SENT_STATUSES : self::TAB_IN_PREP_STATUSES;
        $quoted = array_map(static fn (string $s): string => "'" . esc_sql($s) . "'", $statuses);
        return "p.post_status NOT IN ('auto-draft','trash')"
             . " AND COALESCE(c.status, 'draft') IN (" . implode(',', $quoted) . ")";
    }

    /**
     * Join wp_posts + the companion table so the admin view gets
     * post fields (title, post_status, post_date) and runtime state
     * (status, counters) in one query. Ordered newest-first (creation
     * date desc) so freshly created newsletters land at the top.
     *
     * The $tab argument segments the list into "in preparation"
     * (draft / scheduled), "sent" (sending / paused / sent / failed /
     * aborted), and "trash" (post_status='trash'). Unknown values
     * fall back to in_prep.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_all(int $limit = 50, int $offset = 0, string $tab = self::TAB_IN_PREP): array
    {
        global $wpdb;
        if (!in_array($tab, [self::TAB_IN_PREP, self::TAB_SENT, self::TAB_TRASH], true)) {
            $tab = self::TAB_IN_PREP;
        }
        $cpt = NewsletterCPT::POST_TYPE;
        $newsletters_table = Schema::newsletters_table();
        $where_tab = self::where_for_tab($tab);
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS post_id,
                    p.post_title,
                    p.post_status AS wp_status,
                    p.post_date_gmt,
                    p.post_modified_gmt,
                    c.status,
                    c.pause_reason,
                    c.total_recipients,
                    c.sent_count,
                    c.failed_count,
                    c.skipped_count,
                    c.opens_count,
                    c.opens_unique,
                    c.clicks_count,
                    c.clicks_unique,
                    c.started_at,
                    c.completed_at,
                    c.last_tick_at
               FROM {$wpdb->posts} p
               LEFT JOIN `$newsletters_table` c ON c.post_id = p.ID
              WHERE p.post_type = %s
                AND $where_tab
              ORDER BY p.post_date_gmt DESC
              LIMIT %d OFFSET %d",
            $cpt,
            $limit,
            $offset
        ), ARRAY_A);
    }

    /**
     * Return the row count per tab in one query — feeds the tab nav
     * badges. Cheap: post_type index + post_status index are both used.
     *
     * @return array{in_prep:int, sent:int, trash:int}
     */
    public function counts_by_tab(): array
    {
        global $wpdb;
        $cpt = NewsletterCPT::POST_TYPE;
        $newsletters_table = Schema::newsletters_table();
        $out = ['in_prep' => 0, 'sent' => 0, 'trash' => 0];
        foreach ([self::TAB_IN_PREP, self::TAB_SENT, self::TAB_TRASH] as $tab) {
            $where_tab = self::where_for_tab($tab);
            $out[$tab] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                   LEFT JOIN `$newsletters_table` c ON c.post_id = p.ID
                  WHERE p.post_type = %s
                    AND $where_tab",
                $cpt
            ));
        }
        return $out;
    }

    /** Recognised per-recipient row statuses (defends against arbitrary filter input). */
    private const RECIPIENT_STATUSES = ['pending', 'sent', 'failed', 'skipped'];

    /**
     * Frozen recipient snapshot for a sent (or in-progress) newsletter.
     * Reads `newsletter_recipients` — the rows materialised at send
     * time — so the admin sees the actual list as it was when the
     * send started, not a fresh dry-run.
     *
     * Supports pagination + filtering by status + email substring
     * search so the modal can drill into thousands of rows. The
     * `by_status` map stays *unfiltered* so the filter-tab badges
     * always show the true distribution; `filtered_total` reflects
     * the current filter+search and is what the pagination footer
     * counts against.
     *
     * Empty result (`total === 0`) = no materialisation yet — caller
     * should fall back to a dry-run preview.
     *
     * The `row_id` per sample is `newsletter_recipients.id` — the per-send
     * row id, which is what `X-Lrob-Etk-Newsletter-Recipient-ID` carries and
     * what the Logging module persists into `logs.recipient_id`. Callers
     * pass that id to `LogRepository::log_ids_for_newsletter_recipients()`
     * to attach a "View in Logs" link to failed rows.
     *
     * @return array{
     *   total:int,
     *   filtered_total:int,
     *   by_status:array<string,int>,
     *   sample:array<int, array{row_id:int, kind:string, email:string, name:string, status:string, failure_code:string, sent_at:string}>,
     *   limit:int,
     *   offset:int
     * }
     */
    public function recipients_snapshot(
        int $post_id,
        int $limit = 50,
        int $offset = 0,
        string $status_filter = '',
        string $email_search = ''
    ): array {
        global $wpdb;
        $table = Schema::newsletter_recipients_table();
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $status_filter = in_array($status_filter, self::RECIPIENT_STATUSES, true) ? $status_filter : '';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE newsletter_id = %d",
            $post_id
        ));
        if ($total === 0) {
            return [
                'total'          => 0,
                'filtered_total' => 0,
                'by_status'      => [],
                'sample'         => [],
                'limit'          => $limit,
                'offset'         => 0,
            ];
        }

        // Unfiltered per-status counts feed the filter-tab badges.
        $status_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS c FROM `$table`
              WHERE newsletter_id = %d
              GROUP BY status",
            $post_id
        ), ARRAY_A);
        $by_status = [];
        foreach ($status_rows as $sr) {
            $by_status[(string) $sr['status']] = (int) $sr['c'];
        }

        // Build the filter clause + bound params for the page query.
        // Hand-built LIKE escaping: we use $wpdb->esc_like + %s, so the
        // search string is both escaping- and prepare-safe.
        $where = '`newsletter_id` = %d';
        $params = [$post_id];
        if ($status_filter !== '') {
            $where .= ' AND `status` = %s';
            $params[] = $status_filter;
        }
        $email_search = trim($email_search);
        if ($email_search !== '') {
            $where .= ' AND (`email_snapshot` LIKE %s OR `name_snapshot` LIKE %s)';
            $like = '%' . $wpdb->esc_like($email_search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $filtered_total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE $where",
            ...$params
        ));

        $page_params = array_merge($params, [$limit, $offset]);
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, recipient_kind, email_snapshot, name_snapshot, status, failure_code, sent_at
               FROM `$table`
              WHERE $where
              ORDER BY id ASC
              LIMIT %d OFFSET %d",
            ...$page_params
        ), ARRAY_A);

        $sample = [];
        foreach ($rows as $r) {
            $sample[] = [
                'row_id'       => (int) ($r['id'] ?? 0),
                'kind'         => (string) ($r['recipient_kind'] ?? ''),
                'email'        => (string) ($r['email_snapshot'] ?? ''),
                'name'         => (string) ($r['name_snapshot'] ?? ''),
                'status'       => (string) ($r['status'] ?? ''),
                'failure_code' => (string) ($r['failure_code'] ?? ''),
                'sent_at'      => (string) ($r['sent_at'] ?? ''),
            ];
        }
        return [
            'total'          => $total,
            'filtered_total' => $filtered_total,
            'by_status'      => $by_status,
            'sample'         => $sample,
            'limit'          => $limit,
            'offset'         => $offset,
        ];
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
