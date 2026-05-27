<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Public-page Gutenberg block + shortcode that embeds the preferences
 * UI inside any post/page. Complements PrefsHandler's token-URL flow
 * (the one linked from every confirmation/reminder email) by giving
 * the site a stable, linkable surface — e.g. a `/newsletter-preferences/`
 * page reachable from the menu or footer.
 *
 * Three rendering paths, picked at render time:
 *
 *  - **Logged-in WP user** → renders the full prefs form pre-filled
 *    with their stored prefs. Form action targets the user's existing
 *    prefs token URL; PrefsHandler intercepts the POST, saves, and
 *    redirects back to the page (via `_lrob_etk_nl_return_to`) with
 *    a `?lrob_etk_nl_saved=1` flash. If the WP user has no prefs_token
 *    yet (legacy account, never subscribed via a form), one is minted
 *    lazily on first render.
 *
 *  - **Anonymous visitor with `?lrob-etk-nl-prefs=<token>` in the URL**
 *    → this case never reaches the block: PrefsHandler intercepts the
 *    token at init priority 10 and renders its own standalone page
 *    before the block render fires.
 *
 *  - **Anonymous visitor without a token** → renders a small message
 *    pointing them at the link in their emails (or to log in, if WP
 *    accounts are open). No magic-link request form — keeping scope
 *    tight; PrefsHandler's token URLs are the canonical entry point.
 *
 * Render is server-side (no JSX/React build); the editor JS only
 * registers the block's metadata + a static preview.
 */
final class PrefsBlock
{
    public const BLOCK_NAME = 'lrob-etk/newsletter-preferences';

    public const SHORTCODE = 'lrob_etk_nl_preferences';

    private const NONCE_ACTION = 'lrob_etk_nl_prefs_save';

    public function __construct(
        private SubscriberRepository $subscribers,
        private ListRepository $lists,
    ) {
    }

    public function register(): void
    {
        add_action('init', [$this, 'register_block'], 20);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
    }

    public function register_block(): void
    {
        register_block_type(self::BLOCK_NAME, [
            'render_callback' => [$this, 'render'],
            'supports'        => ['html' => false, 'inserter' => true, 'align' => ['wide']],
        ]);
    }

    public function enqueue_editor_assets(): void
    {
        $deps_js = ['wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor'];

        wp_enqueue_script(
            'lrob-etk-nl-prefs-block-editor',
            LROB_ETK_URL . 'admin/js/newsletter-prefs-block-editor.js',
            $deps_js,
            self::asset_version('admin/js/newsletter-prefs-block-editor.js'),
            true
        );
        wp_set_script_translations('lrob-etk-nl-prefs-block-editor', 'lrob-email-toolkit');
    }

    /**
     * Block render callback. Receives Gutenberg attributes (none used
     * today — the block has no configurable attributes; it always shows
     * the prefs for whoever is viewing).
     *
     * @param array<string, mixed> $atts
     */
    public function render(array $atts = [], string $content = ''): string
    {
        unset($atts, $content);
        return $this->render_html();
    }

    /**
     * Shortcode wrapper for Classic Editor / page builders.
     *
     * @param array<string, mixed>|string $atts
     */
    public function render_shortcode($atts = []): string
    {
        unset($atts);
        return $this->render_html();
    }

    private function render_html(): string
    {
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            return $this->render_for_wp_user($user_id);
        }
        return $this->render_anonymous();
    }

    private function render_for_wp_user(int $user_id): string
    {
        // Lazy-mint a prefs token if the user doesn't have one. New
        // users get tokens via UserHooks::on_user_register; legacy users
        // who existed before the module was enabled won't.
        $token = (string) get_user_meta($user_id, UserMeta::PREFS_TOKEN, true);
        if ($token === '') {
            $token = UserMeta::generate_prefs_token();
            update_user_meta($user_id, UserMeta::PREFS_TOKEN, $token);
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return '';
        }

        $state = $this->build_state_for_user($user_id, (string) $user->user_email);
        $action_url = add_query_arg(PrefsHandler::QUERY_PREFS, $token, home_url('/'));
        $return_to = self::current_url();
        $saved = isset($_GET['lrob_etk_nl_saved']) && (string) $_GET['lrob_etk_nl_saved'] === '1';

        ob_start();
        ?>
        <div class="lrob-etk-nl-prefs-block">
            <?php if ($saved) : ?>
                <p class="lrob-etk-nl-prefs-block-flash" role="status">
                    <?php esc_html_e('Your preferences have been saved.', 'lrob-email-toolkit'); ?>
                </p>
            <?php endif; ?>
            <?php echo PrefsRenderer::render_full_form($state, $action_url, self::NONCE_ACTION, $return_to); ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_anonymous(): string
    {
        ob_start();
        ?>
        <div class="lrob-etk-nl-prefs-block lrob-etk-nl-prefs-block-anon">
            <p>
                <?php esc_html_e('To manage your email preferences, use the link at the bottom of any of our emails.', 'lrob-email-toolkit'); ?>
            </p>
            <?php if (get_option('users_can_register')) : ?>
                <p>
                    <?php
                    printf(
                        /* translators: %s: link to the login/registration page. */
                        esc_html__('You can also %s to manage them here.', 'lrob-email-toolkit'),
                        '<a href="' . esc_url(wp_login_url(self::current_url())) . '">'
                            . esc_html__('log in or create an account', 'lrob-email-toolkit')
                            . '</a>'
                    );
                    ?>
                </p>
            <?php else : ?>
                <p>
                    <?php
                    printf(
                        /* translators: %s: link to the login page. */
                        esc_html__('Already have an account? %s to manage them here.', 'lrob-email-toolkit'),
                        '<a href="' . esc_url(wp_login_url(self::current_url())) . '">'
                            . esc_html__('Log in', 'lrob-email-toolkit')
                            . '</a>'
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Build the renderer state for a WP user. Mirrors PrefsHandler's
     * build_state for the KIND_USER branch — pulling user_meta directly
     * since we already have $user_id.
     *
     * @return array<string, mixed>
     */
    private function build_state_for_user(int $user_id, string $email): array
    {
        $opted_in = (string) get_user_meta($user_id, UserMeta::OPTED_IN, true) === '1';
        $lists = $this->lists->list_public_for_subscribers();
        $member_ids = $this->lists->memberships_for_recipient(UserMeta::KIND_USER, $user_id);

        return [
            'kind'            => UserMeta::KIND_USER,
            'id'              => $user_id,
            'email'           => $email,
            'opted_in'        => $opted_in,
            'list_member_ids' => $member_ids,
            'lists'           => array_map(
                static fn (array $l) => [
                    'id'          => (int) ($l['id'] ?? 0),
                    'name'        => (string) ($l['name'] ?? ''),
                    'description' => (string) ($l['description'] ?? ''),
                ],
                $lists
            ),
        ];
    }

    /**
     * Current page URL, used as the `_lrob_etk_nl_return_to` value so
     * PrefsHandler bounces back to the block's page after save. Strips
     * the `lrob_etk_nl_saved` flash so a refresh-and-resubmit cycle
     * doesn't sticky-flash forever.
     */
    private static function current_url(): string
    {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $path = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($host === '') {
            return home_url('/');
        }
        $full = $scheme . '://' . $host . $path;
        return remove_query_arg('lrob_etk_nl_saved', $full);
    }

    private static function asset_version(string $relative): string
    {
        $version = LROB_ETK_VERSION;
        $full = LROB_ETK_PATH . ltrim($relative, '/');
        if (is_file($full)) {
            $version .= '.' . filemtime($full);
        }
        return $version;
    }
}
