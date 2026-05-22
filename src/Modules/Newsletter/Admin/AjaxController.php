<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Forms\FormStructure;
use LRob\EmailToolkit\Modules\Newsletter\FormCPT;
use LRob\EmailToolkit\Modules\Newsletter\FormTemplateRegistry;

/**
 * Admin-AJAX endpoints for the newsletter Forms admin UI:
 *
 *  - lrob_etk_nl_form_save_structure  : the WYSIWYG editor calls this
 *      on every change. Persists the row/column/field JSON through
 *      the shared FormStructure normalizer.
 *  - lrob_etk_nl_form_save_meta       : per-form settings card auto-save
 *      (confirmation template, default list, success message).
 *  - lrob_etk_nl_form_create          : admin_post handler — wp_insert_post-s
 *      a draft, redirects into the editor. Triggered by the "+ New form"
 *      button in the Forms admin view.
 *
 * Whitelist-based: any unknown meta key is rejected so the endpoint
 * never writes arbitrary post_meta. Capability + nonce checks on every
 * call.
 */
final class AjaxController
{
    public const NONCE_ACTION = 'lrob_etk_nl_form_admin';

    public const ACTION_SAVE_STRUCTURE = 'lrob_etk_nl_form_save_structure';

    public const ACTION_SAVE_META      = 'lrob_etk_nl_form_save_meta';

    public const ACTION_CREATE_FORM    = 'lrob_etk_nl_form_create';

    public const ACTION_DELETE_FORM    = 'lrob_etk_nl_form_delete';

    private const WHITELIST_META_KEYS = [
        FormCPT::META_CONFIRMATION_TEMPLATE_ID,
        FormCPT::META_DEFAULT_LIST_ID,
        FormCPT::META_SUCCESS_MESSAGE,
        FormCPT::META_STYLE_PRESET,
        FormCPT::META_CAPTCHA_ROUTE,
    ];

    /** Special non-meta key for the title (which lives on the post row, not in post_meta). */
    private const TITLE_KEY = 'title';

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_SAVE_STRUCTURE, [$this, 'handle_save_structure']);
        add_action('wp_ajax_' . self::ACTION_SAVE_META,      [$this, 'handle_save_meta']);
        // Create-form is now an AJAX endpoint (matches ContactForm's
        // pattern). The new-picker JS reads the returned form_id and
        // navigates to #form-<id> after page reload.
        add_action('wp_ajax_' . self::ACTION_CREATE_FORM,    [$this, 'handle_create_form']);
        // Delete is a standard admin_post action (full page navigation)
        // since the confirm modal is just an intercept — once the user
        // confirms, the trigger's data-url-orphan is followed, hitting
        // this handler which deletes + redirects back to the Forms view.
        add_action('admin_post_' . self::ACTION_DELETE_FORM, [$this, 'handle_delete_form']);
    }

    /**
     * Editor JS POSTs the full structure JSON; FormStructure::save
     * normalizes + persists (using the registered field types for our
     * CPT — text, email, captcha, submit). Returns the canonical
     * structure version so the editor can sanity-check.
     */
    public function handle_save_structure(): void
    {
        $this->guard();
        $form_id = isset($_POST['form_id']) ? (int) wp_unslash((string) $_POST['form_id']) : 0;
        $this->require_form($form_id);

        $raw = isset($_POST['structure']) ? wp_unslash((string) $_POST['structure']) : '';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            wp_send_json_error(['message' => __('Invalid structure payload.', 'lrob-email-toolkit')], 400);
        }
        FormStructure::save($form_id, $decoded);
        wp_send_json_success(['version' => FormStructure::VERSION]);
    }

    /**
     * Per-form settings auto-save. Sends `{ form_id, key, value }`.
     * Whitelist on $key prevents arbitrary meta writes. Title gets a
     * dedicated path since it's not post_meta.
     */
    public function handle_save_meta(): void
    {
        $this->guard();
        $form_id = isset($_POST['form_id']) ? (int) wp_unslash((string) $_POST['form_id']) : 0;
        $this->require_form($form_id);

        $key = isset($_POST['key']) ? sanitize_key(wp_unslash((string) $_POST['key'])) : '';
        $raw_value = $_POST['value'] ?? '';
        $value = is_array($raw_value)
            ? array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', wp_unslash($raw_value))
            : wp_unslash((string) $raw_value);

        if ($key === self::TITLE_KEY) {
            wp_update_post([
                'ID'         => $form_id,
                'post_title' => sanitize_text_field(is_array($value) ? '' : $value),
            ]);
            wp_send_json_success();
        }

        if (!in_array($key, self::WHITELIST_META_KEYS, true)) {
            wp_send_json_error(['message' => __('Unknown setting key.', 'lrob-email-toolkit')], 400);
        }

        switch ($key) {
            case FormCPT::META_CONFIRMATION_TEMPLATE_ID:
            case FormCPT::META_DEFAULT_LIST_ID:
                update_post_meta($form_id, $key, is_array($value) ? 0 : (int) $value);
                break;
            case FormCPT::META_SUCCESS_MESSAGE:
                update_post_meta($form_id, $key, is_array($value) ? '' : sanitize_text_field((string) $value));
                break;
            case FormCPT::META_STYLE_PRESET:
                $raw = is_array($value) ? '' : sanitize_html_class((string) $value);
                update_post_meta($form_id, $key, $raw);
                break;
            case FormCPT::META_CAPTCHA_ROUTE:
                $raw = is_array($value) ? '' : sanitize_text_field((string) $value);
                update_post_meta($form_id, $key, $raw);
                break;
            default:
                wp_send_json_error(['message' => __('Unsupported setting.', 'lrob-email-toolkit')], 400);
        }
        wp_send_json_success();
    }

    /**
     * Create a new subscribe form. Source is one of:
     *   - blank: empty form (admin will add fields from scratch)
     *   - template: FormTemplateRegistry slug → cloned structure
     *   - form: existing form id → cloned structure
     * Returns JSON success with form_id; the picker JS uses that to
     * navigate to #form-<id> after a page reload so the new card lands
     * in view.
     */
    public function handle_create_form(): void
    {
        $this->guard();
        $source = isset($_POST['source']) ? sanitize_key(wp_unslash((string) $_POST['source'])) : 'blank';
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash((string) $_POST['title'])) : '';
        $structure = FormStructure::empty_structure();

        if ($source === 'template') {
            $slug = isset($_POST['slug']) ? sanitize_key(wp_unslash((string) $_POST['slug'])) : '';
            $tpl = FormTemplateRegistry::get($slug);
            if ($tpl === null) {
                wp_send_json_error(['message' => __('Unknown template.', 'lrob-email-toolkit')], 400);
            }
            $structure = FormTemplateRegistry::structure_for($slug);
            if ($title === '') {
                $title = $tpl['name'];
            }
        } elseif ($source === 'form') {
            $src_id = isset($_POST['form_id']) ? (int) wp_unslash((string) $_POST['form_id']) : 0;
            $src = $src_id > 0 ? get_post($src_id) : null;
            if (!$src instanceof \WP_Post || $src->post_type !== FormCPT::POST_TYPE) {
                wp_send_json_error(['message' => __('Source form not found.', 'lrob-email-toolkit')], 404);
            }
            $structure = FormStructure::load($src_id);
            if ($title === '') {
                /* translators: %s: name of the source form being cloned */
                $title = sprintf(__('%s (copy)', 'lrob-email-toolkit'), $src->post_title);
            }
        }

        if ($title === '') {
            $title = __('Untitled subscribe form', 'lrob-email-toolkit');
        }

        // See FormStructure::save() for the JSON_UNESCAPED_UNICODE /
        // wp_slash reasoning — wp_insert_post unslashes on save.
        $json = (string) wp_json_encode($structure, JSON_UNESCAPED_UNICODE);
        $new_id = wp_insert_post([
            'post_type'    => FormCPT::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => wp_slash($json),
        ], true);

        if (is_wp_error($new_id) || !is_int($new_id) || $new_id <= 0) {
            wp_send_json_error(['message' => __('Could not create the subscribe form.', 'lrob-email-toolkit')], 500);
        }

        wp_send_json_success(['form_id' => (int) $new_id]);
    }

    /**
     * Delete a subscribe form. Nonce field is `_lrob_etk_nonce` and the
     * action is namespaced per-form (`<action>_<form_id>`) — same pattern
     * Contact Form uses for its per-form delete URLs. Redirects back to
     * the Forms view with a deleted=1 flash so the page can show
     * confirmation if it wants to (currently a no-op).
     */
    public function handle_delete_form(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $form_id = isset($_GET['form_id']) ? (int) wp_unslash((string) $_GET['form_id']) : 0;
        $nonce = isset($_GET['_lrob_etk_nonce']) ? wp_unslash((string) $_GET['_lrob_etk_nonce']) : '';
        if (!wp_verify_nonce($nonce, self::ACTION_DELETE_FORM . '_' . $form_id)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }
        $post = $form_id > 0 ? get_post($form_id) : null;
        if (!$post instanceof \WP_Post || $post->post_type !== FormCPT::POST_TYPE) {
            wp_die(esc_html__('Subscribe form not found.', 'lrob-email-toolkit'));
        }
        wp_delete_post($form_id, true);
        wp_safe_redirect(add_query_arg(
            ['page' => PageController::SLUG, 'view' => 'forms', 'deleted' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    private function guard(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'lrob-email-toolkit')], 403);
        }
        // `_nonce` field name (not `_wpnonce`) matches what the shared
        // form-fields-editor.js and the per-form-card auto-save script
        // both send. Aligned with ContactForm's AjaxController for
        // consistency.
        $nonce = isset($_POST['_nonce']) ? wp_unslash((string) $_POST['_nonce']) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload the page.', 'lrob-email-toolkit')], 400);
        }
    }

    private function require_form(int $form_id): void
    {
        $post = $form_id > 0 ? get_post($form_id) : null;
        if (!$post instanceof \WP_Post || $post->post_type !== FormCPT::POST_TYPE) {
            wp_send_json_error(['message' => __('Newsletter form not found.', 'lrob-email-toolkit')], 404);
        }
    }
}
