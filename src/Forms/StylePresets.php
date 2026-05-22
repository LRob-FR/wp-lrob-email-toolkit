<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

/**
 * Style presets for forms rendered through the shared form-builder.
 * Both Contact Form and Newsletter subscribe forms emit the same root
 * class `lrob-etk-form-preset--<slug>`, and the frontend CSS
 * (assets/css/contact-form.css) maps those class modifiers to actual
 * styles — so the preset list must be shared between modules to avoid
 * "preset X works on contact forms but not on newsletter forms" drift.
 *
 * Add a new preset:
 *   1. Add the slug + label here.
 *   2. Add the matching `.lrob-etk-form-preset--<slug>` selectors to
 *      assets/css/contact-form.css.
 *   3. Both modules pick it up automatically.
 */
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

    /** Whitelist check — useful when sanitising input from forms / REST. */
    public static function is_valid(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }
}
