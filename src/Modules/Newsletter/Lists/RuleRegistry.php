<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Lists;

/**
 * Lookup point for list-rule providers.
 *
 * Built-ins are wired in self::built_in(); third-party plugins extend
 * the set via the `lrob_etk_nl_list_rule_providers` filter — return an
 * array indexed by slug, each value an instance of RuleProviderInterface.
 *
 * Resolution is cached for the request — providers are typically cheap
 * to instantiate, but the filter chain may not be.
 */
final class RuleRegistry
{
    /** @var array<string, RuleProviderInterface>|null */
    private static ?array $cache = null;

    /** @return array<string, RuleProviderInterface> indexed by slug */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $providers = self::built_in();
        /**
         * Filter: register custom list-rule providers.
         *
         * @param array<string, RuleProviderInterface> $providers
         */
        $providers = apply_filters('lrob_etk_nl_list_rule_providers', $providers);
        $out = [];
        foreach ($providers as $p) {
            if ($p instanceof RuleProviderInterface) {
                $out[$p->slug()] = $p;
            }
        }
        self::$cache = $out;
        return $out;
    }

    public static function get(string $slug): ?RuleProviderInterface
    {
        $all = self::all();
        return $all[$slug] ?? null;
    }

    /** @return array<int, RuleProviderInterface> */
    private static function built_in(): array
    {
        $providers = [
            new WpAllUsersRule(),
            new WpUserRoleRule(),
            new WcCustomersRule(),
        ];
        // WC Subscriptions provider stays registered even when WCS is
        // inactive — the slug needs to keep resolving so a stored rule
        // doesn't error during a temporary deactivation; the provider
        // itself returns [] until WCS is back.
        $providers[] = new WooSubscriptionsRule();
        return $providers;
    }

    /** Test hook: clear the cache so a test can swap the filter chain. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
