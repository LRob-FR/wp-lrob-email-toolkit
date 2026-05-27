<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Lists;

/**
 * Built-in: every WordPress member. The "send to the whole site" rule
 * without forcing the admin to pick every role. Shipped as a sibling
 * of WpUserRoleRule because:
 *   - role-multiselect with all roles checked is functionally equal,
 *     but admins forget to re-tick newly-added roles → coverage gap;
 *   - this provider exposes intent ("everyone") explicitly, which
 *     reads better in the rule editor than "every role I currently
 *     remembered to tick".
 *
 * No config fields — the result is deterministic.
 */
final class WpAllUsersRule implements RuleProviderInterface
{
    public function slug(): string
    {
        return 'wp_all_users';
    }

    public function label(): string
    {
        return __('WordPress members (all)', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __('Match every WordPress user on this site, regardless of role.', 'lrob-email-toolkit');
    }

    public function config_fields(): array
    {
        return [];
    }

    public function sanitize_config(array $config): array
    {
        unset($config);
        return [];
    }

    public function resolve_user_ids(array $config): array
    {
        unset($config);
        $users = get_users([
            'fields' => 'ID',
            'number' => -1,
        ]);
        return array_map('intval', is_array($users) ? $users : []);
    }
}
