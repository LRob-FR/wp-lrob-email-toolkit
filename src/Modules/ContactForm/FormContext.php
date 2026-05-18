<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Per-render state shared between the embed block (which opens the &lt;form&gt;
 * wrapper) and the field blocks rendered inside it. Field blocks need the
 * current form id + instance id so their input `name` attributes scope
 * properly (`lrob_etk_cf[instance][slug]`) and so two embeds of the same form
 * on a single page don't collide.
 *
 * Always paired: ::start() before do_blocks(), ::end() after in a finally.
 * Field blocks rendered outside a form context produce no output.
 */
final class FormContext
{
    public const FIELD_NAME_PREFIX = 'lrob_etk_cf';

    private static ?int $form_id = null;

    private static ?string $instance = null;

    public static function start(int $form_id, string $instance): void
    {
        self::$form_id = $form_id;
        self::$instance = $instance;
    }

    public static function end(): void
    {
        self::$form_id = null;
        self::$instance = null;
    }

    public static function is_active(): bool
    {
        return self::$form_id !== null && self::$instance !== null;
    }

    public static function form_id(): int
    {
        return self::$form_id ?? 0;
    }

    public static function instance(): string
    {
        return self::$instance ?? '';
    }

    /** name="lrob_etk_cf[instance][slug]" */
    public static function field_name(string $slug, bool $multiple = false): string
    {
        return self::FIELD_NAME_PREFIX . '[' . self::instance() . '][' . $slug . ']' . ($multiple ? '[]' : '');
    }

    public static function field_id(string $slug): string
    {
        return 'lrob-etk-cf-' . self::instance() . '-' . sanitize_html_class($slug);
    }
}
