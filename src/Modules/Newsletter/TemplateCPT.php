<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Newsletter\Admin\PageController;

/**
 * Registers the `lrob_etk_nl_etpl` post type — system email templates
 * (confirmation, reminder, refuse-acknowledgment). Composed in Gutenberg
 * with a curated allowed-blocks list, rendered through TemplateRenderer
 * at send time with `{{token}}` substitution.
 *
 *   - lrob_etk_nl_etpl: 16 chars, fits WP's 20-char CPT slug limit.
 *   - non-public, show_in_menu=false (managed through the Newsletter
 *     homepage's Templates view, not the WP sidebar).
 *   - show_in_rest=true so Gutenberg can edit it.
 *   - capability_type='post' mapped to the toolkit's manage_lrob_etk
 *     primitive — avoids the singular-meta-cap collision documented in
 *     project_wp_cpt_cap_collision.md (map_meta_cap stays at false; we
 *     map the plural caps only).
 *
 * Registration is unconditional (not gated on is_enabled()) so that
 * install() can wp_insert_post() default templates during the toggle's
 * enable() flow, which fires inside admin-post.php after init has
 * already passed. Disabling the module preserves the templates as data;
 * the Templates admin view simply isn't reachable until re-enabled.
 */
final class TemplateCPT
{
    public const POST_TYPE = 'lrob_etk_nl_etpl';

    public const META_PURPOSE = '_lrob_etk_nl_template_purpose';

    public const META_IS_DEFAULT = '_lrob_etk_nl_template_is_default';

    public const META_SMTP_IDENTITY = '_lrob_etk_nl_template_smtp_identity_id';

    /** Stable purpose values — used in meta + dropdowns + token-availability checks. */
    public const PURPOSE_CONFIRMATION = 'confirmation';

    public const PURPOSE_REMINDER     = 'reminder';

    public const PURPOSE_REFUSE_ACK   = 'refuse_ack';

    /**
     * Curated allowed-blocks list. Email clients destroy most modern CSS;
     * the safe subset is paragraph/heading/image/button/columns/etc. with
     * inline styles. The CSS inliner (lands with the send-pipeline step)
     * relies on this restricted vocabulary to keep transformation work
     * tractable.
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
        // Power-user escape hatch — paste raw HTML where blocks fall short.
        'core/html',
    ];

    public function register(): void
    {
        add_action('init', [$this, 'register_post_type'], 6);
        add_action('init', [$this, 'register_meta'], 7);
        add_filter('allowed_block_types_all', [$this, 'filter_allowed_blocks'], 10, 2);
        add_action('admin_init', [$this, 'redirect_post_list']);
        add_action('add_meta_boxes_' . self::POST_TYPE, [$this, 'register_tokens_metabox']);
    }

    /**
     * Editor-side token reference. Lists every token the active
     * template's purpose accepts, with required ones marked and a
     * one-click copy affordance — saves the admin from alt-tabbing
     * back to the Onboarding view to look them up. Hooks via
     * `add_meta_boxes_<cpt>` so the box only appears on this CPT's
     * editor. Lives in the document sidebar (`side`, `default`).
     */
    public function register_tokens_metabox(\WP_Post $post): void
    {
        add_meta_box(
            'lrob-etk-nl-tokens',
            __('Available tokens', 'lrob-email-toolkit'),
            [$this, 'render_tokens_metabox'],
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    public function render_tokens_metabox(\WP_Post $post): void
    {
        $purpose = (string) get_post_meta($post->ID, self::META_PURPOSE, true);
        $tokens = TemplateTokens::available_tokens($purpose);
        $required = TemplateTokens::required_tokens($purpose);
        if ($tokens === []) {
            echo '<p>' . esc_html__('No tokens available — this template\'s purpose isn\'t set.', 'lrob-email-toolkit') . '</p>';
            return;
        }
        ?>
        <p class="lrob-etk-nl-tokens-mb-intro">
            <?php esc_html_e('Click a token to copy it. Paste anywhere in the email body — values are substituted per-recipient at send time.', 'lrob-email-toolkit'); ?>
        </p>
        <ul class="lrob-etk-nl-tokens-mb-list">
            <?php foreach ($tokens as $token) : ?>
                <?php $is_req = in_array($token, $required, true); ?>
                <li class="lrob-etk-nl-tokens-mb-item">
                    <button type="button"
                            class="lrob-etk-nl-tokens-mb-copy<?php echo $is_req ? ' is-required' : ''; ?>"
                            data-token="<?php echo esc_attr('{{' . $token . '}}'); ?>"
                            title="<?php echo esc_attr($is_req
                                ? __('Required for this purpose — click to copy.', 'lrob-email-toolkit')
                                : __('Click to copy.', 'lrob-email-toolkit')
                            ); ?>">
                        <code>{{<?php echo esc_html($token); ?>}}<?php echo $is_req ? '*' : ''; ?></code>
                        <span class="lrob-etk-nl-tokens-mb-copy-state" aria-hidden="true">⧉</span>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($required !== []) : ?>
            <p class="lrob-etk-nl-tokens-mb-required-note">
                <?php esc_html_e('* must appear at least once in the template body.', 'lrob-email-toolkit'); ?>
            </p>
        <?php endif; ?>
        <style>
            #lrob-etk-nl-tokens .lrob-etk-nl-tokens-mb-intro {
                margin: 0 0 0.75em;
                color: #50575e;
                font-size: 0.85em;
            }
            #lrob-etk-nl-tokens .lrob-etk-nl-tokens-mb-list {
                list-style: none;
                margin: 0;
                padding: 0;
                display: flex;
                flex-wrap: wrap;
                gap: 0.4em;
            }
            #lrob-etk-nl-tokens .lrob-etk-nl-tokens-mb-item {
                margin: 0;
            }
            #lrob-etk-nl-tokens .lrob-etk-nl-tokens-mb-copy {
                display: inline-flex;
                align-items: center;
                gap: 0.3em;
                padding: 0.25em 0.6em;
                border: 1px solid #c3c4c7;
                background: #fff;
                border-radius: 4px;
                cursor: pointer;
                font: inherit;
                transition: background-color 100ms ease, border-color 100ms ease;
            }
            #lrob-etk-nl-tokens .lrob-etk-nl-tokens-mb-copy:hover {
                background: #f0f0f1;
                border-color: #8c8f94;
            }
            #lrob-etk-nl-tokens .lrob-etk-nl-tokens-mb-copy code {
                background: transparent;
                padding: 0;
                font-size: 0.85em;
                color: #1d2327;
            }
            #lrob-etk-nl-tokens .lrob-etk-nl-tokens-mb-copy.is-required code {
                font-weight: 600;
            }
            #lrob-etk-nl-tokens .lrob-etk-nl-tokens-mb-copy-state {
                font-size: 0.8em;
                color: #757575;
                line-height: 1;
            }
            #lrob-etk-nl-tokens .lrob-etk-nl-tokens-mb-copy.is-copied {
                background: #d1fae5;
                border-color: #34d399;
            }
            #lrob-etk-nl-tokens .lrob-etk-nl-tokens-mb-copy.is-copied .lrob-etk-nl-tokens-mb-copy-state {
                color: #065f46;
            }
            #lrob-etk-nl-tokens .lrob-etk-nl-tokens-mb-required-note {
                margin: 0.75em 0 0;
                color: #50575e;
                font-size: 0.8em;
                font-style: italic;
            }
        </style>
        <script>
        (function () {
            var root = document.getElementById('lrob-etk-nl-tokens');
            if (!root) return;
            root.addEventListener('click', function (e) {
                var btn = e.target.closest('.lrob-etk-nl-tokens-mb-copy');
                if (!btn) return;
                e.preventDefault();
                var token = btn.getAttribute('data-token') || '';
                if (!token) return;
                var done = function () {
                    btn.classList.add('is-copied');
                    setTimeout(function () { btn.classList.remove('is-copied'); }, 900);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(token).then(done, function () { /* swallow */ });
                } else {
                    // Fallback: legacy execCommand on a transient textarea.
                    var ta = document.createElement('textarea');
                    ta.value = token;
                    ta.setAttribute('readonly', '');
                    ta.style.position = 'absolute';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); done(); } catch (_) { /* noop */ }
                    document.body.removeChild(ta);
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Gutenberg's "back to list" / "Save & exit" buttons send the user
     * to `edit.php?post_type=<cpt>`. Our CPT has show_in_menu=false so
     * that bare post list has no sidebar context and is the wrong UX
     * end-state. Bounce them back to the Onboarding view of our hub.
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
            'admin.php?page=' . PageController::SLUG . '&view=onboarding'
        ));
        exit;
    }

    public function register_post_type(): void
    {
        $labels = [
            'name'               => __('Email templates', 'lrob-email-toolkit'),
            'singular_name'      => __('Email template', 'lrob-email-toolkit'),
            'add_new'            => __('Add new', 'lrob-email-toolkit'),
            'add_new_item'       => __('Add new email template', 'lrob-email-toolkit'),
            'edit_item'          => __('Edit email template', 'lrob-email-toolkit'),
            'new_item'           => __('New email template', 'lrob-email-toolkit'),
            'view_item'          => __('View email template', 'lrob-email-toolkit'),
            'search_items'       => __('Search email templates', 'lrob-email-toolkit'),
            'not_found'          => __('No email templates yet.', 'lrob-email-toolkit'),
            'not_found_in_trash' => __('No email templates in trash.', 'lrob-email-toolkit'),
            'all_items'          => __('Email templates', 'lrob-email-toolkit'),
            'menu_name'          => __('Email templates', 'lrob-email-toolkit'),
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
            'rest_base'           => 'lrob-etk-nl-templates',
            'has_archive'         => false,
            'hierarchical'        => false,
            // Internal-only post type: no rewrite rules, no public query
            // var. These also keep register_post_type safe to call before
            // $wp_rewrite / $wp are initialised — WP_Post_Type::
            // add_rewrite_rules guards on `false !== $this->rewrite` and
            // `false !== $this->query_var` so both blocks short-circuit.
            'rewrite'             => false,
            'query_var'           => false,
            'can_export'          => true,
            'supports'            => ['title', 'editor', 'revisions', 'custom-fields'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'capabilities'        => [
                // PLURAL primitives only — same pattern as Contact Form's
                // CPT. Adding singular caps (edit_post / read_post /
                // delete_post) to the array makes WP register a reverse
                // mapping from manage_lrob_etk back to delete_post, which
                // makes EVERY current_user_can('manage_lrob_etk') check
                // recurse into the meta-cap path and return do_not_allow.
                // Silently locks the whole toolkit out.
                // map_meta_cap=true lets WP route singular checks like
                // current_user_can('edit_post', $id) through the plural
                // grants below.
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

        register_post_meta(self::POST_TYPE, self::META_PURPOSE, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => 'sanitize_key',
            'auth_callback'     => $auth_callback,
            'show_in_rest'      => true,
        ]);
        register_post_meta(self::POST_TYPE, self::META_IS_DEFAULT, [
            'type'              => 'boolean',
            'single'            => true,
            'default'           => false,
            'sanitize_callback' => static fn ($v) => !empty($v),
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
    }

    /**
     * Constrain the block inserter to the email-safe subset when editing
     * an email template. The `$context` argument's `post` property tells
     * us which CPT is being edited.
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

    /** @return array<int, string> Stable purpose enum values for dropdowns + validation. */
    public static function purposes(): array
    {
        return [
            self::PURPOSE_CONFIRMATION,
            self::PURPOSE_REMINDER,
            self::PURPOSE_REFUSE_ACK,
        ];
    }

    public static function purpose_label(string $purpose): string
    {
        return match ($purpose) {
            self::PURPOSE_CONFIRMATION => __('Confirmation email', 'lrob-email-toolkit'),
            self::PURPOSE_REMINDER     => __('Reminder email', 'lrob-email-toolkit'),
            self::PURPOSE_REFUSE_ACK   => __('Refuse acknowledgment', 'lrob-email-toolkit'),
            default                    => $purpose,
        };
    }
}
