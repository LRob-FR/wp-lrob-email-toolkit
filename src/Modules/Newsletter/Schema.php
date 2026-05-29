<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

// Docs: docs/newsletter-internals.md — Schema section
final class Schema
{
    public const TABLE_SUBSCRIBERS           = 'lrob_etk_nl_subscribers';

    public const TABLE_LISTS                 = 'lrob_etk_nl_lists';

    public const TABLE_LIST_MEMBERS          = 'lrob_etk_nl_list_members';

    public const TABLE_LIST_EXCLUSIONS       = 'lrob_etk_nl_list_exclusions';

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

    public static function list_exclusions_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_LIST_EXCLUSIONS;
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

    public static function install(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $subscribers           = self::subscribers_table();
        $lists                 = self::lists_table();
        $list_members          = self::list_members_table();
        $list_exclusions       = self::list_exclusions_table();
        $newsletters           = self::newsletters_table();
        $newsletter_recipients = self::newsletter_recipients_table();
        $tracking_events       = self::tracking_events_table();
        $newsletter_assets     = self::newsletter_assets_table();
        $newsletter_links      = self::newsletter_links_table();

        // varchar(20) for status — dbDelta + MySQL ENUM is unreliable.
        $sql_subscribers = "CREATE TABLE $subscribers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(190) NOT NULL,
            name varchar(190) NOT NULL DEFAULT '',
            first_name varchar(120) NOT NULL DEFAULT '',
            last_name varchar(120) NOT NULL DEFAULT '',
            gender varchar(20) NOT NULL DEFAULT '',
            phone varchar(40) NOT NULL DEFAULT '',
            address_line varchar(190) NOT NULL DEFAULT '',
            address_line2 varchar(190) NOT NULL DEFAULT '',
            address_postcode varchar(20) NOT NULL DEFAULT '',
            address_city varchar(120) NOT NULL DEFAULT '',
            address_region varchar(120) NOT NULL DEFAULT '',
            address_country varchar(2) NOT NULL DEFAULT '',
            language varchar(20) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            previous_status varchar(20) NOT NULL DEFAULT '',
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
            pending_email varchar(190) NOT NULL DEFAULT '',
            pending_email_token varchar(64) NOT NULL DEFAULT '',
            pending_email_requested_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            KEY status (status),
            KEY created_at (created_at),
            KEY prefs_token (prefs_token),
            KEY pending_email_token (pending_email_token),
            KEY pending_followup (status, last_reminder_at),
            KEY cold_subscribers (status, sends_since_engagement)
        ) $charset_collate;";

        $sql_lists = "CREATE TABLE $lists (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            kind varchar(20) NOT NULL DEFAULT 'subscribers',
            is_system tinyint(1) NOT NULL DEFAULT 0,
            visibility varchar(10) NOT NULL DEFAULT 'private',
            description text NOT NULL,
            rule_json longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY kind (kind),
            KEY visibility (visibility)
        ) $charset_collate;";

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

        $sql_list_exclusions = "CREATE TABLE $list_exclusions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            list_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            reason varchar(190) NOT NULL DEFAULT '',
            added_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY list_user (list_id, user_id),
            KEY user_lookup (user_id)
        ) $charset_collate;";

        // status: draft|scheduled|materializing|sending|paused|sent|failed|aborted
        // pause_reason: NULL=user pause, smtp_unhealthy=circuit-breaker
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

        // email_snapshot/name_snapshot freeze identity at materialisation time.
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

        // ip_anon: /24 (IPv4) or /48 (IPv6). UA empty unless META_TRACK_USER_AGENT set.
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

        // purpose: 'content' | 'open_pixel'. UNIQUE on url_hash — idempotent re-renders.
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

        // UNIQUE on url_hash — idempotent re-renders.
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
        dbDelta($sql_list_exclusions);
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
            self::list_exclusions_table(),
            self::newsletters_table(),
            self::newsletter_recipients_table(),
            self::tracking_events_table(),
            self::newsletter_assets_table(),
            self::newsletter_links_table(),
            // Legacy table from pre-v13; harmless if absent.
            $wpdb->prefix . 'lrob_etk_nl_categories',
        ];
        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("DROP TABLE IF EXISTS `$table`");
        }
    }
}
