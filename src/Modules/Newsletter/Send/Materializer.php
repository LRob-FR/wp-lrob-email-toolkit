<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Send;

use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;
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
        private ?\LRob\EmailToolkit\Modules\Newsletter\SubscriberRepository $subscribers = null,
    ) {
    }

    /**
     * Materialize the recipient set for a newsletter. Returns the
     * resulting total_recipients count. Returns 0 (and is a no-op)
     * when the newsletter already has rows in newsletter_recipients.
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
        // No target meta = no recipients. Default-everyone was confusing;
        // an admin who hasn't picked anything explicitly hasn't picked.
        $target = $target_raw !== '' ? (array) json_decode($target_raw, true) : ['kind' => NewsletterCPT::TARGET_KIND_LISTS, 'list_ids' => []];
        $target_kind = (string) ($target['kind'] ?? NewsletterCPT::TARGET_KIND_LISTS);
        $list_id = isset($target['list_id']) ? (int) $target['list_id'] : 0;
        $list_ids = isset($target['list_ids']) && is_array($target['list_ids'])
            ? array_values(array_filter(array_map('intval', $target['list_ids']), static fn ($n) => $n > 0))
            : [];

        // Multi-list union: iterate list_ids[], collect every recipient,
        // dedupe by (kind, id). Single-list (legacy) goes through the
        // original code path.
        if ($target_kind === NewsletterCPT::TARGET_KIND_LISTS) {
            $seen = [];
            $recipients = [];
            foreach ($list_ids as $lid) {
                $rs = $this->resolve_recipients(NewsletterCPT::TARGET_KIND_LIST, $lid);
                foreach ($rs as $r) {
                    $key = $r['kind'] . ':' . $r['id'];
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $recipients[] = $r;
                }
            }
        } else {
            $recipients = $this->resolve_recipients($target_kind, $list_id);
        }
        // Email-level dedup: when the same email is both a WP user and
        // a subscriber row, the WP user wins (they have a real identity,
        // login, and a stable prefs token). Without this the recipient
        // gets the newsletter twice. Two passes: first collect WP-user
        // emails, then strip duplicate subscribers.
        $recipients = self::dedupe_by_email($recipients);
        $total = $this->insert_recipients($newsletter_id, $recipients);

        // Sender-side lifetime stat bump per recipient. We do this *after*
        // the chunked inserts succeed so a failed materialize doesn't
        // inflate counters. Cheap: total_sent + sends_since_engagement +
        // last_sent_at on the subscriber row OR matching user_meta keys.
        $this->bump_send_lifetime($recipients);

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
     * Resolve the newsletter's target_spec into a flat list of
     * `[kind, id, email, name, prefs_token]` rows ready for insertion.
     *
     * @return array<int, array{kind:string, id:int, email:string, name:string, prefs_token:string}>
     */
    private function resolve_recipients(string $target_kind, int $list_id): array
    {
        global $wpdb;
        $subscribers_table = Schema::subscribers_table();
        $list_members_table = Schema::list_members_table();

        // When targeting a list, read its kind once so subscriber/user
        // sides know to skip themselves. Subscribers lists never resolve
        // users-side; users lists never resolve subscribers-side. This
        // keeps the two list types semantically distinct.
        $list_kind = '';
        if ($target_kind === NewsletterCPT::TARGET_KIND_LIST && $list_id > 0) {
            $list_row = (new \LRob\EmailToolkit\Modules\Newsletter\ListRepository())->find($list_id);
            $list_kind = $list_row !== null ? \LRob\EmailToolkit\Modules\Newsletter\ListRepository::kind_of($list_row) : '';
        }

        $out = [];

        // Subscriber side — when target is ALL / ALL_SUBSCRIBERS, or
        // a LIST whose kind is subscribers (manual membership) OR the
        // pseudo-kind `all_subscribers` (the "All subscribers" system
        // list — resolves to every confirmed subscriber).
        $is_all_subs_pseudo = ($target_kind === NewsletterCPT::TARGET_KIND_LIST
            && $list_kind === \LRob\EmailToolkit\Modules\Newsletter\ListRepository::KIND_ALL_SUBSCRIBERS);
        $want_subscribers = $target_kind === NewsletterCPT::TARGET_KIND_ALL
            || $target_kind === NewsletterCPT::TARGET_KIND_ALL_SUBSCRIBERS
            || $is_all_subs_pseudo
            || ($target_kind === NewsletterCPT::TARGET_KIND_LIST && $list_kind === \LRob\EmailToolkit\Modules\Newsletter\ListRepository::KIND_SUBSCRIBERS);

        if ($want_subscribers) {
            if ($target_kind === NewsletterCPT::TARGET_KIND_LIST && !$is_all_subs_pseudo) {
                $rows = $list_id > 0 ? (array) $wpdb->get_results($wpdb->prepare(
                    "SELECT s.id, s.email, s.name, s.prefs_token
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
                    "SELECT id, email, name, prefs_token
                       FROM `$subscribers_table`
                      WHERE status = 'confirmed'",
                    ARRAY_A
                );
            }
            foreach ($rows as $row) {
                $out[] = [
                    'kind'        => UserMeta::KIND_SUBSCRIBER,
                    'id'          => (int) $row['id'],
                    'email'       => (string) $row['email'],
                    'name'        => (string) ($row['name'] ?? ''),
                    'prefs_token' => (string) ($row['prefs_token'] ?? ''),
                ];
            }
        }

        // WP-user side — ALL / ALL_USERS, or LIST with kind=users.
        $want_users = $target_kind === NewsletterCPT::TARGET_KIND_ALL
            || $target_kind === NewsletterCPT::TARGET_KIND_ALL_USERS
            || ($target_kind === NewsletterCPT::TARGET_KIND_LIST && $list_kind === \LRob\EmailToolkit\Modules\Newsletter\ListRepository::KIND_USERS);

        if ($want_users) {
            $users = $this->fetch_opted_in_users($target_kind, $list_id);
            foreach ($users as $u) {
                $user_id = (int) $u['ID'];
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
        // WP users are opt-OUT, not opt-in: a user without the
        // OPTED_IN user_meta (every pre-existing site member) counts
        // as eligible. The PrefsHandler explicitly writes '0' when
        // someone opts out; everyone else is in.
        $args = [
            'fields'     => ['ID', 'user_email', 'display_name'],
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key'   => UserMeta::OPTED_IN,
                    'value' => '1',
                ],
                [
                    'key'     => UserMeta::OPTED_IN,
                    'compare' => 'NOT EXISTS',
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
            // Users-kind list: rule resolves the audience; legacy
            // recipient_kind='user' rows in list_members are honoured
            // too so v9 sites with mixed entries don't lose them.
            // Exclusions are already applied by resolve_rule_user_ids.
            global $wpdb;
            $list_members_table = Schema::list_members_table();
            $member_ids = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT recipient_id FROM `$list_members_table`
                  WHERE recipient_kind = %s AND list_id = %d",
                UserMeta::KIND_USER,
                $list_id
            ));
            $rule_ids = (new \LRob\EmailToolkit\Modules\Newsletter\ListRepository())->resolve_rule_user_ids($list_id);
            $member_set = array_flip(array_map('intval', array_merge($member_ids, $rule_ids)));
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

    /**
     * Sender-side lifetime stats. Subscribers go through SubscriberRepo;
     * WP users get matching user_meta updates. Skips silently when no
     * SubscriberRepository was injected (back-compat for ad-hoc callers).
     *
     * @param array<int, array{kind:string, id:int, email:string, name:string, prefs_token:string}> $recipients
     */
    private function bump_send_lifetime(array $recipients): void
    {
        if ($recipients === []) {
            return;
        }
        $now = current_time('mysql', true);
        foreach ($recipients as $r) {
            $kind = (string) $r['kind'];
            $rid  = (int) $r['id'];
            if ($rid <= 0) {
                continue;
            }
            if ($kind === UserMeta::KIND_SUBSCRIBER && $this->subscribers !== null) {
                $this->subscribers->bump_send_stats($rid);
                continue;
            }
            if ($kind === UserMeta::KIND_USER) {
                $current_sent  = (int) get_user_meta($rid, UserMeta::TOTAL_SENT, true);
                $current_cold  = (int) get_user_meta($rid, UserMeta::SENDS_SINCE_ENGAGEMENT, true);
                update_user_meta($rid, UserMeta::TOTAL_SENT, $current_sent + 1);
                update_user_meta($rid, UserMeta::SENDS_SINCE_ENGAGEMENT, $current_cold + 1);
                update_user_meta($rid, UserMeta::LAST_SENT_AT, $now);
            }
        }
    }

    /**
     * Dry-run resolution: returns the targeted recipient set
     * WITHOUT inserting into `newsletter_recipients`. For the
     * Recipients-preview modal so admins can verify their target
     * before clicking Send.
     *
     * @return array{total:int, by_kind:array<string,int>, sample:array<int, array{kind:string, email:string, name:string}>}
     */
    public function preview_recipients(int $newsletter_id, int $sample_limit = 50): array
    {
        $post = get_post($newsletter_id);
        if (!$post instanceof \WP_Post || $post->post_type !== NewsletterCPT::POST_TYPE) {
            return ['total' => 0, 'by_kind' => [], 'sample' => []];
        }
        $target_raw = (string) get_post_meta($newsletter_id, NewsletterCPT::META_TARGET_SPEC, true);
        $target = $target_raw !== '' ? (array) json_decode($target_raw, true) : ['kind' => NewsletterCPT::TARGET_KIND_LISTS, 'list_ids' => []];
        $target_kind = (string) ($target['kind'] ?? NewsletterCPT::TARGET_KIND_LISTS);
        $list_id = isset($target['list_id']) ? (int) $target['list_id'] : 0;
        $list_ids = isset($target['list_ids']) && is_array($target['list_ids'])
            ? array_values(array_filter(array_map('intval', $target['list_ids']), static fn ($n) => $n > 0))
            : [];

        if ($target_kind === NewsletterCPT::TARGET_KIND_LISTS) {
            $seen = [];
            $recipients = [];
            foreach ($list_ids as $lid) {
                $rs = $this->resolve_recipients(NewsletterCPT::TARGET_KIND_LIST, $lid);
                foreach ($rs as $r) {
                    $key = $r['kind'] . ':' . $r['id'];
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $recipients[] = $r;
                }
            }
        } else {
            $recipients = $this->resolve_recipients($target_kind, $list_id);
        }
        $recipients = self::dedupe_by_email($recipients);

        $by_kind = [
            UserMeta::KIND_SUBSCRIBER => 0,
            UserMeta::KIND_USER       => 0,
        ];
        foreach ($recipients as $r) {
            $k = (string) $r['kind'];
            if (isset($by_kind[$k])) {
                $by_kind[$k]++;
            }
        }
        $sample = [];
        foreach (array_slice($recipients, 0, max(0, $sample_limit)) as $r) {
            $sample[] = [
                'kind'  => (string) $r['kind'],
                'email' => (string) $r['email'],
                'name'  => (string) ($r['name'] ?? ''),
            ];
        }
        return [
            'total'   => count($recipients),
            'by_kind' => $by_kind,
            'sample'  => $sample,
        ];
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

    /** Extract the domain portion of an email for the throttle column. */
    private static function domain_of(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return '';
        }
        return strtolower(substr($email, $at + 1));
    }

    /**
     * Dedupe a recipient list by email — WP user rows beat subscriber
     * rows for the same email. Same email registered both ways = sent
     * once (to the WP user). Comparison is case-insensitive (emails
     * are normalised lowercase here for the dedup key only; the
     * stored email_snapshot keeps the original casing).
     *
     * @param array<int, array{kind:string, id:int, email:string, name:string, prefs_token:string}> $recipients
     * @return array<int, array{kind:string, id:int, email:string, name:string, prefs_token:string}>
     */
    private static function dedupe_by_email(array $recipients): array
    {
        $by_email = [];
        foreach ($recipients as $r) {
            $key = strtolower(trim((string) ($r['email'] ?? '')));
            if ($key === '') {
                continue;
            }
            if (!isset($by_email[$key])) {
                $by_email[$key] = $r;
                continue;
            }
            // Collision — WP user wins; otherwise keep what we had.
            if ($r['kind'] === UserMeta::KIND_USER
                && $by_email[$key]['kind'] !== UserMeta::KIND_USER) {
                $by_email[$key] = $r;
            }
        }
        return array_values($by_email);
    }
}
