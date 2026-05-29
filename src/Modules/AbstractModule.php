<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Container;

// Docs: docs/core.md
abstract class AbstractModule implements ModuleInterface
{
    public function __construct(protected Container $container)
    {
    }

    public function requires(): array
    {
        return [];
    }

    /** Service modules (always-on, no user toggle) return true. */
    public function is_service_module(): bool
    {
        return false;
    }

    /** Default: no stored data. Modules with tables/options override. */
    public function data_summary(): string
    {
        return '';
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    /** Option key holding this module's own settings. */
    final public function settings_option_key(): string
    {
        return 'lrob_etk_' . $this->slug() . '_settings';
    }

    /** Option key tracking the installed schema version for this module. */
    final public function db_version_option_key(): string
    {
        return 'lrob_etk_' . $this->slug() . '_db_version';
    }

    /** Action name used by the in-page enable/disable toggle. */
    final public function toggle_action(): string
    {
        return 'lrob_etk_' . $this->slug() . '_toggle';
    }

    public function is_enabled(): bool
    {
        $state = (array) get_option(Activator::OPTION_MODULES, []);
        return !empty($state[$this->slug()]);
    }

    /** Mark the module as enabled and run install(). Idempotent. */
    public function enable(): void
    {
        $state = (array) get_option(Activator::OPTION_MODULES, []);
        $state[$this->slug()] = true;
        update_option(Activator::OPTION_MODULES, $state);
        $this->install();
        update_option($this->db_version_option_key(), $this->db_version_int());
    }

    /** Schema version integer. Bump on each ALTER/dbDelta change; handle in migrate(). */
    public function db_version_int(): int
    {
        return 1;
    }

    /** Override with an idempotent switch on $from_version. Service modules must forward to install(). */
    public function migrate(int $from_version, int $to_version): void
    {
        unset($from_version, $to_version);
    }

    final public function maybe_migrate(): void
    {
        $stored = (int) get_option($this->db_version_option_key(), 0);
        $target = $this->db_version_int();
        if ($stored === $target) {
            return;
        }
        if ($stored === 0) {
            // 0 = fresh install or pre-migration-scaffolding upgrade; install() is idempotent.
            $this->install();
        } else {
            $this->migrate($stored, $target);
        }
        update_option($this->db_version_option_key(), $target);
    }

    /** Mark the module as disabled. Data is preserved. */
    public function disable(): void
    {
        $state = (array) get_option(Activator::OPTION_MODULES, []);
        $state[$this->slug()] = false;
        update_option(Activator::OPTION_MODULES, $state);
    }

    /**
     * Handles the POST from the in-page enable/disable form. Modules wire
     * this up by adding `admin_post_<toggle_action>` → handle_toggle().
     */
    public function handle_toggle(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $nonce = isset($_POST['_lrob_etk_nonce']) ? (string) $_POST['_lrob_etk_nonce'] : '';
        if (!wp_verify_nonce($nonce, $this->toggle_action())) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }

        $turn_on = !empty($_POST['enable']);
        if ($turn_on) {
            $this->enable();
        } else {
            $this->disable();
        }

        // Return to where the toggle was clicked (dashboard or module page), not always the module page.
        $redirect = wp_get_referer();
        if ($redirect === false) {
            $redirect = $this->admin_page_url() ?? admin_url('admin.php?page=lrob-etk');
        }
        wp_safe_redirect(add_query_arg('toggled', $turn_on ? 'on' : 'off', $redirect));
        exit;
    }

    /** Redirect target after the toggle handler. Override per module. */
    public function admin_page_url(): ?string
    {
        return null;
    }
}
