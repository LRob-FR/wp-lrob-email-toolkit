<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Lists;

/**
 * Built-in: match WP users who hold a WooCommerce Subscription in one of
 * the selected statuses. Silently inert when WC Subscriptions isn't
 * installed (the provider stays registered so the slug doesn't error on
 * a temporarily-uninstalled plugin, but resolve_user_ids returns []
 * until WCS is back).
 *
 * Statuses match WooCommerce Subscriptions' canonical vocabulary:
 *   active / on-hold / pending / cancelled / expired / pending-cancel
 *
 * Product filtering is an opt-in field; empty = "any product". Admins
 * can paste comma-separated product IDs (a proper picker can follow).
 *
 * Custom extensions (filter by SaaS tenant / server / region) plug in
 * via `lrob_etk_nl_woo_subscription_query_args` — receives the WP_User_Query
 * args this provider builds and can amend them with custom meta queries
 * before the final user lookup.
 */
final class WooSubscriptionsRule implements RuleProviderInterface
{
    public function slug(): string
    {
        return 'woo_subscriptions';
    }

    public function label(): string
    {
        return __('WooCommerce subscribers', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        if (!self::wcs_active()) {
            return __('Requires the WooCommerce Subscriptions plugin (not active right now).', 'lrob-email-toolkit');
        }
        return __('Match WP users holding a WooCommerce Subscription in any of the selected statuses (and optionally a specific product).', 'lrob-email-toolkit');
    }

    public function config_fields(): array
    {
        return [
            [
                'name'    => 'statuses',
                'label'   => __('Subscription statuses', 'lrob-email-toolkit'),
                'type'    => 'multiselect',
                'options' => [
                    'active'         => __('Active', 'lrob-email-toolkit'),
                    'pending'        => __('Pending', 'lrob-email-toolkit'),
                    'on-hold'        => __('On hold', 'lrob-email-toolkit'),
                    'pending-cancel' => __('Pending cancellation', 'lrob-email-toolkit'),
                    'expired'        => __('Expired', 'lrob-email-toolkit'),
                    'cancelled'      => __('Cancelled', 'lrob-email-toolkit'),
                ],
                'default' => ['active'],
            ],
            [
                'name'    => 'product_ids',
                'label'   => __('Products (blank = any)', 'lrob-email-toolkit'),
                'type'    => 'wc_product_search',
                'default' => [],
            ],
        ];
    }

    public function sanitize_config(array $config): array
    {
        $allowed_statuses = ['active', 'pending', 'on-hold', 'pending-cancel', 'expired', 'cancelled'];
        $statuses = isset($config['statuses']) && is_array($config['statuses'])
            ? array_values(array_intersect(array_map('sanitize_key', $config['statuses']), $allowed_statuses))
            : [];
        if ($statuses === []) {
            $statuses = ['active'];
        }
        // Product picker stores an array of integer IDs.
        $product_ids = [];
        if (isset($config['product_ids']) && is_array($config['product_ids'])) {
            foreach ($config['product_ids'] as $piece) {
                $n = (int) $piece;
                if ($n > 0) {
                    $product_ids[] = $n;
                }
            }
        } elseif (isset($config['product_ids']) && is_string($config['product_ids'])) {
            // Legacy comma-separated fallback — v0.3.4 wrote text before
            // the picker shipped. Drops gracefully once the rule is re-saved.
            foreach (preg_split('/[\s,]+/', (string) $config['product_ids']) ?: [] as $piece) {
                $n = (int) $piece;
                if ($n > 0) {
                    $product_ids[] = $n;
                }
            }
        }
        $product_ids = array_values(array_unique($product_ids));
        return [
            'statuses'    => $statuses,
            'product_ids' => $product_ids,
        ];
    }

    public function resolve_user_ids(array $config): array
    {
        if (!self::wcs_active()) {
            return [];
        }
        $statuses = isset($config['statuses']) && is_array($config['statuses']) ? $config['statuses'] : ['active'];
        $product_ids = isset($config['product_ids']) && is_array($config['product_ids']) ? $config['product_ids'] : [];

        // wcs_get_subscriptions is the canonical WC Subscriptions API.
        // We page through; the function caps per-call results, but our
        // newsletter audiences are typically below that cap.
        $args = [
            'subscriptions_per_page' => -1,
            'subscription_status'    => $statuses,
        ];
        if ($product_ids !== []) {
            $args['product_id'] = $product_ids;
        }
        /**
         * Filter: amend the wcs_get_subscriptions args before lookup.
         * Lets custom extensions (per-tenant, per-server) constrain
         * further without forking the provider.
         *
         * @param array<string, mixed> $args
         * @param array<string, mixed> $config
         */
        $args = apply_filters('lrob_etk_nl_woo_subscription_query_args', $args, $config);

        if (!function_exists('wcs_get_subscriptions')) {
            return [];
        }
        $subs = wcs_get_subscriptions($args);
        if (!is_array($subs)) {
            return [];
        }
        $user_ids = [];
        foreach ($subs as $sub) {
            if (is_object($sub) && method_exists($sub, 'get_user_id')) {
                $uid = (int) $sub->get_user_id();
                if ($uid > 0) {
                    $user_ids[$uid] = true;
                }
            }
        }
        return array_keys($user_ids);
    }

    /**
     * WooCommerce Subscriptions is active iff its canonical query
     * function exists. We don't depend on class symbols — they shift
     * between WCS versions.
     */
    private static function wcs_active(): bool
    {
        return function_exists('wcs_get_subscriptions');
    }
}
