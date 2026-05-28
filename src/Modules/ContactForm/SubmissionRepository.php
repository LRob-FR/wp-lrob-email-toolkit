<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Thin CRUD over the lrob_etk_contact_submissions table. Submissions hold a
 * JSON copy of the user-submitted fields, the IP hash (never the raw IP),
 * user agent, referer, status, and an optional log_id linking back to the
 * outgoing email entry created by the Logging module.
 *
 * Stored field values are kept verbatim — any escaping happens at *render*
 * time. CSV export will need to neuter spreadsheet formula injection
 * (=, +, -, @ prefixes) before writing the file — flagged in CLAUDE.md
 * backlog. Do not pre-neuter on insert.
 */
final class SubmissionRepository
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SPAM_BLOCKED = 'spam_blocked';

    public const CAPTCHA_OUTCOME_PASSED = 'passed';

    public const CAPTCHA_OUTCOME_FAILED = 'failed';

    public const CAPTCHA_OUTCOME_SKIPPED = 'skipped';

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $context  keys: ip_hash, ip_address (optional, raw IP when
     *                                              admin opted in), user_agent, referer, notes,
     *                                              captcha_slug, captcha_outcome
     */
    public function insert(int $form_id, array $fields, array $context, string $status = self::STATUS_RECEIVED): int
    {
        global $wpdb;
        // Submissions inserts run inside the AJAX submit pipeline. If
        // anything echoes here (WP_DEBUG_DISPLAY + a stale schema column,
        // for example), the JSON response gets prefixed with HTML and the
        // frontend falls back to its generic "Something went wrong"
        // message. Silence wpdb output during the insert; the call's
        // return value still tells us success/failure.
        $suppress_was = $wpdb->suppress_errors(true);
        $show_was = $wpdb->show_errors(false);
        $wpdb->insert(
            Schema::submissions_table(),
            [
                'form_id'         => $form_id,
                'submitted_at'    => gmdate('Y-m-d H:i:s'),
                'status'          => $status,
                'ip_hash'         => substr($context['ip_hash'] ?? '', 0, 64),
                'ip_address'      => substr((string) ($context['ip_address'] ?? ''), 0, 45),
                'user_agent'      => mb_substr($context['user_agent'] ?? '', 0, 500),
                'referer'         => mb_substr($context['referer'] ?? '', 0, 500),
                'fields_json'     => (string) wp_json_encode($fields),
                'log_id'          => null,
                'notes'           => $context['notes'] ?? null,
                'captcha_slug'    => substr((string) ($context['captcha_slug'] ?? ''), 0, 40),
                'captcha_outcome' => substr((string) ($context['captcha_outcome'] ?? ''), 0, 20),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', null, '%s', '%s', '%s']
        );
        $insert_id = (int) $wpdb->insert_id;
        $wpdb->suppress_errors($suppress_was);
        $wpdb->show_errors($show_was);
        return $insert_id;
    }

    /**
     * Recent captcha activity for the dashboard widget: count by
     * (captcha_slug, captcha_outcome) over the last N days. Excludes rows
     * with no recorded captcha (legacy submissions from before this column
     * existed).
     *
     * @return array<int, array{captcha_slug:string, captcha_outcome:string, n:int}>
     */
    public function captcha_breakdown(int $days = 30): array
    {
        global $wpdb;
        $table = Schema::submissions_table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT captcha_slug, captcha_outcome, COUNT(*) AS n
                 FROM `$table`
                 WHERE submitted_at >= %s AND captcha_slug <> ''
                 GROUP BY captcha_slug, captcha_outcome",
                $cutoff
            ),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        return array_map(
            static fn($r) => [
                'captcha_slug'    => (string) $r['captcha_slug'],
                'captcha_outcome' => (string) $r['captcha_outcome'],
                'n'               => (int) $r['n'],
            ],
            $rows
        );
    }

    /**
     * Notes-field prefix used to carry the pre-spam status across a
     * manual spam flag. Distinguishes our marker from other diagnostic
     * notes (`honeypot_tripped`, `wp_mail_returned_false`, …).
     */
    private const NOTE_PRIOR_PREFIX = 'prior:';

    /**
     * Flag a submission as spam, preserving its current status inside the
     * `notes` field as `prior:<status>` so a later restore_from_spam can
     * roll it back accurately. No-op if already spam-flagged.
     *
     * Auto-spam (honeypot / captcha) bypasses this and writes its
     * own diagnostic notes via the SubmitHandler — those rows have no
     * prior status, and restoring them falls back to `received`.
     */
    public function flag_as_spam(int $id): bool
    {
        $row = $this->find($id);
        if ($row === null || $row->status === self::STATUS_SPAM_BLOCKED) {
            return false;
        }
        $this->update_status(
            $id,
            self::STATUS_SPAM_BLOCKED,
            null,
            self::NOTE_PRIOR_PREFIX . $row->status
        );
        return true;
    }

    /**
     * Restore a spam-flagged submission to its pre-spam status, read from
     * the `notes` marker that flag_as_spam wrote. If the marker is
     * missing (auto-spam, legacy rows from before this column was used
     * for prior-status), fall back to `received`.
     *
     * No-op (returns false) if the row doesn't exist or isn't currently
     * spam-flagged.
     */
    public function restore_from_spam(int $id, ?string $notes = null): bool
    {
        $row = $this->find($id);
        if ($row === null || $row->status !== self::STATUS_SPAM_BLOCKED) {
            return false;
        }
        $target = self::STATUS_RECEIVED;
        $raw_notes = is_string($row->notes) ? $row->notes : '';
        if (str_starts_with($raw_notes, self::NOTE_PRIOR_PREFIX)) {
            $candidate = substr($raw_notes, strlen(self::NOTE_PRIOR_PREFIX));
            $known = [self::STATUS_RECEIVED, self::STATUS_DELIVERED, self::STATUS_FAILED];
            if (in_array($candidate, $known, true)) {
                $target = $candidate;
            }
        }
        $this->update_status($id, $target, null, $notes);
        return true;
    }

    public function update_status(int $id, string $status, ?int $log_id = null, ?string $notes = null): void
    {
        global $wpdb;
        $data = ['status' => $status];
        $formats = ['%s'];
        if ($log_id !== null) {
            $data['log_id'] = $log_id;
            $formats[] = '%d';
        }
        if ($notes !== null) {
            $data['notes'] = $notes;
            $formats[] = '%s';
        }
        $wpdb->update(Schema::submissions_table(), $data, ['id' => $id], $formats, ['%d']);
    }

    public function count_for_form(int $form_id): int
    {
        global $wpdb;
        $table = Schema::submissions_table();
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE form_id = %d", $form_id)
        );
    }

    public function count_total(): int
    {
        global $wpdb;
        $table = Schema::submissions_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
    }

    /**
     * Per-form receivable + blocked counters. Used by the Forms list card
     * to surface "N received / M blocked" at a glance. Receivable =
     * received + delivered + failed (anything not classified as spam).
     *
     * @return array{received:int, blocked:int}
     */
    public function counts_for_form_split(int $form_id): array
    {
        global $wpdb;
        $table = Schema::submissions_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS n FROM `$table` WHERE form_id = %d GROUP BY status",
                $form_id
            ),
            ARRAY_A
        );
        $received = 0;
        $blocked = 0;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $status = (string) $row['status'];
                $n = (int) $row['n'];
                if ($status === self::STATUS_SPAM_BLOCKED) {
                    $blocked += $n;
                } else {
                    $received += $n;
                }
            }
        }
        return ['received' => $received, 'blocked' => $blocked];
    }

    /**
     * Delete delivered + received + failed rows older than $cutoff.
     * Spam_blocked rows are handled separately by delete_spam_older_than()
     * so the two retention windows can differ.
     */
    public function delete_non_spam_older_than(\DateTimeImmutable $cutoff): int
    {
        global $wpdb;
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM `' . Schema::submissions_table() . "` WHERE submitted_at < %s AND status <> %s",
                $cutoff->format('Y-m-d H:i:s'),
                self::STATUS_SPAM_BLOCKED
            )
        );
        return is_int($deleted) ? $deleted : 0;
    }

    /** @return list<int> */
    public function list_ids_by_status_older_than(string $status, \DateTimeImmutable $cutoff): array
    {
        global $wpdb;
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM `' . Schema::submissions_table() . "` WHERE submitted_at < %s AND status = %s",
                $cutoff->format('Y-m-d H:i:s'),
                $status
            )
        );
        return is_array($rows) ? array_map('intval', $rows) : [];
    }

    public function delete_by_status_older_than(string $status, \DateTimeImmutable $cutoff): int
    {
        global $wpdb;
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM `' . Schema::submissions_table() . "` WHERE submitted_at < %s AND status = %s",
                $cutoff->format('Y-m-d H:i:s'),
                $status
            )
        );
        return is_int($deleted) ? $deleted : 0;
    }

    /** @return list<int> */
    public function list_ids_non_spam_older_than(\DateTimeImmutable $cutoff): array
    {
        global $wpdb;
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM `' . Schema::submissions_table() . "` WHERE submitted_at < %s AND status <> %s",
                $cutoff->format('Y-m-d H:i:s'),
                self::STATUS_SPAM_BLOCKED
            )
        );
        return is_array($rows) ? array_map('intval', $rows) : [];
    }

    /** @return list<int> */
    public function list_ids_spam_older_than(\DateTimeImmutable $cutoff): array
    {
        global $wpdb;
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM `' . Schema::submissions_table() . "` WHERE submitted_at < %s AND status = %s",
                $cutoff->format('Y-m-d H:i:s'),
                self::STATUS_SPAM_BLOCKED
            )
        );
        return is_array($rows) ? array_map('intval', $rows) : [];
    }

    public function delete_spam_older_than(\DateTimeImmutable $cutoff): int
    {
        global $wpdb;
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM `' . Schema::submissions_table() . "` WHERE submitted_at < %s AND status = %s",
                $cutoff->format('Y-m-d H:i:s'),
                self::STATUS_SPAM_BLOCKED
            )
        );
        return is_int($deleted) ? $deleted : 0;
    }

    /** Hard-delete a single submission row. Files attached to it (if any)
     *  are handled separately by the caller (FileRepository). */
    public function delete_by_id(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        global $wpdb;
        $ok = $wpdb->delete(Schema::submissions_table(), ['id' => $id], ['%d']);
        return (bool) $ok;
    }

    /** Delete all submissions for a form. Used by the cascade-delete path. */
    public function delete_for_form(int $form_id): int
    {
        if ($form_id <= 0) {
            return 0;
        }
        global $wpdb;
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM `' . Schema::submissions_table() . '` WHERE form_id = %d',
                $form_id
            )
        );
        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Site-wide counts by status. Used by FormsPage's bottom stats card
     * and the dashboard tile so we only hit the database once.
     *
     * @return array{received:int, delivered:int, failed:int, blocked:int, total:int}
     */
    public function counts_by_status(): array
    {
        global $wpdb;
        $table = Schema::submissions_table();
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS n FROM `$table` GROUP BY status",
            ARRAY_A
        );
        $out = ['received' => 0, 'delivered' => 0, 'failed' => 0, 'blocked' => 0, 'total' => 0];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $n = (int) $row['n'];
            $out['total'] += $n;
            if ($status === self::STATUS_DELIVERED) {
                $out['delivered'] += $n;
            } elseif ($status === self::STATUS_FAILED) {
                $out['failed'] += $n;
            } elseif ($status === self::STATUS_SPAM_BLOCKED) {
                $out['blocked'] += $n;
            } elseif ($status === self::STATUS_RECEIVED) {
                $out['received'] += $n;
            }
        }
        return $out;
    }

    /**
     * Honeypot-tripped submissions in the last N days. Honeypot is a
     * ContactForm-side block (captcha never sees it), so the dashboard
     * tile reads this counter separately from captcha stats.
     */
    public function count_honeypot_blocks(int $days = 30): int
    {
        global $wpdb;
        $table = Schema::submissions_table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `$table`
                 WHERE submitted_at >= %s AND status = %s AND notes = %s",
                $cutoff,
                self::STATUS_SPAM_BLOCKED,
                'honeypot_tripped'
            )
        );
    }

    /**
     * @param array{form_ids?: array<int, int>, statuses?: array<int, string>,
     *              captcha_outcomes?: array<int, string>, search?: string,
     *              date_from?: string, date_to?: string} $filters
     */
    public function count(array $filters = []): int
    {
        global $wpdb;
        [$where, $params] = $this->build_where($filters);
        $sql = 'SELECT COUNT(*) FROM `' . Schema::submissions_table() . "` $where";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) ($params === [] ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, ...$params)));
    }

    /**
     * @param array{form_ids?: array<int, int>, statuses?: array<int, string>,
     *              captcha_outcomes?: array<int, string>, search?: string,
     *              date_from?: string, date_to?: string} $filters
     * @return array<int, Submission>
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
        $sql = 'SELECT * FROM `' . Schema::submissions_table() . "` $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        return array_map(static fn (array $r): Submission => Submission::from_row($r), $rows);
    }

    public function find(int $id): ?Submission
    {
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM `' . Schema::submissions_table() . '` WHERE id = %d',
                $id
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        return Submission::from_row($row);
    }

    /** Reverse lookup: outbound email log_id → submission id (or null). */
    public function find_by_log_id(int $log_id): ?Submission
    {
        if ($log_id <= 0) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM `' . Schema::submissions_table() . '` WHERE log_id = %d ORDER BY id DESC LIMIT 1',
                $log_id
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        return Submission::from_row($row);
    }

    /**
     * Batched reverse lookup: log_ids → [log_id => submission_id]. Used by
     * the Logs page to render "View submission" links without N+1 queries.
     *
     * @param array<int, int> $log_ids
     * @return array<int, int>
     */
    public function submission_ids_for_log_ids(array $log_ids): array
    {
        $clean = array_values(array_filter(array_map('intval', $log_ids), static fn (int $i): bool => $i > 0));
        if ($clean === []) {
            return [];
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($clean), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, log_id FROM `' . Schema::submissions_table() . "` WHERE log_id IN ($placeholders)",
                ...$clean
            ),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['log_id']] = (int) $row['id'];
        }
        return $map;
    }

    /**
     * @param array{form_ids?: array<int, int>, statuses?: array<int, string>,
     *              captcha_outcomes?: array<int, string>, search?: string,
     *              date_from?: string, date_to?: string} $filters
     * @return array{0:string, 1:array<int, scalar>}
     */
    private static function sanitize_orderby(string $key): string
    {
        // Match the actual `wp_lrob_etk_cf_submissions` schema: the date
        // column is `submitted_at`, not `created_at`; from-email lives
        // in `fields_json` (not its own column) so it isn't sortable.
        $allowed = ['id', 'submitted_at', 'status', 'form_id', 'captcha_outcome'];
        return in_array($key, $allowed, true) ? $key : 'id';
    }

    private function build_where(array $filters): array
    {
        global $wpdb;
        $clauses = [];
        $params = [];

        if (!empty($filters['form_ids']) && is_array($filters['form_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['form_ids']), static fn (int $i): bool => $i > 0));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $clauses[] = "form_id IN ($placeholders)";
                foreach ($ids as $id) {
                    $params[] = $id;
                }
            }
        }
        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $values = array_values(array_filter(array_map('strval', $filters['statuses']), static fn (string $s): bool => $s !== ''));
            if ($values !== []) {
                $placeholders = implode(',', array_fill(0, count($values), '%s'));
                $clauses[] = "status IN ($placeholders)";
                foreach ($values as $v) {
                    $params[] = $v;
                }
            }
        }
        if (!empty($filters['captcha_outcomes']) && is_array($filters['captcha_outcomes'])) {
            $values = array_values(array_filter(array_map('strval', $filters['captcha_outcomes']), static fn (string $s): bool => $s !== ''));
            if ($values !== []) {
                $placeholders = implode(',', array_fill(0, count($values), '%s'));
                $clauses[] = "captcha_outcome IN ($placeholders)";
                foreach ($values as $v) {
                    $params[] = $v;
                }
            }
        }
        if (!empty($filters['search']) && is_string($filters['search'])) {
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            // Search both the JSON payload and the IP hash (admin
            // sometimes pastes a hashed value when tracing abuse).
            $clauses[] = '(fields_json LIKE %s OR notes LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($filters['date_from']) && is_string($filters['date_from'])) {
            $clauses[] = 'submitted_at >= %s';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to']) && is_string($filters['date_to'])) {
            $clauses[] = 'submitted_at <= %s';
            $params[] = $filters['date_to'];
        }

        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        return [$where, $params];
    }
}
