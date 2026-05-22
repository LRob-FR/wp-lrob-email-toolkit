<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Newsletter\Admin\PageController;

/**
 * Registers the `lrob_etk_nl_campaign` post type — newsletter campaigns
 * composed in Gutenberg with the same constrained block subset as the
 * system email templates. Each campaign post has a companion row in
 * `wp_lrob_etk_nl_campaigns` (keyed by post_id) holding hot runtime
 * state (status, counters, started_at, …); the row is created on first
 * save via CampaignRepository::ensure_row() and removed in
 * before_delete_post.
 *
 *   - lrob_etk_nl_campaign: 19 chars, fits WP's 20-char CPT slug limit.
 *   - non-public, show_in_menu=false (managed through the Newsletter
 *     hub's Campaigns view, not the WP sidebar).
 *   - show_in_rest=true so Gutenberg can edit it.
 *   - capability_type='post' mapped to the toolkit's manage_lrob_etk
 *     primitive via plural caps only — avoids the singular-meta-cap
 *     collision documented in project_wp_cpt_cap_collision.md
 *     (map_meta_cap stays true; we only declare plural caps in the
 *     capabilities array).
 *
 * Sending lives in step 7 — this slice ships the composer + admin
 * management surface only. Until the send pipeline lands, campaigns
 * stay in `draft` or `scheduled` status forever; the Send button in
 * the meta box is wired to a stub that surfaces "send pipeline not
 * implemented yet".
 */
final class CampaignCPT
{
    public const POST_TYPE = 'lrob_etk_nl_campaign';

    public const META_PREVIEW_TEXT      = '_lrob_etk_nl_preview_text';

    public const META_FROM_NAME_OVERRIDE = '_lrob_etk_nl_from_name_override';

    public const META_REPLY_TO_OVERRIDE  = '_lrob_etk_nl_reply_to_override';

    public const META_SMTP_IDENTITY      = '_lrob_etk_nl_smtp_identity_id';

    public const META_CATEGORY_ID        = '_lrob_etk_nl_category_id';

    /** JSON shape: `{kind: 'list'|'all_users'|'all_subscribers'|'all', list_id?: int}` */
    public const META_TARGET_SPEC        = '_lrob_etk_nl_target_spec';

    public const META_SCHEDULED_AT       = '_lrob_etk_nl_scheduled_at';

    public const META_TRACK_OPENS        = '_lrob_etk_nl_track_opens';

    public const META_TRACK_CLICKS       = '_lrob_etk_nl_track_clicks';

    public const META_LOG_ALL_SENDS      = '_lrob_etk_nl_log_all_sends';

    public const TARGET_KIND_ALL              = 'all';

    public const TARGET_KIND_ALL_USERS        = 'all_users';

    public const TARGET_KIND_ALL_SUBSCRIBERS  = 'all_subscribers';

    public const TARGET_KIND_LIST             = 'list';

    /**
     * Same email-safe block subset as TemplateCPT. The CSS inliner (step
     * 7 onwards) relies on this restricted vocabulary; campaigns and
     * templates share the inliner so they must agree on what blocks can
     * appear.
     */
    private const ALLOWED_BLOCKS = [
        'core/paragraph',
        'core/heading',
        'core/image',
        'core/button',
        'core/buttons',
        'core/separator',
        'core/spacer',
        'core/columns',
        'core/column',
        'core/list',
        'core/list-item',
        'core/quote',
        'core/html',
    ];

    public function register(): void
    {
        add_action('init', [$this, 'register_post_type'], 6);
        add_action('init', [$this, 'register_meta'], 7);
        add_filter('allowed_block_types_all', [$this, 'filter_allowed_blocks'], 10, 2);
        add_action('admin_init', [$this, 'redirect_post_list']);
    }

    public function register_post_type(): void
    {
        $labels = [
            'name'               => __('Campaigns', 'lrob-email-toolkit'),
            'singular_name'      => __('Campaign', 'lrob-email-toolkit'),
            'add_new'            => __('Add new', 'lrob-email-toolkit'),
            'add_new_item'       => __('Add new campaign', 'lrob-email-toolkit'),
            'edit_item'          => __('Edit campaign', 'lrob-email-toolkit'),
            'new_item'           => __('New campaign', 'lrob-email-toolkit'),
            'view_item'          => __('View campaign', 'lrob-email-toolkit'),
            'search_items'       => __('Search campaigns', 'lrob-email-toolkit'),
            'not_found'          => __('No campaigns yet.', 'lrob-email-toolkit'),
            'not_found_in_trash' => __('No campaigns in trash.', 'lrob-email-toolkit'),
            'all_items'          => __('Campaigns', 'lrob-email-toolkit'),
            'menu_name'          => __('Campaigns', 'lrob-email-toolkit'),
        ];

        register_post_type(self::POST_TYPE, [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'show_in_admin_bar'   => false,
            'show_in_nav_menus'   => false,
            'show_in_rest'        => true,
            'rest_base'           => 'lrob-etk-nl-campaigns',
            'has_archive'         => false,
            'hierarchical'        => false,
            // Internal-only post type: no rewrite rules, no public query
            // var. See TemplateCPT for the same rationale.
            'rewrite'             => false,
            'query_var'           => false,
            'can_export'          => true,
            'supports'            => ['title', 'editor', 'revisions', 'custom-fields'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'capabilities'        => [
                // PLURAL primitives only — see TemplateCPT for the cap-
                // collision rationale (project_wp_cpt_cap_collision).
                'edit_posts'             => Activator::CAPABILITY,
                'edit_others_posts'      => Activator::CAPABILITY,
                'edit_private_posts'     => Activator::CAPABILITY,
                'edit_published_posts'   => Activator::CAPABILITY,
                'publish_posts'          => Activator::CAPABILITY,
                'read_private_posts'     => Activator::CAPABILITY,
                'delete_posts'           => Activator::CAPABILITY,
                'delete_private_posts'   => Activator::CAPABILITY,
                'delete_published_posts' => Activator::CAPABILITY,
                'delete_others_posts'    => Activator::CAPABILITY,
                'create_posts'           => Activator::CAPABILITY,
            ],
            'menu_position'       => null,
        ]);
    }

    public function register_meta(): void
    {
        $auth_callback = static fn (): bool => current_user_can(Activator::CAPABILITY);

        register_post_meta(self::POST_TYPE, self::META_PREVIEW_TEXT, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_FROM_NAME_OVERRIDE, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_REPLY_TO_OVERRIDE, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => 'sanitize_email',
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_SMTP_IDENTITY, [
            'type'              => 'integer',
            'single'            => true,
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_CATEGORY_ID, [
            'type'              => 'integer',
            'single'            => true,
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_TARGET_SPEC, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => static function ($value) {
                // JSON-in-text-field. Sanitise by re-decoding + re-
                // encoding so invalid JSON gets blanked.
                $raw = is_string($value) ? $value : '';
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    return '';
                }
                $kind = isset($decoded['kind']) ? sanitize_key((string) $decoded['kind']) : '';
                if (!in_array($kind, [
                    CampaignCPT::TARGET_KIND_ALL,
                    CampaignCPT::TARGET_KIND_ALL_USERS,
                    CampaignCPT::TARGET_KIND_ALL_SUBSCRIBERS,
                    CampaignCPT::TARGET_KIND_LIST,
                ], true)) {
                    return '';
                }
                $out = ['kind' => $kind];
                if ($kind === CampaignCPT::TARGET_KIND_LIST) {
                    $out['list_id'] = isset($decoded['list_id']) ? (int) $decoded['list_id'] : 0;
                }
                return (string) wp_json_encode($out);
            },
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_SCHEDULED_AT, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            // Stored as MySQL DATETIME in UTC. Empty string = "send
            // now" once the send pipeline is wired up; until then it's
            // just a recorded preference.
            'sanitize_callback' => static function ($value) {
                $raw = is_string($value) ? trim($value) : '';
                if ($raw === '') {
                    return '';
                }
                $ts = strtotime($raw);
                return $ts === false ? '' : gmdate('Y-m-d H:i:s', $ts);
            },
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_TRACK_OPENS, [
            'type'              => 'boolean',
            'single'            => true,
            'default'           => true,
            'sanitize_callback' => static fn ($v) => !empty($v),
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_TRACK_CLICKS, [
            'type'              => 'boolean',
            'single'            => true,
            'default'           => true,
            'sanitize_callback' => static fn ($v) => !empty($v),
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_LOG_ALL_SENDS, [
            'type'              => 'boolean',
            'single'            => true,
            'default'           => false,
            'sanitize_callback' => static fn ($v) => !empty($v),
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
    }

    /**
     * Constrain the block inserter to the email-safe subset when
     * editing a campaign. Same approach as TemplateCPT.
     *
     * @param array<int, string>|bool $allowed
     */
    public function filter_allowed_blocks(array|bool $allowed, \WP_Block_Editor_Context $context): array|bool
    {
        $post = $context->post ?? null;
        if (!$post instanceof \WP_Post || $post->post_type !== self::POST_TYPE) {
            return $allowed;
        }
        return self::ALLOWED_BLOCKS;
    }

    /**
     * Gutenberg's back-to-list / save-and-exit lands on
     * edit.php?post_type=<cpt>. Our CPT has show_in_menu=false so
     * that bare list has no sidebar context. Bounce to the Campaigns
     * view of the Newsletter hub instead.
     */
    public function redirect_post_list(): void
    {
        global $pagenow;
        if ($pagenow !== 'edit.php') {
            return;
        }
        $post_type = isset($_GET['post_type']) && is_string($_GET['post_type'])
            ? sanitize_key((string) $_GET['post_type'])
            : '';
        if ($post_type !== self::POST_TYPE) {
            return;
        }
        wp_safe_redirect(admin_url(
            'admin.php?page=' . PageController::SLUG . '&view=' . \LRob\EmailToolkit\Modules\Newsletter\Admin\HomePage::VIEW_CAMPAIGNS
        ));
        exit;
    }
}
