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
}
