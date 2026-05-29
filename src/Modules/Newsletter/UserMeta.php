<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

// Docs: docs/newsletter-internals.md → "WP-user-side state"
final class UserMeta
{
    public const OPTED_IN          = 'lrob_etk_nl_opted_in';

    public const STATUS            = 'lrob_etk_nl_status';

    public const BOUNCE_COUNT      = 'lrob_etk_nl_bounce_count';

    public const PREFS_TOKEN       = 'lrob_etk_nl_prefs_token';

    public const CONFIRMED_AT      = 'lrob_etk_nl_confirmed_at';

    public const SOURCE            = 'lrob_etk_nl_source';

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

    public static function generate_prefs_token(): string
    {
        return bin2hex(random_bytes(24));
    }
}
