<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Send;

use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;
use LRob\EmailToolkit\Modules\Newsletter\Schema;

/**
 * Safety-net cron for the newsletter send pipeline. Runs every
 * minute, picks up any `status='sending'` newsletter whose
 * `last_tick_at` is stale (>2 min ago), and dispatches one batch
 * through SendLoop.
 *
 * The AJAX path (admin clicked "Send now" and is sitting on the
 * page) drives most sends; this cron exists for the cases where:
 *   - admin closes the browser tab mid-send,
 *   - the network drops,
 *   - or the admin schedules a send and walks away.
 *
 * Stale-window is set to 2 minutes so concurrent AJAX + Cron ticks
 * are unlikely (the AJAX loop runs ~once every 250ms). When they
 * DO race, the SendLoop's claim_batch already flips rows to 'sending'
 * before working on them; the second tick's claim returns rows the
 * first tick already advanced past, so worst-case is a few skipped
 * batches per minute — never duplicates.
 *
 * `wp_schedule_event` is request-driven by default. For production
 * reliability the admin should set up a system cron hitting
 * wp-cron.php (or rely on Action Scheduler if they have it). Pure
 * WP-Cron is fine for low-volume sites; high-volume needs system
 * cron + `DISABLE_WP_CRON`.
 */
final class SendCron
{
    public const CRON_HOOK = 'lrob_etk_nl_cron_send_tick';

    public const CRON_INTERVAL_KEY = 'lrob_etk_nl_minute';

    /**
     * Timestamp (UTC mysql format) of the last completed handle_tick run.
     * Surfaced by the cron-health diagnostic panel on the Newsletters
     * admin view so admins can tell at a glance whether pseudo-cron is
     * actually firing on their site.
     */
    public const OPTION_LAST_TICK = 'lrob_etk_nl_last_cron_tick';

    private const STALE_THRESHOLD_SECONDS = 120;

    private const MAX_NEWSLETTERS_PER_TICK = 5;

    public function __construct(
        private NewsletterRepository $newsletters,
        private Materializer $materializer,
        private SendLoop $loop,
    ) {
    }

    public function register(): void
    {
        add_filter('cron_schedules', [self::class, 'register_interval']);
        add_action(self::CRON_HOOK, [$this, 'handle_tick']);
        // Self-heal: re-schedule if the event somehow isn't in the cron
        // queue. Catches the install-order bug where maybe_migrate() ran
        // BEFORE the cron_schedules filter was added — wp_schedule_event
        // silently rejected the unknown 'lrob_etk_nl_minute' interval
        // and the event never got queued. Idempotent because schedule()
        // checks wp_next_scheduled first.
        self::schedule();
    }

    /**
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public static function register_interval(array $schedules): array
    {
        if (!isset($schedules[self::CRON_INTERVAL_KEY])) {
            $schedules[self::CRON_INTERVAL_KEY] = [
                'interval' => 60,
                'display'  => __('Every minute (Newsletter)', 'lrob-email-toolkit'),
            ];
        }
        return $schedules;
    }

    public static function schedule(): void
    {
        if (wp_next_scheduled(self::CRON_HOOK) !== false) {
            return;
        }
        // Make sure the custom 1-minute interval is known to WP-Cron
        // before we try to schedule with it. The Module's normal boot
        // path also adds this filter in register(), but install() may
        // run *before* register() during a fresh activation / migrate,
        // in which case the filter wasn't on yet and wp_schedule_event
        // would silently fail with an unknown-interval error.
        add_filter('cron_schedules', [self::class, 'register_interval']);
        wp_schedule_event(time() + 60, self::CRON_INTERVAL_KEY, self::CRON_HOOK);
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    /**
     * Cron tick. Two passes:
     *   1. Scheduled newsletters whose scheduled_at has passed →
     *      materialize (flips status to sending) and start the loop.
     *   2. Already-sending newsletters with stale last_tick_at →
     *      continue the loop where AJAX left off.
     *
     * Both passes share the per-tick MAX_NEWSLETTERS_PER_TICK budget.
     */
    public function handle_tick(): void
    {
        // Stamp BEFORE the work so even a partial / fatal-erroring tick
        // still proves the cron is firing; the diagnostic panel needs
        // "did pseudo-cron fire" more than "did the tick finish".
        update_option(self::OPTION_LAST_TICK, current_time('mysql', true), false);

        $remaining_budget = self::MAX_NEWSLETTERS_PER_TICK;

        // 1. Promote scheduled newsletters whose time has arrived.
        global $wpdb;
        $table = Schema::newsletters_table();
        $now = gmdate('Y-m-d H:i:s');
        $scheduled = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT c.post_id FROM `$table` c
               INNER JOIN {$wpdb->postmeta} pm
                 ON pm.post_id = c.post_id
                 AND pm.meta_key = %s
              WHERE c.status = %s
                AND pm.meta_value <> ''
                AND pm.meta_value <= %s
              LIMIT %d",
            NewsletterCPT::META_SCHEDULED_AT,
            NewsletterRepository::STATUS_SCHEDULED,
            $now,
            $remaining_budget
        ));
        foreach ($scheduled as $row) {
            $post_id = (int) ($row->post_id ?? 0);
            if ($post_id <= 0) {
                continue;
            }
            // Materialize flips status sending and resolves recipients.
            // Then run one batch right away — gets the newsletter moving
            // without waiting for the next tick.
            $this->materializer->materialize($post_id);
            $this->loop->tick($post_id);
            $remaining_budget--;
            if ($remaining_budget <= 0) {
                return;
            }
        }

        // 2. Continue sending newsletters whose last_tick_at is stale.
        $stale_before = gmdate('Y-m-d H:i:s', time() - self::STALE_THRESHOLD_SECONDS);
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT post_id FROM `$table`
              WHERE status = %s
                AND (last_tick_at IS NULL OR last_tick_at <= %s)
              ORDER BY last_tick_at ASC
              LIMIT %d",
            NewsletterRepository::STATUS_SENDING,
            $stale_before,
            $remaining_budget
        ));
        foreach ($rows as $row) {
            $post_id = (int) ($row->post_id ?? 0);
            if ($post_id <= 0) {
                continue;
            }
            // Idempotent — short-circuits when rows already exist.
            $this->materializer->materialize($post_id);
            $this->loop->tick($post_id);
        }
    }
}
