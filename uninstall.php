<?php

declare(strict_types=1);

/**
 * Uninstaller — runs when the user deletes the plugin from the WordPress
 * admin. Behaviour is gated by the `lrob_etk_uninstall_mode` option which
 * the user picks in the Data admin page:
 *
 *   keep    (default) — drop NOTHING. Reinstalling picks up where the user
 *                       left off. Safe even on an accidental "Delete".
 *   archive          — drop options + capability + cron, keep data tables.
 *                       Settings reset on reinstall, but logs / identities /
 *                       contact forms / submissions are still there.
 *   wipe             — drop everything (tables, options, capability, cron).
 *
 * Default is `keep` so a misclick on WP's "Delete" button cannot lose data.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$mode = get_option('lrob_etk_uninstall_mode', 'keep');
if (!in_array($mode, ['keep', 'archive', 'wipe'], true)) {
    $mode = 'keep';
}

if ($mode === 'keep') {
    return;
}

global $wpdb;

// `wipe` mode drops the plugin's tables. `archive` skips this step so data
// survives a reinstall.
if ($mode === 'wipe') {
    $prefix = $wpdb->prefix . 'lrob_etk_';
    $like = $wpdb->esc_like($prefix) . '%';
    $tables = $wpdb->get_col(
        $wpdb->prepare('SHOW TABLES LIKE %s', $like)
    );
    if (is_array($tables)) {
        foreach ($tables as $table) {
            // Validate the prefix one more time before DDL — wpdb->prepare
            // can't quote identifiers, and the source is SHOW TABLES output.
            if (is_string($table) && str_starts_with($table, $prefix)) {
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            }
        }
    }
}

// Both `archive` and `wipe` drop options + capability + cron — these are
// configuration, not user data.
$option_like = $wpdb->esc_like('lrob_etk_') . '%';
$option_names = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $option_like
    )
);
if (is_array($option_names)) {
    foreach ($option_names as $name) {
        if (!is_string($name)) {
            continue;
        }
        // Preserve the uninstall mode option in `archive` so a reinstall
        // remembers the user's choice. `wipe` removes it.
        if ($mode === 'archive' && $name === 'lrob_etk_uninstall_mode') {
            continue;
        }
        delete_option($name);
    }
}

$crons = _get_cron_array();
if (is_array($crons)) {
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

$capability = 'manage_lrob_etk';
foreach (wp_roles()->roles as $role_name => $_details) {
    $role = get_role($role_name);
    if ($role instanceof WP_Role && $role->has_cap($capability)) {
        $role->remove_cap($capability);
    }
}
