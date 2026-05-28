<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Assets;
use LRob\EmailToolkit\Admin\Menu as MainMenu;
use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\ModuleInterface;

/**
 * Registers the "Captcha" submenu and wires its AJAX controller. All CRUD
 * runs through AjaxController; this class is mostly a router.
 */
final class PageController
{
    public const SLUG = 'lrob-etk-captcha';

    public function __construct(
        private ModuleInterface $module,
        private CaptchaService $service,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_submenu'], 25);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        $ajax = new AjaxController($this->service, $this->service->identity_repository());
        $ajax->register();
    }

    public function add_submenu(): void
    {
        add_submenu_page(
            MainMenu::SLUG,
            __('Captcha', 'lrob-email-toolkit'),
            __('Captcha', 'lrob-email-toolkit'),
            Activator::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if (!str_contains($hook_suffix, self::SLUG)) {
            return;
        }
        // Frontend form CSS so the built-in challenge previews (and the
        // hCaptcha widget shell) look exactly like what visitors see.
        wp_enqueue_style(
            'lrob-etk-cf-frontend',
            LROB_ETK_URL . 'assets/css/contact-form.css',
            [],
            Assets::asset_version_for('assets/css/contact-form.css')
        );
        wp_enqueue_script(
            'lrob-etk-captcha-admin',
            LROB_ETK_URL . 'admin/js/captcha-admin.js',
            [Assets::HANDLE_CONTROLS_JS],
            Assets::asset_version_for('admin/js/captcha-admin.js'),
            true
        );
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        (new SettingsPage($this->module, $this->service))->render();
    }
}
