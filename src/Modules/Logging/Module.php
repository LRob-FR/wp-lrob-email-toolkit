<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging;

use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\Logging\Admin\PageController;

/**
 * Logging module — captures every outgoing email and exposes browse / search
 * / resend in the admin. Independent of the SMTP module; if SMTP is off,
 * emails are still logged (just with less context).
 *
 * Public services exposed via the Container:
 *   - LogRepository::class → LogRepository (used by future IMAP save-to-sent worker)
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

    public function install(): void
    {
        Schema::install();

        if (false === get_option(RetentionCron::OPTION_RETENTION_DAYS)) {
            add_option(RetentionCron::OPTION_RETENTION_DAYS, RetentionCron::DEFAULT_RETENTION_DAYS);
        }

        // Schedule the daily purge if not already scheduled. The new RetentionCron
        // instance is short-lived; it just sets the wp_schedule_event.
        (new RetentionCron(new LogRepository()))->schedule();
    }

    public function uninstall(): void
    {
        (new RetentionCron(new LogRepository()))->unschedule();
        Schema::drop();
        delete_option(RetentionCron::OPTION_RETENTION_DAYS);
    }

    public function register(): void
    {
        $repository = new LogRepository();
        $logger = new Logger($repository);
        $logger->register();

        $cron = new RetentionCron($repository);
        $cron->register();

        $this->container->set(LogRepository::class, $repository);
        $this->container->set(Logger::class, $logger);

        if (is_admin()) {
            $resender = new Resender($repository);
            (new PageController($repository, $resender))->register();
        }
    }
}
