<?php

declare(strict_types=1);

namespace LRob\EmailToolkit;

use LRob\EmailToolkit\Admin\Menu;
use LRob\EmailToolkit\Modules\ModuleManager;

/**
 * Main plugin singleton. Boots the module manager and admin UI.
 */
final class Plugin
{
    private static ?self $instance = null;

    private Container $container;

    private bool $booted = false;

    private function __construct()
    {
        $this->container = new Container();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        add_action('init', [$this, 'load_textdomain']);

        $manager = new ModuleManager($this->container);
        $this->container->set(ModuleManager::class, $manager);

        $manager->discover();
        $manager->boot_all();

        if (is_admin()) {
            (new Menu($manager))->register();
        }
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            'lrob-email-toolkit',
            false,
            dirname(LROB_ETK_BASENAME) . '/languages'
        );
    }
}
