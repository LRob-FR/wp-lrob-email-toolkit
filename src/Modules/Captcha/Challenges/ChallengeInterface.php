<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Challenges;

/**
 * Self-contained anti-bot challenge. Implementations render their own HTML
 * and verify their own submitted values — the surrounding form doesn't know
 * what kind of challenge is active.
 */
interface ChallengeInterface
{
    /** Stable identifier used in settings + admin UI (lowercase, snake_case). */
    public function slug(): string;

    /** Human-readable name (translated). */
    public function label(): string;

    /** Brief description for the admin picker (translated). */
    public function description(): string;

    /**
     * Render the challenge HTML inline in the form. `$context` lets the
     * challenge know who's calling (e.g. 'contact_form', 'newsletter',
     * 'comments') if it needs to scope token names — most implementations
     * can ignore it.
     *
     * @param array<string, mixed> $context
     */
    public function render(array $context = []): string;

    /**
     * Verify a submitted answer. Returns [success_bool, ?error_message].
     * Error message is user-facing and already translated.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $context
     * @return array{0:bool, 1:?string}
     */
    public function verify(array $post, array $context = []): array;
}
