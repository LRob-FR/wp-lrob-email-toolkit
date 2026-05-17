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
 * Single SMTP page. Identities list + routing rules visible at all times.
 * Add / Edit / Delete / Test operations happen via in-page modals driven by
 * AJAX (see AjaxController). No separate edit URL — keeps the workflow on
 * one screen.
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
            <h1 class="lrob-etk-page-title"><?php esc_html_e('SMTP', 'lrob-email-toolkit'); ?></h1>

            <div id="lrob-etk-flash" class="lrob-etk-flash" aria-live="polite"></div>

            <?php $this->render_toggle_notice(); ?>
            <?php $this->render_auth_key_warning(); ?>

            <?php if (!$enabled && $identities === []) : ?>
                <?php
                ModuleToggle::render_cta(
                    $this->module,
                    __('Set up SMTP', 'lrob-email-toolkit'),
                    __('Route every outgoing email through your SMTP server. Configure one or more identities (a "From" address paired with its SMTP login), then choose which sources use which.', 'lrob-email-toolkit')
                );
                ?>
            <?php else : ?>
                <?php ModuleToggle::render_bar($this->module); ?>

                <div class="lrob-etk-toolbar">
                    <button type="button" class="button button-primary" data-lrob-etk-open="lrob-etk-identity-modal" data-lrob-etk-edit-id="0">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php esc_html_e('Add identity', 'lrob-email-toolkit'); ?>
                    </button>
                </div>

                <?php if ($identities === []) : ?>
                    <div class="lrob-etk-empty">
                        <p><?php esc_html_e('No SMTP identities yet — add one to start routing email.', 'lrob-email-toolkit'); ?></p>
                    </div>
                <?php else : ?>
                    <?php $this->render_identities_table($identities); ?>
                    <?php $this->render_routing_section($identities); ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php $this->render_identity_modal(); ?>
            <?php $this->render_test_send_modal($identities); ?>
        </div>

        <script>
        <?php $this->print_inline_js($identities); ?>
        </script>
        <?php
    }

    /** @param array<int, Identity> $identities */
    private function render_identities_table(array $identities): void
    {
        ?>
        <table class="widefat striped lrob-etk-table lrob-etk-identities-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Identity', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('From', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Server', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Default', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Actions', 'lrob-email-toolkit'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($identities as $identity) : ?>
                    <tr data-identity-id="<?php echo (int) $identity->id; ?>">
                        <td>
                            <strong>
                                <a href="#" data-lrob-etk-open="lrob-etk-identity-modal" data-lrob-etk-edit-id="<?php echo (int) $identity->id; ?>">
                                    <?php echo esc_html($identity->label); ?>
                                </a>
                            </strong>
                            <div class="row-actions"><code><?php echo esc_html($identity->slug); ?></code></div>
                        </td>
                        <td>
                            <?php echo esc_html($identity->effective_from_name()); ?>
                            <div class="row-actions"><?php echo esc_html($identity->from_email); ?></div>
                        </td>
                        <td>
                            <?php echo esc_html(sprintf('%s:%d', $identity->smtp_host, $identity->smtp_port)); ?>
                            <?php if ($identity->smtp_encryption !== '') : ?>
                                <div class="row-actions"><?php echo esc_html(strtoupper($identity->smtp_encryption)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($identity->is_default) : ?>
                                <span class="dashicons dashicons-star-filled" aria-label="<?php esc_attr_e('Default identity', 'lrob-email-toolkit'); ?>"></span>
                            <?php else : ?>
                                <button type="button" class="button-link lrob-etk-set-default" data-id="<?php echo (int) $identity->id; ?>">
                                    <?php esc_html_e('Set default', 'lrob-email-toolkit'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($identity->is_active) : ?>
                                <span class="lrob-etk-status lrob-etk-status--on"><?php esc_html_e('Active', 'lrob-email-toolkit'); ?></span>
                            <?php else : ?>
                                <span class="lrob-etk-status lrob-etk-status--off"><?php esc_html_e('Inactive', 'lrob-email-toolkit'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="lrob-etk-row-actions">
                            <button type="button" class="button button-small" data-lrob-etk-open="lrob-etk-identity-modal" data-lrob-etk-edit-id="<?php echo (int) $identity->id; ?>">
                                <?php esc_html_e('Edit', 'lrob-email-toolkit'); ?>
                            </button>
                            <button type="button" class="button button-small" data-lrob-etk-open="lrob-etk-test-modal" data-lrob-etk-test-id="<?php echo (int) $identity->id; ?>">
                                <?php esc_html_e('Test', 'lrob-email-toolkit'); ?>
                            </button>
                            <button type="button" class="button button-small button-link-delete lrob-etk-delete" data-id="<?php echo (int) $identity->id; ?>" data-label="<?php echo esc_attr($identity->label); ?>">
                                <?php esc_html_e('Delete', 'lrob-email-toolkit'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
                        <label for="lrob-etk-route-<?php echo esc_attr($source); ?>">
                            <strong><?php echo esc_html($label); ?></strong>
                            <code><?php echo esc_html($source); ?></code>
                        </label>
                        <select id="lrob-etk-route-<?php echo esc_attr($source); ?>" name="routing[<?php echo esc_attr($source); ?>]">
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

    private function render_identity_modal(): void
    {
        $overridden = $this->overrides->overridden_fields();
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-identity-modal" role="dialog" aria-modal="true" aria-labelledby="lrob-etk-modal-title" hidden>
            <div class="lrob-etk-modal-backdrop" data-lrob-etk-close></div>
            <div class="lrob-etk-modal-dialog" role="document">
                <header class="lrob-etk-modal-header">
                    <h2 id="lrob-etk-modal-title" class="lrob-etk-modal-title-text">
                        <?php esc_html_e('SMTP identity', 'lrob-email-toolkit'); ?>
                    </h2>
                    <button type="button" class="lrob-etk-modal-close" data-lrob-etk-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </header>

                <form id="lrob-etk-identity-form" class="lrob-etk-identity-form" novalidate>
                    <input type="hidden" name="id" value="0">

                    <input
                        type="text"
                        name="label"
                        id="lrob-etk-label"
                        class="lrob-etk-title-input"
                        placeholder="<?php esc_attr_e('Identity name (e.g. Site default, Newsletter sender)', 'lrob-email-toolkit'); ?>"
                        required
                        autocomplete="off">
                    <input type="hidden" name="slug" id="lrob-etk-slug">
                    <p class="lrob-etk-slug-hint">
                        <?php esc_html_e('Slug:', 'lrob-email-toolkit'); ?>
                        <code id="lrob-etk-slug-display">—</code>
                        <?php Tooltip::render(__('A stable identifier used internally (e.g. in routing rules). Auto-generated from the name; you only need to think about this if you rename the identity later.', 'lrob-email-toolkit')); ?>
                    </p>

                    <div class="lrob-etk-modal-columns">
                        <?php $this->render_mailbox_column($overridden); ?>
                        <?php $this->render_server_column($overridden); ?>
                    </div>

                    <?php $this->render_from_section($overridden); ?>
                    <?php $this->render_status_section(); ?>

                    <div class="lrob-etk-field-errors" id="lrob-etk-form-error" hidden></div>
                </form>

                <footer class="lrob-etk-modal-footer">
                    <button type="button" class="button" data-lrob-etk-close><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></button>
                    <button type="button" class="button button-primary" id="lrob-etk-save-identity">
                        <?php esc_html_e('Save identity', 'lrob-email-toolkit'); ?>
                    </button>
                </footer>
            </div>
        </div>
        <?php
    }

    /** @param array<int, string> $overridden */
    private function render_mailbox_column(array $overridden): void
    {
        ?>
        <section class="lrob-etk-form-column">
            <h3>
                <?php esc_html_e('Mailbox login', 'lrob-email-toolkit'); ?>
                <?php Tooltip::render(__('The credentials your mail server uses to authenticate this site. Usually the same as logging into webmail.', 'lrob-email-toolkit')); ?>
            </h3>

            <div class="lrob-etk-toggle-row">
                <label class="lrob-etk-switch">
                    <input type="checkbox" name="smtp_auth" id="lrob-etk-smtp-auth" value="1" checked>
                    <span class="lrob-etk-switch-track"></span>
                </label>
                <span class="lrob-etk-switch-label"><?php esc_html_e('Authentication', 'lrob-email-toolkit'); ?></span>
                <?php Tooltip::render(__('Most SMTP servers require a login. Disable only for relay-style internal servers that allow anonymous submission.', 'lrob-email-toolkit')); ?>
            </div>

            <div class="lrob-etk-auth-fields">
                <div class="lrob-etk-field">
                    <label for="lrob-etk-username">
                        <?php esc_html_e('Username / email', 'lrob-email-toolkit'); ?>
                        <?php $this->render_override_dot($overridden, 'smtp_username'); ?>
                    </label>
                    <input type="text" id="lrob-etk-username" name="smtp_username" autocomplete="off" placeholder="contact@example.com">
                </div>

                <div class="lrob-etk-field">
                    <label for="lrob-etk-password">
                        <?php esc_html_e('Password', 'lrob-email-toolkit'); ?>
                        <?php $this->render_override_dot($overridden, 'smtp_password_encrypted'); ?>
                        <?php Tooltip::render(__('Encrypted at rest with AES-256-GCM, derived from your AUTH_KEY. To put the password in wp-config.php instead, define LROB_ETK_SMTP_PASS.', 'lrob-email-toolkit'), 'lock'); ?>
                    </label>
                    <div class="lrob-etk-password-row">
                        <input type="password" id="lrob-etk-password" name="smtp_password" autocomplete="new-password" placeholder="<?php esc_attr_e('(unchanged when editing)', 'lrob-email-toolkit'); ?>">
                        <button type="button" class="button" id="lrob-etk-test-auth">
                            <?php esc_html_e('Test connection', 'lrob-email-toolkit'); ?>
                        </button>
                    </div>
                    <div id="lrob-etk-test-auth-result" class="lrob-etk-test-result" hidden></div>
                </div>
            </div>
        </section>
        <?php
    }

    /** @param array<int, string> $overridden */
    private function render_server_column(array $overridden): void
    {
        ?>
        <section class="lrob-etk-form-column">
            <h3>
                <?php esc_html_e('SMTP server', 'lrob-email-toolkit'); ?>
                <?php Tooltip::render(__('Pick your domain\'s mail server. "Custom" lets you use an external relay (Mailgun, SendGrid, etc.) — only needed for high-volume sending. Whichever host you pick must have a valid TLS certificate.', 'lrob-email-toolkit')); ?>
            </h3>

            <div class="lrob-etk-field">
                <label><?php esc_html_e('Host', 'lrob-email-toolkit'); ?></label>
                <div class="lrob-etk-server-picker">
                    <label class="lrob-etk-server-option">
                        <input type="radio" name="host_picker" value="mail" checked>
                        <span>mail.<span class="lrob-etk-server-domain">example.com</span></span>
                    </label>
                    <label class="lrob-etk-server-option">
                        <input type="radio" name="host_picker" value="bare">
                        <span class="lrob-etk-server-domain">example.com</span>
                    </label>
                    <label class="lrob-etk-server-option">
                        <input type="radio" name="host_picker" value="custom">
                        <span><?php esc_html_e('Custom', 'lrob-email-toolkit'); ?></span>
                    </label>
                </div>
                <input type="text" id="lrob-etk-host" name="smtp_host" placeholder="smtp.example.com" hidden>
                <?php $this->render_override_dot($overridden, 'smtp_host'); ?>
            </div>

            <div class="lrob-etk-field">
                <label><?php esc_html_e('Encryption & port', 'lrob-email-toolkit'); ?></label>
                <div class="lrob-etk-encryption-row">
                    <div class="lrob-etk-radio-group">
                        <label>
                            <input type="radio" name="smtp_encryption" value="<?php echo esc_attr(Identity::ENCRYPTION_STARTTLS); ?>" checked>
                            STARTTLS
                        </label>
                        <label>
                            <input type="radio" name="smtp_encryption" value="<?php echo esc_attr(Identity::ENCRYPTION_SSL); ?>">
                            SSL/TLS
                        </label>
                        <label>
                            <input type="radio" name="smtp_encryption" value="<?php echo esc_attr(Identity::ENCRYPTION_NONE); ?>">
                            <?php esc_html_e('None', 'lrob-email-toolkit'); ?>
                        </label>
                    </div>
                    <div class="lrob-etk-port-input">
                        <label for="lrob-etk-port"><?php esc_html_e('Port', 'lrob-email-toolkit'); ?></label>
                        <input type="number" id="lrob-etk-port" name="smtp_port" min="1" max="65535" value="587">
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    /** @param array<int, string> $overridden */
    private function render_from_section(array $overridden): void
    {
        $site_title = get_bloginfo('name');
        ?>
        <section class="lrob-etk-form-row-full">
            <h3>
                <?php esc_html_e('From address', 'lrob-email-toolkit'); ?>
                <?php Tooltip::render(__('The address shown to recipients. Most SMTP servers require the From address to match the mailbox login; the warning below will tell you when something looks off.', 'lrob-email-toolkit')); ?>
            </h3>

            <div class="lrob-etk-from-row">
                <div class="lrob-etk-from-field">
                    <label><?php esc_html_e('From email', 'lrob-email-toolkit'); ?></label>
                    <div class="lrob-etk-segmented" data-segmented-target="from_email_mode">
                        <input type="hidden" name="from_email_mode" value="auto">
                        <button type="button" class="is-active" data-mode="auto"><?php esc_html_e('Automatic', 'lrob-email-toolkit'); ?></button>
                        <button type="button" data-mode="custom"><?php esc_html_e('Custom', 'lrob-email-toolkit'); ?></button>
                    </div>
                    <div class="lrob-etk-from-auto" id="lrob-etk-from-email-auto">
                        <span class="lrob-etk-readonly-value" id="lrob-etk-from-email-auto-value">—</span>
                        <span class="description"><?php esc_html_e('(same as mailbox login)', 'lrob-email-toolkit'); ?></span>
                    </div>
                    <input type="email" id="lrob-etk-from-email" name="from_email" hidden>
                    <div id="lrob-etk-from-warning" class="lrob-etk-from-warning" hidden></div>
                    <?php $this->render_override_dot($overridden, 'from_email'); ?>
                </div>

                <div class="lrob-etk-force-toggle">
                    <label>
                        <input type="checkbox" name="force_from" id="lrob-etk-force-from" value="1" checked>
                        <?php esc_html_e('Force', 'lrob-email-toolkit'); ?>
                    </label>
                    <?php Tooltip::render(__('Override the From address on every outgoing email — even when other plugins try to set their own.', 'lrob-email-toolkit')); ?>
                </div>
            </div>

            <div class="lrob-etk-from-row">
                <div class="lrob-etk-from-field">
                    <label><?php esc_html_e('From name', 'lrob-email-toolkit'); ?></label>
                    <div class="lrob-etk-segmented" data-segmented-target="from_name_mode">
                        <input type="hidden" name="from_name_mode" value="auto">
                        <button type="button" class="is-active" data-mode="auto"><?php esc_html_e('Automatic', 'lrob-email-toolkit'); ?></button>
                        <button type="button" data-mode="custom"><?php esc_html_e('Custom', 'lrob-email-toolkit'); ?></button>
                    </div>
                    <div class="lrob-etk-from-auto" id="lrob-etk-from-name-auto">
                        <span class="lrob-etk-readonly-value"><?php echo esc_html($site_title); ?></span>
                        <span class="description"><?php esc_html_e('(site title)', 'lrob-email-toolkit'); ?></span>
                    </div>
                    <input type="text" id="lrob-etk-from-name" name="from_name" hidden>
                    <?php $this->render_override_dot($overridden, 'from_name'); ?>
                </div>
            </div>

            <div class="lrob-etk-from-row">
                <div class="lrob-etk-from-field">
                    <label for="lrob-etk-reply-to">
                        <?php esc_html_e('Reply-to', 'lrob-email-toolkit'); ?>
                        <?php Tooltip::render(__('Optional. Where replies go if different from the From address.', 'lrob-email-toolkit')); ?>
                    </label>
                    <input type="email" id="lrob-etk-reply-to" name="reply_to_email" class="regular-text">
                </div>
            </div>
        </section>
        <?php
    }

    private function render_status_section(): void
    {
        ?>
        <section class="lrob-etk-form-row-full">
            <h3><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></h3>

            <div class="lrob-etk-status-row">
                <div class="lrob-etk-toggle-row">
                    <label class="lrob-etk-switch">
                        <input type="checkbox" name="is_active" id="lrob-etk-is-active" value="1" checked>
                        <span class="lrob-etk-switch-track"></span>
                    </label>
                    <span class="lrob-etk-switch-label"><?php esc_html_e('Active', 'lrob-email-toolkit'); ?></span>
                    <?php Tooltip::render(__('Inactive identities are kept in storage but never used for sending.', 'lrob-email-toolkit')); ?>
                </div>
                <div class="lrob-etk-toggle-row">
                    <label class="lrob-etk-switch">
                        <input type="checkbox" name="is_default" id="lrob-etk-is-default" value="1">
                        <span class="lrob-etk-switch-track"></span>
                    </label>
                    <span class="lrob-etk-switch-label"><?php esc_html_e('Default identity', 'lrob-email-toolkit'); ?></span>
                    <?php Tooltip::render(__('Used for any source that doesn\'t have its own routing rule.', 'lrob-email-toolkit')); ?>
                </div>
            </div>
        </section>
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
                    <input type="hidden" name="id" id="lrob-etk-test-id" value="0">

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
                        <select id="lrob-etk-test-recipient-choice" name="recipient_choice">
                            <option value="current"><?php echo esc_html(sprintf(__('Me (%s)', 'lrob-email-toolkit'), $current_user->user_email)); ?></option>
                            <option value="admin"><?php echo esc_html(sprintf(__('Site admin (%s)', 'lrob-email-toolkit'), $admin_email)); ?></option>
                            <option value="custom"><?php esc_html_e('Custom address…', 'lrob-email-toolkit'); ?></option>
                        </select>
                    </div>

                    <div class="lrob-etk-field" id="lrob-etk-test-custom-wrap" hidden>
                        <label for="lrob-etk-test-recipient-custom"><?php esc_html_e('Custom recipient', 'lrob-email-toolkit'); ?></label>
                        <input type="email" id="lrob-etk-test-recipient-custom" name="recipient_custom" placeholder="you@example.com">
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

    /** @param array<int, Identity> $identities */
    private function print_inline_js(array $identities): void
    {
        $data = [];
        foreach ($identities as $identity) {
            $data[(int) $identity->id] = [
                'id'              => (int) $identity->id,
                'label'           => $identity->label,
                'slug'            => $identity->slug,
                'from_email'      => $identity->from_email,
                'from_name'       => $identity->from_name,
                'from_email_auto' => $identity->is_from_email_automatic(),
                'from_name_auto'  => $identity->is_from_name_automatic(),
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
                saveRouting: <?php echo wp_json_encode(AjaxController::ACTION_SAVE_ROUTING); ?>
            },
            identities: <?php echo wp_json_encode($data); ?>,
            siteTitle: <?php echo wp_json_encode(get_bloginfo('name')); ?>,
            i18n: {
                deleting:        <?php echo wp_json_encode(__('Deleting…', 'lrob-email-toolkit')); ?>,
                deleteConfirm:   <?php echo wp_json_encode(__('Delete the identity "%s"?', 'lrob-email-toolkit')); ?>,
                saving:          <?php echo wp_json_encode(__('Saving…', 'lrob-email-toolkit')); ?>,
                testing:         <?php echo wp_json_encode(__('Testing…', 'lrob-email-toolkit')); ?>,
                sending:         <?php echo wp_json_encode(__('Sending…', 'lrob-email-toolkit')); ?>,
                addTitle:        <?php echo wp_json_encode(__('Add SMTP identity', 'lrob-email-toolkit')); ?>,
                editTitle:       <?php echo wp_json_encode(__('Edit SMTP identity', 'lrob-email-toolkit')); ?>,
                domainMismatch:  <?php echo wp_json_encode(__('Domain differs from the mailbox login. Most servers will reject or rewrite this — only use this with relays that support arbitrary senders.', 'lrob-email-toolkit')); ?>,
                userMismatch:    <?php echo wp_json_encode(__('Local part differs from the mailbox login. Some servers may rewrite the From address.', 'lrob-email-toolkit')); ?>,
                unknownError:    <?php echo wp_json_encode(__('Something went wrong.', 'lrob-email-toolkit')); ?>
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

    function $(id) { return document.getElementById(id); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

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
        var holder = $('lrob-etk-flash');
        if (!holder) return;
        var div = document.createElement('div');
        div.className = 'notice notice-' + (type === 'error' ? 'error' : 'success') + ' is-dismissible';
        div.innerHTML = '<p></p>';
        div.firstChild.textContent = msg;
        holder.appendChild(div);
        setTimeout(function () { if (div.parentNode) div.parentNode.removeChild(div); }, 5000);
    }

    // ---------------- Modal handling ----------------
    function openModal(id, ctx) {
        var modal = $(id);
        if (!modal) return;
        modal.hidden = false;
        document.body.classList.add('lrob-etk-modal-open');
        if (id === 'lrob-etk-identity-modal') {
            populateIdentityForm(ctx);
        } else if (id === 'lrob-etk-test-modal') {
            $('lrob-etk-test-id').value = ctx || 0;
            var pick = $('lrob-etk-test-identity-pick');
            if (pick && ctx) pick.value = ctx;
            $('lrob-etk-test-result').hidden = true;
        }
        var focusable = modal.querySelector('input, button, select, textarea');
        if (focusable) focusable.focus();
    }

    function closeModal() {
        $$('.lrob-etk-modal').forEach(function (m) { m.hidden = true; });
        document.body.classList.remove('lrob-etk-modal-open');
    }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest && e.target.closest('[data-lrob-etk-open]');
        if (opener) {
            e.preventDefault();
            var target = opener.getAttribute('data-lrob-etk-open');
            var ctx = opener.getAttribute('data-lrob-etk-edit-id') || opener.getAttribute('data-lrob-etk-test-id') || null;
            openModal(target, ctx ? parseInt(ctx, 10) : null);
            return;
        }
        if (e.target.closest && e.target.closest('[data-lrob-etk-close]')) {
            e.preventDefault();
            closeModal();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });

    // ---------------- Identity form ----------------
    function populateIdentityForm(id) {
        var form = $('lrob-etk-identity-form');
        if (!form) return;
        form.reset();
        $('lrob-etk-form-error').hidden = true;
        $('lrob-etk-test-auth-result').hidden = true;

        var data = id && S.identities[id] ? S.identities[id] : null;
        $('lrob-etk-modal-title').textContent = data ? S.i18n.editTitle : S.i18n.addTitle;
        form.elements['id'].value = data ? data.id : 0;
        $('lrob-etk-label').value = data ? data.label : '';
        $('lrob-etk-slug').value = data ? data.slug : '';
        $('lrob-etk-slug').setAttribute('data-initial', data ? data.slug : '');
        $('lrob-etk-slug-display').textContent = data ? data.slug : '—';

        $('lrob-etk-username').value = data ? data.smtp_username : '';
        $('lrob-etk-password').value = '';
        $('lrob-etk-password').placeholder = data ? '<?php echo esc_js(__('(unchanged when editing)', 'lrob-email-toolkit')); ?>' : '';
        $('lrob-etk-smtp-auth').checked = data ? !!data.smtp_auth : true;
        $('lrob-etk-host').value = data ? data.smtp_host : '';
        $('lrob-etk-port').value = data ? data.smtp_port : 587;
        $$('input[name="smtp_encryption"]').forEach(function (r) { r.checked = r.value === (data ? data.smtp_encryption : 'tls'); });
        $('lrob-etk-reply-to').value = data ? (data.reply_to_email || '') : '';
        $('lrob-etk-force-from').checked = data ? !!data.force_from : true;
        $('lrob-etk-is-active').checked = data ? !!data.is_active : true;
        $('lrob-etk-is-default').checked = data ? !!data.is_default : false;

        // From email mode
        var fromEmailMode = data && !data.from_email_auto ? 'custom' : 'auto';
        setSegmentedMode('from_email_mode', fromEmailMode);
        $('lrob-etk-from-email').value = data ? data.from_email : '';

        // From name mode
        var fromNameMode = data && !data.from_name_auto ? 'custom' : 'auto';
        setSegmentedMode('from_name_mode', fromNameMode);
        $('lrob-etk-from-name').value = data ? data.from_name : '';

        // Server picker
        syncServerPicker(true);
        syncAuthVisibility();
        syncFromAutoDisplay();
        syncFromWarning();
    }

    // ---------------- Real-time form behaviors ----------------
    $('lrob-etk-label').addEventListener('input', function () {
        var initial = $('lrob-etk-slug').getAttribute('data-initial');
        if (initial && initial !== '' && $('lrob-etk-slug').value !== '') {
            return; // editing — don't overwrite slug from label changes
        }
        var s = slugify(this.value);
        $('lrob-etk-slug').value = s;
        $('lrob-etk-slug-display').textContent = s || '—';
    });

    $('lrob-etk-username').addEventListener('input', function () {
        syncServerPicker(false);
        syncFromAutoDisplay();
        syncFromWarning();
    });

    $('lrob-etk-from-email').addEventListener('input', syncFromWarning);

    function syncFromAutoDisplay() {
        var user = $('lrob-etk-username').value.trim();
        $('lrob-etk-from-email-auto-value').textContent = user || '—';
    }

    function syncFromWarning() {
        var warn = $('lrob-etk-from-warning');
        if (!warn) return;
        var mode = document.querySelector('[data-segmented-target="from_email_mode"] input[name="from_email_mode"]').value;
        if (mode === 'auto') { warn.hidden = true; return; }
        var u = $('lrob-etk-username').value.trim();
        var f = $('lrob-etk-from-email').value.trim();
        if (u === '' || f === '' || u === f) { warn.hidden = true; return; }
        var uAt = u.lastIndexOf('@'); var fAt = f.lastIndexOf('@');
        if (uAt === -1 || fAt === -1) { warn.hidden = true; return; }
        var uDom = u.substring(uAt + 1); var fDom = f.substring(fAt + 1);
        if (uDom !== fDom) {
            warn.className = 'lrob-etk-from-warning is-danger';
            warn.textContent = S.i18n.domainMismatch;
        } else {
            warn.className = 'lrob-etk-from-warning is-warning';
            warn.textContent = S.i18n.userMismatch;
        }
        warn.hidden = false;
    }

    // Segmented control
    $$('[data-segmented-target]').forEach(function (group) {
        group.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-mode]');
            if (!btn) return;
            setSegmentedMode(group.getAttribute('data-segmented-target'), btn.getAttribute('data-mode'));
        });
    });

    function setSegmentedMode(name, mode) {
        var group = document.querySelector('[data-segmented-target="' + name + '"]');
        if (!group) return;
        group.querySelector('input[type="hidden"]').value = mode;
        $$('button', group).forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-mode') === mode); });

        // Toggle visibility of corresponding input vs readonly display
        if (name === 'from_email_mode') {
            $('lrob-etk-from-email-auto').hidden = mode !== 'auto';
            $('lrob-etk-from-email').hidden = mode === 'auto';
            if (mode === 'custom' && $('lrob-etk-from-email').value === '') {
                $('lrob-etk-from-email').value = $('lrob-etk-username').value;
            }
            syncFromWarning();
        } else if (name === 'from_name_mode') {
            $('lrob-etk-from-name-auto').hidden = mode !== 'auto';
            $('lrob-etk-from-name').hidden = mode === 'auto';
            if (mode === 'custom' && $('lrob-etk-from-name').value === '') {
                $('lrob-etk-from-name').value = S.siteTitle;
            }
        }
    }

    // Auth toggle hides login fields
    $('lrob-etk-smtp-auth').addEventListener('change', syncAuthVisibility);
    function syncAuthVisibility() {
        var on = $('lrob-etk-smtp-auth').checked;
        $$('.lrob-etk-auth-fields > div').forEach(function (div) { div.style.display = on ? '' : 'none'; });
    }

    // Server picker
    $$('input[name="host_picker"]').forEach(function (r) {
        r.addEventListener('change', function () { syncServerPicker(false); });
    });
    function syncServerPicker(populateFromHost) {
        var user = $('lrob-etk-username').value.trim();
        var domain = '';
        var at = user.lastIndexOf('@');
        if (at !== -1) domain = user.substring(at + 1).toLowerCase();
        $$('.lrob-etk-server-domain').forEach(function (el) { el.textContent = domain || 'example.com'; });

        if (populateFromHost) {
            // Existing identity — derive radio from current host vs derived options
            var host = $('lrob-etk-host').value;
            var picked = 'custom';
            if (domain) {
                if (host === 'mail.' + domain) picked = 'mail';
                else if (host === domain) picked = 'bare';
            }
            var radio = document.querySelector('input[name="host_picker"][value="' + picked + '"]');
            if (radio) radio.checked = true;
        }

        var picker = document.querySelector('input[name="host_picker"]:checked');
        if (!picker) return;
        var v = picker.value;
        if (v === 'mail' && domain) {
            $('lrob-etk-host').value = 'mail.' + domain;
            $('lrob-etk-host').hidden = true;
        } else if (v === 'bare' && domain) {
            $('lrob-etk-host').value = domain;
            $('lrob-etk-host').hidden = true;
        } else {
            // custom
            $('lrob-etk-host').hidden = false;
            if (populateFromHost && v !== 'custom') {
                // No domain → start blank
            }
        }
    }

    // Encryption → port autofill
    var portDefaults = { 'tls': 587, 'ssl': 465, '': 25 };
    var portLast = portDefaults[document.querySelector('input[name="smtp_encryption"]:checked').value];
    $$('input[name="smtp_encryption"]').forEach(function (r) {
        r.addEventListener('change', function () {
            var def = portDefaults[r.value];
            if (def === undefined) return;
            var current = parseInt($('lrob-etk-port').value, 10);
            if (!$('lrob-etk-port').value || current === portLast) {
                $('lrob-etk-port').value = def;
            }
            portLast = def;
        });
    });

    // ---------------- Test connection ----------------
    $('lrob-etk-test-auth').addEventListener('click', function () {
        var btn = this;
        var result = $('lrob-etk-test-auth-result');
        btn.disabled = true; btn.textContent = S.i18n.testing;
        result.hidden = false;
        result.className = 'lrob-etk-test-result is-pending';
        result.textContent = S.i18n.testing;

        var form = $('lrob-etk-identity-form');
        var fd = new FormData(form);
        fd.append('action', S.actions.testAuth);
        fd.append('_nonce', S.nonce);

        fetch(S.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success) {
                    result.className = 'lrob-etk-test-result is-success';
                    result.textContent = '✓ ' + resp.data.message;
                } else {
                    result.className = 'lrob-etk-test-result is-failure';
                    var msg = (resp.data && resp.data.message) || S.i18n.unknownError;
                    result.textContent = '✗ ' + msg;
                    if (resp.data && resp.data.debug) {
                        var pre = document.createElement('pre');
                        pre.textContent = resp.data.debug;
                        result.appendChild(pre);
                    }
                }
            })
            .catch(function (err) {
                result.className = 'lrob-etk-test-result is-failure';
                result.textContent = '✗ ' + (err && err.message ? err.message : S.i18n.unknownError);
            })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = '<?php echo esc_js(__('Test connection', 'lrob-email-toolkit')); ?>';
            });
    });

    // ---------------- Save identity ----------------
    $('lrob-etk-save-identity').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true; btn.textContent = S.i18n.saving;
        var errBox = $('lrob-etk-form-error'); errBox.hidden = true;

        var form = $('lrob-etk-identity-form');
        var fd = new FormData(form);
        fd.append('action', S.actions.save);
        fd.append('_nonce', S.nonce);

        fetch(S.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success) {
                    flash(resp.data.message, 'success');
                    setTimeout(function () { window.location.reload(); }, 400);
                } else {
                    var msg = (resp.data && resp.data.message) || S.i18n.unknownError;
                    errBox.hidden = false;
                    errBox.textContent = msg;
                    btn.disabled = false; btn.textContent = '<?php echo esc_js(__('Save identity', 'lrob-email-toolkit')); ?>';
                }
            })
            .catch(function () {
                errBox.hidden = false; errBox.textContent = S.i18n.unknownError;
                btn.disabled = false; btn.textContent = '<?php echo esc_js(__('Save identity', 'lrob-email-toolkit')); ?>';
            });
    });

    // ---------------- Delete identity ----------------
    $$('.lrob-etk-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            var label = btn.getAttribute('data-label') || '';
            if (!confirm(S.i18n.deleteConfirm.replace('%s', label))) return;
            ajax(S.actions.delete, { id: id }).then(function (resp) {
                if (resp.success) {
                    flash(resp.data.message, 'success');
                    setTimeout(function () { window.location.reload(); }, 300);
                } else {
                    flash((resp.data && resp.data.message) || S.i18n.unknownError, 'error');
                }
            });
        });
    });

    // ---------------- Set default ----------------
    $$('.lrob-etk-set-default').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            ajax(S.actions.setDefault, { id: id }).then(function (resp) {
                if (resp.success) {
                    flash(resp.data.message, 'success');
                    setTimeout(function () { window.location.reload(); }, 300);
                } else {
                    flash((resp.data && resp.data.message) || S.i18n.unknownError, 'error');
                }
            });
        });
    });

    // ---------------- Test send ----------------
    var recipientChoice = $('lrob-etk-test-recipient-choice');
    if (recipientChoice) {
        recipientChoice.addEventListener('change', function () {
            $('lrob-etk-test-custom-wrap').hidden = recipientChoice.value !== 'custom';
        });
    }
    $('lrob-etk-send-test').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true; btn.textContent = S.i18n.sending;
        var result = $('lrob-etk-test-result');
        result.hidden = false; result.className = 'lrob-etk-test-result is-pending';
        result.textContent = S.i18n.sending;

        var pick = $('lrob-etk-test-identity-pick');
        var id = pick ? pick.value : $('lrob-etk-test-id').value;
        var params = {
            id: id,
            recipient_choice: $('lrob-etk-test-recipient-choice').value,
            recipient_custom: $('lrob-etk-test-recipient-custom').value
        };
        ajax(S.actions.testSend, params)
            .then(function (resp) {
                if (resp.success) {
                    result.className = 'lrob-etk-test-result is-success';
                    result.textContent = '✓ ' + resp.data.message;
                } else {
                    result.className = 'lrob-etk-test-result is-failure';
                    result.textContent = '✗ ' + ((resp.data && resp.data.message) || S.i18n.unknownError);
                }
            })
            .finally(function () {
                btn.disabled = false; btn.textContent = '<?php echo esc_js(__('Send test', 'lrob-email-toolkit')); ?>';
            });
    });

    // ---------------- Routing form ----------------
    var routingForm = $('lrob-etk-routing-form');
    if (routingForm) {
        routingForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var routing = {};
            $$('select', routingForm).forEach(function (sel) {
                var name = sel.getAttribute('name');
                var m = name.match(/^routing\[(.+)\]$/);
                if (m) routing[m[1]] = sel.value;
            });
            ajax(S.actions.saveRouting, { routing: routing }).then(function (resp) {
                if (resp.success) flash(resp.data.message, 'success');
                else flash((resp.data && resp.data.message) || S.i18n.unknownError, 'error');
            });
        });
    }
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
            ? __('SMTP routing is now active.', 'lrob-email-toolkit')
            : __('SMTP routing is now off. Your configuration is preserved.', 'lrob-email-toolkit');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
