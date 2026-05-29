<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

// Docs: docs/contact-form.md
// dbDelta rules: two spaces before PRIMARY KEY, lowercase column types, no IF NOT EXISTS.
final class Schema
{
    public const TABLE_SUBMISSIONS = 'lrob_etk_contact_submissions';

    public const TABLE_RATE = 'lrob_etk_contact_rate';

    public const TABLE_FILES = 'lrob_etk_contact_files';

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

    public static function files_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_FILES;
    }

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
            ip_address varchar(45) NOT NULL DEFAULT '',
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

        $files = self::files_table();
        $sql_files = "CREATE TABLE $files (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            submission_id bigint(20) unsigned NOT NULL,
            form_id bigint(20) unsigned NOT NULL,
            field_slug varchar(80) NOT NULL,
            original_name varchar(255) NOT NULL,
            stored_path varchar(500) NOT NULL,
            size_bytes bigint(20) unsigned NOT NULL,
            mime varchar(120) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY submission_id (submission_id),
            KEY form_id (form_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_submissions);
        dbDelta($sql_rate);
        dbDelta($sql_files);
    }

    public static function drop(): void
    {
        global $wpdb;
        $submissions = self::submissions_table();
        $rate = self::rate_table();
        $files = self::files_table();
        $wpdb->query("DROP TABLE IF EXISTS `$submissions`");
        $wpdb->query("DROP TABLE IF EXISTS `$rate`");
        $wpdb->query("DROP TABLE IF EXISTS `$files`");
    }
}
