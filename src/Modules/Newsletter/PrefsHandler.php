<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Support\Events;

/**
 * Public-side prefs page + one-click unsubscribe endpoint. Catches two
 * query params on init:
 *
 *   - `?lrob-etk-nl-prefs=<token>` — recipient management:
 *       GET  → render the prefs form.
 *       POST → save (or hard-unsub / forget-me for subscribers).
 *
 *   - `?lrob-etk-nl-unsub=<token>` — RFC 8058 one-click unsubscribe:
 *       POST (with body containing "List-Unsubscribe=One-Click") →
 *             flip the recipient out of everything immediately.
 *       GET  → fall back to the prefs page (some email clients open
 *             the URL in a browser tab; landing on the prefs form is
 *             friendlier than a bare "unsubscribed" page).
 *
 * Token is the opaque prefs_token stored on the subscribers row OR
 * on the WP user's lrob_etk_nl_prefs_token user_meta — the same
 * value embedded in `{{prefs_url}}` and `{{unsub_url}}` template
 * substitutions. No HMAC needed: the token itself is the secret
 * (24 random bytes, unguessable). Anyone holding it is treated as
 * the recipient.
 */
final class PrefsHandler
{
    public const QUERY_PREFS = 'lrob-etk-nl-prefs';

    public const QUERY_UNSUB = 'lrob-etk-nl-unsub';

    private const NONCE_ACTION_PREFS = 'lrob_etk_nl_prefs_save';

    public function __construct(
        private SubscriberRepository $subscribers,
        private ListRepository $lists,
    ) {
    }

    public function register(): void
    {
        add_action('init', [$this, 'maybe_handle']);
    }

    public function maybe_handle(): void
    {
        if (is_admin()) {
            return;
        }
        if (isset($_GET[self::QUERY_PREFS])) {
            $this->handle_prefs((string) wp_unslash((string) $_GET[self::QUERY_PREFS]));
            return;
        }
        if (isset($_GET[self::QUERY_UNSUB])) {
            $this->handle_unsub((string) wp_unslash((string) $_GET[self::QUERY_UNSUB]));
            return;
        }
    }

    /**
     * Render prefs (GET) or save updates (POST). On any submit-button
     * we redirect back to the same URL with a flash so subsequent
     * GETs show the post-action state without re-posting on reload.
     */
    private function handle_prefs(string $token): void
    {
        $recipient = $this->resolve($token);
        if ($recipient === null) {
            self::render_message(
                __('Link expired', 'lrob-email-toolkit'),
                __('This preferences link is no longer valid. If you\'re still subscribed, request a fresh link from any of our emails.', 'lrob-email-toolkit')
            );
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && isset($_POST['lrob_etk_nl_prefs_submit'])
        ) {
            $this->save_prefs($recipient, $token);
            // Falls through to render after the redirect (we exit
            // inside save_prefs for action-button paths; the plain
            // "save preferences" path also exits via redirect).
            return;
        }

        $this->render_prefs_page($recipient, $token);
    }

    private function save_prefs(array $recipient, string $token): void
    {
        $nonce = isset($_POST['_lrob_etk_nl_nonce']) ? (string) wp_unslash((string) $_POST['_lrob_etk_nl_nonce']) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION_PREFS)) {
            self::render_message(
                __('Security check failed', 'lrob-email-toolkit'),
                __('The form expired before we could save it. Please reload the page and try again.', 'lrob-email-toolkit')
            );
        }

        $kind = $recipient['kind'];
        $id = (int) $recipient['id'];

        // Hard-unsubscribe path (subscribers only) — keeps the row
        // for audit but flips status. WP users can't reach this
        // branch (the destructive section isn't rendered for them).
        if (isset($_POST['lrob_etk_nl_prefs_unsubscribe']) && $kind === UserMeta::KIND_SUBSCRIBER) {
            $this->subscribers->update_status($id, 'unsubscribed');
            $this->lists->detach_recipient(UserMeta::KIND_SUBSCRIBER, $id);
            Events::dispatch('newsletter.subscriber.unsubscribed', [
                'recipient_kind' => UserMeta::KIND_SUBSCRIBER,
                'recipient_id'   => $id,
                'email'          => (string) ($recipient['email'] ?? ''),
                'via'            => 'prefs_page',
            ]);
            self::render_message(
                __('Unsubscribed', 'lrob-email-toolkit'),
                __('You\'ve been unsubscribed. Sorry to see you go — you can come back any time by signing up again.', 'lrob-email-toolkit')
            );
        }

        // Hard-delete path (subscribers only). Row goes to trashed
        // status with previous_status recorded so the admin's Trash
        // tab can see what happened.
        if (isset($_POST['lrob_etk_nl_prefs_forget']) && $kind === UserMeta::KIND_SUBSCRIBER) {
            $previous = (string) ($recipient['status'] ?? '');
            global $wpdb;
            $wpdb->update(
                Schema::subscribers_table(),
                [
                    'status'          => 'trashed',
                    'previous_status' => $previous,
                    'trashed_at'      => current_time('mysql', true),
                    'trashed_reason'  => 'user-requested',
                ],
                ['id' => $id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
            $this->lists->detach_recipient(UserMeta::KIND_SUBSCRIBER, $id);
            Events::dispatch('newsletter.subscriber.trashed', [
                'recipient_kind'  => UserMeta::KIND_SUBSCRIBER,
                'recipient_id'    => $id,
                'email'           => (string) ($recipient['email'] ?? ''),
                'previous_status' => $previous,
                'via'             => 'prefs_page',
            ]);
            self::render_message(
                __('Forgotten', 'lrob-email-toolkit'),
                __('We\'ve removed your record. We won\'t contact you again. Sorry for the noise.', 'lrob-email-toolkit')
            );
        }

        // Regular "save preferences" path: sync public-list memberships.
        // Picker only exposes lists where visibility=public + kind=subscribers,
        // so toggles on private / system / users lists silently no-op.
        $chosen_lists = isset($_POST['lrob_etk_nl_lists']) && is_array($_POST['lrob_etk_nl_lists'])
            ? array_map('intval', wp_unslash($_POST['lrob_etk_nl_lists']))
            : [];

        if ($kind === UserMeta::KIND_USER) {
            $opted_in = !empty($_POST['lrob_etk_nl_opted_in']);
            update_user_meta($id, UserMeta::OPTED_IN, $opted_in ? '1' : '0');
        }
        $this->sync_public_list_memberships($kind, $id, $chosen_lists);

        // Redirect back to wherever the form was rendered. The block /
        // shortcode surfaces pass `_lrob_etk_nl_return_to` so the user
        // lands back on the content page they came from instead of the
        // standalone prefs page. wp_safe_redirect rejects off-host
        // values, falling through to the home URL.
        $return_to = isset($_POST['_lrob_etk_nl_return_to'])
            ? (string) wp_unslash((string) $_POST['_lrob_etk_nl_return_to'])
            : '';
        $redirect = $return_to !== ''
            ? add_query_arg(['lrob_etk_nl_saved' => '1'], $return_to)
            : add_query_arg([self::QUERY_PREFS => $token, 'saved' => '1'], home_url('/'));
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * RFC 8058 one-click unsubscribe. POST means the email client
     * fired the one-click action directly; flip the recipient out
     * of everything. GET falls back to the prefs page so a user
     * landing the link in a browser sees the friendlier UI.
     */
    private function handle_unsub(string $token): void
    {
        $recipient = $this->resolve($token);
        if ($recipient === null) {
            self::render_message(
                __('Link expired', 'lrob-email-toolkit'),
                __('This unsubscribe link is no longer valid.', 'lrob-email-toolkit')
            );
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            // Browser landed on the URL — show prefs UI rather than
            // silently unsubscribing on a casual click.
            $this->render_prefs_page($recipient, $token, true);
        }

        $kind = $recipient['kind'];
        $id = (int) $recipient['id'];
        if ($kind === UserMeta::KIND_SUBSCRIBER) {
            $this->subscribers->update_status($id, 'unsubscribed');
            $this->lists->detach_recipient(UserMeta::KIND_SUBSCRIBER, $id);
        } else {
            update_user_meta($id, UserMeta::OPTED_IN, '0');
            $this->lists->detach_recipient(UserMeta::KIND_USER, $id);
        }
        Events::dispatch('newsletter.tracking.unsubscribed', [
            'recipient_kind' => $kind,
            'recipient_id'   => $id,
            'email'          => (string) ($recipient['email'] ?? ''),
            'via'            => 'one_click',
        ]);
        // Bare HTTP 200 for email-client one-click POST — no body
        // expected per RFC 8058.
        status_header(200);
        nocache_headers();
        echo 'OK';
        exit;
    }

    /**
     * Replace the recipient's PUBLIC list memberships with the chosen
     * set: add any new ones, remove any that were dropped. Private +
     * system + users-kind lists are scoped out — subscribers can only
     * toggle their own membership on lists explicitly marked
     * visibility=public, so the picker is the trust boundary AND the
     * sync logic guards the same set server-side.
     */
    private function sync_public_list_memberships(string $kind, int $id, array $chosen_list_ids): void
    {
        $public_lists = $this->lists->list_public_for_subscribers();
        $public_ids = array_map(static fn ($l) => (int) $l['id'], $public_lists);
        // Clip the chosen set to the public-visible set — anything else
        // is either ignorance (an old form field) or tampering.
        $chosen_list_ids = array_values(array_intersect(
            array_map('intval', $chosen_list_ids),
            $public_ids
        ));
        $current_all = $this->lists->memberships_for_recipient($kind, $id);
        $current_public = array_values(array_intersect($current_all, $public_ids));
        $to_add = array_diff($chosen_list_ids, $current_public);
        $to_remove = array_diff($current_public, $chosen_list_ids);
        foreach ($to_add as $list_id) {
            $this->lists->add_member((int) $list_id, $kind, $id);
        }
        foreach ($to_remove as $list_id) {
            $this->lists->remove_member((int) $list_id, $kind, $id);
        }
    }

    /**
     * Build the renderer state + emit the full prefs page. Two-tone
     * banner: green "Saved" flash on ?saved=1, neutral otherwise.
     */
    private function render_prefs_page(array $recipient, string $token, bool $from_unsub_link = false): void
    {
        $state = $this->build_state($recipient);
        $action_url = add_query_arg(self::QUERY_PREFS, $token, home_url('/'));
        $saved_flash = isset($_GET['saved']) && (string) $_GET['saved'] === '1';

        nocache_headers();
        status_header(200);
        $site_name = (string) get_bloginfo('name');
        $back_url = home_url('/');
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html(__('Email preferences', 'lrob-email-toolkit') . ' — ' . $site_name); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f6f7f7; margin: 0; padding: 2rem 1rem; color: #1d2327; }
        .lrob-etk-nl-prefs-page { max-width: 640px; margin: 3rem auto; background: #fff; border-radius: 8px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .lrob-etk-nl-prefs-page h1 { margin: 0 0 1rem; font-size: 1.5rem; }
        .lrob-etk-nl-prefs-page fieldset { border: 1px solid #dcdcde; border-radius: 6px; padding: 1rem 1.25rem; margin: 0 0 1.25rem; }
        .lrob-etk-nl-prefs-page legend { font-weight: 600; padding: 0 0.5em; }
        .lrob-etk-nl-prefs-page ul.lrob-etk-nl-prefs-checklist { list-style: none; padding: 0; margin: 0.5rem 0; }
        .lrob-etk-nl-prefs-page ul.lrob-etk-nl-prefs-checklist li { padding: 0.25rem 0; }
        .lrob-etk-nl-prefs-page label { cursor: pointer; line-height: 1.5; }
        .lrob-etk-nl-prefs-page p.description { color: #6b7280; font-size: 0.85rem; margin: 0.25rem 0; }
        .lrob-etk-nl-prefs-page button { font-family: inherit; font-size: 0.95rem; padding: 0.55rem 1.1rem; border-radius: 5px; cursor: pointer; border: 1px solid #2271b1; background: #2271b1; color: #fff; }
        .lrob-etk-nl-prefs-page button.lrob-etk-nl-prefs-secondary { background: #fff; color: #1d2327; }
        .lrob-etk-nl-prefs-page button.lrob-etk-nl-prefs-destructive { border-color: #b32d2e; color: #b32d2e; }
        .lrob-etk-nl-prefs-page details { margin-top: 1.5rem; padding: 0.75rem 1rem; background: #fef9ec; border-radius: 6px; }
        .lrob-etk-nl-prefs-page details summary { cursor: pointer; font-weight: 500; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-flash { background: #ecfdf5; border: 1px solid #34d399; color: #065f46; border-radius: 5px; padding: 0.6rem 0.9rem; margin-bottom: 1rem; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-email { margin: 0 0 1rem; color: #6b7280; }
        .lrob-etk-nl-prefs-page hr.lrob-etk-nl-prefs-divider { border: 0; border-top: 1px solid #e5e7eb; margin: 1.5rem 0; }
        .lrob-etk-nl-prefs-page-foot { font-size: 0.85rem; color: #6b7280; margin-top: 1rem; text-align: center; }
        .lrob-etk-nl-prefs-page-foot a { color: #2271b1; }
    </style>
</head>
<body>
    <main class="lrob-etk-nl-prefs-page">
        <h1><?php esc_html_e('Your email preferences', 'lrob-email-toolkit'); ?></h1>
        <?php if ($saved_flash) : ?>
            <div class="lrob-etk-nl-prefs-flash"><?php esc_html_e('Saved — your preferences have been updated.', 'lrob-email-toolkit'); ?></div>
        <?php endif; ?>
        <?php if ($from_unsub_link) : ?>
            <div class="lrob-etk-nl-prefs-flash" style="background:#fffbe6;border-color:#facc15;color:#5c4400">
                <?php esc_html_e('Use the form below to fine-tune what you receive — or unsubscribe entirely at the bottom.', 'lrob-email-toolkit'); ?>
            </div>
        <?php endif; ?>
        <?php echo PrefsRenderer::render_full_form($state, $action_url, self::NONCE_ACTION_PREFS); ?>
        <p class="lrob-etk-nl-prefs-page-foot">
            <a href="<?php echo esc_url($back_url); ?>"><?php echo esc_html($site_name); ?></a>
        </p>
    </main>
</body>
</html>
        <?php
        exit;
    }

    /**
     * Build the renderer state from a resolved recipient + the
     * public list catalogue.
     *
     * @param array<string, mixed> $recipient
     * @return array<string, mixed>
     */
    private function build_state(array $recipient): array
    {
        $kind = (string) $recipient['kind'];
        $id = (int) $recipient['id'];
        $email = (string) ($recipient['email'] ?? '');

        $opted_in = $kind === UserMeta::KIND_USER
            ? (string) get_user_meta($id, UserMeta::OPTED_IN, true) === '1'
            : true;

        $lists = $this->lists->list_public_for_subscribers();
        $member_ids = $this->lists->memberships_for_recipient($kind, $id);

        return [
            'kind'            => $kind,
            'id'              => $id,
            'email'           => $email,
            'opted_in'        => $opted_in,
            'list_member_ids' => $member_ids,
            'lists'           => array_map(
                static fn (array $l) => [
                    'id'          => (int) ($l['id'] ?? 0),
                    'name'        => (string) ($l['name'] ?? ''),
                    'description' => (string) ($l['description'] ?? ''),
                ],
                $lists
            ),
        ];
    }

    /**
     * Resolve a prefs token to a recipient. Tries subscribers first
     * (more common path, single indexed query), falls back to WP-
     * user user_meta (less efficient but rarer). Returns null when
     * neither matches.
     *
     * @return array<string, mixed>|null
     */
    private function resolve(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $subscriber = $this->subscribers->find_by_prefs_token($token);
        if ($subscriber !== null) {
            return [
                'kind'   => UserMeta::KIND_SUBSCRIBER,
                'id'     => (int) $subscriber['id'],
                'email'  => (string) $subscriber['email'],
                'status' => (string) ($subscriber['status'] ?? ''),
            ];
        }
        $users = get_users([
            'meta_key'   => UserMeta::PREFS_TOKEN,
            'meta_value' => $token,
            'number'     => 1,
            'fields'     => ['ID', 'user_email'],
        ]);
        if (is_array($users) && $users !== []) {
            $user = $users[0];
            return [
                'kind'  => UserMeta::KIND_USER,
                'id'    => (int) $user->ID,
                'email' => (string) $user->user_email,
            ];
        }
        return null;
    }

    /**
     * Self-contained acknowledgment page (token expired, unsubscribe
     * done, etc.). Same shape as ConfirmationHandler's render_page.
     */
    private static function render_message(string $title, string $body): never
    {
        nocache_headers();
        status_header(200);
        $site_name = (string) get_bloginfo('name');
        $back_url = home_url('/');
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($title . ' — ' . $site_name); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f6f7f7; margin: 0; padding: 2rem 1rem; color: #1d2327; }
        .lrob-etk-nl-ack { max-width: 540px; margin: 4rem auto; background: #fff; border-radius: 8px; padding: 2.5rem 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .lrob-etk-nl-ack h1 { margin: 0 0 1rem; font-size: 1.4rem; }
        .lrob-etk-nl-ack p { margin: 0 0 1.5rem; line-height: 1.55; }
        .lrob-etk-nl-ack a { color: #2271b1; }
        .lrob-etk-nl-ack-site { font-size: 0.85rem; color: #6b7280; }
    </style>
</head>
<body>
    <main class="lrob-etk-nl-ack">
        <h1><?php echo esc_html($title); ?></h1>
        <p><?php echo esc_html($body); ?></p>
        <p class="lrob-etk-nl-ack-site"><a href="<?php echo esc_url($back_url); ?>"><?php echo esc_html($site_name); ?></a></p>
    </main>
</body>
</html>
        <?php
        exit;
    }
}
