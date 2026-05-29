<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

// Docs: docs/forms.md — to add a preset: add entry here + CSS in contact-form.css.
final class StylePresets
{
    public const DEFAULT_SLUG = 'default';

    /** @return array<string, string> slug → translated label. */
    public static function all(): array
    {
        return [
            self::DEFAULT_SLUG => __('Default', 'lrob-email-toolkit'),
            'minimal'          => __('Minimal', 'lrob-email-toolkit'),
            'soft'             => __('Soft', 'lrob-email-toolkit'),
            'contrast'         => __('Contrast', 'lrob-email-toolkit'),
        ];
    }

    public static function label_for(string $slug): string
    {
        return self::all()[$slug] ?? self::all()[self::DEFAULT_SLUG];
    }

    public static function is_valid(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }
}
