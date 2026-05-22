<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

/**
 * Per-render state shared between the embed renderer (which opens the
 * &lt;form&gt; wrapper) and the field renderers inside it. Field renderers
 * need the current form id + instance id so their input `name` attributes
 * scope properly (`<name_prefix>[instance][slug]`) and so two embeds of
 * the same form on a single page don't collide.
 *
 * Host-neutral: each consumer module passes its own field-name prefix
 * (e.g. `lrob_etk_cf` for Contact Form, `lrob_etk_nl` for Newsletter) at
 * start() time so we can mount two different form-CPTs side by side
 * without their POST data colliding.
 *
 * Always paired: ::start() before rendering, ::end() after in a finally.
 * Field renderers called outside a form context produce no output.
 */
final class FormContext
{
    private static ?int $form_id = null;

    private static ?string $instance = null;

    private static string $name_prefix = '';

    private static string $id_prefix = '';

    private static bool $editor = false;

    /**
     * Start a render scope. `$name_prefix` is the top-level `$_POST` key
     * the host module reads on submit (e.g. `lrob_etk_cf`); `$id_prefix`
     * seeds DOM ids (`<id_prefix>-<instance>-<slug>`).
     */
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

    /** True when fields are being rendered for the admin WYSIWYG editor. */
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

    /** name="<prefix>[instance][slug]" — host-determined prefix. */
    public static function field_name(string $slug, bool $multiple = false): string
    {
        return self::$name_prefix . '[' . self::instance() . '][' . $slug . ']' . ($multiple ? '[]' : '');
    }

    public static function field_id(string $slug): string
    {
        return self::$id_prefix . '-' . self::instance() . '-' . sanitize_html_class($slug);
    }
}
