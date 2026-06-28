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
    /**
     * verify() failure reason: no answer/token was submitted at all — for a
     * hosted provider this means the vendor script never loaded (blocked by a
     * content/cookie blocker), so the submission is bot-noise, not a reviewable
     * attempt. Callers use it to reject without persisting. See docs/captcha.md.
     */
    public const REASON_TOKEN_MISSING = 'token_missing';

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
     * Verify a submitted answer. Returns [success_bool, ?error_message,
     * ?reason]. Error message is user-facing and already translated. The
     * optional third element is a machine reason code (e.g.
     * REASON_TOKEN_MISSING) so callers can branch on *why* it failed; absent
     * means "no special reason".
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $context
     * @return array{0:bool, 1:?string, 2?:string}
     */
    public function verify(array $post, array $context = []): array;
}
