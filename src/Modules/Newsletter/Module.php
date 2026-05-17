<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Modules\AbstractModule;

/**
 * Newsletter module — campaigns to WordPress users with WP-role / user-meta /
 * WooCommerce (HPOS-aware) segmentation, throttled sending, open/click
 * tracking, unsubscribe handling.
 *
 * Skeleton only at v0.0.1.
 */
final class Module extends AbstractModule
{
    public function slug(): string
    {
        return 'newsletter';
    }

    public function name(): string
    {
        return __('Newsletter', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __(
            'Send campaigns to your WordPress users with advanced segmentation and tracking.',
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
