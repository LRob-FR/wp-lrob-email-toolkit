<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Support\Events;

/**
 * Processes the confirm / refuse links sent in the double-opt-in
 * email. Catches `?lrob-etk-nl-confirm=<token>` and
 * `?lrob-etk-nl-refuse=<token>` on any frontend URL, verifies the
 * HMAC, flips the subscriber's status, fires the matching event,
 * and renders a small acknowledgment page.
 *
 * Hooks on `init` so we run before WordPress's template loader
 * decides what to render — the acknowledgment page replaces the
 * entire response body, no theme template needed.
 */
final class ConfirmationHandler
{
    public const QUERY_CONFIRM = 'lrob-etk-nl-confirm';

    public const QUERY_REFUSE  = 'lrob-etk-nl-refuse';

    public function __construct(private SubscriberRepository $subscribers)
    {
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
        if (isset($_GET[self::QUERY_CONFIRM])) {
            $this->handle_confirm((string) wp_unslash((string) $_GET[self::QUERY_CONFIRM]));
            return;
        }
        if (isset($_GET[self::QUERY_REFUSE])) {
            $this->handle_refuse((string) wp_unslash((string) $_GET[self::QUERY_REFUSE]));
            return;
        }
    }

    private function handle_confirm(string $token): void
    {
        $subscriber_id = ConfirmationTokens::verify($token, ConfirmationTokens::ACTION_CONFIRM);
        if ($subscriber_id <= 0) {
            self::render_page(
                __('Link expired', 'lrob-email-toolkit'),
                __('This confirmation link is no longer valid. If you still want to subscribe, please sign up again — we\'ll send a fresh confirmation email.', 'lrob-email-toolkit')
            );
        }
        $row = $this->subscribers->find_by_id($subscriber_id);
        if (!is_array($row)) {
            self::render_page(
                __('Link expired', 'lrob-email-toolkit'),
                __('We couldn\'t find this subscription anymore. It may have been removed.', 'lrob-email-toolkit')
            );
        }
        $status = (string) ($row['status'] ?? '');
        if ($status === 'confirmed') {
            self::render_page(
                __('Already confirmed', 'lrob-email-toolkit'),
                __('Your subscription is already confirmed — you\'re all set.', 'lrob-email-toolkit')
            );
        }
        $this->subscribers->update_status($subscriber_id, 'confirmed', current_time('mysql', true));
        Events::dispatch('newsletter.subscriber.confirmed', [
            'recipient_kind'  => UserMeta::KIND_SUBSCRIBER,
            'recipient_id'    => $subscriber_id,
            'email'           => (string) ($row['email'] ?? ''),
            'previous_status' => $status,
            'via'             => 'confirmation_link',
        ]);
        self::render_page(
            __('Subscription confirmed', 'lrob-email-toolkit'),
            __('Thanks for confirming! You\'re now subscribed. Look out for our next email.', 'lrob-email-toolkit')
        );
    }

    private function handle_refuse(string $token): void
    {
        $subscriber_id = ConfirmationTokens::verify($token, ConfirmationTokens::ACTION_REFUSE);
        if ($subscriber_id <= 0) {
            self::render_page(
                __('Link expired', 'lrob-email-toolkit'),
                __('This link is no longer valid.', 'lrob-email-toolkit')
            );
        }
        $row = $this->subscribers->find_by_id($subscriber_id);
        if (!is_array($row)) {
            self::render_page(
                __('Already handled', 'lrob-email-toolkit'),
                __('We\'ve already taken care of this — thanks.', 'lrob-email-toolkit')
            );
        }
        $status = (string) ($row['status'] ?? '');
        $this->subscribers->update_status($subscriber_id, 'refused');
        Events::dispatch('newsletter.subscriber.refused', [
            'recipient_kind'  => UserMeta::KIND_SUBSCRIBER,
            'recipient_id'    => $subscriber_id,
            'email'           => (string) ($row['email'] ?? ''),
            'previous_status' => $status,
            'via'             => 'confirmation_link',
        ]);
        self::render_page(
            __('Subscription declined', 'lrob-email-toolkit'),
            __('No problem — we won\'t add you to the list. Sorry for the inconvenience.', 'lrob-email-toolkit')
        );
    }

    /**
     * Render a self-contained acknowledgment page and exit. Doesn't
     * use the site theme since this URL is hit from email clients
     * landing on whatever page happens to be home_url() — keeping
     * the response minimal and theme-agnostic avoids surprises.
     */
    private static function render_page(string $title, string $body): never
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
        <p class="lrob-etk-nl-ack-site">
            <a href="<?php echo esc_url($back_url); ?>"><?php echo esc_html($site_name); ?></a>
        </p>
    </main>
</body>
</html>
        <?php
        exit;
    }
}
