<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Daily cron that purges old trashed subscribers when the admin has
 * configured a positive `lrob_etk_nl_trash_auto_purge_days` retention.
 * Default `0` = disabled (trash is kept forever until the admin clicks
 * Empty Trash manually).
 *
 * Each tick loops `purge_old_trash()` in bounded batches until the
 * repository returns 0 — keeps a giant cleanup from blocking the
 * request while still finishing same-day on typical volumes.
 */
final class TrashCron
{
    public const CRON_HOOK = 'lrob_etk_nl_trash_purge';

    public const OPTION_DAYS = 'lrob_etk_nl_trash_auto_purge_days';

    private const DEFAULT_DAYS = 0;

    private const MAX_BATCHES = 20;

    public function __construct(private SubscriberRepository $subscribers)
    {
    }

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
        // Loop in bounded batches so a one-time massive purge doesn't
        // monopolise the cron tick. MAX_BATCHES * batch_limit caps
        // total per-tick deletions; the next daily tick picks up any
        // remainder.
        for ($i = 0; $i < self::MAX_BATCHES; $i++) {
            $deleted = $this->subscribers->purge_old_trash($days);
            if ($deleted === 0) {
                return;
            }
        }
    }
}
