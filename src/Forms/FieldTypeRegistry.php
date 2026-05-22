<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

/**
 * Per-CPT registry of allowed field types. Each module that owns a form
 * CPT (Contact Form, Newsletter, …) registers its field types here during
 * boot — the shared FormStructure normaliser and FormEditorRenderer
 * dispatch through the registry instead of hard-coding type slugs.
 *
 * Per-CPT isolation matters: a Contact Form's text/email/textarea types
 * should not bleed into a Newsletter form's allowed types (and vice
 * versa). Same slug across CPTs is fine — each CPT has its own bucket.
 */
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
