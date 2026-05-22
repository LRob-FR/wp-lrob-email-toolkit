<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

/**
 * Contract every form-builder field type implements. Modules register one
 * instance per allowed field type for each of their form CPTs via
 * FieldTypeRegistry — Contact Form ships the stock text/email/select/etc.
 * types and a contact-form-specific captcha type; Newsletter (later) will
 * register its own list-picker / category-picker / etc. for its own CPT.
 *
 * Renderers are stateless — they take the field's attributes (the
 * normalised structure entry) and emit HTML. Frontend vs editor mode is
 * surfaced via FormContext::is_editor(); a single render() method handles
 * both because the markup is almost identical (the editor JS overlays
 * controls on top of the same DOM).
 */
interface FieldTypeInterface
{
    /** Stable slug used in the stored structure (e.g. 'text', 'list_picker'). */
    public function slug(): string;

    /** Human-readable label shown in the editor field picker. */
    public function label(): string;

    /**
     * Sanitise a single field entry. Returns the canonical shape (always
     * including 'type' set to slug()) or null to drop the entry as malformed.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>|null
     */
    public function normalize(array $field): ?array;

    /**
     * Render the field. The host module is responsible for starting/ending
     * FormContext around the call; this method only produces the field HTML.
     * Returns an empty string when FormContext is inactive (caller error)
     * so a stray field block outside an embed never leaks markup.
     *
     * @param array<string, mixed> $attrs
     */
    public function render(array $attrs): string;
}
