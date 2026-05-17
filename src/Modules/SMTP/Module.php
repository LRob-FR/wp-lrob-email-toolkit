<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\SMTP\Admin\PageController;

/**
 * SMTP module — reconfigures the WordPress-bundled PHPMailer to route
 * wp_mail() through configured SMTP identities, with per-source routing.
 *
 * Admin page is always registered so users can find and configure SMTP from
 * the start; the actual phpmailer_init wiring only activates when the module
 * is enabled via the in-page toggle.
 *
 * Public services exposed via the Container (when enabled, for cross-module access):
 *   - IdentityRepository::class
 *   - SourceResolver::class
 *   - RoutingRules::class
 *   - MailRouter::class
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

    public function admin_page_url(): ?string
    {
        return admin_url('admin.php?page=' . PageController::SLUG);
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

        // Runtime: only hook PHPMailer when the module is enabled.
        if ($this->is_enabled()) {
            $router = new MailRouter($identities, $routing, $source_resolver, $overrides);
            $router->register();
            $this->container->set(MailRouter::class, $router);
        }

        // Admin: always available so users can configure and toggle.
        if (is_admin()) {
            add_action('admin_post_' . $this->toggle_action(), [$this, 'handle_toggle']);

            $tester = new TestSender($identities, $overrides);
            (new PageController($this, $identities, $routing, $overrides, $tester))->register();
        }
    }
}
