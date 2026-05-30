<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP\Admin;

use LRob\EmailToolkit\Admin\Combobox;
use LRob\EmailToolkit\Admin\Tooltip;
use LRob\EmailToolkit\Modules\SMTP\ConstantOverrides;
use LRob\EmailToolkit\Modules\SMTP\Identity;

// Docs: docs/smtp.md
final class IdentityCardRenderer
{
    public function __construct(
        private ConstantOverrides $overrides,
    ) {
    }

    public function render(?Identity $identity): void
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
            'override_mode'   => $identity ? $identity->override_mode : Identity::OVERRIDE_ALWAYS,
            'reply_to_email'  => $identity?->reply_to_email ?? '',
            'is_default'      => $identity?->is_default ?? false,
            'is_active'       => $identity ? $identity->is_active : true,
            'save_attachments' => $identity ? $identity->save_attachments : true,
            'has_password'    => $identity ? $identity->smtp_password_encrypted !== '' : false,
        ];
        ?>
        <form class="lrob-etk-card-form" novalidate>
            <input type="hidden" name="id" class="lrob-etk-field-id" value="<?php echo (int) $f['id']; ?>">
            <input type="hidden" name="slug" class="lrob-etk-field-slug" value="<?php echo esc_attr($f['slug']); ?>" data-initial="<?php echo esc_attr($f['slug']); ?>">

            <header class="lrob-etk-card-form-head">
                <label class="lrob-etk-inline-switch lrob-etk-active-switch" title="<?php echo $f['is_active'] ? esc_attr__('Disable', 'lrob-email-toolkit') : esc_attr__('Enable', 'lrob-email-toolkit'); ?>" data-on="<?php esc_attr_e('Disable', 'lrob-email-toolkit'); ?>" data-off="<?php esc_attr_e('Enable', 'lrob-email-toolkit'); ?>">
                    <input type="checkbox" name="is_active" class="lrob-etk-field-is-active" value="1" <?php checked($f['is_active']); ?>>
                    <span class="lrob-etk-switch-track"></span>
                </label>
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
                <button type="button" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-icon-btn--danger lrob-etk-card-delete" data-action="delete" data-id="<?php echo (int) $f['id']; ?>" data-label="<?php echo esc_attr($f['label']); ?>" title="<?php esc_attr_e('Delete', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Delete', 'lrob-email-toolkit'); ?>" <?php echo $is_new ? 'hidden' : ''; ?>>
                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                </button>
            </header>

            <div class="lrob-etk-smtp-only">
                <?php $this->render_auth_section($overridden, $f); ?>
                <?php $this->render_server_section($overridden, $f); ?>
            </div>

            <?php $this->render_from_section($overridden, $f, $site_title); ?>

            <div class="lrob-etk-smtp-attachments-field">
                <label class="lrob-etk-section-switch">
                    <input type="checkbox" name="save_attachments" class="lrob-etk-field-save-attachments" value="1" <?php checked($f['save_attachments']); ?>>
                    <span class="lrob-etk-switch-track"></span>
                    <span class="lrob-etk-section-switch-label"><?php esc_html_e('Save attachments locally', 'lrob-email-toolkit'); ?></span>
                </label>
                <?php Tooltip::render(__('Keep a copy of each attachment sent through this identity on the server, so you can re-send the email later with its files intact. Copies are removed when the log entry is deleted.', 'lrob-email-toolkit')); ?>
            </div>

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
                <span class="lrob-etk-card-status" aria-live="polite"></span>
                <div class="lrob-etk-card-footer-actions">
                    <button type="button" class="button button-primary lrob-etk-card-create" data-action="create" <?php echo $is_new ? '' : 'hidden'; ?>>
                        <?php esc_html_e('Create', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button" class="button lrob-etk-card-discard" data-action="discard" <?php echo $is_new ? '' : 'hidden'; ?>>
                        <?php esc_html_e('Discard', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button" class="button lrob-etk-conn-test lrob-etk-smtp-only-inline" data-action="test-auth" <?php echo $is_new ? 'hidden' : ''; ?>>
                        <span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
                        <span class="lrob-etk-conn-test-label"><?php esc_html_e('Test connection', 'lrob-email-toolkit'); ?></span>
                    </button>
                    <button type="button" class="button lrob-etk-card-test-email" data-action="test" data-id="<?php echo (int) $f['id']; ?>" <?php echo $is_new ? 'hidden' : ''; ?>>
                        <span class="dashicons dashicons-email" aria-hidden="true"></span>
                        <?php esc_html_e('Test email', 'lrob-email-toolkit'); ?>
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
    private function render_auth_section(array $overridden, array $f): void
    {
        ?>
        <section class="lrob-etk-form-row-full">
            <label class="lrob-etk-section-switch lrob-etk-auth-switch">
                <input type="checkbox" name="smtp_auth" class="lrob-etk-field-smtp-auth" value="1" <?php checked($f['smtp_auth']); ?>>
                <span class="lrob-etk-switch-track"></span>
                <span class="lrob-etk-section-switch-label"><?php esc_html_e('Authentication required', 'lrob-email-toolkit'); ?></span>
                <?php Tooltip::render(__('Credentials your mail server uses to authenticate this site — usually the same as logging into webmail.', 'lrob-email-toolkit')); ?>
            </label>

            <div class="lrob-etk-auth-fields lrob-etk-from-grid">
                <div class="lrob-etk-field">
                    <label>
                        <?php esc_html_e('Username / email', 'lrob-email-toolkit'); ?>
                        <?php $this->render_override_dot($overridden, 'smtp_username'); ?>
                    </label>
                    <input type="text" name="smtp_username" class="lrob-etk-field-username" value="<?php echo esc_attr($f['smtp_username']); ?>" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" placeholder="contact@example.com">
                </div>

                <div class="lrob-etk-field">
                    <label>
                        <?php esc_html_e('Password', 'lrob-email-toolkit'); ?>
                        <?php $this->render_override_dot($overridden, 'smtp_password_encrypted'); ?>
                        <?php Tooltip::render(__('Encrypted at rest with AES-256-GCM, derived from your AUTH_KEY. To put the password in wp-config.php instead, define LROB_ETK_SMTP_PASS.', 'lrob-email-toolkit'), 'lock'); ?>
                    </label>
                    <?php
                    $password_placeholder = $f['has_password']
                        ? str_repeat("\u{2022}", 10)
                        : '';
                    ?>
                    <input type="password" name="smtp_password" class="lrob-etk-field-password" autocomplete="new-password" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" placeholder="<?php echo esc_attr($password_placeholder); ?>">
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * @param array<int, string>   $overridden
     * @param array<string, mixed> $f
     */
    private function render_server_section(array $overridden, array $f): void
    {
        ?>
        <section class="lrob-etk-form-row-full lrob-etk-server-section">
            <div class="lrob-etk-field lrob-etk-server-host">
                <label>
                    <?php esc_html_e('SMTP host server', 'lrob-email-toolkit'); ?>
                    <?php $this->render_override_dot($overridden, 'smtp_host'); ?>
                    <?php Tooltip::render(__('Pick your domain\'s mail server. "Custom" is for external relays (Mailgun, SendGrid, etc.) — usually only needed for high-volume sending. Whichever host you pick must have a valid TLS certificate.', 'lrob-email-toolkit')); ?>
                </label>
                <div class="lrob-etk-combo lrob-etk-combo--host" data-name="host">
                    <span class="lrob-etk-host-dot" aria-hidden="true" hidden></span>
                    <input
                        type="text"
                        name="smtp_host"
                        class="lrob-etk-combo-input lrob-etk-field-host"
                        value="<?php echo esc_attr($f['smtp_host']); ?>"
                        placeholder="smtp.example.com"
                        autocomplete="off">
                    <span class="lrob-etk-host-status" aria-live="polite" hidden></span>
                    <button type="button" class="lrob-etk-combo-toggle" tabindex="-1"
                            aria-label="<?php esc_attr_e('Show host presets', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                    </button>
                    <ul class="lrob-etk-combo-menu" role="listbox" hidden></ul>
                </div>
            </div>

            <div class="lrob-etk-server-row2">
                <div class="lrob-etk-field lrob-etk-server-enc">
                    <label>
                        <?php esc_html_e('Encryption', 'lrob-email-toolkit'); ?>
                        <?php Tooltip::render(__('STARTTLS upgrades a plain connection to encrypted (port 587 — most common). TLS connects encrypted from the start (port 465). None sends plaintext on port 25 — almost always blocked by hosting providers.', 'lrob-email-toolkit')); ?>
                    </label>
                    <?php
                    Combobox::render_fixed_select(
                        'smtp_encryption',
                        $f['smtp_encryption'],
                        [
                            ['value' => Identity::ENCRYPTION_STARTTLS, 'label' => 'STARTTLS'],
                            ['value' => Identity::ENCRYPTION_SSL,      'label' => 'TLS'],
                            ['value' => Identity::ENCRYPTION_NONE,     'label' => __('None', 'lrob-email-toolkit')],
                        ],
                        '',
                        ''
                    );
                    ?>
                </div>

                <div class="lrob-etk-field lrob-etk-server-port">
                    <label for="lrob-etk-port-<?php echo (int) $f['id']; ?>"><?php esc_html_e('Port', 'lrob-email-toolkit'); ?></label>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" id="lrob-etk-port-<?php echo (int) $f['id']; ?>" name="smtp_port" class="lrob-etk-field-port" value="<?php echo (int) $f['smtp_port']; ?>">
                </div>
            </div>

            <div class="lrob-etk-none-warning" <?php echo $f['smtp_encryption'] === Identity::ENCRYPTION_NONE ? '' : 'hidden'; ?>>
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <?php esc_html_e('Plaintext SMTP on port 25 is almost always blocked by web hosts. Use STARTTLS or TLS.', 'lrob-email-toolkit'); ?>
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
            <div class="lrob-etk-from-grid">
                <div class="lrob-etk-field">
                    <label>
                        <?php esc_html_e('From email', 'lrob-email-toolkit'); ?>
                        <?php $this->render_override_dot($overridden, 'from_email'); ?>
                        <?php Tooltip::render(__('The address shown to recipients. Empty fields use the mailbox login (for From email) or site title (for From name) automatically — placeholders show what will be used.', 'lrob-email-toolkit')); ?>
                    </label>
                    <div class="lrob-etk-combo" data-name="from-email">
                        <input
                            type="email"
                            name="from_email"
                            class="lrob-etk-combo-input lrob-etk-field-from-email"
                            value="<?php echo esc_attr($f['from_email']); ?>"
                            placeholder="<?php
                            if ($f['smtp_username'] !== '' && $f['transport'] !== Identity::TRANSPORT_MAIL) {
                                echo esc_attr(sprintf(__('Default — %s', 'lrob-email-toolkit'), $f['smtp_username']));
                            } elseif ($f['transport'] === Identity::TRANSPORT_MAIL) {
                                echo esc_attr__('Default — WordPress sender', 'lrob-email-toolkit');
                            } else {
                                echo esc_attr__('Default — uses mailbox login', 'lrob-email-toolkit');
                            }
                            ?>"
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
                        <?php esc_html_e('Force', 'lrob-email-toolkit'); ?>
                        <?php Tooltip::render(__('How hard this identity overrides a From address set by another plugin (WooCommerce, contact forms, etc.). "If no sender defined" leaves explicit choices alone but kicks in on emails that didn\'t pick a sender themselves.', 'lrob-email-toolkit')); ?>
                    </label>
                    <?php
                    Combobox::render_fixed_select(
                        'override_mode',
                        $f['override_mode'],
                        [
                            ['value' => Identity::OVERRIDE_ALWAYS,       'label' => __('Always', 'lrob-email-toolkit')],
                            ['value' => Identity::OVERRIDE_WHEN_DEFAULT, 'label' => __('If no sender defined', 'lrob-email-toolkit')],
                            ['value' => Identity::OVERRIDE_NEVER,        'label' => __('Never', 'lrob-email-toolkit')],
                        ],
                        '',
                        ''
                    );
                    ?>
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
}
