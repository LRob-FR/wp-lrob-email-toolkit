<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Send;

use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;
use LRob\EmailToolkit\Modules\Newsletter\Schema;

// Docs: docs/newsletter-internals.md → "Send pipeline / AJAX ↔ Cron handoff"
final class SendCron
{
    public const CRON_HOOK = 'lrob_etk_nl_cron_send_tick';

    public const CRON_INTERVAL_KEY = 'lrob_etk_nl_minute';

    // Surfaced by the cron-health diagnostic panel in the Newsletters admin view.
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
        // Self-heal: catches install-order bug where cron_schedules hadn't fired yet.
        self::schedule();
    }

    /**
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public static function register_interval(array $schedules): array
    {
        if (!isset($schedules[self::CRON_INTERVAL_KEY])) {
            // Plain string: too early for __() per WP 6.7 textdomain rules; label surfaces only in WP Crontrol.
            $schedules[self::CRON_INTERVAL_KEY] = [
                'interval' => 60,
                'display'  => 'Every minute (Newsletter)',
            ];
        }
        return $schedules;
    }

    public static function schedule(): void
    {
        if (wp_next_scheduled(self::CRON_HOOK) !== false) {
            return;
        }
        // Ensure the interval is registered before scheduling (install() may run before register()).
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

    public function handle_tick(): void
    {
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
               INNER JOIN {$wpdb->posts} p
                 ON p.ID = c.post_id
                 AND p.post_status <> 'trash'
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
            $this->materializer->materialize($post_id);
            $this->loop->tick($post_id);
            $remaining_budget--;
            if ($remaining_budget <= 0) {
                return;
            }
        }

        $stale_before = gmdate('Y-m-d H:i:s', time() - self::STALE_THRESHOLD_SECONDS);
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT c.post_id FROM `$table` c
               INNER JOIN {$wpdb->posts} p
                 ON p.ID = c.post_id
                 AND p.post_status <> 'trash'
              WHERE c.status = %s
                AND (c.last_tick_at IS NULL OR c.last_tick_at <= %s)
              ORDER BY c.last_tick_at ASC
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
            $this->materializer->materialize($post_id);
            $this->loop->tick($post_id);
        }
    }
}
