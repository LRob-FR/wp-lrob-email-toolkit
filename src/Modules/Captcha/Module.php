<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\Captcha\Admin\PageController;
use LRob\EmailToolkit\Modules\Captcha\Challenges\MathChallenge;

/**
 * Captcha service module. Owns the shared anti-bot challenge pool that
 * ContactForm (and eventually Newsletter, comments, lost-password) uses.
 * Always enabled — the toolkit always has at least one challenge available
 * — so this module ignores the standard enable/disable toggle.
 */
final class Module extends AbstractModule
{
    public function slug(): string
    {
        return 'captcha';
    }

    public function name(): string
    {
        return __('Captcha', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __(
            'Anti-bot challenges shared across modules. Default math challenge ships; future providers (hCaptcha, Turnstile, reCAPTCHA) plug in here.',
            'lrob-email-toolkit'
        );
    }

    public function version(): string
    {
        return '0.0.1';
    }

    public function is_service_module(): bool
    {
        return true;
    }

    /** Service module: always on, no toggle. */
    public function is_enabled(): bool
    {
        return true;
    }

    /** Service module: ignore disable requests. */
    public function disable(): void
    {
    }

    public function admin_page_url(): ?string
    {
        return admin_url('admin.php?page=' . PageController::SLUG);
    }

    public function register(): void
    {
        $service = new CaptchaService();
        $service->add_challenge(new MathChallenge());
        $this->container->set(CaptchaService::class, $service);

        if (is_admin()) {
            (new PageController($this, $service))->register();
        }
    }
}
