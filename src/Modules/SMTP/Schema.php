<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

/**
 * Owns the SMTP module's database schema. Tables are created via dbDelta on
 * module enable and dropped from plugin uninstall.php (or by an explicit reset
 * call). dbDelta requires very specific SQL formatting (two spaces before
 * PRIMARY KEY, lowercase column types, no IF NOT EXISTS) — preserve it.
 */
final class Schema
{
    public const TABLE = 'lrob_etk_identities';

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void
    {
        global $wpdb;

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore — dbDelta SQL is intentionally formatted, not parameterized.
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(50) NOT NULL,
            label varchar(190) NOT NULL,
            transport varchar(10) NOT NULL DEFAULT 'smtp',
            from_email varchar(190) NOT NULL,
            from_name varchar(190) NOT NULL,
            smtp_host varchar(190) NOT NULL DEFAULT '',
            smtp_port smallint(5) unsigned NOT NULL DEFAULT 465,
            smtp_encryption varchar(10) NOT NULL DEFAULT 'tls',
            smtp_username varchar(190) NOT NULL DEFAULT '',
            smtp_password_encrypted text NOT NULL,
            smtp_auth tinyint(1) NOT NULL DEFAULT 1,
            override_mode varchar(20) NOT NULL DEFAULT 'when_default',
            reply_to_email varchar(190) DEFAULT NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            save_attachments tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY is_active (is_active),
            KEY is_default (is_default)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // v2 → v3 migration: replace the force_from bool with a 3-state
        // override_mode column. dbDelta added override_mode above but
        // won't drop force_from; do that explicitly + carry old values
        // across. Idempotent — the column-exists check makes re-runs safe.
        $has_force_from = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'force_from'",
            $table
        ));
        if ($has_force_from > 0) {
            $wpdb->query("UPDATE `$table` SET override_mode = CASE force_from WHEN 1 THEN 'always' ELSE 'never' END");
            $wpdb->query("ALTER TABLE `$table` DROP COLUMN force_from");
        }
    }

    public static function drop(): void
    {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("DROP TABLE IF EXISTS `$table`");
    }
}
