<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\ModuleManager;

/**
 * Registers the top-level "Email Toolkit" admin menu and submenu pages.
 * Module-specific submenus are added later by each module's own admin code.
 */
final class Menu
{
    public const SLUG = 'lrob-etk';

    public const SLUG_MODULES = 'lrob-etk-modules';

    private DashboardPage $dashboard;

    private ModulesPage $modules_page;

    public function __construct(private ModuleManager $manager)
    {
        $this->dashboard = new DashboardPage($manager);
        $this->modules_page = new ModulesPage($manager);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_pages']);
        add_action('admin_post_lrob_etk_toggle_modules', [$this->modules_page, 'handle_toggle']);
        add_action('admin_enqueue_scripts', [Assets::class, 'enqueue_admin']);
    }

    public function add_pages(): void
    {
        $capability = Activator::CAPABILITY;

        add_menu_page(
            __('Email Toolkit', 'lrob-email-toolkit'),
            __('Email Toolkit', 'lrob-email-toolkit'),
            $capability,
            self::SLUG,
            [$this->dashboard, 'render'],
            'dashicons-email-alt',
            58
        );

        add_submenu_page(
            self::SLUG,
            __('Dashboard', 'lrob-email-toolkit'),
            __('Dashboard', 'lrob-email-toolkit'),
            $capability,
            self::SLUG,
            [$this->dashboard, 'render']
        );

        add_submenu_page(
            self::SLUG,
            __('Modules', 'lrob-email-toolkit'),
            __('Modules', 'lrob-email-toolkit'),
            $capability,
            self::SLUG_MODULES,
            [$this->modules_page, 'render']
        );
    }
}
