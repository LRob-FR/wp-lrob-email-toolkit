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

        $overrides = self::read_overrides($newsletter_id);

        // Multi-list union: iterate list_ids[], collect every recipient,
        // dedupe by (kind, id). Single-list (legacy) goes through the
        // original code path.
        if ($target_kind === NewsletterCPT::TARGET_KIND_LISTS) {
            $seen = [];
            $recipients = [];
            foreach ($list_ids as $lid) {
                $rs = $this->resolve_recipients(NewsletterCPT::TARGET_KIND_LIST, $lid, $overrides['ignore_optouts']);
                foreach ($rs as $r) {
                    $key = $r['kind'] . ':' . $r['id'];
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $recipients[] = $r;
                }
            }
        } else {
            $recipients = $this->resolve_recipients($target_kind, $list_id, $overrides['ignore_optouts']);
        }

        // Per-newsletter force-include / force-exclude overlays.
        // Excludes win against everything (audience + force-include);
        // includes get fetched even if not in the resolved audience.
        $recipients = self::apply_force_overrides($recipients, $overrides);

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
     * `$ignore_optouts` flips the opt-out / unsubscribed filters off
     * — set by the per-newsletter override toggle for operational
     * communications.
     *
     * @return array<int, array{kind:string, id:int, email:string, name:string, prefs_token:string}>
     */
    private function resolve_recipients(string $target_kind, int $list_id, bool $ignore_optouts = false): array
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
            // Status filter: confirmed-only by default; ignore_optouts
            // widens to include unsubscribed (the ones who explicitly
            // opted out) but never bounced / trashed / refused (those
            // are operational rejections, not user preferences).
            $status_clause = $ignore_optouts
                ? "s.status IN ('confirmed', 'unsubscribed')"
                : "s.status = 'confirmed'";
            $status_clause_flat = $ignore_optouts
                ? "status IN ('confirmed', 'unsubscribed')"
                : "status = 'confirmed'";
            if ($target_kind === NewsletterCPT::TARGET_KIND_LIST && !$is_all_subs_pseudo) {
                $rows = $list_id > 0 ? (array) $wpdb->get_results($wpdb->prepare(
                    "SELECT s.id, s.email, s.name, s.prefs_token
                       FROM `$subscribers_table` s
                       INNER JOIN `$list_members_table` lm
                         ON lm.recipient_kind = %s
                         AND lm.recipient_id = s.id
                      WHERE lm.list_id = %d
                        AND $status_clause",
                    UserMeta::KIND_SUBSCRIBER,
                    $list_id
                ), ARRAY_A) : [];
            } else {
                $rows = (array) $wpdb->get_results(
                    "SELECT id, email, name, prefs_token
                       FROM `$subscribers_table`
                      WHERE $status_clause_flat",
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
            $users = $this->fetch_opted_in_users($target_kind, $list_id, $ignore_optouts);
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
    private function fetch_opted_in_users(string $target_kind, int $list_id, bool $ignore_optouts = false): array
    {
        // WP users are opt-OUT, not opt-in: a user without the
        // OPTED_IN user_meta (every pre-existing site member) counts
        // as eligible. The PrefsHandler explicitly writes '0' when
        // someone opts out; everyone else is in. ignore_optouts drops
        // the meta_query filter entirely so even explicit '0' opt-outs
        // are returned — admin override for operational messages.
        $args = ['fields' => ['ID', 'user_email', 'display_name'], 'number' => -1];
        if (!$ignore_optouts) {
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key'   => UserMeta::OPTED_IN,
                    'value' => '1',
                ],
                [
                    'key'     => UserMeta::OPTED_IN,
                    'compare' => 'NOT EXISTS',
                ],
            ];
        }
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

        // Filter out bounced / refused via user_meta status flag.
        // 'unsubscribed' status survives when ignore_optouts is on
        // (mirrors the subscriber-side status widen above).
        $skip_statuses = $ignore_optouts ? ['bounced', 'refused'] : ['bounced', 'unsubscribed', 'refused'];
        $out = [];
        foreach ($rows as $r) {
            $status = (string) get_user_meta($r['ID'], UserMeta::STATUS, true);
            if (in_array($status, $skip_statuses, true)) {
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
     * WITHOUT inserting into `newsletter_recipients`. Computes the
     * FULL matched audience (ignoring opt-outs) + tracks which IDs
     * are opt-outs, then builds a unified sample where each
     * recipient appears exactly once with two orthogonal flags:
     *
     *   - **was_opted_out** (bool): the user's stated preference,
     *     regardless of bypass. The Show List tabs filter on this.
     *   - **delivery** ('sent'|'skipped'): the actual decision,
     *     factoring in the bypass + force overrides.
     *
     * Plus a `force` flag ('none'|'include'|'exclude') so the row
     * UI can show the per-recipient override is active.
     *
     * @return array{
     *   total:int, by_kind:array<string,int>, opted_out:int,
     *   ignore_optouts:bool,
     *   sample:array<int, array{kind:string, id:int, email:string, name:string, was_opted_out:bool, delivery:string, force:string}>
     * }
     */
    public function preview_recipients(int $newsletter_id, int $sample_limit = 50): array
    {
        $post = get_post($newsletter_id);
        if (!$post instanceof \WP_Post || $post->post_type !== NewsletterCPT::POST_TYPE) {
            return ['total' => 0, 'by_kind' => [], 'opted_out' => 0, 'ignore_optouts' => false, 'sample' => []];
        }
        $target_raw = (string) get_post_meta($newsletter_id, NewsletterCPT::META_TARGET_SPEC, true);
        $target = $target_raw !== '' ? (array) json_decode($target_raw, true) : ['kind' => NewsletterCPT::TARGET_KIND_LISTS, 'list_ids' => []];
        $target_kind = (string) ($target['kind'] ?? NewsletterCPT::TARGET_KIND_LISTS);
        $list_id = isset($target['list_id']) ? (int) $target['list_id'] : 0;
        $list_ids = isset($target['list_ids']) && is_array($target['list_ids'])
            ? array_values(array_filter(array_map('intval', $target['list_ids']), static fn ($n) => $n > 0))
            : [];

        $overrides = self::read_overrides($newsletter_id);

        // Resolve the FULL audience once with ignore_optouts=true:
        // this gives us every matched user/subscriber regardless of
        // opt-out state. We then tag each row with its opt-out
        // status using the canonical opted-out lookup.
        $full = $this->resolve_for_preview($target_kind, $list_id, $list_ids, true);

        // Single indexed query: every WP user with OPTED_IN='0'. Cheap.
        $opted_out_user_ids = (new \LRob\EmailToolkit\Modules\Newsletter\ListRepository())->opted_out_user_ids();
        $optout_set = array_flip($opted_out_user_ids);
        // Subscriber side: 'unsubscribed' status is the opt-out
        // equivalent. Walk the resolved subscribers once to flag them.
        $sub_ids = [];
        foreach ($full as $r) {
            if ($r['kind'] === UserMeta::KIND_SUBSCRIBER) {
                $sub_ids[] = (int) $r['id'];
            }
        }
        $optout_subs = [];
        if ($sub_ids !== []) {
            global $wpdb;
            $table = Schema::subscribers_table();
            $placeholders = implode(',', array_fill(0, count($sub_ids), '%d'));
            $rows = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM `$table` WHERE id IN ($placeholders) AND status = 'unsubscribed'",
                ...$sub_ids
            ));
            $optout_subs = array_flip(array_map('intval', $rows));
        }

        $force_include_keys = [];
        foreach ($overrides['force_include'] as $e) {
            $force_include_keys[$e['kind'] . ':' . $e['id']] = true;
        }
        $force_exclude_keys = [];
        foreach ($overrides['force_exclude'] as $e) {
            $force_exclude_keys[$e['kind'] . ':' . $e['id']] = true;
        }

        // Single pass: tag each row with was_opted_out + delivery + force.
        // Delivery logic:
        //   - force_exclude → skipped (always)
        //   - force_include → sent (always)
        //   - opted_out: sent if bypass on, else skipped
        //   - normal: sent
        $tagged = [];
        $delivered_count = 0;
        $optout_count = 0;
        $by_kind = [UserMeta::KIND_SUBSCRIBER => 0, UserMeta::KIND_USER => 0];

        foreach ($full as $r) {
            $key = $r['kind'] . ':' . $r['id'];
            $was_optout = false;
            if ($r['kind'] === UserMeta::KIND_USER && isset($optout_set[(int) $r['id']])) {
                $was_optout = true;
            }
            if ($r['kind'] === UserMeta::KIND_SUBSCRIBER && isset($optout_subs[(int) $r['id']])) {
                $was_optout = true;
            }
            if ($was_optout) {
                $optout_count++;
            }

            $force = 'none';
            if (isset($force_include_keys[$key])) $force = 'include';
            if (isset($force_exclude_keys[$key])) $force = 'exclude';

            $delivery = 'sent';
            if ($force === 'exclude') {
                $delivery = 'skipped';
            } elseif ($force === 'include') {
                $delivery = 'sent';
            } elseif ($was_optout && !$overrides['ignore_optouts']) {
                $delivery = 'skipped';
            }

            if ($delivery === 'sent') {
                $delivered_count++;
                if (isset($by_kind[$r['kind']])) $by_kind[$r['kind']]++;
            }

            $tagged[] = [
                'kind'          => (string) $r['kind'],
                'id'            => (int) $r['id'],
                'email'         => (string) $r['email'],
                'name'          => (string) ($r['name'] ?? ''),
                'was_opted_out' => $was_optout,
                'delivery'      => $delivery,
                'force'         => $force,
            ];
        }

        // Force-include entries not in the audience need fetching +
        // appending so the sample reflects the override fully.
        $audience_keys = [];
        foreach ($tagged as $t) {
            $audience_keys[$t['kind'] . ':' . $t['id']] = true;
        }
        $missing_includes = [];
        foreach ($overrides['force_include'] as $e) {
            if (!isset($audience_keys[$e['kind'] . ':' . $e['id']])) {
                $missing_includes[] = $e;
            }
        }
        foreach (self::fetch_recipient_details($missing_includes) as $r) {
            $tagged[] = [
                'kind'          => (string) $r['kind'],
                'id'            => (int) $r['id'],
                'email'         => (string) $r['email'],
                'name'          => (string) ($r['name'] ?? ''),
                'was_opted_out' => false,
                'delivery'      => 'sent',
                'force'         => 'include',
            ];
            $delivered_count++;
            if (isset($by_kind[$r['kind']])) $by_kind[$r['kind']]++;
        }

        // Bound the sample. Sort to prioritise: skipped rows (admin
        // wants to see what's NOT being sent) + force-flagged rows
        // first, then the rest.
        usort($tagged, static function ($a, $b) {
            $a_priority = ($a['force'] !== 'none' ? 0 : ($a['delivery'] === 'skipped' ? 1 : 2));
            $b_priority = ($b['force'] !== 'none' ? 0 : ($b['delivery'] === 'skipped' ? 1 : 2));
            return $a_priority <=> $b_priority;
        });
        $sample = array_slice($tagged, 0, max(0, $sample_limit));

        return [
            'total'          => $delivered_count,
            'by_kind'        => $by_kind,
            'opted_out'      => $optout_count,
            'ignore_optouts' => $overrides['ignore_optouts'],
            'sample'         => $sample,
        ];
    }

    /**
     * Shared resolution path for preview_recipients — same shape as
     * materialize() (LISTS branch unions, single-list / all-* go
     * through resolve_recipients directly), with the email-level
     * dedup applied.
     *
     * @param array<int, int> $list_ids
     * @return array<int, array{kind:string, id:int, email:string, name:string, prefs_token:string}>
     */
    private function resolve_for_preview(string $target_kind, int $list_id, array $list_ids, bool $ignore_optouts): array
    {
        if ($target_kind === NewsletterCPT::TARGET_KIND_LISTS) {
            $seen = [];
            $recipients = [];
            foreach ($list_ids as $lid) {
                $rs = $this->resolve_recipients(NewsletterCPT::TARGET_KIND_LIST, $lid, $ignore_optouts);
                foreach ($rs as $r) {
                    $key = $r['kind'] . ':' . $r['id'];
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $recipients[] = $r;
                }
            }
            return self::dedupe_by_email($recipients);
        }
        return self::dedupe_by_email($this->resolve_recipients($target_kind, $list_id, $ignore_optouts));
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

    /**
     * Decode the three opt-out override post_meta keys into a single
     * struct. Defaults are safe-everywhere (no override).
     *
     * @return array{ignore_optouts:bool, force_include:array<int, array{kind:string,id:int}>, force_exclude:array<int, array{kind:string,id:int}>}
     */
    private static function read_overrides(int $newsletter_id): array
    {
        $ignore = !empty(get_post_meta($newsletter_id, NewsletterCPT::META_IGNORE_OPTOUTS, true));
        $include = self::decode_recipient_set((string) get_post_meta($newsletter_id, NewsletterCPT::META_FORCE_INCLUDE_IDS, true));
        $exclude = self::decode_recipient_set((string) get_post_meta($newsletter_id, NewsletterCPT::META_FORCE_EXCLUDE_IDS, true));
        return [
            'ignore_optouts' => $ignore,
            'force_include'  => $include,
            'force_exclude'  => $exclude,
        ];
    }

    /**
     * Tolerate malformed JSON; drop entries missing kind+id.
     * @return array<int, array{kind:string,id:int}>
     */
    private static function decode_recipient_set(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $kind = isset($entry['kind']) ? (string) $entry['kind'] : '';
            $id = isset($entry['id']) ? (int) $entry['id'] : 0;
            if ($id <= 0 || !in_array($kind, [UserMeta::KIND_SUBSCRIBER, UserMeta::KIND_USER], true)) {
                continue;
            }
            $out[] = ['kind' => $kind, 'id' => $id];
        }
        return $out;
    }

    /**
     * Apply the per-newsletter force-include / force-exclude overlays
     * to a resolved recipient list. Excludes are applied first (they
     * win over both audience matches AND force-includes — admin can
     * always say "no, drop X" with no escape hatch). Force-includes
     * then union with whatever survived, fetching recipient details
     * for IDs not already in the audience.
     *
     * @param array<int, array{kind:string, id:int, email:string, name:string, prefs_token:string}> $recipients
     * @param array{ignore_optouts:bool, force_include:array<int, array{kind:string,id:int}>, force_exclude:array<int, array{kind:string,id:int}>} $overrides
     * @return array<int, array{kind:string, id:int, email:string, name:string, prefs_token:string}>
     */
    private function apply_force_overrides(array $recipients, array $overrides): array
    {
        // Index by (kind, id) for O(1) collision checks.
        $by_key = [];
        foreach ($recipients as $r) {
            $by_key[$r['kind'] . ':' . $r['id']] = $r;
        }

        foreach ($overrides['force_exclude'] as $entry) {
            unset($by_key[$entry['kind'] . ':' . $entry['id']]);
        }

        $missing_include = [];
        foreach ($overrides['force_include'] as $entry) {
            $key = $entry['kind'] . ':' . $entry['id'];
            if (isset($by_key[$key])) {
                continue;
            }
            $missing_include[] = $entry;
        }
        $fetched = self::fetch_recipient_details($missing_include);
        foreach ($fetched as $r) {
            $by_key[$r['kind'] . ':' . $r['id']] = $r;
        }

        return array_values($by_key);
    }

    /**
     * Bulk-fetch recipient details for force-include IDs that didn't
     * land in the regular audience query. Issues one query per kind
     * (one to subscribers, one to wp_users) regardless of how many
     * IDs — bounded by admin attention so the IN-list stays small.
     *
     * @param array<int, array{kind:string,id:int}> $entries
     * @return array<int, array{kind:string, id:int, email:string, name:string, prefs_token:string}>
     */
    private static function fetch_recipient_details(array $entries): array
    {
        if ($entries === []) {
            return [];
        }
        $by_kind = ['subscriber' => [], 'user' => []];
        foreach ($entries as $e) {
            if (isset($by_kind[$e['kind']])) {
                $by_kind[$e['kind']][] = (int) $e['id'];
            }
        }
        $out = [];

        if ($by_kind['subscriber'] !== []) {
            global $wpdb;
            $ids = array_values(array_unique($by_kind['subscriber']));
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $table = Schema::subscribers_table();
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT id, email, name, prefs_token FROM `$table`
                  WHERE id IN ($placeholders)
                    AND status NOT IN ('bounced', 'trashed', 'refused')",
                ...$ids
            ), ARRAY_A);
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

        if ($by_kind['user'] !== []) {
            $ids = array_values(array_unique($by_kind['user']));
            $users = get_users([
                'include' => $ids,
                'fields'  => ['ID', 'user_email', 'display_name'],
                'number'  => -1,
            ]);
            foreach (is_array($users) ? $users : [] as $u) {
                $uid = (int) $u->ID;
                $status = (string) get_user_meta($uid, UserMeta::STATUS, true);
                if (in_array($status, ['bounced', 'refused'], true)) {
                    continue;
                }
                $token = (string) get_user_meta($uid, UserMeta::PREFS_TOKEN, true);
                if ($token === '') {
                    $token = UserMeta::generate_prefs_token();
                    update_user_meta($uid, UserMeta::PREFS_TOKEN, $token);
                }
                $out[] = [
                    'kind'        => UserMeta::KIND_USER,
                    'id'          => $uid,
                    'email'       => (string) $u->user_email,
                    'name'        => trim((string) ($u->display_name ?? '')),
                    'prefs_token' => $token,
                ];
            }
        }

        return $out;
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
