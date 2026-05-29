<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

use LRob\EmailToolkit\Modules\ModuleInterface;

/**
 * Single source for every plugin page header. See docs/admin-ui.md for layout + button shape.
 */
final class PageHeader
{
    /**
     * @param array{
     *     title: string,
     *     module?: ModuleInterface|null,
     *     primary?: array<string, mixed>|null,
     *     tools?: array<int, array<string, mixed>>,
     *     nav?: array<int, array<string, mixed>>,
     *     gate?: bool,
     * } $args
     */
    public static function render(array $args): void
    {
        $title  = (string) ($args['title'] ?? '');
        $module = $args['module'] ?? null;
        $primary = $args['primary'] ?? null;
        $tools   = isset($args['tools']) && is_array($args['tools']) ? $args['tools'] : [];
        $nav     = isset($args['nav']) && is_array($args['nav']) ? $args['nav'] : [];
        // When module is disabled, hide actions but keep title + toggle. gate=true overrides.
        $gate = array_key_exists('gate', $args) ? (bool) $args['gate'] : ($module === null || $module->is_enabled());

        ?>
        <header class="lrob-etk-page-header">
            <h1 class="lrob-etk-page-title">
                <?php echo esc_html($title); ?>
                <small class="lrob-etk-page-credit"><?php
                    /* translators: precedes the author name "LRob" in the page title credit */
                    esc_html_e('by', 'lrob-email-toolkit');
                ?> <a href="https://www.lrob.fr" target="_blank" rel="noopener noreferrer">LRob</a></small>
            </h1>
            <?php if ($module !== null) { ModuleToggle::render_inline($module); } ?>

            <?php if ($gate && is_array($primary)) : ?>
                <?php self::render_button($primary, true); ?>
            <?php endif; ?>

            <?php if ($gate && ($tools !== [] || $nav !== [])) : ?>
                <div class="lrob-etk-page-header-actions">
                    <?php if ($tools !== []) : ?>
                        <span class="lrob-etk-header-group lrob-etk-header-tools">
                            <?php foreach ($tools as $btn) { self::render_button((array) $btn, false); } ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($nav !== []) : ?>
                        <span class="lrob-etk-header-group lrob-etk-header-nav">
                            <?php foreach ($nav as $btn) { self::render_button((array) $btn, false, true); } ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </header>
        <?php
    }

    /**
     * @param array<string, mixed> $btn
     */
    private static function render_button(array $btn, bool $primary, bool $nav = false): void
    {
        $label = (string) ($btn['label'] ?? '');
        $icon  = isset($btn['icon']) ? (string) $btn['icon'] : '';
        $id    = isset($btn['id']) ? (string) $btn['id'] : '';
        $href  = isset($btn['href']) ? (string) $btn['href'] : '';
        $attrs = isset($btn['attrs']) && is_array($btn['attrs']) ? $btn['attrs'] : [];

        $classes = ['button'];
        if ($primary) {
            $classes[] = 'button-primary';
            $classes[] = 'lrob-etk-page-add';
        }
        if ($nav) {
            $classes[] = 'lrob-etk-nav-button';
        }
        $class_attr = implode(' ', $classes);

        $extra = '';
        foreach ($attrs as $k => $v) {
            if ($v === null) {
                $extra .= ' ' . esc_attr((string) $k);
            } else {
                $extra .= ' ' . esc_attr((string) $k) . '="' . esc_attr((string) $v) . '"';
            }
        }

        $icon_html = $icon !== '' ? '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>' : '';

        if ($href !== '') {
            printf(
                '<a href="%s" class="%s"%s>%s%s</a>',
                esc_url($href),
                esc_attr($class_attr),
                $extra,
                $icon_html, // phpcs:ignore WordPress.Security.EscapeOutput
                esc_html($label)
            );
        } else {
            $id_attr = $id !== '' ? ' id="' . esc_attr($id) . '"' : '';
            printf(
                '<button type="button"%s class="%s"%s>%s%s</button>',
                $id_attr, // phpcs:ignore WordPress.Security.EscapeOutput
                esc_attr($class_attr),
                $extra,
                $icon_html, // phpcs:ignore WordPress.Security.EscapeOutput
                esc_html($label)
            );
        }
    }
}
