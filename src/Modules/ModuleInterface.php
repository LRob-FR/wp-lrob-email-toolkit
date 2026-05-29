<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules;

// Docs: docs/core.md
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

    /** Always-on modules return true; drives "Always on" badge in the dashboard. */
    public function is_service_module(): bool;

    /** Translated count of stored data (e.g. "3 identities"). Empty when nothing stored. */
    public function data_summary(): string;

    /**
     * Slugs of modules this one requires. ModuleManager skips boot if any are disabled.
     *
     * @return array<int, string>
     */
    public function requires(): array;

    /** Register actions/filters/REST routes. Gate on is_enabled() internally. */
    public function register(): void;

    /** Create tables (dbDelta), seed options, schedule cron. Must be idempotent. */
    public function install(): void;

    /** Drop tables and data. Called only from uninstall.php — disable preserves data. */
    public function uninstall(): void;

    public function is_enabled(): bool;

    public function enable(): void;

    public function disable(): void;

    /** Action name used by the in-page enable/disable toggle form. */
    public function toggle_action(): string;

    /** URL of the module's primary admin page (used for redirects). */
    public function admin_page_url(): ?string;
}
