<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

// Docs: docs/newsletter-internals.md → "Prefs page / Email-change flow"
final class EmailChangeDispatcher
{
    public function send(int $subscriber_id, string $old_email, string $new_email, string $token): bool
    {
        $confirm_url = add_query_arg(PrefsHandler::QUERY_CONFIRM_EMAIL, $token, home_url('/'));
        $site_name = (string) get_bloginfo('name');

        $confirm_subject = sprintf(
            /* translators: %s: site name. */
            __('Confirm your new email address for %s', 'lrob-email-toolkit'),
            $site_name
        );

        $confirm_body = self::format_message(
            sprintf(
                /* translators: %1$s: site name, %2$s: previous email, %3$s: new email. */
                __('We received a request to move your %1$s subscription from %2$s to this address.', 'lrob-email-toolkit'),
                $site_name,
                $old_email,
                $new_email
            ),
            sprintf(
                /* translators: %s: confirmation URL. */
                __('Confirm the change: %s', 'lrob-email-toolkit'),
                $confirm_url
            ),
            __('The link expires in 24 hours. If you didn\'t request this, just ignore this email — nothing changes until you click.', 'lrob-email-toolkit')
        );

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $confirm_ok = wp_mail($new_email, $confirm_subject, $confirm_body, $headers);

        if (is_email($old_email)) {
            $notice_subject = sprintf(
                /* translators: %s: site name. */
                __('Email change requested for your %s subscription', 'lrob-email-toolkit'),
                $site_name
            );
            $notice_body = self::format_message(
                sprintf(
                    /* translators: %1$s: site name, %2$s: new email address. */
                    __('Someone (hopefully you) asked to move your %1$s subscription to %2$s.', 'lrob-email-toolkit'),
                    $site_name,
                    $new_email
                ),
                __('Nothing has changed yet — the new address has 24 hours to confirm the move.', 'lrob-email-toolkit'),
                __('If you didn\'t make this request, no action is needed — your subscription stays linked to this address.', 'lrob-email-toolkit')
            );
            wp_mail($old_email, $notice_subject, $notice_body, $headers);
        }

        unset($subscriber_id);
        return (bool) $confirm_ok;
    }

    private static function format_message(string $intro, string $action, string $tail): string
    {
        return '<p>' . esc_html($intro) . '</p>'
            . '<p>' . self::linkify($action) . '</p>'
            . '<p style="color:#6b7280;font-size:0.9em">' . esc_html($tail) . '</p>';
    }

    private static function linkify(string $text): string
    {
        if (preg_match('#^(.+?:\s*)(https?://\S+)$#', $text, $m) !== 1) {
            return esc_html($text);
        }
        return esc_html($m[1]) . '<a href="' . esc_url($m[2]) . '">' . esc_html($m[2]) . '</a>';
    }
}
