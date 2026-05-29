<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Support\Events;

// Docs: docs/newsletter-internals.md → "Prefs page + one-click unsubscribe"
final class PrefsHandler
{
    public const QUERY_PREFS = 'lrob-etk-nl-prefs';

    public const QUERY_UNSUB = 'lrob-etk-nl-unsub';

    public const QUERY_CONFIRM_EMAIL = 'lrob-etk-nl-confirm-email';

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
        if (isset($_GET[self::QUERY_CONFIRM_EMAIL])) {
            $this->handle_confirm_email((string) wp_unslash((string) $_GET[self::QUERY_CONFIRM_EMAIL]));
            return;
        }
    }

    private function handle_confirm_email(string $token): void
    {
        $outcome = $this->subscribers->confirm_pending_email_change($token);
        switch ($outcome) {
            case 'ok':
                self::render_message(
                    __('Email confirmed', 'lrob-email-toolkit'),
                    __('Your email address has been updated. You can keep using the previous preferences link or request a fresh one from any of our emails.', 'lrob-email-toolkit')
                );
                break;
            case 'expired':
                self::render_message(
                    __('Link expired', 'lrob-email-toolkit'),
                    __('This confirmation link is older than 24 hours. Go back to your preferences page and request the change again.', 'lrob-email-toolkit')
                );
                break;
            case 'email_taken':
                self::render_message(
                    __('Address no longer available', 'lrob-email-toolkit'),
                    __('That email is already linked to another subscription. Pick a different address from your preferences page.', 'lrob-email-toolkit')
                );
                break;
            default:
                self::render_message(
                    __('Link expired', 'lrob-email-toolkit'),
                    __('This email-change link is no longer valid.', 'lrob-email-toolkit')
                );
        }
    }

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

        if ($kind === UserMeta::KIND_SUBSCRIBER && !empty($_POST['lrob_etk_nl_request_email_change'])) {
            $this->handle_email_change_request($recipient);
        }
        if ($kind === UserMeta::KIND_SUBSCRIBER && !empty($_POST['lrob_etk_nl_cancel_email_change'])) {
            $this->subscribers->cancel_pending_email_change($id);
            $return_to = isset($_POST['_lrob_etk_nl_return_to']) ? (string) wp_unslash((string) $_POST['_lrob_etk_nl_return_to']) : '';
            $redirect = $return_to !== ''
                ? add_query_arg(['lrob_etk_nl_saved' => '1'], $return_to)
                : add_query_arg([self::QUERY_PREFS => $token, 'saved' => '1'], home_url('/'));
            wp_safe_redirect($redirect);
            exit;
        }

        if ($kind === UserMeta::KIND_SUBSCRIBER && isset($_POST['profile']) && is_array($_POST['profile'])) {
            $payload = wp_unslash($_POST['profile']);
            foreach ($payload as $column => $value) {
                $col = (string) $column;
                if ($col === 'email') {
                    continue;
                }
                if (!in_array($col, SubscriberFields::PROFILE_COLUMNS, true)) {
                    continue;
                }
                $this->subscribers->set_profile_field($id, $col, (string) $value);
            }
        }

        $chosen_lists = isset($_POST['lrob_etk_nl_lists']) && is_array($_POST['lrob_etk_nl_lists'])
            ? array_map('intval', wp_unslash($_POST['lrob_etk_nl_lists']))
            : [];

        if ($kind === UserMeta::KIND_USER) {
            $opted_in = !empty($_POST['lrob_etk_nl_opted_in']);
            update_user_meta($id, UserMeta::OPTED_IN, $opted_in ? '1' : '0');
        }
        $this->sync_public_list_memberships($kind, $id, $chosen_lists);

        $return_to = isset($_POST['_lrob_etk_nl_return_to'])
            ? (string) wp_unslash((string) $_POST['_lrob_etk_nl_return_to'])
            : '';
        $redirect = $return_to !== ''
            ? add_query_arg(['lrob_etk_nl_saved' => '1'], $return_to)
            : add_query_arg([self::QUERY_PREFS => $token, 'saved' => '1'], home_url('/'));
        wp_safe_redirect($redirect);
        exit;
    }

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

    private function handle_email_change_request(array $recipient): void
    {
        $id = (int) $recipient['id'];
        $old_email = (string) ($recipient['email'] ?? '');
        $new_email = isset($_POST['lrob_etk_nl_new_email'])
            ? sanitize_email((string) wp_unslash((string) $_POST['lrob_etk_nl_new_email']))
            : '';

        [$status, $token] = $this->subscribers->set_pending_email_change($id, $new_email);
        if ($status !== 'ok') {
            $msg = match ($status) {
                'invalid'     => __('That address doesn\'t look valid. Please double-check and try again.', 'lrob-email-toolkit'),
                'same'        => __('That\'s already your email — nothing to change.', 'lrob-email-toolkit'),
                'email_taken' => __('Another subscription already uses that address. Pick a different one.', 'lrob-email-toolkit'),
                default       => __('We couldn\'t stage the change. Please try again.', 'lrob-email-toolkit'),
            };
            self::render_message(__('Email change rejected', 'lrob-email-toolkit'), $msg);
        }

        $sent = (new EmailChangeDispatcher())->send($id, $old_email, $new_email, $token);
        if (!$sent) {
            // Token + pending row still exist; we just couldn't dispatch
            // the confirmation. Tell the subscriber so they can ask the
            // admin to resend rather than silently waiting on a missed
            // email. The pending state stays in place so the admin can
            // see it from their detail modal.
            self::render_message(
                __('Confirmation email failed', 'lrob-email-toolkit'),
                __('We staged the change but couldn\'t send the confirmation email to the new address. Please contact the site admin.', 'lrob-email-toolkit')
            );
        }

        Events::dispatch('newsletter.subscriber.email_change_requested', [
            'subscriber_id' => $id,
            'old_email'     => $old_email,
            'new_email'     => $new_email,
        ]);

        self::render_message(
            __('Check your new inbox', 'lrob-email-toolkit'),
            sprintf(
                /* translators: %s: new email address awaiting confirmation. */
                __('We sent a confirmation link to %s. The change kicks in once you click it (24h to confirm). The previous address has been notified.', 'lrob-email-toolkit'),
                $new_email
            )
        );
    }

    private function sync_public_list_memberships(string $kind, int $id, array $chosen_list_ids): void
    {
        $public_lists = $this->lists->list_public_for_subscribers();
        $public_ids = array_map(static fn ($l) => (int) $l['id'], $public_lists);
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
        .lrob-etk-nl-prefs-page section[class^="lrob-etk-nl-prefs-"] { border: 1px solid #dcdcde; border-radius: 6px; padding: 1rem 1.25rem; margin: 0 0 1.25rem; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-section-title { font-size: 1rem; font-weight: 600; margin: 0 0 0.6rem; color: #1d2327; }
        .lrob-etk-nl-prefs-page ul.lrob-etk-nl-prefs-checklist { list-style: none; padding: 0; margin: 0.5rem 0; }
        .lrob-etk-nl-prefs-page ul.lrob-etk-nl-prefs-checklist li { padding: 0.25rem 0; }
        .lrob-etk-nl-prefs-page label { cursor: pointer; line-height: 1.5; }
        .lrob-etk-nl-prefs-page p.description { color: #6b7280; font-size: 0.85rem; margin: 0.25rem 0; }
        .lrob-etk-nl-prefs-page button { font-family: inherit; font-size: 0.95rem; padding: 0.55rem 1.1rem; border-radius: 5px; cursor: pointer; border: 1px solid #2271b1; background: #2271b1; color: #fff; }
        .lrob-etk-nl-prefs-page button.lrob-etk-nl-prefs-secondary { background: #fff; color: #1d2327; }
        .lrob-etk-nl-prefs-page button.lrob-etk-nl-prefs-destructive { border-color: #b32d2e; color: #b32d2e; }
        .lrob-etk-nl-prefs-page input[type="text"],
        .lrob-etk-nl-prefs-page input[type="email"],
        .lrob-etk-nl-prefs-page input[type="tel"],
        .lrob-etk-nl-prefs-page select { font-family: inherit; font-size: 0.95rem; padding: 0.4rem 0.55rem; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff; width: 100%; box-sizing: border-box; }
        .lrob-etk-nl-prefs-page input:focus, .lrob-etk-nl-prefs-page select:focus { outline: 2px solid #2271b1; outline-offset: -1px; border-color: #2271b1; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-profile-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.6rem 0.8rem; margin-top: 0.4rem; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-profile-grid label { display: flex; flex-direction: column; gap: 0.25rem; cursor: text; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-profile-grid label > span { font-size: 0.8rem; color: #6b7280; font-weight: 500; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-profile-address { margin-top: 1rem; padding-top: 0.75rem; border-top: 1px dashed #e5e7eb; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-profile-address-title { display: block; font-size: 0.8rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.5rem; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-profile-row { display: flex; flex-direction: column; gap: 0.25rem; cursor: text; margin: 0 0 0.5rem; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-profile-row > span { font-size: 0.8rem; color: #6b7280; font-weight: 500; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-email-change-row { display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 0.6rem; cursor: text; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-email-change-row > span { font-size: 0.8rem; color: #6b7280; font-weight: 500; }
        .lrob-etk-nl-prefs-page .lrob-etk-nl-prefs-email-pending { padding: 0.5rem 0.75rem; background: #fef9ec; border-left: 3px solid #facc15; border-radius: 4px; margin-bottom: 0.6rem; color: #5c4400; }
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

        $profile = [];
        $pending_email = '';
        if ($kind === UserMeta::KIND_SUBSCRIBER) {
            $row = $this->subscribers->find_by_id($id);
            if (is_array($row)) {
                foreach (SubscriberFields::PROFILE_COLUMNS as $col) {
                    if ($col === 'email') {
                        continue;
                    }
                    $profile[$col] = (string) ($row[$col] ?? '');
                }
                $pending_email = (string) ($row['pending_email'] ?? '');
            }
        }

        return [
            'kind'            => $kind,
            'id'              => $id,
            'email'           => $email,
            'opted_in'        => $opted_in,
            'list_member_ids' => $member_ids,
            'profile'         => $profile,
            'pending_email'   => $pending_email,
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

    /** @return array<string, mixed>|null */
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
