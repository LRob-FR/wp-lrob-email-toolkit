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

    /** Schema version. Bump on any column change so install() can migrate. */
    private const SCHEMA_VERSION = '2';

    private const VERSION_OPTION = 'lrob_etk_smtp_db_version';

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void
    {
        global $wpdb;

        $installed = get_option(self::VERSION_OPTION);
        if ($installed === self::SCHEMA_VERSION) {
            return;
        }

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
            force_from tinyint(1) NOT NULL DEFAULT 1,
            reply_to_email varchar(190) DEFAULT NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY is_active (is_active),
            KEY is_default (is_default)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
    }

    public static function drop(): void
    {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("DROP TABLE IF EXISTS `$table`");
        delete_option(self::VERSION_OPTION);
    }
}
