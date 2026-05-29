<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\Captcha\Admin\PageController;
use LRob\EmailToolkit\Modules\Captcha\Challenges\ChallengeInterface;

// Docs: docs/captcha.md
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
            'Protect your forms against spam and bots.',
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

    /** Schema version history: docs/captcha.md → "Schema". migrate() always forwards to install() — see CLAUDE.md "service-module migrate trap". */
    public function db_version_int(): int
    {
        return 6;
    }

    public function install(): void
    {
        Schema::install();
        self::seed_context_map_if_missing();
        self::migrate_wp_native_contexts_off_once();
    }

    /** v6 migration: WP-native contexts on 'inherit' (ambiguous) → 'none' (opt-in). Idempotent. */
    private static function migrate_wp_native_contexts_off_once(): void
    {
        $map = Routing::context_map();
        if ($map === []) {
            return;
        }
        $changed = false;
        foreach (Routing::wp_native_contexts() as $context) {
            $value = $map[$context] ?? '';
            if ($value === '' || $value === Routing::ROUTE_INHERIT) {
                $map[$context] = Routing::ROUTE_NONE;
                $changed = true;
            }
        }
        if ($changed) {
            Routing::replace_map($map);
        }
    }

    // Service module: forward migrate() to install() — see CLAUDE.md "service-module migrate trap".
    public function migrate(int $from_version, int $to_version): void
    {
        unset($from_version, $to_version);
        $this->install();
    }

    public function register(): void
    {
        $stats = new StatsRepository();
        $service = new CaptchaService(new IdentityRepository(), $stats);
        self::register_challenges($service);
        $this->container->set(CaptchaService::class, $service);
        $this->container->set(StatsRepository::class, $stats);

        if (is_admin()) {
            (new PageController($this, $service))->register();
            add_action('admin_notices', [$this, 'render_broken_routes_notice']);
        }

        (new WpHooks($service))->register();
    }

    /** Admin notice when any active route resolves to STATE_BROKEN. */
    public function render_broken_routes_notice(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            return;
        }
        $service = $this->container->has(CaptchaService::class)
            ? $this->container->get(CaptchaService::class)
            : null;
        if ($service === null) {
            return;
        }

        $broken = [];
        foreach (Routing::context_map() as $key => $route) {
            if ($route === Routing::ROUTE_NONE || $route === Routing::ROUTE_INHERIT || $route === '') {
                continue;
            }
            [, , $state] = $service->resolve(['force_route' => $route]);
            if ($state === CaptchaService::STATE_BROKEN) {
                $broken[$key] = $route;
            }
        }
        if ($broken === []) {
            return;
        }

        $labels = array_map(
            static fn (string $key): string => $key === Routing::KEY_DEFAULT
                ? __('Default (site-wide)', 'lrob-email-toolkit')
                : Routing::context_label($key),
            array_keys($broken)
        );
        $url = admin_url('admin.php?page=' . PageController::SLUG);

        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong></p><p>%2$s</p><p><a href="%3$s" class="button button-primary">%4$s</a></p></div>',
            esc_html__('Captcha misconfiguration', 'lrob-email-toolkit'),
            esc_html(sprintf(
                /* translators: %s: comma-separated list of affected captcha contexts (e.g. "Default (site-wide), Contact forms") */
                __('The captcha route is broken for: %s. Affected forms will reject all submissions until you fix this — open the Captcha settings page to pick a working challenge or re-enter credentials.', 'lrob-email-toolkit'),
                implode(', ', $labels)
            )),
            esc_url($url),
            esc_html__('Open Captcha settings', 'lrob-email-toolkit')
        );
    }

    /** Auto-scan Challenges/ and Providers/; see docs/captcha.md for the extension contract. */
    private static function register_challenges(CaptchaService $service): void
    {
        self::register_from_directory(
            $service,
            LROB_ETK_PATH . 'src/Modules/Captcha/Challenges',
            __NAMESPACE__ . '\\Challenges\\',
            ['ChallengeInterface']
        );
        self::register_from_directory(
            $service,
            LROB_ETK_PATH . 'src/Modules/Captcha/Providers',
            __NAMESPACE__ . '\\Providers\\',
            ['ProviderInterface']
        );
    }

    /** @param array<int, string> $skip_basenames */
    private static function register_from_directory(
        CaptchaService $service,
        string $dir,
        string $namespace,
        array $skip_basenames
    ): void {
        $files = glob($dir . '/*.php');
        if (!is_array($files)) {
            return;
        }
        sort($files); // deterministic registration order (= filename order)
        foreach ($files as $file) {
            $base = basename($file, '.php');
            if (in_array($base, $skip_basenames, true)) {
                continue;
            }
            $fqcn = $namespace . $base;
            if (!class_exists($fqcn)) {
                continue;
            }
            $reflection = new \ReflectionClass($fqcn);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(ChallengeInterface::class)) {
                continue;
            }
            $service->add_challenge($reflection->newInstance());
        }
    }

    /** Seed the context map on first install. Migrates legacy single-default option if present. */
    private static function seed_context_map_if_missing(): void
    {
        $existing = get_option(Routing::OPTION_CONTEXT_MAP, null);
        if (is_array($existing) && !empty($existing)) {
            return;
        }

        $legacy = get_option(CaptchaService::OPTION_SETTINGS, []);
        $legacy_active = is_array($legacy) && isset($legacy[CaptchaService::SETTING_ACTIVE]) && is_string($legacy[CaptchaService::SETTING_ACTIVE])
            ? $legacy[CaptchaService::SETTING_ACTIVE]
            : '';

        $default = ($legacy_active === '' || $legacy_active === CaptchaService::SLUG_NONE)
            ? Routing::homemade('math')
            : Routing::homemade($legacy_active);

        // Plugin contexts → inherit (pick up default); WP-native contexts → none (opt-in).
        $inherit_contexts = Routing::plugin_contexts();
        $map = [Routing::KEY_DEFAULT => $default];
        foreach (Routing::known_contexts() as $context) {
            $map[$context] = in_array($context, $inherit_contexts, true)
                ? Routing::ROUTE_INHERIT
                : Routing::ROUTE_NONE;
        }
        update_option(Routing::OPTION_CONTEXT_MAP, $map);
    }
}
