<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging;

/**
 * Daily cron event that deletes log entries older than the configured
 * retention period. 0 days means "keep forever". Default: 30 days.
 *
 * The cron hook lives under the lrob_etk_ prefix so Deactivator's prefix-scan
 * cleanup catches it on plugin deactivation.
 */
final class RetentionCron
{
    public const HOOK = 'lrob_etk_logging_purge';

    public const OPTION_RETENTION_DAYS = 'lrob_etk_logging_retention_days';

    public const DEFAULT_RETENTION_DAYS = 365;

    public function __construct(private LogRepository $repository)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public function run(): void
    {
        $days = max(0, (int) get_option(self::OPTION_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS));
        if ($days === 0) {
            return;
        }

        try {
            $cutoff = new \DateTimeImmutable('-' . $days . ' days', new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return;
        }

        $deleted = $this->repository->delete_older_than($cutoff);
        if ($deleted > 0) {
            do_action('lrob_etk_logging_purged', $deleted, $days);
        }
    }
}
