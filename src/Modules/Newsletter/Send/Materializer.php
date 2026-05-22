<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Send;

use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;
use LRob\EmailToolkit\Modules\Newsletter\CategoryRepository;
use LRob\EmailToolkit\Modules\Newsletter\Schema;
use LRob\EmailToolkit\Modules\Newsletter\UserMeta;
use LRob\EmailToolkit\Support\Events;

/**
 * Resolves a newsletter's target_spec into rows in
 * `wp_lrob_etk_nl_newsletter_recipients`. One-time per newsletter —
 * once a row exists for a newsletter in the recipients table,
 * materialize() is a no-op (the existence check uses the
 * (newsletter_id, kind, id) UNIQUE so re-runs are safe but pointless).
 *
 * Filters at materialization time:
 *   - WP users: lrob_etk_nl_opted_in = '1' AND status user_meta NOT in
 *     {bounced, refused, unsubscribed}.
 *   - Subscribers: status = 'confirmed'.
 *   - Both: the newsletter's category slug is NOT in the recipient's
 *     category_opt_outs JSON.
 *   - List target: only members of that list, intersected with the
 *     above filters.
 *
 * Inserts use chunked multi-value INSERTs (50 rows per query) to
 * keep wpdb prepared-statement size bounded on big targets.
 *
 * On success: flips newsletter status to `sending`, sets total_recipients,
 * stamps started_at, fires `newsletter.started`.
 */
final class Materializer
{
    private const INSERT_CHUNK = 50;

    public function __construct(
        private NewsletterRepository $newsletters,
        private CategoryRepository $categories,
    ) {
    }

    /**
     * Materialize the recipient set for a campaign. Returns the
     * resulting total_recipients count. Returns 0 (and is a no-op)
     * when the campaign already has rows in campaign_recipients.
     */
    public function materialize(int $newsletter_id): int
    {
        $post = get_post($newsletter_id);
        if (!$post instanceof \WP_Post || $post->post_type !== NewsletterCPT::POST_TYPE) {
            return 0;
        }
        if ($this->already_materialized($newsletter_id)) {
            $row = $this->newsletters->find_by_post_id($newsletter_id);
            return (int) ($row['total_recipients'] ?? 0);
        }

        $target_raw = (string) get_post_meta($newsletter_id, NewsletterCPT::META_TARGET_SPEC, true);
        $target = $target_raw !== '' ? (array) json_decode($target_raw, true) : ['kind' => NewsletterCPT::TARGET_KIND_ALL];
        $target_kind = (string) ($target['kind'] ?? NewsletterCPT::TARGET_KIND_ALL);
        $list_id = isset($target['list_id']) ? (int) $target['list_id'] : 0;

        $category_id = (int) get_post_meta($newsletter_id, NewsletterCPT::META_CATEGORY_ID, true);
        $category_slug = $this->category_slug($category_id);

        $recipients = $this->resolve_recipients($target_kind, $list_id, $category_slug);
        $total = $this->insert_recipients($newsletter_id, $recipients);

        global $wpdb;
        $wpdb->update(
            Schema::newsletters_table(),
            [
                'status'           => NewsletterRepository::STATUS_SENDING,
                'total_recipients' => $total,
                'started_at'       => current_time('mysql', true),
                'last_tick_at'     => current_time('mysql', true),
            ],
            ['post_id' => $newsletter_id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );

        Events::dispatch('newsletter.started', [
            'newsletter_id'      => $newsletter_id,
            'total_recipients' => $total,
        ]);

        return $total;
    }

    /**
     * Resolve the campaign's target_spec into a flat list of
     * `[kind, id, email, name, prefs_token]` rows ready for insertion.
     *
     * @return array<int, array{kind:string, id:int, email:string, name:string, prefs_token:string}>
     */
    private function resolve_recipients(string $target_kind, int $list_id, string $category_slug): array
    {
        global $wpdb;
        $subscribers_table = Schema::subscribers_table();
        $list_members_table = Schema::list_members_table();

        $out = [];

        // Subscriber side
        if (in_array($target_kind, [
            NewsletterCPT::TARGET_KIND_ALL,
            NewsletterCPT::TARGET_KIND_ALL_SUBSCRIBERS,
            NewsletterCPT::TARGET_KIND_LIST,
        ], true)) {
            if ($target_kind === NewsletterCPT::TARGET_KIND_LIST) {
                $rows = $list_id > 0 ? (array) $wpdb->get_results($wpdb->prepare(
                    "SELECT s.id, s.email, s.name, s.prefs_token, s.category_opt_outs
                       FROM `$subscribers_table` s
                       INNER JOIN `$list_members_table` lm
                         ON lm.recipient_kind = %s
                         AND lm.recipient_id = s.id
                      WHERE lm.list_id = %d
                        AND s.status = 'confirmed'",
                    UserMeta::KIND_SUBSCRIBER,
                    $list_id
                ), ARRAY_A) : [];
            } else {
                $rows = (array) $wpdb->get_results(
                    "SELECT id, email, name, prefs_token, category_opt_outs
                       FROM `$subscribers_table`
                      WHERE status = 'confirmed'",
                    ARRAY_A
                );
            }
            foreach ($rows as $row) {
                if (!self::category_allows((string) ($row['category_opt_outs'] ?? ''), $category_slug)) {
                    continue;
                }
                $out[] = [
                    'kind'        => UserMeta::KIND_SUBSCRIBER,
                    'id'          => (int) $row['id'],
                    'email'       => (string) $row['email'],
                    'name'        => (string) ($row['name'] ?? ''),
                    'prefs_token' => (string) ($row['prefs_token'] ?? ''),
                ];
            }
        }

        // WP-user side
        if (in_array($target_kind, [
            NewsletterCPT::TARGET_KIND_ALL,
            NewsletterCPT::TARGET_KIND_ALL_USERS,
            NewsletterCPT::TARGET_KIND_LIST,
        ], true)) {
            $users = $this->fetch_opted_in_users($target_kind, $list_id);
            foreach ($users as $u) {
                $user_id = (int) $u['ID'];
                $opt_outs_json = (string) get_user_meta($user_id, UserMeta::CATEGORY_OPT_OUTS, true);
                if (!self::category_allows($opt_outs_json, $category_slug)) {
                    continue;
                }
                $token = (string) get_user_meta($user_id, UserMeta::PREFS_TOKEN, true);
                if ($token === '') {
                    $token = UserMeta::generate_prefs_token();
                    update_user_meta($user_id, UserMeta::PREFS_TOKEN, $token);
                }
                $out[] = [
                    'kind'        => UserMeta::KIND_USER,
                    'id'          => $user_id,
                    'email'       => (string) $u['user_email'],
                    'name'        => trim((string) ($u['display_name'] ?? '')),
                    'prefs_token' => $token,
                ];
            }
        }

        return $out;
    }

    /**
     * Pull opted-in WP users (with optional list-membership filter).
     * Uses get_users so the user query respects multisite scoping.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch_opted_in_users(string $target_kind, int $list_id): array
    {
        $args = [
            'fields'     => ['ID', 'user_email', 'display_name'],
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key'   => UserMeta::OPTED_IN,
                    'value' => '1',
                ],
            ],
            'number'     => -1,
        ];
        $users = get_users($args);
        $rows = array_map(static fn ($u) => [
            'ID'           => (int) $u->ID,
            'user_email'   => (string) $u->user_email,
            'display_name' => (string) $u->display_name,
        ], is_array($users) ? $users : []);

        if ($target_kind === NewsletterCPT::TARGET_KIND_LIST && $list_id > 0) {
            // Intersect with list members. Cheaper than joining in SQL
            // because users come from get_users (multisite-aware) and
            // list_members is a custom table.
            global $wpdb;
            $list_members_table = Schema::list_members_table();
            $member_ids = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT recipient_id FROM `$list_members_table`
                  WHERE recipient_kind = %s AND list_id = %d",
                UserMeta::KIND_USER,
                $list_id
            ));
            $member_set = array_flip(array_map('intval', $member_ids));
            $rows = array_filter($rows, static fn ($r) => isset($member_set[$r['ID']]));
        }

        // Filter out bounced / unsubscribed via user_meta status flag.
        $out = [];
        foreach ($rows as $r) {
            $status = (string) get_user_meta($r['ID'], UserMeta::STATUS, true);
            if (in_array($status, ['bounced', 'unsubscribed', 'refused'], true)) {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Chunked INSERT of the resolved recipient list. Returns the
     * count actually inserted (UNIQUE skips deduplicate within a
     * single materialize, so repeat targets don't double-count).
     *
     * @param array<int, array{kind:string, id:int, email:string, name:string, prefs_token:string}> $recipients
     */
    private function insert_recipients(int $newsletter_id, array $recipients): int
    {
        if ($recipients === []) {
            return 0;
        }
        global $wpdb;
        $table = Schema::newsletter_recipients_table();
        $inserted = 0;
        $chunks = array_chunk($recipients, self::INSERT_CHUNK);
        foreach ($chunks as $chunk) {
            $placeholders = [];
            $args = [];
            foreach ($chunk as $r) {
                $placeholders[] = '(%d, %s, %d, %s, %s, %s, %s)';
                $args[] = $newsletter_id;
                $args[] = $r['kind'];
                $args[] = (int) $r['id'];
                $args[] = (string) $r['email'];
                $args[] = (string) $r['name'];
                $args[] = self::domain_of((string) $r['email']);
                $args[] = 'pending';
            }
            $sql = "INSERT IGNORE INTO `$table`
                        (newsletter_id, recipient_kind, recipient_id, email_snapshot, name_snapshot, domain, status)
                    VALUES " . implode(', ', $placeholders);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $affected = $wpdb->query($wpdb->prepare($sql, $args));
            if (is_int($affected)) {
                $inserted += $affected;
            }
        }
        return $inserted;
    }

    private function already_materialized(int $newsletter_id): bool
    {
        global $wpdb;
        $table = Schema::newsletter_recipients_table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE newsletter_id = %d LIMIT 1",
            $newsletter_id
        )) > 0;
    }

    private function category_slug(int $category_id): string
    {
        if ($category_id <= 0) {
            return '';
        }
        $cats = $this->categories->list_all();
        foreach ($cats as $c) {
            if ((int) ($c['id'] ?? 0) === $category_id) {
                return (string) ($c['slug'] ?? '');
            }
        }
        return '';
    }

    /**
     * True when the recipient hasn't opted out of the campaign's
     * category. Empty category_slug = no category filter (campaign
     * doesn't have a category set) → always pass.
     */
    private static function category_allows(string $opt_outs_json, string $category_slug): bool
    {
        if ($category_slug === '') {
            return true;
        }
        $arr = $opt_outs_json !== '' ? (array) json_decode($opt_outs_json, true) : [];
        if (!is_array($arr)) {
            return true;
        }
        return !in_array($category_slug, array_map('strval', $arr), true);
    }

    /** Extract the domain portion of an email for the throttle column. */
    private static function domain_of(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return '';
        }
        return strtolower(substr($email, $at + 1));
    }
}
