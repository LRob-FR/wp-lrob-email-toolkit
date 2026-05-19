<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

use LRob\EmailToolkit\Modules\Captcha\Challenges\ChallengeInterface;

/**
 * Public API for anti-bot challenges. Other modules grab this from the
 * Container and call render()/verify() without knowing which challenge is
 * active. Consumers planned: ContactForm (live), Newsletter, comments,
 * lost-password, registration.
 *
 * Challenges register themselves via add_challenge() during module boot.
 * The active challenge slug lives in the `lrob_etk_captcha_settings`
 * option; if the configured one isn't registered (e.g. removed by a plugin
 * update) we fall back to the first registered challenge — there is always
 * at least one active challenge as long as MathChallenge exists.
 */
final class CaptchaService
{
    public const OPTION_SETTINGS = 'lrob_etk_captcha_settings';

    public const SETTING_ACTIVE = 'active_challenge';

    public const SLUG_NONE = 'none';

    /** @var array<string, ChallengeInterface> */
    private array $challenges = [];

    public function add_challenge(ChallengeInterface $challenge): void
    {
        $this->challenges[$challenge->slug()] = $challenge;
    }

    /** @return array<string, ChallengeInterface> */
    public function available(): array
    {
        return $this->challenges;
    }

    public function active_slug(): string
    {
        $settings = (array) get_option(self::OPTION_SETTINGS, []);
        $slug = isset($settings[self::SETTING_ACTIVE]) ? (string) $settings[self::SETTING_ACTIVE] : '';
        if ($slug === self::SLUG_NONE) {
            return self::SLUG_NONE;
        }
        if (isset($this->challenges[$slug])) {
            return $slug;
        }
        // Fallback: first registered challenge.
        $first = array_key_first($this->challenges);
        return $first !== null ? $first : self::SLUG_NONE;
    }

    public function active(): ?ChallengeInterface
    {
        $slug = $this->active_slug();
        return $this->challenges[$slug] ?? null;
    }

    /**
     * Render the active challenge for a calling context. Returns empty
     * string when no challenge is active or registered. Callers may pin a
     * specific challenge via `$context['force_slug']` — used by the
     * Contact Form per-form challenge override so a form can pick
     * "image" while the captcha module's global default is "math".
     *
     * @param array<string, mixed> $context
     */
    public function render(array $context = []): string
    {
        $challenge = $this->resolve_challenge($context);
        return $challenge !== null ? $challenge->render($context) : '';
    }

    /**
     * Verify the active challenge against submitted POST data. Returns
     * [true, null] when no challenge is active (so callers don't have to
     * special-case the "none" path). Same `force_slug` override as render().
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $context
     * @return array{0:bool, 1:?string}
     */
    public function verify(array $post, array $context = []): array
    {
        $challenge = $this->resolve_challenge($context);
        if ($challenge === null) {
            return [true, null];
        }
        return $challenge->verify($post, $context);
    }

    /** @param array<string, mixed> $context */
    private function resolve_challenge(array $context): ?ChallengeInterface
    {
        $forced = isset($context['force_slug']) && is_string($context['force_slug']) ? $context['force_slug'] : '';
        if ($forced !== '' && $forced !== self::SLUG_NONE && isset($this->challenges[$forced])) {
            return $this->challenges[$forced];
        }
        return $this->active();
    }

    public function set_active(string $slug): void
    {
        $settings = (array) get_option(self::OPTION_SETTINGS, []);
        $settings[self::SETTING_ACTIVE] = $slug;
        update_option(self::OPTION_SETTINGS, $settings);
    }
}
