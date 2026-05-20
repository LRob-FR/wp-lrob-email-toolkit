<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

/**
 * Aggregate counters for captcha verify() outcomes. Single UPSERT per verify
 * keeps the cost negligible; consumers (dashboard tile, settings counter)
 * read pre-aggregated sums.
 *
 * Stored shape: one row per (day_date, route_key, outcome). day_date is in
 * UTC — matches the convention used by submissions.submitted_at and the
 * email logs table.
 */
final class StatsRepository
{
    public const OUTCOME_PASSED = 'passed';

    public const OUTCOME_FAILED = 'failed';

    public function record(string $route_key, string $outcome): void
    {
        if ($route_key === '' || $outcome === '') {
            return;
        }
        global $wpdb;
        $table = Schema::stats_table();
        $day = gmdate('Y-m-d');
        // Increment the counter atomically. Suppress wpdb output so a stale
        // schema (table not yet migrated) can't leak HTML into the AJAX
        // submit pipeline — submissions writes a row regardless of whether
        // we managed to record stats.
        $suppress_was = $wpdb->suppress_errors(true);
        $show_was = $wpdb->show_errors(false);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `$table` (day_date, route_key, outcome, n)
                 VALUES (%s, %s, %s, 1)
                 ON DUPLICATE KEY UPDATE n = n + 1",
                $day,
                substr($route_key, 0, 80),
                substr($outcome, 0, 20)
            )
        );
        $wpdb->suppress_errors($suppress_was);
        $wpdb->show_errors($show_was);
    }

    /** Sum across the last N days for a given route. */
    public function count_for_route(string $route_key, string $outcome, int $days = 30): int
    {
        if ($route_key === '') {
            return 0;
        }
        global $wpdb;
        $table = Schema::stats_table();
        $cutoff = gmdate('Y-m-d', time() - ($days * DAY_IN_SECONDS));
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(n), 0) FROM `$table`
                 WHERE route_key = %s AND outcome = %s AND day_date >= %s",
                $route_key,
                $outcome,
                $cutoff
            )
        );
    }

    /**
     * Total verify-failures across every route in the window. Used by the
     * dashboard "spam blocked by captcha" tile.
     */
    public function total_failures(int $days = 30): int
    {
        global $wpdb;
        $table = Schema::stats_table();
        $cutoff = gmdate('Y-m-d', time() - ($days * DAY_IN_SECONDS));
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(n), 0) FROM `$table`
                 WHERE outcome = %s AND day_date >= %s",
                self::OUTCOME_FAILED,
                $cutoff
            )
        );
    }

    /**
     * Per-route breakdown for the captcha settings page. Returns one row per
     * route_key seen in the window, with passed/failed/total counters.
     *
     * @return array<string, array{passed:int, failed:int, total:int}>
     */
    public function breakdown_by_route(int $days = 30): array
    {
        global $wpdb;
        $table = Schema::stats_table();
        $cutoff = gmdate('Y-m-d', time() - ($days * DAY_IN_SECONDS));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT route_key, outcome, SUM(n) AS total FROM `$table`
                 WHERE day_date >= %s
                 GROUP BY route_key, outcome",
                $cutoff
            ),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['route_key'];
            $outcome = (string) $row['outcome'];
            $n = (int) $row['total'];
            if (!isset($out[$key])) {
                $out[$key] = ['passed' => 0, 'failed' => 0, 'total' => 0];
            }
            if ($outcome === self::OUTCOME_PASSED) {
                $out[$key]['passed'] += $n;
            } elseif ($outcome === self::OUTCOME_FAILED) {
                $out[$key]['failed'] += $n;
            }
            $out[$key]['total'] += $n;
        }
        return $out;
    }
}
