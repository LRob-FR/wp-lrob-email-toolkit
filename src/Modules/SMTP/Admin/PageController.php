<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Menu as MainMenu;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\SMTP\ConstantOverrides;
use LRob\EmailToolkit\Modules\SMTP\Identity;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Modules\SMTP\MailRouter;
use LRob\EmailToolkit\Modules\SMTP\RoutingRules;
use LRob\EmailToolkit\Modules\SMTP\TestSender;

/**
 * Registers the "SMTP" submenu and dispatches POST actions. Pages are
 * rendered by SettingsPage (list + routing) and IdentityEditPage (form).
 *
 * URL routing inside this submenu:
 *   page=lrob-etk-smtp                          → identities list + routing
 *   page=lrob-etk-smtp&action=edit&id=N         → edit identity #N (id=0 for new)
 *
 * POST actions all go through admin-post.php and redirect back to the list.
 */
final class PageController
{
    public const SLUG = 'lrob-etk-smtp';

    public const ACTION_SAVE = 'lrob_etk_smtp_save_identity';

    public const ACTION_DELETE = 'lrob_etk_smtp_delete_identity';

    public const ACTION_SET_DEFAULT = 'lrob_etk_smtp_set_default';

    public const ACTION_SAVE_ROUTING = 'lrob_etk_smtp_save_routing';

    public const ACTION_TEST_SEND = 'lrob_etk_smtp_test_send';

    public function __construct(
        private ModuleInterface $module,
        private IdentityRepository $identities,
        private RoutingRules $routing,
        private ConstantOverrides $overrides,
        private TestSender $tester,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_submenu'], 20);

        add_action('admin_post_' . self::ACTION_SAVE,         [$this, 'handle_save']);
        add_action('admin_post_' . self::ACTION_DELETE,       [$this, 'handle_delete']);
        add_action('admin_post_' . self::ACTION_SET_DEFAULT,  [$this, 'handle_set_default']);
        add_action('admin_post_' . self::ACTION_SAVE_ROUTING, [$this, 'handle_save_routing']);
        add_action('admin_post_' . self::ACTION_TEST_SEND,    [$this, 'handle_test_send']);
    }

    public function add_submenu(): void
    {
        add_submenu_page(
            MainMenu::SLUG,
            __('SMTP', 'lrob-email-toolkit'),
            __('SMTP', 'lrob-email-toolkit'),
            Activator::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }

        $action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';

        if ($action === 'edit') {
            $id = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
            $identity = $id > 0 ? $this->identities->find($id) : null;
            (new IdentityEditPage($this->identities, $this->overrides))->render($identity, $id);
            return;
        }

        (new SettingsPage($this->module, $this->identities, $this->routing, $this->overrides))->render();
    }

    public function handle_save(): void
    {
        $this->guard(self::ACTION_SAVE);

        $errors = [];
        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;

        $slug = isset($_POST['slug']) ? sanitize_key((string) wp_unslash($_POST['slug'])) : '';
        if ($slug === '') {
            $errors[] = __('Slug is required.', 'lrob-email-toolkit');
        } elseif ($this->identities->slug_exists($slug, $id > 0 ? $id : null)) {
            $errors[] = __('That slug is already used by another identity.', 'lrob-email-toolkit');
        }

        $label = isset($_POST['label']) ? sanitize_text_field((string) wp_unslash($_POST['label'])) : '';
        if ($label === '') {
            $errors[] = __('Label is required.', 'lrob-email-toolkit');
        }

        $from_email = isset($_POST['from_email']) ? sanitize_email((string) wp_unslash($_POST['from_email'])) : '';
        if (!is_email($from_email)) {
            $errors[] = __('From email must be a valid email address.', 'lrob-email-toolkit');
        }

        $from_name = isset($_POST['from_name']) ? sanitize_text_field((string) wp_unslash($_POST['from_name'])) : '';
        if ($from_name === '') {
            $errors[] = __('From name is required.', 'lrob-email-toolkit');
        }

        $smtp_host = isset($_POST['smtp_host']) ? sanitize_text_field((string) wp_unslash($_POST['smtp_host'])) : '';
        if ($smtp_host === '') {
            $errors[] = __('SMTP host is required.', 'lrob-email-toolkit');
        }

        $smtp_port = isset($_POST['smtp_port']) ? (int) $_POST['smtp_port'] : 0;
        if ($smtp_port < 1 || $smtp_port > 65535) {
            $errors[] = __('SMTP port must be between 1 and 65535.', 'lrob-email-toolkit');
        }

        $smtp_encryption = isset($_POST['smtp_encryption']) ? (string) wp_unslash($_POST['smtp_encryption']) : 'tls';
        if (!in_array($smtp_encryption, [Identity::ENCRYPTION_NONE, Identity::ENCRYPTION_SSL, Identity::ENCRYPTION_STARTTLS], true)) {
            $smtp_encryption = Identity::ENCRYPTION_STARTTLS;
        }

        $smtp_auth = !empty($_POST['smtp_auth']);
        $smtp_username = isset($_POST['smtp_username']) ? sanitize_text_field((string) wp_unslash($_POST['smtp_username'])) : '';
        if ($smtp_auth && $smtp_username === '') {
            $errors[] = __('Username is required when authentication is enabled.', 'lrob-email-toolkit');
        }

        $password_input = isset($_POST['smtp_password']) ? (string) wp_unslash($_POST['smtp_password']) : '';
        $clear_password = !empty($_POST['smtp_password_clear']);
        $plain_password = $clear_password ? '' : ($password_input !== '' ? $password_input : null);

        $force_from = !empty($_POST['force_from']);

        $reply_to_email = isset($_POST['reply_to_email']) ? sanitize_email((string) wp_unslash($_POST['reply_to_email'])) : '';
        if ($reply_to_email !== '' && !is_email($reply_to_email)) {
            $errors[] = __('Reply-to must be a valid email address.', 'lrob-email-toolkit');
        }

        $is_default = !empty($_POST['is_default']);
        $is_active = !isset($_POST['is_active']) || !empty($_POST['is_active']);

        if ($errors !== []) {
            $this->store_flash('errors', $errors);
            $this->redirect_to_edit($id);
        }

        $existing = $id > 0 ? $this->identities->find($id) : null;
        $existing_ciphertext = $existing instanceof Identity ? $existing->smtp_password_encrypted : '';

        $identity = new Identity(
            id: $id > 0 ? $id : null,
            slug: $slug,
            label: $label,
            from_email: $from_email,
            from_name: $from_name,
            smtp_host: $smtp_host,
            smtp_port: $smtp_port,
            smtp_encryption: $smtp_encryption,
            smtp_username: $smtp_username,
            smtp_password_encrypted: $existing_ciphertext,
            smtp_auth: $smtp_auth,
            force_from: $force_from,
            reply_to_email: $reply_to_email !== '' ? $reply_to_email : null,
            is_default: $is_default,
            is_active: $is_active,
        );

        $saved_id = $this->identities->save($identity, $plain_password);

        if ($is_default) {
            $this->identities->set_default($saved_id);
        } elseif ($this->identities->count() === 1) {
            // First identity is always default, regardless of checkbox.
            $this->identities->set_default($saved_id);
        }

        $this->store_flash('notice', __('SMTP identity saved.', 'lrob-email-toolkit'));
        $this->redirect_to_list();
    }

    public function handle_delete(): void
    {
        $this->guard(self::ACTION_DELETE);

        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        if ($id > 0) {
            $this->identities->delete($id);
            $this->store_flash('notice', __('SMTP identity deleted.', 'lrob-email-toolkit'));
        }
        $this->redirect_to_list();
    }

    public function handle_set_default(): void
    {
        $this->guard(self::ACTION_SET_DEFAULT);

        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        if ($id > 0) {
            $this->identities->set_default($id);
            $this->store_flash('notice', __('Default identity updated.', 'lrob-email-toolkit'));
        }
        $this->redirect_to_list();
    }

    public function handle_save_routing(): void
    {
        $this->guard(self::ACTION_SAVE_ROUTING);

        $submitted = isset($_POST['routing']) && is_array($_POST['routing'])
            ? wp_unslash($_POST['routing'])
            : [];

        $clean = [];
        foreach ($submitted as $source => $slug) {
            if (!is_string($source)) {
                continue;
            }
            $source = sanitize_key($source);
            $slug = is_string($slug) ? sanitize_key($slug) : '';
            $clean[$source] = $slug;
        }
        $this->routing->save($clean);

        $this->store_flash('notice', __('Routing rules saved.', 'lrob-email-toolkit'));
        $this->redirect_to_list();
    }

    public function handle_test_send(): void
    {
        $this->guard(self::ACTION_TEST_SEND);

        $id = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        $recipient = isset($_POST['recipient']) ? sanitize_email((string) wp_unslash($_POST['recipient'])) : '';

        if ($id <= 0 || $recipient === '') {
            $this->store_flash('errors', [__('Recipient and identity are required for a test send.', 'lrob-email-toolkit')]);
            $this->redirect_to_edit($id);
        }

        $result = $this->tester->send($id, $recipient);
        if ($result['success']) {
            $this->store_flash('notice', sprintf(
                /* translators: %s: recipient email */
                __('Test email sent to %s.', 'lrob-email-toolkit'),
                $recipient
            ));
        } else {
            $error = $result['error'] ?? __('Unknown error.', 'lrob-email-toolkit');
            $this->store_flash('errors', [sprintf(
                /* translators: %s: error message */
                __('Test email failed: %s', 'lrob-email-toolkit'),
                $error
            )]);
        }
        $this->redirect_to_edit($id);
    }

    private function guard(string $action): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $nonce = isset($_POST['_lrob_etk_nonce']) ? (string) $_POST['_lrob_etk_nonce'] : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }
    }

    private function redirect_to_list(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    private function redirect_to_edit(int $id): void
    {
        $url = add_query_arg(
            ['page' => self::SLUG, 'action' => 'edit', 'id' => $id],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Flash messages survive one redirect via a short-lived user transient.
     * Pulled by SettingsPage::render() / IdentityEditPage::render().
     *
     * @param string|array<int, string> $value
     */
    private function store_flash(string $key, $value): void
    {
        $user_id = get_current_user_id();
        set_transient('lrob_etk_smtp_flash_' . $key . '_' . $user_id, $value, 60);
    }

    /**
     * @return string|array<int, string>|null
     */
    public static function pop_flash(string $key)
    {
        $user_id = get_current_user_id();
        $transient_key = 'lrob_etk_smtp_flash_' . $key . '_' . $user_id;
        $value = get_transient($transient_key);
        if ($value !== false) {
            delete_transient($transient_key);
            return $value;
        }
        return null;
    }
}
