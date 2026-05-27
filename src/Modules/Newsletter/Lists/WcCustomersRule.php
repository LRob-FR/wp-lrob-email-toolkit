<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Lists;

/**
 * Built-in: every WP user with the `customer` role — the canonical
 * WooCommerce customer set (WC creates the role on every order).
 * Independent of WC Subscriptions; pairs with WooSubscriptionsRule for
 * the "active subscribers" subset.
 *
 * Inert when WooCommerce is not active (no `customer` role exists).
 * Extensible via `lrob_etk_nl_wc_customers_query_args` so custom
 * extensions can constrain by meta (e.g. last_order_date, total_spent)
 * without forking the provider.
 */
final class WcCustomersRule implements RuleProviderInterface
{
    public function slug(): string
    {
        return 'wc_customers';
    }

    public function label(): string
    {
        return __('WC customers', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        if (!self::wc_active()) {
            return __('Requires WooCommerce (not active right now).', 'lrob-email-toolkit');
        }
        return __('Every WP user with the WooCommerce customer role — i.e. anyone who placed an order.', 'lrob-email-toolkit');
    }

    public function config_fields(): array
    {
        return [];
    }

    public function sanitize_config(array $config): array
    {
        unset($config);
        return [];
    }

    public function resolve_user_ids(array $config): array
    {
        unset($config);
        if (!self::wc_active()) {
            return [];
        }
        $args = [
            'role'    => 'customer',
            'fields'  => 'ID',
            'number'  => -1,
        ];
        /**
         * Filter: amend the get_users args before lookup. Extensions
         * (e.g. "spent > €100", "ordered in the last 6 months") add
         * meta_query / date_query here without forking the provider.
         *
         * @param array<string, mixed> $args
         */
        $args = apply_filters('lrob_etk_nl_wc_customers_query_args', $args);
        $users = get_users($args);
        return array_map('intval', is_array($users) ? $users : []);
    }

    private static function wc_active(): bool
    {
        // WC is the canonical class; checking class_exists is the
        // standard WC integration probe used by the broader plugin
        // ecosystem.
        return class_exists('WooCommerce');
    }
}
