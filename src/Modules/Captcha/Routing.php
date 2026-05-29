<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

// Docs: docs/captcha.md → "Routing"
final class Routing
{
    public const OPTION_CONTEXT_MAP = 'lrob_etk_captcha_context_map';

    public const KEY_DEFAULT = 'default';

    public const ROUTE_NONE = 'none';

    public const ROUTE_INHERIT = 'inherit';

    public const KIND_HOMEMADE = 'homemade';

    public const KIND_IDENTITY = 'identity';

    public const CONTEXT_CONTACT_FORM = 'contact_form';

    public const CONTEXT_COMMENTS = 'comments';

    public const CONTEXT_NEWSLETTER = 'newsletter_subscribe';

    public const CONTEXT_LOST_PASSWORD = 'lost_password';

    public const CONTEXT_REGISTRATION = 'registration';

    public const CONTEXT_LOGIN = 'login';

    /** @return array<int, string> Known contexts shown on the settings page, in display order. */
    public static function known_contexts(): array
    {
        return array_merge(self::plugin_contexts(), self::wp_native_contexts());
    }

    /** @return array<int, string> */
    public static function plugin_contexts(): array
    {
        return [
            self::CONTEXT_CONTACT_FORM,
            self::CONTEXT_NEWSLETTER,
        ];
    }

    /** @return array<int, string> */
    public static function wp_native_contexts(): array
    {
        return [
            self::CONTEXT_COMMENTS,
            self::CONTEXT_LOGIN,
            self::CONTEXT_LOST_PASSWORD,
            self::CONTEXT_REGISTRATION,
        ];
    }

    public static function context_label(string $context): string
    {
        return match ($context) {
            self::CONTEXT_CONTACT_FORM   => __('Contact forms', 'lrob-email-toolkit'),
            self::CONTEXT_COMMENTS       => __('Blog comments', 'lrob-email-toolkit'),
            self::CONTEXT_NEWSLETTER     => __('Newsletter subscribe', 'lrob-email-toolkit'),
            self::CONTEXT_LOGIN          => __('Login (WordPress + WooCommerce)', 'lrob-email-toolkit'),
            self::CONTEXT_LOST_PASSWORD  => __('Lost password', 'lrob-email-toolkit'),
            self::CONTEXT_REGISTRATION   => __('User registration', 'lrob-email-toolkit'),
            default                      => $context,
        };
    }

    /**
     * Decompose a routing key.
     *
     * @return array{kind:string, value:string}
     */
    public static function parse(string $route): array
    {
        if ($route === self::ROUTE_NONE || $route === self::ROUTE_INHERIT) {
            return ['kind' => $route, 'value' => ''];
        }
        $parts = explode(':', $route, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return ['kind' => '', 'value' => ''];
        }
        return ['kind' => $parts[0], 'value' => $parts[1]];
    }

    public static function homemade(string $slug): string
    {
        return self::KIND_HOMEMADE . ':' . $slug;
    }

    public static function identity(int $id): string
    {
        return self::KIND_IDENTITY . ':' . $id;
    }

    /** @return array<string, string> */
    public static function context_map(): array
    {
        $stored = get_option(self::OPTION_CONTEXT_MAP, []);
        if (!is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** Default route across the whole toolkit (or 'none' when unset). */
    public static function default_route(): string
    {
        $map = self::context_map();
        return isset($map[self::KEY_DEFAULT]) ? $map[self::KEY_DEFAULT] : self::ROUTE_NONE;
    }

    public static function effective_route(string $context): string
    {
        $map = self::context_map();
        $per_ctx = isset($map[$context]) ? $map[$context] : '';
        if ($per_ctx !== '' && $per_ctx !== self::ROUTE_INHERIT) {
            return $per_ctx;
        }
        $default = isset($map[self::KEY_DEFAULT]) ? $map[self::KEY_DEFAULT] : '';
        return $default !== '' ? $default : self::ROUTE_NONE;
    }

    public static function set_default(string $route): void
    {
        $map = self::context_map();
        $map[self::KEY_DEFAULT] = $route;
        update_option(self::OPTION_CONTEXT_MAP, $map);
    }

    public static function set_context(string $context, string $route): void
    {
        $map = self::context_map();
        $map[$context] = $route;
        update_option(self::OPTION_CONTEXT_MAP, $map);
    }

    /** Replace the whole map at once. Used by save handlers. */
    public static function replace_map(array $map): void
    {
        $clean = [];
        foreach ($map as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $clean[$key] = $value;
            }
        }
        update_option(self::OPTION_CONTEXT_MAP, $clean);
    }
}
