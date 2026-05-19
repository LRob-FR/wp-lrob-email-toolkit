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
     * Service modules (always-on, no user toggle, depended on by others —
     * e.g. Captcha) return true. Feature modules return false. Drives the
     * dashboard module card UI: "Always on" badge instead of toggle.
     */
    public function is_service_module(): bool;

    /**
     * Translated, human-readable summary of what's stored for this module
     * (e.g. "3 identities", "412 log entries", "5 forms, 28 submissions").
     * Empty string when the module stores no user data. Used by the Data
     * admin page to preview what a "Wipe" action will remove.
     */
    public function data_summary(): string;

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

    public function is_enabled(): bool;

    public function enable(): void;

    public function disable(): void;

    /** Action name used by the in-page enable/disable toggle form. */
    public function toggle_action(): string;

    /** URL of the module's primary admin page (used for redirects). */
    public function admin_page_url(): ?string;
}
