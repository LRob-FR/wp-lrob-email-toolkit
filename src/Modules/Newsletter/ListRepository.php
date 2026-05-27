<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * CRUD + membership helpers for newsletter lists. Lists are unified
 * manual / rule-based subscriber groupings. Ships with manual lists
 * only — rule_json stays empty for everything created through the
 * admin UI. Rule editor + rule evaluator land later (see todo.md
 * "subscriber custom fields + tags" + "WooCommerce integration"
 * which both extend the rule grammar).
 *
 * Memberships live in wp_lrob_etk_nl_list_members keyed by
 * (list_id, recipient_kind, recipient_id). UserHooks::on_deleted_user
 * already cleans up rows for deleted WP users — SubscriberRepository::
 * delete cleans up subscriber-side rows likewise.
 */
final class ListRepository
{
    public const KIND_SUBSCRIBERS      = 'subscribers';
    public const KIND_USERS            = 'users';
    /** Pseudo-kind: virtual list resolving to every confirmed subscriber. System-only. */
    public const KIND_ALL_SUBSCRIBERS  = 'all_subscribers';

    /** @return array<int, string> Valid kind slugs (extensible-by-filter later). */
    public static function valid_kinds(): array
    {
        return [self::KIND_SUBSCRIBERS, self::KIND_USERS, self::KIND_ALL_SUBSCRIBERS];
    }

    public static function kind_label(string $kind): string
    {
        return match ($kind) {
            self::KIND_SUBSCRIBERS     => __('Subscribers list', 'lrob-email-toolkit'),
            self::KIND_USERS           => __('WP users list', 'lrob-email-toolkit'),
            self::KIND_ALL_SUBSCRIBERS => __('All subscribers', 'lrob-email-toolkit'),
            default                    => $kind,
        };
    }

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

    /**
     * Bulk variant for table views: one query, returns
     * `recipient_id => [list_id, list_id, …]`. Empty `$ids` returns `[]`
     * without hitting the DB (so callers can call unconditionally).
     *
     * @param array<int, int> $ids
     * @return array<int, array<int, int>>
     */
    public function memberships_for_recipients(string $recipient_kind, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, static fn ($v) => (int) $v > 0))));
        if ($ids === []) {
            return [];
        }
        global $wpdb;
        $table = Schema::list_members_table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT recipient_id, list_id FROM `$table`
                  WHERE recipient_kind = %s
                    AND recipient_id IN ($placeholders)",
                array_merge([$recipient_kind], $ids)
            ),
            ARRAY_A
        );
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $rid = (int) ($row['recipient_id'] ?? 0);
            $lid = (int) ($row['list_id'] ?? 0);
            if ($rid > 0 && $lid > 0) {
                $out[$rid][] = $lid;
            }
        }
        return $out;
    }

    public function insert(string $name, string $description = '', string $slug = '', string $kind = self::KIND_SUBSCRIBERS): int
    {
        global $wpdb;
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        if (!in_array($kind, self::valid_kinds(), true)) {
            $kind = self::KIND_SUBSCRIBERS;
        }
        $slug = $slug !== '' ? sanitize_title($slug) : $this->generate_unique_slug($name);
        if ($slug === '' || $this->slug_exists($slug)) {
            return 0;
        }
        $now = current_time('mysql', true);
        $ok = $wpdb->insert(Schema::lists_table(), [
            'name'        => $name,
            'slug'        => $slug,
            'kind'        => $kind,
            'description' => $description,
            'rule_json'   => '',
            'created_at'  => $now,
            'updated_at'  => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        return $ok !== false ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Replace the list's rule_json column. Pass `''` to clear (turns
     * the list back into pure-manual). Caller is responsible for the
     * provider lookup + sanitisation (RuleRegistry::get → sanitize_config
     * → wp_json_encode); this method just persists the resulting blob.
     */
    public function update_rule(int $id, string $rule_json): bool
    {
        global $wpdb;
        $count = $wpdb->update(
            Schema::lists_table(),
            ['rule_json' => $rule_json, 'updated_at' => current_time('mysql', true)],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
        return $count !== false;
    }

    /**
     * Decode rule_json into `['provider' => slug, 'config' => array]` or
     * `null` when the list has no rule (manual-only). Tolerant: malformed
     * JSON → null, missing keys → null.
     *
     * @return array{provider:string, config:array<string,mixed>}|null
     */
    public static function decode_rule(string $rule_json): ?array
    {
        if ($rule_json === '') {
            return null;
        }
        $decoded = json_decode($rule_json, true);
        if (!is_array($decoded)) {
            return null;
        }
        $provider = isset($decoded['provider']) ? (string) $decoded['provider'] : '';
        $config = isset($decoded['config']) && is_array($decoded['config']) ? $decoded['config'] : [];
        if ($provider === '') {
            return null;
        }
        return ['provider' => $provider, 'config' => $config];
    }

    /**
     * Resolve the WP user IDs matching this list's rule, or `[]` when
     * the list has no rule or the named provider isn't registered.
     * Manual subscriber/user memberships are NOT folded in here — the
     * Materializer unions the two sets at send time.
     *
     * @return array<int, int>
     */
    public function resolve_rule_user_ids(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            return [];
        }
        $rule = self::decode_rule((string) ($row['rule_json'] ?? ''));
        if ($rule === null) {
            return [];
        }
        $provider = Lists\RuleRegistry::get($rule['provider']);
        if ($provider === null) {
            return [];
        }
        $ids = $provider->resolve_user_ids($provider->sanitize_config($rule['config']));
        $exclusions = $this->list_exclusions($id);
        if ($exclusions !== []) {
            $excl_set = array_flip(array_map('intval', $exclusions));
            $ids = array_values(array_filter($ids, static fn ($uid) => !isset($excl_set[(int) $uid])));
        }
        return $ids;
    }

    /** @return string `subscribers` | `users` (`subscribers` if missing or invalid). */
    public static function kind_of(array $row): string
    {
        $k = (string) ($row['kind'] ?? self::KIND_SUBSCRIBERS);
        return in_array($k, self::valid_kinds(), true) ? $k : self::KIND_SUBSCRIBERS;
    }

    public function rename(int $id, string $name): bool
    {
        global $wpdb;
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        // `$wpdb->update` returns 0 when the row exists but no value
        // changed (e.g. re-saving the same name) — that's still a
        // success from the admin's POV. Treat any non-`false` as ok.
        $result = $wpdb->update(
            Schema::lists_table(),
            ['name' => $name, 'updated_at' => current_time('mysql', true)],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
        return $result !== false;
    }

    /**
     * Delete a list and all its membership + exclusion rows. Refuses
     * to act on system (built-in) lists — those are seeded by the
     * module and must round-trip across upgrades.
     */
    public function delete(int $id): bool
    {
        $row = $this->find($id);
        if ($row !== null && (int) ($row['is_system'] ?? 0) === 1) {
            return false;
        }
        global $wpdb;
        $wpdb->delete(Schema::list_members_table(),    ['list_id' => $id], ['%d']);
        $wpdb->delete(Schema::list_exclusions_table(), ['list_id' => $id], ['%d']);
        $count = $wpdb->delete(Schema::lists_table(), ['id' => $id], ['%d']);
        return $count !== false && $count > 0;
    }

    public static function is_system(array $row): bool
    {
        return (int) ($row['is_system'] ?? 0) === 1;
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
        // Also drop user exclusions when a WP user is deleted.
        if ($recipient_kind === 'user') {
            $wpdb->delete(Schema::list_exclusions_table(), ['user_id' => $recipient_id], ['%d']);
        }
    }

    /**
     * Exclude a WP user from a list (the rule may match them, but the
     * Materializer skips them). Idempotent via the UNIQUE key.
     */
    public function add_exclusion(int $list_id, int $user_id, string $reason = ''): void
    {
        if ($list_id <= 0 || $user_id <= 0) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO `' . Schema::list_exclusions_table() . '` '
            . '(list_id, user_id, reason, added_at) VALUES (%d, %d, %s, %s)',
            $list_id,
            $user_id,
            $reason,
            current_time('mysql', true)
        ));
    }

    public function remove_exclusion(int $list_id, int $user_id): void
    {
        if ($list_id <= 0 || $user_id <= 0) {
            return;
        }
        global $wpdb;
        $wpdb->delete(Schema::list_exclusions_table(), [
            'list_id' => $list_id,
            'user_id' => $user_id,
        ], ['%d', '%d']);
    }

    /** @return array<int, int> WP user IDs excluded from $list_id */
    public function list_exclusions(int $list_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT user_id FROM `' . Schema::list_exclusions_table() . '` WHERE list_id = %d',
            $list_id
        ));
        return is_array($rows) ? array_map('intval', $rows) : [];
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
