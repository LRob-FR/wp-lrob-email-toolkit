<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Newsletter\Admin\PageController;

/**
 * Registers the `lrob_etk_newsletter` post type — newsletter posts
 * composed in Gutenberg with the same constrained block subset as the
 * system email templates. Each newsletter post has a companion row in
 * `wp_lrob_etk_nl_newsletters` (keyed by post_id) holding hot runtime
 * state (status, counters, started_at, …); the row is created on first
 * save via NewsletterRepository::ensure_row() and removed in
 * before_delete_post.
 *
 *   - lrob_etk_newsletter: 19 chars, fits WP's 20-char CPT slug limit.
 *   - non-public, show_in_menu=false (managed through the Newsletter
 *     hub's Newsletters view + per-newsletter cards, not the WP sidebar).
 *   - show_in_rest=true so Gutenberg can edit it.
 *   - capability_type='post' mapped to the toolkit's manage_lrob_etk
 *     primitive via plural caps only — avoids the singular-meta-cap
 *     collision documented in project_wp_cpt_cap_collision.md
 *     (map_meta_cap stays true; we only declare plural caps in the
 *     capabilities array).
 *
 * Step 7 ships the send pipeline; the newsletter cards refactor moved
 * settings + send actions out of metaboxes into NewslettersPage cards,
 * so this CPT registration is now plumbing only — Gutenberg edits the
 * post content, everything else lives on the card.
 */
final class NewsletterCPT
{
    public const POST_TYPE = 'lrob_etk_newsletter';

    public const META_PREVIEW_TEXT      = '_lrob_etk_nl_preview_text';

    public const META_FROM_NAME_OVERRIDE = '_lrob_etk_nl_from_name_override';

    public const META_REPLY_TO_OVERRIDE  = '_lrob_etk_nl_reply_to_override';

    public const META_SMTP_IDENTITY      = '_lrob_etk_nl_smtp_identity_id';

    public const META_CATEGORY_ID        = '_lrob_etk_nl_category_id';

    /** JSON shape: `{kind: 'lists'|'list'|'all_users'|'all_subscribers'|'all', list_id?: int, list_ids?: int[]}` */
    public const META_TARGET_SPEC        = '_lrob_etk_nl_target_spec';

    public const META_SCHEDULED_AT       = '_lrob_etk_nl_scheduled_at';

    public const META_TRACK_OPENS        = '_lrob_etk_nl_track_opens';

    public const META_TRACK_CLICKS       = '_lrob_etk_nl_track_clicks';

    public const META_LOG_ALL_SENDS      = '_lrob_etk_nl_log_all_sends';

    /**
     * Per-newsletter override of recipient opt-outs. When true, the
     * Materializer ignores the OPTED_IN=0 user_meta filter + the
     * 'unsubscribed' subscriber status when resolving recipients.
     * Critical: only for operational/legal communications. The send
     * confirm modal surfaces a count of opted-outs the send will
     * reach when this is on.
     */
    public const META_IGNORE_OPTOUTS     = '_lrob_etk_nl_ignore_optouts';

    /**
     * Per-newsletter manual force-include list. JSON `[{kind, id},…]`
     * where each entry references a (recipient_kind, recipient_id)
     * the Materializer must include regardless of opt-out / status.
     * Independent from META_IGNORE_OPTOUTS — the global toggle
     * applies to the whole audience, this targets individuals.
     */
    public const META_FORCE_INCLUDE_IDS  = '_lrob_etk_nl_force_include_ids';

    /**
     * Per-newsletter manual force-exclude list. JSON `[{kind, id},…]`
     * — the Materializer drops these recipients regardless of their
     * opt-in state or list membership. Useful for excluding a known
     * problematic address or someone who asked verbally to be left
     * out of one specific send.
     */
    public const META_FORCE_EXCLUDE_IDS  = '_lrob_etk_nl_force_exclude_ids';

    public const TARGET_KIND_ALL              = 'all';

    public const TARGET_KIND_ALL_USERS        = 'all_users';

    public const TARGET_KIND_ALL_SUBSCRIBERS  = 'all_subscribers';

    public const TARGET_KIND_LIST             = 'list';

    /** Multi-list union: target = union of every list_id in list_ids[]. */
    public const TARGET_KIND_LISTS            = 'lists';

    /**
     * Same email-safe block subset as TemplateCPT. The CSS inliner (step
     * 7b polish) relies on this restricted vocabulary; newsletters and
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
            'name'               => __('Newsletters', 'lrob-email-toolkit'),
            'singular_name'      => __('Newsletter', 'lrob-email-toolkit'),
            'add_new'            => __('Add new', 'lrob-email-toolkit'),
            'add_new_item'       => __('Add new newsletter', 'lrob-email-toolkit'),
            'edit_item'          => __('Edit newsletter', 'lrob-email-toolkit'),
            'new_item'           => __('New newsletter', 'lrob-email-toolkit'),
            'view_item'          => __('View newsletter', 'lrob-email-toolkit'),
            'search_items'       => __('Search newsletters', 'lrob-email-toolkit'),
            'not_found'          => __('No newsletters yet.', 'lrob-email-toolkit'),
            'not_found_in_trash' => __('No newsletters in trash.', 'lrob-email-toolkit'),
            'all_items'          => __('Newsletters', 'lrob-email-toolkit'),
            'menu_name'          => __('Newsletters', 'lrob-email-toolkit'),
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
            'rest_base'           => 'lrob-etk-nl-newsletters',
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
                    NewsletterCPT::TARGET_KIND_ALL,
                    NewsletterCPT::TARGET_KIND_ALL_USERS,
                    NewsletterCPT::TARGET_KIND_ALL_SUBSCRIBERS,
                    NewsletterCPT::TARGET_KIND_LIST,
                    NewsletterCPT::TARGET_KIND_LISTS,
                ], true)) {
                    return '';
                }
                $out = ['kind' => $kind];
                if ($kind === NewsletterCPT::TARGET_KIND_LIST) {
                    $out['list_id'] = isset($decoded['list_id']) ? (int) $decoded['list_id'] : 0;
                } elseif ($kind === NewsletterCPT::TARGET_KIND_LISTS) {
                    $ids = isset($decoded['list_ids']) && is_array($decoded['list_ids'])
                        ? array_values(array_unique(array_filter(array_map('intval', $decoded['list_ids']), static fn ($n) => $n > 0)))
                        : [];
                    $out['list_ids'] = $ids;
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
        register_post_meta(self::POST_TYPE, self::META_IGNORE_OPTOUTS, [
            'type'              => 'boolean',
            'single'            => true,
            'default'           => false,
            'sanitize_callback' => static fn ($v) => !empty($v),
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_FORCE_INCLUDE_IDS, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => [self::class, 'sanitize_recipient_id_set'],
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_FORCE_EXCLUDE_IDS, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => [self::class, 'sanitize_recipient_id_set'],
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
    }

    /**
     * Validates a `[{kind:'user'|'subscriber', id:int}, …]` JSON
     * blob — drops malformed entries, normalises kind, casts id to
     * int. Returns the canonical JSON or '' when nothing survives.
     */
    public static function sanitize_recipient_id_set(mixed $value): string
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }
        $out = [];
        $seen = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $kind = isset($entry['kind']) ? sanitize_key((string) $entry['kind']) : '';
            $id   = isset($entry['id']) ? (int) $entry['id'] : 0;
            if ($id <= 0 || !in_array($kind, ['user', 'subscriber'], true)) {
                continue;
            }
            $key = $kind . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['kind' => $kind, 'id' => $id];
        }
        return $out === [] ? '' : (string) wp_json_encode($out);
    }

    /**
     * Constrain the block inserter to the email-safe subset when
     * editing a newsletter. Same approach as TemplateCPT.
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
     * that bare list has no sidebar context. Bounce to the Newsletters
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
            'admin.php?page=' . PageController::SLUG . '&view=' . \LRob\EmailToolkit\Modules\Newsletter\Admin\HomePage::VIEW_NEWSLETTERS
        ));
        exit;
    }
}
