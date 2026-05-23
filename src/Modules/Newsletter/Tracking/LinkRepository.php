<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Tracking;

use LRob\EmailToolkit\Modules\Newsletter\Schema;

/**
 * Per-newsletter link registry (every <a href> URL the rewriter
 * encounters in the rendered body). Same per-newsletter `link_id`
 * pattern as AssetRepository — the tracking URL carries `l=<small int>`
 * rather than a global id.
 *
 * label_snippet stores up to ~180 chars of the anchor's visible text
 * so admin tracking reports can identify links by label, not just URL.
 */
final class LinkRepository
{
    public const LABEL_MAX = 180;

    public function register(int $newsletter_id, string $url, string $label_snippet = ''): int
    {
        if ($newsletter_id <= 0 || $url === '') {
            return -1;
        }
        global $wpdb;
        $table = Schema::newsletter_links_table();
        $url_hash = sha1($url);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT link_id FROM `$table` WHERE newsletter_id = %d AND url_hash = %s LIMIT 1",
            $newsletter_id,
            $url_hash
        ));
        if ($existing !== null) {
            return (int) $existing;
        }

        $next = self::next_id($newsletter_id);
        $label = self::trim_label($label_snippet);

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `$table`
              (newsletter_id, link_id, url, label_snippet, url_hash, created_at)
             VALUES (%d, %d, %s, %s, %s, %s)",
            $newsletter_id,
            $next,
            $url,
            $label,
            $url_hash,
            current_time('mysql', true)
        ));

        $final = $wpdb->get_var($wpdb->prepare(
            "SELECT link_id FROM `$table` WHERE newsletter_id = %d AND url_hash = %s LIMIT 1",
            $newsletter_id,
            $url_hash
        ));
        return $final !== null ? (int) $final : $next;
    }

    /**
     * @return array{url:string, label_snippet:string}|null
     */
    public function find(int $newsletter_id, int $link_id): ?array
    {
        global $wpdb;
        $table = Schema::newsletter_links_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT url, label_snippet FROM `$table` WHERE newsletter_id = %d AND link_id = %d LIMIT 1",
            $newsletter_id,
            $link_id
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return ['url' => (string) $row['url'], 'label_snippet' => (string) $row['label_snippet']];
    }

    private static function next_id(int $newsletter_id): int
    {
        global $wpdb;
        $table = Schema::newsletter_links_table();
        $max = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(link_id) FROM `$table` WHERE newsletter_id = %d",
            $newsletter_id
        ));
        return $max + 1;
    }

    /**
     * Strip tags + collapse whitespace + truncate so the label fits
     * within the column width and stays readable in admin reports.
     */
    private static function trim_label(string $raw): string
    {
        $stripped = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($raw)) ?? '');
        if ($stripped === '') {
            return '';
        }
        if (mb_strlen($stripped) <= self::LABEL_MAX) {
            return $stripped;
        }
        return mb_substr($stripped, 0, self::LABEL_MAX - 1) . '…';
    }
}
