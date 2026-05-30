<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\SMTP\AuthTester;
use LRob\EmailToolkit\Modules\SMTP\ConstantOverrides;
use LRob\EmailToolkit\Modules\SMTP\DnsLookup;
use LRob\EmailToolkit\Modules\SMTP\Identity;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Modules\SMTP\RoutingRules;
use LRob\EmailToolkit\Modules\SMTP\TestSender;
use LRob\EmailToolkit\Support\Encryption;

// Docs: docs/smtp.md
final class AjaxController
{
    public const NONCE_ACTION = 'lrob_etk_smtp_ajax';

    public const ACTION_SAVE          = 'lrob_etk_smtp_save_identity';

    public const ACTION_DELETE        = 'lrob_etk_smtp_delete_identity';

    public const ACTION_SET_DEFAULT   = 'lrob_etk_smtp_set_default';

    public const ACTION_TEST_AUTH     = 'lrob_etk_smtp_test_auth';

    public const ACTION_TEST_SEND     = 'lrob_etk_smtp_test_send';

    public const ACTION_SAVE_ROUTING  = 'lrob_etk_smtp_save_routing';

    public const ACTION_CHECK_HOSTS   = 'lrob_etk_smtp_check_hosts';

    public const ACTION_CHECK_HOST    = 'lrob_etk_smtp_check_host';

    public function __construct(
        private IdentityRepository $identities,
        private RoutingRules $routing,
        private ConstantOverrides $overrides,
        private AuthTester $auth_tester,
        private TestSender $test_sender,
        private DnsLookup $dns,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_SAVE,         [$this, 'ajax_save']);
        add_action('wp_ajax_' . self::ACTION_DELETE,       [$this, 'ajax_delete']);
        add_action('wp_ajax_' . self::ACTION_SET_DEFAULT,  [$this, 'ajax_set_default']);
        add_action('wp_ajax_' . self::ACTION_TEST_AUTH,    [$this, 'ajax_test_auth']);
        add_action('wp_ajax_' . self::ACTION_TEST_SEND,    [$this, 'ajax_test_send']);
        add_action('wp_ajax_' . self::ACTION_SAVE_ROUTING, [$this, 'ajax_save_routing']);
        add_action('wp_ajax_' . self::ACTION_CHECK_HOSTS,  [$this, 'ajax_check_hosts']);
        add_action('wp_ajax_' . self::ACTION_CHECK_HOST,   [$this, 'ajax_check_host']);
    }

    /** Resolve a single host as typed (A/AAAA). Used by the in-field resolve dot. */
    public function ajax_check_host(): void
    {
        $this->guard();
        $host = strtolower($this->post_str('host'));
        if ($host === '' || strpos($host, '.') === false) {
            wp_send_json_error(['message' => __('No host to check.', 'lrob-email-toolkit')]);
        }
        wp_send_json_success(['host' => $host, 'resolves' => $this->dns->resolves($host)]);
    }

    /**
     * Build the host-candidate list for a domain (presets + MX, deduped) and
     * resolve each. The dropdown shows the result; MX entries carry a priority.
     */
    public function ajax_check_hosts(): void
    {
        $this->guard();
        $domain = strtolower($this->post_str('domain'));
        // Accept a bare domain or an email — keep only the domain part.
        $at = strrpos($domain, '@');
        if ($at !== false) {
            $domain = substr($domain, $at + 1);
        }
        if ($domain === '' || strpos($domain, '.') === false) {
            wp_send_json_error(['message' => __('No domain to check.', 'lrob-email-toolkit')]);
        }

        $mx = $this->dns->mx_records($domain);          // [{host, priority}], deduped, sorted
        $mx_hosts = array_column($mx, 'host');

        // Preset candidates, minus any that are already an MX target (dedup).
        $candidates = [];
        foreach (['mail.' . $domain, 'smtp.' . $domain, $domain] as $preset) {
            if (!in_array($preset, $mx_hosts, true)) {
                $candidates[] = ['host' => $preset, 'priority' => null];
            }
        }
        foreach ($mx as $r) {
            $candidates[] = ['host' => $r['host'], 'priority' => $r['priority']];
        }

        $out = [];
        foreach ($candidates as $c) {
            $out[] = [
                'host'     => $c['host'],
                'priority' => $c['priority'],
                'resolves' => $this->dns->resolves($c['host']),
            ];
        }

        wp_send_json_success(['domain' => $domain, 'hosts' => $out]);
    }

    public function ajax_save(): void
    {
        $this->guard();

        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        $errors = [];

        $label = $this->post_str('label');
        if ($label === '') {
            $errors['label'] = __('Label is required.', 'lrob-email-toolkit');
        }

        $transport = $this->post_str('transport', Identity::TRANSPORT_SMTP);
        if (!in_array($transport, [Identity::TRANSPORT_SMTP, Identity::TRANSPORT_MAIL], true)) {
            $transport = Identity::TRANSPORT_SMTP;
        }
        $is_smtp = $transport === Identity::TRANSPORT_SMTP;

        $slug = $this->post_str('slug');
        if ($slug === '') {
            $slug = $this->slugify($label);
        }
        if ($slug === '') {
            $errors['slug'] = __('Slug could not be generated from the label.', 'lrob-email-toolkit');
        } elseif ($this->identities->slug_exists($slug, $id > 0 ? $id : null)) {
            $errors['slug'] = __('Another identity already uses this slug.', 'lrob-email-toolkit');
        }

        $smtp_auth = !empty($_POST['smtp_auth']);
        $smtp_username = $this->post_str('smtp_username');
        if ($is_smtp && $smtp_auth && $smtp_username === '') {
            $errors['smtp_username'] = __('Username is required when authentication is on.', 'lrob-email-toolkit');
        }

        $password_input = isset($_POST['smtp_password']) ? (string) wp_unslash($_POST['smtp_password']) : '';
        $clear_password = !empty($_POST['smtp_password_clear']);
        $plain_password = $clear_password ? '' : ($password_input !== '' ? $password_input : null);

        $smtp_host = $this->post_str('smtp_host');
        if ($is_smtp && $smtp_host === '') {
            $errors['smtp_host'] = __('SMTP host is required.', 'lrob-email-toolkit');
        }

        $smtp_port = isset($_POST['smtp_port']) ? (int) $_POST['smtp_port'] : 0;
        if ($is_smtp && ($smtp_port < 1 || $smtp_port > 65535)) {
            $errors['smtp_port'] = __('Port must be between 1 and 65535.', 'lrob-email-toolkit');
        }
        if (!$is_smtp && $smtp_port < 1) {
            $smtp_port = 587;  // harmless default for mail() transport
        }

        $smtp_encryption = $this->post_str('smtp_encryption', 'tls');
        if (!in_array($smtp_encryption, [Identity::ENCRYPTION_NONE, Identity::ENCRYPTION_SSL, Identity::ENCRYPTION_STARTTLS], true)) {
            $smtp_encryption = Identity::ENCRYPTION_STARTTLS;
        }

        $from_email_raw = isset($_POST['from_email']) ? (string) wp_unslash($_POST['from_email']) : '';
        $from_email = $from_email_raw === '' ? '' : sanitize_email($from_email_raw);
        if ($from_email_raw !== '' && !is_email($from_email)) {
            $errors['from_email'] = __('From email is not a valid address.', 'lrob-email-toolkit');
        }

        $from_name = $this->post_str('from_name');

        $reply_to_raw = isset($_POST['reply_to_email']) ? sanitize_email((string) wp_unslash($_POST['reply_to_email'])) : '';
        $reply_to_email = $reply_to_raw !== '' && is_email($reply_to_raw) ? $reply_to_raw : null;
        if ($reply_to_raw !== '' && $reply_to_email === null) {
            $errors['reply_to_email'] = __('Reply-to is not a valid email address.', 'lrob-email-toolkit');
        }

        $override_mode = Identity::normalize_override_mode(
            isset($_POST['override_mode']) ? wp_unslash((string) $_POST['override_mode']) : Identity::OVERRIDE_WHEN_DEFAULT
        );
        $is_default = !empty($_POST['is_default']);
        $is_active = !empty($_POST['is_active']);
        $save_attachments = !empty($_POST['save_attachments']);

        if ($errors !== []) {
            wp_send_json_error(['fields' => $errors, 'message' => __('Please fix the highlighted fields.', 'lrob-email-toolkit')]);
        }

        $existing = $id > 0 ? $this->identities->find($id) : null;
        $existing_ciphertext = $existing instanceof Identity ? $existing->smtp_password_encrypted : '';

        $identity = new Identity(
            id: $id > 0 ? $id : null,
            slug: $slug,
            label: $label,
            transport: $transport,
            from_email: $from_email,
            from_name: $from_name,
            smtp_host: $smtp_host,
            smtp_port: $smtp_port,
            smtp_encryption: $smtp_encryption,
            smtp_username: $smtp_username,
            smtp_password_encrypted: $existing_ciphertext,
            smtp_auth: $smtp_auth,
            override_mode: $override_mode,
            reply_to_email: $reply_to_email,
            is_default: $is_default,
            is_active: $is_active,
            save_attachments: $save_attachments,
        );

        try {
            $saved_id = $this->identities->save($identity, $plain_password);
            if ($is_default || $this->identities->count() === 1) {
                $this->identities->set_default($saved_id);
            }
            wp_send_json_success([
                'id'      => $saved_id,
                'message' => __('Identity saved.', 'lrob-email-toolkit'),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_delete(): void
    {
        $this->guard();
        $this->guard_action(self::ACTION_DELETE);
        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid identity.', 'lrob-email-toolkit')]);
        }
        $this->identities->delete($id);
        wp_send_json_success(['message' => __('Identity deleted.', 'lrob-email-toolkit')]);
    }

    public function ajax_set_default(): void
    {
        $this->guard();
        $this->guard_action(self::ACTION_SET_DEFAULT);
        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid identity.', 'lrob-email-toolkit')]);
        }
        $this->identities->set_default($id);
        wp_send_json_success(['message' => __('Default identity updated.', 'lrob-email-toolkit')]);
    }

    public function ajax_test_auth(): void
    {
        $this->guard();

        $transport = $this->post_str('transport', Identity::TRANSPORT_SMTP);
        if ($transport === Identity::TRANSPORT_MAIL) {
            wp_send_json_error(['message' => __('PHP mail() transport has nothing to authenticate against.', 'lrob-email-toolkit')]);
        }

        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;

        $smtp_host = $this->post_str('smtp_host');
        $smtp_port = isset($_POST['smtp_port']) ? (int) $_POST['smtp_port'] : 587;
        $smtp_encryption = $this->post_str('smtp_encryption', 'tls');
        $smtp_auth = !empty($_POST['smtp_auth']);
        $smtp_username = $this->post_str('smtp_username');
        $password_input = isset($_POST['smtp_password']) ? (string) wp_unslash($_POST['smtp_password']) : '';

        $ciphertext = '';
        if ($password_input !== '') {
            try {
                $ciphertext = Encryption::encrypt($password_input);
            } catch (\Throwable $e) {
                wp_send_json_error(['message' => $e->getMessage()]);
            }
        } elseif ($id > 0) {
            $existing = $this->identities->find($id);
            if ($existing instanceof Identity) {
                $ciphertext = $existing->smtp_password_encrypted;
            }
        }

        $identity = new Identity(
            id: null,
            slug: 'test',
            label: 'test',
            transport: Identity::TRANSPORT_SMTP,
            from_email: 'test@test',
            from_name: 'test',
            smtp_host: $smtp_host,
            smtp_port: $smtp_port,
            smtp_encryption: $smtp_encryption,
            smtp_username: $smtp_username,
            smtp_password_encrypted: $ciphertext,
            smtp_auth: $smtp_auth,
            override_mode: Identity::OVERRIDE_NEVER,
            reply_to_email: null,
            is_default: false,
            is_active: true,
            save_attachments: false,
        );

        $result = $this->auth_tester->test($identity);
        if ($result['ok']) {
            wp_send_json_success([
                'message' => $result['message'],
                'debug'   => $result['debug'],
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['message'],
                'debug'   => $result['debug'],
            ]);
        }
    }

    public function ajax_test_send(): void
    {
        $this->guard();

        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        $choice = $this->post_str('recipient_choice', 'current');
        $recipient = match ($choice) {
            'admin'  => (string) get_option('admin_email'),
            'custom' => isset($_POST['recipient_custom']) ? sanitize_email((string) wp_unslash($_POST['recipient_custom'])) : '',
            default  => (string) wp_get_current_user()->user_email,
        };

        if ($id <= 0 || $recipient === '' || !is_email($recipient)) {
            wp_send_json_error(['message' => __('Pick an identity and a valid recipient.', 'lrob-email-toolkit')]);
        }

        $result = $this->test_sender->send($id, $recipient);
        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %s: recipient email */
                    __('Test email sent to %s.', 'lrob-email-toolkit'),
                    $recipient
                ),
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['error'] ?? __('Unknown error.', 'lrob-email-toolkit'),
            ]);
        }
    }

    public function ajax_save_routing(): void
    {
        $this->guard();
        $this->guard_action(self::ACTION_SAVE_ROUTING);

        $submitted = isset($_POST['routing']) && is_array($_POST['routing'])
            ? wp_unslash($_POST['routing'])
            : [];

        $clean = [];
        foreach ($submitted as $source => $slug) {
            if (!is_string($source)) {
                continue;
            }
            $src = sanitize_key($source);
            $slg = is_string($slug) ? sanitize_key($slug) : '';
            $clean[$src] = $slg;
        }
        $this->routing->save($clean);

        wp_send_json_success(['message' => __('Routing rules saved.', 'lrob-email-toolkit')]);
    }

    /** Extra nonce gate on destructive endpoints (delete, set-default, save-routing). */
    private function guard_action(string $action): void
    {
        $nonce = isset($_POST['_action_nonce']) ? (string) $_POST['_action_nonce'] : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload and retry.', 'lrob-email-toolkit')], 403);
        }
    }

    private function guard(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'lrob-email-toolkit')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, '_nonce');
    }

    private function post_str(string $key, string $default = ''): string
    {
        if (!isset($_POST[$key])) {
            return $default;
        }
        return sanitize_text_field((string) wp_unslash($_POST[$key]));
    }

    private function slugify(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
        $s = trim($s, '_');
        return substr($s, 0, 50);
    }
}
