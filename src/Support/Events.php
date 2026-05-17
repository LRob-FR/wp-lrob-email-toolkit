<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Support;

/**
 * Dispatches plugin events through two WordPress actions:
 *
 *   1. The generic hook `lrob_etk_event` with ($name, $payload), so a single
 *      listener (e.g. the future Integrations module) can subscribe once and
 *      receive every event.
 *   2. A typed hook `lrob_etk_<name_with_underscores>` with just ($payload),
 *      so callers can hook a specific event without inspecting the name.
 *
 * Example: Events::dispatch('email.sent', ['log_id' => 42]) fires both
 * `lrob_etk_event` and `lrob_etk_email_sent`.
 *
 * Event names are dot-namespaced (domain.action[.detail]). The full vocabulary
 * lives in CLAUDE.md and forms a stable public API from v0.0.1 onward.
 */
final class Events
{
    public const HOOK_GENERIC = 'lrob_etk_event';

    private const TYPED_HOOK_PREFIX = 'lrob_etk_';

    /**
     * @param array<string, mixed> $payload
     */
    public static function dispatch(string $name, array $payload = []): void
    {
        do_action(self::HOOK_GENERIC, $name, $payload);

        $typed = self::TYPED_HOOK_PREFIX . str_replace('.', '_', $name);
        do_action($typed, $payload);
    }
}
