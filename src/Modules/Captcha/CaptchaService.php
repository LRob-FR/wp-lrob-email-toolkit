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

    /** @var array<string, ChallengeInterface> */
    private array $challenges = [];

    public function __construct(private IdentityRepository $identities)
    {
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
     * Resolve a route to a (challenge, plaintext credentials) pair. Returns
     * [null, []] when the route is 'none', or when an identity-route points
     * at a missing/inactive identity, or when the homemade slug is unknown.
     *
     * @param array<string, mixed> $context
     * @return array{0:?ChallengeInterface, 1:array<string, string>}
     */
    public function resolve(array $context): array
    {
        $route = $this->resolve_route($context);
        if ($route === Routing::ROUTE_NONE || $route === '') {
            return [null, []];
        }
        $parsed = Routing::parse($route);
        if ($parsed['kind'] === Routing::KIND_HOMEMADE) {
            $challenge = $this->challenges[$parsed['value']] ?? null;
            return [$challenge, []];
        }
        if ($parsed['kind'] === Routing::KIND_IDENTITY) {
            $id = (int) $parsed['value'];
            if ($id <= 0) {
                return [null, []];
            }
            $identity = $this->identities->find($id);
            if ($identity === null || !$identity->is_active) {
                return [null, []];
            }
            $challenge = $this->challenges[$identity->provider_slug] ?? null;
            if ($challenge === null) {
                return [null, []];
            }
            try {
                $credentials = $identity->decrypted_credentials();
            } catch (\RuntimeException) {
                // Credentials unreadable (e.g. AUTH_KEY rotated). Fail closed —
                // the form won't render and admin must re-enter credentials.
                return [null, []];
            }
            return [$challenge, $credentials];
        }
        return [null, []];
    }

    /**
     * Render the resolved challenge. Returns '' when no challenge applies.
     * Identity credentials (if any) are injected into $context['credentials']
     * before delegating to the challenge's own render().
     *
     * @param array<string, mixed> $context
     */
    public function render(array $context = []): string
    {
        [$challenge, $credentials] = $this->resolve($context);
        if ($challenge === null) {
            return '';
        }
        if ($credentials !== []) {
            $context['credentials'] = $credentials;
        }
        return $challenge->render($context);
    }

    /**
     * Verify the resolved challenge. Returns [true, null] when no challenge
     * applies (so callers don't have to special-case 'none').
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $context
     * @return array{0:bool, 1:?string}
     */
    public function verify(array $post, array $context = []): array
    {
        [$challenge, $credentials] = $this->resolve($context);
        if ($challenge === null) {
            return [true, null];
        }
        if ($credentials !== []) {
            $context['credentials'] = $credentials;
        }
        return $challenge->verify($post, $context);
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
