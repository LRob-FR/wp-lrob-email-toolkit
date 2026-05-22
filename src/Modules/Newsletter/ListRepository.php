<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * CRUD + membership helpers for newsletter lists. Lists are the
 * unified manual / rule-based subscriber groupings described in
 * newsletter.md. Step 4a ships manual lists only — rule_json stays
 * empty for everything created through the admin UI. Rule editor +
 * rule evaluator land with the send pipeline when targeting actually
 * matters (newsletters ship later).
 *
 * Memberships live in wp_lrob_etk_nl_list_members keyed by
 * (list_id, recipient_kind, recipient_id). UserHooks::on_deleted_user
 * already cleans up rows for deleted WP users — SubscriberRepository::
 * delete cleans up subscriber-side rows likewise.
 */
final class ListRepository
{
    /** @return array<int, array<string, mixed>> Most-recently-created first. */
    public function list_all(): array
    {
        global $wpdb;
        $table = Schema::lists_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT * FROM `$table` ORDER BY created_at ASC",
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $table = Schema::lists_table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `$table` WHERE id = %d", $id),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public function count_total(): int
    {
        global $wpdb;
        $table = Schema::lists_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
    }

    /** @return array<int, int> list_id values the given (kind, id) recipient belongs to. */
    public function memberships_for_recipient(string $recipient_kind, int $recipient_id): array
    {
        global $wpdb;
        $table = Schema::list_members_table();
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT list_id FROM `$table` WHERE recipient_kind = %s AND recipient_id = %d",
                $recipient_kind,
                $recipient_id
            )
        );
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    public function insert(string $name, string $description = '', string $slug = ''): int
    {
        global $wpdb;
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $slug = $slug !== '' ? sanitize_title($slug) : $this->generate_unique_slug($name);
        if ($slug === '' || $this->slug_exists($slug)) {
            return 0;
        }
        $now = current_time('mysql', true);
        $ok = $wpdb->insert(Schema::lists_table(), [
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description,
            'rule_json'   => '',
            'created_at'  => $now,
            'updated_at'  => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s']);
        return $ok !== false ? (int) $wpdb->insert_id : 0;
    }

    public function rename(int $id, string $name): bool
    {
        global $wpdb;
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        $count = $wpdb->update(
            Schema::lists_table(),
            ['name' => $name, 'updated_at' => current_time('mysql', true)],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
        return $count !== false && $count > 0;
    }

    /** Delete a list and all its membership rows. */
    public function delete(int $id): bool
    {
        global $wpdb;
        $wpdb->delete(Schema::list_members_table(), ['list_id' => $id], ['%d']);
        $count = $wpdb->delete(Schema::lists_table(), ['id' => $id], ['%d']);
        return $count !== false && $count > 0;
    }

    /**
     * Add a recipient to a list. Idempotent — relies on the UNIQUE
     * KEY (list_id, recipient_kind, recipient_id), silently no-ops
     * if the row already exists.
     */
    public function add_member(int $list_id, string $recipient_kind, int $recipient_id): void
    {
        global $wpdb;
        // INSERT IGNORE is the cleanest way to be idempotent against
        // the UNIQUE key. wpdb doesn't expose IGNORE so we use a raw
        // prepare; the placeholders make it safe.
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO `' . Schema::list_members_table() . '` '
            . '(list_id, recipient_kind, recipient_id, added_at) VALUES (%d, %s, %d, %s)',
            $list_id,
            $recipient_kind,
            $recipient_id,
            current_time('mysql', true)
        ));
    }

    /** Detach a recipient from a list. */
    public function remove_member(int $list_id, string $recipient_kind, int $recipient_id): void
    {
        global $wpdb;
        $wpdb->delete(
            Schema::list_members_table(),
            [
                'list_id'        => $list_id,
                'recipient_kind' => $recipient_kind,
                'recipient_id'   => $recipient_id,
            ],
            ['%d', '%s', '%d']
        );
    }

    /** Drop all of a (kind, id) recipient's memberships — used at subscriber-deletion time. */
    public function detach_recipient(string $recipient_kind, int $recipient_id): void
    {
        global $wpdb;
        $wpdb->delete(
            Schema::list_members_table(),
            [
                'recipient_kind' => $recipient_kind,
                'recipient_id'   => $recipient_id,
            ],
            ['%s', '%d']
        );
    }

    private function slug_exists(string $slug): bool
    {
        global $wpdb;
        $table = Schema::lists_table();
        $hit = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM `$table` WHERE slug = %s LIMIT 1", $slug)
        );
        return $hit !== null;
    }

    private function generate_unique_slug(string $name): string
    {
        $base = sanitize_title($name);
        if ($base === '') {
            return '';
        }
        $candidate = $base;
        $n = 2;
        while ($this->slug_exists($candidate)) {
            $candidate = $base . '-' . $n;
            $n++;
        }
        return $candidate;
    }
}
