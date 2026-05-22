<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Send;

use LRob\EmailToolkit\Modules\Newsletter\CampaignCPT;
use LRob\EmailToolkit\Modules\Newsletter\CampaignRepository;
use LRob\EmailToolkit\Modules\Newsletter\Schema;
use LRob\EmailToolkit\Modules\Newsletter\UserMeta;
use LRob\EmailToolkit\Support\Events;

/**
 * One tick of the send loop: claim up to N pending recipients for a
 * campaign, render + wp_mail each, update statuses + counters, and
 * mark the campaign tick timestamp. Bounded per call so the admin's
 * AJAX loop stays responsive — caller decides whether to call again
 * based on the returned `remaining`.
 *
 * Minimum-viable shape today:
 *   - No per-domain throttle (step 7b).
 *   - No Cron safety-net (step 7b).
 *   - Sequential wp_mail (no async queueing).
 *   - Claim semantics: SELECT pending rows, then UPDATE each row to
 *     'sending'. Race-safe for the single-admin AJAX driver; concurrent
 *     ticks for the same campaign would double-send (revisit when
 *     Cron lands and races become real).
 *
 * Completion detection: when zero pending rows remain after a tick,
 * campaign status → 'sent', completed_at stamped, event dispatched.
 */
final class SendLoop
{
    public const DEFAULT_BATCH = 25;

    public const HEADER_CAMPAIGN_ID = 'X-Lrob-Etk-Newsletter-Campaign-ID';

    public function __construct(private CampaignRepository $campaigns)
    {
    }

    /**
     * Process one batch for the campaign. Returns a progress dict:
     *   ['sent' => int, 'failed' => int, 'remaining' => int, 'total' => int, 'status' => string]
     *
     * @return array<string, mixed>
     */
    public function tick(int $campaign_id, int $batch_size = self::DEFAULT_BATCH): array
    {
        $post = get_post($campaign_id);
        if (!$post instanceof \WP_Post || $post->post_type !== CampaignCPT::POST_TYPE) {
            return $this->progress($campaign_id, 0, 0, 'invalid');
        }

        $companion = $this->campaigns->find_by_post_id($campaign_id);
        $status = (string) ($companion['status'] ?? CampaignRepository::STATUS_DRAFT);
        if ($status !== CampaignRepository::STATUS_SENDING) {
            return $this->progress($campaign_id, 0, 0, $status);
        }

        $batch_size = max(1, min(200, $batch_size));
        $claimed = $this->claim_batch($campaign_id, $batch_size);
        if ($claimed === []) {
            // No pending left → mark sent + dispatch event.
            $this->mark_complete($campaign_id);
            return $this->progress($campaign_id, 0, 0, CampaignRepository::STATUS_SENT);
        }

        // Build per-campaign sending context once. Subject + headers
        // are constant across the batch; only body + recipient vary.
        $subject = $post->post_title !== '' ? $post->post_title : __('(no subject)', 'lrob-email-toolkit');
        $from_name_override = (string) get_post_meta($campaign_id, CampaignCPT::META_FROM_NAME_OVERRIDE, true);
        $reply_to = (string) get_post_meta($campaign_id, CampaignCPT::META_REPLY_TO_OVERRIDE, true);

        $sent = 0;
        $failed = 0;
        foreach ($claimed as $row) {
            $row_id = (int) $row['id'];
            $email = (string) $row['email_snapshot'];
            $name = (string) ($row['name_snapshot'] ?? '');
            $prefs_token = $this->prefs_token_for($row);

            $tokens = CampaignRenderer::tokens_for_recipient($email, $name, $prefs_token);
            $body = CampaignRenderer::render($campaign_id, $tokens);
            if ($body === '' || !is_email($email)) {
                $this->mark_failed($row_id, 'invalid_recipient_or_body');
                $failed++;
                continue;
            }

            $headers = $this->build_headers($campaign_id, $prefs_token, $from_name_override, $reply_to);
            $ok = (bool) wp_mail($email, (string) $subject, $body, $headers);
            if ($ok) {
                $this->mark_sent($row_id);
                $sent++;
                Events::dispatch('newsletter.recipient.sent', [
                    'campaign_id'    => $campaign_id,
                    'recipient_kind' => (string) $row['recipient_kind'],
                    'recipient_id'   => (int) $row['recipient_id'],
                    'email'          => $email,
                ]);
            } else {
                $this->mark_failed($row_id, 'wp_mail_failed');
                $failed++;
                Events::dispatch('newsletter.recipient.failed', [
                    'campaign_id'    => $campaign_id,
                    'recipient_kind' => (string) $row['recipient_kind'],
                    'recipient_id'   => (int) $row['recipient_id'],
                    'email'          => $email,
                    'failure_code'   => 'wp_mail_failed',
                ]);
            }
        }

        $this->bump_counters($campaign_id, $sent, $failed);

        // Re-read companion to compute remaining + decide completion.
        $row = $this->campaigns->find_by_post_id($campaign_id);
        $total = (int) ($row['total_recipients'] ?? 0);
        $total_sent = (int) ($row['sent_count'] ?? 0);
        $total_failed = (int) ($row['failed_count'] ?? 0);
        $remaining = max(0, $total - $total_sent - $total_failed);
        if ($remaining === 0) {
            $this->mark_complete($campaign_id);
            return $this->progress($campaign_id, $sent, $failed, CampaignRepository::STATUS_SENT);
        }

        return $this->progress($campaign_id, $sent, $failed, CampaignRepository::STATUS_SENDING);
    }

    /**
     * Claim up to $limit pending rows by flipping them to 'sending'
     * one by one. Returns the rows actually claimed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function claim_batch(int $campaign_id, int $limit): array
    {
        global $wpdb;
        $table = Schema::campaign_recipients_table();
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$table`
              WHERE campaign_id = %d AND status = 'pending'
              ORDER BY id ASC
              LIMIT %d",
            $campaign_id,
            $limit
        ), ARRAY_A);
        if ($rows === []) {
            return [];
        }
        // Flip each to 'sending' so a concurrent tick doesn't re-claim.
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
            Schema::campaign_recipients_table(),
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
            Schema::campaign_recipients_table(),
            ['status' => 'failed', 'failure_code' => $failure_code],
            ['id' => $row_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Atomic counter bump on the campaigns companion row. UPDATE
     * with `col = col + N` so concurrent ticks don't lose increments.
     */
    private function bump_counters(int $campaign_id, int $sent, int $failed): void
    {
        global $wpdb;
        $table = Schema::campaigns_table();
        $wpdb->query($wpdb->prepare(
            "UPDATE `$table`
                SET sent_count = sent_count + %d,
                    failed_count = failed_count + %d,
                    last_tick_at = %s
              WHERE post_id = %d",
            $sent,
            $failed,
            current_time('mysql', true),
            $campaign_id
        ));
    }

    private function mark_complete(int $campaign_id): void
    {
        global $wpdb;
        $wpdb->update(
            Schema::campaigns_table(),
            [
                'status'       => CampaignRepository::STATUS_SENT,
                'completed_at' => current_time('mysql', true),
                'last_tick_at' => current_time('mysql', true),
            ],
            ['post_id' => $campaign_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        Events::dispatch('newsletter.campaign.completed', [
            'campaign_id' => $campaign_id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function progress(int $campaign_id, int $sent_this_tick, int $failed_this_tick, string $status): array
    {
        $row = $this->campaigns->find_by_post_id($campaign_id);
        $total = (int) ($row['total_recipients'] ?? 0);
        $total_sent = (int) ($row['sent_count'] ?? 0);
        $total_failed = (int) ($row['failed_count'] ?? 0);
        $remaining = max(0, $total - $total_sent - $total_failed);
        return [
            'sent_this_tick'   => $sent_this_tick,
            'failed_this_tick' => $failed_this_tick,
            'total'            => $total,
            'sent'             => $total_sent,
            'failed'           => $total_failed,
            'remaining'        => $remaining,
            'status'           => $status,
        ];
    }

    /**
     * Resolve the recipient's prefs_token by looking up the live row
     * (subscribers table or wp_users user_meta). The snapshot doesn't
     * store the token so changes (e.g. a forced rotation) propagate.
     *
     * @param array<string, mixed> $row
     */
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

    /**
     * @return array<int, string>
     */
    private function build_headers(int $campaign_id, string $prefs_token, string $from_name_override, string $reply_to): array
    {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            self::HEADER_CAMPAIGN_ID . ': ' . (int) $campaign_id,
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
}
