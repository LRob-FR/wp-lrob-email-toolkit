<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Assets;
use LRob\EmailToolkit\Admin\Menu as MainMenu;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\SMTP\ConstantOverrides;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Modules\SMTP\RoutingRules;

// Docs: docs/smtp.md
final class PageController
{
    public const SLUG = 'lrob-etk-smtp';

    private const HANDLE_CARDS_JS = 'lrob-etk-smtp-cards';

    public function __construct(
        private ModuleInterface $module,
        private IdentityRepository $identities,
        private RoutingRules $routing,
        private ConstantOverrides $overrides,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_submenu'], 20);
        // Priority 20: the shared admin assets (Menu → Assets::enqueue_admin)
        // register the controls/modal/confirm handles at the default 10 first.
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets'], 20);
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if (!str_contains($hook_suffix, self::SLUG)) {
            return;
        }

        wp_enqueue_script(
            self::HANDLE_CARDS_JS,
            LROB_ETK_URL . 'admin/js/smtp-cards.js',
            [Assets::HANDLE_CONTROLS_JS, Assets::HANDLE_MODAL_JS, Assets::HANDLE_CONFIRM_JS],
            Assets::asset_version_for('admin/js/smtp-cards.js'),
            true
        );

        $data = [];
        foreach ($this->identities->all() as $identity) {
            $data[(int) $identity->id] = [
                'id'              => (int) $identity->id,
                'label'           => $identity->label,
                'slug'            => $identity->slug,
                'transport'       => $identity->transport,
                'from_email'      => $identity->from_email,
                'from_name'       => $identity->from_name,
                'smtp_host'       => $identity->smtp_host,
                'smtp_port'       => $identity->smtp_port,
                'smtp_encryption' => $identity->smtp_encryption,
                'smtp_username'   => $identity->smtp_username,
                'smtp_auth'       => $identity->smtp_auth,
                'override_mode'   => $identity->override_mode,
                'reply_to_email'  => $identity->reply_to_email,
                'is_default'      => $identity->is_default,
                'is_active'       => $identity->is_active,
            ];
        }

        wp_localize_script(self::HANDLE_CARDS_JS, 'lrobEtkSmtp', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(AjaxController::NONCE_ACTION),
            'actions' => [
                'save'        => AjaxController::ACTION_SAVE,
                'delete'      => AjaxController::ACTION_DELETE,
                'setDefault'  => AjaxController::ACTION_SET_DEFAULT,
                'testAuth'    => AjaxController::ACTION_TEST_AUTH,
                'testSend'    => AjaxController::ACTION_TEST_SEND,
                'saveRouting' => AjaxController::ACTION_SAVE_ROUTING,
                'checkHosts'  => AjaxController::ACTION_CHECK_HOSTS,
                'checkHost'   => AjaxController::ACTION_CHECK_HOST,
            ],
            'actionNonces' => [
                'delete'      => wp_create_nonce(AjaxController::ACTION_DELETE),
                'setDefault'  => wp_create_nonce(AjaxController::ACTION_SET_DEFAULT),
                'saveRouting' => wp_create_nonce(AjaxController::ACTION_SAVE_ROUTING),
            ],
            'identities' => $data,
            'siteTitle'  => get_bloginfo('name'),
            'i18n' => [
                /* translators: %s: the SMTP identity's display name */
                'deleteConfirm'      => __('Delete the identity "%s"?', 'lrob-email-toolkit'),
                'dirty'              => __('Unsaved changes', 'lrob-email-toolkit'),
                'saving'             => __('Saving…', 'lrob-email-toolkit'),
                'saved'              => __('Saved', 'lrob-email-toolkit'),
                'saveFailed'         => __('Save failed', 'lrob-email-toolkit'),
                'testing'            => __('Testing…', 'lrob-email-toolkit'),
                'sending'            => __('Sending…', 'lrob-email-toolkit'),
                'resolves'           => __('✓ resolves', 'lrob-email-toolkit'),
                'noResolve'          => __('✗ cannot resolve', 'lrob-email-toolkit'),
                'domainMismatch'     => __('Domain differs from the mailbox login. Most servers will reject or rewrite this — only use with relays that support arbitrary senders.', 'lrob-email-toolkit'),
                'userMismatch'       => __('Local part differs from the mailbox login. Some servers may rewrite the From address.', 'lrob-email-toolkit'),
                'unknownError'       => __('Something went wrong.', 'lrob-email-toolkit'),
                'active'             => __('Active', 'lrob-email-toolkit'),
                'inactive'           => __('Inactive', 'lrob-email-toolkit'),
                'createBtn'          => __('Create', 'lrob-email-toolkit'),
                'testAuthBtn'        => __('Test connection', 'lrob-email-toolkit'),
                'sendBtn'            => __('Send test', 'lrob-email-toolkit'),
                'defaultWpSender'    => __('Default — WordPress sender', 'lrob-email-toolkit'),
                'defaultPrefix'      => __('Default — ', 'lrob-email-toolkit'),
                'defaultMailboxLogin' => __('Default — uses mailbox login', 'lrob-email-toolkit'),
                'wpSenderShort'      => __('WordPress sender', 'lrob-email-toolkit'),
                'mailboxLoginShort'  => __('mailbox login', 'lrob-email-toolkit'),
            ],
        ]);
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
        (new SettingsPage($this->module, $this->identities, $this->routing, $this->overrides))->render();
    }
}
