<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Send;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;
use LRob\EmailToolkit\Modules\Newsletter\ListRepository;
use LRob\EmailToolkit\Modules\Newsletter\Schema;
use LRob\EmailToolkit\Modules\Newsletter\UserMeta;
use LRob\EmailToolkit\Support\Events;

/**
 * Admin-AJAX endpoints driving the send pipeline from the campaign
 * editor. Two actions:
 *
 *  - lrob_etk_nl_send_tick: materialize-if-needed, run one batch via
 *    SendLoop, return progress JSON. The editor JS loops this until
 *    `remaining === 0` (or the admin closes the page; the Cron path
 *    will pick up where AJAX left off — step 7b).
 *
 *  - lrob_etk_nl_test_send: render the campaign for one or more
 *    test recipients (ad-hoc address / a "test list" / the current
 *    admin) and send via wp_mail. Does NOT write to campaign_recipients
 *    and does NOT touch counters. Header X-Lrob-Etk-Newsletter-Test: 1
 *    flags these so future tracking + logging integrations can exclude
 *    them from stats.
 *
 * One shared nonce action (`lrob_etk_nl_newsletter_send`) gates both.
 * Capability check via Activator::CAPABILITY on every call.
 */
final class SendAjaxController
{
    public const NONCE_ACTION = 'lrob_etk_nl_newsletter_send';

    public const ACTION_TICK      = 'lrob_etk_nl_send_tick';

    public const ACTION_TEST_SEND = 'lrob_etk_nl_test_send';

    public const OPTION_TEST_LIST_ID = 'lrob_etk_nl_test_list_id';

    public const HEADER_TEST = 'X-Lrob-Etk-Newsletter-Test';

    public function __construct(
        private Materializer $materializer,
        private SendLoop $loop,
        private NewsletterRepository $newsletters,
        private ListRepository $lists,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_TICK,      [$this, 'handle_tick']);
        add_action('wp_ajax_' . self::ACTION_TEST_SEND, [$this, 'handle_test_send']);
    }

    public function handle_tick(): void
    {
        $this->guard();
        $newsletter_id = isset($_POST['newsletter_id']) ? (int) wp_unslash((string) $_POST['newsletter_id']) : 0;
        $batch_size = isset($_POST['batch_size']) ? (int) wp_unslash((string) $_POST['batch_size']) : SendLoop::DEFAULT_BATCH;
        if ($newsletter_id <= 0) {
            wp_send_json_error(['message' => __('Missing newsletter id.', 'lrob-email-toolkit')], 400);
        }
        $post = get_post($newsletter_id);
        if (!$post instanceof \WP_Post || $post->post_type !== NewsletterCPT::POST_TYPE) {
            wp_send_json_error(['message' => __('Newsletter not found.', 'lrob-email-toolkit')], 404);
        }

        // First tick may need to materialize. Subsequent ticks are
        // no-ops on the materializer (it short-circuits when rows
        // already exist).
        $this->materializer->materialize($newsletter_id);

        $progress = $this->loop->tick($newsletter_id, $batch_size);
        wp_send_json_success($progress);
    }

    public function handle_test_send(): void
    {
        $this->guard();
        $newsletter_id = isset($_POST['newsletter_id']) ? (int) wp_unslash((string) $_POST['newsletter_id']) : 0;
        $target = isset($_POST['target']) ? sanitize_key(wp_unslash((string) $_POST['target'])) : '';
        if ($newsletter_id <= 0) {
            wp_send_json_error(['message' => __('Missing newsletter id.', 'lrob-email-toolkit')], 400);
        }
        $post = get_post($newsletter_id);
        if (!$post instanceof \WP_Post || $post->post_type !== NewsletterCPT::POST_TYPE) {
            wp_send_json_error(['message' => __('Newsletter not found.', 'lrob-email-toolkit')], 404);
        }

        $recipients = $this->resolve_test_recipients($target);
        if ($recipients === []) {
            wp_send_json_error(['message' => __('No valid test recipient.', 'lrob-email-toolkit')], 400);
        }

        $subject_prefix = '[TEST] ';
        $subject = $subject_prefix . ($post->post_title !== '' ? $post->post_title : __('(no subject)', 'lrob-email-toolkit'));
        $from_name_override = (string) get_post_meta($newsletter_id, NewsletterCPT::META_FROM_NAME_OVERRIDE, true);
        $reply_to = (string) get_post_meta($newsletter_id, NewsletterCPT::META_REPLY_TO_OVERRIDE, true);

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $r) {
            $tokens = NewsletterRenderer::tokens_for_recipient($r['email'], $r['name'], $r['prefs_token']);
            $body = NewsletterRenderer::render($newsletter_id, $tokens);
            if ($body === '' || !is_email($r['email'])) {
                $failed++;
                continue;
            }
            $headers = $this->build_test_headers($newsletter_id, $r['prefs_token'], $from_name_override, $reply_to);
            $ok = (bool) wp_mail($r['email'], $subject, $body, $headers);
            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }
        }

        Events::dispatch('newsletter.test_sent', [
            'newsletter_id' => $newsletter_id,
            'target'      => $target,
            'sent'        => $sent,
            'failed'      => $failed,
        ]);

        wp_send_json_success([
            'sent'   => $sent,
            'failed' => $failed,
            'count'  => count($recipients),
        ]);
    }

    /**
     * Resolve the test-send target into a recipient list. Three kinds:
     *   - 'self'   → the current admin (one recipient).
     *   - 'adhoc'  → admin types an address. Posted as `email`.
     *   - 'list'   → the configured test-list option (a regular list
     *                used as a manual sample group). Posts use the
     *                list members directly.
     *
     * @return array<int, array{email:string, name:string, prefs_token:string}>
     */
    private function resolve_test_recipients(string $target): array
    {
        switch ($target) {
            case 'self':
                $user = wp_get_current_user();
                if (!$user || !is_email($user->user_email)) {
                    return [];
                }
                $token = (string) get_user_meta($user->ID, UserMeta::PREFS_TOKEN, true);
                if ($token === '') {
                    $token = UserMeta::generate_prefs_token();
                    update_user_meta($user->ID, UserMeta::PREFS_TOKEN, $token);
                }
                return [[
                    'email'       => (string) $user->user_email,
                    'name'        => (string) $user->display_name,
                    'prefs_token' => $token,
                ]];

            case 'adhoc':
                $raw = isset($_POST['email']) ? sanitize_email(wp_unslash((string) $_POST['email'])) : '';
                if ($raw === '' || !is_email($raw)) {
                    return [];
                }
                return [[
                    'email'       => $raw,
                    'name'        => '',
                    // No prefs_token for ad-hoc — they're not a real
                    // subscriber. Token-dependent URLs render empty,
                    // which is fine for a test.
                    'prefs_token' => '',
                ]];

            case 'list':
                $list_id = (int) get_option(self::OPTION_TEST_LIST_ID, 0);
                if ($list_id <= 0 || $this->lists->find($list_id) === null) {
                    return [];
                }
                return $this->list_members_as_test_recipients($list_id);
        }
        return [];
    }

    /**
     * @return array<int, array{email:string, name:string, prefs_token:string}>
     */
    private function list_members_as_test_recipients(int $list_id): array
    {
        global $wpdb;
        $subscribers = Schema::subscribers_table();
        $list_members = Schema::list_members_table();
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT s.email, s.name, s.prefs_token
               FROM `$subscribers` s
               INNER JOIN `$list_members` lm
                 ON lm.recipient_kind = %s
                 AND lm.recipient_id = s.id
              WHERE lm.list_id = %d",
            UserMeta::KIND_SUBSCRIBER,
            $list_id
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'email'       => (string) ($r['email'] ?? ''),
                'name'        => (string) ($r['name'] ?? ''),
                'prefs_token' => (string) ($r['prefs_token'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function build_test_headers(int $newsletter_id, string $prefs_token, string $from_name_override, string $reply_to): array
    {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            SendLoop::HEADER_NEWSLETTER_ID . ': ' . (int) $newsletter_id,
            self::HEADER_TEST . ': 1',
        ];
        if ($from_name_override !== '') {
            $headers[] = 'X-Lrob-Etk-From-Name: ' . self::strip_crlf($from_name_override);
        }
        if ($reply_to !== '' && is_email($reply_to)) {
            $headers[] = 'Reply-To: ' . self::strip_crlf($reply_to);
        }
        if ($prefs_token !== '') {
            $unsub_url = add_query_arg('lrob-etk-nl-unsub', $prefs_token, home_url('/'));
            $headers[] = 'List-Unsubscribe: <' . $unsub_url . '>';
            $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        }
        return $headers;
    }

    private static function strip_crlf(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    private function guard(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'lrob-email-toolkit')], 403);
        }
        $nonce = isset($_POST['_nonce']) ? (string) wp_unslash((string) $_POST['_nonce']) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload the page.', 'lrob-email-toolkit')], 400);
        }
    }
}
