<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

/**
 * Captcha module schema. One table holds identities (= configured credential
 * sets) for hosted providers like hCaptcha or Turnstile. Homemade challenges
 * (MathChallenge, ImageChallenge) don't need rows here — they're stateless
 * and credential-free.
 *
 * dbDelta SQL is intentionally formatted, not parameterized; preserve the
 * two-space indent before PRIMARY KEY and lowercase column types.
 */
final class Schema
{
    public const TABLE = 'lrob_etk_captcha_identities';

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
            provider_slug varchar(50) NOT NULL,
            label varchar(190) NOT NULL,
            credentials_encrypted text NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY provider_slug (provider_slug),
            KEY is_active (is_active)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function drop(): void
    {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DROP TABLE IF EXISTS `$table`");
    }
}
