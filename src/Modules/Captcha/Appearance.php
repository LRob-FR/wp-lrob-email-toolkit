<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

// Docs: docs/captcha.md → "Appearance"
final class Appearance
{
    public const THEME_AUTO  = 'auto';
    public const THEME_LIGHT = 'light';
    public const THEME_DARK  = 'dark';

    public const SIZE_NORMAL    = 'normal';
    public const SIZE_COMPACT   = 'compact';
    public const SIZE_INVISIBLE = 'invisible';

    public static function normalize_theme(string $value): string
    {
        return in_array($value, [self::THEME_AUTO, self::THEME_LIGHT, self::THEME_DARK], true)
            ? $value
            : self::THEME_AUTO;
    }

    public static function normalize_size(string $value): string
    {
        return in_array($value, [self::SIZE_NORMAL, self::SIZE_COMPACT, self::SIZE_INVISIBLE], true)
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

    /**
     * @param bool $include_invisible Append the "Invisible" option — only for
     *                                providers whose supports_invisible() is true.
     * @return array<int, array{value:string, label:string}>
     */
    public static function size_options(bool $include_invisible = false): array
    {
        $options = [
            ['value' => self::SIZE_NORMAL,  'label' => __('Normal', 'lrob-email-toolkit')],
            ['value' => self::SIZE_COMPACT, 'label' => __('Compact', 'lrob-email-toolkit')],
        ];
        if ($include_invisible) {
            $options[] = ['value' => self::SIZE_INVISIBLE, 'label' => __('Invisible', 'lrob-email-toolkit')];
        }
        return $options;
    }
}
