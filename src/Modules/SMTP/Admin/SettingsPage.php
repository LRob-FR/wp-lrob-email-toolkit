<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP\Admin;

use LRob\EmailToolkit\Admin\ModuleToggle;
use LRob\EmailToolkit\Admin\Tooltip;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\SMTP\ConstantOverrides;
use LRob\EmailToolkit\Modules\SMTP\Identity;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Modules\SMTP\RoutingRules;
use LRob\EmailToolkit\Modules\SMTP\SourceResolver;

/**
 * SMTP page. Module toggle sits inline with the page H1. When the module is
 * off, a one-line CTA replaces the cards. When on, identities render as a
 * grid of always-editable cards with auto-save. From email/name use smart
 * placeholders (empty = auto-derived at runtime; placeholder shows what
 * auto resolves to) — no more visible Auto/Custom mode toggle.
 */
final class SettingsPage
{
    public function __construct(
        private ModuleInterface $module,
        private IdentityRepository $identities,
        private RoutingRules $routing,
        private ConstantOverrides $overrides,
    ) {
    }

    public function render(): void
    {
        $identities = $this->identities->all();
        $enabled = $this->module->is_enabled();

        ?>
        <div class="wrap lrob-etk lrob-etk-smtp-page">
            <header class="lrob-etk-page-header">
                <h1 class="lrob-etk-page-title"><?php esc_html_e('SMTP', 'lrob-email-toolkit'); ?></h1>
                <?php ModuleToggle::render_inline($this->module); ?>
                <?php if ($enabled) : ?>
                    <button type="button" id="lrob-etk-add-identity" class="button button-primary lrob-etk-page-add">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php esc_html_e('Add identity', 'lrob-email-toolkit'); ?>
                    </button>
                <?php endif; ?>
            </header>

            <div id="lrob-etk-flash" class="lrob-etk-flash" aria-live="polite"></div>

            <?php $this->render_toggle_notice(); ?>
            <?php $this->render_auth_key_warning(); ?>

            <?php if (!$enabled) : ?>
                <p class="lrob-etk-disabled-message">
                    <?php esc_html_e('Enable the SMTP module to start managing your outgoing emails with LRob — Email Toolkit.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else : ?>
                <?php if ($identities === []) : ?>
                    <div class="lrob-etk-empty">
                        <p><?php esc_html_e('No SMTP identities yet — click "Add identity" to start.', 'lrob-email-toolkit'); ?></p>
                    </div>
                <?php else : ?>
                    <div class="lrob-etk-identities">
                        <?php foreach ($identities as $identity) : ?>
                            <?php $this->render_identity_card($identity); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php $this->render_routing_section($identities); ?>
            <?php endif; ?>

            <?php $this->render_card_template(); ?>
            <?php $this->render_test_send_modal($identities); ?>
            <?php $this->render_conn_popover(); ?>
        </div>

        <script>
        <?php $this->print_inline_js($identities); ?>
        </script>
        <?php
    }

    private function render_identity_card(Identity $identity): void
    {
        ?>
        <article class="lrob-etk-identity-card" data-identity-id="<?php echo (int) $identity->id; ?>" data-state="existing">
            <?php $this->render_card_form($identity); ?>
        </article>
        <?php
    }

    private function render_card_template(): void
    {
        ?>
        <template id="lrob-etk-card-template">
            <article class="lrob-etk-identity-card is-new" data-identity-id="0" data-state="new">
                <?php $this->render_card_form(null); ?>
            </article>
        </template>
        <?php
    }

    private function render_card_form(?Identity $identity): void
    {
        $is_new = !$identity instanceof Identity;
        $overridden = $this->overrides->overridden_fields();
        $site_title = (string) get_bloginfo('name');

        $f = [
            'id'              => $identity?->id ?? 0,
            'slug'            => $identity?->slug ?? '',
            'label'           => $identity?->label ?? '',
            'transport'       => $identity?->transport ?? Identity::TRANSPORT_SMTP,
            'from_email'      => $identity?->from_email ?? '',
            'from_name'       => $identity?->from_name ?? '',
            'smtp_host'       => $identity?->smtp_host ?? '',
            'smtp_port'       => $identity?->smtp_port ?? 465,
            'smtp_encryption' => $identity?->smtp_encryption ?? Identity::ENCRYPTION_SSL,
            'smtp_username'   => $identity?->smtp_username ?? '',
            'smtp_auth'       => $identity ? $identity->smtp_auth : true,
            'force_from'      => $identity ? $identity->force_from : true,
            'reply_to_email'  => $identity?->reply_to_email ?? '',
            'is_default'      => $identity?->is_default ?? false,
            'is_active'       => $identity ? $identity->is_active : true,
            'has_password'    => $identity ? $identity->smtp_password_encrypted !== '' : false,
        ];
        ?>
        <form class="lrob-etk-card-form" novalidate>
            <input type="hidden" name="id" class="lrob-etk-field-id" value="<?php echo (int) $f['id']; ?>">
            <input type="hidden" name="slug" class="lrob-etk-field-slug" value="<?php echo esc_attr($f['slug']); ?>" data-initial="<?php echo esc_attr($f['slug']); ?>">

            <header class="lrob-etk-card-form-head">
                <div class="lrob-etk-segmented lrob-etk-transport-segmented" title="<?php esc_attr_e('Send via', 'lrob-email-toolkit'); ?>">
                    <button type="button" data-mode="<?php echo esc_attr(Identity::TRANSPORT_SMTP); ?>" class="<?php echo $f['transport'] === Identity::TRANSPORT_SMTP ? 'is-active' : ''; ?>">
                        <?php esc_html_e('SMTP', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button" data-mode="<?php echo esc_attr(Identity::TRANSPORT_MAIL); ?>" class="<?php echo $f['transport'] === Identity::TRANSPORT_MAIL ? 'is-active' : ''; ?>">
                        mail()
                    </button>
                    <input type="hidden" name="transport" class="lrob-etk-field-transport" value="<?php echo esc_attr($f['transport']); ?>">
                </div>
                <input
                    type="text"
                    name="label"
                    class="lrob-etk-title-input lrob-etk-field-label"
                    value="<?php echo esc_attr($f['label']); ?>"
                    placeholder="<?php esc_attr_e('Identity name', 'lrob-email-toolkit'); ?>"
                    autocomplete="off">
                <span class="lrob-etk-card-status" aria-live="polite"></span>
                <label class="lrob-etk-inline-switch">
                    <input type="checkbox" name="is_active" class="lrob-etk-field-is-active" value="1" <?php checked($f['is_active']); ?>>
                    <span class="lrob-etk-switch-track"></span>
                    <span class="lrob-etk-inline-switch-label" data-on="<?php esc_attr_e('Active', 'lrob-email-toolkit'); ?>" data-off="<?php esc_attr_e('Inactive', 'lrob-email-toolkit'); ?>">
                        <?php echo $f['is_active']
                            ? esc_html__('Active', 'lrob-email-toolkit')
                            : esc_html__('Inactive', 'lrob-email-toolkit'); ?>
                    </span>
                </label>
            </header>

            <div class="lrob-etk-modal-columns lrob-etk-smtp-only">
                <?php $this->render_mailbox_column($overridden, $f); ?>
                <?php $this->render_server_column($overridden, $f); ?>
            </div>

            <?php $this->render_from_section($overridden, $f, $site_title); ?>

            <div class="lrob-etk-field-errors lrob-etk-card-error" hidden></div>

            <footer class="lrob-etk-card-footer">
                <div class="lrob-etk-card-footer-default">
                    <?php if ($f['is_default']) : ?>
                        <span class="lrob-etk-default-badge">
                            <span class="dashicons dashicons-star-filled"></span>
                            <?php esc_html_e('Default identity', 'lrob-email-toolkit'); ?>
                        </span>
                    <?php else : ?>
                        <button type="button" class="button-link lrob-etk-set-default" data-id="<?php echo (int) $f['id']; ?>" <?php echo $is_new ? 'hidden' : ''; ?>>
                            <span class="dashicons dashicons-star-empty"></span>
                            <?php esc_html_e('Set as default', 'lrob-email-toolkit'); ?>
                        </button>
                    <?php endif; ?>
                    <input type="hidden" name="is_default" class="lrob-etk-field-is-default" value="<?php echo $f['is_default'] ? '1' : ''; ?>">
                </div>
                <div class="lrob-etk-card-footer-actions">
                    <button type="button" class="button button-primary lrob-etk-card-create" data-action="create" <?php echo $is_new ? '' : 'hidden'; ?>>
                        <?php esc_html_e('Create', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button" class="button lrob-etk-card-discard" data-action="discard" <?php echo $is_new ? '' : 'hidden'; ?>>
                        <?php esc_html_e('Discard', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button" class="button lrob-etk-card-test-email" data-action="test" data-id="<?php echo (int) $f['id']; ?>" <?php echo $is_new ? 'hidden' : ''; ?>>
                        <?php esc_html_e('Test email', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button" class="lrob-etk-card-delete-link" data-action="delete" data-id="<?php echo (int) $f['id']; ?>" data-label="<?php echo esc_attr($f['label']); ?>" <?php echo $is_new ? 'hidden' : ''; ?>>
                        <?php esc_html_e('Delete', 'lrob-email-toolkit'); ?>
                    </button>
                </div>
            </footer>
        </form>
        <?php
    }

    /**
     * @param array<int, string>   $overridden
     * @param array<string, mixed> $f
     */
    private function render_mailbox_column(array $overridden, array $f): void
    {
        ?>
        <section class="lrob-etk-form-column">
            <h3 class="lrob-etk-form-column-head">
                <span class="lrob-etk-form-column-title">
                    <?php esc_html_e('Mailbox login', 'lrob-email-toolkit'); ?>
                    <?php Tooltip::render(__('Credentials your mail server uses to authenticate this site — usually the same as logging into webmail.', 'lrob-email-toolkit')); ?>
                </span>
                <label class="lrob-etk-section-switch" title="<?php esc_attr_e('Authentication', 'lrob-email-toolkit'); ?>">
                    <input type="checkbox" name="smtp_auth" class="lrob-etk-field-smtp-auth" value="1" <?php checked($f['smtp_auth']); ?>>
                    <span class="lrob-etk-switch-track"></span>
                    <span class="lrob-etk-section-switch-label"><?php esc_html_e('Auth', 'lrob-email-toolkit'); ?></span>
                </label>
            </h3>

            <div class="lrob-etk-auth-fields">
                <div class="lrob-etk-field">
                    <label>
                        <?php esc_html_e('Username / email', 'lrob-email-toolkit'); ?>
                        <?php $this->render_override_dot($overridden, 'smtp_username'); ?>
                    </label>
                    <input type="text" name="smtp_username" class="lrob-etk-field-username" value="<?php echo esc_attr($f['smtp_username']); ?>" autocomplete="off" placeholder="contact@example.com">
                </div>

                <div class="lrob-etk-field">
                    <label>
                        <?php esc_html_e('Password', 'lrob-email-toolkit'); ?>
                        <?php $this->render_override_dot($overridden, 'smtp_password_encrypted'); ?>
                        <?php Tooltip::render(__('Encrypted at rest with AES-256-GCM, derived from your AUTH_KEY. To put the password in wp-config.php instead, define LROB_ETK_SMTP_PASS.', 'lrob-email-toolkit'), 'lock'); ?>
                    </label>
                    <?php
                    // Bullets in the placeholder when a password is stored
                    // matches the password-manager pattern admins already
                    // know. Empty placeholder when there's nothing to keep.
                    $password_placeholder = $f['has_password']
                        ? str_repeat("\u{2022}", 10)
                        : '';
                    ?>
                    <input type="password" name="smtp_password" class="lrob-etk-field-password" autocomplete="new-password" placeholder="<?php echo esc_attr($password_placeholder); ?>">
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * @param array<int, string>   $overridden
     * @param array<string, mixed> $f
     */
    private function render_server_column(array $overridden, array $f): void
    {
        ?>
        <section class="lrob-etk-form-column">
            <h3 class="lrob-etk-form-column-head">
                <span class="lrob-etk-form-column-title">
                    <?php esc_html_e('SMTP server', 'lrob-email-toolkit'); ?>
                    <?php Tooltip::render(__('Pick your domain\'s mail server. "Custom" is for external relays (Mailgun, SendGrid, etc.) — usually only needed for high-volume sending. Whichever host you pick must have a valid TLS certificate.', 'lrob-email-toolkit')); ?>
                </span>
            </h3>

            <div class="lrob-etk-field">
                <label>
                    <?php esc_html_e('Host', 'lrob-email-toolkit'); ?>
                    <?php $this->render_override_dot($overridden, 'smtp_host'); ?>
                </label>
                <div class="lrob-etk-combo-row">
                    <div class="lrob-etk-combo" data-name="host">
                        <input
                            type="text"
                            name="smtp_host"
                            class="lrob-etk-combo-input lrob-etk-field-host"
                            value="<?php echo esc_attr($f['smtp_host']); ?>"
                            placeholder="smtp.example.com"
                            autocomplete="off">
                        <button type="button" class="lrob-etk-combo-toggle" tabindex="-1"
                                aria-label="<?php esc_attr_e('Show host presets', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        </button>
                        <ul class="lrob-etk-combo-menu" role="listbox" hidden></ul>
                    </div>
                    <button
                        type="button"
                        class="lrob-etk-conn-test"
                        data-action="test-auth"
                        title="<?php esc_attr_e('Test connection', 'lrob-email-toolkit'); ?>"
                        aria-label="<?php esc_attr_e('Test SMTP connection', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
                    </button>
                </div>
                <div class="lrob-etk-host-status" hidden></div>
            </div>

            <div class="lrob-etk-field">
                <label>
                    <?php esc_html_e('Encryption & port', 'lrob-email-toolkit'); ?>
                    <?php Tooltip::render(__('STARTTLS upgrades a plain connection to encrypted (port 587 — most common). TLS connects encrypted from the start (port 465). None sends plaintext on port 25 — almost always blocked by hosting providers.', 'lrob-email-toolkit')); ?>
                </label>
                <div class="lrob-etk-encryption-row">
                    <select name="smtp_encryption" class="lrob-etk-select lrob-etk-field-encryption">
                        <option value="<?php echo esc_attr(Identity::ENCRYPTION_STARTTLS); ?>" <?php selected($f['smtp_encryption'], Identity::ENCRYPTION_STARTTLS); ?>>STARTTLS</option>
                        <option value="<?php echo esc_attr(Identity::ENCRYPTION_SSL); ?>" <?php selected($f['smtp_encryption'], Identity::ENCRYPTION_SSL); ?>>TLS</option>
                        <option value="<?php echo esc_attr(Identity::ENCRYPTION_NONE); ?>" <?php selected($f['smtp_encryption'], Identity::ENCRYPTION_NONE); ?>><?php esc_html_e('None', 'lrob-email-toolkit'); ?></option>
                    </select>
                    <div class="lrob-etk-port-input">
                        <label for="lrob-etk-port-<?php echo (int) $f['id']; ?>"><?php esc_html_e('Port', 'lrob-email-toolkit'); ?></label>
                        <input type="number" id="lrob-etk-port-<?php echo (int) $f['id']; ?>" name="smtp_port" class="lrob-etk-field-port" min="1" max="65535" value="<?php echo (int) $f['smtp_port']; ?>">
                    </div>
                </div>
                <div class="lrob-etk-none-warning" <?php echo $f['smtp_encryption'] === Identity::ENCRYPTION_NONE ? '' : 'hidden'; ?>>
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <?php esc_html_e('Plaintext SMTP on port 25 is almost always blocked by web hosts. Use STARTTLS or TLS.', 'lrob-email-toolkit'); ?>
                </div>
            </div>

        </section>
        <?php
    }

    /**
     * @param array<int, string>   $overridden
     * @param array<string, mixed> $f
     */
    private function render_from_section(array $overridden, array $f, string $site_title): void
    {
        ?>
        <section class="lrob-etk-form-row-full lrob-etk-from-section">
            <h3>
                <?php esc_html_e('From', 'lrob-email-toolkit'); ?>
                <?php Tooltip::render(__('The address shown to recipients. Empty fields use the mailbox login (for From email) or site title (for From name) automatically — placeholders show what will be used.', 'lrob-email-toolkit')); ?>
            </h3>

            <div class="lrob-etk-toggle-row lrob-etk-force-row">
                <label class="lrob-etk-switch">
                    <input type="checkbox" name="force_from" class="lrob-etk-field-force-from" value="1" <?php checked($f['force_from']); ?>>
                    <span class="lrob-etk-switch-track"></span>
                </label>
                <span class="lrob-etk-switch-label"><?php esc_html_e('Force "From" on every outgoing email', 'lrob-email-toolkit'); ?></span>
                <?php Tooltip::render(__('When on, overrides the From address even when other plugins try to set their own.', 'lrob-email-toolkit')); ?>
            </div>

            <div class="lrob-etk-from-grid">
                <div class="lrob-etk-field">
                    <label>
                        <?php esc_html_e('From email', 'lrob-email-toolkit'); ?>
                        <?php $this->render_override_dot($overridden, 'from_email'); ?>
                    </label>
                    <div class="lrob-etk-combo" data-name="from-email">
                        <input
                            type="email"
                            name="from_email"
                            class="lrob-etk-combo-input lrob-etk-field-from-email"
                            value="<?php echo esc_attr($f['from_email']); ?>"
                            placeholder="<?php echo esc_attr($f['smtp_username'] !== '' ? sprintf(__('Default — %s', 'lrob-email-toolkit'), $f['smtp_username']) : __('Default — uses mailbox login', 'lrob-email-toolkit')); ?>"
                            autocomplete="off">
                        <button type="button" class="lrob-etk-combo-toggle" tabindex="-1"
                                aria-label="<?php esc_attr_e('Show presets', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        </button>
                        <ul class="lrob-etk-combo-menu" role="listbox" hidden></ul>
                    </div>
                    <div class="lrob-etk-from-warning lrob-etk-from-warning-el" hidden></div>
                </div>
                <div class="lrob-etk-field">
                    <label>
                        <?php esc_html_e('From name', 'lrob-email-toolkit'); ?>
                        <?php $this->render_override_dot($overridden, 'from_name'); ?>
                    </label>
                    <div class="lrob-etk-combo" data-name="from-name">
                        <input
                            type="text"
                            name="from_name"
                            class="lrob-etk-combo-input lrob-etk-field-from-name"
                            value="<?php echo esc_attr($f['from_name']); ?>"
                            placeholder="<?php echo esc_attr(sprintf(__('Default — %s', 'lrob-email-toolkit'), $site_title)); ?>"
                            autocomplete="off">
                        <button type="button" class="lrob-etk-combo-toggle" tabindex="-1"
                                aria-label="<?php esc_attr_e('Show presets', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        </button>
                        <ul class="lrob-etk-combo-menu" role="listbox" hidden></ul>
                    </div>
                </div>
                <div class="lrob-etk-field">
                    <label><?php esc_html_e('Reply-to', 'lrob-email-toolkit'); ?> <span class="lrob-etk-label-hint"><?php esc_html_e('(optional)', 'lrob-email-toolkit'); ?></span></label>
                    <input type="email" name="reply_to_email" class="lrob-etk-field-reply-to" value="<?php echo esc_attr($f['reply_to_email']); ?>">
                </div>
            </div>
        </section>
        <?php
    }

    /** @param array<int, Identity> $identities */
    private function render_routing_section(array $identities): void
    {
        if (count($identities) < 2) {
            return;
        }
        $sources = $this->known_sources();
        $rules = $this->routing->all();
        ?>
        <h2 class="lrob-etk-section-title">
            <?php esc_html_e('Routing rules', 'lrob-email-toolkit'); ?>
            <?php Tooltip::render(__('Each source can use a specific identity. Sources without a rule fall back to the default.', 'lrob-email-toolkit')); ?>
        </h2>
        <form id="lrob-etk-routing-form" class="lrob-etk-routing-form">
            <div class="lrob-etk-routing-grid">
                <?php foreach ($sources as $source => $label) :
                    $current = $rules[$source] ?? '';
                    ?>
                    <div class="lrob-etk-routing-row">
                        <label>
                            <strong><?php echo esc_html($label); ?></strong>
                            <code><?php echo esc_html($source); ?></code>
                        </label>
                        <select name="routing[<?php echo esc_attr($source); ?>]">
                            <option value=""><?php esc_html_e('— Use default —', 'lrob-email-toolkit'); ?></option>
                            <?php foreach ($identities as $identity) : ?>
                                <option value="<?php echo esc_attr($identity->slug); ?>" <?php selected($current, $identity->slug); ?>>
                                    <?php echo esc_html($identity->label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
            <p>
                <button type="submit" class="button button-secondary"><?php esc_html_e('Save routing rules', 'lrob-email-toolkit'); ?></button>
            </p>
        </form>
        <?php
    }

    /** @param array<int, Identity> $identities */
    private function render_test_send_modal(array $identities): void
    {
        $current_user = wp_get_current_user();
        $admin_email = (string) get_option('admin_email');
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-test-modal" role="dialog" aria-modal="true" hidden>
            <div class="lrob-etk-modal-backdrop" data-lrob-etk-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--small" role="document">
                <header class="lrob-etk-modal-header">
                    <h2 class="lrob-etk-modal-title-text"><?php esc_html_e('Send a test email', 'lrob-email-toolkit'); ?></h2>
                    <button type="button" class="lrob-etk-modal-close" data-lrob-etk-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </header>

                <form id="lrob-etk-test-form" class="lrob-etk-modal-body">
                    <input type="hidden" id="lrob-etk-test-id" value="0">

                    <?php if (count($identities) > 1) : ?>
                        <div class="lrob-etk-field">
                            <label for="lrob-etk-test-identity-pick"><?php esc_html_e('Identity', 'lrob-email-toolkit'); ?></label>
                            <select id="lrob-etk-test-identity-pick">
                                <?php foreach ($identities as $identity) : ?>
                                    <option value="<?php echo (int) $identity->id; ?>" <?php selected($identity->is_default); ?>>
                                        <?php echo esc_html($identity->label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="lrob-etk-field">
                        <label for="lrob-etk-test-recipient-choice"><?php esc_html_e('Recipient', 'lrob-email-toolkit'); ?></label>
                        <select id="lrob-etk-test-recipient-choice">
                            <?php /* translators: %s: current user's email address */ ?>
                            <option value="current"><?php echo esc_html(sprintf(__('Me (%s)', 'lrob-email-toolkit'), $current_user->user_email)); ?></option>
                            <?php /* translators: %s: site admin email address */ ?>
                            <option value="admin"><?php echo esc_html(sprintf(__('Site admin (%s)', 'lrob-email-toolkit'), $admin_email)); ?></option>
                            <option value="custom"><?php esc_html_e('Custom address…', 'lrob-email-toolkit'); ?></option>
                        </select>
                    </div>

                    <div class="lrob-etk-field" id="lrob-etk-test-custom-wrap" hidden>
                        <label for="lrob-etk-test-recipient-custom"><?php esc_html_e('Custom recipient', 'lrob-email-toolkit'); ?></label>
                        <input type="email" id="lrob-etk-test-recipient-custom" placeholder="you@example.com">
                    </div>

                    <div id="lrob-etk-test-result" class="lrob-etk-test-result" hidden></div>
                </form>

                <footer class="lrob-etk-modal-footer">
                    <button type="button" class="button" data-lrob-etk-close><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></button>
                    <button type="button" class="button button-primary" id="lrob-etk-send-test">
                        <?php esc_html_e('Send test', 'lrob-email-toolkit'); ?>
                    </button>
                </footer>
            </div>
        </div>
        <?php
    }

    private function render_conn_popover(): void
    {
        ?>
        <div class="lrob-etk-popover" id="lrob-etk-conn-popover" role="dialog" aria-label="<?php esc_attr_e('Connection test result', 'lrob-email-toolkit'); ?>" hidden>
            <header class="lrob-etk-popover-header">
                <h3><?php esc_html_e('Connection test', 'lrob-email-toolkit'); ?></h3>
                <button type="button" class="lrob-etk-popover-close" aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>"
                        onclick="this.closest('.lrob-etk-popover').hidden = true">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </header>
            <div class="lrob-etk-popover-body">
                <p class="lrob-etk-popover-message"></p>
                <pre class="lrob-etk-popover-debug" hidden></pre>
            </div>
            <footer class="lrob-etk-popover-footer">
                <button type="button" class="button button-primary lrob-etk-popover-rerun">
                    <?php esc_html_e('Run again', 'lrob-email-toolkit'); ?>
                </button>
            </footer>
        </div>
        <?php
    }

    /** @param array<int, Identity> $identities */
    private function print_inline_js(array $identities): void
    {
        $data = [];
        foreach ($identities as $identity) {
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
                'force_from'      => $identity->force_from,
                'reply_to_email'  => $identity->reply_to_email,
                'is_default'      => $identity->is_default,
                'is_active'       => $identity->is_active,
            ];
        }
        ?>
        window.lrobEtkSmtp = {
            ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
            nonce: <?php echo wp_json_encode(wp_create_nonce(AjaxController::NONCE_ACTION)); ?>,
            actions: {
                save:        <?php echo wp_json_encode(AjaxController::ACTION_SAVE); ?>,
                delete:      <?php echo wp_json_encode(AjaxController::ACTION_DELETE); ?>,
                setDefault:  <?php echo wp_json_encode(AjaxController::ACTION_SET_DEFAULT); ?>,
                testAuth:    <?php echo wp_json_encode(AjaxController::ACTION_TEST_AUTH); ?>,
                testSend:    <?php echo wp_json_encode(AjaxController::ACTION_TEST_SEND); ?>,
                saveRouting: <?php echo wp_json_encode(AjaxController::ACTION_SAVE_ROUTING); ?>,
                checkHost:   <?php echo wp_json_encode(AjaxController::ACTION_CHECK_HOST); ?>,
                lookupMx:    <?php echo wp_json_encode(AjaxController::ACTION_LOOKUP_MX); ?>
            },
            identities: <?php echo wp_json_encode($data); ?>,
            siteTitle: <?php echo wp_json_encode(get_bloginfo('name')); ?>,
            i18n: {
                deleteConfirm:    <?php
                    /* translators: %s: the SMTP identity's display name */
                    echo wp_json_encode(__('Delete the identity "%s"?', 'lrob-email-toolkit'));
                ?>,
                saving:           <?php echo wp_json_encode(__('Saving…', 'lrob-email-toolkit')); ?>,
                saved:            <?php echo wp_json_encode(__('Saved', 'lrob-email-toolkit')); ?>,
                saveFailed:       <?php echo wp_json_encode(__('Save failed', 'lrob-email-toolkit')); ?>,
                testing:          <?php echo wp_json_encode(__('Testing…', 'lrob-email-toolkit')); ?>,
                sending:          <?php echo wp_json_encode(__('Sending…', 'lrob-email-toolkit')); ?>,
                resolves:         <?php echo wp_json_encode(__('✓ resolves', 'lrob-email-toolkit')); ?>,
                noResolve:        <?php echo wp_json_encode(__('✗ cannot resolve', 'lrob-email-toolkit')); ?>,
                domainMismatch:   <?php echo wp_json_encode(__('Domain differs from the mailbox login. Most servers will reject or rewrite this — only use with relays that support arbitrary senders.', 'lrob-email-toolkit')); ?>,
                userMismatch:     <?php echo wp_json_encode(__('Local part differs from the mailbox login. Some servers may rewrite the From address.', 'lrob-email-toolkit')); ?>,
                unknownError:     <?php echo wp_json_encode(__('Something went wrong.', 'lrob-email-toolkit')); ?>,
                active:           <?php echo wp_json_encode(__('Active', 'lrob-email-toolkit')); ?>,
                inactive:         <?php echo wp_json_encode(__('Inactive', 'lrob-email-toolkit')); ?>,
                createBtn:        <?php echo wp_json_encode(__('Create', 'lrob-email-toolkit')); ?>,
                testAuthBtn:      <?php echo wp_json_encode(__('Test connection', 'lrob-email-toolkit')); ?>,
                sendBtn:          <?php echo wp_json_encode(__('Send test', 'lrob-email-toolkit')); ?>
            }
        };
        <?php
        $this->print_driver_js();
    }

    private function print_driver_js(): void
    {
        ?>
(function () {
    var S = window.lrobEtkSmtp;
    if (!S) return;

    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function field(card, cls) { return card.querySelector('.lrob-etk-field-' + cls); }
    function slugify(s) {
        return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').substring(0, 50);
    }

    function ajax(action, params) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('_nonce', S.nonce);
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (v === undefined || v === null) return;
            if (typeof v === 'boolean') v = v ? '1' : '';
            if (Array.isArray(v)) { v.forEach(function (item) { fd.append(k + '[]', item); }); return; }
            if (typeof v === 'object') {
                Object.keys(v).forEach(function (sub) { fd.append(k + '[' + sub + ']', v[sub]); });
                return;
            }
            fd.append(k, v);
        });
        return fetch(S.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); });
    }

    function flash(msg, type) {
        var holder = document.getElementById('lrob-etk-flash');
        if (!holder) return;
        var div = document.createElement('div');
        div.className = 'notice notice-' + (type === 'error' ? 'error' : 'success') + ' is-dismissible';
        var p = document.createElement('p'); p.textContent = msg; div.appendChild(p);
        holder.appendChild(div);
        setTimeout(function () { if (div.parentNode) div.parentNode.removeChild(div); }, 5000);
    }

    function setStatus(card, state, message) {
        var el = card.querySelector('.lrob-etk-card-status');
        if (!el) return;
        el.className = 'lrob-etk-card-status is-' + state;
        el.textContent = message || '';
        if (state === 'saved') {
            clearTimeout(card._statusTimer);
            card._statusTimer = setTimeout(function () {
                el.className = 'lrob-etk-card-status';
                el.textContent = '';
            }, 1500);
        }
    }

    function applyCardState(card) {
        var transport = field(card, 'transport').value;
        var smtpOnly = card.querySelector('.lrob-etk-smtp-only');
        if (smtpOnly) smtpOnly.style.display = transport === 'mail' ? 'none' : '';

        // Auth visibility
        var authOn = field(card, 'smtp-auth').checked;
        $$('.lrob-etk-auth-fields > .lrob-etk-field', card).forEach(function (div) {
            div.style.display = authOn ? '' : 'none';
        });

        // Active label
        var activeEl = card.querySelector('.lrob-etk-inline-switch-label');
        var activeChk = field(card, 'is-active');
        if (activeEl && activeChk) {
            activeEl.textContent = activeChk.checked ? S.i18n.active : S.i18n.inactive;
        }

        // None warning
        var encChecked = card.querySelector('input[name="smtp_encryption"]:checked');
        var noneWarn = card.querySelector('.lrob-etk-none-warning');
        if (noneWarn) noneWarn.hidden = !(encChecked && encChecked.value === '');

        rebuildHostPresets(card);
        updateFromEmailDefaultLabel(card);
        syncFromWarning(card);
    }

    function updateFromEmailPlaceholder(card) {
        var input = card.querySelector('.lrob-etk-field-from-email');
        if (!input) return;
        var user = field(card, 'username').value.trim();
        input.placeholder = user
            ? ('<?php echo esc_js(__('Default — ', 'lrob-email-toolkit')); ?>' + user)
            : '<?php echo esc_js(__('Default — uses mailbox login', 'lrob-email-toolkit')); ?>';
    }
    function rebuildHostPresets() { /* presets are built on demand by combobox */ }
    function updateFromEmailDefaultLabel(card) { updateFromEmailPlaceholder(card); }

    /**
     * Combobox setup — delegates open/close/click handling to the shared
     * lrobEtkControls.attachCombobox component (admin/js/etk-controls.js).
     * SMTP only supplies the preset-building logic and the post-select side
     * effects (host check / from-email warning / save).
     */
    function setupCombobox(card, name) {
        var combo = card.querySelector('.lrob-etk-combo[data-name="' + name + '"]');
        if (!combo) return;
        if (!window.lrobEtkControls || !window.lrobEtkControls.attachCombobox) return;
        window.lrobEtkControls.attachCombobox(combo, {
            mode: 'free',
            populate: function () { return buildComboOptions(card, name); },
            onSelect: function () {
                if (name === 'host') runHostCheck(card);
                if (name === 'from-email') syncFromWarning(card);
                queueSave(card, 0);
            }
        });
    }

    function buildComboOptions(card, name) {
        var items = [];
        var user = field(card, 'username').value.trim();
        if (name === 'host') {
            var at = user.lastIndexOf('@');
            var domain = at !== -1 ? user.substring(at + 1).toLowerCase() : '';
            if (domain) {
                items.push({ value: 'mail.' + domain, label: 'mail.' + domain });
                items.push({ value: 'smtp.' + domain, label: 'smtp.' + domain });
                items.push({ value: domain, label: domain });
            }
            if (card._mxHost) {
                items.push({ value: card._mxHost, label: 'MX: ' + card._mxHost });
            }
        } else if (name === 'from-email') {
            if (user) {
                items.push({ value: user, label: '<?php echo esc_js(__('Default — ', 'lrob-email-toolkit')); ?>' + user });
            }
            items.push({ value: '', label: '<?php echo esc_js(__('Use automatic (uses mailbox login)', 'lrob-email-toolkit')); ?>' });
        } else if (name === 'from-name') {
            items.push({ value: S.siteTitle, label: '<?php echo esc_js(__('Default — ', 'lrob-email-toolkit')); ?>' + S.siteTitle });
            items.push({ value: '', label: '<?php echo esc_js(__('Use automatic (uses site title)', 'lrob-email-toolkit')); ?>' });
        }
        return items;
    }

    // Outside-click closure is owned by lrobEtkControls — no local handler needed.

    function syncFromWarning(card) {
        var warn = card.querySelector('.lrob-etk-from-warning-el');
        if (!warn) return;
        var u = field(card, 'username').value.trim();
        var f = field(card, 'from-email').value.trim();
        // No warning when From email is empty (auto mode).
        if (!u || !f || u === f) { warn.hidden = true; return; }
        var uAt = u.lastIndexOf('@'); var fAt = f.lastIndexOf('@');
        if (uAt === -1 || fAt === -1) { warn.hidden = true; return; }
        var uDom = u.substring(uAt + 1); var fDom = f.substring(fAt + 1);
        if (uDom !== fDom) {
            warn.className = 'lrob-etk-from-warning lrob-etk-from-warning-el is-danger';
            warn.textContent = S.i18n.domainMismatch;
        } else {
            warn.className = 'lrob-etk-from-warning lrob-etk-from-warning-el is-warning';
            warn.textContent = S.i18n.userMismatch;
        }
        warn.hidden = false;
    }

    function runHostCheck(card) {
        var host = field(card, 'host').value.trim();
        var status = card.querySelector('.lrob-etk-host-status');
        if (!status) return;
        if (!host || host.indexOf('.') === -1) {
            status.hidden = true;
            return;
        }
        clearTimeout(card._hostCheckTimer);
        card._hostCheckTimer = setTimeout(function () {
            ajax(S.actions.checkHost, { host: host }).then(function (resp) {
                if (resp.success && resp.data.host === host) {
                    status.hidden = false;
                    status.className = 'lrob-etk-host-status ' + (resp.data.resolves ? 'is-ok' : 'is-bad');
                    status.textContent = resp.data.resolves ? S.i18n.resolves : S.i18n.noResolve;
                }
            });
        }, 600);
    }

    function runMxLookup(card) {
        var user = field(card, 'username').value.trim();
        var at = user.lastIndexOf('@');
        if (at === -1) return;
        var domain = user.substring(at + 1).toLowerCase();
        if (!domain || domain.indexOf('.') === -1) return;
        if (card._lastMxDomain === domain) return;
        card._lastMxDomain = domain;
        ajax(S.actions.lookupMx, { domain: domain }).then(function (resp) {
            if (resp.success && resp.data.hosts && resp.data.hosts.length > 0) {
                card._mxHost = resp.data.hosts[0];
                updateHostSuggestions(card);
            }
        });
    }

    function wireCardListeners(card) {
        if (card.getAttribute('data-wired') === '1') return;
        card.setAttribute('data-wired', '1');

        var labelInput = field(card, 'label');
        if (labelInput) {
            labelInput.addEventListener('input', function () {
                var initial = field(card, 'slug').getAttribute('data-initial') || '';
                if (initial && field(card, 'slug').value !== '') return;
                var s = slugify(labelInput.value);
                field(card, 'slug').value = s;
            });
        }

        field(card, 'username').addEventListener('input', function () {
            applyCardState(card);
        });
        field(card, 'username').addEventListener('blur', function () { runMxLookup(card); });
        field(card, 'smtp-auth').addEventListener('change', function () { applyCardState(card); });
        field(card, 'is-active').addEventListener('change', function () { applyCardState(card); });

        // Combobox: input + dropdown menu for each of these three fields.
        setupCombobox(card, 'host');
        setupCombobox(card, 'from-email');
        setupCombobox(card, 'from-name');

        // Host input also triggers DNS resolves check
        field(card, 'host').addEventListener('input', function () { runHostCheck(card); });
        field(card, 'from-email').addEventListener('input', function () { syncFromWarning(card); });

        // Encryption → port autofill. The encryption control is a <select>
        // (was radio buttons in an earlier iteration — the old listener
        // queried `input[name="smtp_encryption"]:checked` and silently
        // matched nothing once the markup flipped to a dropdown).
        var portDefaults = { 'tls': 587, 'ssl': 465, '': 25 };
        var encSelect = card.querySelector('select[name="smtp_encryption"]');
        if (encSelect) {
            var portLast = portDefaults[encSelect.value] !== undefined ? portDefaults[encSelect.value] : 465;
            encSelect.addEventListener('change', function () {
                applyCardState(card);
                var def = portDefaults[encSelect.value];
                if (def === undefined) return;
                var portEl = field(card, 'port');
                var current = parseInt(portEl.value, 10);
                // Only auto-update the port if it's empty or still on the
                // previous encryption's default — preserves a user's
                // explicitly-typed non-default port.
                if (!portEl.value || current === portLast) portEl.value = def;
                portLast = def;
            });
        }

        // Transport segmented
        $$('.lrob-etk-transport-segmented', card).forEach(function (group) {
            group.addEventListener('click', function (e) {
                var btn = e.target.closest('button[data-mode]');
                if (!btn) return;
                var hidden = group.querySelector('input[type="hidden"]');
                var mode = btn.getAttribute('data-mode');
                hidden.value = mode;
                $$('button[data-mode]', group).forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                });
                applyCardState(card);
                queueSave(card);
            });
        });

        // Auto-save bindings
        $$('input[type="text"], input[type="email"], input[type="password"], input[type="number"]', card).forEach(function (input) {
            input.addEventListener('input', function () { queueSave(card, 1000); });
            input.addEventListener('blur', function () { flushSave(card); });
        });
        $$('input[type="radio"], input[type="checkbox"]', card).forEach(function (input) {
            input.addEventListener('change', function () { queueSave(card, 0); });
        });
    }

    function queueSave(card, delay) {
        if (card.getAttribute('data-state') === 'new') return;
        if (card._saveTimer) clearTimeout(card._saveTimer);
        card._saveTimer = setTimeout(function () { saveCard(card); }, delay || 0);
    }

    function flushSave(card) {
        if (card._saveTimer) {
            clearTimeout(card._saveTimer);
            card._saveTimer = null;
            saveCard(card);
        }
    }

    function saveCard(card) {
        var fd = new FormData(card.querySelector('.lrob-etk-card-form'));
        fd.append('action', S.actions.save);
        fd.append('_nonce', S.nonce);

        var data = S.identities[parseInt(card.getAttribute('data-identity-id'), 10)];
        var wasDefault = data ? !!data.is_default : false;
        var isDefaultNow = field(card, 'is-default').value === '1';
        var defaultToggled = isDefaultNow !== wasDefault;

        setStatus(card, 'saving', S.i18n.saving);
        var errBox = card.querySelector('.lrob-etk-card-error'); errBox.hidden = true;

        return fetch(S.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success) {
                    if (data) data.is_default = isDefaultNow;
                    if (defaultToggled) {
                        setStatus(card, 'saved', S.i18n.saved);
                        setTimeout(function () { window.location.reload(); }, 400);
                        return;
                    }
                    setStatus(card, 'saved', S.i18n.saved);
                    if (card.getAttribute('data-state') === 'new' && resp.data && resp.data.id) {
                        card.setAttribute('data-identity-id', resp.data.id);
                        card.setAttribute('data-state', 'existing');
                        card.classList.remove('is-new');
                        field(card, 'id').value = resp.data.id;
                        setTimeout(function () { window.location.reload(); }, 600);
                    }
                } else {
                    setStatus(card, 'failed', S.i18n.saveFailed);
                    errBox.hidden = false;
                    errBox.textContent = (resp.data && resp.data.message) || S.i18n.unknownError;
                }
            })
            .catch(function () {
                setStatus(card, 'failed', S.i18n.saveFailed);
                errBox.hidden = false;
                errBox.textContent = S.i18n.unknownError;
            });
    }

    // ---------------- Card actions ----------------
    document.addEventListener('click', function (e) {
        var card = e.target.closest && e.target.closest('.lrob-etk-identity-card');
        if (!card) return;
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.getAttribute('data-action');

        if (action === 'create') {
            card.setAttribute('data-state', 'existing-pending');
            saveCard(card);
        } else if (action === 'discard') {
            card.parentNode.removeChild(card);
        } else if (action === 'delete') {
            var label = btn.getAttribute('data-label') || '';
            if (!confirm(S.i18n.deleteConfirm.replace('%s', label))) return;
            ajax(S.actions.delete, { id: btn.getAttribute('data-id') }).then(function (resp) {
                if (resp.success) { flash(resp.data.message, 'success'); setTimeout(function () { window.location.reload(); }, 300); }
                else flash((resp.data && resp.data.message) || S.i18n.unknownError, 'error');
            });
        } else if (action === 'test') {
            openTestModal(parseInt(btn.getAttribute('data-id'), 10), btn);
        } else if (action === 'test-auth') {
            handleConnTestClick(card, btn);
        }
    });

    // Set-default uses a separate button outside the form-actions cluster.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.lrob-etk-set-default');
        if (!btn) return;
        ajax(S.actions.setDefault, { id: btn.getAttribute('data-id') }).then(function (resp) {
            if (resp.success) { flash(resp.data.message, 'success'); setTimeout(function () { window.location.reload(); }, 300); }
            else flash((resp.data && resp.data.message) || S.i18n.unknownError, 'error');
        });
    });

    // Inline connection-test icon button — click cycles between "run now"
    // (no result yet) and "show details popover" (after a result).
    function handleConnTestClick(card, btn) {
        if (btn._lastResult) {
            showConnPopover(card, btn);
        } else {
            runTestAuth(card, btn);
        }
    }

    function runTestAuth(card, btn) {
        btn.classList.remove('is-ok', 'is-fail');
        btn.classList.add('is-testing');
        var icon = btn.querySelector('.dashicons');
        if (icon) icon.className = 'dashicons dashicons-update';
        btn.disabled = true;
        btn.setAttribute('title', S.i18n.testing);

        var fd = new FormData(card.querySelector('.lrob-etk-card-form'));
        fd.append('action', S.actions.testAuth);
        fd.append('_nonce', S.nonce);

        return fetch(S.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                btn.classList.remove('is-testing');
                btn.disabled = false;
                if (resp.success) {
                    btn.classList.add('is-ok');
                    if (icon) icon.className = 'dashicons dashicons-yes-alt';
                    btn._lastResult = { ok: true, message: resp.data.message, debug: resp.data.debug || null };
                    btn.setAttribute('title', resp.data.message);
                } else {
                    btn.classList.add('is-fail');
                    if (icon) icon.className = 'dashicons dashicons-warning';
                    btn._lastResult = {
                        ok: false,
                        message: (resp.data && resp.data.message) || S.i18n.unknownError,
                        debug: (resp.data && resp.data.debug) || null
                    };
                    btn.setAttribute('title', btn._lastResult.message);
                    showConnPopover(card, btn);  // auto-open details on failure
                }
            })
            .catch(function () {
                btn.classList.remove('is-testing');
                btn.classList.add('is-fail');
                btn.disabled = false;
                if (icon) icon.className = 'dashicons dashicons-warning';
                btn._lastResult = { ok: false, message: S.i18n.unknownError, debug: null };
                showConnPopover(card, btn);
            });
    }

    function showConnPopover(card, anchorBtn) {
        var popover = document.getElementById('lrob-etk-conn-popover');
        if (!popover) return;
        var result = anchorBtn._lastResult || { ok: false, message: '' };
        popover.classList.toggle('is-success', !!result.ok);
        popover.classList.toggle('is-failure', !result.ok);
        var msg = popover.querySelector('.lrob-etk-popover-message');
        if (msg) msg.textContent = (result.ok ? '✓ ' : '✗ ') + result.message;
        var debug = popover.querySelector('.lrob-etk-popover-debug');
        if (debug) {
            if (result.debug) { debug.textContent = result.debug; debug.hidden = false; }
            else debug.hidden = true;
        }
        var rerun = popover.querySelector('.lrob-etk-popover-rerun');
        if (rerun) {
            rerun.onclick = function () {
                popover.hidden = true;
                runTestAuth(card, anchorBtn);
            };
        }
        anchorPopover(popover, anchorBtn);
    }

    function anchorPopover(popover, anchorEl) {
        popover.hidden = false;
        // Measure after show
        var pRect = popover.getBoundingClientRect();
        var aRect = anchorEl.getBoundingClientRect();
        var width = pRect.width || 320;
        var height = pRect.height || 100;
        var margin = 8;

        var top = aRect.bottom + margin;
        if (top + height > window.innerHeight - margin) {
            top = aRect.top - height - margin;
            if (top < margin) top = margin;
        }
        var left = aRect.left;
        if (left + width > window.innerWidth - margin) {
            left = window.innerWidth - width - margin;
        }
        if (left < margin) left = margin;

        popover.style.position = 'fixed';
        popover.style.top = top + 'px';
        popover.style.left = left + 'px';
    }

    function closeAllPopovers() {
        $$('.lrob-etk-popover').forEach(function (p) { p.hidden = true; });
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest && (e.target.closest('.lrob-etk-popover') || e.target.closest('[data-action="test-auth"]') || e.target.closest('[data-action="test"]'))) {
            return;  // clicks inside or that opened a popover don't close
        }
        closeAllPopovers();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllPopovers();
    });

    // Add identity
    var addBtn = document.getElementById('lrob-etk-add-identity');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var template = document.getElementById('lrob-etk-card-template');
            if (!template) return;
            var fragment = template.content.cloneNode(true);
            var newCard = fragment.querySelector('.lrob-etk-identity-card');
            var container = document.querySelector('.lrob-etk-identities');
            if (!container) {
                container = document.createElement('div');
                container.className = 'lrob-etk-identities';
                var emptyEl = document.querySelector('.lrob-etk-empty');
                if (emptyEl) emptyEl.parentNode.replaceChild(container, emptyEl);
                else document.querySelector('.lrob-etk-add-row').insertAdjacentElement('beforebegin', container);
            }
            container.appendChild(newCard);
            wireCardListeners(newCard);
            applyCardState(newCard);
            field(newCard, 'label').focus();
            newCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }

    // ---------------- Test email anchored popover ----------------
    function openTestModal(id, anchorBtn) {
        var modal = document.getElementById('lrob-etk-test-modal');
        if (!modal) return;
        document.getElementById('lrob-etk-test-id').value = id || 0;
        var pick = document.getElementById('lrob-etk-test-identity-pick');
        if (pick && id) pick.value = id;
        document.getElementById('lrob-etk-test-result').hidden = true;
        var dialog = modal.querySelector('.lrob-etk-modal-dialog');
        if (anchorBtn && dialog) {
            modal.setAttribute('data-anchored', '1');
            modal.hidden = false;
            anchorPopover(dialog, anchorBtn);
        } else {
            modal.removeAttribute('data-anchored');
            modal.hidden = false;
        }
        document.body.classList.add('lrob-etk-modal-open');
    }
    function closeTestModal() {
        var modal = document.getElementById('lrob-etk-test-modal');
        if (modal) modal.hidden = true;
        document.body.classList.remove('lrob-etk-modal-open');
    }
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('[data-lrob-etk-close]')) {
            e.preventDefault(); closeTestModal();
        }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeTestModal(); });

    var recipientChoice = document.getElementById('lrob-etk-test-recipient-choice');
    if (recipientChoice) {
        recipientChoice.addEventListener('change', function () {
            document.getElementById('lrob-etk-test-custom-wrap').hidden = recipientChoice.value !== 'custom';
        });
    }
    var sendBtn = document.getElementById('lrob-etk-send-test');
    if (sendBtn) {
        sendBtn.addEventListener('click', function () {
            sendBtn.disabled = true; sendBtn.textContent = S.i18n.sending;
            var result = document.getElementById('lrob-etk-test-result');
            result.hidden = false; result.className = 'lrob-etk-test-result is-pending';
            result.textContent = S.i18n.sending;
            var pickEl = document.getElementById('lrob-etk-test-identity-pick');
            var id = pickEl ? pickEl.value : document.getElementById('lrob-etk-test-id').value;
            ajax(S.actions.testSend, {
                id: id,
                recipient_choice: document.getElementById('lrob-etk-test-recipient-choice').value,
                recipient_custom: document.getElementById('lrob-etk-test-recipient-custom').value
            }).then(function (resp) {
                if (resp.success) {
                    result.className = 'lrob-etk-test-result is-success';
                    result.textContent = '✓ ' + resp.data.message;
                } else {
                    result.className = 'lrob-etk-test-result is-failure';
                    result.textContent = '✗ ' + ((resp.data && resp.data.message) || S.i18n.unknownError);
                }
            }).finally(function () {
                sendBtn.disabled = false; sendBtn.textContent = S.i18n.sendBtn;
            });
        });
    }

    // Routing form
    var routingForm = document.getElementById('lrob-etk-routing-form');
    if (routingForm) {
        routingForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var routing = {};
            $$('select', routingForm).forEach(function (sel) {
                var m = sel.getAttribute('name').match(/^routing\[(.+)\]$/);
                if (m) routing[m[1]] = sel.value;
            });
            ajax(S.actions.saveRouting, { routing: routing }).then(function (resp) {
                if (resp.success) flash(resp.data.message, 'success');
                else flash((resp.data && resp.data.message) || S.i18n.unknownError, 'error');
            });
        });
    }

    // Initial wiring
    $$('.lrob-etk-identity-card').forEach(function (card) {
        wireCardListeners(card);
        applyCardState(card);
        runMxLookup(card);
    });
})();
        <?php
    }

    /** @param array<int, string> $overridden */
    private function render_override_dot(array $overridden, string $field): void
    {
        if (!in_array($field, $overridden, true)) {
            return;
        }
        Tooltip::render(
            __('Overridden by a wp-config.php constant — value here is ignored at runtime.', 'lrob-email-toolkit'),
            'lock'
        );
    }

    /** @return array<string, string> */
    private function known_sources(): array
    {
        $sources = [
            SourceResolver::SOURCE_DEFAULT => __('Default emails', 'lrob-email-toolkit'),
        ];
        if (class_exists('WooCommerce')) {
            $sources[SourceResolver::SOURCE_WOOCOMMERCE] = __('WooCommerce emails', 'lrob-email-toolkit');
        }
        return apply_filters('lrob_etk_smtp_known_sources', $sources);
    }

    private function render_auth_key_warning(): void
    {
        if (\LRob\EmailToolkit\Support\Encryption::is_available()) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('AUTH_KEY is missing or unset in wp-config.php.', 'lrob-email-toolkit'); ?></strong>
                <?php esc_html_e('SMTP passwords cannot be encrypted at rest until you configure AUTH_KEY. Generate one at https://api.wordpress.org/secret-key/1.1/salt/ and add it to wp-config.php.', 'lrob-email-toolkit'); ?>
            </p>
        </div>
        <?php
    }

    private function render_toggle_notice(): void
    {
        if (!isset($_GET['toggled'])) {
            return;
        }
        $toggled = sanitize_key((string) $_GET['toggled']);
        $message = $toggled === 'on'
            ? __('Email routing is now active.', 'lrob-email-toolkit')
            : __('Email routing is now off. Your configuration is preserved.', 'lrob-email-toolkit');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
