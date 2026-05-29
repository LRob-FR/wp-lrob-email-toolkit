<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

// Docs: docs/contact-form.md — transients rejected because object-cache hosts evict them silently.
final class RateLimiter
{
    public const CRON_HOOK = 'lrob_etk_cf_rate_gc';

    public const GC_RETENTION_DAYS = 7;

    public function register(): void
    {
        add_action(self::CRON_HOOK, [$this, 'gc']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 600, 'daily', self::CRON_HOOK);
        }
    }

    public function unregister(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function over_limit(string $ip_hash, int $form_id, int $max, int $window_seconds): bool
    {
        if ($max <= 0 || $window_seconds <= 0 || $ip_hash === '' || $form_id <= 0) {
            return false;
        }
        global $wpdb;
        $table = Schema::rate_table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $window_seconds);

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `$table` WHERE ip_hash = %s AND form_id = %d AND hit_at >= %s",
                $ip_hash,
                $form_id,
                $cutoff
            )
        );
        return $count >= $max;
    }

    public function record(string $ip_hash, int $form_id): void
    {
        if ($ip_hash === '' || $form_id <= 0) {
            return;
        }
        global $wpdb;
        $wpdb->insert(
            Schema::rate_table(),
            [
                'ip_hash' => $ip_hash,
                'form_id' => $form_id,
                'hit_at'  => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%d', '%s']
        );
    }

    public function gc(): void
    {
        global $wpdb;
        $table = Schema::rate_table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::GC_RETENTION_DAYS * 86400));
        $wpdb->query(
            $wpdb->prepare("DELETE FROM `$table` WHERE hit_at < %s", $cutoff)
        );
    }

    // AUTH_KEY salt prevents cross-site hash correlation.
    public static function hash_ip(string $ip): string
    {
        if ($ip === '') {
            return '';
        }
        $salt = defined('AUTH_KEY') && is_string(AUTH_KEY) ? AUTH_KEY : '';
        return hash('sha256', $salt . '|' . $ip);
    }

    // CF-Connecting-IP → X-Real-IP → X-Forwarded-For (first entry) → REMOTE_ADDR.
    public static function client_ip(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'];
        foreach ($headers as $h) {
            if (empty($_SERVER[$h])) {
                continue;
            }
            $candidate = trim(explode(',', (string) $_SERVER[$h])[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '';
    }
}
