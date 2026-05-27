<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * User-meta key registry + small helpers for WP-user-side newsletter state.
 * WP users are first-class recipients — their opt-in flag, bounce
 * counter, prefs token, etc. live as `lrob_etk_nl_*`
 * user_meta and travel with the user (deleting the user takes the whole
 * state with them, no cleanup needed on this side).
 *
 * The `kind` constants (`user`, `subscriber`) are shared between user_meta
 * code and the lists/newsletter_recipients junction tables — same string in
 * both places.
 *
 * Status enum on the user_meta side is simpler than the subscriber-row
 * enum: WP users can't be `pending` (their account exists, no double-opt-in
 * needed) and can't be `trashed`/`refused` (those are subscriber-only soft
 * states). Effective status is just active vs bounced.
 */
final class UserMeta
{
    public const OPTED_IN          = 'lrob_etk_nl_opted_in';

    public const STATUS            = 'lrob_etk_nl_status';

    public const BOUNCE_COUNT      = 'lrob_etk_nl_bounce_count';

    public const PREFS_TOKEN       = 'lrob_etk_nl_prefs_token';

    public const CONFIRMED_AT      = 'lrob_etk_nl_confirmed_at';

    public const SOURCE            = 'lrob_etk_nl_source';

    /**
     * Lifetime engagement keys mirroring the subscribers-table columns
     * (Schema v8). Materializer bumps total_sent + sends_since_engagement
     * + last_sent_at on every send; tracking endpoints bump total_opened/
     * total_clicked + last_engagement_at (and reset sends_since_engagement
     * to 0 on click, or on open when engagement_counts_opens is on).
     */
    public const TOTAL_SENT             = 'lrob_etk_nl_total_sent';

    public const TOTAL_OPENED           = 'lrob_etk_nl_total_opened';

    public const TOTAL_CLICKED          = 'lrob_etk_nl_total_clicked';

    public const SENDS_SINCE_ENGAGEMENT = 'lrob_etk_nl_sends_since_engagement';

    public const LAST_SENT_AT           = 'lrob_etk_nl_last_sent_at';

    public const LAST_ENGAGEMENT_AT     = 'lrob_etk_nl_last_engagement_at';

    public const STATUS_ACTIVE  = 'active';

    public const STATUS_BOUNCED = 'bounced';

    /** Recipient-kind tags used in list_members / newsletter_recipients / tracking_events. */
    public const KIND_USER       = 'user';

    public const KIND_SUBSCRIBER = 'subscriber';

    /**
     * Per-recipient opaque token used to identify them in tokenised prefs
     * URLs without needing them to log in. Cryptographically random — not
     * a secret per se (a leaked token only exposes that recipient's
     * preferences page), but unguessable. Stored as-is; rotation requires
     * regenerating + redistributing.
     */
    public static function generate_prefs_token(): string
    {
        return bin2hex(random_bytes(24));
    }
}
