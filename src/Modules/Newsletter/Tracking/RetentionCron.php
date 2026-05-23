<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Tracking;

use LRob\EmailToolkit\Modules\Newsletter\Schema;

/**
 * Daily cron that prunes old tracking_events rows. Companion-row
 * aggregate counters (opens_count, clicks_count, opens_unique,
 * clicks_unique on the newsletters companion + per-subscriber lifetime
 * stats) are kept forever — only the per-event detail (one row per open
 * / click / unsubscribe) ages out.
 *
 * Retention window: `lrob_etk_nl_tracking_retention_days` (default 365).
 * Setting it to 0 disables pruning entirely.
 *
 * Each tick loops chunked DELETE … LIMIT 5000 up to MAX_BATCHES times,
 * keeping a one-time large cleanup from blocking a tick while still
 * finishing in a reasonable number of days on typical volumes.
 */
final class RetentionCron
{
    public const CRON_HOOK = 'lrob_etk_nl_tracking_retention';

    public const OPTION_DAYS = 'lrob_etk_nl_tracking_retention_days';

    private const DEFAULT_DAYS = 365;

    private const MAX_BATCHES = 20;

    private const BATCH_LIMIT = 5000;

    public function register(): void
    {
        add_action(self::CRON_HOOK, [$this, 'handle_tick']);
    }

    public static function schedule(): void
    {
        if (wp_next_scheduled(self::CRON_HOOK) === false) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    public function handle_tick(): void
    {
        $days = (int) get_option(self::OPTION_DAYS, self::DEFAULT_DAYS);
        if ($days <= 0) {
            return;
        }
        global $wpdb;
        $table = Schema::tracking_events_table();
        $threshold = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        for ($i = 0; $i < self::MAX_BATCHES; $i++) {
            $deleted = (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM `$table`
                  WHERE occurred_at <= %s
                  LIMIT %d",
                $threshold,
                self::BATCH_LIMIT
            ));
            if ($deleted === 0) {
                return;
            }
        }
    }
}
