<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\AutoUpdate;

/**
 * Self-hosted plugin updater — surfaces GitHub releases as WordPress updates.
 *
 * Two filters do the work:
 *   1. pre_set_site_transient_update_plugins — when WP decides which plugins
 *      need updating, we hit the GitHub API, compare versions, and inject our
 *      entry if a newer release is published.
 *   2. plugins_api — the "View details" modal on the Plugins / Updates screens
 *      pulls release info from GitHub (changelog from the release body,
 *      formatted via a minimal Markdown → HTML conversion).
 *
 * The GitHub API response is cached in a 1-hour transient on success
 * (vs the 12h the calendar plugin uses — this plugin is more actively
 * developed and the user wants fresher data). On the Updates page itself,
 * the cache is bypassed entirely: the admin is there *because* they want
 * to know if there are updates, so we ask GitHub directly.
 *
 * GitHub's unauthenticated rate limit is 60 req/h per IP. Even with the
 * Updates page bypassing the cache, normal admin workflows stay well under
 * that — and the cache absorbs the WP cron / background-poll traffic.
 *
 * No external library. Mirrors `wp-lrob-calendar` (the original).
 */
final class Updater
{
    public const TRANSIENT_KEY      = 'lrob_etk_gh_release';
    public const TRANSIENT_TTL      = HOUR_IN_SECONDS;       // success cache
    public const TRANSIENT_TTL_FAIL = HOUR_IN_SECONDS;       // failure cache (don't hammer a flaky API)
    public const PLUGIN_SLUG        = 'lrob-email-toolkit';

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api',                           [$this, 'plugin_info'], 10, 3);
    }

    /**
     * Inject our update notice into the transient WordPress uses to decide
     * which plugins have updates available. Runs via wp-cron (~12h default)
     * and on any admin page load if WP's own transient has expired.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function check_for_update($transient)
    {
        if (empty($transient) || !is_object($transient)) {
            return $transient;
        }

        $release = $this->get_release();
        if ($release === null) {
            return $transient;
        }

        $remote_version = $this->normalize_version((string) ($release['tag_name'] ?? ''));
        if ($remote_version === '') {
            return $transient;
        }
        if (version_compare(LROB_ETK_VERSION, $remote_version, '>=')) {
            return $transient;
        }

        $zip_url = $this->find_asset_url($release);
        if ($zip_url === null) {
            // Release published but no zip asset attached — skip rather than
            // pointing WP at the GitHub-generated source tarball (commit-hash
            // folder name → installs side-by-side, doesn't replace).
            return $transient;
        }

        $update = (object) [
            'slug'         => self::PLUGIN_SLUG,
            'plugin'       => LROB_ETK_BASENAME,
            'new_version'  => $remote_version,
            'url'          => LROB_ETK_GITHUB_URL,
            'package'      => $zip_url,
            'tested'       => $this->tested_wp_version(),
            'requires_php' => '8.1',
            'icons'        => [],
            'banners'      => [],
        ];

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        $transient->response[LROB_ETK_BASENAME] = $update;
        return $transient;
    }

    /**
     * Fill the "View version details" modal on the Plugins / Updates screens.
     * Returns the unchanged $result for any request that isn't asking about
     * THIS plugin.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object|array
     */
    public function plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!isset($args->slug) || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }

        $release = $this->get_release();
        if ($release === null) {
            return $result;
        }

        $remote_version = $this->normalize_version((string) ($release['tag_name'] ?? ''));
        $zip_url        = $this->find_asset_url($release);

        return (object) [
            'name'          => 'LRob - Email Toolkit',
            'slug'          => self::PLUGIN_SLUG,
            'version'       => $remote_version,
            'author'        => '<a href="https://www.lrob.fr">LRob</a>',
            'homepage'      => defined('LROB_ETK_PLUGIN_URL') ? LROB_ETK_PLUGIN_URL : LROB_ETK_GITHUB_URL,
            'requires'      => '6.0',
            'requires_php'  => '8.1',
            'tested'        => $this->tested_wp_version(),
            'last_updated'  => (string) ($release['published_at'] ?? ''),
            'download_link' => (string) $zip_url,
            'sections'      => [
                'description' => __('Modular all-in-one email plugin: SMTP routing, email logging, contact forms, captcha, newsletters, and webhook integrations. Each module is independently activatable.', 'lrob-email-toolkit'),
                'changelog'   => $this->markdown_to_html((string) ($release['body'] ?? '')),
            ],
        ];
    }

    /** Force-clear the cached release info. Exposed for activation hooks / a future "check now" button. */
    public static function flush_cache(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /* ─── Internals ──────────────────────────────────────────────────── */

    /**
     * Hit the GitHub API for the latest release. Cached on success +
     * failure (both 1h). When the admin is on the Updates page (or
     * clicked "Check again" with $_GET['force-check']), the cache is
     * bypassed: that's the explicit "I want fresh data" signal.
     *
     * @return array<string, mixed>|null
     */
    private function get_release(): ?array
    {
        $force = $this->is_force_refresh();
        if (!$force) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if ($cached === 'none') {
                return null;
            }
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        $api_url = 'https://api.github.com/repos/' . $this->github_repo() . '/releases/latest';
        $response = wp_remote_get($api_url, [
            'timeout' => 8,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::TRANSIENT_KEY, 'none', self::TRANSIENT_TTL_FAIL);
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            set_transient(self::TRANSIENT_KEY, 'none', self::TRANSIENT_TTL_FAIL);
            return null;
        }

        set_transient(self::TRANSIENT_KEY, $body, self::TRANSIENT_TTL);
        return $body;
    }

    /**
     * Two intent signals tell us to bypass the cache:
     *  - $_GET['force-check'] = '1' — set by core when admin clicks "Check
     *    again" on the Updates page (wp_update_plugins reads the same flag).
     *  - The current screen IS the Updates page (update-core.php). Just
     *    landing there means the admin is looking for updates *right now*;
     *    they shouldn't have to click an extra button to bypass our cache.
     */
    private function is_force_refresh(): bool
    {
        if (!is_admin()) {
            return false;
        }
        if (isset($_GET['force-check']) && (string) $_GET['force-check'] === '1') {
            return true;
        }
        $pagenow = $GLOBALS['pagenow'] ?? '';
        if ($pagenow === 'update-core.php') {
            return true;
        }
        return false;
    }

    private function github_repo(): string
    {
        $url = defined('LROB_ETK_GITHUB_URL') ? LROB_ETK_GITHUB_URL : '';
        if (preg_match('#github\.com/([^/]+/[^/]+?)/?$#', $url, $m)) {
            return $m[1];
        }
        return 'LRob-FR/wp-lrob-email-toolkit';
    }

    private function normalize_version(string $tag): string
    {
        return ltrim($tag, 'vV');
    }

    /**
     * Find the plugin zip on the release. release.sh produces
     * `lrob-email-toolkit-<version>.zip`; admin uploads it as a release
     * asset with `gh release create`. We match by filename prefix + .zip
     * suffix so the embedded version doesn't have to be hardcoded here.
     *
     * @param array<string, mixed> $release
     */
    private function find_asset_url(array $release): ?string
    {
        $assets = $release['assets'] ?? [];
        if (!is_array($assets)) {
            return null;
        }
        foreach ($assets as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $url  = (string) ($asset['browser_download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            if (str_starts_with($name, self::PLUGIN_SLUG . '-') && str_ends_with($name, '.zip')) {
                return $url;
            }
        }
        return null;
    }

    private function tested_wp_version(): string
    {
        // Bumping by hand each WP release is busywork; reporting the running
        // version sidesteps the "tested up to" warning without lying.
        return get_bloginfo('version');
    }

    /**
     * Minimal Markdown → HTML for the changelog modal. Covers what GitHub
     * release notes typically use: headings, bullets, bold, code spans,
     * links, paragraphs. Not a real parser — anything fancier renders as
     * escaped text, which is safe.
     */
    private function markdown_to_html(string $md): string
    {
        $md = trim($md);
        if ($md === '') {
            return '';
        }

        // Escape everything first; selectively re-introduce the markup below.
        $html = esc_html($md);

        // Headings (h2/h3 in source → h3/h4 in modal; h2 is too big for it).
        $html = (string) preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $html);
        $html = (string) preg_replace('/^## (.+)$/m',  '<h3>$1</h3>', $html);
        // Bold
        $html = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
        // Inline code
        $html = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
        // Links — [text](url)
        $html = (string) preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            static fn (array $m): string => '<a href="' . esc_url($m[2]) . '" target="_blank" rel="noopener">' . $m[1] . '</a>',
            $html
        );
        // Bullet lists — consecutive "- foo" lines wrapped in <ul><li>…</li></ul>.
        $html = (string) preg_replace_callback(
            '/(?:^- .+(?:\n|$))+/m',
            static function (array $m): string {
                $items = (string) preg_replace('/^- (.+)$/m', '<li>$1</li>', trim($m[0]));
                return '<ul>' . $items . '</ul>';
            },
            $html
        );
        // Paragraph splitting on blank lines, skipping already-blockified content.
        $blocks = preg_split('/\n{2,}/', $html) ?: [];
        $blocks = array_map(static function (string $b): string {
            $b = trim($b);
            if ($b === '') {
                return '';
            }
            if (preg_match('/^<(h[1-6]|ul|ol|p|pre|blockquote)\b/i', $b)) {
                return $b;
            }
            return '<p>' . str_replace("\n", '<br>', $b) . '</p>';
        }, $blocks);
        return implode("\n", $blocks);
    }
}
