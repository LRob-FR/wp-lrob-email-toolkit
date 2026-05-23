<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Tracking;

use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\UserMeta;
use LRob\EmailToolkit\Support\TrackingToken;

/**
 * Per-recipient tracking rewriter pipeline.
 *
 * Called once per (recipient, newsletter) at send time, after the body
 * has been rendered and tokens substituted. Three rewrite passes:
 *
 *   1. Image rewriter — every `<img src>` gets routed through the
 *      tracking endpoint, so the email client loading the image fires
 *      the open signal. URL registered once per newsletter in
 *      newsletter_assets; the per-recipient token + recipient identity
 *      live in the URL itself (no per-recipient row to maintain).
 *   2. Open-pixel fallback — if the body had zero `<img>` after the
 *      pass, append a 1x1 transparent GIF at the end so the open
 *      signal is at least possible. asset_id is reserved (0) so a
 *      pixel never collides with a real asset.
 *   3. Link rewriter — every `<a href>` (except mailto / tel / anchor /
 *      data-lrob-etk-no-track / prefs URLs) gets the same treatment;
 *      the click endpoint inserts a click event and 302s to the real URL.
 *
 * Per-newsletter tracking toggles (META_TRACK_OPENS / META_TRACK_CLICKS)
 * gate each pass independently. Test sends bypass the pipeline entirely
 * — see SendLoop where the `is_test` flag is wired.
 */
final class Pipeline
{
    public const REST_NAMESPACE = 'lrob-etk/v1';

    public const REST_ROUTE_IMG   = 'nl/track/img';

    public const REST_ROUTE_CLICK = 'nl/track/click';

    /** Marker class so we can skip our own URLs on re-render (defense in depth). */
    private const SKIP_HREF_ATTRS = ['data-lrob-etk-no-track'];

    public function __construct(
        private AssetRepository $assets,
        private LinkRepository $links,
    ) {
    }

    public function rewrite(
        string $html,
        int $newsletter_id,
        string $recipient_kind,
        int $recipient_id
    ): string {
        if ($html === '' || $newsletter_id <= 0 || $recipient_id <= 0) {
            return $html;
        }
        if ($recipient_kind !== UserMeta::KIND_USER && $recipient_kind !== UserMeta::KIND_SUBSCRIBER) {
            return $html;
        }

        $track_opens = self::track_flag($newsletter_id, NewsletterCPT::META_TRACK_OPENS);
        $track_clicks = self::track_flag($newsletter_id, NewsletterCPT::META_TRACK_CLICKS);

        if ($track_opens) {
            $img_count = 0;
            $html = $this->rewrite_images($html, $newsletter_id, $recipient_kind, $recipient_id, $img_count);
            if ($img_count === 0) {
                $html = $this->append_open_pixel($html, $newsletter_id, $recipient_kind, $recipient_id);
            }
        }
        if ($track_clicks) {
            $html = $this->rewrite_links($html, $newsletter_id, $recipient_kind, $recipient_id);
        }
        return $html;
    }

    private function rewrite_images(string $html, int $newsletter_id, string $kind, int $rid, int &$count): string
    {
        // <img …src="URL" …> — case-insensitive, single OR double quote, attrs in any order.
        $pattern = '/<img\b([^>]*?)\bsrc=(["\'])(.*?)\2([^>]*)>/i';
        return (string) preg_replace_callback($pattern, function (array $m) use ($newsletter_id, $kind, $rid, &$count): string {
            $before_src = $m[1];
            $quote      = $m[2];
            $src        = (string) $m[3];
            $after_src  = $m[4];
            if (!self::should_track_src($src)) {
                // Still count it for the open-pixel-fallback decision —
                // a cid:/data: image at least gives some open signal in
                // clients that don't strip them.
                $count++;
                return $m[0];
            }
            $asset_id = $this->assets->register($newsletter_id, $src, AssetRepository::PURPOSE_CONTENT);
            if ($asset_id < 0) {
                $count++;
                return $m[0];
            }
            $tracking = self::build_image_url($newsletter_id, $kind, $rid, $asset_id);
            $count++;
            return '<img' . $before_src . ' src=' . $quote . esc_url($tracking) . $quote . $after_src . '>';
        }, $html);
    }

    private function rewrite_links(string $html, int $newsletter_id, string $kind, int $rid): string
    {
        // <a …href="URL" …>…</a> — non-greedy on inner content. We don't
        // care about nested HTML inside the anchor for the rewrite itself
        // (only the href is replaced + inner is harvested for the label).
        $pattern = '/<a\b([^>]*?)\bhref=(["\'])(.*?)\2([^>]*)>(.*?)<\/a>/is';
        return (string) preg_replace_callback($pattern, function (array $m) use ($newsletter_id, $kind, $rid): string {
            $before_href = $m[1];
            $quote       = $m[2];
            $href        = (string) $m[3];
            $after_href  = $m[4];
            $inner       = (string) $m[5];
            $all_attrs   = $before_href . $after_href;
            if (!self::should_track_href($href, $all_attrs)) {
                return $m[0];
            }
            $link_id = $this->links->register($newsletter_id, $href, $inner);
            if ($link_id < 0) {
                return $m[0];
            }
            $tracking = self::build_click_url($newsletter_id, $kind, $rid, $link_id);
            return '<a' . $before_href . ' href=' . $quote . esc_url($tracking) . $quote . $after_href . '>' . $inner . '</a>';
        }, $html);
    }

    private function append_open_pixel(string $html, int $newsletter_id, string $kind, int $rid): string
    {
        $this->assets->register($newsletter_id, self::open_pixel_marker_url(), AssetRepository::PURPOSE_OPEN_PIXEL);
        $tracking = self::build_image_url($newsletter_id, $kind, $rid, AssetRepository::OPEN_PIXEL_ASSET_ID);
        $img_tag = '<img src="' . esc_url($tracking) . '" alt="" width="1" height="1" border="0" '
            . 'style="display:block;width:1px;height:1px;border:0;outline:none;overflow:hidden;">';
        if (stripos($html, '</body>') !== false) {
            return str_ireplace('</body>', $img_tag . '</body>', $html);
        }
        return $html . $img_tag;
    }

    public static function build_image_url(int $newsletter_id, string $kind, int $rid, int $asset_id): string
    {
        $token = TrackingToken::sign(TrackingToken::PURPOSE_IMAGE, $newsletter_id, $kind, $rid, $asset_id);
        $base = rest_url(self::REST_NAMESPACE . '/' . self::REST_ROUTE_IMG . '/' . $token);
        return add_query_arg([
            'n' => (int) $newsletter_id,
            'r' => $kind . ':' . (int) $rid,
            'a' => (int) $asset_id,
        ], $base);
    }

    public static function build_click_url(int $newsletter_id, string $kind, int $rid, int $link_id): string
    {
        $token = TrackingToken::sign(TrackingToken::PURPOSE_CLICK, $newsletter_id, $kind, $rid, $link_id);
        $base = rest_url(self::REST_NAMESPACE . '/' . self::REST_ROUTE_CLICK . '/' . $token);
        return add_query_arg([
            'n' => (int) $newsletter_id,
            'r' => $kind . ':' . (int) $rid,
            'l' => (int) $link_id,
        ], $base);
    }

    private static function should_track_src(string $src): bool
    {
        $src = trim($src);
        if ($src === '') {
            return false;
        }
        if (stripos($src, 'cid:') === 0 || stripos($src, 'data:') === 0) {
            return false;
        }
        if (self::is_own_tracking_url($src)) {
            return false;
        }
        return true;
    }

    private static function should_track_href(string $href, string $all_attrs): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }
        $lower = strtolower($href);
        if (str_starts_with($lower, 'mailto:') || str_starts_with($lower, 'tel:')) {
            return false;
        }
        if (str_starts_with($href, '#')) {
            return false;
        }
        // Don't double-rewrite our own tracking URLs.
        if (self::is_own_tracking_url($href)) {
            return false;
        }
        // Preferences / unsubscribe URLs carry the recipient's prefs_token
        // — rewriting them through the click endpoint would break the
        // List-Unsubscribe one-click flow and leak the token into the
        // event log. Skip anything carrying the well-known query keys.
        if (stripos($href, 'lrob-etk-nl-prefs=') !== false
            || stripos($href, 'lrob-etk-nl-unsub=') !== false) {
            return false;
        }
        // Explicit opt-out marker on the anchor element.
        foreach (self::SKIP_HREF_ATTRS as $attr) {
            if (stripos($all_attrs, $attr) !== false) {
                return false;
            }
        }
        return true;
    }

    private static function is_own_tracking_url(string $url): bool
    {
        return stripos($url, '/' . self::REST_ROUTE_IMG . '/') !== false
            || stripos($url, '/' . self::REST_ROUTE_CLICK . '/') !== false;
    }

    /**
     * Synthetic URL used as the open-pixel asset row's stored URL so
     * the UNIQUE (newsletter_id, url_hash) doesn't collide with any
     * real asset URL.
     */
    private static function open_pixel_marker_url(): string
    {
        return 'lrob-etk-internal://open-pixel';
    }

    /**
     * Read the per-newsletter tracking toggle. Default is enabled when
     * the meta is unset (= newsletter created before the toggle existed).
     */
    private static function track_flag(int $newsletter_id, string $meta_key): bool
    {
        $raw = get_post_meta($newsletter_id, $meta_key, true);
        if ($raw === '' || $raw === null) {
            return true;
        }
        return (bool) $raw;
    }
}
