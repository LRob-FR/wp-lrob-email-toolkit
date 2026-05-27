<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Forms\FormStructure;
use LRob\EmailToolkit\Modules\Newsletter\FormCPT;
use LRob\EmailToolkit\Modules\Newsletter\FormTemplateRegistry;
use LRob\EmailToolkit\Modules\Newsletter\ListRepository;
use LRob\EmailToolkit\Modules\Newsletter\Lists\RuleRegistry;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;
use LRob\EmailToolkit\Modules\Newsletter\Send\NewsletterFooter;
use LRob\EmailToolkit\Modules\Newsletter\SubscriberRepository;
use LRob\EmailToolkit\Modules\Newsletter\TemplateRepository;
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

    public const ACTION_LIST_CREATE     = 'lrob_etk_nl_list_create';

    public const ACTION_LIST_RENAME     = 'lrob_etk_nl_list_rename';

    public const ACTION_LIST_DELETE     = 'lrob_etk_nl_list_delete';

    public const ACTION_LIST_RULE_SAVE  = 'lrob_etk_nl_list_rule_save';

    public const ACTION_LIST_VISIBILITY_SET = 'lrob_etk_nl_list_visibility_set';

    public const ACTION_LIST_RULE_PREVIEW = 'lrob_etk_nl_list_rule_preview';

    public const ACTION_LIST_EXCLUSION_ADD    = 'lrob_etk_nl_list_exclusion_add';

    public const ACTION_LIST_EXCLUSION_REMOVE = 'lrob_etk_nl_list_exclusion_remove';

    public const ACTION_LIST_EXCLUSIONS_LIST  = 'lrob_etk_nl_list_exclusions_list';

    public const ACTION_SUBSCRIBER_TRASH    = 'lrob_etk_nl_subscriber_trash';

    public const ACTION_SUBSCRIBER_RESTORE  = 'lrob_etk_nl_subscriber_restore';

    public const ACTION_SUBSCRIBER_DELETE   = 'lrob_etk_nl_subscriber_delete';

    public const ACTION_SUBSCRIBER_DETAIL   = 'lrob_etk_nl_subscriber_detail';

    public const ACTION_SUBSCRIBER_LIST_TOGGLE = 'lrob_etk_nl_subscriber_list_toggle';

    public const ACTION_SUBSCRIBERS_IMPORT     = 'lrob_etk_nl_subscribers_import';

    public const ACTION_SUBSCRIBER_UPDATE      = 'lrob_etk_nl_subscriber_update';

    public const ACTION_SUBSCRIBERS_COLUMNS_PREF = 'lrob_etk_nl_subscribers_columns_pref';

    public const ACTION_SUBSCRIBERS_BULK         = 'lrob_etk_nl_subscribers_bulk';

    public const ACTION_WC_PRODUCT_SEARCH        = 'lrob_etk_nl_wc_product_search';

    public const ACTION_WP_USER_SEARCH           = 'lrob_etk_nl_wp_user_search';

    public const ACTION_EMPTY_TRASH         = 'lrob_etk_nl_empty_trash';

    public const ACTION_NEWSLETTER_SAVE_META = 'lrob_etk_nl_newsletter_save_meta';

    public const ACTION_TEMPLATE_SET_DEFAULT = 'lrob_etk_nl_template_set_default';

    /**
     * Newsletter-card meta keys. Mirror NewsletterCPT::META_* keys
     * plus the `title` pseudo-key (post_title isn't post_meta).
     */
    private const WHITELIST_NEWSLETTER_KEYS = [
        'title',
        NewsletterCPT::META_PREVIEW_TEXT,
        NewsletterCPT::META_FROM_NAME_OVERRIDE,
        NewsletterCPT::META_REPLY_TO_OVERRIDE,
        NewsletterCPT::META_SMTP_IDENTITY,
        // target_spec is composed from target_kind + target_list_id
        // posts — they arrive as separate keys but write the same meta.
        // Modern UI sends a single `target_audience` value of either
        // 'all' or 'list:<id>' that the handler unpacks into both.
        'target_kind',
        'target_list_id',
        'target_audience',
        'target_list_ids',
        NewsletterCPT::META_SCHEDULED_AT,
        NewsletterCPT::META_TRACK_OPENS,
        NewsletterCPT::META_TRACK_CLICKS,
        NewsletterCPT::META_LOG_ALL_SENDS,
    ];

    /**
     * Per-key option save for the Settings view. Each whitelisted
     * option gets its own sanitisation rule below; unknown keys are
     * rejected with a 400.
     */
    public const ACTION_SETTING_SAVE    = 'lrob_etk_nl_setting_save';

    /** Settings keys the AJAX save endpoint accepts. */
    private const WHITELIST_SETTING_KEYS = [
        'lrob_etk_nl_reminder_enabled',
        'lrob_etk_nl_reminder_max',
        'lrob_etk_nl_first_reminder_after_days',
        'lrob_etk_nl_reminder_interval_days',
        TrashCron::OPTION_DAYS,
        NewsletterFooter::OPTION_INTRO,
        NewsletterFooter::OPTION_PREFS_LABEL,
        NewsletterFooter::OPTION_UNSUB_LABEL,
        'lrob_etk_nl_cold_threshold',
        'lrob_etk_nl_engagement_counts_opens',
        'lrob_etk_nl_tracking_retention_days',
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
        add_action('wp_ajax_' . self::ACTION_LIST_CREATE,     [$this, 'handle_list_create']);
        add_action('wp_ajax_' . self::ACTION_LIST_RENAME,     [$this, 'handle_list_rename']);
        add_action('wp_ajax_' . self::ACTION_LIST_DELETE,     [$this, 'handle_list_delete']);
        add_action('wp_ajax_' . self::ACTION_LIST_RULE_SAVE,    [$this, 'handle_list_rule_save']);
        add_action('wp_ajax_' . self::ACTION_LIST_VISIBILITY_SET, [$this, 'handle_list_visibility_set']);
        add_action('wp_ajax_' . self::ACTION_LIST_RULE_PREVIEW,    [$this, 'handle_list_rule_preview']);
        add_action('wp_ajax_' . self::ACTION_LIST_EXCLUSION_ADD,   [$this, 'handle_list_exclusion_add']);
        add_action('wp_ajax_' . self::ACTION_LIST_EXCLUSION_REMOVE, [$this, 'handle_list_exclusion_remove']);
        add_action('wp_ajax_' . self::ACTION_LIST_EXCLUSIONS_LIST, [$this, 'handle_list_exclusions_list']);
        add_action('wp_ajax_' . self::ACTION_SETTING_SAVE,    [$this, 'handle_setting_save']);
        add_action('wp_ajax_' . self::ACTION_SUBSCRIBER_TRASH,   [$this, 'handle_subscriber_trash']);
        add_action('wp_ajax_' . self::ACTION_SUBSCRIBER_RESTORE, [$this, 'handle_subscriber_restore']);
        add_action('wp_ajax_' . self::ACTION_SUBSCRIBER_DELETE,  [$this, 'handle_subscriber_delete']);
        add_action('wp_ajax_' . self::ACTION_SUBSCRIBER_DETAIL,  [$this, 'handle_subscriber_detail']);
        add_action('wp_ajax_' . self::ACTION_SUBSCRIBER_LIST_TOGGLE, [$this, 'handle_subscriber_list_toggle']);
        add_action('wp_ajax_' . self::ACTION_SUBSCRIBERS_IMPORT,    [$this, 'handle_subscribers_import']);
        add_action('wp_ajax_' . self::ACTION_SUBSCRIBER_UPDATE,     [$this, 'handle_subscriber_update']);
        add_action('wp_ajax_' . self::ACTION_SUBSCRIBERS_COLUMNS_PREF, [$this, 'handle_subscribers_columns_pref']);
        add_action('wp_ajax_' . self::ACTION_SUBSCRIBERS_BULK,         [$this, 'handle_subscribers_bulk']);
        add_action('wp_ajax_' . self::ACTION_WC_PRODUCT_SEARCH,        [$this, 'handle_wc_product_search']);
        add_action('wp_ajax_' . self::ACTION_WP_USER_SEARCH,           [$this, 'handle_wp_user_search']);
        add_action('wp_ajax_' . self::ACTION_EMPTY_TRASH,        [$this, 'handle_empty_trash']);
        add_action('wp_ajax_' . self::ACTION_NEWSLETTER_SAVE_META, [$this, 'handle_newsletter_save_meta']);
        add_action('wp_ajax_' . self::ACTION_TEMPLATE_SET_DEFAULT, [$this, 'handle_template_set_default']);
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
            case NewsletterFooter::OPTION_INTRO:
            case NewsletterFooter::OPTION_PREFS_LABEL:
            case NewsletterFooter::OPTION_UNSUB_LABEL:
                // Plain text fields — sanitize_text_field strips HTML
                // tags but keeps `{{token}}` literal (braces aren't
                // touched). Renderer composes the styled markup; the
                // admin never types angle brackets.
                $value = sanitize_text_field(trim((string) $raw));
                break;
            case 'lrob_etk_nl_cold_threshold':
                // Send-count past which a recipient is "cold". 1 = "any
                // send without engagement makes them cold" (way too
                // aggressive); 50 covers a year of weekly sends for the
                // ceiling.
                $value = max(1, min(50, (int) $raw));
                break;
            case 'lrob_etk_nl_reminder_enabled':
            case 'lrob_etk_nl_engagement_counts_opens':
                // Boolean. Default false (Apple MPP image-prefetching
                // poisons the open signal — clicks are the reliable
                // engagement proxy).
                $value = $raw === '1' || $raw === 'true' || $raw === 'on' ? 1 : 0;
                break;
            case 'lrob_etk_nl_tracking_retention_days':
                // 0 disables pruning. Cap at 10 years (3650) — anything
                // longer is just hoarding rows for no benefit.
                $value = max(0, min(3650, (int) $raw));
                break;
            default:
                wp_send_json_error(['message' => __('Unsupported setting.', 'lrob-email-toolkit')], 400);
        }

        update_option($key, $value, false);
        wp_send_json_success(['value' => $value]);
    }

    public function handle_list_create(): void
    {
        $this->guard();
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
        $kind = isset($_POST['kind']) ? sanitize_key(wp_unslash((string) $_POST['kind'])) : ListRepository::KIND_SUBSCRIBERS;
        $provider_slug = isset($_POST['provider']) ? sanitize_key(wp_unslash((string) $_POST['provider'])) : '';
        if ($name === '') {
            wp_send_json_error(['message' => __('List name is required.', 'lrob-email-toolkit')], 400);
        }
        // Users-kind lists need a provider locked in at creation. Validate.
        if ($kind === ListRepository::KIND_USERS) {
            if ($provider_slug === '' || RuleRegistry::get($provider_slug) === null) {
                wp_send_json_error(['message' => __('Pick a rule provider for the WP users list.', 'lrob-email-toolkit')], 400);
            }
        }
        $repo = new ListRepository();
        $id = $repo->insert($name, '', '', $kind);
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Could not create the list (the slug may collide with an existing one).', 'lrob-email-toolkit')], 409);
        }
        // Lock the provider on creation — empty config initially, admin
        // fills it via the in-row config fields.
        if ($kind === ListRepository::KIND_USERS) {
            $provider = RuleRegistry::get($provider_slug);
            $repo->update_rule($id, (string) wp_json_encode([
                'provider' => $provider_slug,
                'config'   => $provider->sanitize_config([]),
            ]));
        }
        $row = $repo->find($id);
        $html = '';
        if (is_array($row)) {
            ob_start();
            ListsPage::render_row($row);
            $html = (string) ob_get_clean();
        }
        wp_send_json_success(['id' => $id, 'name' => $name, 'kind' => $kind, 'html' => $html]);
    }

    public function handle_list_rename(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
        if ($id <= 0 || $name === '') {
            wp_send_json_error(['message' => __('Missing list id or name.', 'lrob-email-toolkit')], 400);
        }
        $repo = new ListRepository();
        $row = $repo->find($id);
        if ($row !== null && ListRepository::is_system($row)) {
            wp_send_json_error(['message' => __('Built-in lists can\'t be renamed.', 'lrob-email-toolkit')], 400);
        }
        $ok = $repo->rename($id, $name);
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
     * Flip a list's visibility (public / private). Private = admin-
     * managed, hidden from subscribers. Public = surfaced on the
     * preferences page where subscribers can self-join/leave.
     * Refuses system lists at the repository layer.
     */
    public function handle_list_visibility_set(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        $value = isset($_POST['visibility']) ? sanitize_key((string) wp_unslash((string) $_POST['visibility'])) : '';
        if ($id <= 0 || !in_array($value, ListRepository::valid_visibilities(), true)) {
            wp_send_json_error(['message' => __('Missing or invalid visibility value.', 'lrob-email-toolkit')], 400);
        }
        $ok = (new ListRepository())->set_visibility($id, $value);
        if (!$ok) {
            wp_send_json_error(['message' => __('Could not change this list\'s visibility (system lists are admin-only).', 'lrob-email-toolkit')], 400);
        }
        wp_send_json_success(['visibility' => $value]);
    }

    /**
     * Persist a list's rule. Payload: { id, provider, config[…] }.
     * Empty provider clears the rule (list becomes manual-only).
     * Unknown provider slug → 400; the sanitiser is the provider's
     * own — server never trusts the raw POST shape.
     */
    public function handle_list_rule_save(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Missing list id.', 'lrob-email-toolkit')], 400);
        }
        $repo = new ListRepository();
        $row = $repo->find($id);
        if ($row !== null && ListRepository::is_system($row)) {
            wp_send_json_error(['message' => __('Built-in lists have a fixed rule.', 'lrob-email-toolkit')], 400);
        }
        // Provider is locked at creation time — read it off the existing
        // rule_json so the admin can never swap providers post-hoc (the
        // editor doesn't expose the option anyway).
        $existing_rule = is_array($row) ? ListRepository::decode_rule((string) ($row['rule_json'] ?? '')) : null;
        $provider_slug = $existing_rule['provider'] ?? '';
        if ($provider_slug === '') {
            wp_send_json_error(['message' => __('This list has no rule provider — it\'s a Subscribers list, not a WP users list.', 'lrob-email-toolkit')], 400);
        }
        $provider = RuleRegistry::get($provider_slug);
        if ($provider === null) {
            wp_send_json_error(['message' => __('Unknown rule provider.', 'lrob-email-toolkit')], 400);
        }
        $raw_config = isset($_POST['config']) && is_array($_POST['config']) ? wp_unslash($_POST['config']) : [];
        $clean = $provider->sanitize_config($raw_config);
        $payload = (string) wp_json_encode([
            'provider' => $provider_slug,
            'config'   => $clean,
        ]);
        $repo->update_rule($id, $payload);
        $resolved = $provider->resolve_user_ids($clean);
        wp_send_json_success([
            'provider'      => $provider_slug,
            'config'        => $clean,
            'resolved_count' => count($resolved),
        ]);
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

    public function handle_subscriber_detail(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Missing subscriber id.', 'lrob-email-toolkit')], 400);
        }
        $row = (new SubscriberRepository())->find_by_id($id);
        if ($row === null) {
            wp_send_json_error(['message' => __('Subscriber not found.', 'lrob-email-toolkit')], 404);
        }
        $page = new SubscribersPage(new SubscriberRepository());
        ob_start();
        $page->render_detail_body($row);
        $html = (string) ob_get_clean();
        wp_send_json_success([
            'id'     => $id,
            'status' => (string) ($row['status'] ?? ''),
            'title'  => $page->detail_title($row),
            'html'   => $html,
        ]);
    }

    /**
     * Toggle a single (subscriber, list) membership. Payload: { id, list_id, add }.
     * `add=1` calls add_member (idempotent via UNIQUE key), `add=0` removes.
     * Used by the per-checkbox handler in the subscriber detail modal —
     * no all-at-once save UX, each click is its own request.
     */
    /**
     * Flip the active template for a given purpose. Payload: { template_id }.
     * The purpose is read off the template's meta — the front-end never
     * has to send it, and the server can't be tricked into setting a
     * template as active for a purpose it doesn't belong to.
     */
    public function handle_template_set_default(): void
    {
        $this->guard();
        $id = isset($_POST['template_id']) ? (int) wp_unslash((string) $_POST['template_id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Missing template id.', 'lrob-email-toolkit')], 400);
        }
        $ok = (new TemplateRepository())->set_default_for_purpose($id);
        if (!$ok) {
            wp_send_json_error(['message' => __('Could not update the default template.', 'lrob-email-toolkit')], 400);
        }
        wp_send_json_success();
    }

    /**
     * Import subscribers from a paste or a CSV upload. Payload:
     *   - `rows[]`: array of `[email, name, first_name, last_name, phone,
     *     gender, language, address_line, address_line2, address_postcode,
     *     address_city, address_region, address_country]` shaped rows
     *     (paste mode populates email+name only; CSV mode hydrates any
     *     subset of headers that match `SubscriberFields::PROFILE_COLUMNS`).
     *   - `list_ids[]`: optional list ids to add every imported subscriber to.
     *   - `status`: `pending` (default — sends confirmation email)
     *               or `confirmed` (admin attests these are opted-in already).
     *
     * Security: every column passes through `SubscriberFields::sanitize`,
     * which whitelists the column against PROFILE_COLUMNS + applies the
     * per-column sanitiser (sanitize_email for email, ENUM check for
     * gender, ISO-2 cap for country, sanitize_text_field for the rest).
     * Unknown row keys are silently ignored — the import can never write
     * arbitrary columns to the subscribers table.
     *
     * Each row: if email already exists, merge into the existing row;
     * else create a new pending row. Returns
     * `{added, updated, skipped, errors}` counts.
     */
    public function handle_subscribers_import(): void
    {
        $this->guard();
        $raw_rows = isset($_POST['rows']) && is_array($_POST['rows']) ? wp_unslash($_POST['rows']) : [];
        $list_ids = isset($_POST['list_ids']) && is_array($_POST['list_ids'])
            ? array_values(array_unique(array_map('intval', wp_unslash($_POST['list_ids']))))
            : [];
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash((string) $_POST['status'])) : 'pending';
        if (!in_array($status, ['pending', 'confirmed'], true)) {
            $status = 'pending';
        }
        $source = isset($_POST['source']) ? sanitize_key(wp_unslash((string) $_POST['source'])) : 'admin_import';

        $subs = new SubscriberRepository();
        $lists = new ListRepository();
        $valid_list_ids = [];
        foreach ($list_ids as $lid) {
            if ($lid > 0 && $lists->find($lid) !== null) {
                $valid_list_ids[] = $lid;
            }
        }

        // Profile-column whitelist: every row's keys are intersected
        // with PROFILE_COLUMNS before anything is written. Email + name
        // are extracted explicitly since they drive insert_pending(); the
        // rest fan out via set_profile_field() per row.
        $profile_cols = \LRob\EmailToolkit\Modules\Newsletter\SubscriberFields::PROFILE_COLUMNS;

        $added = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        foreach ($raw_rows as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }
            $email = sanitize_email((string) ($row['email'] ?? ''));
            $name = sanitize_text_field((string) ($row['name'] ?? ''));
            if ($email === '' || !is_email($email)) {
                $skipped++;
                continue;
            }
            $existing = $subs->find_by_email($email);
            if ($existing !== null) {
                $id = (int) $existing['id'];
                $current_status = (string) ($existing['status'] ?? '');
                if (in_array($current_status, ['trashed', 'refused', 'unsubscribed', 'bounced'], true)) {
                    $subs->reset_to_pending($id);
                }
                if ($status === 'confirmed' && $current_status !== 'confirmed') {
                    $subs->update_status($id, 'confirmed', current_time('mysql', true));
                }
                $updated++;
            } else {
                $id = $subs->insert_pending($email, $name, $source);
                if ($id <= 0) {
                    $errors[] = $email;
                    continue;
                }
                if ($status === 'confirmed') {
                    $subs->update_status($id, 'confirmed', current_time('mysql', true));
                }
                $added++;
            }
            // Fan-out extra profile fields. Every column passes through
            // set_profile_field() which whitelists vs PROFILE_COLUMNS +
            // runs the per-column sanitiser. email + name already handled.
            foreach ($profile_cols as $col) {
                if ($col === 'email' || $col === 'name') continue;
                if (!isset($row[$col])) continue;
                $val = (string) $row[$col];
                if ($val === '') continue;
                $subs->set_profile_field($id, $col, $val);
            }
            foreach ($valid_list_ids as $lid) {
                $lists->add_member($lid, 'subscriber', $id);
            }
        }

        wp_send_json_success([
            'added'   => $added,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
        ]);
    }

    /**
     * Live preview of a rule's resolved-user set without persisting.
     * Used by the list modal's "Preview matches" button so admins see
     * what they're about to send to before saving. Payload identical to
     * the save endpoint: { provider, config[…] }. Returns `total` + a
     * sample of `(id, display_name, user_email)` capped at PREVIEW_LIMIT
     * so a 50K-user site doesn't ship a 50K-row JSON blob.
     */
    public function handle_list_rule_preview(): void
    {
        $this->guard();
        $provider_slug = isset($_POST['provider']) ? sanitize_key(wp_unslash((string) $_POST['provider'])) : '';
        if ($provider_slug === '') {
            wp_send_json_success(['total' => 0, 'sample' => []]);
        }
        $provider = RuleRegistry::get($provider_slug);
        if ($provider === null) {
            wp_send_json_error(['message' => __('Unknown rule provider.', 'lrob-email-toolkit')], 400);
        }
        $raw_config = isset($_POST['config']) && is_array($_POST['config']) ? wp_unslash($_POST['config']) : [];
        $clean = $provider->sanitize_config($raw_config);
        $ids = $provider->resolve_user_ids($clean);
        $total = count($ids);
        $limit = max(1, min(200, isset($_POST['limit']) ? (int) $_POST['limit'] : 20));
        $offset = max(0, isset($_POST['offset']) ? (int) $_POST['offset'] : 0);
        $page_ids = array_slice($ids, $offset, $limit);
        $sample = [];
        if ($page_ids !== []) {
            $users = get_users([
                'include' => $page_ids,
                'fields'  => ['ID', 'display_name', 'user_email'],
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'number'  => $limit,
            ]);
            foreach (is_array($users) ? $users : [] as $u) {
                $sample[] = [
                    'id'    => (int) $u->ID,
                    'name'  => (string) $u->display_name,
                    'email' => (string) $u->user_email,
                ];
            }
        }
        wp_send_json_success([
            'total'   => $total,
            'sample'  => $sample,
            'limit'   => $limit,
            'offset'  => $offset,
            'hasMore' => ($offset + $limit) < $total,
        ]);
    }

    /**
     * Add one or more WP users to the list's exclusion set. Accepts:
     *   - `user_ids[]` (numeric IDs), and/or
     *   - `emails` (textarea, one per line — resolved to user IDs).
     * Unknown emails are silently dropped; the response carries
     * `{added, missing}` so the admin sees which emails didn't match.
     */
    public function handle_list_exclusion_add(): void
    {
        $this->guard();
        $list_id = isset($_POST['list_id']) ? (int) wp_unslash((string) $_POST['list_id']) : 0;
        if ($list_id <= 0) {
            wp_send_json_error(['message' => __('Missing list id.', 'lrob-email-toolkit')], 400);
        }
        $repo = new ListRepository();
        $user_ids = isset($_POST['user_ids']) && is_array($_POST['user_ids'])
            ? array_values(array_unique(array_map('intval', wp_unslash($_POST['user_ids']))))
            : [];
        $emails_raw = isset($_POST['emails']) ? (string) wp_unslash((string) $_POST['emails']) : '';
        $missing = [];
        if ($emails_raw !== '') {
            $emails = array_filter(array_map('trim', preg_split('/[\s,;]+/', $emails_raw) ?: []));
            foreach ($emails as $email) {
                $clean = sanitize_email($email);
                if ($clean === '' || !is_email($clean)) {
                    $missing[] = $email;
                    continue;
                }
                $user = get_user_by('email', $clean);
                if ($user instanceof \WP_User) {
                    $user_ids[] = (int) $user->ID;
                } else {
                    $missing[] = $clean;
                }
            }
        }
        $user_ids = array_values(array_unique(array_filter($user_ids, static fn ($n) => $n > 0)));
        $added = 0;
        foreach ($user_ids as $uid) {
            $repo->add_exclusion($list_id, $uid, 'admin');
            $added++;
        }
        wp_send_json_success(['added' => $added, 'missing' => $missing, 'total' => count($repo->list_exclusions($list_id))]);
    }

    public function handle_list_exclusion_remove(): void
    {
        $this->guard();
        $list_id = isset($_POST['list_id']) ? (int) wp_unslash((string) $_POST['list_id']) : 0;
        $user_id = isset($_POST['user_id']) ? (int) wp_unslash((string) $_POST['user_id']) : 0;
        if ($list_id <= 0 || $user_id <= 0) {
            wp_send_json_error(['message' => __('Missing list/user id.', 'lrob-email-toolkit')], 400);
        }
        (new ListRepository())->remove_exclusion($list_id, $user_id);
        wp_send_json_success();
    }

    /**
     * Return excluded users for a list — admin UI calls this on
     * "Manage exclusions" open. Returns `[{id, name, email}, ...]`
     * paginated (page 1 = first 200; pagination can grow later).
     */
    public function handle_list_exclusions_list(): void
    {
        $this->guard();
        $list_id = isset($_POST['list_id']) ? (int) wp_unslash((string) $_POST['list_id']) : 0;
        if ($list_id <= 0) {
            wp_send_json_error(['message' => __('Missing list id.', 'lrob-email-toolkit')], 400);
        }
        $ids = (new ListRepository())->list_exclusions($list_id);
        $sample = [];
        if ($ids !== []) {
            $users = get_users([
                'include' => array_slice($ids, 0, 200),
                'fields'  => ['ID', 'display_name', 'user_email'],
                'orderby' => 'display_name',
                'order'   => 'ASC',
            ]);
            foreach (is_array($users) ? $users : [] as $u) {
                $sample[] = [
                    'id'    => (int) $u->ID,
                    'name'  => (string) $u->display_name,
                    'email' => (string) $u->user_email,
                ];
            }
        }
        wp_send_json_success(['total' => count($ids), 'items' => $sample]);
    }

    /**
     * Save the current admin's column-visibility preference for the
     * Subscribers list view. Payload: `columns[]` — slug list. Unknown
     * slugs are dropped against SubscribersPage::AVAILABLE_COLUMNS;
     * empty list reverts to defaults.
     */
    /**
     * Bulk action on a set of subscriber rows. Payload: { op, ids[] }.
     * Ops: trash / restore / delete. Returns the count actually
     * applied (zero on a no-op like restoring a non-trashed row).
     */
    /**
     * AJAX search over WooCommerce products. Payload: { q, ids[] }. When
     * `ids` is set, the endpoint resolves those IDs to their current
     * title/sku (used to hydrate the picker on initial load); else it
     * does a `LIKE` search via wc_get_products. Returns up to 20 hits.
     * Inert (empty result) when WooCommerce isn't active.
     */
    public function handle_wc_product_search(): void
    {
        $this->guard();
        if (!function_exists('wc_get_products')) {
            wp_send_json_success(['items' => []]);
        }
        $ids = isset($_POST['ids']) && is_array($_POST['ids'])
            ? array_values(array_unique(array_map('intval', wp_unslash($_POST['ids']))))
            : [];
        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash((string) $_POST['q'])) : '';
        $args = [
            'limit'   => 20,
            'orderby' => 'title',
            'order'   => 'ASC',
            'status'  => ['publish', 'private'],
        ];
        if ($ids !== []) {
            $args['include'] = $ids;
        } elseif ($q !== '') {
            $args['s'] = $q;
        } else {
            // Browse mode: return the 20 most-recent products as a starting set.
            $args['orderby'] = 'date';
            $args['order']   = 'DESC';
        }
        $products = wc_get_products($args);
        $items = [];
        if (is_array($products)) {
            foreach ($products as $p) {
                if (!is_object($p)) continue;
                $items[] = [
                    'id'    => (int) (method_exists($p, 'get_id') ? $p->get_id() : 0),
                    'name'  => (string) (method_exists($p, 'get_name') ? $p->get_name() : ''),
                    'sku'   => (string) (method_exists($p, 'get_sku') ? $p->get_sku() : ''),
                ];
            }
        }
        wp_send_json_success(['items' => $items]);
    }

    /**
     * AJAX search over WP users — used by the exclusions picker.
     * Payload: { q, ids[] }. With ids: hydrate the chips from existing
     * IDs. With q: WP_User_Query LIKE search on email + display_name.
     * Caps at 20 hits.
     */
    public function handle_wp_user_search(): void
    {
        $this->guard();
        $ids = isset($_POST['ids']) && is_array($_POST['ids'])
            ? array_values(array_unique(array_map('intval', wp_unslash($_POST['ids']))))
            : [];
        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash((string) $_POST['q'])) : '';
        $args = [
            'number'  => 20,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => ['ID', 'display_name', 'user_email'],
        ];
        if ($ids !== []) {
            $args['include'] = $ids;
        } elseif ($q !== '') {
            $args['search']         = '*' . $q . '*';
            $args['search_columns'] = ['user_email', 'user_login', 'display_name'];
        }
        $users = get_users($args);
        $items = [];
        foreach (is_array($users) ? $users : [] as $u) {
            $items[] = [
                'id'    => (int) $u->ID,
                'name'  => (string) $u->display_name,
                'email' => (string) $u->user_email,
            ];
        }
        wp_send_json_success(['items' => $items]);
    }

    public function handle_subscribers_bulk(): void
    {
        $this->guard();
        $op = isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
        $ids = isset($_POST['ids']) && is_array($_POST['ids'])
            ? array_values(array_unique(array_map('intval', wp_unslash($_POST['ids']))))
            : [];
        $ids = array_values(array_filter($ids, static fn ($n) => $n > 0));
        if ($ids === []) {
            wp_send_json_error(['message' => __('Select at least one subscriber.', 'lrob-email-toolkit')], 400);
        }
        if (!in_array($op, ['trash', 'restore', 'delete'], true)) {
            wp_send_json_error(['message' => __('Unknown bulk action.', 'lrob-email-toolkit')], 400);
        }
        $repo = new SubscriberRepository();
        $applied = 0;
        foreach ($ids as $id) {
            switch ($op) {
                case 'trash':
                    $repo->trash($id, 'admin_bulk');
                    $applied++;
                    break;
                case 'restore':
                    if ($repo->restore($id)) {
                        $applied++;
                    }
                    break;
                case 'delete':
                    if ($repo->permanently_delete($id)) {
                        $applied++;
                    }
                    break;
            }
        }
        wp_send_json_success(['applied' => $applied, 'total' => count($ids)]);
    }

    public function handle_subscribers_columns_pref(): void
    {
        $this->guard();
        $raw = isset($_POST['columns']) && is_array($_POST['columns'])
            ? array_map('sanitize_key', wp_unslash($_POST['columns']))
            : [];
        $allowed = array_keys(SubscribersPage::available_columns());
        $clean = array_values(array_intersect($raw, $allowed));
        update_user_meta(get_current_user_id(), SubscribersPage::USER_META_COLUMNS, wp_json_encode($clean));
        wp_send_json_success(['columns' => $clean]);
    }

    public function handle_subscriber_update(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        $repo = new SubscriberRepository();
        // New per-field path: payload `{ id, field, value }`. Whitelisted
        // server-side via SubscriberFields::PROFILE_COLUMNS — caller can
        // never reach outside the profile schema.
        if (isset($_POST['field'])) {
            $field = sanitize_key(wp_unslash((string) $_POST['field']));
            $value = (string) wp_unslash((string) ($_POST['value'] ?? ''));
            $result = $repo->set_profile_field($id, $field, $value);
        } else {
            // Legacy path: name + email together.
            $email = isset($_POST['email']) ? wp_unslash((string) $_POST['email']) : '';
            $name = isset($_POST['name']) ? wp_unslash((string) $_POST['name']) : '';
            $result = $repo->update_basics($id, $email, $name);
        }
        if ($result === 'ok') {
            wp_send_json_success();
        }
        $messages = [
            'email_taken' => __('That email already belongs to another subscriber.', 'lrob-email-toolkit'),
            'invalid'     => __('Invalid value.', 'lrob-email-toolkit'),
            'noop'        => __('Subscriber not found.', 'lrob-email-toolkit'),
        ];
        wp_send_json_error(['message' => $messages[$result] ?? __('Could not update.', 'lrob-email-toolkit')], 400);
    }

    public function handle_subscriber_list_toggle(): void
    {
        $this->guard();
        $id = isset($_POST['id']) ? (int) wp_unslash((string) $_POST['id']) : 0;
        $list_id = isset($_POST['list_id']) ? (int) wp_unslash((string) $_POST['list_id']) : 0;
        $add = isset($_POST['add']) ? (string) wp_unslash((string) $_POST['add']) : '';
        if ($id <= 0 || $list_id <= 0) {
            wp_send_json_error(['message' => __('Missing subscriber or list id.', 'lrob-email-toolkit')], 400);
        }
        $subscriber = (new SubscriberRepository())->find_by_id($id);
        $list = (new ListRepository())->find($list_id);
        if ($subscriber === null || $list === null) {
            wp_send_json_error(['message' => __('Subscriber or list not found.', 'lrob-email-toolkit')], 404);
        }
        $repo = new ListRepository();
        if ($add === '1' || $add === 'true' || $add === 'on') {
            $repo->add_member($list_id, 'subscriber', $id);
        } else {
            $repo->remove_member($list_id, 'subscriber', $id);
        }
        wp_send_json_success();
    }

    /**
     * Per-newsletter-card meta save. Mirrors handle_save_meta but
     * scoped to the NewsletterCPT and its meta vocabulary. Two
     * special keys: `title` writes post_title, and `target_kind` /
     * `target_list_id` co-write the JSON-shaped META_TARGET_SPEC.
     */
    public function handle_newsletter_save_meta(): void
    {
        $this->guard();
        $newsletter_id = isset($_POST['newsletter_id']) ? (int) wp_unslash((string) $_POST['newsletter_id']) : 0;
        $post = $newsletter_id > 0 ? get_post($newsletter_id) : null;
        if (!$post instanceof \WP_Post || $post->post_type !== NewsletterCPT::POST_TYPE) {
            wp_send_json_error(['message' => __('Newsletter not found.', 'lrob-email-toolkit')], 404);
        }

        $key = isset($_POST['key']) ? sanitize_key(wp_unslash((string) $_POST['key'])) : '';
        $raw_value = $_POST['value'] ?? '';
        $value = is_array($raw_value)
            ? array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', wp_unslash($raw_value))
            : wp_unslash((string) $raw_value);

        if (!in_array($key, self::WHITELIST_NEWSLETTER_KEYS, true)) {
            wp_send_json_error(['message' => __('Unknown newsletter setting key.', 'lrob-email-toolkit')], 400);
        }

        // Title isn't post_meta — writes to wp_posts.post_title.
        if ($key === 'title') {
            wp_update_post([
                'ID'         => $newsletter_id,
                'post_title' => sanitize_text_field(is_array($value) ? '' : $value),
            ]);
            wp_send_json_success();
        }

        // target_list_ids: multi-list audience picker. Value is a
        // comma-separated list of list IDs (`"3,7,12"` or `""`). The
        // server validates each ID against `lists.id` and writes the
        // canonical META_TARGET_SPEC shape `{kind: 'lists', list_ids: [...]}`.
        // Empty list_ids → drop back to `{kind: 'all'}` (legacy "everyone").
        if ($key === 'target_list_ids') {
            $raw = is_array($value) ? '' : (string) $value;
            $ids = [];
            if ($raw !== '') {
                foreach (preg_split('/[\s,]+/', $raw) ?: [] as $piece) {
                    $n = (int) $piece;
                    if ($n > 0) $ids[] = $n;
                }
            }
            $ids = array_values(array_unique($ids));
            if ($ids === []) {
                // Empty selection = no recipients (forces a conscious
                // pick before send). Default-everyone was confusing.
                update_post_meta($newsletter_id, NewsletterCPT::META_TARGET_SPEC, (string) wp_json_encode([
                    'kind'     => NewsletterCPT::TARGET_KIND_LISTS,
                    'list_ids' => [],
                ]));
                wp_send_json_success();
            }
            // Drop unknown IDs against the lists table.
            global $wpdb;
            $tbl = \LRob\EmailToolkit\Modules\Newsletter\Schema::lists_table();
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $known = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM `$tbl` WHERE id IN ($placeholders)",
                ...$ids
            ));
            $ids = array_values(array_intersect($ids, array_map('intval', $known)));
            update_post_meta($newsletter_id, NewsletterCPT::META_TARGET_SPEC, (string) wp_json_encode([
                'kind'     => NewsletterCPT::TARGET_KIND_LISTS,
                'list_ids' => $ids,
            ]));
            wp_send_json_success();
        }

        // target_audience: single-pick dropdown vocab — `all` /
        // `list:<id>`. Unpacks into the canonical kind + list_id pair.
        if ($key === 'target_audience') {
            $raw = is_array($value) ? '' : (string) $value;
            $kind = NewsletterCPT::TARGET_KIND_ALL;
            $list_id = 0;
            if ($raw === '' || $raw === 'all') {
                $kind = NewsletterCPT::TARGET_KIND_ALL;
            } elseif (strpos($raw, 'list:') === 0) {
                $kind = NewsletterCPT::TARGET_KIND_LIST;
                $list_id = (int) substr($raw, 5);
            }
            $spec = ['kind' => $kind];
            if ($kind === NewsletterCPT::TARGET_KIND_LIST) {
                $spec['list_id'] = $list_id;
            }
            update_post_meta($newsletter_id, NewsletterCPT::META_TARGET_SPEC, (string) wp_json_encode($spec));
            wp_send_json_success();
        }

        // target_kind / target_list_id co-write the JSON-shaped
        // META_TARGET_SPEC. Read the other piece off the existing
        // meta so a single-field update doesn't blank the other.
        if ($key === 'target_kind' || $key === 'target_list_id') {
            $current_raw = (string) get_post_meta($newsletter_id, NewsletterCPT::META_TARGET_SPEC, true);
            $current = $current_raw !== '' ? (array) json_decode($current_raw, true) : [];
            $kind = (string) ($current['kind'] ?? NewsletterCPT::TARGET_KIND_ALL);
            $list_id = isset($current['list_id']) ? (int) $current['list_id'] : 0;
            if ($key === 'target_kind') {
                $kind = sanitize_key(is_array($value) ? '' : (string) $value);
            } else {
                $list_id = (int) (is_array($value) ? 0 : $value);
            }
            $allowed = [
                NewsletterCPT::TARGET_KIND_ALL,
                NewsletterCPT::TARGET_KIND_ALL_USERS,
                NewsletterCPT::TARGET_KIND_ALL_SUBSCRIBERS,
                NewsletterCPT::TARGET_KIND_LIST,
            ];
            if (!in_array($kind, $allowed, true)) {
                $kind = NewsletterCPT::TARGET_KIND_ALL;
            }
            $spec = ['kind' => $kind];
            if ($kind === NewsletterCPT::TARGET_KIND_LIST) {
                $spec['list_id'] = $list_id;
            }
            update_post_meta($newsletter_id, NewsletterCPT::META_TARGET_SPEC, (string) wp_json_encode($spec));
            wp_send_json_success();
        }

        // Scheduled-at: input is local datetime-local (no tz), convert
        // to UTC for storage. We persist the date silently — the
        // status only flips to `scheduled` when the admin explicitly
        // clicks the Schedule button (SendAjaxController::handle_commit_schedule).
        // The only auto-status-change here is the *de-schedule*: clearing
        // the date when status is already `scheduled` flips back to `draft`.
        if ($key === NewsletterCPT::META_SCHEDULED_AT) {
            $raw = is_array($value) ? '' : trim((string) $value);
            if ($raw === '') {
                update_post_meta($newsletter_id, $key, '');
                $this->maybe_unschedule($newsletter_id);
                wp_send_json_success();
            }
            $ts = strtotime($raw . ' ' . wp_timezone_string());
            $stored = $ts === false ? '' : gmdate('Y-m-d H:i:s', $ts);
            update_post_meta($newsletter_id, $key, $stored);
            wp_send_json_success();
        }

        // Per-key sanitisation.
        switch ($key) {
            case NewsletterCPT::META_PREVIEW_TEXT:
            case NewsletterCPT::META_FROM_NAME_OVERRIDE:
                update_post_meta($newsletter_id, $key, is_array($value) ? '' : sanitize_text_field((string) $value));
                break;
            case NewsletterCPT::META_REPLY_TO_OVERRIDE:
                update_post_meta($newsletter_id, $key, is_array($value) ? '' : sanitize_email((string) $value));
                break;
            case NewsletterCPT::META_SMTP_IDENTITY:
                update_post_meta($newsletter_id, $key, is_array($value) ? 0 : (int) $value);
                break;
            case NewsletterCPT::META_TRACK_OPENS:
            case NewsletterCPT::META_TRACK_CLICKS:
            case NewsletterCPT::META_LOG_ALL_SENDS:
                update_post_meta($newsletter_id, $key, !empty($value) && $value !== '0');
                break;
            default:
                wp_send_json_error(['message' => __('Unsupported newsletter setting.', 'lrob-email-toolkit')], 400);
        }

        wp_send_json_success();
    }

    /**
     * If the newsletter is currently `scheduled` and the admin cleared
     * the date, drop it back to `draft`. Only writes when status is
     * still pre-send; refuses to clobber a running send.
     *
     * Promotion (draft → scheduled) is **not** automatic — it requires
     * an explicit click on the Schedule button, handled by
     * SendAjaxController::handle_commit_schedule. Auto-promotion was
     * confusing: setting the date silently committed the schedule, and
     * the button click became a no-op.
     */
    private function maybe_unschedule(int $post_id): void
    {
        $repo = new NewsletterRepository();
        $row = $repo->find_by_post_id($post_id);
        $current = (string) ($row['status'] ?? NewsletterRepository::STATUS_DRAFT);
        if ($current === NewsletterRepository::STATUS_SCHEDULED) {
            $repo->update_status($post_id, NewsletterRepository::STATUS_DRAFT);
        }
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
