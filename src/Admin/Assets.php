<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

/** Enqueues shared admin assets. Load positions + version strategy in docs/admin-ui.md. */
final class Assets
{
    public const HANDLE_BASE       = 'lrob-etk-admin-base';
    public const HANDLE_COMPONENTS = 'lrob-etk-admin-components';
    public const HANDLE_DASHBOARD  = 'lrob-etk-admin-dashboard';
    public const HANDLE_SMTP       = 'lrob-etk-admin-smtp';
    public const HANDLE_LOGGING    = 'lrob-etk-admin-logging';
    public const HANDLE_CF         = 'lrob-etk-admin-contact-form';
    public const HANDLE_CAPTCHA    = 'lrob-etk-admin-captcha';
    public const HANDLE_NL         = 'lrob-etk-admin-newsletter';
    public const HANDLE_THEMES     = 'lrob-etk-admin-themes';

    private const STYLE_FILES = [
        self::HANDLE_BASE       => 'admin/css/admin-base.css',
        self::HANDLE_COMPONENTS => 'admin/css/admin-components.css',
        self::HANDLE_DASHBOARD  => 'admin/css/admin-dashboard.css',
        self::HANDLE_SMTP       => 'admin/css/admin-smtp.css',
        self::HANDLE_LOGGING    => 'admin/css/admin-logging.css',
        self::HANDLE_CF         => 'admin/css/admin-contact-form.css',
        self::HANDLE_CAPTCHA    => 'admin/css/admin-captcha.css',
        self::HANDLE_NL         => 'admin/css/admin-newsletter.css',
        // Themes last so its token overrides win the cascade.
        self::HANDLE_THEMES     => 'admin/css/admin-themes.css',
    ];

    public const HANDLE_CONTROLS_JS    = 'lrob-etk-controls';
    public const HANDLE_LIST_FILTER_JS = 'lrob-etk-list-filter';
    public const HANDLE_SORTABLE_JS    = 'lrob-etk-sortable';
    public const HANDLE_PERPAGE_JS     = 'lrob-etk-perpage';
    public const HANDLE_DETAIL_MODAL_JS = 'lrob-etk-detail-modal';
    public const HANDLE_RETENTION_TOGGLE_JS = 'lrob-etk-retention-toggle';
    public const HANDLE_MODAL_JS = 'lrob-etk-modal';
    public const HANDLE_AUTOSAVE_JS = 'lrob-etk-autosave';
    public const HANDLE_CONFIRM_JS = 'lrob-etk-confirm';
    public const HANDLE_PROMO_JS = 'lrob-etk-promo';
    public const HANDLE_THEME_JS = 'lrob-etk-theme';

    public static function enqueue_admin(string $hook_suffix): void
    {
        if (!self::is_plugin_page($hook_suffix)) {
            return;
        }

        // Chained deps preserve cascade order.
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

        // Head-loaded so the resolved theme applies before first paint (no flash).
        wp_enqueue_script(
            self::HANDLE_THEME_JS,
            LROB_ETK_URL . 'admin/js/etk-theme.js',
            [],
            self::asset_version('admin/js/etk-theme.js'),
            false
        );

        // Head-loaded: SMTP identity card calls lrobEtkControls.attachCombobox synchronously mid-body.
        wp_enqueue_script(
            self::HANDLE_CONTROLS_JS,
            LROB_ETK_URL . 'admin/js/etk-controls.js',
            [],
            self::asset_version('admin/js/etk-controls.js'),
            false
        );

        wp_enqueue_script(
            self::HANDLE_LIST_FILTER_JS,
            LROB_ETK_URL . 'admin/js/etk-list-filter.js',
            [],
            self::asset_version('admin/js/etk-list-filter.js'),
            true
        );
        wp_enqueue_script(
            self::HANDLE_SORTABLE_JS,
            LROB_ETK_URL . 'admin/js/etk-sortable.js',
            [self::HANDLE_LIST_FILTER_JS],
            self::asset_version('admin/js/etk-sortable.js'),
            true
        );
        wp_enqueue_script(
            self::HANDLE_PERPAGE_JS,
            LROB_ETK_URL . 'admin/js/etk-perpage.js',
            [self::HANDLE_LIST_FILTER_JS],
            self::asset_version('admin/js/etk-perpage.js'),
            true
        );
        wp_enqueue_script(
            self::HANDLE_DETAIL_MODAL_JS,
            LROB_ETK_URL . 'admin/js/etk-detail-modal.js',
            [],
            self::asset_version('admin/js/etk-detail-modal.js'),
            true
        );
        wp_enqueue_script(
            self::HANDLE_RETENTION_TOGGLE_JS,
            LROB_ETK_URL . 'admin/js/etk-retention-toggle.js',
            [],
            self::asset_version('admin/js/etk-retention-toggle.js'),
            true
        );
        // Head-loaded: mid-body scripts call window.lrobEtkModal.bindHeader synchronously.
        wp_enqueue_script(
            self::HANDLE_MODAL_JS,
            LROB_ETK_URL . 'admin/js/etk-modal.js',
            [],
            self::asset_version('admin/js/etk-modal.js'),
            false
        );
        wp_localize_script(self::HANDLE_MODAL_JS, 'lrobEtkModalI18n', [
            'saving' => __('Saving…', 'lrob-email-toolkit'),
            'saved'  => __('Saved', 'lrob-email-toolkit'),
            'error'  => __('Save failed', 'lrob-email-toolkit'),
        ]);
        wp_enqueue_script(
            self::HANDLE_AUTOSAVE_JS,
            LROB_ETK_URL . 'admin/js/etk-autosave.js',
            [],
            self::asset_version('admin/js/etk-autosave.js'),
            true
        );
        // Head-loaded: mid-body inline scripts may use window.lrobEtkConfirm.
        wp_enqueue_script(
            self::HANDLE_CONFIRM_JS,
            LROB_ETK_URL . 'admin/js/etk-confirm.js',
            [],
            self::asset_version('admin/js/etk-confirm.js'),
            false
        );

        wp_enqueue_script(
            self::HANDLE_PROMO_JS,
            LROB_ETK_URL . 'admin/js/etk-promo.js',
            [],
            self::asset_version('admin/js/etk-promo.js'),
            true
        );
        wp_localize_script(self::HANDLE_PROMO_JS, 'lrobEtkPromo', [
            'messages'  => PromoStrip::messages(),
            'authorUrl' => PromoStrip::AUTHOR_URL,
        ]);

        add_action('admin_footer', [self::class, 'print_tooltip_script']);
        // in_admin_footer (not admin_footer): inside #wpbody-content where .lrob-etk tokens apply.
        add_action('in_admin_footer', [PromoStrip::class, 'render']);
    }

    public static function print_tooltip_script(): void
    {
        ?>
        <script>
        (function () {
            function showTip(tipEl) {
                var text = tipEl.querySelector('.lrob-etk-tip-text');
                if (!text) return;
                // Make visible first to measure dimensions.
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

                // Prefer above; flip below if not enough room.
                var top = iconRect.top - textRect.height - margin;
                if (top < margin) {
                    top = iconRect.bottom + margin;

                    if (top + textRect.height > vh - margin) {
                        top = Math.max(margin, vh - textRect.height - margin);
                    }
                }

                // Center horizontally on icon, clamp to viewport.
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
            // Reposition open sticky tooltips on scroll/resize.
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

    /** Version = plugin version + filemtime so every edit busts the cache without a version bump. */
    private static function asset_version(string $relative_path): string
    {
        return self::asset_version_for($relative_path);
    }

    /** Public version for per-module enqueuers. */
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
