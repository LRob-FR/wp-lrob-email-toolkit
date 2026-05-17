<?php

declare(strict_types=1);

namespace LRob\EmailToolkit;

/**
 * Tiny service locator. Modules register their shared services here so other
 * modules can read them without globals. Not a full DI container by design.
 */
final class Container
{
    /** @var array<class-string, object> */
    private array $services = [];

    public function set(string $id, object $service): void
    {
        $this->services[$id] = $service;
    }

    public function get(string $id): ?object
    {
        return $this->services[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
