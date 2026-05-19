<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

/**
 * Decides which "source" the current wp_mail() call belongs to so the
 * MailRouter can pick the right identity. Sources are open-ended strings;
 * the built-ins are the four declared as constants below. Plugins or other
 * modules can introduce new sources by pushing them on the stack or by
 * filtering `lrob_etk_smtp_source`.
 *
 * Resolution order:
 *   1. The most recently pushed explicit source (via push()/pop()), if any.
 *   2. Auto-detection (WooCommerce mail context).
 *   3. Built-in default 'default'.
 *
 * Every value is then run through the `lrob_etk_smtp_source` filter so it
 * can be overridden globally.
 */
final class SourceResolver
{
    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_NEWSLETTER = 'newsletter';

    public const SOURCE_CONTACT_FORM = 'contact_form';

    public const SOURCE_WOOCOMMERCE = 'woocommerce';

    /** @var array<int, string> stack of pushed sources */
    private static array $stack = [];

    /**
     * Declare that the next wp_mail() (until pop()) belongs to a particular
     * source. Always pair with pop() — prefer try/finally to guarantee it.
     */
    public static function push(string $source): void
    {
        self::$stack[] = $source;
    }

    public static function pop(): void
    {
        array_pop(self::$stack);
    }

    /**
     * Helper: run $callback with $source pushed, automatically popping after.
     * Use this when convenient — `SourceResolver::with('newsletter', fn() => wp_mail(...));`
     *
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

    // Fallback when the WC-callback wrapper hasn't pushed 'woocommerce' onto
    // the stack (e.g. mail sent from a different action lifecycle).
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
