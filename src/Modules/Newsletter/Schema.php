<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Newsletter module schema. Seven tables — see newsletter.md for the full
 * shape rationale; this file is the canonical SQL source of truth.
 *
 *  - subscribers ........ email-only recipients (no WP account). WP users
 *                         are recipients via user_meta, never duplicated
 *                         in this table.
 *  - lists .............. unified manual + rule-based groupings.
 *  - list_members ....... explicit junction (list_id, recipient).
 *  - categories ......... email categories, required per newsletter. "General"
 *                         is seeded on install.
 *  - newsletters ........ companion table keyed by newsletter post_id; holds
 *                         hot runtime counters (status, sent_count,
 *                         opens_count, …) off the postmeta hot path.
 *  - newsletter_recipients  per-send recipient state. The send loop's primary
 *                         working set; designed for chunked materialization
 *                         and crash-safe AJAX↔Cron handoff.
 *  - tracking_events .... open/click/unsubscribe timeline. IP anonymised
 *                         to /24 (IPv4) or /48 (IPv6) before insertion;
 *                         retention cron prunes by occurred_at.
 *
 * Enum-like columns are stored as `varchar(20)` rather than MySQL `ENUM` —
 * dbDelta's ENUM parsing is unreliable across MySQL versions and the app
 * layer constrains values anyway.
 *
 * dbDelta formatting rules apply (two spaces before PRIMARY KEY, lowercase
 * column types, no IF NOT EXISTS) — preserve them.
 */
final class Schema
{
    public const TABLE_SUBSCRIBERS           = 'lrob_etk_nl_subscribers';

    public const TABLE_LISTS                 = 'lrob_etk_nl_lists';

    public const TABLE_LIST_MEMBERS          = 'lrob_etk_nl_list_members';

    public const TABLE_CATEGORIES            = 'lrob_etk_nl_categories';

    public const TABLE_NEWSLETTERS           = 'lrob_etk_nl_newsletters';

    public const TABLE_NEWSLETTER_RECIPIENTS = 'lrob_etk_nl_newsletter_recipients';

    public const TABLE_TRACKING_EVENTS       = 'lrob_etk_nl_tracking_events';

    public const TABLE_NEWSLETTER_ASSETS     = 'lrob_etk_nl_newsletter_assets';

    public const TABLE_NEWSLETTER_LINKS      = 'lrob_etk_nl_newsletter_links';

    public static function subscribers_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUBSCRIBERS;
    }

    public static function lists_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_LISTS;
    }

    public static function list_members_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_LIST_MEMBERS;
    }

    public static function categories_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_CATEGORIES;
    }

    public static function newsletters_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NEWSLETTERS;
    }

    public static function newsletter_recipients_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NEWSLETTER_RECIPIENTS;
    }

    public static function tracking_events_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_TRACKING_EVENTS;
    }

    public static function newsletter_assets_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NEWSLETTER_ASSETS;
    }

    public static function newsletter_links_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NEWSLETTER_LINKS;
    }

    /**
     * Idempotent. Versioning is owned by AbstractModule::maybe_migrate via
     * the shared `lrob_etk_newsletter_db_version` option — Schema itself
     * just declares the current shape. dbDelta handles additive upgrades
     * when this is re-called on an existing install.
     */
    public static function install(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $subscribers           = self::subscribers_table();
        $lists                 = self::lists_table();
        $list_members          = self::list_members_table();
        $categories            = self::categories_table();
        $newsletters           = self::newsletters_table();
        $newsletter_recipients = self::newsletter_recipients_table();
        $tracking_events       = self::tracking_events_table();
        $newsletter_assets     = self::newsletter_assets_table();
        $newsletter_links      = self::newsletter_links_table();

        // status enum: pending | confirmed | unsubscribed | refused | bounced | trashed
        // (varchar instead of MySQL ENUM — dbDelta + ENUM is flaky).
        // category_opt_outs is JSON-encoded array of category slugs.
        // reminder_count + last_reminder_at drive the pending-followup
        // cron: stops after the configurable max + spaces messages out
        // by the configured interval.
        // Lifetime engagement columns (added schema v8):
        //   total_sent/_opened/_clicked — cumulative counters bumped at
        //     send-materialise time and tracking-event time.
        //   last_sent_at — UTC mysql, set when a recipient row materialises.
        //   last_engagement_at — UTC mysql, set when the recipient opens
        //     (if engagement_counts_opens=true) or clicks (always).
        //   sends_since_engagement — increments on every materialise,
        //     resets to 0 on engagement. Cold-detection compares this to
        //     `lrob_etk_nl_cold_threshold`.
        // Mirror keys exist on WP-user user_meta (UserMeta::*) so a
        // unified cold-list query covers both populations.
        $sql_subscribers = "CREATE TABLE $subscribers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(190) NOT NULL,
            name varchar(190) NOT NULL DEFAULT '',
            language varchar(20) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            previous_status varchar(20) NOT NULL DEFAULT '',
            category_opt_outs longtext NOT NULL,
            prefs_token varchar(64) NOT NULL,
            source varchar(50) NOT NULL DEFAULT '',
            bounce_count smallint unsigned NOT NULL DEFAULT 0,
            reminder_count smallint unsigned NOT NULL DEFAULT 0,
            last_reminder_at datetime DEFAULT NULL,
            confirmed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            trashed_at datetime DEFAULT NULL,
            trashed_reason varchar(190) NOT NULL DEFAULT '',
            total_sent int unsigned NOT NULL DEFAULT 0,
            total_opened int unsigned NOT NULL DEFAULT 0,
            total_clicked int unsigned NOT NULL DEFAULT 0,
            sends_since_engagement smallint unsigned NOT NULL DEFAULT 0,
            last_sent_at datetime DEFAULT NULL,
            last_engagement_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            KEY status (status),
            KEY created_at (created_at),
            KEY prefs_token (prefs_token),
            KEY pending_followup (status, last_reminder_at),
            KEY cold_subscribers (status, sends_since_engagement)
        ) $charset_collate;";

        // rule_json: JSON filter expression (see newsletter.md "Rule grammar")
        // when the list is rule-based or hybrid; empty string for manual-only
        // lists.
        $sql_lists = "CREATE TABLE $lists (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            description text NOT NULL,
            rule_json longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";

        // recipient_kind: 'user' | 'subscriber'. recipient_id is wp_users.ID
        // or subscribers.id depending on kind. The composite UNIQUE prevents
        // adding the same recipient to the same list twice; the recipient
        // lookup KEY supports the deleted_user cleanup hook.
        $sql_list_members = "CREATE TABLE $list_members (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            list_id bigint(20) unsigned NOT NULL,
            recipient_kind varchar(20) NOT NULL,
            recipient_id bigint(20) unsigned NOT NULL,
            added_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY list_recipient (list_id, recipient_kind, recipient_id),
            KEY recipient_lookup (recipient_kind, recipient_id)
        ) $charset_collate;";

        $sql_categories = "CREATE TABLE $categories (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            description text NOT NULL,
            sort_order smallint NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";

        // Keyed by post_id (1:1 with the lrob_etk_newsletter CPT post).
        // Hot counters live here so updating sent_count++ per recipient
        // doesn't touch the wp_postmeta hot path.
        // status: draft | scheduled | materializing | sending | paused | sent | failed | aborted
        // pause_reason: NULL (user-initiated pause) | smtp_unhealthy (circuit
        //   breaker tripped on consecutive SMTP failures) | other future codes.
        $sql_newsletters = "CREATE TABLE $newsletters (
            post_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            pause_reason varchar(50) DEFAULT NULL,
            total_recipients int unsigned NOT NULL DEFAULT 0,
            sent_count int unsigned NOT NULL DEFAULT 0,
            failed_count int unsigned NOT NULL DEFAULT 0,
            skipped_count int unsigned NOT NULL DEFAULT 0,
            opens_count int unsigned NOT NULL DEFAULT 0,
            clicks_count int unsigned NOT NULL DEFAULT 0,
            opens_unique int unsigned NOT NULL DEFAULT 0,
            clicks_unique int unsigned NOT NULL DEFAULT 0,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            last_tick_at datetime DEFAULT NULL,
            PRIMARY KEY  (post_id),
            KEY status (status)
        ) $charset_collate;";

        // Send-loop working set. The composite (newsletter_id, status, domain)
        // KEY supports the batch-claim query
        //   UPDATE … SET status='sending', tick_id=?
        //   WHERE status='pending' AND newsletter_id=? AND domain=?
        //   LIMIT N
        // which is the inner loop of the per-domain throttle. snapshot
        // columns (email_snapshot, name_snapshot) freeze the recipient's
        // identity at materialization time so a rename mid-send doesn't
        // break logging.
        $sql_newsletter_recipients = "CREATE TABLE $newsletter_recipients (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            newsletter_id bigint(20) unsigned NOT NULL,
            recipient_kind varchar(20) NOT NULL,
            recipient_id bigint(20) unsigned NOT NULL,
            email_snapshot varchar(190) NOT NULL,
            name_snapshot varchar(190) NOT NULL DEFAULT '',
            domain varchar(100) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            sent_at datetime DEFAULT NULL,
            failure_code varchar(80) NOT NULL DEFAULT '',
            opens smallint unsigned NOT NULL DEFAULT 0,
            last_open_at datetime DEFAULT NULL,
            clicks smallint unsigned NOT NULL DEFAULT 0,
            last_click_at datetime DEFAULT NULL,
            unsubscribed_via_email tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY newsletter_recipient (newsletter_id, recipient_kind, recipient_id),
            KEY domain_pending (newsletter_id, domain, status),
            KEY status (status)
        ) $charset_collate;";

        // kind: 'open' | 'click' | 'unsubscribe'. ip_anon is truncated /24 (v4) or /48 (v6).
        // user_agent stays empty unless the send opts in to UA storage.
        // Retention cron prunes rows older than the configured window using
        // chunked DELETE … LIMIT to keep tx size bounded on big tables.
        $sql_tracking_events = "CREATE TABLE $tracking_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            newsletter_id bigint(20) unsigned NOT NULL,
            recipient_kind varchar(20) NOT NULL,
            recipient_id bigint(20) unsigned NOT NULL,
            kind varchar(20) NOT NULL,
            url varchar(500) NOT NULL DEFAULT '',
            ip_anon varchar(45) NOT NULL DEFAULT '',
            user_agent varchar(500) NOT NULL DEFAULT '',
            occurred_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY newsletter_kind (newsletter_id, kind),
            KEY occurred_at (occurred_at),
            KEY recipient (recipient_kind, recipient_id)
        ) $charset_collate;";

        // Side-table per newsletter: every distinct media URL appearing
        // in the rendered body, keyed by (newsletter_id, asset_id). The
        // tracking endpoint resolves the asset_id back to the URL to
        // redirect to. purpose='open_pixel' is the synthetic 1x1 GIF
        // appended when the body had no <img>; everything else is
        // 'content'. UNIQUE on (newsletter_id, url) so re-rendering the
        // same body finds the same asset_id (idempotent registration).
        $sql_newsletter_assets = "CREATE TABLE $newsletter_assets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            newsletter_id bigint(20) unsigned NOT NULL,
            asset_id int unsigned NOT NULL,
            url varchar(2048) NOT NULL,
            purpose varchar(20) NOT NULL DEFAULT 'content',
            url_hash char(40) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY newsletter_asset (newsletter_id, asset_id),
            UNIQUE KEY newsletter_url (newsletter_id, url_hash)
        ) $charset_collate;";

        // Side-table per newsletter: every distinct <a href> appearing
        // in the rendered body. label_snippet stores a short preview of
        // the anchor's text so admins can recognise links in tracking
        // reports. UNIQUE on (newsletter_id, url) for the same idempotent-
        // rewrite reason.
        $sql_newsletter_links = "CREATE TABLE $newsletter_links (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            newsletter_id bigint(20) unsigned NOT NULL,
            link_id int unsigned NOT NULL,
            url varchar(2048) NOT NULL,
            label_snippet varchar(190) NOT NULL DEFAULT '',
            url_hash char(40) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY newsletter_link (newsletter_id, link_id),
            UNIQUE KEY newsletter_url (newsletter_id, url_hash)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_subscribers);
        dbDelta($sql_lists);
        dbDelta($sql_list_members);
        dbDelta($sql_categories);
        dbDelta($sql_newsletters);
        dbDelta($sql_newsletter_recipients);
        dbDelta($sql_tracking_events);
        dbDelta($sql_newsletter_assets);
        dbDelta($sql_newsletter_links);
    }

    public static function drop(): void
    {
        global $wpdb;
        $tables = [
            self::subscribers_table(),
            self::lists_table(),
            self::list_members_table(),
            self::categories_table(),
            self::newsletters_table(),
            self::newsletter_recipients_table(),
            self::tracking_events_table(),
            self::newsletter_assets_table(),
            self::newsletter_links_table(),
        ];
        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("DROP TABLE IF EXISTS `$table`");
        }
    }
}
