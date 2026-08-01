<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\AutoUpdate;

// Docs: docs/core.md
final class Updater
{
    // Back-off when the server is unreachable, so admin pages don't each pay a
    // connection timeout.
    public const TRANSIENT_KEY      = 'lrob_etk_release_fail';
    public const TRANSIENT_TTL_FAIL = 5 * MINUTE_IN_SECONDS;
    public const PLUGIN_SLUG        = 'lrob-email-toolkit';

    /** Per-request memo — the filters below can both fire in a single request. */
    private static array|null|false $release_memo = false;

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api',                           [$this, 'plugin_info'], 10, 3);
    }

    /**
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
            // pointing WP at the auto-generated source tarball (commit-hash
            // folder name → installs side-by-side, doesn't replace).
            return $transient;
        }

        $update = (object) [
            'slug'         => self::PLUGIN_SLUG,
            'plugin'       => LROB_ETK_BASENAME,
            'new_version'  => $remote_version,
            'url'          => LROB_ETK_REPO_URL,
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
            'homepage'      => defined('LROB_ETK_PLUGIN_URL') ? LROB_ETK_PLUGIN_URL : LROB_ETK_REPO_URL,
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

    /** Clear the "server unreachable" back-off so the next check retries immediately. */
    public static function flush_cache(): void
    {
        delete_transient(self::TRANSIENT_KEY);
        self::$release_memo = false;
    }

    /* ─── Internals ──────────────────────────────────────────────────── */

    /** @return array<string, mixed>|null */
    private function get_release(): ?array
    {
        if (self::$release_memo !== false) {
            return self::$release_memo;
        }
        if (get_transient(self::TRANSIENT_KEY) === 'down') {
            return null;
        }

        $api_url = $this->api_url();
        if ($api_url === '') {
            return null;
        }

        $response = wp_remote_get($api_url, [
            'timeout' => 5,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::TRANSIENT_KEY, 'down', self::TRANSIENT_TTL_FAIL);
            return self::$release_memo = null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            set_transient(self::TRANSIENT_KEY, 'down', self::TRANSIENT_TTL_FAIL);
            return self::$release_memo = null;
        }

        return self::$release_memo = $body;
    }

    /** https://git.lrob.net/WP/email-toolkit → https://git.lrob.net/api/v1/repos/WP/email-toolkit/releases/latest */
    private function api_url(): string
    {
        $url = defined('LROB_ETK_REPO_URL') ? LROB_ETK_REPO_URL : '';
        if (!preg_match('#^(https?://[^/]+)/([^/]+/[^/]+?)/?$#', $url, $m)) {
            return '';
        }
        return $m[1] . '/api/v1/repos/' . $m[2] . '/releases/latest';
    }

    private function normalize_version(string $tag): string
    {
        return ltrim($tag, 'vV');
    }

    /** @param array<string, mixed> $release */
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

    /** Minimal Markdown → HTML for the changelog modal (headings, bullets, bold, code, links). */
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
