<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Owns the Contact Form module's database schema.
 *
 *  - `lrob_etk_contact_submissions` archives every submission so users can
 *    browse them in admin and so we can correlate them with outbound logs.
 *  - `lrob_etk_contact_rate` tracks per-IP-per-form submission counts within
 *    rolling windows. Pruned daily by RateLimiter::gc(). Transients were
 *    rejected here because they silently disappear on object-cache hosts.
 *
 * dbDelta formatting rules apply (two spaces before PRIMARY KEY, lowercase
 * column types, no IF NOT EXISTS) — preserve them.
 */
final class Schema
{
    public const TABLE_SUBMISSIONS = 'lrob_etk_contact_submissions';

    public const TABLE_RATE = 'lrob_etk_contact_rate';

    public static function submissions_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUBMISSIONS;
    }

    public static function rate_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_RATE;
    }

    /**
     * Idempotent. Versioning is owned by AbstractModule::maybe_migrate via
     * the shared `lrob_etk_contact_form_db_version` option — Schema itself
     * just declares the current shape. dbDelta handles additive upgrades
     * (added columns / new indexes) when this is called on an existing
     * install.
     */
    public static function install(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $submissions = self::submissions_table();
        $rate = self::rate_table();

        $sql_submissions = "CREATE TABLE $submissions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL,
            submitted_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'received',
            ip_hash varchar(64) NOT NULL DEFAULT '',
            user_agent varchar(500) NOT NULL DEFAULT '',
            referer varchar(500) NOT NULL DEFAULT '',
            fields_json longtext NOT NULL,
            log_id bigint(20) unsigned DEFAULT NULL,
            notes text DEFAULT NULL,
            captcha_slug varchar(40) NOT NULL DEFAULT '',
            captcha_outcome varchar(20) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY form_id (form_id),
            KEY status (status),
            KEY submitted_at (submitted_at),
            KEY ip_hash (ip_hash),
            KEY captcha_slug (captcha_slug)
        ) $charset_collate;";

        $sql_rate = "CREATE TABLE $rate (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ip_hash varchar(64) NOT NULL,
            form_id bigint(20) unsigned NOT NULL,
            hit_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY ip_form_time (ip_hash, form_id, hit_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_submissions);
        dbDelta($sql_rate);
    }

    public static function drop(): void
    {
        global $wpdb;
        $submissions = self::submissions_table();
        $rate = self::rate_table();
        $wpdb->query("DROP TABLE IF EXISTS `$submissions`");
        $wpdb->query("DROP TABLE IF EXISTS `$rate`");
    }
}
