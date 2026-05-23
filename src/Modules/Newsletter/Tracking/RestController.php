<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Tracking;

use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;
use LRob\EmailToolkit\Modules\Newsletter\Schema;
use LRob\EmailToolkit\Modules\Newsletter\SubscriberRepository;
use LRob\EmailToolkit\Modules\Newsletter\UserMeta;
use LRob\EmailToolkit\Support\Events;
use LRob\EmailToolkit\Support\TrackingToken;

/**
 * Public REST endpoints for the tracking pipeline. Two routes:
 *
 *   GET /lrob-etk/v1/nl/track/img/<token>?n=&r=&a=
 *     Image-load handler. Verifies the HMAC against the URL parameters,
 *     records an `open` event, redirects 302 to the original asset URL
 *     (or serves a 1x1 transparent GIF for purpose='open_pixel'). On
 *     invalid token: serves the GIF anyway so the email client doesn't
 *     surface a broken image — but skips all bookkeeping (no event
 *     row, no counter bump). Don't leak token validity to the client.
 *
 *   GET /lrob-etk/v1/nl/track/click/<token>?n=&r=&l=
 *     Click handler. Verifies the HMAC, records a `click` event +
 *     implicit `open` if none exists for this recipient yet (recovers
 *     signal from image-blocking clients). Redirects 302 to the
 *     original href. On invalid token: 400 — we don't want to become
 *     an open redirect.
 *
 * Counter semantics:
 *   - newsletter_recipients.opens/clicks bumped on every event.
 *   - newsletters.opens_count/clicks_count bumped on every event;
 *     opens_unique/clicks_unique bumped only when the per-recipient
 *     counter was 0 pre-bump. Tiny race window between the SELECT and
 *     UPDATE could overcount uniques by ±1 under burst load —
 *     acceptable for stats-display purposes.
 *   - Subscriber / user lifetime stats: total_opened++ / total_clicked++,
 *     last_engagement_at set. sends_since_engagement resets to 0
 *     on click always; on open only when
 *     `lrob_etk_nl_engagement_counts_opens` is true (default false —
 *     Apple MPP server-side image loads would otherwise poison
 *     cold-detection).
 *
 * IP anonymisation: IPv4 → /24, IPv6 → /48 before storage. User-agent
 * stored only when the newsletter opts in via
 * `_lrob_etk_nl_track_user_agent` post meta.
 */
final class RestController
{
    public const OPTION_ENGAGEMENT_COUNTS_OPENS = 'lrob_etk_nl_engagement_counts_opens';

    /** Per-newsletter meta key (currently opt-in for power users; no admin UI yet). */
    public const META_TRACK_USER_AGENT = '_lrob_etk_nl_track_user_agent';

    /** 35-byte transparent 1x1 GIF — smallest reliable cross-client open pixel. */
    private const TRANSPARENT_GIF =
        "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00"
        . "\x00\x00\x00\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00"
        . "\x01\x00\x01\x00\x00\x02\x01\x44\x00\x3b";

    public function __construct(
        private NewsletterRepository $newsletters,
        private SubscriberRepository $subscribers,
        private AssetRepository $assets,
        private LinkRepository $links,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(Pipeline::REST_NAMESPACE, Pipeline::REST_ROUTE_IMG . '/(?P<token>[A-Za-z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_img'],
            'permission_callback' => '__return_true',
            'args'                => [
                'token' => ['required' => true, 'type' => 'string'],
                'n'     => ['required' => true, 'type' => 'integer'],
                'r'     => ['required' => true, 'type' => 'string'],
                'a'     => ['required' => true, 'type' => 'integer'],
            ],
        ]);

        register_rest_route(Pipeline::REST_NAMESPACE, Pipeline::REST_ROUTE_CLICK . '/(?P<token>[A-Za-z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_click'],
            'permission_callback' => '__return_true',
            'args'                => [
                'token' => ['required' => true, 'type' => 'string'],
                'n'     => ['required' => true, 'type' => 'integer'],
                'r'     => ['required' => true, 'type' => 'string'],
                'l'     => ['required' => true, 'type' => 'integer'],
            ],
        ]);
    }

    public function handle_img(\WP_REST_Request $req): void
    {
        $token = (string) $req->get_param('token');
        $newsletter_id = (int) $req->get_param('n');
        [$kind, $rid] = self::parse_recipient((string) $req->get_param('r'));
        $asset_id = (int) $req->get_param('a');

        $valid = TrackingToken::verify(
            $token,
            TrackingToken::PURPOSE_IMAGE,
            $newsletter_id,
            $kind,
            $rid,
            $asset_id
        );

        if (!$valid) {
            // Don't leak token validity — serve the pixel either way.
            self::send_pixel();
            return;
        }

        $asset = $this->assets->find($newsletter_id, $asset_id);
        $purpose = $asset !== null ? (string) $asset['purpose'] : '';

        // Bookkeeping: insert event + bump per-recipient + per-newsletter
        // + per-subscriber lifetime counters. Always invoked on valid
        // token, regardless of whether the asset row was found (a missing
        // row only affects the redirect target).
        $this->record_open($newsletter_id, $kind, $rid);

        if ($asset === null || $purpose === AssetRepository::PURPOSE_OPEN_PIXEL) {
            self::send_pixel();
            return;
        }
        self::redirect_to((string) $asset['url']);
    }

    public function handle_click(\WP_REST_Request $req): void
    {
        $token = (string) $req->get_param('token');
        $newsletter_id = (int) $req->get_param('n');
        [$kind, $rid] = self::parse_recipient((string) $req->get_param('r'));
        $link_id = (int) $req->get_param('l');

        $valid = TrackingToken::verify(
            $token,
            TrackingToken::PURPOSE_CLICK,
            $newsletter_id,
            $kind,
            $rid,
            $link_id
        );

        if (!$valid) {
            // 400 — don't 302 with a bad signature, that would turn the
            // endpoint into an open redirect.
            self::send_400();
            return;
        }

        $link = $this->links->find($newsletter_id, $link_id);
        if ($link === null) {
            self::send_400();
            return;
        }

        $this->record_click($newsletter_id, $kind, $rid, $link_id, (string) $link['url']);
        self::redirect_to((string) $link['url']);
    }

    /**
     * Record an open event. Inserts a row in tracking_events, bumps the
     * recipient + newsletter + subscriber-lifetime counters, dispatches
     * the `newsletter.tracking.open` event.
     */
    private function record_open(int $newsletter_id, string $kind, int $rid): void
    {
        $is_first = $this->bump_recipient_open($newsletter_id, $kind, $rid);
        $this->bump_newsletter_counters($newsletter_id, opens: 1, opens_unique: $is_first ? 1 : 0);
        $this->insert_event($newsletter_id, $kind, $rid, 'open', '');
        $this->bump_subscriber_lifetime($kind, $rid, opened: true, clicked: false);
        Events::dispatch('newsletter.tracking.opened', [
            'newsletter_id'  => $newsletter_id,
            'recipient_kind' => $kind,
            'recipient_id'   => $rid,
            'first_open'     => $is_first,
        ]);
    }

    private function record_click(int $newsletter_id, string $kind, int $rid, int $link_id, string $url): void
    {
        // Clicks imply opens — if this recipient has no open event
        // yet, synthesise one so opens stay >= clicks.
        $had_open_before = $this->recipient_open_count($newsletter_id, $kind, $rid) > 0;
        if (!$had_open_before) {
            $this->record_open($newsletter_id, $kind, $rid);
        }
        $is_first = $this->bump_recipient_click($newsletter_id, $kind, $rid);
        $this->bump_newsletter_counters($newsletter_id, clicks: 1, clicks_unique: $is_first ? 1 : 0);
        $this->insert_event($newsletter_id, $kind, $rid, 'click', $url);
        $this->bump_subscriber_lifetime($kind, $rid, opened: false, clicked: true);
        Events::dispatch('newsletter.tracking.clicked', [
            'newsletter_id'  => $newsletter_id,
            'recipient_kind' => $kind,
            'recipient_id'   => $rid,
            'link_id'        => $link_id,
            'first_click'    => $is_first,
            'url'            => $url,
        ]);
    }

    private function bump_recipient_open(int $newsletter_id, string $kind, int $rid): bool
    {
        global $wpdb;
        $table = Schema::newsletter_recipients_table();
        // SELECT-then-UPDATE: small race window for the "is first open"
        // detection. Documented in the class-level comment.
        $current = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT opens FROM `$table`
              WHERE newsletter_id = %d AND recipient_kind = %s AND recipient_id = %d
              LIMIT 1",
            $newsletter_id,
            $kind,
            $rid
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE `$table`
                SET opens = opens + 1,
                    last_open_at = %s
              WHERE newsletter_id = %d AND recipient_kind = %s AND recipient_id = %d",
            current_time('mysql', true),
            $newsletter_id,
            $kind,
            $rid
        ));
        return $current === 0;
    }

    private function bump_recipient_click(int $newsletter_id, string $kind, int $rid): bool
    {
        global $wpdb;
        $table = Schema::newsletter_recipients_table();
        $current = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT clicks FROM `$table`
              WHERE newsletter_id = %d AND recipient_kind = %s AND recipient_id = %d
              LIMIT 1",
            $newsletter_id,
            $kind,
            $rid
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE `$table`
                SET clicks = clicks + 1,
                    last_click_at = %s
              WHERE newsletter_id = %d AND recipient_kind = %s AND recipient_id = %d",
            current_time('mysql', true),
            $newsletter_id,
            $kind,
            $rid
        ));
        return $current === 0;
    }

    private function recipient_open_count(int $newsletter_id, string $kind, int $rid): int
    {
        global $wpdb;
        $table = Schema::newsletter_recipients_table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT opens FROM `$table`
              WHERE newsletter_id = %d AND recipient_kind = %s AND recipient_id = %d
              LIMIT 1",
            $newsletter_id,
            $kind,
            $rid
        ));
    }

    /**
     * Aggregate counter bump on the newsletters companion row. Pass 0
     * for the dimensions you don't want to change.
     */
    private function bump_newsletter_counters(
        int $newsletter_id,
        int $opens = 0,
        int $opens_unique = 0,
        int $clicks = 0,
        int $clicks_unique = 0,
    ): void {
        if ($opens === 0 && $opens_unique === 0 && $clicks === 0 && $clicks_unique === 0) {
            return;
        }
        global $wpdb;
        $table = Schema::newsletters_table();
        $wpdb->query($wpdb->prepare(
            "UPDATE `$table`
                SET opens_count = opens_count + %d,
                    opens_unique = opens_unique + %d,
                    clicks_count = clicks_count + %d,
                    clicks_unique = clicks_unique + %d
              WHERE post_id = %d",
            $opens,
            $opens_unique,
            $clicks,
            $clicks_unique,
            $newsletter_id
        ));
    }

    private function insert_event(int $newsletter_id, string $kind, int $rid, string $event_kind, string $url): void
    {
        global $wpdb;
        $store_ua = (bool) get_post_meta($newsletter_id, self::META_TRACK_USER_AGENT, true);
        $wpdb->insert(Schema::tracking_events_table(), [
            'newsletter_id'  => $newsletter_id,
            'recipient_kind' => $kind,
            'recipient_id'   => $rid,
            'kind'           => $event_kind,
            'url'            => substr($url, 0, 500),
            'ip_anon'        => self::anonymise_ip(self::client_ip()),
            'user_agent'     => $store_ua ? substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) : '',
            'occurred_at'    => current_time('mysql', true),
        ], ['%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s']);
    }

    /**
     * Bump the lifetime engagement stats on the recipient's source row
     * (subscribers table or wp_users user_meta). `sends_since_engagement`
     * resets to 0 on click; on open only when the admin opted in via
     * `lrob_etk_nl_engagement_counts_opens` — Apple MPP server-side
     * image loads would otherwise poison cold-detection.
     */
    private function bump_subscriber_lifetime(string $kind, int $rid, bool $opened, bool $clicked): void
    {
        if ($rid <= 0) {
            return;
        }
        $counts_opens = (bool) get_option(self::OPTION_ENGAGEMENT_COUNTS_OPENS, false);
        $reset_cold = $clicked || ($opened && $counts_opens);

        if ($kind === UserMeta::KIND_SUBSCRIBER) {
            $this->subscribers->bump_engagement($rid, opened: $opened, clicked: $clicked, reset_cold: $reset_cold);
            return;
        }
        if ($kind === UserMeta::KIND_USER) {
            self::bump_user_meta_engagement($rid, $opened, $clicked, $reset_cold);
        }
    }

    private static function bump_user_meta_engagement(int $user_id, bool $opened, bool $clicked, bool $reset_cold): void
    {
        if ($opened) {
            $current = (int) get_user_meta($user_id, UserMeta::TOTAL_OPENED, true);
            update_user_meta($user_id, UserMeta::TOTAL_OPENED, $current + 1);
        }
        if ($clicked) {
            $current = (int) get_user_meta($user_id, UserMeta::TOTAL_CLICKED, true);
            update_user_meta($user_id, UserMeta::TOTAL_CLICKED, $current + 1);
        }
        update_user_meta($user_id, UserMeta::LAST_ENGAGEMENT_AT, current_time('mysql', true));
        if ($reset_cold) {
            update_user_meta($user_id, UserMeta::SENDS_SINCE_ENGAGEMENT, 0);
        }
    }

    private static function parse_recipient(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || !str_contains($raw, ':')) {
            return ['', 0];
        }
        [$kind, $id] = explode(':', $raw, 2);
        $kind = sanitize_key($kind);
        if ($kind !== UserMeta::KIND_USER && $kind !== UserMeta::KIND_SUBSCRIBER) {
            return ['', 0];
        }
        return [$kind, (int) $id];
    }

    private static function client_ip(): string
    {
        // No proxy-header trust by default; admin can wire one via
        // server config if they're behind a reverse proxy. Keeps the
        // anonymised value honest in default WP deployments.
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private static function anonymise_ip(string $ip): string
    {
        if ($ip === '') {
            return '';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Truncate to /48 by keeping the first three 16-bit blocks.
            $bin = inet_pton($ip);
            if ($bin !== false) {
                $truncated = substr($bin, 0, 6) . str_repeat("\x00", 10);
                $packed = inet_ntop($truncated);
                if (is_string($packed)) {
                    return $packed;
                }
            }
        }
        return '';
    }

    private static function redirect_to(string $url): void
    {
        // No-cache so the tracking endpoint always fires on subsequent loads.
        nocache_headers();
        wp_redirect(esc_url_raw($url), 302);
        exit;
    }

    private static function send_pixel(): void
    {
        nocache_headers();
        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen(self::TRANSPARENT_GIF));
        echo self::TRANSPARENT_GIF;
        exit;
    }

    private static function send_400(): void
    {
        status_header(400);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid tracking token.';
        exit;
    }
}
