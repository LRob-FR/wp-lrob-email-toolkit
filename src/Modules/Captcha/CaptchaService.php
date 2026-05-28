<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

use LRob\EmailToolkit\Modules\Captcha\Challenges\ChallengeInterface;
use LRob\EmailToolkit\Modules\Captcha\Providers\ProviderInterface;

/**
 * Public API for anti-bot challenges. Other modules grab this from the
 * Container and call render()/verify() without knowing which challenge is
 * active. Consumers planned: ContactForm (live), Newsletter, comments,
 * lost-password, registration.
 *
 * Resolution happens via routing keys (see Routing) — strings like
 * 'homemade:math' or 'identity:7'. Callers may pass a `context` (one of
 * Routing::CONTEXT_*) and the service looks up the effective route in
 * the persisted context map, OR an explicit `force_route` to bypass it.
 * There is always at least one homemade challenge so a truly-empty
 * configuration still does something useful.
 */
final class CaptchaService
{
    /** Legacy single-default option, kept for one-time migration only. */
    public const OPTION_SETTINGS = 'lrob_etk_captcha_settings';

    public const SETTING_ACTIVE = 'active_challenge';

    public const SLUG_NONE = 'none';

    /** Route resolved successfully — challenge + (maybe-empty) credentials returned. */
    public const STATE_OK = 'ok';

    /** Route is intentionally `none` (or unset) — admin opted out, no challenge to run. */
    public const STATE_NONE = 'none';

    /**
     * Route is configured but can't resolve: identity row missing/inactive,
     * provider class not registered, or credentials un-decryptable (e.g.
     * AUTH_KEY rotated since save). verify() must fail closed in this state
     * — otherwise bots silently bypass the captcha while the admin UI
     * looks fine.
     */
    public const STATE_BROKEN = 'broken';

    /** @var array<string, ChallengeInterface> */
    private array $challenges = [];

    public function __construct(
        private IdentityRepository $identities,
        private ?StatsRepository $stats = null,
    ) {
    }

    public function add_challenge(ChallengeInterface $challenge): void
    {
        $this->challenges[$challenge->slug()] = $challenge;
    }

    /** @return array<string, ChallengeInterface> Every challenge registered (homemade + hosted). */
    public function available(): array
    {
        return $this->challenges;
    }

    /** @return array<string, ChallengeInterface> Homemade challenges only — no credentials needed. */
    public function homemade_challenges(): array
    {
        $out = [];
        foreach ($this->challenges as $slug => $challenge) {
            if (!($challenge instanceof ProviderInterface)) {
                $out[$slug] = $challenge;
            }
        }
        return $out;
    }

    /** @return array<string, ProviderInterface> Hosted providers only — need identities to be usable. */
    public function hosted_providers(): array
    {
        $out = [];
        foreach ($this->challenges as $slug => $challenge) {
            if ($challenge instanceof ProviderInterface) {
                $out[$slug] = $challenge;
            }
        }
        return $out;
    }

    public function identity_repository(): IdentityRepository
    {
        return $this->identities;
    }

    /**
     * Resolve a routing key from a render/verify context. Priority:
     *  1. `force_route` (explicit routing-key string)
     *  2. `context`     (one of Routing::CONTEXT_* → effective_route)
     *  3. Global default from the context map
     *
     * @param array<string, mixed> $context
     */
    public function resolve_route(array $context): string
    {
        $force_route = isset($context['force_route']) && is_string($context['force_route']) ? $context['force_route'] : '';
        if ($force_route !== '' && $force_route !== Routing::ROUTE_INHERIT) {
            return $force_route;
        }
        $consumer = isset($context['context']) && is_string($context['context']) ? $context['context'] : '';
        if ($consumer !== '') {
            return Routing::effective_route($consumer);
        }
        return Routing::default_route();
    }

    /**
     * Resolve a route to a (challenge, credentials, state) triple. The
     * state distinguishes:
     *   - STATE_OK     : challenge non-null, ready to run.
     *   - STATE_NONE   : admin intentionally turned this context off — no
     *                    challenge expected, verify() should pass.
     *   - STATE_BROKEN : route points at something missing/inactive/
     *                    undecryptable. verify() must fail closed so bots
     *                    can't bypass while the admin UI looks fine.
     *
     * Existing callers that destructure only the first two elements (like
     * the diagnostics page) keep working — PHP list destructuring ignores
     * extra elements.
     *
     * @param array<string, mixed> $context
     * @return array{0:?ChallengeInterface, 1:array<string, string>, 2:string}
     */
    public function resolve(array $context): array
    {
        $route = $this->resolve_route($context);
        if ($route === Routing::ROUTE_NONE) {
            return [null, [], self::STATE_NONE];
        }
        if ($route === '' || $route === Routing::ROUTE_INHERIT) {
            // Empty or inherit at the top level — equivalent to "not
            // configured", treat as opted-out rather than broken.
            return [null, [], self::STATE_NONE];
        }
        $parsed = Routing::parse($route);
        if ($parsed['kind'] === Routing::KIND_HOMEMADE) {
            $challenge = $this->challenges[$parsed['value']] ?? null;
            if ($challenge === null) {
                // Slug stored in routing but the challenge class is gone
                // (e.g. file deleted between releases). Broken.
                return [null, [], self::STATE_BROKEN];
            }
            return [$challenge, [], self::STATE_OK];
        }
        if ($parsed['kind'] === Routing::KIND_IDENTITY) {
            $id = (int) $parsed['value'];
            if ($id <= 0) {
                return [null, [], self::STATE_BROKEN];
            }
            $identity = $this->identities->find($id);
            if ($identity === null || !$identity->is_active) {
                return [null, [], self::STATE_BROKEN];
            }
            $challenge = $this->challenges[$identity->provider_slug] ?? null;
            if ($challenge === null) {
                return [null, [], self::STATE_BROKEN];
            }
            try {
                $credentials = $identity->decrypted_credentials();
            } catch (\RuntimeException) {
                // Credentials unreadable (e.g. AUTH_KEY rotated). Broken.
                return [null, [], self::STATE_BROKEN];
            }
            return [$challenge, $credentials, self::STATE_OK];
        }
        return [null, [], self::STATE_BROKEN];
    }

    /**
     * Render the resolved challenge. Returns '' when no challenge applies
     * (either opted-out OR broken — visitors never see the difference).
     * Admins get a separate persistent notice for broken routes via the
     * Module's render_broken_routes_notice hook. Identity credentials, if
     * any, are injected into $context['credentials'] before delegating.
     *
     * @param array<string, mixed> $context
     */
    public function render(array $context = []): string
    {
        [$challenge, $credentials, ] = $this->resolve($context);
        if ($challenge === null) {
            return '';
        }
        if ($credentials !== []) {
            $context['credentials'] = $credentials;
        }
        // Per-identity widget appearance (theme/size). Hosted providers read
        // it; homemade challenges ignore it.
        $context['display'] = $this->resolve_display($context);
        return $challenge->render($context);
    }

    /**
     * Resolve the widget appearance (theme/size) for the active route. Only
     * hosted-provider identities carry appearance; homemade challenges and
     * unresolvable routes return [].
     *
     * @param array<string, mixed> $context
     * @return array{theme?:string, size?:string}
     */
    private function resolve_display(array $context): array
    {
        $parsed = Routing::parse($this->resolve_route($context));
        if ($parsed['kind'] !== Routing::KIND_IDENTITY) {
            return [];
        }
        $identity = $this->identities->find((int) $parsed['value']);
        if ($identity === null) {
            return [];
        }
        return ['theme' => $identity->theme, 'size' => $identity->size];
    }

    /**
     * Verify the resolved challenge. Three outcomes:
     *   - Challenge resolved      → run challenge->verify()
     *   - STATE_NONE (opted out)  → [true, null] — admin chose not to challenge
     *   - STATE_BROKEN (misconf.) → [false, message] — fail closed so bots
     *     can't slip past a captcha the admin thought was active. Common
     *     trigger: AUTH_KEY rotated since credentials were saved, or an
     *     identity row was deleted while still referenced by the routing
     *     map.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $context
     * @return array{0:bool, 1:?string}
     */
    public function verify(array $post, array $context = []): array
    {
        $route = $this->resolve_route($context);
        [$challenge, $credentials, $state] = $this->resolve($context);
        if ($challenge === null) {
            if ($state === self::STATE_BROKEN) {
                // Broken route counts as a failure so the dashboard tile
                // reflects what the visitor actually saw (rejection).
                $this->stats?->record($route, StatsRepository::OUTCOME_FAILED);
                return [false, __('Anti-spam check is unavailable right now. Please try again later or contact the site administrator.', 'lrob-email-toolkit')];
            }
            return [true, null];
        }
        if ($credentials !== []) {
            $context['credentials'] = $credentials;
        }
        [$ok, $error] = $challenge->verify($post, $context);
        $this->stats?->record(
            $route,
            $ok ? StatsRepository::OUTCOME_PASSED : StatsRepository::OUTCOME_FAILED
        );
        return [$ok, $error];
    }

    /**
     * Persist a new default route. Convenience wrapper around Routing so
     * callers don't have to import both classes.
     */
    public function set_default_route(string $route): void
    {
        Routing::set_default($route);
    }
}
