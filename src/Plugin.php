<?php

declare(strict_types=1);

namespace LRob\EmailToolkit;

use LRob\EmailToolkit\Admin\Menu;
use LRob\EmailToolkit\Forms\FieldTypeRegistry;
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

        // Shared form-builder registry — populated by each module's register()
        // with the field types its form CPT accepts. Available before
        // ModuleManager::boot_all() so modules can register types immediately.
        $this->container->set(FieldTypeRegistry::class, new FieldTypeRegistry());

        $manager = new ModuleManager($this->container);
        $this->container->set(ModuleManager::class, $manager);
        $manager->discover();

        if (is_admin()) {
            add_action('admin_init', [Activator::class, 'ensure_capability']);
            (new Menu($manager))->register();
        }

        // Self-hosted updater runs in every context — wp_update_plugins() can
        // be triggered by wp-cron from a frontend visitor's request when
        // DISABLE_WP_CRON is set, and we'd miss the chance to inject our
        // update entry if we scoped this to is_admin().
        (new \LRob\EmailToolkit\AutoUpdate\Updater())->register();

        $manager->boot_all();
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
