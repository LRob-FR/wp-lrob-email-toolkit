<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging;

use LRob\EmailToolkit\Modules\AbstractModule;

/**
 * Logging module — captures every outgoing email (headers, body, attachments,
 * status, errors), exposes browse/search/resend in the admin, and provides the
 * IMAP "Save to Sent" archive feature via a custom mini-IMAP client.
 *
 * Skeleton only at v0.0.1.
 */
final class Module extends AbstractModule
{
    public function slug(): string
    {
        return 'logging';
    }

    public function name(): string
    {
        return __('Email Logging', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __(
            'Log every outgoing email and optionally archive a copy to your IMAP Sent folder.',
            'lrob-email-toolkit'
        );
    }

    public function version(): string
    {
        return '0.0.1';
    }

    public function register(): void
    {
        // Runtime hooks land in a later commit.
    }
}
