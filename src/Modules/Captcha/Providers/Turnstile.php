<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Providers;

// Docs: docs/captcha.md — widget: https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/  verify: https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
final class Turnstile extends AbstractHostedCaptcha
{
    public const SLUG = 'turnstile';

    public const POST_RESPONSE_FIELD = 'cf-turnstile-response';

    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const WIDGET_CLASS = 'cf-turnstile';

    public const WIDGET_GLOBAL = 'turnstile';

    public function sort_order(): int
    {
        return 20;
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function label(): string
    {
        return __('Cloudflare Turnstile', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __('Privacy-friendly, often invisible captcha from Cloudflare. No puzzles for most visitors; bots are filtered by Cloudflare\'s risk engine. Requires a free Cloudflare account.', 'lrob-email-toolkit');
    }

    protected function vendor_label(): string
    {
        return __('Cloudflare', 'lrob-email-toolkit');
    }

    public function logo_html(): string
    {
        // Cloudflare brand mark — stylized cloud in their primary orange
        // (#f6821f) inside a rounded rectangle.
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" width="20" height="20" aria-hidden="true" focusable="false">'
            . '<rect width="36" height="36" rx="6" fill="#f6821f"/>'
            . '<path d="M24.5 24H12.5a4 4 0 0 1-.5-7.97A6 6 0 0 1 23.4 15.2a3.4 3.4 0 0 1 1.1 8.8z" fill="#fff"/>'
            . '</svg>';
    }
}
