<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Send;

/**
 * Always-on footer appended to every sent newsletter. Carries the
 * Preferences + Unsubscribe links — visible counterparts to the
 * List-Unsubscribe header.
 *
 * The admin can customise the HTML (Settings → Newsletter footer)
 * but `{{unsub_url}}` is enforced: if the saved footer is missing
 * that token, we fall back to the canonical default rather than
 * silently send mail without an unsubscribe path.
 *
 * Token substitution happens via NewsletterRenderer's existing pass
 * — this class just hands back HTML with `{{prefs_url}}` /
 * `{{unsub_url}}` placeholders intact.
 */
final class NewsletterFooter
{
    public const OPTION_HTML = 'lrob_etk_nl_newsletter_footer';

    private const REQUIRED_TOKEN = '{{unsub_url}}';

    /**
     * Resolve the active footer HTML. Falls back to the default
     * whenever the stored value is empty or missing the required
     * unsubscribe token.
     */
    public static function resolve(): string
    {
        $stored = (string) get_option(self::OPTION_HTML, '');
        if ($stored === '' || !str_contains($stored, self::REQUIRED_TOKEN)) {
            return self::default_html();
        }
        return $stored;
    }

    /**
     * The shipped default. Plain inline-styled HTML — no CSS classes,
     * works in every email client. Translators get the strings as one
     * unit so the layout can be rearranged per locale.
     */
    public static function default_html(): string
    {
        $intro = __('You\'re receiving this email because you subscribed at', 'lrob-email-toolkit');
        $prefs = __('Manage preferences', 'lrob-email-toolkit');
        $unsub = __('Unsubscribe', 'lrob-email-toolkit');
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:32px; padding-top:16px; border-top:1px solid #e5e7eb; font-size:12px; color:#6b7280; text-align:center; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;">'
            . '<tr><td style="padding:4px 0;">'
            . esc_html($intro) . ' <a href="{{site_url}}" style="color:#6b7280;">{{site_name}}</a>.'
            . '</td></tr>'
            . '<tr><td style="padding:4px 0;">'
            . '<a href="{{prefs_url}}" style="color:#2271b1; text-decoration:underline;">' . esc_html($prefs) . '</a>'
            . ' &nbsp;·&nbsp; '
            . '<a href="{{unsub_url}}" style="color:#b32d2e; text-decoration:underline;">' . esc_html($unsub) . '</a>'
            . '</td></tr>'
            . '</table>';
    }
}
