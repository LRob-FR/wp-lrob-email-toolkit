<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use LRob\EmailToolkit\Activator;

/**
 * Registers the contact form post type and its sidebar meta. The post type
 * is non-public (no frontend URL of its own): forms are surfaced exclusively
 * via the embed block. show_in_rest is on so Gutenberg can edit it.
 *
 * Sidebar settings (recipient identity, captcha override, anti-bot toggles,
 * style preset, style vars, confirmation tier) are stored as individual
 * post_meta keys so REST exposes them automatically to the editor sidebar.
 */
final class CPT
{
    /**
     * WordPress rejects post type slugs longer than 20 characters
     * (varchar(20) on the `post_type` column), so `lrob_etk_contact_form`
     * (21 chars) silently failed to register. `cform` keeps the meaning
     * and stays inside the limit.
     */
    public const POST_TYPE = 'lrob_etk_cform';

    public const META_RECIPIENT = '_lrob_etk_cf_recipient';

    public const META_RECIPIENT_IDENTITY = '_lrob_etk_cf_identity_id';

    public const META_REPLY_TO_FIELD = '_lrob_etk_cf_reply_to_field';

    public const META_SUBJECT_TEMPLATE = '_lrob_etk_cf_subject_template';

    public const META_SUCCESS_MESSAGE = '_lrob_etk_cf_success_message';

    public const META_RATE_LIMIT_MAX = '_lrob_etk_cf_rate_max';

    public const META_RATE_LIMIT_WINDOW = '_lrob_etk_cf_rate_window';

    public const META_HONEYPOT_ENABLED = '_lrob_etk_cf_honeypot';

    public const META_CHALLENGE_KIND = '_lrob_etk_cf_challenge';

    public const META_CONFIRMATION_TIER = '_lrob_etk_cf_confirmation_tier';

    public const META_STYLE_PRESET = '_lrob_etk_cf_style_preset';

    public const META_STYLE_VARS = '_lrob_etk_cf_style_vars';

    /** Tri-state ('default' / 'on' / 'off'): per-form override for "save submissions to database". */
    public const META_SAVE_SUBMISSIONS = '_lrob_etk_cf_save_submissions';


    public const CONFIRMATION_NONE = 'none';

    public const CONFIRMATION_BASIC = 'basic';

    public const CONFIRMATION_TOPIC = 'topic';

    public const CONFIRMATION_FULL = 'full';

    /**
     * Per-form captcha routing-key sentinel meaning "no challenge for this
     * form". Matches Routing::ROUTE_NONE — kept here so consumers inside
     * ContactForm don't need to import the Captcha module just for the
     * string. Other routing keys (`homemade:<slug>`, `identity:<id>`) are
     * free-form and don't need constants.
     */
    public const CHALLENGE_NONE = 'none';

    public const STYLE_DEFAULT = 'default';

    /** Per-form sentinel for tri-state meta: '' / 0 / 'default' all mean "inherit the global default". */
    public const META_INHERIT = 'default';

    /**
     * `$_POST` top-level key the public form serializes under: each field
     * is `lrob_etk_cf[<instance>][<slug>]`. EmbedRenderer passes this to
     * FormContext at render time; SubmitHandler reads `$_POST[FIELD_NAME_PREFIX]`
     * on submit. Newsletter has its own equivalent (`lrob_etk_nl`) so the
     * two CPTs' submissions never collide on a single page.
     */
    public const FIELD_NAME_PREFIX = 'lrob_etk_cf';

    /** DOM id prefix: `lrob-etk-cf-<instance>-<slug>`. Mirror of FIELD_NAME_PREFIX. */
    public const FIELD_ID_PREFIX = 'lrob-etk-cf';

    public function register(): void
    {
        add_action('init', [$this, 'register_post_type'], 5);
        add_action('init', [$this, 'register_meta'], 6);
    }

    public function register_post_type(): void
    {
        $labels = [
            'name'               => __('Contact Forms', 'lrob-email-toolkit'),
            'singular_name'      => __('Contact Form', 'lrob-email-toolkit'),
            'add_new'            => __('Add New', 'lrob-email-toolkit'),
            'add_new_item'       => __('Add New Contact Form', 'lrob-email-toolkit'),
            'edit_item'          => __('Edit Contact Form', 'lrob-email-toolkit'),
            'new_item'           => __('New Contact Form', 'lrob-email-toolkit'),
            'view_item'          => __('View Contact Form', 'lrob-email-toolkit'),
            'search_items'       => __('Search Contact Forms', 'lrob-email-toolkit'),
            'not_found'          => __('No contact forms yet.', 'lrob-email-toolkit'),
            'not_found_in_trash' => __('No contact forms in trash.', 'lrob-email-toolkit'),
            'all_items'          => __('Contact Forms', 'lrob-email-toolkit'),
            'menu_name'          => __('Contact Forms', 'lrob-email-toolkit'),
        ];

        register_post_type(self::POST_TYPE, [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => false, // We add it under the Email Toolkit menu via Menu.
            'show_in_admin_bar'   => false,
            'show_in_rest'        => true,
            'rest_base'           => 'lrob-etk-contact-forms',
            'rewrite'             => false,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'capabilities'        => [
                // PLURAL primitives only. Do NOT add 'edit_post', 'read_post',
                // 'delete_post' here — WP's _post_type_meta_capabilities()
                // would then add `$post_type_meta_caps['manage_lrob_etk'] = 'delete_post'`,
                // which makes every later `current_user_can('manage_lrob_etk')`
                // recurse into the delete_post meta cap path (without a post id)
                // and return `do_not_allow`. That silently locks every admin out
                // of the toolkit menu. WP routes singular checks to these plural
                // caps internally — that's all the gating we need.
                'edit_posts'             => Activator::CAPABILITY,
                'edit_others_posts'      => Activator::CAPABILITY,
                'publish_posts'          => Activator::CAPABILITY,
                'read_private_posts'     => Activator::CAPABILITY,
                'delete_posts'           => Activator::CAPABILITY,
                'delete_private_posts'   => Activator::CAPABILITY,
                'delete_published_posts' => Activator::CAPABILITY,
                'delete_others_posts'    => Activator::CAPABILITY,
                'edit_private_posts'     => Activator::CAPABILITY,
                'edit_published_posts'   => Activator::CAPABILITY,
                'create_posts'           => Activator::CAPABILITY,
            ],
            // No 'editor' support — fields are edited on the custom Contact
            // Forms admin page, not in Gutenberg. The CPT keeps 'title' and
            // 'revisions' so the title input still renders and history works.
            'supports'            => ['title', 'revisions', 'custom-fields'],
            'menu_icon'           => 'dashicons-feedback',
        ]);
    }

    public function register_meta(): void
    {
        $auth_callback = static function (): bool {
            return current_user_can(Activator::CAPABILITY);
        };

        // All defaults are the "inherit" sentinel — Settings::effective_*()
        // walks per-form → global → hardcoded fallback. Empty string / 0 /
        // 'default' all mean "use the global default".
        $defs = [
            self::META_RECIPIENT           => ['string',  '',                       $auth_callback],
            self::META_RECIPIENT_IDENTITY  => ['integer', 0,                        $auth_callback],
            self::META_REPLY_TO_FIELD      => ['string',  '',                       $auth_callback],
            self::META_SUBJECT_TEMPLATE    => ['string',  '',                       $auth_callback],
            self::META_SUCCESS_MESSAGE     => ['string',  '',                       $auth_callback],
            self::META_RATE_LIMIT_MAX      => ['integer', 0,                        $auth_callback],
            self::META_RATE_LIMIT_WINDOW   => ['integer', 0,                        $auth_callback],
            self::META_HONEYPOT_ENABLED    => ['string',  self::META_INHERIT,       $auth_callback],
            self::META_CHALLENGE_KIND      => ['string',  '',                       $auth_callback],
            self::META_CONFIRMATION_TIER   => ['string',  self::CONFIRMATION_NONE,  $auth_callback],
            self::META_STYLE_PRESET        => ['string',  '',                       $auth_callback],
            self::META_STYLE_VARS          => ['string',  '',                       $auth_callback],
        ];

        foreach ($defs as $key => [$type, $default, $auth]) {
            register_post_meta(self::POST_TYPE, $key, [
                'type'              => $type,
                'single'            => true,
                'default'           => $default,
                'show_in_rest'      => true,
                'auth_callback'     => $auth,
                'sanitize_callback' => self::sanitizer_for($type),
            ]);
        }
    }

    private static function sanitizer_for(string $type): callable
    {
        return match ($type) {
            'integer' => static fn ($v): int => (int) $v,
            'boolean' => static fn ($v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            default   => static fn ($v): string => is_scalar($v) ? (string) $v : '',
        };
    }
}
