<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules;

interface ModuleInterface
{
    /** Stable identifier used in options, hook names, table prefixes. */
    public function slug(): string;

    /** Human-readable name (translated). */
    public function name(): string;

    /** Short user-facing description (translated). */
    public function description(): string;

    /** Module version; bumped when the module's schema or wire format changes. */
    public function version(): string;

    /**
     * Slugs of other modules this module needs to function. Returned modules
     * must be enabled for this one to boot. ModuleManager surfaces missing
     * dependencies in the admin UI rather than refusing to load.
     *
     * @return array<int, string>
     */
    public function requires(): array;

    /**
     * Register runtime hooks (actions, filters, REST routes, etc.). Only
     * called for modules that are enabled. Must be idempotent — may be called
     * multiple times within a single request in theory, though normally once.
     */
    public function register(): void;

    /**
     * One-time setup when the module is enabled: creates tables via dbDelta,
     * seeds default options, schedules cron events. Must be idempotent so
     * repeated calls (re-enable, plugin upgrade) are safe.
     */
    public function install(): void;

    /**
     * Drop tables and clean up the module's data. Called only from the
     * plugin-wide uninstall.php — disabling a module does NOT drop its data,
     * so users can re-enable without losing logs/campaigns/etc.
     */
    public function uninstall(): void;
}
