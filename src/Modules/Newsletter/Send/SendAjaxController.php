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
 * Admin-AJAX endpoints driving the send pipeline from each newsletter
 * card. The shipped endpoints are:
 *
 *  - lrob_etk_nl_send_tick: materialize-if-needed, run one batch via
 *    SendLoop, return progress JSON. newsletter-cards.js loops this
 *    until `remaining === 0` (or the admin closes the tab; SendCron
 *    picks up where AJAX left off).
 *
 *  - lrob_etk_nl_test_send: render the newsletter for one or more
 *    test recipients (ad-hoc address / a "test list" / the current
 *    admin) and send via wp_mail. Does NOT write to newsletter_recipients
 *    and does NOT touch counters. Header X-Lrob-Etk-Newsletter-Test: 1
 *    flags these so future tracking + logging integrations can exclude
 *    them from stats.
 *
 *  - lrob_etk_nl_send_{pause,resume,abort}: flip companion status.
 *  - lrob_etk_nl_preview: render the body as the current admin and
 *    return HTML for the preview modal.
 *  - lrob_etk_nl_recipients_preview: snapshot if available, dry-run
 *    materialization otherwise — powers the Show-list modal.
 *
 * One shared nonce action (`lrob_etk_nl_newsletter_send`) gates them.
 * Capability check via Activator::CAPABILITY on every call.
 */
final class SendAjaxController
{
    public const NONCE_ACTION = 'lrob_etk_nl_newsletter_send';

    public const ACTION_TICK      = 'lrob_etk_nl_send_tick';

    public const ACTION_TEST_SEND = 'lrob_etk_nl_test_send';

    public const ACTION_PAUSE  = 'lrob_etk_nl_send_pause';

    public const ACTION_RESUME = 'lrob_etk_nl_send_resume';

    public const ACTION_ABORT  = 'lrob_etk_nl_send_abort';

    public const ACTION_RETRY_FAILED = 'lrob_etk_nl_retry_failed';

    public const ACTION_COMMIT_SCHEDULE = 'lrob_etk_nl_commit_schedule';

    public const ACTION_UNCOMMIT_SCHEDULE = 'lrob_etk_nl_uncommit_schedule';

    public const ACTION_CARD_STATES = 'lrob_etk_nl_card_states';

    public const ACTION_PREVIEW = 'lrob_etk_nl_preview';

    public const ACTION_RECIPIENTS_PREVIEW = 'lrob_etk_nl_recipients_preview';

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
        add_action('wp_ajax_' . self::ACTION_PAUSE,     [$this, 'handle_pause']);
        add_action('wp_ajax_' . self::ACTION_RESUME,    [$this, 'handle_resume']);
        add_action('wp_ajax_' . self::ACTION_ABORT,     [$this, 'handle_abort']);
        add_action('wp_ajax_' . self::ACTION_RETRY_FAILED, [$this, 'handle_retry_failed']);
        add_action('wp_ajax_' . self::ACTION_COMMIT_SCHEDULE, [$this, 'handle_commit_schedule']);
        add_action('wp_ajax_' . self::ACTION_UNCOMMIT_SCHEDULE, [$this, 'handle_uncommit_schedule']);
        add_action('wp_ajax_' . self::ACTION_CARD_STATES, [$this, 'handle_card_states']);
        add_action('wp_ajax_' . self::ACTION_PREVIEW,   [$this, 'handle_preview']);
        add_action('wp_ajax_' . self::ACTION_RECIPIENTS_PREVIEW, [$this, 'handle_recipients_preview']);
    }

    /**
     * Render the newsletter for the current admin and return the HTML
     * (no email send). Used by the Preview modal's iframe.
     */
    public function handle_preview(): void
    {
        $this->guard();
        $newsletter_id = $this->require_post_id();
        $admin = wp_get_current_user();
        $token = $admin ? (string) get_user_meta((int) $admin->ID, \LRob\EmailToolkit\Modules\Newsletter\UserMeta::PREFS_TOKEN, true) : '';
        $tokens = \LRob\EmailToolkit\Modules\Newsletter\Send\NewsletterRenderer::tokens_for_recipient(
            (string) ($admin->user_email ?? ''),
            (string) ($admin->display_name ?? ''),
            $token
        );
        $html = \LRob\EmailToolkit\Modules\Newsletter\Send\NewsletterRenderer::render($newsletter_id, $tokens);
        if ($html === '') {
            wp_send_json_error(['message' => __('Could not render this newsletter.', 'lrob-email-toolkit')], 400);
        }
        wp_send_json_success(['html' => $html]);
    }

    /**
     * Recipient list for the modal — two modes:
     *
     *   - **snapshot** (preferred when rows exist in newsletter_recipients):
     *     the frozen list as it was at send time. Each row carries its
     *     per-recipient status (sent / failed / pending / skipped).
     *     This is what you want for sent / sending / paused newsletters.
     *
     *   - **preview** (fallback for pre-send): a dry-run materialisation
     *     of who *would* be targeted given the current settings.
     */
    public function handle_recipients_preview(): void
    {
        $this->guard();
        $newsletter_id = $this->require_post_id();

        $offset = isset($_POST['offset']) ? max(0, (int) wp_unslash((string) $_POST['offset'])) : 0;
        $status_filter = isset($_POST['status_filter']) ? sanitize_key((string) wp_unslash((string) $_POST['status_filter'])) : '';
        $search = isset($_POST['search']) ? (string) wp_unslash((string) $_POST['search']) : '';
        $search = trim(sanitize_text_field($search));
        $limit = 50;

        $snapshot = $this->newsletters->recipients_snapshot($newsletter_id, $limit, $offset, $status_filter, $search);
        if ($snapshot['total'] > 0) {
            $sample = $this->attach_log_urls($newsletter_id, $snapshot['sample']);
            wp_send_json_success([
                'mode'           => 'snapshot',
                'total'          => $snapshot['total'],
                'filtered_total' => $snapshot['filtered_total'],
                'by_status'      => $snapshot['by_status'],
                'sample'         => $sample,
                'limit'          => $snapshot['limit'],
                'offset'         => $snapshot['offset'],
                'status_filter'  => $status_filter,
                'search'         => $search,
            ]);
        }

        // Pre-send: no materialisation yet, fall back to dry-run preview.
        // Filter / search / pagination params don't apply here — the
        // dry-run is bounded to 50 rows by design.
        $preview = $this->materializer->preview_recipients($newsletter_id, $limit);
        wp_send_json_success([
            'mode'         => 'preview',
            'total'        => $preview['total'],
            'by_kind'      => $preview['by_kind'],
            'sample'       => $preview['sample'],
            'sample_limit' => $limit,
        ]);
    }

    /**
     * Decorate the snapshot sample with a `log_url` per row that has a
     * matching log entry. With newsletter sends suppressed on success by
     * default, in practice only failed rows have a log row to link to.
     *
     * Late-binds the Logging classes via class_exists so the Newsletter
     * module degrades cleanly when Logging is disabled or absent.
     *
     * @param  array<int, array<string, mixed>> $sample
     * @return array<int, array<string, mixed>>
     */
    private function attach_log_urls(int $newsletter_id, array $sample): array
    {
        if ($sample === []) {
            return $sample;
        }
        $log_repo_class = '\\LRob\\EmailToolkit\\Modules\\Logging\\LogRepository';
        $log_page_class = '\\LRob\\EmailToolkit\\Modules\\Logging\\Admin\\PageController';
        if (!class_exists($log_repo_class) || !class_exists($log_page_class)) {
            return $sample;
        }
        $row_ids = array_map(static fn (array $r): int => (int) ($r['row_id'] ?? 0), $sample);
        $row_ids = array_values(array_filter($row_ids, static fn (int $i): bool => $i > 0));
        if ($row_ids === []) {
            return $sample;
        }
        /** @var \LRob\EmailToolkit\Modules\Logging\LogRepository $repo */
        $repo = new $log_repo_class();
        $map = $repo->log_ids_for_newsletter_recipients($newsletter_id, $row_ids);
        if ($map === []) {
            return $sample;
        }
        foreach ($sample as &$row) {
            $rid = (int) ($row['row_id'] ?? 0);
            if (isset($map[$rid])) {
                $row['log_url'] = add_query_arg(
                    ['page' => $log_page_class::SLUG, 'action' => 'view', 'id' => (int) $map[$rid]],
                    admin_url('admin.php')
                );
            }
        }
        unset($row);
        return $sample;
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

    public function handle_pause(): void
    {
        $this->guard();
        $newsletter_id = $this->require_post_id();
        $row = $this->newsletters->find_by_post_id($newsletter_id);
        $status = (string) ($row['status'] ?? '');
        if ($status !== NewsletterRepository::STATUS_SENDING) {
            wp_send_json_error([
                'message' => __('Only a sending newsletter can be paused.', 'lrob-email-toolkit'),
            ], 400);
        }
        $this->newsletters->update_status($newsletter_id, NewsletterRepository::STATUS_PAUSED);
        Events::dispatch('newsletter.paused', ['newsletter_id' => $newsletter_id]);
        wp_send_json_success(['status' => NewsletterRepository::STATUS_PAUSED]);
    }

    public function handle_resume(): void
    {
        $this->guard();
        $newsletter_id = $this->require_post_id();
        $row = $this->newsletters->find_by_post_id($newsletter_id);
        $status = (string) ($row['status'] ?? '');
        if ($status !== NewsletterRepository::STATUS_PAUSED) {
            wp_send_json_error([
                'message' => __('Only a paused newsletter can be resumed.', 'lrob-email-toolkit'),
            ], 400);
        }
        // Clear pause_reason too — if SMTP is still broken, the circuit-
        // breaker will trip again on the next tick and re-set it.
        $this->newsletters->update_status_with_reason($newsletter_id, NewsletterRepository::STATUS_SENDING, null);
        Events::dispatch('newsletter.resumed', ['newsletter_id' => $newsletter_id]);
        wp_send_json_success(['status' => NewsletterRepository::STATUS_SENDING]);
    }

    /**
     * Abort: flips status to 'aborted'. Any still-pending recipient
     * rows get marked 'skipped' so the newsletter-recipients table
     * doesn't carry forever-pending rows. No undo.
     */
    public function handle_abort(): void
    {
        $this->guard();
        $newsletter_id = $this->require_post_id();
        $row = $this->newsletters->find_by_post_id($newsletter_id);
        $status = (string) ($row['status'] ?? '');
        if (!in_array($status, [NewsletterRepository::STATUS_SENDING, NewsletterRepository::STATUS_PAUSED], true)) {
            wp_send_json_error([
                'message' => __('Only a sending or paused newsletter can be aborted.', 'lrob-email-toolkit'),
            ], 400);
        }
        global $wpdb;
        $recipients_table = Schema::newsletter_recipients_table();
        $skipped = (int) $wpdb->query($wpdb->prepare(
            "UPDATE `$recipients_table`
                SET status = 'skipped'
              WHERE newsletter_id = %d
                AND status = 'pending'",
            $newsletter_id
        ));
        $this->newsletters->update_status($newsletter_id, NewsletterRepository::STATUS_ABORTED);
        $wpdb->update(
            Schema::newsletters_table(),
            [
                'skipped_count' => $skipped,
                'completed_at'  => current_time('mysql', true),
            ],
            ['post_id' => $newsletter_id],
            ['%d', '%s'],
            ['%d']
        );
        Events::dispatch('newsletter.aborted', [
            'newsletter_id' => $newsletter_id,
            'skipped'       => $skipped,
        ]);
        wp_send_json_success([
            'status'  => NewsletterRepository::STATUS_ABORTED,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Commit-schedule: flips the companion `draft → scheduled`, which is
     * the signal `SendCron` waits for to start the materializer at the
     * configured time. Setting the date alone no longer auto-promotes —
     * the explicit click here is what locks the schedule in.
     *
     * Idempotent: clicking on an already-scheduled newsletter just
     * returns the current state.
     */
    public function handle_commit_schedule(): void
    {
        $this->guard();
        $newsletter_id = $this->require_post_id();
        $row = $this->newsletters->find_by_post_id($newsletter_id);
        $status = (string) ($row['status'] ?? '');
        if ($status === NewsletterRepository::STATUS_SCHEDULED) {
            wp_send_json_success(['status' => NewsletterRepository::STATUS_SCHEDULED, 'noop' => true]);
        }
        if ($status !== NewsletterRepository::STATUS_DRAFT) {
            wp_send_json_error([
                'message' => __('Only a draft newsletter can be scheduled.', 'lrob-email-toolkit'),
            ], 400);
        }
        $scheduled_at = (string) get_post_meta($newsletter_id, NewsletterCPT::META_SCHEDULED_AT, true);
        if ($scheduled_at === '') {
            wp_send_json_error([
                'message' => __('No schedule date set — tick the Schedule box and pick a date first.', 'lrob-email-toolkit'),
            ], 400);
        }
        $this->newsletters->update_status($newsletter_id, NewsletterRepository::STATUS_SCHEDULED);
        Events::dispatch('newsletter.scheduled', [
            'newsletter_id' => $newsletter_id,
            'scheduled_at'  => $scheduled_at,
        ]);
        wp_send_json_success(['status' => NewsletterRepository::STATUS_SCHEDULED]);
    }

    /**
     * Uncommit-schedule: flip `scheduled` back to `draft`, leaving the
     * date stored. The admin can re-commit later (or clear the date /
     * untick the schedule box to drop the date entirely).
     */
    public function handle_uncommit_schedule(): void
    {
        $this->guard();
        $newsletter_id = $this->require_post_id();
        $row = $this->newsletters->find_by_post_id($newsletter_id);
        $status = (string) ($row['status'] ?? '');
        if ($status !== NewsletterRepository::STATUS_SCHEDULED) {
            wp_send_json_error([
                'message' => __('Only a scheduled newsletter can be unscheduled.', 'lrob-email-toolkit'),
            ], 400);
        }
        $this->newsletters->update_status($newsletter_id, NewsletterRepository::STATUS_DRAFT);
        Events::dispatch('newsletter.unscheduled', ['newsletter_id' => $newsletter_id]);
        wp_send_json_success(['status' => NewsletterRepository::STATUS_DRAFT]);
    }

    /**
     * Card-states: cheap read-only batch endpoint that returns the
     * current state for every newsletter id on the page plus the
     * cron-health timestamps. Powers the polling-based auto-refresh
     * (admin watches a send progress in real time even when SendCron
     * is doing the work in the background).
     *
     * Returns: {
     *   newsletters: { "<id>": {status, sent, failed, total,
     *                            pause_reason, completed_at_ts, ...}, … },
     *   cron: { last_tick_ts, next_tick_ts, now_ts }
     * }
     *
     * Designed to be polled at ~20s by every open admin tab — the JS
     * side uses a localStorage cooperative lock so total server hits
     * stay roughly 1 per 15s regardless of tab count.
     */
    public function handle_card_states(): void
    {
        $this->guard();
        $raw_ids = $_POST['ids'] ?? [];
        if (!is_array($raw_ids)) {
            $raw_ids = [];
        }
        $ids = array_values(array_filter(
            array_map(static fn ($v): int => (int) $v, $raw_ids),
            static fn (int $i): bool => $i > 0
        ));

        $newsletters = [];
        foreach ($ids as $id) {
            $row = $this->newsletters->find_by_post_id($id);
            if ($row === null) {
                continue;
            }
            $completed_at = (string) ($row['completed_at'] ?? '');
            $started_at   = (string) ($row['started_at'] ?? '');
            $newsletters[(string) $id] = [
                'status'          => (string) ($row['status'] ?? NewsletterRepository::STATUS_DRAFT),
                'pause_reason'    => $row['pause_reason'] !== null ? (string) $row['pause_reason'] : null,
                'total'           => (int) ($row['total_recipients'] ?? 0),
                'sent'            => (int) ($row['sent_count'] ?? 0),
                'failed'          => (int) ($row['failed_count'] ?? 0),
                'completed_at_ts' => $completed_at !== '' ? (int) strtotime($completed_at . ' UTC') : 0,
                'started_at_ts'   => $started_at !== '' ? (int) strtotime($started_at . ' UTC') : 0,
            ];
        }

        $last_tick_s = (string) get_option(SendCron::OPTION_LAST_TICK, '');
        $last_tick_ts = $last_tick_s !== '' ? (int) strtotime($last_tick_s . ' UTC') : 0;
        $next_tick = wp_next_scheduled(SendCron::CRON_HOOK);

        wp_send_json_success([
            'newsletters' => $newsletters,
            'cron' => [
                'last_tick_ts' => $last_tick_ts,
                'next_tick_ts' => $next_tick !== false ? (int) $next_tick : 0,
                'now_ts'       => time(),
            ],
        ]);
    }

    /**
     * Retry-failed: flip every `failed` newsletter_recipients row back to
     * `pending` and decrement the companion's failed_count by the same
     * count. Designed for two scenarios:
     *
     *   1. SMTP went down mid-send and the circuit-breaker tripped only
     *      after some rows were already marked failed — the admin fixes
     *      SMTP and clicks "Retry failed" to re-queue those rows.
     *   2. The send completed but the post-mortem found a chunk of
     *      transient failures (provider had a hiccup). Same fix.
     *
     * If the newsletter is currently in `sent`, we flip it back to
     * `sending` so SendCron / next AJAX tick processes the re-queued rows.
     * `paused` / `sending` stay as-is.
     */
    public function handle_retry_failed(): void
    {
        $this->guard();
        $newsletter_id = $this->require_post_id();
        $row = $this->newsletters->find_by_post_id($newsletter_id);
        $status = (string) ($row['status'] ?? '');
        $allowed = [
            NewsletterRepository::STATUS_SENDING,
            NewsletterRepository::STATUS_PAUSED,
            NewsletterRepository::STATUS_SENT,
            NewsletterRepository::STATUS_FAILED,
        ];
        if (!in_array($status, $allowed, true)) {
            wp_send_json_error([
                'message' => __('No failed recipients to retry for this newsletter.', 'lrob-email-toolkit'),
            ], 400);
        }
        $reset = $this->newsletters->retry_failed_recipients($newsletter_id);
        if ($reset === 0) {
            wp_send_json_error([
                'message' => __('No failed recipients to retry.', 'lrob-email-toolkit'),
            ], 400);
        }
        // If the newsletter had already converged to sent/failed because
        // the previous pass ran to completion, flip it back to sending
        // so the existing send pipeline picks up the re-queued rows.
        if ($status === NewsletterRepository::STATUS_SENT || $status === NewsletterRepository::STATUS_FAILED) {
            $this->newsletters->update_status_with_reason(
                $newsletter_id,
                NewsletterRepository::STATUS_SENDING,
                null
            );
        }
        Events::dispatch('newsletter.recipients.requeued', [
            'newsletter_id' => $newsletter_id,
            'count'         => $reset,
        ]);
        wp_send_json_success([
            'requeued' => $reset,
            'status'   => $status === NewsletterRepository::STATUS_SENT || $status === NewsletterRepository::STATUS_FAILED
                ? NewsletterRepository::STATUS_SENDING
                : $status,
        ]);
    }

    /**
     * Shared validator for handlers that only need a post id —
     * guard()s the request, reads `newsletter_id`, checks CPT match.
     */
    private function require_post_id(): int
    {
        $newsletter_id = isset($_POST['newsletter_id']) ? (int) wp_unslash((string) $_POST['newsletter_id']) : 0;
        if ($newsletter_id <= 0) {
            wp_send_json_error(['message' => __('Missing newsletter id.', 'lrob-email-toolkit')], 400);
        }
        $post = get_post($newsletter_id);
        if (!$post instanceof \WP_Post || $post->post_type !== NewsletterCPT::POST_TYPE) {
            wp_send_json_error(['message' => __('Newsletter not found.', 'lrob-email-toolkit')], 404);
        }
        return $newsletter_id;
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
