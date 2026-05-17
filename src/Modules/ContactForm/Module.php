<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use LRob\EmailToolkit\Modules\AbstractModule;

/**
 * Contact Form module — customizable forms with shortcode/block output,
 * stacked anti-spam (honeypot, time-trap, rate limit, JS token) and optional
 * captcha (hCaptcha, Cloudflare Turnstile, Google reCAPTCHA) configured at the
 * plugin level rather than per-form.
 *
 * Skeleton only at v0.0.1.
 */
final class Module extends AbstractModule
{
    public function slug(): string
    {
        return 'contact_form';
    }

    public function name(): string
    {
        return __('Contact Form', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __(
            'Build customizable contact forms with stacked anti-spam and optional captcha providers.',
            'lrob-email-toolkit'
        );
    }

    public function version(): string
    {
        return '0.0.1';
    }

    public function register(): void
    {
        // Runtime hooks land in a later commit.
    }
}
