<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\ModuleManager;

/**
 * Registers the top-level "Email Toolkit" admin menu and the Dashboard submenu.
 * Module-specific submenus are added by each module's own admin code, so they
 * appear (or not) depending on whether the module ships an admin page.
 */
final class Menu
{
    public const SLUG = 'lrob-etk';

    private DashboardPage $dashboard;

    private DataPage $data;

    public function __construct(private ModuleManager $manager)
    {
        $this->dashboard = new DashboardPage($manager);
        $this->data = new DataPage($manager);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_pages']);
        add_action('admin_enqueue_scripts', [Assets::class, 'enqueue_admin']);
        $this->data->register();
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
    }
}
