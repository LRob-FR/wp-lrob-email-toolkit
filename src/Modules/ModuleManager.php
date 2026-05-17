<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules;

use LRob\EmailToolkit\Container;

/**
 * Discovers all modules and boots them unconditionally. Each module decides,
 * inside its register() method, what to register for admin vs runtime — the
 * manager doesn't gate on enabled state any more.
 *
 * The list of available modules is hard-coded in module_classes() so adding
 * a module is an explicit code change.
 */
final class ModuleManager
{
    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    private bool $discovered = false;

    public function __construct(private Container $container)
    {
    }

    /** @return array<int, class-string<ModuleInterface>> */
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

    /**
     * Boot every module. Each module's register() is responsible for
     * deciding what to wire up based on `$this->is_enabled()` and
     * `is_admin()`.
     */
    public function boot_all(): void
    {
        foreach ($this->modules as $module) {
            if (!$this->dependencies_satisfied($module)) {
                continue;
            }
            $module->register();
        }
    }

    private function dependencies_satisfied(ModuleInterface $module): bool
    {
        foreach ($module->requires() as $required_slug) {
            $required = $this->get($required_slug);
            if (!$required instanceof ModuleInterface || !$required->is_enabled()) {
                return false;
            }
        }
        return true;
    }
}
