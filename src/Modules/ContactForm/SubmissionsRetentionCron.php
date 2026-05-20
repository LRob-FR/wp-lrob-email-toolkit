<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Daily cron that purges old contact-form submissions. Two retention
 * windows — delivered/received/failed rows and spam_blocked rows — are
 * configured independently because their useful lifetimes differ:
 * deliveries are the audit trail (user defaults to keeping forever);
 * spam churns fast and is mostly useful for short-term forensics
 * (90-day default).
 *
 * Hook name uses the lrob_etk_ prefix so the Deactivator's prefix-scan
 * unhooks it cleanly on module/plugin deactivation.
 */
final class SubmissionsRetentionCron
{
    public const HOOK = 'lrob_etk_cf_submissions_purge';

    public function __construct(private SubmissionRepository $repository)
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
        $delivered_days = Settings::retention_delivered_days();
        $spam_days = Settings::retention_spam_days();

        $deleted_non_spam = 0;
        if ($delivered_days > 0) {
            try {
                $cutoff = new \DateTimeImmutable('-' . $delivered_days . ' days', new \DateTimeZone('UTC'));
                $deleted_non_spam = $this->repository->delete_non_spam_older_than($cutoff);
            } catch (\Exception) {
                // bad interval string — should be caught by Settings sanitizer, but stay quiet
            }
        }

        $deleted_spam = 0;
        if ($spam_days > 0) {
            try {
                $cutoff = new \DateTimeImmutable('-' . $spam_days . ' days', new \DateTimeZone('UTC'));
                $deleted_spam = $this->repository->delete_spam_older_than($cutoff);
            } catch (\Exception) {
                // same
            }
        }

        if (($deleted_non_spam + $deleted_spam) > 0) {
            do_action('lrob_etk_cf_submissions_purged', $deleted_non_spam, $deleted_spam);
        }
    }
}
