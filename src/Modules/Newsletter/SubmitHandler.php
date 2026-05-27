<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Forms\FormStructure;
use LRob\EmailToolkit\Forms\Honeypot;
use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\Captcha\Routing as CaptchaRouting;
use LRob\EmailToolkit\Plugin;
use LRob\EmailToolkit\Support\Events;

/**
 * AJAX endpoint that handles every subscribe-form submission. Hangs
 * off both `wp_ajax_<ACTION>` and `wp_ajax_nopriv_<ACTION>` — most
 * submissions come from logged-out visitors.
 *
 * Pipeline order matters (cheap checks first):
 *   1. Nonce.
 *   2. Form exists + published.
 *   3. Honeypot — silent success so bots can't tell they were caught.
 *   4. Time-trap — same silent success.
 *   5. Captcha verify (newsletter_subscribe context + per-form route).
 *   6. Email validation.
 *   7. Recipient resolution + state transition:
 *        - WP user with this email → opt them in directly (already
 *          email-verified at registration; no double-opt-in needed).
 *        - Existing subscriber, status=confirmed → silent success
 *          (anti-enumeration; we don't reveal that this email is
 *          already on the list).
 *        - Existing subscriber, any other status → reset to pending,
 *          regenerate token, dispatch confirmation email.
 *        - No match → create new pending subscriber row, dispatch.
 *   8. Return JSON with the user-facing success message.
 *
 * Newsletter-side honeypot + time-trap always-on; no per-form
 * override yet. Captcha override IS per-form via FormCPT::META_CAPTCHA_ROUTE.
 */
final class SubmitHandler
{
    public const ACTION = 'lrob_etk_nl_subscribe';

    public const NONCE_ACTION = 'lrob_etk_nl_subscribe';

    /** Minimum seconds a human takes to fill a subscribe form. Below = bot. */
    private const MIN_FORM_TIME_SECONDS = 2;

    public function __construct(
        private SubscriberRepository $subscribers,
        private ListRepository $lists,
        private CategoryRepository $categories,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION,        [$this, 'handle']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        $post = wp_unslash($_POST);

        $nonce = isset($post['_wpnonce']) ? (string) $post['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error([
                'message' => __('This form has expired. Please reload the page and try again.', 'lrob-email-toolkit'),
            ], 400);
        }

        $form_id = isset($post['_lrob_etk_nl_form_id']) ? (int) $post['_lrob_etk_nl_form_id'] : 0;
        $form = $form_id > 0 ? get_post($form_id) : null;
        if (!$form instanceof \WP_Post || $form->post_type !== FormCPT::POST_TYPE || $form->post_status !== 'publish') {
            wp_send_json_error(['message' => __('Subscribe form not found.', 'lrob-email-toolkit')], 404);
        }

        $instance = isset($post['_lrob_etk_nl_instance']) ? (string) $post['_lrob_etk_nl_instance'] : '';

        // Honeypot tripped → silent success. The bot sees a success
        // response indistinguishable from a real submission, so it
        // can't tell its trick was detected.
        if (Honeypot::tripped($post)) {
            self::respond_success($form_id);
        }

        // Time-trap: forms submitted impossibly fast are bots.
        $started = isset($post['_lrob_etk_nl_started']) ? (int) $post['_lrob_etk_nl_started'] : 0;
        if ($started > 0 && (time() - $started) < self::MIN_FORM_TIME_SECONDS) {
            self::respond_success($form_id);
        }

        // Captcha. Per-form override stored on the form post; default
        // to context routing if empty.
        $captcha_service = $this->captcha_service();
        if ($captcha_service !== null) {
            $per_form_route = (string) get_post_meta($form_id, FormCPT::META_CAPTCHA_ROUTE, true);
            $captcha_context = [
                'context' => CaptchaRouting::CONTEXT_NEWSLETTER,
                'form_id' => $form_id,
            ];
            if ($per_form_route !== '') {
                $captcha_context['force_route'] = $per_form_route;
            }
            [$captcha_ok, $captcha_error] = $captcha_service->verify($post, $captcha_context);
            if (!$captcha_ok) {
                wp_send_json_error([
                    'message' => $captcha_error !== null
                        ? (string) $captcha_error
                        : __('Anti-spam check failed. Please try again.', 'lrob-email-toolkit'),
                ], 400);
            }
        }

        // Field values: lrob_etk_nl[<instance>][<slug>].
        $field_values = self::extract_field_values($post, $instance);
        $email = self::pick_email_field($form_id, $field_values);
        if ($email === '' || !is_email($email)) {
            wp_send_json_error([
                'message'     => __('Please enter a valid email address.', 'lrob-email-toolkit'),
                'fieldErrors' => ['email' => __('Please enter a valid email address.', 'lrob-email-toolkit')],
            ], 400);
        }
        $name = self::pick_name_field($form_id, $field_values);
        $mapped_profile = self::extract_mapped_profile($form_id, $field_values);

        // Picker values: which lists this subscriber wants to join +
        // which categories they want (anything they tick they want;
        // unticked = opt out). Resolved against the form's structure
        // so unknown slugs / ids are dropped.
        $chosen_lists = self::extract_chosen_lists($form_id, $field_values);
        $chosen_categories = self::extract_chosen_categories($form_id, $field_values);
        $has_category_picker = self::form_has_field($form_id, 'category_picker');

        // Recipient resolution.
        $wp_user = get_user_by('email', $email);
        if ($wp_user instanceof \WP_User) {
            $this->opt_in_wp_user($wp_user, $form_id, $chosen_lists, $chosen_categories, $has_category_picker);
            self::respond_success($form_id, __('Welcome back! You\'re subscribed.', 'lrob-email-toolkit'));
        }

        $existing = $this->subscribers->find_by_email($email);
        if (is_array($existing)) {
            $status = (string) ($existing['status'] ?? '');
            if ($status === 'confirmed') {
                // Anti-enumeration — same response shape as a fresh
                // signup, so an attacker can't tell who's already on
                // the list. We don't re-send a confirmation since they
                // already are confirmed.
                self::respond_success($form_id);
            }
            $subscriber_id = (int) $existing['id'];
            $this->subscribers->reset_to_pending($subscriber_id);
            $this->write_mapped_profile($subscriber_id, $mapped_profile);
            $this->apply_subscriber_preferences($subscriber_id, $form_id, $chosen_lists, $chosen_categories, $has_category_picker);
            $this->dispatch_confirmation($subscriber_id, $email, $name, $form_id);
            Events::dispatch('newsletter.subscriber.resubscribed', [
                'subscriber_id'   => $subscriber_id,
                'email'           => $email,
                'previous_status' => $status,
                'form_id'         => $form_id,
            ]);
            self::respond_success($form_id);
        }

        // Fresh signup.
        $source = 'form:' . $form_id;
        $language = self::detect_language();
        $new_id = $this->subscribers->insert_pending($email, $name, $source, $language);
        if ($new_id <= 0) {
            wp_send_json_error([
                'message' => __('Could not record your subscription. Please try again.', 'lrob-email-toolkit'),
            ], 500);
        }
        $this->write_mapped_profile($new_id, $mapped_profile);
        $this->apply_subscriber_preferences($new_id, $form_id, $chosen_lists, $chosen_categories, $has_category_picker);
        $this->dispatch_confirmation($new_id, $email, $name, $form_id);
        Events::dispatch('newsletter.subscriber.added', [
            'subscriber_id' => $new_id,
            'email'         => $email,
            'source'        => $source,
            'form_id'       => $form_id,
        ]);
        self::respond_success($form_id);
    }

    /**
     * Opt a WP user into the newsletter — they already validated their
     * email at registration time, so we don't force them through
     * double-opt-in. Applies any list-picker / category-picker choices
     * immediately. Fires `newsletter.subscriber.confirmed`.
     *
     * @param array<int, int>    $chosen_lists List IDs ticked in a list_picker field.
     * @param array<int, string> $chosen_categories Category slugs ticked in a category_picker field.
     */
    private function opt_in_wp_user(
        \WP_User $user,
        int $form_id,
        array $chosen_lists,
        array $chosen_categories,
        bool $has_category_picker
    ): void {
        $user_id = (int) $user->ID;
        update_user_meta($user_id, UserMeta::OPTED_IN, '1');
        update_user_meta($user_id, UserMeta::STATUS, UserMeta::STATUS_ACTIVE);
        $existing_confirmed_at = (string) get_user_meta($user_id, UserMeta::CONFIRMED_AT, true);
        if ($existing_confirmed_at === '') {
            update_user_meta($user_id, UserMeta::CONFIRMED_AT, current_time('mysql', true));
        }
        if ((string) get_user_meta($user_id, UserMeta::PREFS_TOKEN, true) === '') {
            update_user_meta($user_id, UserMeta::PREFS_TOKEN, UserMeta::generate_prefs_token());
        }
        update_user_meta($user_id, UserMeta::SOURCE, 'form:' . $form_id);

        // Apply explicit list memberships from the picker, plus the
        // form's default list if no picker was present.
        $list_ids = $this->resolve_list_membership($form_id, $chosen_lists);
        foreach ($list_ids as $list_id) {
            $this->lists->add_member($list_id, UserMeta::KIND_USER, $user_id);
        }

        // Category opt-outs only computed when the picker was on the
        // form. Without a picker, the WP user inherits the global
        // default (opted in to everything) which is no opt-outs.
        if ($has_category_picker) {
            $opt_outs = $this->compute_opt_outs($chosen_categories);
            update_user_meta($user_id, UserMeta::CATEGORY_OPT_OUTS, (string) wp_json_encode($opt_outs));
        }

        Events::dispatch('newsletter.subscriber.confirmed', [
            'recipient_kind' => UserMeta::KIND_USER,
            'recipient_id'   => $user_id,
            'email'          => (string) $user->user_email,
            'form_id'        => $form_id,
            'via'            => 'wp_user_optin',
        ]);
    }

    /**
     * Persist list memberships + category opt-outs for a subscriber
     * row. Called after insert / reset_to_pending in the pending-flow
     * path. The subscriber's `category_opt_outs` JSON column is
     * updated directly; list_members rows are inserted via the
     * repository (idempotent on the UNIQUE key).
     *
     * @param array<int, int>    $chosen_lists
     * @param array<int, string> $chosen_categories
     */
    /**
     * Fan out a mapped-profile payload onto the subscriber row via the
     * whitelisted set_profile_field path. Skips the empty entries that
     * are already filtered by extract_mapped_profile.
     *
     * @param array<string, string> $mapped
     */
    private function write_mapped_profile(int $subscriber_id, array $mapped): void
    {
        if ($mapped === [] || $subscriber_id <= 0) {
            return;
        }
        foreach ($mapped as $column => $value) {
            $this->subscribers->set_profile_field($subscriber_id, $column, $value);
        }
    }

    private function apply_subscriber_preferences(
        int $subscriber_id,
        int $form_id,
        array $chosen_lists,
        array $chosen_categories,
        bool $has_category_picker
    ): void {
        $list_ids = $this->resolve_list_membership($form_id, $chosen_lists);
        foreach ($list_ids as $list_id) {
            $this->lists->add_member($list_id, UserMeta::KIND_SUBSCRIBER, $subscriber_id);
        }
        if ($has_category_picker) {
            $opt_outs = $this->compute_opt_outs($chosen_categories);
            global $wpdb;
            $wpdb->update(
                Schema::subscribers_table(),
                ['category_opt_outs' => (string) wp_json_encode($opt_outs)],
                ['id' => $subscriber_id],
                ['%s'],
                ['%d']
            );
        }
    }

    /**
     * Resolve the list ids to add the new subscriber to:
     *   1. Explicit picker ticks, intersected with existing list ids.
     *   2. Form's default list (META_DEFAULT_LIST_ID) — only when the
     *      submission had no picker selections (empty $chosen_lists).
     *      "Form had a picker but visitor ticked nothing" defers to
     *      the visitor's choice; "form had no picker, admin set a
     *      default" uses that default.
     *
     * @param array<int, int> $chosen_lists
     * @return array<int, int>
     */
    private function resolve_list_membership(int $form_id, array $chosen_lists): array
    {
        if ($chosen_lists !== []) {
            return $chosen_lists;
        }
        $default = (int) get_post_meta($form_id, FormCPT::META_DEFAULT_LIST_ID, true);
        if ($default <= 0) {
            return [];
        }
        return $this->lists->find($default) !== null ? [$default] : [];
    }

    /**
     * Build the category_opt_outs payload from a list of *ticked*
     * category slugs. opt_outs = all categories minus the ones the
     * subscriber chose.
     *
     * @param array<int, string> $chosen_slugs
     * @return array<int, string>
     */
    private function compute_opt_outs(array $chosen_slugs): array
    {
        $all_slugs = array_keys($this->categories->slug_label_map());
        return array_values(array_diff($all_slugs, $chosen_slugs));
    }

    private function dispatch_confirmation(int $subscriber_id, string $email, string $name, int $form_id): void
    {
        $dispatcher = new ConfirmationDispatcher();
        $dispatcher->send($subscriber_id, $email, $name, $form_id);
    }

    /**
     * Pull `lrob_etk_nl[<instance>][<slug>]` values into a flat
     * slug → value array. Drops anything not a scalar (defensive
     * against poisoned arrays in $_POST).
     *
     * @return array<string, mixed>
     */
    private static function extract_field_values(array $post, string $instance): array
    {
        $raw = $post[FormCPT::FIELD_NAME_PREFIX] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $by_instance = $raw[$instance] ?? null;
        if (!is_array($by_instance)) {
            return [];
        }
        $out = [];
        foreach ($by_instance as $slug => $value) {
            if (!is_string($slug)) {
                continue;
            }
            $key = sanitize_key($slug);
            if ($key === '') {
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Find the first email-type field's value in the submission. Walks
     * the form's structure to identify which slug is the email field
     * (typically `email` but admins can rename it).
     */
    private static function pick_email_field(int $form_id, array $field_values): string
    {
        $structure = FormStructure::load($form_id);
        foreach ($structure['rows'] as $row) {
            foreach ($row['columns'] as $col) {
                foreach ($col['fields'] as $field) {
                    if (($field['type'] ?? '') !== 'email') {
                        continue;
                    }
                    $slug = (string) ($field['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $val = $field_values[$slug] ?? '';
                    return is_string($val) ? trim($val) : '';
                }
            }
        }
        // No email field declared — try the conventional `email` key
        // as a last-ditch.
        $val = $field_values['email'] ?? '';
        return is_string($val) ? trim($val) : '';
    }

    /**
     * Optional name field. Honours an explicit `maps_to=name`
     * declaration first (the form-builder's "Maps to subscriber field"
     * picker), then falls back to the first text-type field's value.
     */
    private static function pick_name_field(int $form_id, array $field_values): string
    {
        $mapped = self::extract_mapped_profile($form_id, $field_values);
        if (isset($mapped['name']) && $mapped['name'] !== '') {
            return $mapped['name'];
        }
        // Fallback: first text field's value (legacy).
        $structure = FormStructure::load($form_id);
        foreach ($structure['rows'] as $row) {
            foreach ($row['columns'] as $col) {
                foreach ($col['fields'] as $field) {
                    if (($field['type'] ?? '') !== 'text') {
                        continue;
                    }
                    $slug = (string) ($field['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $val = $field_values[$slug] ?? '';
                    if (is_string($val) && trim($val) !== '') {
                        return sanitize_text_field(trim($val));
                    }
                }
            }
        }
        return '';
    }

    /**
     * Walk the form structure for every field carrying a `maps_to`
     * attribute and return `column => sanitised value` for each
     * non-empty submission. Whitelisted server-side against the canonical
     * `SubscriberFields::PROFILE_COLUMNS`. The email field is excluded
     * here — it's resolved separately via `pick_email_field` (which is
     * the actual signup gate).
     *
     * @return array<string, string>
     */
    private static function extract_mapped_profile(int $form_id, array $field_values): array
    {
        $structure = FormStructure::load($form_id);
        $out = [];
        foreach ($structure['rows'] as $row) {
            foreach ($row['columns'] as $col) {
                foreach ($col['fields'] as $field) {
                    $maps_to = (string) ($field['maps_to'] ?? '');
                    if ($maps_to === '' || $maps_to === 'email') {
                        continue;
                    }
                    if (!in_array($maps_to, SubscriberFields::PROFILE_COLUMNS, true)) {
                        continue;
                    }
                    $slug = (string) ($field['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $raw = $field_values[$slug] ?? '';
                    if (!is_string($raw)) {
                        continue;
                    }
                    $clean = SubscriberFields::sanitize($maps_to, $raw);
                    if ($clean === '') {
                        continue;
                    }
                    $out[$maps_to] = $clean;
                }
            }
        }
        return $out;
    }

    /**
     * Resolve list-picker submissions to list IDs the picker actually
     * exposed. Walks the form structure to find the list_picker
     * field, reads its slug, picks the submitted value off the
     * extracted field map. Drops list IDs that don't exist (defensive
     * against tampered submissions).
     *
     * @return array<int, int>
     */
    private static function extract_chosen_lists(int $form_id, array $field_values): array
    {
        $slug = self::find_field_slug_by_type($form_id, 'list_picker');
        if ($slug === '') {
            return [];
        }
        $raw = $field_values[$slug] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        $repo = new ListRepository();
        foreach ($raw as $v) {
            $list_id = (int) $v;
            if ($list_id > 0 && $repo->find($list_id) !== null) {
                $ids[] = $list_id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Resolve category-picker submissions to category slugs that exist.
     * Same shape as extract_chosen_lists but the picker submits slug
     * strings.
     *
     * @return array<int, string>
     */
    private static function extract_chosen_categories(int $form_id, array $field_values): array
    {
        $slug = self::find_field_slug_by_type($form_id, 'category_picker');
        if ($slug === '') {
            return [];
        }
        $raw = $field_values[$slug] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $valid_slugs = array_keys((new CategoryRepository())->slug_label_map());
        $out = [];
        foreach ($raw as $v) {
            $s = is_string($v) ? sanitize_title($v) : '';
            if ($s !== '' && in_array($s, $valid_slugs, true)) {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    /** True if the form's structure contains at least one field of $type. */
    private static function form_has_field(int $form_id, string $type): bool
    {
        return self::find_field_slug_by_type($form_id, $type) !== '';
    }

    /** Return the slug of the first field of $type in the form, or '' if none. */
    private static function find_field_slug_by_type(int $form_id, string $type): string
    {
        $structure = FormStructure::load($form_id);
        foreach ($structure['rows'] as $row) {
            foreach ($row['columns'] as $col) {
                foreach ($col['fields'] as $field) {
                    if (($field['type'] ?? '') !== $type) {
                        continue;
                    }
                    $slug = (string) ($field['slug'] ?? '');
                    if ($slug !== '') {
                        return $slug;
                    }
                }
            }
        }
        return '';
    }

    private function captcha_service(): ?CaptchaService
    {
        $service = Plugin::instance()->container()->get(CaptchaService::class);
        return $service instanceof CaptchaService ? $service : null;
    }

    /**
     * Best-effort recipient locale at subscribe time. Reads the top
     * preference from the Accept-Language header (e.g. `fr-FR,fr;q=0.9`
     * → `fr_FR`). Normalises BCP-47 hyphens to underscores for
     * downstream consumers (WP uses the underscore form). Returns
     * empty string when nothing usable is in the header.
     */
    private static function detect_language(): string
    {
        $raw = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
            ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE']
            : '';
        if ($raw === '') {
            return '';
        }
        $first = strtok($raw, ',');
        if ($first === false) {
            return '';
        }
        $first = trim(strtok($first, ';') ?: '');
        if ($first === '') {
            return '';
        }
        $normalised = str_replace('-', '_', $first);
        // Cap to varchar(20) and keep only locale-like characters.
        $normalised = preg_replace('/[^A-Za-z0-9_]/', '', $normalised) ?? '';
        return substr($normalised, 0, 20);
    }

    /**
     * Send a successful response, optionally with a non-default
     * message. Reads the per-form success message override; falls
     * back to a generic confirmation copy.
     */
    private static function respond_success(int $form_id, ?string $override = null): void
    {
        $message = $override;
        if ($message === null) {
            $form_message = (string) get_post_meta($form_id, FormCPT::META_SUCCESS_MESSAGE, true);
            $message = $form_message !== ''
                ? $form_message
                : __('Thanks! Check your inbox to confirm your subscription.', 'lrob-email-toolkit');
        }
        wp_send_json_success(['message' => $message]);
    }
}
