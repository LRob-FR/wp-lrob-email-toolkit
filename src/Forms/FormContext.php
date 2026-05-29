<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

// Docs: docs/forms.md
final class FormContext
{
    private static ?int $form_id = null;

    private static ?string $instance = null;

    private static string $name_prefix = '';

    private static string $id_prefix = '';

    private static bool $editor = false;

    public static function start(
        int $form_id,
        string $instance,
        string $name_prefix,
        string $id_prefix,
        bool $editor = false
    ): void {
        self::$form_id = $form_id;
        self::$instance = $instance;
        self::$name_prefix = $name_prefix;
        self::$id_prefix = $id_prefix;
        self::$editor = $editor;
    }

    public static function end(): void
    {
        self::$form_id = null;
        self::$instance = null;
        self::$name_prefix = '';
        self::$id_prefix = '';
        self::$editor = false;
    }

    public static function is_active(): bool
    {
        return self::$form_id !== null && self::$instance !== null;
    }

    public static function is_editor(): bool
    {
        return self::$editor;
    }

    public static function form_id(): int
    {
        return self::$form_id ?? 0;
    }

    public static function instance(): string
    {
        return self::$instance ?? '';
    }

    public static function name_prefix(): string
    {
        return self::$name_prefix;
    }

    public static function field_name(string $slug, bool $multiple = false): string
    {
        return self::$name_prefix . '[' . self::instance() . '][' . $slug . ']' . ($multiple ? '[]' : '');
    }

    public static function field_id(string $slug): string
    {
        return self::$id_prefix . '-' . self::instance() . '-' . sanitize_html_class($slug);
    }
}
