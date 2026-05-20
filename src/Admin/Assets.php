<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

/**
 * Enqueues shared admin assets (CSS) and a tooltip driver that escapes
 * scroll/modal clipping by using position: fixed with JS-computed coordinates.
 */
final class Assets
{
    public const HANDLE_BASE       = 'lrob-etk-admin-base';
    public const HANDLE_COMPONENTS = 'lrob-etk-admin-components';
    public const HANDLE_DASHBOARD  = 'lrob-etk-admin-dashboard';
    public const HANDLE_SMTP       = 'lrob-etk-admin-smtp';
    public const HANDLE_LOGGING    = 'lrob-etk-admin-logging';
    public const HANDLE_CF         = 'lrob-etk-admin-contact-form';
    public const HANDLE_CAPTCHA    = 'lrob-etk-admin-captcha';

    private const STYLE_FILES = [
        self::HANDLE_BASE       => 'admin/css/admin-base.css',
        self::HANDLE_COMPONENTS => 'admin/css/admin-components.css',
        self::HANDLE_DASHBOARD  => 'admin/css/admin-dashboard.css',
        self::HANDLE_SMTP       => 'admin/css/admin-smtp.css',
        self::HANDLE_LOGGING    => 'admin/css/admin-logging.css',
        self::HANDLE_CF         => 'admin/css/admin-contact-form.css',
        self::HANDLE_CAPTCHA    => 'admin/css/admin-captcha.css',
    ];

    public const HANDLE_CONTROLS_JS = 'lrob-etk-controls';

    public static function enqueue_admin(string $hook_suffix): void
    {
        if (!self::is_plugin_page($hook_suffix)) {
            return;
        }

        // Chained dependencies preserve cascade order: each file lists the
        // previous handle as a dep so WordPress enqueues them in this order.
        $deps = [];
        foreach (self::STYLE_FILES as $handle => $relative_path) {
            wp_enqueue_style(
                $handle,
                LROB_ETK_URL . $relative_path,
                $deps,
                self::asset_version($relative_path)
            );
            $deps = [$handle];
        }

        // Shared admin UI components (combobox). Every toolkit page may use
        // them, so we load once here instead of per-module. Loaded in
        // <head> — the SMTP identity card's inline <script> runs mid-body
        // and synchronously calls lrobEtkControls.attachCombobox, so this
        // global has to exist before the body parses. Moving back to the
        // footer silently breaks every dropdown on the SMTP card.
        wp_enqueue_script(
            self::HANDLE_CONTROLS_JS,
            LROB_ETK_URL . 'admin/js/etk-controls.js',
            [],
            self::asset_version('admin/js/etk-controls.js'),
            false
        );

        add_action('admin_footer', [self::class, 'print_tooltip_script']);
    }

    public static function print_tooltip_script(): void
    {
        ?>
        <script>
        (function () {
            // Tooltip handling: hover, focus, and click-to-stick.
            // Tooltips use position:fixed with JS-computed coordinates so they
            // escape clipping from any scrollable ancestor (modals, sidebars).

            function showTip(tipEl) {
                var text = tipEl.querySelector('.lrob-etk-tip-text');
                if (!text) return;
                // Make visible first so we can measure
                text.classList.add('is-shown');
                positionTip(tipEl, text);
            }

            function hideTip(tipEl) {
                var text = tipEl.querySelector('.lrob-etk-tip-text');
                if (text) text.classList.remove('is-shown');
            }

            function hideAll() {
                Array.prototype.forEach.call(document.querySelectorAll('.lrob-etk-tip.is-open'), function (t) {
                    t.classList.remove('is-open');
                });
                Array.prototype.forEach.call(document.querySelectorAll('.lrob-etk-tip-text.is-shown'), function (t) {
                    t.classList.remove('is-shown');
                });
            }

            function positionTip(tipEl, textEl) {
                var iconRect = tipEl.getBoundingClientRect();
                var textRect = textEl.getBoundingClientRect();
                var vw = window.innerWidth;
                var vh = window.innerHeight;
                var margin = 8;

                // Vertical: prefer above; flip below if not enough room above.
                var top = iconRect.top - textRect.height - margin;
                if (top < margin) {
                    top = iconRect.bottom + margin;
                    // If still overflows bottom, clamp to viewport.
                    if (top + textRect.height > vh - margin) {
                        top = Math.max(margin, vh - textRect.height - margin);
                    }
                }

                // Horizontal: center on icon, clamp to viewport.
                var left = iconRect.left + iconRect.width / 2 - textRect.width / 2;
                if (left < margin) left = margin;
                if (left + textRect.width > vw - margin) {
                    left = vw - textRect.width - margin;
                }

                textEl.style.top = top + 'px';
                textEl.style.left = left + 'px';
            }

            document.addEventListener('mouseover', function (e) {
                var tip = e.target.closest && e.target.closest('.lrob-etk-tip');
                if (tip && !tip.classList.contains('is-open')) showTip(tip);
            });
            document.addEventListener('mouseout', function (e) {
                var tip = e.target.closest && e.target.closest('.lrob-etk-tip');
                if (!tip || tip.classList.contains('is-open')) return;
                var to = e.relatedTarget;
                if (!to || !tip.contains(to)) hideTip(tip);
            });
            document.addEventListener('focusin', function (e) {
                if (e.target.classList && e.target.classList.contains('lrob-etk-tip')) showTip(e.target);
            });
            document.addEventListener('focusout', function (e) {
                if (e.target.classList && e.target.classList.contains('lrob-etk-tip') && !e.target.classList.contains('is-open')) {
                    hideTip(e.target);
                }
            });
            document.addEventListener('click', function (e) {
                var tip = e.target.closest && e.target.closest('.lrob-etk-tip');
                if (tip) {
                    e.stopPropagation();
                    var wasOpen = tip.classList.contains('is-open');
                    hideAll();
                    if (!wasOpen) {
                        tip.classList.add('is-open');
                        showTip(tip);
                    }
                } else {
                    hideAll();
                }
            });
            // Re-position open sticky tooltips on scroll/resize so they don't drift.
            window.addEventListener('scroll', function () {
                Array.prototype.forEach.call(document.querySelectorAll('.lrob-etk-tip.is-open'), function (tip) {
                    var text = tip.querySelector('.lrob-etk-tip-text');
                    if (text && text.classList.contains('is-shown')) positionTip(tip, text);
                });
            }, true);
            window.addEventListener('resize', function () {
                Array.prototype.forEach.call(document.querySelectorAll('.lrob-etk-tip-text.is-shown'), function (text) {
                    var tip = text.closest('.lrob-etk-tip');
                    if (tip) positionTip(tip, text);
                });
            });
        })();
        </script>
        <?php
    }

    private static function is_plugin_page(string $hook_suffix): bool
    {
        return str_contains($hook_suffix, 'lrob-etk');
    }

    /**
     * Cache-busting version string: plugin version + file mtime. The mtime
     * suffix means every CSS/JS edit produces a different `?ver=…` so
     * browsers fetch fresh — no version bump required, no stale-cache
     * surprise when we re-zip the same release with a fix.
     */
    private static function asset_version(string $relative_path): string
    {
        return self::asset_version_for($relative_path);
    }

    /**
     * Public version of asset_version() so per-module enqueuers reuse the
     * same cache-busting scheme.
     */
    public static function asset_version_for(string $relative_path): string
    {
        $version = LROB_ETK_VERSION;
        $full = LROB_ETK_PATH . ltrim($relative_path, '/');
        if (is_file($full)) {
            $version .= '.' . filemtime($full);
        }
        return $version;
    }
}
