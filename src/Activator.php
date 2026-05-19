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

    public const OPTION_UNINSTALL_MODE = 'lrob_etk_uninstall_mode';

    /** Default uninstall mode: keep all data. See uninstall.php for the others. */
    public const UNINSTALL_MODE_DEFAULT = 'keep';

    public static function activate(): void
    {
        self::grant_capability();
        self::seed_module_state();
        self::seed_uninstall_mode();
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
                'captcha'      => true,   // service module, always on (Captcha\Module::is_enabled() also enforces this)
                'smtp'         => false,
                'logging'      => false,
                'contact_form' => false,
                'newsletter'   => false,
            ]);
        }
    }

    /**
     * Seed the uninstall mode with the safest default. Existing installs
     * (upgrading from <0.0.4) won't have this option yet; uninstall.php
     * also defaults to 'keep' on get_option default-arg, so a delete-before-
     * activate-after-upgrade can never lose data.
     */
    private static function seed_uninstall_mode(): void
    {
        if (false === get_option(self::OPTION_UNINSTALL_MODE)) {
            add_option(self::OPTION_UNINSTALL_MODE, self::UNINSTALL_MODE_DEFAULT);
        }
    }
}
