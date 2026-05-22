<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Forms\FormStructure;
use LRob\EmailToolkit\Modules\Newsletter\CategoryRepository;
use LRob\EmailToolkit\Modules\Newsletter\FormCPT;
use LRob\EmailToolkit\Modules\Newsletter\FormTemplateRegistry;
use LRob\EmailToolkit\Modules\Newsletter\ListRepository;
use LRob\EmailToolkit\Modules\Newsletter\Send\NewsletterFooter;
use LRob\EmailToolkit\Modules\Newsletter\SubscriberRepository;
use LRob\EmailToolkit\Modules\Newsletter\TrashCron;

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

    public const ACTION_CATEGORY_CREATE = 'lrob_etk_nl_category_create';

    public const ACTION_CATEGORY_RENAME = 'lrob_etk_nl_category_rename';

    public const ACTION_CATEGORY_DELETE = 'lrob_etk_nl_category_delete';

    public const ACTION_LIST_CREATE     = 'lrob_etk_nl_list_create';

    public const ACTION_LIST_RENAME     = 'lrob_etk_nl_list_rename';

    public const ACTION_LIST_DELETE     = 'lrob_etk_nl_list_delete';

    public const ACTION_SUBSCRIBER_TRASH    = 'lrob_etk_nl_subscriber_trash';

    public const ACTION_SUBSCRIBER_RESTORE  = 'lrob_etk_nl_subscriber_restore';

    public const ACTION_SUBSCRIBER_DELETE   = 'lrob_etk_nl_subscriber_delete';

    public const ACTION_EMPTY_TRASH         = 'lrob_etk_nl_empty_trash';

    /**
     * Per-key option save for the Settings view. Each whitelisted
     * option gets its own sanitisation rule below; unknown keys are
     * rejected with a 400.
     */
    public const ACTION_SETTING_SAVE    = 'lrob_etk_nl_setting_save';

    /** Settings keys the AJAX save endpoint accepts. */
    private const WHITELIST_SETTING_KEYS = [
        'lrob_etk_nl_reminder_max',
        'lrob_etk_nl_first_reminder_after_days',
        'lrob_etk_nl_reminder_interval_days',
        TrashCron::OPTION_DAYS,
        NewsletterFooter::OPTION_HTML,
    ];

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

        // Categories + lists: simple CRUD over the admin-ajax route,
        // JSON responses. The admin pages submit via fetch and re-
        // render the affected row on success.
        add_action('wp_ajax_' . self::ACTION_CATEGORY_CREATE, [$this, 'handle_category_create']);
        add_action('wp_ajax_' . self::ACTION_CATEGORY_RENAME, [$this, 'handle_category_rename']);
        add_action('wp_ajax_' . self::ACTION_CATEGORY_DELETE, [$this, 'handle_category_delete']);
        add_action('wp_ajax_' . self::ACTION_LIST_CREATE,     [$this, 'handle_list_create']);
        add_action('wp_ajax_' . self::ACTION_LIST_RENAME,     [$this, 'handle_list_rename']);
        add_action('wp_ajax_' . self::ACTION_LIST_DELETE,     [$this, 'handle_list_delete']);
        add_action('wp_ajax_' . self::ACTION_SETTING_SAVE,    [$this, 'handle_setting_save']);
        add_action('wp_ajax_' . self::ACTION_SUBSCRIBER_TRASH,   [$this, 'handle_subscriber_trash']);
        add_action('wp_ajax_' . self::ACTION_SUBSCRIBER_RESTORE, [$this, 'handle_subscriber_restore']);
        add_action('wp_ajax_' . self::ACTION_SUBSCRIBER_DELETE,  [$this, 'handle_subscriber_delete']);
        add_action('wp_ajax_' . self::ACTION_EMPTY_TRASH,        [$this, 'handle_empty_trash']);
    }

    /**
     * Save a whitelisted module option. Payload: { key, value }.
     * Per-key sanitisation lives below — option_keys are validated
     * against WHITELIST_SETTING_KEYS before dispatch.
     */
    public function handle_setting_save(): void
    {
        $this->guard();
        $key = isset($_POST['key']) ? sanitize_key(wp_unslash((string) $_POST['key'])) : '';
        if (!in_array($key, self::WHITELIST_SETTING_KEYS, true)) {
            wp_send_json_error(['message' => __('Unknown setting.', 'lrob-email-toolkit')], 400);
        }
        $raw = isset($_POST['value']) ? wp_unslash((string) $_POST['value']) : '';

        switch ($key) {
            case 'lrob_etk_nl_reminder_max':
                // 0 disables reminders entirely. Cap at 10 to prevent
                // absurd values; that's overkill anyway.
                $value = max(0, min(10, (int) $raw));
                break;
            case 'lrob_etk_nl_first_reminder_after_days':
            case 'lrob_etk_nl_reminder_interval_days':
                // 1-day floor — the cron runs daily so anything below
                // 1 is meaningless. 365-day ceiling because longer
                // windows likely indicate a misconfiguration.
                $value = max(1, min(365, (int) $raw));
                break;
            case TrashCron::OPTION_DAYS:
                // 0 = disabled (never auto-purge); otherwise clamp to a
                // reasonable window. Larger retention windows are fine
                // for compliance archives but eventually the trash isn't
                // doing anything useful.
                $value = max(0, min(3650, (int) $raw));
                break;
            case NewsletterFooter::OPTION_HTML:
                // wp_kses_post strips JS/forms but keeps the table
                // markup needed for cross-client centering. The
                // renderer enforces unsub_url presence on read, so
                // we don't validate it here — empty/broken stored
                // value just falls back to the default at render.
                $value = wp_kses_post(trim((string) $raw));
                break;
            default:
                wp_send_json_error(['message' => __('Unsupported setting.', 'lrob-email-toolkit')], 400);
        }

        update_option($key, $value, false);
        wp_send_json_success(['value' => $value]);
    }

    public function handle_category_create(): void
    {
        $this->guard();
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
        if ($name === '') {
            wp_send_json_error(['message' => __('Category name is required.', 'lrob-email-toolkit')], 400);
        }
        $id = (new CategoryRepository())->insert($name);
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Could not create the category (the slug may collide with an existing one).', 'lrob-email-toolkit')], 409);
        }
        wp_send_json_success(['id' => $id]);
    }

    public function handle_category_rename(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
        if ($id <= 0 || $name === '') {
            wp_send_json_error(['message' => __('Missing category id or name.', 'lrob-email-toolkit')], 400);
        }
        $ok = (new CategoryRepository())->rename($id, $name);
        if (!$ok) {
            wp_send_json_error(['message' => __('Could not rename the category.', 'lrob-email-toolkit')], 500);
        }
        wp_send_json_success();
    }

    public function handle_category_delete(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        $ok = $id > 0 && (new CategoryRepository())->delete($id);
        if (!$ok) {
            wp_send_json_error(['message' => __('Could not delete the category (the default "general" category is protected).', 'lrob-email-toolkit')], 400);
        }
        wp_send_json_success();
    }

    public function handle_list_create(): void
    {
        $this->guard();
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
        if ($name === '') {
            wp_send_json_error(['message' => __('List name is required.', 'lrob-email-toolkit')], 400);
        }
        $id = (new ListRepository())->insert($name);
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Could not create the list (the slug may collide with an existing one).', 'lrob-email-toolkit')], 409);
        }
        wp_send_json_success(['id' => $id]);
    }

    public function handle_list_rename(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
        if ($id <= 0 || $name === '') {
            wp_send_json_error(['message' => __('Missing list id or name.', 'lrob-email-toolkit')], 400);
        }
        $ok = (new ListRepository())->rename($id, $name);
        if (!$ok) {
            wp_send_json_error(['message' => __('Could not rename the list.', 'lrob-email-toolkit')], 500);
        }
        wp_send_json_success();
    }

    public function handle_list_delete(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        $ok = $id > 0 && (new ListRepository())->delete($id);
        if (!$ok) {
            wp_send_json_error(['message' => __('Could not delete the list.', 'lrob-email-toolkit')], 500);
        }
        wp_send_json_success();
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
                /* translators: %s: original item title being cloned (form, newsletter, template, etc.) */
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

    public function handle_subscriber_trash(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Missing subscriber id.', 'lrob-email-toolkit')], 400);
        }
        (new SubscriberRepository())->trash($id, 'admin');
        wp_send_json_success();
    }

    public function handle_subscriber_restore(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        $ok = $id > 0 && (new SubscriberRepository())->restore($id);
        if (!$ok) {
            wp_send_json_error(['message' => __('Could not restore — the subscriber may no longer be in trash.', 'lrob-email-toolkit')], 400);
        }
        wp_send_json_success();
    }

    public function handle_subscriber_delete(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        $ok = $id > 0 && (new SubscriberRepository())->permanently_delete($id);
        if (!$ok) {
            wp_send_json_error(['message' => __('Only trashed subscribers can be permanently deleted. Move the row to trash first.', 'lrob-email-toolkit')], 400);
        }
        wp_send_json_success();
    }

    public function handle_empty_trash(): void
    {
        $this->guard();
        $deleted = (new SubscriberRepository())->empty_trash();
        wp_send_json_success(['deleted' => $deleted]);
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
