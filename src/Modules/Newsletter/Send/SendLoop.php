<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Send;

use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;
use LRob\EmailToolkit\Modules\Newsletter\Schema;
use LRob\EmailToolkit\Modules\Newsletter\Tracking\Pipeline as TrackingPipeline;
use LRob\EmailToolkit\Modules\Newsletter\UserMeta;
use LRob\EmailToolkit\Modules\SMTP\MailRouter;
use LRob\EmailToolkit\Modules\SMTP\SourceResolver;
use LRob\EmailToolkit\Support\Events;

// Docs: docs/newsletter-internals.md → "Send pipeline"
final class SendLoop
{
    public const DEFAULT_BATCH = 25;

    public const HEADER_NEWSLETTER_ID           = 'X-Lrob-Etk-Newsletter-ID';

    public const HEADER_NEWSLETTER_RECIPIENT_ID = 'X-Lrob-Etk-Newsletter-Recipient-ID';

    // 5 consecutive failures trips the SMTP circuit-breaker; resets to 0 on any success.
    public const CONSECUTIVE_FAILURE_THRESHOLD = 5;

    public const PAUSE_REASON_SMTP_UNHEALTHY = 'smtp_unhealthy';

    public function __construct(
        private NewsletterRepository $newsletters,
        private ?TrackingPipeline $tracking = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function tick(int $newsletter_id, int $batch_size = self::DEFAULT_BATCH): array
    {
        $post = get_post($newsletter_id);
        if (!$post instanceof \WP_Post || $post->post_type !== NewsletterCPT::POST_TYPE) {
            return $this->progress($newsletter_id, 0, 0, 'invalid');
        }

        $companion = $this->newsletters->find_by_post_id($newsletter_id);
        $status = (string) ($companion['status'] ?? NewsletterRepository::STATUS_DRAFT);
        if ($status !== NewsletterRepository::STATUS_SENDING) {
            return $this->progress($newsletter_id, 0, 0, $status);
        }

        $batch_size = max(1, min(200, $batch_size));
        $claimed = $this->claim_batch($newsletter_id, $batch_size);
        if ($claimed === []) {
            // No pending left → mark sent + dispatch event.
            $this->mark_complete($newsletter_id);
            return $this->progress($newsletter_id, 0, 0, NewsletterRepository::STATUS_SENT);
        }

        // Build per-newsletter sending context once. Subject + headers
        // are constant across the batch; only body + recipient vary.
        $subject = $post->post_title !== '' ? $post->post_title : __('(no subject)', 'lrob-email-toolkit');
        $from_name_override = (string) get_post_meta($newsletter_id, NewsletterCPT::META_FROM_NAME_OVERRIDE, true);
        $reply_to = (string) get_post_meta($newsletter_id, NewsletterCPT::META_REPLY_TO_OVERRIDE, true);
        $identity_id = (int) get_post_meta($newsletter_id, NewsletterCPT::META_SMTP_IDENTITY, true);

        $sent = 0;
        $failed = 0;
        $consecutive_failures = 0;
        $breaker_tripped = false;
        $unprocessed_ids = [];

        // Send the batch under the newsletter's chosen SMTP identity + From-name
        // (no-ops when SMTP is disabled or no identity is set). The `newsletter`
        // source lets SMTP routing rules target newsletter mail when no
        // per-newsletter identity is forced (forced identity still wins).
        // Cleared in finally so a renderer/tracking throw can't leak either.
        $router = $this->force_sender($identity_id, $from_name_override);
        SourceResolver::push(SourceResolver::SOURCE_NEWSLETTER);
        try {
        foreach ($claimed as $i => $row) {
            $row_id = (int) $row['id'];
            $email = (string) $row['email_snapshot'];
            $name = (string) ($row['name_snapshot'] ?? '');
            $prefs_token = $this->prefs_token_for($row);

            $tokens = NewsletterRenderer::tokens_for_recipient($email, $name, $prefs_token);
            $body = NewsletterRenderer::render($newsletter_id, $tokens);
            if ($body !== '' && $this->tracking !== null) {
                $body = $this->tracking->rewrite(
                    $body,
                    $newsletter_id,
                    (string) $row['recipient_kind'],
                    (int) $row['recipient_id']
                );
            }
            if ($body === '' || !is_email($email)) {
                // Bad recipient data is the recipient's problem, not SMTP's.
                // Mark it failed but don't count it toward the breaker —
                // otherwise a batch with a few invalid emails could trip us.
                $this->mark_failed($row_id, 'invalid_recipient_or_body');
                $failed++;
                continue;
            }

            $headers = $this->build_headers($newsletter_id, $row_id, $prefs_token, $reply_to);
            $ok = (bool) wp_mail($email, (string) $subject, $body, $headers);
            if ($ok) {
                $this->mark_sent($row_id);
                $sent++;
                $consecutive_failures = 0;
                Events::dispatch('newsletter.recipient.sent', [
                    'newsletter_id'    => $newsletter_id,
                    'recipient_kind' => (string) $row['recipient_kind'],
                    'recipient_id'   => (int) $row['recipient_id'],
                    'email'          => $email,
                ]);
            } else {
                $this->mark_failed($row_id, 'wp_mail_failed');
                $failed++;
                $consecutive_failures++;
                Events::dispatch('newsletter.recipient.failed', [
                    'newsletter_id'    => $newsletter_id,
                    'recipient_kind' => (string) $row['recipient_kind'],
                    'recipient_id'   => (int) $row['recipient_id'],
                    'email'          => $email,
                    'failure_code'   => 'wp_mail_failed',
                ]);
                if ($consecutive_failures >= self::CONSECUTIVE_FAILURE_THRESHOLD) {
                    $breaker_tripped = true;
                    for ($j = $i + 1; $j < count($claimed); $j++) {
                        $unprocessed_ids[] = (int) $claimed[$j]['id'];
                    }
                    break;
                }
            }
        }
        } finally {
            SourceResolver::pop();
            if ($router instanceof MailRouter) {
                $router->clear_forced_send();
            }
        }

        if ($unprocessed_ids !== []) {
            $this->release_claimed($unprocessed_ids);
        }

        $this->bump_counters($newsletter_id, $sent, $failed);

        if ($breaker_tripped) {
            $this->newsletters->update_status_with_reason(
                $newsletter_id,
                NewsletterRepository::STATUS_PAUSED,
                self::PAUSE_REASON_SMTP_UNHEALTHY
            );
            Events::dispatch('newsletter.paused', [
                'newsletter_id' => $newsletter_id,
                'reason'        => self::PAUSE_REASON_SMTP_UNHEALTHY,
            ]);
            return $this->progress(
                $newsletter_id,
                $sent,
                $failed,
                NewsletterRepository::STATUS_PAUSED,
                self::PAUSE_REASON_SMTP_UNHEALTHY
            );
        }

        // Re-read companion to compute remaining + decide completion.
        $row = $this->newsletters->find_by_post_id($newsletter_id);
        $total = (int) ($row['total_recipients'] ?? 0);
        $total_sent = (int) ($row['sent_count'] ?? 0);
        $total_failed = (int) ($row['failed_count'] ?? 0);
        $remaining = max(0, $total - $total_sent - $total_failed);
        if ($remaining === 0) {
            $this->mark_complete($newsletter_id);
            return $this->progress($newsletter_id, $sent, $failed, NewsletterRepository::STATUS_SENT);
        }

        return $this->progress($newsletter_id, $sent, $failed, NewsletterRepository::STATUS_SENDING);
    }

    /** @param array<int, int> $row_ids */
    private function release_claimed(array $row_ids): void
    {
        if ($row_ids === []) {
            return;
        }
        global $wpdb;
        $table = Schema::newsletter_recipients_table();
        $placeholders = implode(',', array_fill(0, count($row_ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE `$table` SET status = 'pending' WHERE id IN ($placeholders)",
            ...$row_ids
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function claim_batch(int $newsletter_id, int $limit): array
    {
        global $wpdb;
        $table = Schema::newsletter_recipients_table();
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$table`
              WHERE newsletter_id = %d AND status = 'pending'
              ORDER BY id ASC
              LIMIT %d",
            $newsletter_id,
            $limit
        ), ARRAY_A);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE `$table` SET status = 'sending' WHERE id IN ($placeholders)",
            ...$ids
        ));
        return $rows;
    }

    private function mark_sent(int $row_id): void
    {
        global $wpdb;
        $wpdb->update(
            Schema::newsletter_recipients_table(),
            ['status' => 'sent', 'sent_at' => current_time('mysql', true)],
            ['id' => $row_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    private function mark_failed(int $row_id, string $failure_code): void
    {
        global $wpdb;
        $wpdb->update(
            Schema::newsletter_recipients_table(),
            ['status' => 'failed', 'failure_code' => $failure_code],
            ['id' => $row_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    private function bump_counters(int $newsletter_id, int $sent, int $failed): void
    {
        global $wpdb;
        $table = Schema::newsletters_table();
        $wpdb->query($wpdb->prepare(
            "UPDATE `$table`
                SET sent_count = sent_count + %d,
                    failed_count = failed_count + %d,
                    last_tick_at = %s
              WHERE post_id = %d",
            $sent,
            $failed,
            current_time('mysql', true),
            $newsletter_id
        ));
    }

    private function mark_complete(int $newsletter_id): void
    {
        global $wpdb;
        $wpdb->update(
            Schema::newsletters_table(),
            [
                'status'       => NewsletterRepository::STATUS_SENT,
                'completed_at' => current_time('mysql', true),
                'last_tick_at' => current_time('mysql', true),
            ],
            ['post_id' => $newsletter_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        Events::dispatch('newsletter.completed', [
            'newsletter_id' => $newsletter_id,
        ]);
    }

    /** @return array<string, mixed> */
    private function progress(int $newsletter_id, int $sent_this_tick, int $failed_this_tick, string $status, ?string $pause_reason = null): array
    {
        $row = $this->newsletters->find_by_post_id($newsletter_id);
        $total = (int) ($row['total_recipients'] ?? 0);
        $total_sent = (int) ($row['sent_count'] ?? 0);
        $total_failed = (int) ($row['failed_count'] ?? 0);
        $remaining = max(0, $total - $total_sent - $total_failed);
        if ($pause_reason === null && isset($row['pause_reason'])) {
            $pause_reason = (string) $row['pause_reason'] !== '' ? (string) $row['pause_reason'] : null;
        }
        return [
            'sent_this_tick'   => $sent_this_tick,
            'failed_this_tick' => $failed_this_tick,
            'total'            => $total,
            'sent'             => $total_sent,
            'failed'           => $total_failed,
            'remaining'        => $remaining,
            'status'           => $status,
            'pause_reason'     => $pause_reason,
        ];
    }

    /** @param array<string, mixed> $row */
    private function prefs_token_for(array $row): string
    {
        $kind = (string) ($row['recipient_kind'] ?? '');
        $id = (int) ($row['recipient_id'] ?? 0);
        if ($id <= 0) {
            return '';
        }
        if ($kind === UserMeta::KIND_SUBSCRIBER) {
            global $wpdb;
            $table = Schema::subscribers_table();
            return (string) $wpdb->get_var($wpdb->prepare(
                "SELECT prefs_token FROM `$table` WHERE id = %d LIMIT 1",
                $id
            ));
        }
        if ($kind === UserMeta::KIND_USER) {
            return (string) get_user_meta($id, UserMeta::PREFS_TOKEN, true);
        }
        return '';
    }

    /** @return array<int, string> */
    /**
     * Fetch the MailRouter from the container and force the newsletter's SMTP
     * identity (+ From-name) for the upcoming sends. Returns the router so the
     * caller can clear_forced_send() in a finally — or null when SMTP isn't
     * active (the newsletter then sends through plain wp_mail, unchanged).
     */
    private function force_sender(int $identity_id, string $from_name_override): ?MailRouter
    {
        if (!class_exists(MailRouter::class)) {
            return null;
        }
        $plugin = \LRob\EmailToolkit\Plugin::instance();
        if (!$plugin->container()->has(MailRouter::class)) {
            return null;
        }
        $router = $plugin->container()->get(MailRouter::class);
        if (!$router instanceof MailRouter) {
            return null;
        }
        $router->force_send($identity_id, $from_name_override);
        return $router;
    }

    private function build_headers(int $newsletter_id, int $recipient_row_id, string $prefs_token, string $reply_to): array
    {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            self::HEADER_NEWSLETTER_ID . ': ' . (int) $newsletter_id,
            self::HEADER_NEWSLETTER_RECIPIENT_ID . ': ' . (int) $recipient_row_id,
        ];
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
}
