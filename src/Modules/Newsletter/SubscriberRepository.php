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
            'email'           => $email,
            'name'            => $name,
            'language'        => $language,
            'status'          => 'pending',
            'previous_status' => '',
            'prefs_token'     => UserMeta::generate_prefs_token(),
            'source'          => $source,
            'bounce_count'    => 0,
            'created_at'      => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']);
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

    /**
     * Admin-side rename / re-email. Returns one of:
     *   - 'ok'          — update applied (no email change or new email free)
     *   - 'email_taken' — the requested email already belongs to another row
     *   - 'invalid'     — email is malformed
     *   - 'noop'        — subscriber not found
     * Caller is responsible for surfacing the result.
     */
    public function update_basics(int $id, string $email, string $name): string
    {
        $row = $this->find_by_id($id);
        if ($row === null) {
            return 'noop';
        }
        $email = sanitize_email($email);
        $name = sanitize_text_field($name);
        if ($email === '' || !is_email($email)) {
            return 'invalid';
        }
        if (strcasecmp($email, (string) $row['email']) !== 0) {
            $clash = $this->find_by_email($email);
            if ($clash !== null && (int) $clash['id'] !== $id) {
                return 'email_taken';
            }
        }
        global $wpdb;
        $wpdb->update(Schema::subscribers_table(), [
            'email' => $email,
            'name'  => $name,
        ], ['id' => $id], ['%s', '%s'], ['%d']);
        return 'ok';
    }

    /**
     * Single-column profile-field write. Returns 'ok' / 'invalid' /
     * 'noop' / 'email_taken'. Whitelists `$column` against
     * `SubscriberFields::PROFILE_COLUMNS` so the caller can never reach
     * outside the subscriber profile schema. Email writes go through the
     * collision check + is_email validation; everything else flows through
     * the per-column sanitiser.
     */
    public function set_profile_field(int $id, string $column, string $value): string
    {
        $row = $this->find_by_id($id);
        if ($row === null) {
            return 'noop';
        }
        if (!in_array($column, SubscriberFields::PROFILE_COLUMNS, true)) {
            return 'invalid';
        }
        $clean = SubscriberFields::sanitize($column, $value);
        if ($column === 'email') {
            if ($clean === '' || !is_email($clean)) {
                return 'invalid';
            }
            if (strcasecmp($clean, (string) $row['email']) !== 0) {
                $other = $this->find_by_email($clean);
                if ($other !== null && (int) $other['id'] !== $id) {
                    return 'email_taken';
                }
            }
        }
        global $wpdb;
        $wpdb->update(Schema::subscribers_table(), [$column => $clean], ['id' => $id], ['%s'], ['%d']);
        return 'ok';
    }

    /**
     * Stage an email-change request. Generates a single-use token, stores
     * it alongside the requested new address + a timestamp. Caller is
     * responsible for dispatching the confirmation message; this method
     * only persists the request.
     *
     * Returns one of:
     *   - 'ok'          — request persisted, token in second slot
     *   - 'invalid'     — malformed email
     *   - 'same'        — new email equals current
     *   - 'email_taken' — another subscriber already owns the address
     *   - 'noop'        — subscriber row not found
     *
     * Calling this with a different `$new_email` while a previous
     * request is still pending overwrites it (the previous token becomes
     * useless silently — the new request supersedes).
     *
     * @return array{0:string, 1:string} `[$status, $token]` (token empty unless status='ok')
     */
    public function set_pending_email_change(int $id, string $new_email): array
    {
        $row = $this->find_by_id($id);
        if ($row === null) {
            return ['noop', ''];
        }
        $new_email = sanitize_email($new_email);
        if ($new_email === '' || !is_email($new_email)) {
            return ['invalid', ''];
        }
        if (strcasecmp($new_email, (string) $row['email']) === 0) {
            return ['same', ''];
        }
        $other = $this->find_by_email($new_email);
        if ($other !== null && (int) $other['id'] !== $id) {
            return ['email_taken', ''];
        }
        $token = UserMeta::generate_prefs_token();
        global $wpdb;
        $wpdb->update(
            Schema::subscribers_table(),
            [
                'pending_email'              => $new_email,
                'pending_email_token'        => $token,
                'pending_email_requested_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        return ['ok', $token];
    }

    /**
     * Resolve a pending-email-change token to its subscriber row.
     * Tolerant of expired tokens (caller decides what to do based on
     * `pending_email_requested_at`); returns null only when no row
     * carries the token.
     *
     * @return array<string, mixed>|null
     */
    public function find_by_pending_email_token(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        global $wpdb;
        $table = Schema::subscribers_table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `$table` WHERE pending_email_token = %s LIMIT 1", $token),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * Apply a pending email-change: flip `email` to `pending_email`,
     * clear the pending columns + token. Returns one of:
     *   - 'ok'          — change applied
     *   - 'expired'     — token TTL exceeded
     *   - 'email_taken' — somebody else claimed the address in the meantime
     *   - 'invalid'     — token didn't match any row, or no pending email
     *
     * `$ttl_seconds` caps how old `pending_email_requested_at` can be;
     * default 24h.
     */
    public function confirm_pending_email_change(string $token, int $ttl_seconds = DAY_IN_SECONDS): string
    {
        $row = $this->find_by_pending_email_token($token);
        if ($row === null) {
            return 'invalid';
        }
        $new_email = (string) ($row['pending_email'] ?? '');
        $requested_at = (string) ($row['pending_email_requested_at'] ?? '');
        if ($new_email === '' || $requested_at === '') {
            return 'invalid';
        }
        $age = time() - (int) strtotime($requested_at . ' UTC');
        if ($age > $ttl_seconds) {
            // Drop the stale request so the column isn't a lingering
            // ghost for future requests.
            $this->cancel_pending_email_change((int) $row['id']);
            return 'expired';
        }
        // Re-check the email-taken race: someone may have grabbed the
        // address between request + confirm. Excludes the subscriber's
        // own row from the collision check.
        $other = $this->find_by_email($new_email);
        if ($other !== null && (int) $other['id'] !== (int) $row['id']) {
            $this->cancel_pending_email_change((int) $row['id']);
            return 'email_taken';
        }
        global $wpdb;
        $wpdb->update(
            Schema::subscribers_table(),
            [
                'email'                      => $new_email,
                'pending_email'              => '',
                'pending_email_token'        => '',
                'pending_email_requested_at' => null,
            ],
            ['id' => (int) $row['id']],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
        return 'ok';
    }

    /** Drop any pending email-change for a subscriber. Idempotent. */
    public function cancel_pending_email_change(int $id): void
    {
        global $wpdb;
        $wpdb->update(
            Schema::subscribers_table(),
            [
                'pending_email'              => '',
                'pending_email_token'        => '',
                'pending_email_requested_at' => null,
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
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
     * `LIKE '%term%'` match on email + name; empty disables it. `$list_id`
     * > 0 narrows to subscribers that are explicit members of that list
     * (the caller resolves system / all_subscribers / users-kind cases
     * before passing here — `all_subscribers` should pass 0 + force
     * status='confirmed'; users-kind should never reach this method).
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_with_filters(string $status, string $search, int $limit, int $offset, int $list_id = 0): array
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        [$where_sql, $where_args] = self::build_where($status, $search, $list_id);
        $sql = "SELECT * FROM `$table` $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $args = array_merge($where_args, [$limit, $offset]);
        return (array) $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare($sql, ...$args),
            ARRAY_A
        );
    }

    public function count_with_filters(string $status, string $search, int $list_id = 0): int
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        [$where_sql, $where_args] = self::build_where($status, $search, $list_id);
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
     * Sender-side lifetime stat bump, called from the Materializer per
     * recipient row at send time. Increments total_sent +
     * sends_since_engagement, stamps last_sent_at. Idempotent in the sense
     * that it's safe to call twice — counters just rise; the cold filter
     * tolerates both.
     */
    public function bump_send_stats(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        global $wpdb;
        $table = Schema::subscribers_table();
        $wpdb->query($wpdb->prepare(
            "UPDATE `$table`
                SET total_sent = total_sent + 1,
                    sends_since_engagement = sends_since_engagement + 1,
                    last_sent_at = %s
              WHERE id = %d",
            current_time('mysql', true),
            $id
        ));
    }

    /**
     * Engagement bump from the tracking endpoint. Either flag (opened /
     * clicked) increments its lifetime counter; both update
     * last_engagement_at. `reset_cold` decides whether
     * sends_since_engagement zeroes — controlled by the caller (always
     * resets on click, opens only when the admin trusts open signals
     * enough to ignore Apple MPP inflation).
     */
    public function bump_engagement(int $id, bool $opened, bool $clicked, bool $reset_cold): void
    {
        if ($id <= 0 || (!$opened && !$clicked)) {
            return;
        }
        global $wpdb;
        $table = Schema::subscribers_table();
        $sets = [];
        if ($opened) {
            $sets[] = 'total_opened = total_opened + 1';
        }
        if ($clicked) {
            $sets[] = 'total_clicked = total_clicked + 1';
        }
        $sets[] = $wpdb->prepare('last_engagement_at = %s', current_time('mysql', true));
        if ($reset_cold) {
            $sets[] = 'sends_since_engagement = 0';
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE id = %d",
            $id
        ));
    }

    /**
     * Cold-subscribers query — anyone whose sends_since_engagement has
     * climbed past the configured threshold without an engagement reset.
     * Caller paginates with limit + offset.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_cold(int $threshold, int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        $threshold = max(1, $threshold);
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$table`
              WHERE status = 'confirmed' AND sends_since_engagement >= %d
              ORDER BY sends_since_engagement DESC, last_sent_at DESC
              LIMIT %d OFFSET %d",
            $threshold,
            $limit,
            $offset
        ), ARRAY_A);
    }

    public function count_cold(int $threshold): int
    {
        global $wpdb;
        $table = Schema::subscribers_table();
        $threshold = max(1, $threshold);
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$table`
              WHERE status = 'confirmed' AND sends_since_engagement >= %d",
            $threshold
        ));
    }

    /**
     * @return array{0:string,1:array<int,mixed>} `[$where_sql,$args]`
     */
    private static function build_where(string $status, string $search, int $list_id = 0): array
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
        if ($list_id > 0) {
            $members = Schema::list_members_table();
            $clauses[] = "EXISTS (SELECT 1 FROM `$members` lm
                                   WHERE lm.list_id = %d
                                     AND lm.recipient_kind = 'subscriber'
                                     AND lm.recipient_id = " . Schema::subscribers_table() . ".id)";
            $args[] = $list_id;
        }
        return ['WHERE ' . implode(' AND ', $clauses), $args];
    }
}
