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

    public function update_imap_result(int $id, bool $saved, ?string $error = null): void
    {
        global $wpdb;
        $wpdb->update(
            Schema::table_name(),
            ['imap_saved' => $saved ? 1 : 0, 'imap_error' => $error],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
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
     *     date_to?: string
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
     * @param array{status?: string, source?: string, search?: string, date_from?: string, date_to?: string} $filters
     * @return array<int, LogEntry>
     */
    public function paginate(array $filters, int $page, int $per_page): array
    {
        global $wpdb;
        $page = max(1, $page);
        $per_page = max(1, min(200, $per_page));
        $offset = ($page - 1) * $per_page;

        [$where, $params] = $this->build_where($filters);
        $sql = "SELECT * FROM `" . Schema::table_name() . "` $where ORDER BY id DESC LIMIT %d OFFSET %d";
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
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `" . Schema::table_name() . "` WHERE created_at < %s",
                $cutoff->format('Y-m-d H:i:s')
            )
        );
        return is_int($deleted) ? $deleted : 0;
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

    /** @return array<int, string> distinct sources present in the table */
    public function distinct_sources(): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_col("SELECT DISTINCT source FROM `" . Schema::table_name() . "` ORDER BY source ASC");
        return is_array($rows) ? array_values(array_filter($rows, static fn ($r): bool => is_string($r) && $r !== '')) : [];
    }

    /**
     * @param array{status?: string, source?: string, search?: string, date_from?: string, date_to?: string} $filters
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
            'campaign_id'   => $entry->campaign_id,
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
            'imap_saved'    => $entry->imap_saved ? 1 : 0,
            'imap_error'    => $entry->imap_error,
        ];
    }

    /** @return array<int, string> */
    private function insert_formats(): array
    {
        // status, source, identity_id, campaign_id, recipient_id,
        // from_email, from_name, to_emails, cc_emails, bcc_emails,
        // reply_to, subject, body_html, body_text, headers, attachments,
        // message_id, error_message, retry_count, imap_saved, imap_error,
        // then created_at, sent_at (appended in insert())
        return [
            '%s', '%s', '%d', '%d', '%d',
            '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%s', '%s', '%s',
            '%s', '%s', '%d', '%d', '%s',
            '%s', '%s',
        ];
    }
}
