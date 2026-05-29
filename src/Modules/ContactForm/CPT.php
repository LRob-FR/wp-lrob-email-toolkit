<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use LRob\EmailToolkit\Activator;

// Docs: docs/contact-form.md
final class CPT
{
    // 'lrob_etk_contact_form' is 21 chars — exceeds WP's 20-char post_type limit.
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

    public const CHALLENGE_NONE = 'none';

    public const STYLE_DEFAULT = 'default';

    /** Per-form sentinel for tri-state meta: '' / 0 / 'default' all mean "inherit the global default". */
    public const META_INHERIT = 'default';

    // POST key: lrob_etk_cf[<instance>][<slug>]. Newsletter uses lrob_etk_nl to avoid collisions on the same page.
    public const FIELD_NAME_PREFIX = 'lrob_etk_cf';

    // DOM id prefix: lrob-etk-cf-<instance>-<slug>.
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
                // Plural primitives ONLY — no 'edit_post'/'read_post'/'delete_post'.
                // Adding singular caps here makes current_user_can('manage_lrob_etk')
                // recurse into a post-level meta cap path (no post ID) → do_not_allow.
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
            // No 'editor' — fields live on the custom admin page, not in Gutenberg.
            'supports'            => ['title', 'revisions', 'custom-fields'],
            'menu_icon'           => 'dashicons-feedback',
        ]);
    }

    public function register_meta(): void
    {
        $auth_callback = static function (): bool {
            return current_user_can(Activator::CAPABILITY);
        };

        // Sentinels: '' / 0 / 'default' all mean "inherit global default" (Settings::effective_*).
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
