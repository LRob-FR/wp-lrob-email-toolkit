<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging;

/**
 * CRUD against lrob_etk_logs. Handles JSON encoding/decoding for the
 * array-valued columns (to_emails, cc_emails, bcc_emails, headers, attachments)
 * so the rest of the codebase only ever deals in PHP arrays.
 */
final class LogRepository
{
    public function insert(LogEntry $entry): int
    {
        global $wpdb;

        $data = $this->row_data($entry);
        $data['created_at'] = $entry->created_at->format('Y-m-d H:i:s');
        $data['sent_at'] = $entry->sent_at?->format('Y-m-d H:i:s');

        $formats = $this->insert_formats();
        $wpdb->insert(Schema::table_name(), $data, $formats);

        return (int) $wpdb->insert_id;
    }

    /** Update only the status fields (and sent_at when transitioning to 'sent'). */
    public function update_status(int $id, string $status, ?string $error = null): void
    {
        global $wpdb;

        $data = ['status' => $status];
        $formats = ['%s'];

        if ($status === LogEntry::STATUS_SENT) {
            $data['sent_at'] = current_time('mysql', true);
            $formats[] = '%s';
        }

        if ($error !== null) {
            $data['error_message'] = $error;
            $formats[] = '%s';
        }

        $wpdb->update(Schema::table_name(), $data, ['id' => $id], $formats, ['%d']);
    }

    public function find(int $id): ?LogEntry
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `" . Schema::table_name() . "` WHERE id = %d", $id),
            ARRAY_A
        );
        return is_array($row) ? LogEntry::from_row($row) : null;
    }

    /**
     * @param array{
     *     status?: string,
     *     source?: string,
     *     search?: string,
     *     date_from?: string,
     *     date_to?: string,
     *     newsletter_id?: int,
     *     newsletter_mode?: string
     * } $filters
     */
    public function count(array $filters = []): int
    {
        global $wpdb;
        [$where, $params] = $this->build_where($filters);
        $sql = "SELECT COUNT(*) FROM `" . Schema::table_name() . "` $where";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) ($params === [] ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, ...$params)));
    }

    /**
     * @param array{status?: string, source?: string, search?: string, date_from?: string, date_to?: string, newsletter_id?: int, newsletter_mode?: string} $filters
     * @return array<int, LogEntry>
     */
    public function paginate(array $filters, int $page, int $per_page): array
    {
        global $wpdb;
        $page = max(1, $page);
        $per_page = max(1, min(200, $per_page));
        $offset = ($page - 1) * $per_page;

        [$where, $params] = $this->build_where($filters);
        $orderby = self::sanitize_orderby((string) ($filters['orderby'] ?? ''));
        $order = (string) ($filters['order'] ?? '') === 'asc' ? 'ASC' : 'DESC';
        // `orderby` came through `sanitize_orderby` which only emits whitelisted column names —
        // safe to interpolate. `order` is constrained to ASC/DESC above.
        $sql = "SELECT * FROM `" . Schema::table_name() . "` $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        return array_map(static fn (array $r): LogEntry => LogEntry::from_row($r), $rows);
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $this->purge_attachments_for_ids([$id]);
        $wpdb->delete(Schema::table_name(), ['id' => $id], ['%d']);
    }

    /**
     * Bulk delete by IDs. Filters non-positive ints, returns rows actually
     * deleted. Used by the bulk-action toolbar.
     *
     * @param array<int, int> $ids
     */
    public function bulk_delete(array $ids): int
    {
        global $wpdb;
        $clean = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($clean === []) {
            return 0;
        }
        $this->purge_attachments_for_ids($clean);
        $placeholders = implode(',', array_fill(0, count($clean), '%d'));
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM `' . Schema::table_name() . "` WHERE id IN ($placeholders)",
                ...$clean
            )
        );
        return is_int($deleted) ? $deleted : 0;
    }

    /** Returns rows deleted. */
    public function delete_older_than(\DateTimeImmutable $cutoff): int
    {
        global $wpdb;
        $table = Schema::table_name();
        $cutoff_str = $cutoff->format('Y-m-d H:i:s');

        // Remove any locally-saved attachment copies for the rows about to go.
        $blobs = $wpdb->get_col($wpdb->prepare(
            "SELECT attachments FROM `$table` WHERE created_at < %s AND attachments IS NOT NULL",
            $cutoff_str
        ));
        foreach ($blobs as $blob) {
            $this->purge_attachment_files((string) $blob);
        }

        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM `$table` WHERE created_at < %s", $cutoff_str)
        );
        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Delete locally-saved attachment copies (AttachmentStore-managed files)
     * for the given log ids, before the rows themselves are removed. Files
     * not under our store (e.g. transient wp_mail paths) are left untouched.
     *
     * @param array<int, int> $ids
     */
    private function purge_attachments_for_ids(array $ids): void
    {
        global $wpdb;
        $clean = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($clean === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($clean), '%d'));
        $blobs = $wpdb->get_col($wpdb->prepare(
            'SELECT attachments FROM `' . Schema::table_name() . "` WHERE id IN ($placeholders) AND attachments IS NOT NULL",
            ...$clean
        ));
        foreach ($blobs as $blob) {
            $this->purge_attachment_files((string) $blob);
        }
    }

    /** Delete the AttachmentStore-managed files referenced in one row's attachments JSON. */
    private function purge_attachment_files(string $attachments_json): void
    {
        if ($attachments_json === '') {
            return;
        }
        $decoded = json_decode($attachments_json, true);
        if (!is_array($decoded)) {
            return;
        }
        foreach ($decoded as $a) {
            if (is_array($a) && isset($a['path']) && is_string($a['path']) && $a['path'] !== '') {
                AttachmentStore::delete($a['path']);
            }
        }
    }

    /**
     * Aggregated counts grouped by day + status, for the dashboard's bar chart.
     * Days with no emails are still included with zero counts so the chart's
     * x-axis has consistent spacing.
     *
     * @return array<string, array{sent:int, failed:int, sending:int, retried:int}>
     *         Keyed by 'Y-m-d' in the site timezone.
     */
    public function counts_by_day(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT DATE(created_at) AS day, status, COUNT(*) AS count
                 FROM `' . Schema::table_name() . '`
                 WHERE created_at BETWEEN %s AND %s
                 GROUP BY day, status',
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s')
            ),
            ARRAY_A
        );

        $out = [];
        $cursor = $from->setTime(0, 0);
        $stop = $to->setTime(0, 0);
        while ($cursor <= $stop) {
            $key = $cursor->format('Y-m-d');
            $out[$key] = ['sent' => 0, 'failed' => 0, 'sending' => 0, 'retried' => 0];
            $cursor = $cursor->modify('+1 day');
        }

        if (is_array($rows)) {
            foreach ($rows as $r) {
                $day = (string) ($r['day'] ?? '');
                $status = (string) ($r['status'] ?? '');
                $count = (int) ($r['count'] ?? 0);
                if (!isset($out[$day]) || !isset($out[$day][$status])) {
                    continue;
                }
                $out[$day][$status] = $count;
            }
        }

        return $out;
    }

    /**
     * Group counts into time buckets of fixed size for the activity chart.
     * Gap-fills empty buckets with zeros so the chart's x-axis has even spacing.
     *
     * Timezone note: created_at is stored as a UTC datetime string, but
     * MySQL's UNIX_TIMESTAMP() interprets DATETIME values in the session
     * timezone, which gives wrong unix seconds when the session is local
     * time (very common). DST makes a single offset insufficient. We use
     * TIMESTAMPDIFF(SECOND, ANCHOR, created_at) instead — it does pure
     * calendar arithmetic on the string values, ignoring session tz — then
     * add the anchor's known UTC unix ts to land on true UTC seconds.
     *
     * @param  int $bucket_seconds e.g. 60 (1min), 3600 (1h), 86400 (1d)
     * @return array<int, array{ts:int, sent:int, failed:int}> chronological list
     */
    public function counts_by_bucket(\DateTimeImmutable $from, \DateTimeImmutable $to, int $bucket_seconds): array
    {
        global $wpdb;

        $bucket_seconds = max(60, $bucket_seconds);
        $from_sql = $from->format('Y-m-d H:i:s');
        $to_sql = $to->format('Y-m-d H:i:s');

        // 2000-01-01 00:00:00 UTC = 946684800 unix seconds. Pick any fixed
        // anchor; this one is comfortably before any conceivable log row.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    FLOOR(TIMESTAMPDIFF(SECOND, '2000-01-01 00:00:00', created_at) / %d) * %d + 946684800 AS bucket_ts,
                    status,
                    COUNT(*) AS cnt
                 FROM `" . Schema::table_name() . "`
                 WHERE created_at BETWEEN %s AND %s
                 GROUP BY bucket_ts, status",
                $bucket_seconds,
                $bucket_seconds,
                $from_sql,
                $to_sql
            ),
            ARRAY_A
        );

        // Build a zero-filled bucket map indexed by start timestamp.
        $out = [];
        $cursor = (int) (floor($from->getTimestamp() / $bucket_seconds) * $bucket_seconds);
        $stop = $to->getTimestamp();
        while ($cursor <= $stop) {
            $out[$cursor] = ['ts' => $cursor, 'sent' => 0, 'failed' => 0];
            $cursor += $bucket_seconds;
        }

        if (is_array($rows)) {
            foreach ($rows as $r) {
                $ts = (int) ($r['bucket_ts'] ?? 0);
                $status = (string) ($r['status'] ?? '');
                $cnt = (int) ($r['cnt'] ?? 0);
                if (!isset($out[$ts])) {
                    continue;
                }
                if ($status === LogEntry::STATUS_SENT) {
                    $out[$ts]['sent'] = $cnt;
                } elseif ($status === LogEntry::STATUS_FAILED) {
                    $out[$ts]['failed'] = $cnt;
                }
            }
        }

        return array_values($out);
    }

    /** Earliest log timestamp in UTC, or null if the table is empty. */
    public function oldest_log_time(): ?\DateTimeImmutable
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_var("SELECT MIN(created_at) FROM `" . Schema::table_name() . "`");
        if (!is_string($row) || $row === '' || $row === '0000-00-00 00:00:00') {
            return null;
        }
        try {
            return new \DateTimeImmutable($row, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * For a given newsletter, return a map of recipient-row id → log id for
     * any logged entry. Used by the recipients drawer to attach a "View in
     * Logs" link to failed rows. Since the default newsletter-suppress rule
     * deletes successes, in practice only failed rows have a match here.
     *
     * @param array<int, int> $recipient_ids
     * @return array<int, int>
     */
    public function log_ids_for_newsletter_recipients(int $newsletter_id, array $recipient_ids): array
    {
        if ($newsletter_id <= 0 || $recipient_ids === []) {
            return [];
        }
        global $wpdb;
        $clean = array_values(array_filter(array_map('intval', $recipient_ids), static fn (int $i): bool => $i > 0));
        if ($clean === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($clean), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, recipient_id FROM `' . Schema::table_name() . "`
                  WHERE newsletter_id = %d AND recipient_id IN ($placeholders)
                  ORDER BY id DESC",
                $newsletter_id,
                ...$clean
            ),
            ARRAY_A
        );
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $rid = (int) ($r['recipient_id'] ?? 0);
            $lid = (int) ($r['id'] ?? 0);
            // ORDER BY id DESC means the first row we see per recipient is
            // the newest log — what the admin wants to inspect.
            if ($rid > 0 && $lid > 0 && !isset($out[$rid])) {
                $out[$rid] = $lid;
            }
        }
        return $out;
    }

    /** @return array<int, string> distinct sources present in the table */
    public function distinct_sources(): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_col("SELECT DISTINCT source FROM `" . Schema::table_name() . "` ORDER BY source ASC");
        return is_array($rows) ? array_values(array_filter($rows, static fn ($r): bool => is_string($r) && $r !== '')) : [];
    }

    /**
     * Whitelist `orderby` to a stored column name; fall back to the
     * default `id` (newest-first) when unknown. Keeps SQL injection out
     * of the ORDER BY interpolation in `paginate()`.
     */
    private static function sanitize_orderby(string $key): string
    {
        // `to_emails` is a TEXT column storing the comma/json-joined
        // recipient list — sort sorts lexically on the raw payload
        // (i.e. by the first listed recipient most of the time).
        $allowed = ['id', 'created_at', 'status', 'from_email', 'to_emails', 'subject', 'source'];
        return in_array($key, $allowed, true) ? $key : 'id';
    }

    /**
     * @param array{status?: string, source?: string, search?: string, date_from?: string, date_to?: string, newsletter_id?: int, newsletter_mode?: string} $filters
     * @return array{0: string, 1: array<int, scalar>} [where clause including WHERE keyword if present, params array]
     */
    private function build_where(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'status = %s';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['source'])) {
            $clauses[] = 'source = %s';
            $params[] = (string) $filters['source'];
        }
        if (!empty($filters['search'])) {
            $like = '%' . $GLOBALS['wpdb']->esc_like((string) $filters['search']) . '%';
            $clauses[] = '(subject LIKE %s OR from_email LIKE %s OR to_emails LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($filters['date_from'])) {
            $clauses[] = 'created_at >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $clauses[] = 'created_at <= %s';
            $params[] = (string) $filters['date_to'];
        }
        // Newsletter visibility — default is exclude. The Logs page exposes a
        // tri-state dropdown so the admin can see newsletter rows when
        // debugging without losing the clean default view. An explicit
        // newsletter_id wins over the mode.
        if (isset($filters['newsletter_id']) && (int) $filters['newsletter_id'] > 0) {
            $clauses[] = 'newsletter_id = %d';
            $params[] = (int) $filters['newsletter_id'];
        } else {
            $mode = (string) ($filters['newsletter_mode'] ?? 'exclude');
            if ($mode === 'only') {
                $clauses[] = 'newsletter_id IS NOT NULL';
            } elseif ($mode !== 'include') {
                $clauses[] = 'newsletter_id IS NULL';
            }
        }

        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        return [$where, $params];
    }

    /** @return array<string, mixed> */
    private function row_data(LogEntry $entry): array
    {
        return [
            'status'        => $entry->status,
            'source'        => $entry->source,
            'identity_id'   => $entry->identity_id,
            'newsletter_id'   => $entry->newsletter_id,
            'recipient_id'  => $entry->recipient_id,
            'from_email'    => $entry->from_email,
            'from_name'     => $entry->from_name,
            'to_emails'     => wp_json_encode($entry->to_emails),
            'cc_emails'     => $entry->cc_emails === [] ? null : wp_json_encode($entry->cc_emails),
            'bcc_emails'    => $entry->bcc_emails === [] ? null : wp_json_encode($entry->bcc_emails),
            'reply_to'      => $entry->reply_to,
            'subject'       => $entry->subject,
            'body_html'     => $entry->body_html,
            'body_text'     => $entry->body_text,
            'headers'       => $entry->headers === [] ? null : wp_json_encode($entry->headers),
            'attachments'   => $entry->attachments === [] ? null : wp_json_encode($entry->attachments),
            'message_id'    => $entry->message_id,
            'error_message' => $entry->error_message,
            'retry_count'   => $entry->retry_count,
        ];
    }

    /** @return array<int, string> */
    private function insert_formats(): array
    {
        // status, source, identity_id, newsletter_id, recipient_id,
        // from_email, from_name, to_emails, cc_emails, bcc_emails,
        // reply_to, subject, body_html, body_text, headers, attachments,
        // message_id, error_message, retry_count,
        // then created_at, sent_at (appended in insert())
        return [
            '%s', '%s', '%d', '%d', '%d',
            '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%d',
            '%s', '%s',
        ];
    }
}
