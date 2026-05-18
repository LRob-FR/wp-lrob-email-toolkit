<?php

declare(strict_types=1);

namespace LRob\EmailToolkit;

/**
 * Runs on plugin activation. Seeds default options and grants the plugin
 * capability to administrators. Does NOT create any module tables — those are
 * created lazily by each module when it is first enabled.
 */
final class Activator
{
    public const CAPABILITY = 'manage_lrob_etk';

    public const OPTION_MODULES = 'lrob_etk_modules';

    public const OPTION_DB_VERSION = 'lrob_etk_db_version';

    public static function activate(): void
    {
        self::grant_capability();
        self::seed_module_state();
        update_option(self::OPTION_DB_VERSION, LROB_ETK_VERSION);
    }

    /**
     * Idempotent capability self-heal. Recovers from delete+file-copy
     * reinstalls where uninstall.php stripped the cap but the activation
     * hook never re-fired. Hooked on admin_init.
     */
    public static function ensure_capability(): void
    {
        self::grant_capability();
    }

    private static function grant_capability(): void
    {
        $role = get_role('administrator');
        if ($role instanceof \WP_Role && !$role->has_cap(self::CAPABILITY)) {
            $role->add_cap(self::CAPABILITY);
        }
    }

    private static function seed_module_state(): void
    {
        if (false === get_option(self::OPTION_MODULES)) {
            add_option(self::OPTION_MODULES, [
                'smtp'         => false,
                'logging'      => false,
                'contact_form' => false,
                'newsletter'   => false,
            ]);
        }
    }
}
