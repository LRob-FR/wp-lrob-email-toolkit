<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\SMTP\Admin\PageController;

/**
 * SMTP module — reconfigures the WordPress-bundled PHPMailer to route
 * wp_mail() through configured SMTP identities, with per-source routing.
 *
 * Public services exposed via the Container (so Logging can read identities):
 *   - IdentityRepository::class    → IdentityRepository
 *   - SourceResolver::class        → SourceResolver
 *   - RoutingRules::class          → RoutingRules
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

    public function install(): void
    {
        Schema::install();
    }

    public function uninstall(): void
    {
        Schema::drop();
    }

    public function register(): void
    {
        $identities = new IdentityRepository();
        $source_resolver = new SourceResolver();
        $routing = new RoutingRules($identities);
        $overrides = new ConstantOverrides();

        $router = new MailRouter($identities, $routing, $source_resolver, $overrides);
        $router->register();

        $this->container->set(IdentityRepository::class, $identities);
        $this->container->set(SourceResolver::class, $source_resolver);
        $this->container->set(RoutingRules::class, $routing);
        $this->container->set(ConstantOverrides::class, $overrides);
        $this->container->set(MailRouter::class, $router);

        if (is_admin()) {
            $tester = new TestSender($identities, $router);
            (new PageController($identities, $routing, $overrides, $tester))->register();
        }
    }
}
