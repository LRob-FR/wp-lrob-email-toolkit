<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

use LRob\EmailToolkit\Modules\AbstractModule;

/**
 * SMTP module — reconfigures the WordPress-bundled PHPMailer to route
 * wp_mail() through a configured SMTP server. Supports multiple identities
 * (one SMTP login per identity) with per-source routing.
 *
 * Skeleton only at v0.0.1; runtime wiring lands in the SMTP implementation
 * pass.
 */
final class Module extends AbstractModule
{
    public function slug(): string
    {
        return 'smtp';
    }

    public function name(): string
    {
        return __('SMTP', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __(
            'Route outgoing emails through one or more SMTP servers, with per-source identity routing.',
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
