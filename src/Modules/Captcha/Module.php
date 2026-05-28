<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\Captcha\Admin\PageController;
use LRob\EmailToolkit\Modules\Captcha\Challenges\ChallengeInterface;

/**
 * Captcha service module. Owns the shared anti-bot challenge pool that
 * ContactForm (and eventually Newsletter, comments, lost-password) uses.
 * Always enabled — the toolkit always has at least one challenge available
 * — so this module ignores the standard enable/disable toggle.
 *
 * Two flavours of challenge live side by side:
 *  - Homemade (Challenges/) — self-contained, no credentials.
 *  - Hosted provider (Providers/) — needs a credentialled identity row.
 * Both directories are auto-scanned at boot.
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

    /**
     * Schema version 6 = identities table + context map + stats table +
     * per-identity widget appearance (theme/size) columns + WP-native
     * contexts flipped off.
     *
     * v3 was the recovery bump from a broken v0.1.0 first-release migration
     * that set version=2 without creating the table. v4 added the stats table
     * (day_date, route_key, outcome, n). v5 added the `theme` + `size` columns
     * to the identities table. v6 flips WordPress-native contexts that were
     * on 'inherit' to 'none' (opt-in model). migrate() forwards every call
     * back into install(), which is idempotent — dbDelta CREATE TABLE for
     * both tables (and ALTERs the new columns onto existing rows with their
     * defaults) + a "seed if missing" guard + the WP-native off flip.
     */
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

    /**
     * Align existing installs with the opt-in model: WordPress-native
     * contexts (comments / lost-password / registration) left on the
     * ambiguous 'inherit' (or empty) by an earlier seed get flipped to
     * 'none'. Explicit per-context challenge choices are left untouched —
     * only the "follow the default" state is treated as "not opted in yet".
     * Idempotent: after the flip those contexts are 'none', so re-runs
     * change nothing (the UI no longer offers 'inherit' for them).
     */
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

    /**
     * Sites running v0.0.7 already had `lrob_etk_captcha_db_version=1`
     * recorded by AbstractModule's default-version no-op install path
     * (service modules run maybe_migrate every boot). When we bump the
     * target to 2 those sites take the migrate() branch — not install() —
     * so the new identities table never gets created. Forward that path
     * back into install(); both Schema::install (dbDelta) and the
     * context-map seed are fully idempotent.
     */
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
            // Persistent notice when any non-trivial route in the context
            // map can't resolve — surfaces fail-closed verify() situations
            // to the admin before they get a support ticket.
            add_action('admin_notices', [$this, 'render_broken_routes_notice']);
        }

        (new WpHooks($service))->register();
    }

    /**
     * Walk the routing map and flag any entry pointing at something that
     * doesn't exist anymore (deleted identity, inactive identity, missing
     * provider class, undecryptable credentials). Only renders when there's
     * something to report.
     */
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

    /**
     * Auto-discover and register every challenge/provider dropped into
     * `Challenges/` or `Providers/`. Adding a new homemade challenge or
     * a new hosted provider is as simple as creating a class in the right
     * folder that implements ChallengeInterface (or ProviderInterface) —
     * no edits to this file, no glue code anywhere else.
     */
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

    /**
     * One-time migration from the v0.0.7 single-default world to the new
     * per-context routing map. Runs once on install() (which itself only
     * runs when db_version was 0); subsequent boots short-circuit via
     * maybe_migrate().
     *
     * - If a context map already exists, leave it alone (admin already
     *   moved past the migration).
     * - Otherwise seed `default` from the legacy `active_challenge` option,
     *   falling back to homemade:math.
     */
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

        // This plugin's own form contexts inherit the site default so
        // freshly-created contact / newsletter forms get captcha out of the
        // box. WordPress-native contexts (comments / lost-password /
        // registration) start as 'none' so adding the WpHooks doesn't
        // surprise the admin — they opt in explicitly per section.
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
