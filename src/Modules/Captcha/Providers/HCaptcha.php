<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Providers;

/**
 * hCaptcha provider — privacy-respecting alternative to Google reCAPTCHA.
 * All the widget render + siteverify plumbing lives in AbstractHostedCaptcha;
 * this class only carries hCaptcha's constants + branding.
 *
 * Vendor references:
 *  - Widget: https://docs.hcaptcha.com/configuration
 *  - Verify: https://docs.hcaptcha.com/#verify-the-user-response-server-side
 */
final class HCaptcha extends AbstractHostedCaptcha
{
    public const SLUG = 'hcaptcha';

    public const POST_RESPONSE_FIELD = 'h-captcha-response';

    public const SCRIPT_URL = 'https://js.hcaptcha.com/1/api.js';

    public const VERIFY_URL = 'https://api.hcaptcha.com/siteverify';

    public const WIDGET_CLASS = 'h-captcha';

    public const WIDGET_GLOBAL = 'hcaptcha';

    public function supports_invisible(): bool
    {
        return true;
    }

    public function sort_order(): int
    {
        return 10;
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function label(): string
    {
        return __('hCaptcha', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __('Privacy-respecting hosted captcha. Visitors see a checkbox widget; bots are filtered by hCaptcha\'s risk engine. Requires a free or paid hCaptcha account.', 'lrob-email-toolkit');
    }

    protected function vendor_label(): string
    {
        return __('hCaptcha', 'lrob-email-toolkit');
    }

    public function logo_html(): string
    {
        // hCaptcha brand mark — stylized "h" inside a rounded rectangle in
        // their primary blue (#0074bf).
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36" width="20" height="20" aria-hidden="true" focusable="false">'
            . '<rect width="36" height="36" rx="6" fill="#0074bf"/>'
            . '<path d="M10 8v20M10 18h16M26 18v10" stroke="#fff" stroke-width="3.2" stroke-linecap="round" fill="none"/>'
            . '</svg>';
    }
}
