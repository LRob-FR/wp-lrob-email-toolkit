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

    public function __construct(
        private SubmissionRepository $repository,
        private ?FileRepository $files = null,
    ) {
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
        // Per-status retention. 0 days = disabled (kept forever) — the
        // admin opt-in is explicit per category via the auto-cleanup
        // checkbox in the submissions UI.
        $by_status = [
            SubmissionRepository::STATUS_DELIVERED    => Settings::retention_delivered_days(),
            SubmissionRepository::STATUS_RECEIVED     => Settings::retention_received_days(),
            SubmissionRepository::STATUS_FAILED       => Settings::retention_failed_days(),
            SubmissionRepository::STATUS_SPAM_BLOCKED => Settings::retention_spam_days(),
        ];

        $deleted_non_spam = 0;
        $deleted_spam = 0;
        foreach ($by_status as $status => $days) {
            if ($days <= 0) {
                continue;
            }
            try {
                $cutoff = new \DateTimeImmutable('-' . $days . ' days', new \DateTimeZone('UTC'));
                $this->purge_attached_files($this->repository->list_ids_by_status_older_than($status, $cutoff));
                $deleted = $this->repository->delete_by_status_older_than($status, $cutoff);
            } catch (\Exception) {
                // bad interval string — sanitizer should catch upstream, stay quiet.
                continue;
            }
            if ($status === SubmissionRepository::STATUS_SPAM_BLOCKED) {
                $deleted_spam += $deleted;
            } else {
                $deleted_non_spam += $deleted;
            }
        }

        if (($deleted_non_spam + $deleted_spam) > 0) {
            do_action('lrob_etk_cf_submissions_purged', $deleted_non_spam, $deleted_spam);
        }
    }

    /** @param list<int> $submission_ids */
    private function purge_attached_files(array $submission_ids): void
    {
        if ($this->files === null || $submission_ids === []) {
            return;
        }
        foreach ($submission_ids as $id) {
            $this->files->delete_by_submission($id);
        }
    }
}
