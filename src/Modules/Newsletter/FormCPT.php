<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Newsletter\Admin\PageController;

/**
 * Registers the `lrob_etk_nl_form` post type — newsletter subscribe forms.
 * Each form is a row/column/field structure stored as JSON in post_content
 * (via the shared FormStructure), edited with the same WYSIWYG builder
 * Contact Form uses (`admin/js/form-fields-editor.js`), and rendered
 * publicly via a Gutenberg block + shortcode (lands in step 3b).
 *
 * Slug `lrob_etk_nl_form` is 16 chars — fits WP's 20-char CPT limit.
 *
 * Registered unconditionally (not gated on is_enabled()) so that
 * install() and admin code can rely on the post type existing regardless
 * of toggle state. Disabled module preserves form data; the admin UI is
 * hidden but the rows stay.
 */
final class FormCPT
{
    public const POST_TYPE = 'lrob_etk_nl_form';

    /**
     * $_POST top-level key for submitted field data — `lrob_etk_nl[<instance>][<slug>]`.
     * Mirror of ContactForm's CPT::FIELD_NAME_PREFIX so each CPT submits
     * under its own bucket and never collides on a page with both.
     */
    public const FIELD_NAME_PREFIX = 'lrob_etk_nl';

    /** DOM id prefix for form fields: `<prefix>-<instance>-<slug>`. */
    public const FIELD_ID_PREFIX = 'lrob-etk-nl-form';

    /** Which onboarding template (lrob_etk_nl_etpl) is used for this form's confirmation email. */
    public const META_CONFIRMATION_TEMPLATE_ID = '_lrob_etk_nl_form_confirmation_template_id';

    /**
     * Legacy single-default-list meta — kept as a back-compat fallback
     * read by SubmitHandler when META_DEFAULT_LIST_IDS is empty.
     * Admin UI no longer writes here.
     */
    public const META_DEFAULT_LIST_ID = '_lrob_etk_nl_form_default_list_id';

    /**
     * Multi-list default audience — when a form has no list_picker
     * field, confirmed subscribers from this form land in every list
     * referenced here. JSON-encoded array of int IDs. Empty array =
     * no automatic list assignment.
     */
    public const META_DEFAULT_LIST_IDS = '_lrob_etk_nl_form_default_list_ids';

    /** Per-form success message shown to the visitor after submit. Empty = inherit global. */
    public const META_SUCCESS_MESSAGE = '_lrob_etk_nl_form_success_message';

    /**
     * Per-form style preset override. Shared style preset registry
     * lives at src/Forms/StylePresets (same list Contact Form uses)
     * so both modules pick from the same vocabulary. Empty string =
     * inherit the module's global default.
     */
    public const META_STYLE_PRESET = '_lrob_etk_nl_form_style_preset';

    /**
     * Per-form captcha routing override — same shape ContactForm's
     * META_CHALLENGE_KIND uses: '' (inherit context), 'none',
     * 'homemade:<slug>', or 'identity:<id>'. Read by the shared
     * src/Forms/CaptchaField at render time.
     */
    public const META_CAPTCHA_ROUTE = '_lrob_etk_nl_form_captcha_route';

    public function register(): void
    {
        add_action('init', [$this, 'register_post_type'], 6);
        add_action('init', [$this, 'register_meta'], 7);
        add_action('admin_init', [$this, 'redirect_post_list']);
    }

    public function register_post_type(): void
    {
        $labels = [
            'name'               => __('Newsletter forms', 'lrob-email-toolkit'),
            'singular_name'      => __('Newsletter form', 'lrob-email-toolkit'),
            'add_new'            => __('Add new', 'lrob-email-toolkit'),
            'add_new_item'       => __('Add new newsletter form', 'lrob-email-toolkit'),
            'edit_item'          => __('Edit newsletter form', 'lrob-email-toolkit'),
            'new_item'           => __('New newsletter form', 'lrob-email-toolkit'),
            'view_item'          => __('View newsletter form', 'lrob-email-toolkit'),
            'search_items'       => __('Search newsletter forms', 'lrob-email-toolkit'),
            'not_found'          => __('No newsletter forms yet.', 'lrob-email-toolkit'),
            'not_found_in_trash' => __('No newsletter forms in trash.', 'lrob-email-toolkit'),
            'all_items'          => __('Newsletter forms', 'lrob-email-toolkit'),
            'menu_name'          => __('Newsletter forms', 'lrob-email-toolkit'),
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
            'rest_base'           => 'lrob-etk-nl-forms',
            'has_archive'         => false,
            'hierarchical'        => false,
            // Internal-only: no rewrite tags, no query var. See TemplateCPT
            // for the same defensive defaults — keeps register_post_type
            // safe under any request-lifecycle ordering.
            'rewrite'             => false,
            'query_var'           => false,
            'supports'            => ['title', 'revisions', 'custom-fields'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            // PLURAL primitives only — adding singular caps (edit_post /
            // read_post / delete_post) triggers the cap-collision trap
            // documented in project_wp_cpt_cap_collision.md. With
            // map_meta_cap=true, singular checks like current_user_can(
            // 'edit_post', $id) route through these plural grants.
            'capabilities'        => [
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
            'menu_icon'           => 'dashicons-email-alt',
            'menu_position'       => null,
        ]);
    }

    public function register_meta(): void
    {
        $auth_callback = static fn (): bool => current_user_can(Activator::CAPABILITY);

        register_post_meta(self::POST_TYPE, self::META_CONFIRMATION_TEMPLATE_ID, [
            'type'              => 'integer',
            'single'            => true,
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_DEFAULT_LIST_ID, [
            'type'              => 'integer',
            'single'            => true,
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_DEFAULT_LIST_IDS, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            // JSON array of ints — sanitize by re-decoding so anything
            // shaped wrong gets stored as an empty array.
            'sanitize_callback' => static function ($value): string {
                $raw = is_string($value) ? $value : '';
                if ($raw === '') {
                    return '';
                }
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    return '';
                }
                $ids = array_values(array_unique(array_filter(
                    array_map('intval', $decoded),
                    static fn ($n) => $n > 0
                )));
                return $ids === [] ? '' : (string) wp_json_encode($ids);
            },
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_SUCCESS_MESSAGE, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_STYLE_PRESET, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => 'sanitize_html_class',
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_CAPTCHA_ROUTE, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            // Routing keys are constrained to `none` | `homemade:<slug>`
            // | `identity:<int>`. sanitize_text_field is conservative;
            // the shared CaptchaField never trusts the value without
            // resolving it through CaptchaService anyway.
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
    }

    /**
     * Bounce visitors who land on the bare WP post-list page for this CPT
     * back to the Newsletter hub's Forms view. Same treatment as
     * TemplateCPT — show_in_menu=false means edit.php?post_type=… has no
     * sidebar context and is the wrong end-state.
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
            'admin.php?page=' . PageController::SLUG . '&view=forms'
        ));
        exit;
    }
}
