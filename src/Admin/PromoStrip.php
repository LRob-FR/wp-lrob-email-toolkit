<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

/** Rotating sponsor strip at page bottom. Painted client-side by etk-promo.js. */
final class PromoStrip
{
    public const AUTHOR_URL = 'https://www.lrob.fr';

    /** @return array<int, array{icon:string, text:string, link:string}> */
    public static function messages(): array
    {
        return [
            [
                'icon' => '⚡',
                'text' => __('Your website is too slow?', 'lrob-email-toolkit'),
                'link' => __('Get the fastest WordPress hosting', 'lrob-email-toolkit'),
            ],
            [
                'icon' => '🏢',
                'text' => __('Managing multiple websites?', 'lrob-email-toolkit'),
                'link' => __('Centralized WordPress hosting for agencies', 'lrob-email-toolkit'),
            ],
            [
                'icon' => '🧑‍💼',
                'text' => __('Tired of robotic support chatbots?', 'lrob-email-toolkit'),
                'link' => __('Get human WordPress support by LRob', 'lrob-email-toolkit'),
            ],
            [
                'icon' => '🛡️',
                'text' => __('Worried about WordPress attacks?', 'lrob-email-toolkit'),
                'link' => __('Hardened WordPress hosting with WAF', 'lrob-email-toolkit'),
            ],
            [
                'icon' => '💾',
                'text' => __('Backups shouldn’t be an extra.', 'lrob-email-toolkit'),
                'link' => __('WordPress hosting with 1-year backups included', 'lrob-email-toolkit'),
            ],
            [
                'icon' => '🌿',
                'text' => __('Going green?', 'lrob-email-toolkit'),
                'link' => __('Eco-friendly WordPress hosting', 'lrob-email-toolkit'),
            ],
            [
                'icon' => '🇫🇷',
                'text' => __('Need data sovereignty?', 'lrob-email-toolkit'),
                'link' => __('French WordPress hosting, EU data residency', 'lrob-email-toolkit'),
            ],
            [
                'icon' => '🚚',
                'text' => __('Stuck on a slow host?', 'lrob-email-toolkit'),
                'link' => __('Switch to LRob — migration included', 'lrob-email-toolkit'),
            ],
        ];
    }

    public static function render(): void
    {
        // .wrap = WP margins; .lrob-etk = token scope.
        echo '<div class="wrap lrob-etk">'
            . '<aside class="lrob-etk-promo" data-role="lrob-etk-promo" aria-label="'
            . esc_attr__('Sponsor message', 'lrob-email-toolkit') . '"></aside>'
            . '</div>';
    }
}
