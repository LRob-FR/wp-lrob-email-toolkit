<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

// Docs: docs/forms.md
interface FieldTypeInterface
{
    /** Stable slug used in the stored structure (e.g. 'text', 'list_picker'). */
    public function slug(): string;

    /** Human-readable label shown in the editor field picker. */
    public function label(): string;

    /**
     * Returns canonical shape or null to drop the entry silently.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>|null
     */
    public function normalize(array $field): ?array;

    /**
     * @param array<string, mixed> $attrs
     */
    public function render(array $attrs): string;
}
