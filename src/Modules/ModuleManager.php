<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Container;

/**
 * Discovers, enables/disables, and boots feature modules. The list of
 * available modules is hard-coded in self::register_module_classes() — module
 * discovery is intentionally not dynamic (no scanning the filesystem) so the
 * runtime stays predictable and adding a module is an explicit code change.
 */
final class ModuleManager
{
    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    private bool $discovered = false;

    public function __construct(private Container $container)
    {
    }

    /**
     * Module classes shipped with the plugin. Order is irrelevant; the
     * `requires()` array on each module declares dependencies.
     *
     * @return array<int, class-string<ModuleInterface>>
     */
    private function module_classes(): array
    {
        return [
            \LRob\EmailToolkit\Modules\SMTP\Module::class,
            \LRob\EmailToolkit\Modules\Logging\Module::class,
            \LRob\EmailToolkit\Modules\ContactForm\Module::class,
            \LRob\EmailToolkit\Modules\Newsletter\Module::class,
        ];
    }

    public function discover(): void
    {
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;

        foreach ($this->module_classes() as $class) {
            $module = new $class($this->container);
            $this->modules[$module->slug()] = $module;
        }
    }

    /** @return array<string, ModuleInterface> */
    public function all(): array
    {
        return $this->modules;
    }

    public function get(string $slug): ?ModuleInterface
    {
        return $this->modules[$slug] ?? null;
    }

    public function is_enabled(string $slug): bool
    {
        $state = (array) get_option(Activator::OPTION_MODULES, []);
        return !empty($state[$slug]);
    }

    public function boot_enabled(): void
    {
        foreach ($this->modules as $slug => $module) {
            if (!$this->is_enabled($slug)) {
                continue;
            }
            if (!$this->dependencies_satisfied($module)) {
                continue;
            }
            $module->register();
        }
    }

    public function enable(string $slug): bool
    {
        $module = $this->get($slug);
        if (!$module instanceof ModuleInterface) {
            return false;
        }

        $state = (array) get_option(Activator::OPTION_MODULES, []);
        $state[$slug] = true;
        update_option(Activator::OPTION_MODULES, $state);

        // install() must be idempotent — call on every enable, not just the first.
        $module->install();

        return true;
    }

    public function disable(string $slug): bool
    {
        if (!isset($this->modules[$slug])) {
            return false;
        }

        $state = (array) get_option(Activator::OPTION_MODULES, []);
        $state[$slug] = false;
        update_option(Activator::OPTION_MODULES, $state);

        return true;
    }

    private function dependencies_satisfied(ModuleInterface $module): bool
    {
        foreach ($module->requires() as $required_slug) {
            if (!$this->is_enabled($required_slug)) {
                return false;
            }
        }
        return true;
    }
}
