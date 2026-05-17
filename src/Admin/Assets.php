<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

/**
 * Enqueues shared admin assets (CSS used across every plugin admin page) and
 * a tiny tooltip click-to-stay-open script. Per-module pages add their own
 * inline JS for page-specific behavior.
 */
final class Assets
{
    public const HANDLE_CSS = 'lrob-etk-admin';

    public static function enqueue_admin(string $hook_suffix): void
    {
        if (!self::is_plugin_page($hook_suffix)) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE_CSS,
            LROB_ETK_URL . 'admin/css/admin.css',
            [],
            LROB_ETK_VERSION
        );

        add_action('admin_footer', [self::class, 'print_tooltip_script']);
    }

    public static function print_tooltip_script(): void
    {
        ?>
        <script>
        (function () {
            document.addEventListener('click', function (e) {
                var tip = e.target.closest && e.target.closest('.lrob-etk-tip');
                if (tip) {
                    e.stopPropagation();
                    var wasOpen = tip.classList.contains('is-open');
                    Array.prototype.forEach.call(document.querySelectorAll('.lrob-etk-tip.is-open'), function (t) {
                        t.classList.remove('is-open');
                    });
                    if (!wasOpen) tip.classList.add('is-open');
                } else {
                    Array.prototype.forEach.call(document.querySelectorAll('.lrob-etk-tip.is-open'), function (t) {
                        t.classList.remove('is-open');
                    });
                }
            });
        })();
        </script>
        <?php
    }

    private static function is_plugin_page(string $hook_suffix): bool
    {
        return str_contains($hook_suffix, 'lrob-etk');
    }
}
