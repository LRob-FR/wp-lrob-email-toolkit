<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

// Docs: docs/forms.md
final class FieldTypeRegistry
{
    /** @var array<string, array<string, FieldTypeInterface>> */
    private array $by_cpt = [];

    public function register(string $cpt_slug, FieldTypeInterface $type): void
    {
        $this->by_cpt[$cpt_slug][$type->slug()] = $type;
    }

    /** @return array<string, FieldTypeInterface> */
    public function for_cpt(string $cpt_slug): array
    {
        return $this->by_cpt[$cpt_slug] ?? [];
    }

    public function get(string $cpt_slug, string $type_slug): ?FieldTypeInterface
    {
        return $this->by_cpt[$cpt_slug][$type_slug] ?? null;
    }

    /** @return array<int, string> */
    public function slugs_for_cpt(string $cpt_slug): array
    {
        return array_keys($this->by_cpt[$cpt_slug] ?? []);
    }
}
