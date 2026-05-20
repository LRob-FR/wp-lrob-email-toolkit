<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Modules\SMTP\MailRouter;
use LRob\EmailToolkit\Modules\SMTP\SourceResolver;
use LRob\EmailToolkit\Plugin;
use LRob\EmailToolkit\Support\Events;

/**
 * AJAX endpoint that processes every contact-form submission. Hangs off both
 * `wp_ajax_nopriv_lrob_etk_cf_submit` and `wp_ajax_lrob_etk_cf_submit`.
 *
 * Pipeline order matters — cheap checks before expensive ones: nonce → form
 * exists + published → honeypot (silent success so bots can't adapt) →
 * time-trap → rate limit → challenge → field validation. On success: insert
 * submission row → send via SMTP → update with log_id. Dispatches
 * contact_form.* events at every interesting transition.
 */
final class SubmitHandler
{
    public const ACTION = 'lrob_etk_cf_submit';

    public const NONCE_ACTION = 'lrob_etk_cf_submit';

    /** Minimum seconds a human takes to fill any form. Below this = bot. */
    private const MIN_FORM_TIME_SECONDS = 2;

    public function __construct(
        private RateLimiter $rate_limiter,
        private SubmissionRepository $submissions,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        $post = wp_unslash($_POST);

        $nonce = isset($post['_wpnonce']) ? (string) $post['_wpnonce'] : '';
        if ($nonce === '') {
            $nonce = isset($post['_lrob_etk_cf_nonce']) ? (string) $post['_lrob_etk_cf_nonce'] : '';
        }
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION) && !$this->verify_data_attr_nonce($post)) {
            wp_send_json_error(['message' => __('This form has expired. Please reload the page and try again.', 'lrob-email-toolkit')], 400);
        }

        $form_id = isset($post['_lrob_etk_cf_form_id']) ? (int) $post['_lrob_etk_cf_form_id'] : 0;
        $form = $form_id > 0 ? get_post($form_id) : null;
        if (!$form || $form->post_type !== CPT::POST_TYPE || $form->post_status !== 'publish') {
            wp_send_json_error(['message' => __('Form not found.', 'lrob-email-toolkit')], 404);
        }

        $instance = isset($post['_lrob_etk_cf_instance']) ? (string) $post['_lrob_etk_cf_instance'] : '';
        $field_values = self::extract_field_values($post, $instance);

        $ip = RateLimiter::client_ip();
        $ip_hash = RateLimiter::hash_ip($ip);
        $context = [
            'ip_hash'    => $ip_hash,
            // Raw IP only when admin opted in. Default is hash-only for
            // privacy / GDPR friendliness; `ip_hash` always populated since
            // the rate limiter needs it.
            'ip_address' => Settings::store_raw_ip() ? $ip : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
            'referer'    => isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '',
        ];
        // Per-form opt-out of submission persistence. Notification email
        // still goes out (that's the form's whole point); we just don't
        // archive. SubmitHandler routes every insert() call through this
        // gate via $persist below.
        $persist = Settings::effective_save_submissions($form_id);

        // Captcha state captured up front so every insert path (honeypot,
        // time-trap, rate-limit, challenge-fail, happy path) records the
        // same canonical slug. Outcome starts as "skipped" and flips to
        // passed / failed once the challenge actually runs.
        //
        // Routing-key world (v0.1.0+): per-form meta stores 'none' /
        // 'homemade:X' / 'identity:N' / '' (inherit). CaptchaService
        // resolves whichever applies, using the contact_form context to
        // fall back to the Captcha page's contact_form override (or its
        // global default if that's set to 'inherit').
        $captcha_route = Settings::effective_routing_key($form_id);
        $captcha_service = $this->captcha_service();
        $captcha_context = [
            'context'     => 'contact_form',
            'form_id'     => $form_id,
            'force_route' => $captcha_route,
        ];
        if ($captcha_service !== null) {
            [$resolved_challenge, ] = $captcha_service->resolve($captcha_context);
            $captcha_slug = $resolved_challenge !== null
                ? $resolved_challenge->slug()
                : CaptchaService::SLUG_NONE;
        } else {
            $resolved_challenge = null;
            $captcha_slug = CaptchaService::SLUG_NONE;
        }
        $captcha_enabled = $resolved_challenge !== null;
        $captcha_outcome = SubmissionRepository::CAPTCHA_OUTCOME_SKIPPED;
        $context['captcha_slug'] = $captcha_slug;
        $context['captcha_outcome'] = $captcha_outcome;

        // Honeypot returns a successful-looking response so bots can't adapt.
        if (Settings::effective_honeypot($form_id) && Honeypot::tripped($post)) {
            if ($persist) {
                $this->submissions->insert($form_id, $field_values, $context + ['notes' => 'honeypot_tripped'], SubmissionRepository::STATUS_SPAM_BLOCKED);
            }
            Events::dispatch('contact_form.spam_blocked', ['form_id' => $form_id, 'reason' => 'honeypot']);
            wp_send_json_success(['message' => Settings::effective_success_message($form_id)]);
        }

        $started = isset($post['_lrob_etk_cf_started']) ? (int) $post['_lrob_etk_cf_started'] : 0;
        if ($started > 0 && (time() - $started) < self::MIN_FORM_TIME_SECONDS) {
            if ($persist) {
                $this->submissions->insert($form_id, $field_values, $context + ['notes' => 'time_trap'], SubmissionRepository::STATUS_SPAM_BLOCKED);
            }
            Events::dispatch('contact_form.spam_blocked', ['form_id' => $form_id, 'reason' => 'time_trap']);
            wp_send_json_success(['message' => Settings::effective_success_message($form_id)]);
        }

        if ($this->rate_limiter->over_limit($ip_hash, $form_id, Settings::effective_rate_max($form_id), Settings::effective_rate_window_seconds($form_id))) {
            // No insert — rate-limited submissions don't reach our table.
            // We still emit the event so external listeners can react.
            Events::dispatch('contact_form.spam_blocked', ['form_id' => $form_id, 'reason' => 'rate_limit']);
            wp_send_json_error(['message' => __('You are submitting too quickly. Please wait a few minutes and try again.', 'lrob-email-toolkit')], 429);
        }

        if ($captcha_enabled && $captcha_service !== null) {
            [$ok, $message] = $captcha_service->verify($post, $captcha_context);
            if (!$ok) {
                $context['captcha_outcome'] = SubmissionRepository::CAPTCHA_OUTCOME_FAILED;
                if ($persist) {
                    $this->submissions->insert($form_id, $field_values, $context + ['notes' => 'captcha_failed'], SubmissionRepository::STATUS_SPAM_BLOCKED);
                }
                $this->rate_limiter->record($ip_hash, $form_id);
                Events::dispatch('contact_form.spam_blocked', ['form_id' => $form_id, 'reason' => 'captcha']);
                wp_send_json_error(['message' => $message], 400);
            }
            $context['captcha_outcome'] = SubmissionRepository::CAPTCHA_OUTCOME_PASSED;
        }

        $structure = FormStructure::load($form_id);
        $field_blocks = self::collect_fields_from_structure($structure);
        $errors = self::validate_fields($field_blocks, $field_values);

        if ($errors !== []) {
            wp_send_json_error([
                'message'     => __('Please correct the highlighted fields.', 'lrob-email-toolkit'),
                'fieldErrors' => $errors,
            ], 422);
        }

        // Persist submission row early so we have an id even if mail send blows up.
        $submission_id = $persist
            ? $this->submissions->insert($form_id, $field_values, $context)
            : 0;
        $this->rate_limiter->record($ip_hash, $form_id);

        Events::dispatch('contact_form.submitted', [
            'form_id'       => $form_id,
            'submission_id' => $submission_id,
            'fields'        => $field_values,
        ]);

        // Send via SMTP — push 'contact_form' source so SMTP routing rules can target it.
        $send_result = $this->send_notification_email($form, $form_id, $field_blocks, $field_values);

        if ($persist && $submission_id > 0) {
            if ($send_result['ok']) {
                $this->submissions->update_status($submission_id, SubmissionRepository::STATUS_DELIVERED, $send_result['log_id']);
            } else {
                $this->submissions->update_status($submission_id, SubmissionRepository::STATUS_FAILED, null, $send_result['error']);
                // Still tell the user we received it — the message *is* on the
                // server. Admin can resend from the Logs page.
            }
        }
        if ($send_result['ok']) {
            Events::dispatch('contact_form.delivered', ['form_id' => $form_id, 'submission_id' => $submission_id]);
        }

        wp_send_json_success(['message' => Settings::effective_success_message($form_id)]);
    }

    private function captcha_service(): ?CaptchaService
    {
        $container = Plugin::instance()->container();
        return $container->has(CaptchaService::class) ? $container->get(CaptchaService::class) : null;
    }

    /** Optional X-WP-Nonce header fallback (some page builders strip "non-standard" hidden inputs). */
    private function verify_data_attr_nonce(array $post): bool
    {
        unset($post);
        $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? (string) $_SERVER['HTTP_X_WP_NONCE'] : '';
        if ($nonce === '') {
            return false;
        }
        return (bool) wp_verify_nonce($nonce, self::NONCE_ACTION);
    }

    /**
     * Pull lrob_etk_cf[instance][slug] keys out of $_POST into a flat
     * slug → value map. Multiple-value fields (checkbox groups) keep arrays.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function extract_field_values(array $post, string $instance): array
    {
        $raw = $post[FormContext::FIELD_NAME_PREFIX] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $bucket = $raw[$instance] ?? null;
        if (!is_array($bucket)) {
            return [];
        }
        $out = [];
        foreach ($bucket as $slug => $value) {
            $clean_slug = sanitize_key((string) $slug);
            if ($clean_slug === '') {
                continue;
            }
            $out[$clean_slug] = $value;
        }
        return $out;
    }

    /**
     * Walk a FormStructure (rows of columns of fields) and return a slug →
     * field descriptor map for validation + composition. The "name" key
     * keeps the `lrob-etk/field-{type}` shape callers downstream expect.
     *
     * @param array{rows:array<int, array{columns:array<int, array{fields:array<int, array<string, mixed>>}>}>} $structure
     * @return array<string, array{name:string, attrs:array<string, mixed>}>
     */
    private static function collect_fields_from_structure(array $structure): array
    {
        // Submit + captcha aren't user-submittable values — skip them so they
        // don't show up as orphan validation entries.
        $skip = ['submit', 'captcha'];
        $out = [];
        foreach ($structure['rows'] as $row) {
            foreach ($row['columns'] as $col) {
                foreach ($col['fields'] as $field) {
                    $type = (string) ($field['type'] ?? '');
                    if ($type === '' || in_array($type, $skip, true)) {
                        continue;
                    }
                    $slug = sanitize_key((string) ($field['slug'] ?? ''));
                    if ($slug === '') {
                        continue;
                    }
                    $out[$slug] = [
                        'name'  => 'lrob-etk/field-' . $type,
                        'attrs' => $field,
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Validate each declared field against its submitted value. Returns
     * slug → error message; empty array means valid.
     *
     * @param array<string, array{name:string, attrs:array<string, mixed>}> $fields
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private static function validate_fields(array $fields, array $values): array
    {
        $errors = [];

        foreach ($fields as $slug => $def) {
            $value = $values[$slug] ?? null;
            $attrs = $def['attrs'];
            $required = !empty($attrs['required']);
            $type = $def['name'];

            $is_empty = self::is_empty_value($value);
            if ($required && $is_empty) {
                $errors[$slug] = __('This field is required.', 'lrob-email-toolkit');
                continue;
            }
            if ($is_empty) {
                continue;
            }

            switch ($type) {
                case 'lrob-etk/field-email':
                    if (!is_string($value) || !is_email($value)) {
                        $errors[$slug] = __('Please enter a valid email address.', 'lrob-email-toolkit');
                    }
                    break;
                case 'lrob-etk/field-number':
                    if (!is_numeric($value)) {
                        $errors[$slug] = __('Please enter a number.', 'lrob-email-toolkit');
                        break;
                    }
                    $num = (float) $value;
                    if (isset($attrs['min']) && $attrs['min'] !== '' && $num < (float) $attrs['min']) {
                        $errors[$slug] = sprintf(
                            /* translators: %s: minimum numeric value the field accepts */
                            __('Value must be at least %s.', 'lrob-email-toolkit'),
                            (string) $attrs['min']
                        );
                    } elseif (isset($attrs['max']) && $attrs['max'] !== '' && $num > (float) $attrs['max']) {
                        $errors[$slug] = sprintf(
                            /* translators: %s: maximum numeric value the field accepts */
                            __('Value must be at most %s.', 'lrob-email-toolkit'),
                            (string) $attrs['max']
                        );
                    }
                    break;
                case 'lrob-etk/field-phone':
                    if (!is_string($value)) {
                        $errors[$slug] = __('Please enter a valid phone number.', 'lrob-email-toolkit');
                        break;
                    }
                    $digits = preg_replace('/\D+/', '', $value) ?? '';
                    if (strlen($digits) < 6) {
                        $errors[$slug] = __('Please enter a valid phone number.', 'lrob-email-toolkit');
                    } elseif (isset($attrs['pattern']) && is_string($attrs['pattern']) && $attrs['pattern'] !== '' && !preg_match('/^' . str_replace('/', '\/', $attrs['pattern']) . '$/', $value)) {
                        $errors[$slug] = __('Please enter a valid phone number.', 'lrob-email-toolkit');
                    }
                    break;
                case 'lrob-etk/field-date':
                    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $errors[$slug] = __('Please enter a valid date.', 'lrob-email-toolkit');
                        break;
                    }
                    if (isset($attrs['min']) && $attrs['min'] !== '' && strcmp($value, (string) $attrs['min']) < 0) {
                        $errors[$slug] = __('Date is before the allowed range.', 'lrob-email-toolkit');
                    } elseif (isset($attrs['max']) && $attrs['max'] !== '' && strcmp($value, (string) $attrs['max']) > 0) {
                        $errors[$slug] = __('Date is after the allowed range.', 'lrob-email-toolkit');
                    }
                    break;
                case 'lrob-etk/field-select':
                case 'lrob-etk/field-radio':
                    $allowed = self::option_values($attrs['options'] ?? []);
                    if ($allowed !== [] && !in_array((string) $value, $allowed, true)) {
                        $errors[$slug] = __('Invalid choice.', 'lrob-email-toolkit');
                    }
                    break;
                case 'lrob-etk/field-checkbox':
                    $multiple = !isset($attrs['multiple']) || !empty($attrs['multiple']);
                    $allowed = self::option_values($attrs['options'] ?? []);
                    if ($multiple) {
                        if (!is_array($value)) {
                            $value = [(string) $value];
                        }
                        foreach ($value as $v) {
                            if ($allowed !== [] && !in_array((string) $v, $allowed, true)) {
                                $errors[$slug] = __('Invalid choice.', 'lrob-email-toolkit');
                                break;
                            }
                        }
                    }
                    break;
                case 'lrob-etk/field-text':
                case 'lrob-etk/field-textarea':
                    $maxLen = isset($attrs['maxLength']) ? (int) $attrs['maxLength'] : 0;
                    if ($maxLen > 0 && is_string($value) && mb_strlen($value) > $maxLen) {
                        $errors[$slug] = sprintf(
                            /* translators: %d: maximum character length the field accepts */
                            __('This field is too long (max %d characters).', 'lrob-email-toolkit'),
                            $maxLen
                        );
                    }
                    break;
            }
        }

        return $errors;
    }

    /** @param mixed $raw */
    private static function option_values(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $opt) {
            if (is_array($opt) && isset($opt['value']) && is_scalar($opt['value'])) {
                $out[] = (string) $opt['value'];
            } elseif (is_string($opt)) {
                $out[] = sanitize_title($opt);
            }
        }
        return $out;
    }

    private static function is_empty_value(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }
        return false;
    }

    /**
     * Build + send the notification email via wp_mail. Pushes 'contact_form'
     * onto the SourceResolver so the SMTP module can route through routing
     * rules. If META_RECIPIENT_IDENTITY is set on the form, that identity
     * overrides routing for this single send.
     *
     * @param array<string, array{name:string, attrs:array<string, mixed>}> $field_blocks
     * @param array<string, mixed> $field_values
     * @return array{ok:bool, log_id:?int, error:?string}
     */
    private function send_notification_email(\WP_Post $form, int $form_id, array $field_blocks, array $field_values): array
    {
        $recipient = Settings::effective_recipient($form_id);
        $recipient_list = self::parse_recipient_list($recipient);
        if ($recipient_list === []) {
            return ['ok' => false, 'log_id' => null, 'error' => 'invalid_recipient'];
        }

        $subject = self::compose_subject($form, $field_blocks, $field_values);
        [$body_text, $body_html] = self::compose_body($form, $field_blocks, $field_values);

        $headers = [];
        $reply_to = self::resolve_reply_to($form_id, $field_values);
        if ($reply_to !== '') {
            $reply_name = self::resolve_reply_name($field_values);
            $headers[] = $reply_name !== ''
                ? 'Reply-To: ' . self::format_address($reply_name, $reply_to)
                : 'Reply-To: ' . $reply_to;
        }
        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        // Force-pick the per-form (or per-module-default) identity if configured.
        $forced_router = null;
        $identity_id = Settings::effective_identity_id($form_id);
        if ($identity_id > 0 && class_exists(IdentityRepository::class) && class_exists(MailRouter::class)) {
            $identity = (new IdentityRepository())->find($identity_id);
            $plugin = \LRob\EmailToolkit\Plugin::instance();
            if ($identity && $plugin->container()->has(MailRouter::class)) {
                $forced_router = $plugin->container()->get(MailRouter::class);
                if ($forced_router instanceof MailRouter) {
                    $forced_router->force_identity($identity->slug);
                }
            }
        }

        $ok = false;
        SourceResolver::push(SourceResolver::SOURCE_CONTACT_FORM);
        try {
            $ok = wp_mail($recipient_list, $subject, $body_html, $headers);
        } finally {
            SourceResolver::pop();
            if ($forced_router instanceof MailRouter) {
                $forced_router->force_identity(null);
            }
        }

        // log_id is not directly returned by wp_mail; the Logging module fills it.
        // We intentionally don't try to look it up here — keeping the coupling loose.
        unset($body_text); // body_text is built for future plain-part support; not used yet.
        return ['ok' => $ok, 'log_id' => null, 'error' => $ok ? null : 'wp_mail_returned_false'];
    }

    /**
     * Parse a comma-separated recipient string into a clean list of valid
     * email addresses. Returns [] when nothing is valid (caller should fail
     * the send and surface invalid_recipient).
     *
     * @return array<int, string>
     */
    private static function parse_recipient_list(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || !is_email($candidate)) {
                continue;
            }
            $out[] = $candidate;
        }
        return $out;
    }

    /**
     * @param array<string, array{name:string, attrs:array<string, mixed>}> $field_blocks
     * @param array<string, mixed> $values
     */
    private static function compose_subject(\WP_Post $form, array $field_blocks, array $values): string
    {
        $template = Settings::effective_subject_template($form->ID);
        if ($template === '') {
            return sprintf(
                /* translators: %s: form name */
                __('[%s] New contact form submission', 'lrob-email-toolkit'),
                $form->post_title !== '' ? $form->post_title : __('Contact form', 'lrob-email-toolkit')
            );
        }
        return self::interpolate($template, $values, $field_blocks, $form);
    }

    /**
     * @param array<string, array{name:string, attrs:array<string, mixed>}> $field_blocks
     * @param array<string, mixed> $values
     * @return array{0:string, 1:string} [plain, html]
     */
    private static function compose_body(\WP_Post $form, array $field_blocks, array $values): array
    {
        $lines_plain = [];
        $rows_html = [];
        foreach ($field_blocks as $slug => $def) {
            $label = isset($def['attrs']['label']) && is_string($def['attrs']['label']) && $def['attrs']['label'] !== ''
                ? (string) $def['attrs']['label']
                : $slug;
            $value = $values[$slug] ?? '';
            $display = self::value_for_display($value, is_array($def['attrs'] ?? null) ? $def['attrs'] : []);

            $lines_plain[] = $label . ': ' . $display;
            $rows_html[] = sprintf(
                '<tr><th align="left" style="padding:6px 12px 6px 0;border-bottom:1px solid #eee;vertical-align:top;color:#555;font-weight:600;white-space:nowrap">%s</th><td style="padding:6px 0;border-bottom:1px solid #eee;vertical-align:top">%s</td></tr>',
                esc_html($label),
                nl2br(esc_html($display))
            );
        }

        $footer = sprintf(
            /* translators: %s: form title */
            __('Sent from contact form "%s"', 'lrob-email-toolkit'),
            $form->post_title !== '' ? $form->post_title : __('Untitled', 'lrob-email-toolkit')
        );

        $html = '<!DOCTYPE html><html><body style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#222;line-height:1.5;margin:0;padding:24px;background:#f6f7fb">' .
            '<div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px">' .
            '<h2 style="margin:0 0 16px 0;font-size:16px">' . esc_html__('New submission', 'lrob-email-toolkit') . '</h2>' .
            '<table style="width:100%;border-collapse:collapse;font-size:14px">' . implode('', $rows_html) . '</table>' .
            '<p style="margin:18px 0 0 0;color:#888;font-size:12px">' . esc_html($footer) . '</p>' .
            '</div></body></html>';

        return [implode("\n", $lines_plain), $html];
    }

    /**
     * Resolve the Reply-To email by reading the form's nominated field
     * (resolved via Settings::effective_reply_to_field, default 'email').
     * Returns '' if absent or invalid.
     *
     * @param array<string, mixed> $values
     */
    private static function resolve_reply_to(int $form_id, array $values): string
    {
        $slug = Settings::effective_reply_to_field($form_id);
        // Empty slug = "use global default" (already resolved by effective_*).
        // Sentinel "__none__" = "explicitly no Reply-To header for this form".
        // See FormsPage::REPLY_TO_NONE; the picker stores this when a form
        // opts out (some mail providers flag mismatched Reply-To as spam).
        if ($slug === '' || $slug === '__none__') {
            return '';
        }
        $value = $values[$slug] ?? '';
        if (!is_string($value) || trim($value) === '') {
            return '';
        }
        $candidate = trim($value);
        if (!is_email($candidate)) {
            return '';
        }
        // Defense in depth: strip CR/LF to prevent header injection from
        // attacker-controlled input even though is_email already rejects them.
        return preg_replace('/[\r\n]+/', '', $candidate) ?? '';
    }

    /** @param array<string, mixed> $values */
    private static function resolve_reply_name(array $values): string
    {
        foreach (['name', 'fullname', 'firstname'] as $candidate) {
            $v = $values[$candidate] ?? '';
            if (is_string($v) && trim($v) !== '') {
                return preg_replace('/[\r\n]+/', '', trim($v)) ?? '';
            }
        }
        return '';
    }

    private static function format_address(string $name, string $email): string
    {
        $clean_name = str_replace(['"', "\r", "\n"], '', $name);
        return sprintf('"%s" <%s>', $clean_name, $email);
    }

    /**
     * Replace {field:slug} tokens in a string template with submitted values.
     * Also supports {title} for the form's title.
     *
     * @param array<string, mixed> $values
     * @param array<string, array{name:string, attrs:array<string, mixed>}> $fields
     */
    private static function interpolate(string $template, array $values, array $fields, \WP_Post $form): string
    {
        $out = $template;
        $out = (string) preg_replace_callback('/\{field:([a-z0-9_]+)\}/', static function (array $m) use ($values, $fields): string {
            $slug = $m[1];
            $attrs = isset($fields[$slug]['attrs']) && is_array($fields[$slug]['attrs']) ? $fields[$slug]['attrs'] : [];
            return self::value_for_display($values[$slug] ?? '', $attrs);
        }, $out);
        $out = str_replace('{title}', $form->post_title, $out);
        // Strip CR/LF for header safety.
        return preg_replace('/[\r\n]+/', ' ', $out) ?? '';
    }

    /**
     * Stringify a submitted value for display in subject / body / templates.
     * For multi-choice fields (select / radio / checkbox), the submitted
     * scalar is the option's `value` (the HTML-form transport identifier);
     * we look the option up in `$attrs['options']` and emit its `label`
     * instead so the email reads as "Subject: Hello" rather than
     * "Subject: hello" or "Subject: option_1".
     *
     * @param array<string, mixed> $attrs
     */
    private static function value_for_display(mixed $value, array $attrs = []): string
    {
        $label_map = [];
        if (isset($attrs['options']) && is_array($attrs['options'])) {
            foreach ($attrs['options'] as $opt) {
                if (!is_array($opt) || !isset($opt['value'])) {
                    continue;
                }
                $label_map[(string) $opt['value']] = isset($opt['label']) && $opt['label'] !== ''
                    ? (string) $opt['label']
                    : (string) $opt['value'];
            }
        }
        $resolve = static function ($v) use ($label_map): string {
            $s = (string) $v;
            return $label_map[$s] ?? $s;
        };
        if (is_array($value)) {
            $flat = [];
            foreach ($value as $v) {
                if (is_scalar($v)) {
                    $flat[] = $resolve($v);
                }
            }
            return implode(', ', $flat);
        }
        if (is_scalar($value)) {
            return $resolve($value);
        }
        return '';
    }

}
