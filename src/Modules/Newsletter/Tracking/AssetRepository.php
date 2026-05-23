<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Tracking;

use LRob\EmailToolkit\Modules\Newsletter\Schema;

/**
 * Per-newsletter asset registry (every <img src> URL the rewriter
 * encounters in the rendered body). Returns a small per-newsletter
 * `asset_id` (not a global id) so tracking URLs stay short:
 * /track/img/{token}?n=123&r=user:7&a=2 vs `a=18372919`.
 *
 * Idempotent: re-rendering the same body returns the same asset_id
 * for the same URL via the UNIQUE (newsletter_id, url_hash) constraint.
 *
 * Purposes:
 *   - 'content'    — a URL from the actual rendered body.
 *   - 'open_pixel' — the synthetic 1x1 GIF appended when the body had
 *                    zero <img>. Always asset_id=0 for that newsletter.
 */
final class AssetRepository
{
    public const PURPOSE_CONTENT    = 'content';

    public const PURPOSE_OPEN_PIXEL = 'open_pixel';

    public const OPEN_PIXEL_ASSET_ID = 0;

    /**
     * Resolve (or create) the per-newsletter asset_id for a given URL.
     * On collision (concurrent rewriters) re-reads the existing row.
     */
    public function register(int $newsletter_id, string $url, string $purpose = self::PURPOSE_CONTENT): int
    {
        if ($newsletter_id <= 0 || $url === '') {
            return -1;
        }
        global $wpdb;
        $table = Schema::newsletter_assets_table();
        $url_hash = sha1($url);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT asset_id FROM `$table` WHERE newsletter_id = %d AND url_hash = %s LIMIT 1",
            $newsletter_id,
            $url_hash
        ));
        if ($existing !== null) {
            return (int) $existing;
        }

        $next = $purpose === self::PURPOSE_OPEN_PIXEL
            ? self::OPEN_PIXEL_ASSET_ID
            : self::next_id($newsletter_id);

        // INSERT IGNORE so a race between two concurrent rewrites doesn't
        // throw — the second one just re-reads.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `$table`
              (newsletter_id, asset_id, url, purpose, url_hash, created_at)
             VALUES (%d, %d, %s, %s, %s, %s)",
            $newsletter_id,
            $next,
            $url,
            $purpose,
            $url_hash,
            current_time('mysql', true)
        ));

        $final = $wpdb->get_var($wpdb->prepare(
            "SELECT asset_id FROM `$table` WHERE newsletter_id = %d AND url_hash = %s LIMIT 1",
            $newsletter_id,
            $url_hash
        ));
        return $final !== null ? (int) $final : $next;
    }

    /**
     * Find the URL for a given (newsletter_id, asset_id) — used by the
     * /track/img endpoint to redirect the recipient to the real asset.
     * Returns null on unknown asset.
     *
     * @return array{url:string, purpose:string}|null
     */
    public function find(int $newsletter_id, int $asset_id): ?array
    {
        global $wpdb;
        $table = Schema::newsletter_assets_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT url, purpose FROM `$table` WHERE newsletter_id = %d AND asset_id = %d LIMIT 1",
            $newsletter_id,
            $asset_id
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return ['url' => (string) $row['url'], 'purpose' => (string) $row['purpose']];
    }

    private static function next_id(int $newsletter_id): int
    {
        global $wpdb;
        $table = Schema::newsletter_assets_table();
        $max = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(asset_id) FROM `$table` WHERE newsletter_id = %d AND asset_id > %d",
            $newsletter_id,
            self::OPEN_PIXEL_ASSET_ID
        ));
        return $max + 1;
    }
}
