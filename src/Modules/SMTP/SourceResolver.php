<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

// Docs: docs/smtp.md
final class SourceResolver
{
    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_NEWSLETTER = 'newsletter';

    public const SOURCE_CONTACT_FORM = 'contact_form';

    public const SOURCE_WOOCOMMERCE = 'woocommerce';

    /** @var array<int, string> stack of pushed sources */
    private static array $stack = [];

    /** Always pair with pop() — prefer try/finally or use with(). */
    public static function push(string $source): void
    {
        self::$stack[] = $source;
    }

    public static function pop(): void
    {
        array_pop(self::$stack);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function with(string $source, callable $callback): mixed
    {
        self::push($source);
        try {
            return $callback();
        } finally {
            self::pop();
        }
    }

    public function resolve(): string
    {
        $source = self::$stack === []
            ? $this->auto_detect()
            : end(self::$stack);

        $filtered = apply_filters('lrob_etk_smtp_source', $source);
        return is_string($filtered) && $filtered !== '' ? $filtered : self::SOURCE_DEFAULT;
    }

    private function auto_detect(): string
    {
        if (function_exists('did_action') && (
            doing_action('woocommerce_mail_callback')
            || doing_filter('woocommerce_mail_callback')
        )) {
            return self::SOURCE_WOOCOMMERCE;
        }

        return self::SOURCE_DEFAULT;
    }
}
