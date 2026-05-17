<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging;

/**
 * Owns the Logging module's database schema. dbDelta SQL is hand-formatted —
 * two spaces before PRIMARY KEY, lowercase column types, no IF NOT EXISTS.
 *
 * Columns reserved for future modules (campaign_id, recipient_id, message_id,
 * provider) are present from v1 so we don't need a migration once Newsletter
 * and IMAP-save land — they just start populating those columns.
 */
final class Schema
{
    public const TABLE = 'lrob_etk_logs';

    private const SCHEMA_VERSION = '1';

    private const VERSION_OPTION = 'lrob_etk_logging_db_version';

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void
    {
        $installed = get_option(self::VERSION_OPTION);
        if ($installed === self::SCHEMA_VERSION) {
            return;
        }

        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore — dbDelta SQL is intentionally formatted, not parameterized.
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            sent_at datetime DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'sending',
            source varchar(50) NOT NULL DEFAULT 'unknown',
            identity_id bigint(20) unsigned DEFAULT NULL,
            campaign_id bigint(20) unsigned DEFAULT NULL,
            recipient_id bigint(20) unsigned DEFAULT NULL,
            from_email varchar(190) NOT NULL DEFAULT '',
            from_name varchar(190) DEFAULT NULL,
            to_emails text NOT NULL,
            cc_emails text DEFAULT NULL,
            bcc_emails text DEFAULT NULL,
            reply_to varchar(190) DEFAULT NULL,
            subject varchar(500) NOT NULL DEFAULT '',
            body_html longtext DEFAULT NULL,
            body_text longtext DEFAULT NULL,
            headers text DEFAULT NULL,
            attachments text DEFAULT NULL,
            message_id varchar(190) DEFAULT NULL,
            error_message text DEFAULT NULL,
            retry_count int(11) NOT NULL DEFAULT 0,
            imap_saved tinyint(1) NOT NULL DEFAULT 0,
            imap_error text DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status_created (status, created_at),
            KEY source (source),
            KEY created_at (created_at),
            KEY campaign_id (campaign_id),
            KEY identity_id (identity_id)
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
