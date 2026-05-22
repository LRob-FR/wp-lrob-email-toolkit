<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Send;

/**
 * Always-on footer appended to every sent newsletter. Carries the
 * Preferences + Unsubscribe links — visible counterparts to the
 * List-Unsubscribe header.
 *
 * The admin customises THREE plain text fields (no HTML editing):
 *
 *   - intro: short sentence explaining why the recipient got this
 *     email. May reference `{{site_name}}` etc.; the link to
 *     `{{site_url}}` is wired in by the composer below.
 *   - prefs_label: text for the "manage preferences" link.
 *   - unsub_label: text for the "unsubscribe" link.
 *
 * The composer builds the styled HTML (inline-styled `<table>` for
 * cross-client centering) automatically. Admins never see angle
 * brackets, and the unsubscribe link is structurally guaranteed —
 * no fail-open path where missing markup ships footerless mail.
 *
 * Token substitution happens via NewsletterRenderer's existing pass:
 * `{{prefs_url}}`, `{{unsub_url}}`, `{{site_name}}`, `{{site_url}}`
 * placeholders survive into the composed HTML and get replaced
 * per-recipient at send time.
 */
final class NewsletterFooter
{
    public const OPTION_INTRO         = 'lrob_etk_nl_footer_intro';

    public const OPTION_PREFS_LABEL   = 'lrob_etk_nl_footer_prefs_label';

    public const OPTION_UNSUB_LABEL   = 'lrob_etk_nl_footer_unsub_label';

    /**
     * Resolve the active footer HTML by composing the three stored
     * text fields (falling back to their defaults when empty) into
     * the email-safe `<table>` markup.
     */
    public static function resolve(): string
    {
        $intro       = self::resolve_intro();
        $prefs_label = self::resolve_prefs_label();
        $unsub_label = self::resolve_unsub_label();
        return self::compose($intro, $prefs_label, $unsub_label);
    }

    public static function default_intro(): string
    {
        return __('You\'re receiving this email because you subscribed at {{site_name}}.', 'lrob-email-toolkit');
    }

    public static function default_prefs_label(): string
    {
        return __('Manage preferences', 'lrob-email-toolkit');
    }

    public static function default_unsub_label(): string
    {
        return __('Unsubscribe', 'lrob-email-toolkit');
    }

    public static function resolve_intro(): string
    {
        $stored = trim((string) get_option(self::OPTION_INTRO, ''));
        return $stored !== '' ? $stored : self::default_intro();
    }

    public static function resolve_prefs_label(): string
    {
        $stored = trim((string) get_option(self::OPTION_PREFS_LABEL, ''));
        return $stored !== '' ? $stored : self::default_prefs_label();
    }

    public static function resolve_unsub_label(): string
    {
        $stored = trim((string) get_option(self::OPTION_UNSUB_LABEL, ''));
        return $stored !== '' ? $stored : self::default_unsub_label();
    }

    /**
     * Build the email-safe footer HTML from the three text parts.
     * Inline-styled `<table>` so it survives Outlook desktop +
     * gmail's CSS-stripping; the wrapper rule of "no class-driven
     * styling for email" applies here too.
     *
     * The intro line is wrapped through esc_html so any stray `<`
     * the admin types is rendered as text, but the `{{tokens}}` stay
     * literal so NewsletterRenderer's substitution still matches
     * (esc_html doesn't touch `{` or `}`).
     */
    private static function compose(string $intro, string $prefs_label, string $unsub_label): string
    {
        $safe_intro       = esc_html($intro);
        $safe_prefs_label = esc_html($prefs_label);
        $safe_unsub_label = esc_html($unsub_label);
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:32px; padding-top:16px; border-top:1px solid #e5e7eb; font-size:12px; color:#6b7280; text-align:center; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;">'
            . '<tr><td style="padding:4px 0;">' . $safe_intro . '</td></tr>'
            . '<tr><td style="padding:4px 0;">'
            . '<a href="{{prefs_url}}" style="color:#2271b1; text-decoration:underline;">' . $safe_prefs_label . '</a>'
            . ' &nbsp;·&nbsp; '
            . '<a href="{{unsub_url}}" style="color:#b32d2e; text-decoration:underline;">' . $safe_unsub_label . '</a>'
            . '</td></tr>'
            . '</table>';
    }
}
