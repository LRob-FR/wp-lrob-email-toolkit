<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Menu as MainMenu;
use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\ModuleInterface;

final class PageController
{
    public const SLUG = 'lrob-etk-captcha';

    public const ACTION_SAVE = 'lrob_etk_captcha_save';

    public function __construct(
        private ModuleInterface $module,
        private CaptchaService $service,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_submenu'], 25);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handle_save']);
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

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        (new SettingsPage($this->module, $this->service))->render();
    }

    public function handle_save(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $nonce = isset($_POST['_lrob_etk_nonce']) ? (string) $_POST['_lrob_etk_nonce'] : '';
        if (!wp_verify_nonce($nonce, self::ACTION_SAVE)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }

        $slug = isset($_POST['active_challenge']) ? sanitize_key((string) $_POST['active_challenge']) : '';
        $allowed = array_keys($this->service->available());
        $allowed[] = CaptchaService::SLUG_NONE;
        if (!in_array($slug, $allowed, true)) {
            $slug = (string) array_key_first($this->service->available());
        }
        $this->service->set_active($slug);

        wp_safe_redirect(add_query_arg('saved', '1', admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }
}
