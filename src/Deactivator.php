<?php

declare(strict_types=1);

namespace LRob\EmailToolkit;

/**
 * Runs on plugin deactivation. Clears any scheduled cron events so they do not
 * fire and crash while the plugin's classes are unavailable. Does NOT drop any
 * data — that is handled by uninstall.php when the plugin is deleted.
 */
final class Deactivator
{
    public static function deactivate(): void
    {
        self::clear_scheduled_events();
    }

    private static function clear_scheduled_events(): void
    {
        $crons = _get_cron_array();
        if (!is_array($crons)) {
            return;
        }

        foreach ($crons as $hooks) {
            if (!is_array($hooks)) {
                continue;
            }
            foreach (array_keys($hooks) as $hook) {
                if (is_string($hook) && str_starts_with($hook, 'lrob_etk_')) {
                    wp_clear_scheduled_hook($hook);
                }
            }
        }
    }
}
