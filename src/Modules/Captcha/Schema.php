<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

/**
 * Captcha module schema. Two tables:
 *
 *  - `lrob_etk_captcha_identities` — credentialled provider instances
 *    (hCaptcha / Turnstile / reCAPTCHA). Homemade challenges have no row.
 *  - `lrob_etk_captcha_stats` — pre-aggregated verify counters keyed by
 *    (day_date, route_key, outcome). One UPSERT per verify() call; total
 *    rows stay tiny (≈ routes × outcomes × days). Powers the dashboard
 *    "spam blocked" tile and the per-route counter on the settings page.
 *
 * dbDelta SQL is intentionally formatted, not parameterized; preserve the
 * two-space indent before PRIMARY KEY and lowercase column types.
 */
final class Schema
{
    public const TABLE = 'lrob_etk_captcha_identities';

    public const TABLE_STATS = 'lrob_etk_captcha_stats';

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function stats_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_STATS;
    }

    public static function install(): void
    {
        global $wpdb;

        $table = self::table_name();
        $stats = self::stats_table();
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore — dbDelta SQL is intentionally formatted, not parameterized.
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider_slug varchar(50) NOT NULL,
            label varchar(190) NOT NULL,
            credentials_encrypted text NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            theme varchar(10) NOT NULL DEFAULT 'auto',
            size varchar(10) NOT NULL DEFAULT 'normal',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY provider_slug (provider_slug),
            KEY is_active (is_active)
        ) $charset_collate;";

        // route_key is the same routing-key shape used elsewhere
        // ('homemade:math', 'identity:7'). outcome is 'passed' or 'failed'.
        // Primary key is the natural triple — INSERT ... ON DUPLICATE KEY
        // UPDATE n = n + 1 makes verify() a single-row upsert.
        $sql_stats = "CREATE TABLE $stats (
            day_date date NOT NULL,
            route_key varchar(80) NOT NULL,
            outcome varchar(20) NOT NULL,
            n bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (day_date, route_key, outcome),
            KEY route_key (route_key),
            KEY day_date (day_date)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($sql_stats);
    }

    public static function drop(): void
    {
        global $wpdb;
        $table = self::table_name();
        $stats = self::stats_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DROP TABLE IF EXISTS `$table`");
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DROP TABLE IF EXISTS `$stats`");
    }
}
