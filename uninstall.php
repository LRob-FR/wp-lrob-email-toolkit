<?php

declare(strict_types=1);

/**
 * Uninstaller — runs when the user deletes the plugin from the WordPress
 * admin. Drops every plugin table, deletes every plugin option, and removes
 * the custom capability.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop every table whose name starts with the plugin's prefix.
// Belt-and-suspenders: if a future module adds tables and forgets to list
// them here, the prefix scan still cleans them up.
$prefix = $wpdb->prefix . 'lrob_etk_';
$like = $wpdb->esc_like($prefix) . '%';
$tables = $wpdb->get_col(
    $wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $like
    )
);

if (is_array($tables)) {
    foreach ($tables as $table) {
        // Table name is sourced from SHOW TABLES output (already-quoted identifiers
        // not supported by wpdb->prepare for DDL); we still validate it matches
        // our prefix before issuing DROP.
        if (is_string($table) && str_starts_with($table, $prefix)) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}

// Delete every option whose key starts with `lrob_etk_`.
$option_like = $wpdb->esc_like('lrob_etk_') . '%';
$option_names = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $option_like
    )
);

if (is_array($option_names)) {
    foreach ($option_names as $name) {
        if (is_string($name)) {
            delete_option($name);
        }
    }
}

// Clear scheduled cron events under the plugin prefix.
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

// Remove the custom capability from every role.
$capability = 'manage_lrob_etk';
foreach (wp_roles()->roles as $role_name => $_details) {
    $role = get_role($role_name);
    if ($role instanceof WP_Role && $role->has_cap($capability)) {
        $role->remove_cap($capability);
    }
}
