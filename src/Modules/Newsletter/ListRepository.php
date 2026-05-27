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
    private const COUNTS_CACHE_KEY = 'lrob_etk_nl_list_counts';

    public const KIND_SUBSCRIBERS      = 'subscribers';
    public const KIND_USERS            = 'users';
    /** Pseudo-kind: virtual list resolving to every confirmed subscriber. System-only. */
    public const KIND_ALL_SUBSCRIBERS  = 'all_subscribers';

    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_PUBLIC  = 'public';

    /** @return array<int, string> Valid kind slugs (extensible-by-filter later). */
    public static function valid_kinds(): array
    {
        return [self::KIND_SUBSCRIBERS, self::KIND_USERS, self::KIND_ALL_SUBSCRIBERS];
    }

    /** @return array<int, string> Valid visibility values. */
    public static function valid_visibilities(): array
    {
        return [self::VISIBILITY_PRIVATE, self::VISIBILITY_PUBLIC];
    }

    public static function visibility_of(array $row): string
    {
        $v = (string) ($row['visibility'] ?? self::VISIBILITY_PRIVATE);
        return in_array($v, self::valid_visibilities(), true) ? $v : self::VISIBILITY_PRIVATE;
    }

    public static function is_public(array $row): bool
    {
        return self::visibility_of($row) === self::VISIBILITY_PUBLIC;
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

    /**
     * Single-query lookup of every WP user explicitly opted-out of
     * newsletter sends (lrob_etk_nl_opted_in='0' user_meta). Used by
     * `member_counts()` to subtract the opt-outs from rule-resolved
     * users-kind list sizes, and by the Materializer / audience
     * preview to flag per-recipient status. Scales linearly with the
     * count of opt-out rows, not with the user total — single
     * indexed usermeta lookup.
     *
     * @return array<int, int> Flat list of opted-out WP user IDs.
     */
    public function opted_out_user_ids(): array
    {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM `{$wpdb->usermeta}`
              WHERE meta_key = %s AND meta_value = '0'",
            UserMeta::OPTED_IN
        ));
        return is_array($rows) ? array_map('intval', $rows) : [];
    }

    /**
     * Per-list member counts. Returns `list_id => count` for every
     * list. Three branches:
     *   - subscribers-kind: explicit `list_members` rows (one query).
     *   - all_subscribers pseudo-kind: confirmed-subscriber total.
     *   - users-kind: manual list_members rows UNION rule-resolved
     *     IDs, deduped, MINUS explicit opt-outs. Rule resolution can
     *     be expensive on big lists (e.g. WooCommerce) — counts are
     *     transient-cached 5 min.
     *
     * @return array<int, int>
     */
    public function member_counts(): array
    {
        $cached = get_transient(self::COUNTS_CACHE_KEY);
        if (is_array($cached) && isset($cached['counts']) && is_array($cached['counts'])) {
            return array_map('intval', $cached['counts']);
        }

        global $wpdb;
        $members = Schema::list_members_table();
        $manual_rows = $wpdb->get_results(
            "SELECT list_id, recipient_id FROM `$members`",
            ARRAY_A
        );
        $manual_by_list = [];
        foreach (is_array($manual_rows) ? $manual_rows : [] as $r) {
            $lid = (int) ($r['list_id'] ?? 0);
            $rid = (int) ($r['recipient_id'] ?? 0);
            if ($lid > 0 && $rid > 0) {
                $manual_by_list[$lid][$rid] = true;
            }
        }

        $subs = Schema::subscribers_table();
        $confirmed = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$subs` WHERE status = 'confirmed'");

        // Opted-out lookup is the same set for every users-kind list;
        // fetch once and reuse. Keep both the flat array (for fast
        // membership tests) AND the flip (for isset() lookups inside
        // the loop — handful of opt-outs typically, so fine memory-wise).
        $opted_out = array_flip($this->opted_out_user_ids());

        $out = [];
        $opted_out_per_list = [];
        foreach ($this->list_all() as $row) {
            $lid = (int) ($row['id'] ?? 0);
            if ($lid <= 0) {
                continue;
            }
            $kind = self::kind_of($row);
            if ($kind === self::KIND_ALL_SUBSCRIBERS) {
                $out[$lid] = $confirmed;
                continue;
            }
            if ($kind === self::KIND_USERS) {
                // Rule-resolved IDs union manual additions, deduped.
                $rule_ids = $this->resolve_rule_user_ids($lid);
                $manual_ids = array_keys($manual_by_list[$lid] ?? []);
                $all_ids = array_unique(array_merge($rule_ids, $manual_ids));
                // Split opted-in vs opted-out so the picker can
                // surface both counts ("N recipients · −M opted out").
                $opted_in = 0;
                $opted_out_n = 0;
                foreach ($all_ids as $uid) {
                    if (isset($opted_out[(int) $uid])) {
                        $opted_out_n++;
                    } else {
                        $opted_in++;
                    }
                }
                $out[$lid] = $opted_in;
                if ($opted_out_n > 0) {
                    $opted_out_per_list[$lid] = $opted_out_n;
                }
                continue;
            }
            // subscribers-kind: manual memberships only.
            $out[$lid] = count($manual_by_list[$lid] ?? []);
        }

        $cache = ['counts' => $out, 'opted_out' => $opted_out_per_list];
        set_transient(self::COUNTS_CACHE_KEY, $cache, 5 * MINUTE_IN_SECONDS);
        return $out;
    }

    /**
     * Returns `list_id => N` for users-kind lists where at least N WP
     * users matched-by-rule are explicitly opted-out. Surfaced next to
     * the count in the audience picker so admins see why their
     * "23-member list" only reaches 21 recipients. Reuses the same
     * 5-min transient as `member_counts()`.
     *
     * @return array<int, int>
     */
    public function opted_out_counts_per_list(): array
    {
        // Prime the cache via member_counts (cheap; that's the cache
        // writer).
        $this->member_counts();
        $cached = get_transient(self::COUNTS_CACHE_KEY);
        if (is_array($cached) && isset($cached['opted_out']) && is_array($cached['opted_out'])) {
            return array_map('intval', $cached['opted_out']);
        }
        return [];
    }

    /**
     * Drop the per-list count cache. Called from every mutator that
     * could change a list's membership — add/remove member, rule edits,
     * delete, exclusions. Subscriber-side status flips (confirm /
     * unsubscribe / trash) bust it too via SubscriberRepository.
     */
    public static function flush_counts_cache(): void
    {
        delete_transient(self::COUNTS_CACHE_KEY);
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

    public function insert(string $name, string $description = '', string $slug = '', string $kind = self::KIND_SUBSCRIBERS, string $visibility = self::VISIBILITY_PRIVATE): int
    {
        global $wpdb;
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        if (!in_array($kind, self::valid_kinds(), true)) {
            $kind = self::KIND_SUBSCRIBERS;
        }
        if (!in_array($visibility, self::valid_visibilities(), true)) {
            $visibility = self::VISIBILITY_PRIVATE;
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
            'visibility'  => $visibility,
            'description' => $description,
            'rule_json'   => '',
            'created_at'  => $now,
            'updated_at'  => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        if ($ok !== false) {
            self::flush_counts_cache();
            return (int) $wpdb->insert_id;
        }
        return 0;
    }

    /**
     * Flip a list's visibility. Refuses system lists (admin-only by
     * design). Returns false on no-op / refusal.
     */
    public function set_visibility(int $id, string $visibility): bool
    {
        if (!in_array($visibility, self::valid_visibilities(), true)) {
            return false;
        }
        $row = $this->find($id);
        if ($row === null || self::is_system($row)) {
            return false;
        }
        global $wpdb;
        $count = $wpdb->update(
            Schema::lists_table(),
            ['visibility' => $visibility, 'updated_at' => current_time('mysql', true)],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
        return $count !== false;
    }

    /**
     * Public-facing list catalogue for the subscriber prefs page.
     * Excludes system lists (computed; not subscriber-toggleable) and
     * users-kind lists (rule-driven; same rationale). Returns
     * subscribers-kind lists explicitly marked visibility=public.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_public_for_subscribers(): array
    {
        global $wpdb;
        $table = Schema::lists_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `$table`
                  WHERE is_system = 0
                    AND kind = %s
                    AND visibility = %s
                  ORDER BY name ASC",
                self::KIND_SUBSCRIBERS,
                self::VISIBILITY_PUBLIC
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
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
        if ($count !== false) {
            self::flush_counts_cache();
        }
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
        if ($count !== false && $count > 0) {
            self::flush_counts_cache();
            return true;
        }
        return false;
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
        self::flush_counts_cache();
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
        self::flush_counts_cache();
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
        self::flush_counts_cache();
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
