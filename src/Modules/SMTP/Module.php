<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\SMTP\Admin\AjaxController;
use LRob\EmailToolkit\Modules\SMTP\Admin\PageController;

// Docs: docs/smtp.md
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
            'Send emails through your own SMTP servers.',
            'lrob-email-toolkit'
        );
    }

    public function version(): string
    {
        return '0.0.1';
    }

    public function db_version_int(): int
    {
        return 4;
    }

    /** Forward every migration to install() — Schema::install handles each transition idempotently. */
    public function migrate(int $from_version, int $to_version): void
    {
        unset($from_version, $to_version);
        $this->install();
    }

    public function install(): void
    {
        Schema::install();
    }

    public function uninstall(): void
    {
        Schema::drop();
    }

    public function admin_page_url(): ?string
    {
        return admin_url('admin.php?page=' . PageController::SLUG);
    }

    public function data_summary(): string
    {
        $count = count((new IdentityRepository())->all());
        if ($count === 0) {
            return '';
        }
        return sprintf(
            /* translators: %d: number of SMTP identities. */
            _n('%d identity', '%d identities', $count, 'lrob-email-toolkit'),
            $count
        );
    }

    public function register(): void
    {
        $identities = new IdentityRepository();
        $source_resolver = new SourceResolver();
        $routing = new RoutingRules($identities);
        $overrides = new ConstantOverrides();

        $this->container->set(IdentityRepository::class, $identities);
        $this->container->set(SourceResolver::class, $source_resolver);
        $this->container->set(RoutingRules::class, $routing);
        $this->container->set(ConstantOverrides::class, $overrides);

        if ($this->is_enabled()) {
            $router = new MailRouter($identities, $routing, $source_resolver, $overrides);
            $router->register();
            $this->container->set(MailRouter::class, $router);
        }

        if (is_admin()) {
            add_action('admin_post_' . $this->toggle_action(), [$this, 'handle_toggle']);

            $auth_tester = new AuthTester();
            $test_sender = new TestSender($identities, $overrides);
            $dns = new DnsLookup();
            (new AjaxController($identities, $routing, $overrides, $auth_tester, $test_sender, $dns))->register();

            (new PageController($this, $identities, $routing, $overrides))->register();
        }
    }
}
