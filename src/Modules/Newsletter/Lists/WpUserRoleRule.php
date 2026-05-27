<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Lists;

/**
 * Built-in: match WP users carrying any of the selected roles.
 *
 * Doubles as the reference for third-party providers — the file is
 * short on purpose. Custom providers (WooCommerce subscribers, ACF
 * field match, etc.) follow the same shape.
 */
final class WpUserRoleRule implements RuleProviderInterface
{
    public function slug(): string
    {
        return 'wp_user_role';
    }

    public function label(): string
    {
        return __('WordPress role', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __('Match every WP user that carries any of the selected roles.', 'lrob-email-toolkit');
    }

    public function config_fields(): array
    {
        global $wp_roles;
        $options = [];
        if ($wp_roles instanceof \WP_Roles) {
            foreach ($wp_roles->roles as $slug => $info) {
                $options[(string) $slug] = (string) translate_user_role((string) ($info['name'] ?? $slug));
            }
        }
        return [
            [
                'name'    => 'roles',
                'label'   => __('Roles', 'lrob-email-toolkit'),
                'type'    => 'multiselect',
                'options' => $options,
                'default' => [],
            ],
        ];
    }

    public function sanitize_config(array $config): array
    {
        $roles = isset($config['roles']) && is_array($config['roles'])
            ? array_values(array_unique(array_map('sanitize_key', $config['roles'])))
            : [];
        // Drop slugs the site doesn't actually know — same defensive
        // filter as in resolve_user_ids, but applied at save time so
        // the persisted JSON never grows stale entries either.
        global $wp_roles;
        $known = $wp_roles instanceof \WP_Roles ? array_keys($wp_roles->roles) : [];
        if ($known !== []) {
            $roles = array_values(array_intersect($roles, $known));
        }
        return ['roles' => $roles];
    }

    public function resolve_user_ids(array $config): array
    {
        $roles = isset($config['roles']) && is_array($config['roles']) ? $config['roles'] : [];
        if ($roles === []) {
            return [];
        }
        // Drop any saved role slugs the site no longer has (a plugin got
        // uninstalled, a custom role was removed, …). Without this filter
        // `role__in` would still query for them; WP_User_Query tolerates
        // the miss, but other consumers might not.
        global $wp_roles;
        $known = $wp_roles instanceof \WP_Roles ? array_keys($wp_roles->roles) : [];
        $roles = array_values(array_intersect($roles, $known));
        if ($roles === []) {
            return [];
        }
        $users = get_users([
            'role__in' => $roles,
            'fields'   => 'ID',
            'number'   => -1,
        ]);
        return array_map('intval', is_array($users) ? $users : []);
    }
}
