<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Read-side helpers for the subscribers table at this stage of the build.
 * CRUD lands when subscribe forms ship (step 3). Today this exists so:
 *   - Module::data_summary() can show a "N subscribers" tile on the toolkit
 *     dashboard;
 *   - UserHooks::on_user_register() can detect "this new WP user matches
 *     an existing subscriber row" and trigger the promotion path.
 *
 * Everything is scoped to non-trashed rows by default — the trash tab is the
 * only place trashed rows surface.
 */
final class SubscriberRepository
{
    /** Total non-trashed subscribers across all statuses. */
    public function count_total(): int
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE status <> 'trashed'");
    }

    /** Confirmed-status subscribers only — what "All subscribers" send target counts. */
    public function count_confirmed(): int
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE status = 'confirmed'");
    }

    /**
     * Look up a subscriber row by email. Returns the raw associative row or
     * null. Matches against ALL statuses including trashed/refused/bounced
     * so callers can detect "this email exists in some state" — useful for
     * the user_register promotion path and for resubscribe deduplication.
     *
     * @return array<string, string>|null
     */
    public function find_by_email(string $email): ?array
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare("SELECT * FROM `$table` WHERE email = %s LIMIT 1", $email),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /** Hard-delete a subscriber row by id. Used by the user_register promotion. */
    public function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(Schema::subscribers_table(), ['id' => $id], ['%d']);
    }

    /**
     * Insert a new pending subscriber. Returns the new row's id.
     * Caller is responsible for the email-uniqueness pre-check via
     * find_by_email — the schema's UNIQUE KEY would reject duplicates
     * anyway but the caller's flow needs to know whether to insert or
     * update.
     */
    public function insert_pending(string $email, string $name, string $source, string $language = ''): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $ok = $wpdb->insert(Schema::subscribers_table(), [
            'email'             => $email,
            'name'              => $name,
            'language'          => $language,
            'status'            => 'pending',
            'previous_status'   => '',
            'category_opt_outs' => '[]',
            'prefs_token'       => UserMeta::generate_prefs_token(),
            'source'            => $source,
            'bounce_count'      => 0,
            'created_at'        => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']);
        if ($ok === false) {
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Resubscribe an existing row — used when someone in a non-pending
     * state (refused, unsubscribed, bounced, trashed) signs up again
     * via the form. Flips status to pending, regenerates the prefs
     * token (so the old token can't be reused to confirm the new
     * intent), and clears any previous-status / trash bookkeeping.
     */
    public function reset_to_pending(int $id): void
    {
        global $wpdb;
        $wpdb->update(Schema::subscribers_table(), [
            'status'          => 'pending',
            'previous_status' => '',
            'prefs_token'     => UserMeta::generate_prefs_token(),
            'trashed_at'      => null,
            'trashed_reason'  => '',
        ], ['id' => $id], ['%s', '%s', '%s', '%s', '%s'], ['%d']);
    }

    /**
     * Flip a subscriber's status. Used by the confirm/refuse URL
     * handlers when the recipient acts on the confirmation email.
     */
    public function update_status(int $id, string $status, ?string $confirmed_at = null): void
    {
        global $wpdb;
        $data = ['status' => $status];
        $formats = ['%s'];
        if ($confirmed_at !== null) {
            $data['confirmed_at'] = $confirmed_at;
            $formats[] = '%s';
        }
        $wpdb->update(Schema::subscribers_table(), $data, ['id' => $id], $formats, ['%d']);
    }

    public function find_by_id(int $id): ?array
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `$table` WHERE id = %d LIMIT 1", $id),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * Look up a subscriber by their opaque prefs_token (the secret
     * carried in tokenised prefs / unsubscribe URLs). Returns null on
     * miss — caller falls through to checking WP users via user_meta.
     */
    public function find_by_prefs_token(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        global $wpdb;
        $table = Schema::subscribers_table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `$table` WHERE prefs_token = %s LIMIT 1", $token),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /** Replace the subscribers.category_opt_outs JSON column. */
    public function set_category_opt_outs(int $id, array $opt_out_slugs): void
    {
        global $wpdb;
        $wpdb->update(
            Schema::subscribers_table(),
            ['category_opt_outs' => (string) wp_json_encode(array_values($opt_out_slugs))],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Cron-friendly scan: pending subscribers whose last reminder was
     * more than $interval_days ago (or never) AND whose reminder_count
     * is below $max. Ordered by oldest-first so a partial-batch run
     * picks up the longest-waiting subscribers next time. LIMIT keeps
     * one tick bounded.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_pending_for_reminder(int $first_after_days, int $interval_days, int $max_reminders, int $limit = 50): array
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        $first_threshold = gmdate('Y-m-d H:i:s', time() - ($first_after_days * DAY_IN_SECONDS));
        $interval_threshold = gmdate('Y-m-d H:i:s', time() - ($interval_days * DAY_IN_SECONDS));
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `$table`
                  WHERE status = 'pending'
                    AND reminder_count < %d
                    AND (
                        (reminder_count = 0 AND created_at <= %s)
                        OR (reminder_count > 0 AND last_reminder_at <= %s)
                    )
                  ORDER BY created_at ASC
                  LIMIT %d",
                $max_reminders,
                $first_threshold,
                $interval_threshold,
                $limit
            ),
            ARRAY_A
        );
    }

    /** Increment reminder_count and stamp last_reminder_at. */
    public function record_reminder_sent(int $id): void
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        $wpdb->query($wpdb->prepare(
            "UPDATE `$table`
                SET reminder_count = reminder_count + 1,
                    last_reminder_at = %s
              WHERE id = %d",
            current_time('mysql', true),
            $id
        ));
    }

    /**
     * Paginated listing for the Subscribers admin view. `$status` filters
     * by exact status; empty string returns every status EXCEPT trashed
     * (the trashed tab uses status='trashed' explicitly). `$search` is a
     * `LIKE '%term%'` match on email + name; empty disables it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_with_filters(string $status, string $search, int $limit, int $offset): array
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        [$where_sql, $where_args] = self::build_where($status, $search);
        $sql = "SELECT * FROM `$table` $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $args = array_merge($where_args, [$limit, $offset]);
        return (array) $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare($sql, ...$args),
            ARRAY_A
        );
    }

    public function count_with_filters(string $status, string $search): int
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        [$where_sql, $where_args] = self::build_where($status, $search);
        $sql = "SELECT COUNT(*) FROM `$table` $where_sql";
        if ($where_args === []) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return (int) $wpdb->get_var($sql);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$where_args));
    }

    /**
     * Returns map of `status → count` for the tab badges. `''` key holds
     * the "all (non-trashed)" total — what the dashboard tile shows.
     *
     * @return array<string, int>
     */
    public function counts_by_status(): array
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        $rows = (array) $wpdb->get_results(
            "SELECT status, COUNT(*) AS c FROM `$table` GROUP BY status",
            ARRAY_A
        );
        $counts = [
            ''             => 0,
            'pending'      => 0,
            'confirmed'    => 0,
            'unsubscribed' => 0,
            'refused'      => 0,
            'bounced'      => 0,
            'trashed'      => 0,
        ];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['c'] ?? 0);
            if (isset($counts[$status])) {
                $counts[$status] = $count;
            }
            if ($status !== 'trashed') {
                $counts[''] += $count;
            }
        }
        return $counts;
    }

    /**
     * Admin-initiated trash. Stashes the current status into
     * previous_status so Restore can flip back to it.
     */
    public function trash(int $id, string $reason = 'admin'): void
    {
        $current = $this->find_by_id($id);
        if (!is_array($current)) {
            return;
        }
        $previous = (string) ($current['status'] ?? '');
        if ($previous === 'trashed') {
            return;
        }
        global $wpdb;
        $wpdb->update(
            Schema::subscribers_table(),
            [
                'status'          => 'trashed',
                'previous_status' => $previous,
                'trashed_at'      => current_time('mysql', true),
                'trashed_reason'  => $reason,
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Restore a trashed row to its previous_status (or 'pending' if the
     * previous_status was somehow blanked). Clears the trash bookkeeping
     * columns.
     */
    public function restore(int $id): bool
    {
        $current = $this->find_by_id($id);
        if (!is_array($current) || (string) ($current['status'] ?? '') !== 'trashed') {
            return false;
        }
        $previous = (string) ($current['previous_status'] ?? '');
        if ($previous === '' || $previous === 'trashed') {
            $previous = 'pending';
        }
        global $wpdb;
        $wpdb->update(
            Schema::subscribers_table(),
            [
                'status'          => $previous,
                'previous_status' => '',
                'trashed_at'      => null,
                'trashed_reason'  => '',
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
        return true;
    }

    /**
     * Hard-delete one trashed row. Refuses to act on non-trashed rows
     * — permanent delete should always be a two-step (trash → delete)
     * to prevent admin slip-ups. The user_register promotion path uses
     * the unconditional `delete()` method above instead.
     */
    public function permanently_delete(int $id): bool
    {
        $current = $this->find_by_id($id);
        if (!is_array($current) || (string) ($current['status'] ?? '') !== 'trashed') {
            return false;
        }
        $this->delete($id);
        return true;
    }

    /**
     * Hard-delete every trashed row. Returns the number of rows removed.
     * No safeguard against very large batches — admins reviewing the
     * trash know how much is in there before clicking "Empty".
     */
    public function empty_trash(): int
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        return (int) $wpdb->query("DELETE FROM `$table` WHERE status = 'trashed'");
    }

    /**
     * Cron-side: hard-delete trashed rows older than `$days`. Bounded
     * `LIMIT` keeps transaction size predictable on huge tables; the
     * cron loops until 0 rows match.
     */
    public function purge_old_trash(int $days, int $batch_limit = 500): int
    {
        if ($days <= 0) {
            return 0;
        }
        global $wpdb;
        $table = Schema::subscribers_table();
        $threshold = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM `$table`
              WHERE status = 'trashed'
                AND trashed_at IS NOT NULL
                AND trashed_at <= %s
              LIMIT %d",
            $threshold,
            $batch_limit
        ));
    }

    /**
     * @return array{0:string,1:array<int,mixed>} `[$where_sql,$args]`
     */
    private static function build_where(string $status, string $search): array
    {
        $clauses = [];
        $args = [];
        if ($status === '') {
            $clauses[] = "status <> 'trashed'";
        } else {
            $clauses[] = 'status = %s';
            $args[] = $status;
        }
        if ($search !== '') {
            $like = '%' . $GLOBALS['wpdb']->esc_like($search) . '%';
            $clauses[] = '(email LIKE %s OR name LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        return ['WHERE ' . implode(' AND ', $clauses), $args];
    }
}
