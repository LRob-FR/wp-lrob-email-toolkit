<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

/**
 * Enqueues shared admin assets (CSS/JS used across every plugin admin page).
 * Module-specific assets are enqueued by each module's own admin code on the
 * pages where they're needed.
 */
final class Assets
{
    public const HANDLE_CSS = 'lrob-etk-admin';

    public static function enqueue_admin(string $hook_suffix): void
    {
        if (!self::is_plugin_page($hook_suffix)) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE_CSS,
            LROB_ETK_URL . 'admin/css/admin.css',
            [],
            LROB_ETK_VERSION
        );
    }

    private static function is_plugin_page(string $hook_suffix): bool
    {
        return str_contains($hook_suffix, 'lrob-etk');
    }
}
