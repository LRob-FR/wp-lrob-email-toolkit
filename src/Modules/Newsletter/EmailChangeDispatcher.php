<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Sends the two-sided email-change confirmation for a subscriber:
 *
 *   - Confirm message → goes to the NEW address. Contains the
 *     click-to-confirm URL embedding the single-use token.
 *   - Notice message → goes to the OLD address. Lets the original
 *     owner know a change was requested + that nothing happens
 *     until the new address confirms. No "Cancel" link — they can
 *     act from their own prefs page.
 *
 * Plain-HTML wp_mail; no TemplateCPT involvement. The wording is
 * load-bearing for trust + needs to be obvious to a non-technical
 * recipient; locking it in code avoids template misconfiguration
 * blocking confirmations. If admin wants custom wording later, add
 * a `lrob_etk_nl_email_change_message` filter.
 *
 * Failure to send the OLD-address notice is non-fatal — the confirm
 * message is the load-bearing one. Both wp_mail calls are best-effort;
 * the SMTP module logs failures through its own pipeline.
 */
final class EmailChangeDispatcher
{
    /**
     * @return bool true when the confirm message went out (the notice
     *              to the old address is best-effort and doesn't gate
     *              the boolean — caller doesn't care).
     */
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

        // Notice to the old address — best-effort, doesn't gate the
        // return value. Skipped if the old address looks malformed.
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

    /**
     * Tiny HTML wrap — three paragraphs. No fancy template chrome;
     * legibility + trust matter more than branding here. Links auto-
     * detected from "Confirm the change: <url>" so the URL is shown
     * as a clickable anchor when possible.
     */
    private static function format_message(string $intro, string $action, string $tail): string
    {
        return '<p>' . esc_html($intro) . '</p>'
            . '<p>' . self::linkify($action) . '</p>'
            . '<p style="color:#6b7280;font-size:0.9em">' . esc_html($tail) . '</p>';
    }

    /**
     * Convert the trailing URL of `Label: https://…` into an anchor.
     * If no URL is found, escape the whole string.
     */
    private static function linkify(string $text): string
    {
        if (preg_match('#^(.+?:\s*)(https?://\S+)$#', $text, $m) !== 1) {
            return esc_html($text);
        }
        return esc_html($m[1]) . '<a href="' . esc_url($m[2]) . '">' . esc_html($m[2]) . '</a>';
    }
}
