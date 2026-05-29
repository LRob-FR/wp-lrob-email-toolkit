<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging;

use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\Logging\Admin\AjaxController;
use LRob\EmailToolkit\Modules\Logging\Admin\PageController;

// Docs: docs/logging.md
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
            'Log every outgoing email.',
            'lrob-email-toolkit'
        );
    }

    public function version(): string
    {
        return '0.0.1';
    }

    // db_version=2: bump from the AbstractModule default of 1 so maybe_migrate() triggers the campaign_id→newsletter_id ALTER on upgrade sites.
    public function db_version_int(): int
    {
        return 2;
    }

    // Forward to install(): Schema::install() and wp_schedule_event are both idempotent.
    public function migrate(int $from_version, int $to_version): void
    {
        unset($from_version, $to_version);
        $this->install();
    }

    public function install(): void
    {
        Schema::install();

        if (false === get_option(RetentionCron::OPTION_RETENTION_DAYS)) {
            add_option(RetentionCron::OPTION_RETENTION_DAYS, RetentionCron::DEFAULT_RETENTION_DAYS);
        }

        (new RetentionCron(new LogRepository()))->schedule();
    }

    public function uninstall(): void
    {
        (new RetentionCron(new LogRepository()))->unschedule();
        Schema::drop();
        delete_option(RetentionCron::OPTION_RETENTION_DAYS);
    }

    public function admin_page_url(): ?string
    {
        return admin_url('admin.php?page=' . PageController::SLUG);
    }

    public function data_summary(): string
    {
        $count = (new LogRepository())->count();
        if ($count === 0) {
            return '';
        }
        return sprintf(
            /* translators: %s: number of log entries (already formatted with i18n thousands separator). */
            _n('%s log entry', '%s log entries', $count, 'lrob-email-toolkit'),
            number_format_i18n($count)
        );
    }

    public function register(): void
    {
        $repository = new LogRepository();
        $this->container->set(LogRepository::class, $repository);

        if ($this->is_enabled()) {
            $logger = new Logger($repository);
            $logger->register();
            $this->container->set(Logger::class, $logger);

            $cron = new RetentionCron($repository);
            $cron->register();
        }

        if (is_admin()) {
            add_action('admin_post_' . $this->toggle_action(), [$this, 'handle_toggle']);

            $resender = new Resender($repository);
            (new PageController($this, $repository))->register();
            (new AjaxController($repository, $resender))->register();
        }
    }
}
