<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

use LRob\EmailToolkit\Modules\Captcha\Challenges\ChallengeInterface;
use LRob\EmailToolkit\Modules\Captcha\Providers\ProviderInterface;

// Docs: docs/captcha.md
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

    /** Route configured but unresolvable (deleted identity, missing class, AUTH_KEY rotated). verify() fails closed. */
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

    /** @return array<string, ProviderInterface> Hosted providers only — need identities to be usable, ordered by sort_order(). */
    public function hosted_providers(): array
    {
        $out = [];
        foreach ($this->challenges as $slug => $challenge) {
            if ($challenge instanceof ProviderInterface) {
                $out[$slug] = $challenge;
            }
        }
        uasort($out, static fn(ProviderInterface $a, ProviderInterface $b): int => $a->sort_order() <=> $b->sort_order());
        return $out;
    }

    public function identity_repository(): IdentityRepository
    {
        return $this->identities;
    }

    /** @param array<string, mixed> $context */
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
     * Returns '' for both opted-out and broken routes — visitors never see the difference.
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
     * @param array<string, mixed> $post
     * @param array<string, mixed> $context
     * @return array{0:bool, 1:?string, 2?:string}
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
        $result = $challenge->verify($post, $context);
        $ok = (bool) $result[0];
        $error = $result[1] ?? null;
        $reason = $result[2] ?? null;
        $this->stats?->record(
            $route,
            $ok ? StatsRepository::OUTCOME_PASSED : StatsRepository::OUTCOME_FAILED
        );
        return $reason !== null ? [$ok, $error, $reason] : [$ok, $error];
    }

    public function set_default_route(string $route): void
    {
        Routing::set_default($route);
    }
}
