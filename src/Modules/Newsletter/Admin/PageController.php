<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Menu as MainMenu;
use LRob\EmailToolkit\Modules\ModuleInterface;

/**
 * Registers the Newsletter submenu under the Email Toolkit parent. The
 * single submenu entry resolves to `?page=lrob-etk-nl`; sub-areas
 * (Subscribers, Lists, Campaigns, Categories, Templates, Forms, Import,
 * Settings) are reached via `&view=…` on the same slug — the
 * hidden-page-pattern documented in CLAUDE.md.
 */
final class PageController
{
    public const SLUG = 'lrob-etk-nl';

    public function __construct(
        private ModuleInterface $module,
        private HomePage $home,
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
            __('Newsletter', 'lrob-email-toolkit'),
            __('Newsletter', 'lrob-email-toolkit'),
            Activator::CAPABILITY,
            self::SLUG,
            [$this->home, 'render']
        );
    }
}
