<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Support\Events;

/**
 * WP user lifecycle integration. Two hooks:
 *
 *  - `user_register`: a new WP account just got created. If an existing
 *    subscriber row matches its email, copy the subscriber state into
 *    user_meta and delete the subscriber row — the WP user is now the
 *    canonical recipient for that email. Otherwise seed default opt-in
 *    state on the fresh user. Fires `newsletter.subscriber.promoted`
 *    (matched) or `newsletter.subscriber.added` (cold start).
 *
 *  - `deleted_user`: WP account just went away. Drop their list_members
 *    rows so the recipient counts stay honest. user_meta gets cleaned up
 *    by WP itself.
 *
 * Both hooks gate on the module being enabled (handled by Module::register
 * — these handlers are only wired when is_enabled() is true).
 */
final class UserHooks
{
    public function __construct(private SubscriberRepository $subscribers)
    {
    }

    /**
     * Fires after wp_insert_user. Two paths:
     *  - Matched subscriber row → promote (carry over confirmed_at,
     *    category_opt_outs, bounce_count, prefs_token, source) and delete
     *    the subscriber row.
     *  - No match → seed a confirmed-by-default WP user. The "all WP users
     *    default-opt-in across all roles" policy is locked in newsletter.md.
     */
    public function on_user_register(int $user_id): void
    {
        $user = get_userdata($user_id);
        if (!$user instanceof \WP_User) {
            return;
        }

        $email = (string) $user->user_email;
        $existing = $email !== '' ? $this->subscribers->find_by_email($email) : null;

        if (is_array($existing)) {
            $this->promote_subscriber_to_user($user_id, $existing);
            return;
        }

        $this->seed_user_defaults($user_id);
        Events::dispatch('newsletter.subscriber.added', [
            'recipient_kind' => 'user',
            'recipient_id'   => $user_id,
            'email'          => $email,
            'source'         => 'user_register',
        ]);
    }

    /**
     * Fires after WP has already deleted the user. The user_meta is gone
     * with them via standard WP cleanup; we only need to detach their
     * explicit list memberships so the lists table doesn't dangle.
     * Tracking events keep the recipient_id reference for audit history —
     * the daily retention cron will eventually age them out.
     */
    public function on_deleted_user(int $user_id): void
    {
        global $wpdb;
        $wpdb->delete(Schema::list_members_table(), [
            'recipient_kind' => UserMeta::KIND_USER,
            'recipient_id'   => $user_id,
        ], ['%s', '%d']);
    }

    /**
     * Copy subscriber state into user_meta keys (lrob_etk_nl_*) and drop
     * the subscriber row. Idempotent: if the user already has these meta
     * keys, they're overwritten with subscriber values (subscriber wins
     * because it's the older record).
     *
     * @param array<string, string> $subscriber
     */
    private function promote_subscriber_to_user(int $user_id, array $subscriber): void
    {
        $status = (string) ($subscriber['status'] ?? '');
        $confirmed_at = isset($subscriber['confirmed_at']) && $subscriber['confirmed_at'] !== ''
            ? (string) $subscriber['confirmed_at']
            : null;

        // A trashed/refused/bounced subscriber promoting to a WP user gets
        // flipped to a clean opted-in state — the WP account is an
        // affirmative action, and they can re-opt-out from their profile
        // any time. Subscriber→user promotion is a fresh start.
        $opted_in = !in_array($status, ['trashed', 'refused', 'bounced'], true);

        update_user_meta($user_id, UserMeta::OPTED_IN, $opted_in ? '1' : '0');
        update_user_meta($user_id, UserMeta::STATUS, $opted_in ? UserMeta::STATUS_ACTIVE : UserMeta::STATUS_BOUNCED);
        update_user_meta($user_id, UserMeta::CATEGORY_OPT_OUTS, (string) ($subscriber['category_opt_outs'] ?? ''));
        update_user_meta($user_id, UserMeta::BOUNCE_COUNT, (int) ($subscriber['bounce_count'] ?? 0));
        update_user_meta($user_id, UserMeta::PREFS_TOKEN, (string) ($subscriber['prefs_token'] ?? UserMeta::generate_prefs_token()));
        if ($confirmed_at !== null) {
            update_user_meta($user_id, UserMeta::CONFIRMED_AT, $confirmed_at);
        }
        update_user_meta($user_id, UserMeta::SOURCE, (string) ($subscriber['source'] ?? ''));

        // Junction-table rewrite: any list_members rows that referenced the
        // subscriber id need to point at the WP user id instead so the
        // recipient stays in their lists across promotion.
        global $wpdb;
        $subscriber_id = (int) ($subscriber['id'] ?? 0);
        if ($subscriber_id > 0) {
            $wpdb->update(
                Schema::list_members_table(),
                ['recipient_kind' => UserMeta::KIND_USER, 'recipient_id' => $user_id],
                ['recipient_kind' => UserMeta::KIND_SUBSCRIBER, 'recipient_id' => $subscriber_id],
                ['%s', '%d'],
                ['%s', '%d']
            );
            $this->subscribers->delete($subscriber_id);
        }

        Events::dispatch('newsletter.subscriber.promoted', [
            'user_id'              => $user_id,
            'subscriber_id'        => $subscriber_id,
            'email'                => (string) ($subscriber['email'] ?? ''),
            'previous_status'      => $status,
            'promoted_to_opted_in' => $opted_in,
        ]);
    }

    /**
     * Seed the lrob_etk_nl_* user_meta keys for a brand-new WP user with no
     * prior subscriber row. Default = opted in to everything (the policy
     * locked in newsletter.md). Per-category opt-out is up to the user via
     * their preferences page.
     */
    private function seed_user_defaults(int $user_id): void
    {
        update_user_meta($user_id, UserMeta::OPTED_IN, '1');
        update_user_meta($user_id, UserMeta::STATUS, UserMeta::STATUS_ACTIVE);
        update_user_meta($user_id, UserMeta::CATEGORY_OPT_OUTS, '[]');
        update_user_meta($user_id, UserMeta::BOUNCE_COUNT, 0);
        update_user_meta($user_id, UserMeta::PREFS_TOKEN, UserMeta::generate_prefs_token());
        update_user_meta($user_id, UserMeta::CONFIRMED_AT, current_time('mysql', true));
        update_user_meta($user_id, UserMeta::SOURCE, 'user_register');
    }
}
