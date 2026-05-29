<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Support;

// Docs: docs/core.md
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
