<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

/**
 * Vocabulary + normalizers for hosted-captcha widget appearance (theme +
 * size). Stored per-identity (not global) on the captcha identity row, so
 * each hCaptcha / Turnstile / reCAPTCHA account can render its own look.
 * Homemade challenges ignore appearance entirely.
 *
 * `theme = auto` follows the visitor's OS colour scheme client-side (the
 * widgets don't all support a native "auto", so the provider render resolves
 * it from `prefers-color-scheme`).
 */
final class Appearance
{
    public const THEME_AUTO  = 'auto';
    public const THEME_LIGHT = 'light';
    public const THEME_DARK  = 'dark';

    public const SIZE_NORMAL  = 'normal';
    public const SIZE_COMPACT = 'compact';

    public static function normalize_theme(string $value): string
    {
        return in_array($value, [self::THEME_AUTO, self::THEME_LIGHT, self::THEME_DARK], true)
            ? $value
            : self::THEME_AUTO;
    }

    public static function normalize_size(string $value): string
    {
        return in_array($value, [self::SIZE_NORMAL, self::SIZE_COMPACT], true)
            ? $value
            : self::SIZE_NORMAL;
    }

    /** @return array<int, array{value:string, label:string}> */
    public static function theme_options(): array
    {
        return [
            ['value' => self::THEME_AUTO,  'label' => __('Auto (follow visitor system)', 'lrob-email-toolkit')],
            ['value' => self::THEME_LIGHT, 'label' => __('Light', 'lrob-email-toolkit')],
            ['value' => self::THEME_DARK,  'label' => __('Dark', 'lrob-email-toolkit')],
        ];
    }

    /** @return array<int, array{value:string, label:string}> */
    public static function size_options(): array
    {
        return [
            ['value' => self::SIZE_NORMAL,  'label' => __('Normal', 'lrob-email-toolkit')],
            ['value' => self::SIZE_COMPACT, 'label' => __('Compact', 'lrob-email-toolkit')],
        ];
    }
}
