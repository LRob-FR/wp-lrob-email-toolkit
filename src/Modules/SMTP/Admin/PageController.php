<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Menu as MainMenu;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\SMTP\ConstantOverrides;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Modules\SMTP\RoutingRules;

// Docs: docs/smtp.md
final class PageController
{
    public const SLUG = 'lrob-etk-smtp';

    public function __construct(
        private ModuleInterface $module,
        private IdentityRepository $identities,
        private RoutingRules $routing,
        private ConstantOverrides $overrides,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_submenu'], 20);
    }

    public function add_submenu(): void
    {
        add_submenu_page(
            MainMenu::SLUG,
            __('SMTP', 'lrob-email-toolkit'),
            __('SMTP', 'lrob-email-toolkit'),
            Activator::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        (new SettingsPage($this->module, $this->identities, $this->routing, $this->overrides))->render();
    }
}
