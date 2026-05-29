<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

/**
 * Inline per-page picker. Resolution: POST > GET > session cookie > default.
 * Runtime: admin/js/etk-perpage.js. See docs/admin-ui.md.
 */
final class PerPagePicker
{
    /** @var array<int, int> Allowed values surfaced in the dropdown. */
    public const ALLOWED = [10, 25, 50, 100, 200];

    public const COOKIE_PREFIX = 'lrob_etk_per_page_';

    /**
     * @param array<string, mixed>|null $source `$_POST`/`$_GET` overrides — defaults
     *        to $_POST merged onto $_GET (POST wins).
     */
    public static function parse(string $slug, int $default, ?array $source = null): int
    {
        $src = $source ?? array_merge($_GET, $_POST);
        $raw = 0;
        if (isset($src['per_page'])) {
            $raw = (int) $src['per_page'];
        }
        if ($raw <= 0) {
            $cookie_name = self::COOKIE_PREFIX . $slug;
            if (isset($_COOKIE[$cookie_name])) {
                $raw = (int) $_COOKIE[$cookie_name];
            }
        }
        if ($raw <= 0) {
            $raw = $default;
        }
        return max(5, min(500, $raw));
    }

    public static function render(string $slug, int $current): void
    {
        ?>
        <label class="lrob-etk-bulk-count">
            <?php esc_html_e('Per page', 'lrob-email-toolkit'); ?>
            <select class="lrob-etk-select" data-per-page="<?php echo esc_attr($slug); ?>">
                <?php foreach (self::ALLOWED as $n) : ?>
                    <option value="<?php echo $n; ?>" <?php selected($current, $n); ?>><?php echo $n; ?></option>
                <?php endforeach; ?>
                <?php if (!in_array($current, self::ALLOWED, true)) : ?>
                    <option value="<?php echo $current; ?>" selected><?php echo (int) $current; ?></option>
                <?php endif; ?>
            </select>
        </label>
        <?php
    }
}
