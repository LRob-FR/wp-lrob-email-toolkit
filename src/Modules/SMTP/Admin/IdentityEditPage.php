<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP\Admin;

use LRob\EmailToolkit\Modules\SMTP\ConstantOverrides;
use LRob\EmailToolkit\Modules\SMTP\Identity;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;

/**
 * Edit / create form for a single SMTP identity. Designed for the common case:
 * mailbox login + server config + (optionally) advanced overrides. Vanilla JS
 * auto-fills:
 *   - label → slug (slugify on input)
 *   - SMTP username → from_email (on blur, only if empty)
 *   - email domain → host autosuggestions via <datalist>
 *   - encryption choice → port (STARTTLS=587, SSL=465, none=25)
 *
 * "Set as default" is a separate POST action available both from the list and
 * here on the form (alongside Save).
 */
final class IdentityEditPage
{
    public function __construct(
        private IdentityRepository $identities,
        private ConstantOverrides $overrides,
    ) {
    }

    public function render(?Identity $identity, int $id): void
    {
        $is_new = !$identity instanceof Identity;
        $is_first = $is_new && $this->identities->count() === 0;

        $notice = PageController::pop_flash('notice');
        $errors = PageController::pop_flash('errors');

        $action_url = admin_url('admin-post.php');
        $list_url = admin_url('admin.php?page=' . PageController::SLUG);

        $f = [
            'slug'            => $identity?->slug            ?? '',
            'label'           => $identity?->label           ?? '',
            'from_email'      => $identity?->from_email      ?? '',
            'from_name'       => $identity?->from_name       ?? get_bloginfo('name'),
            'smtp_host'       => $identity?->smtp_host       ?? '',
            'smtp_port'       => $identity?->smtp_port       ?? 587,
            'smtp_encryption' => $identity?->smtp_encryption ?? Identity::ENCRYPTION_STARTTLS,
            'smtp_username'   => $identity?->smtp_username   ?? '',
            'smtp_auth'       => $identity ? $identity->smtp_auth : true,
            'force_from'      => $identity ? $identity->force_from : true,
            'reply_to_email'  => $identity?->reply_to_email  ?? '',
            'is_default'      => $identity?->is_default      ?? $is_first,
            'is_active'       => $identity ? $identity->is_active : true,
        ];

        $overridden = $this->overrides->overridden_fields();
        ?>
        <div class="wrap lrob-etk">
            <?php $this->render_flash($notice, $errors); ?>

            <p>
                <a href="<?php echo esc_url($list_url); ?>" class="button button-link">
                    ← <?php esc_html_e('Back to SMTP', 'lrob-email-toolkit'); ?>
                </a>
            </p>

            <form method="post" action="<?php echo esc_url($action_url); ?>" class="lrob-etk-identity-form">
                <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_SAVE); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                <?php wp_nonce_field(PageController::ACTION_SAVE, '_lrob_etk_nonce'); ?>

                <input
                    type="text"
                    name="label"
                    id="lrob-etk-label"
                    class="lrob-etk-title-input"
                    value="<?php echo esc_attr($f['label']); ?>"
                    placeholder="<?php esc_attr_e('Untitled identity', 'lrob-email-toolkit'); ?>"
                    required
                    autocomplete="off">
                <input type="hidden" name="slug" id="lrob-etk-slug" value="<?php echo esc_attr($f['slug']); ?>">
                <p class="lrob-etk-slug-hint">
                    <?php esc_html_e('Slug:', 'lrob-email-toolkit'); ?>
                    <code id="lrob-etk-slug-display"><?php echo esc_html($f['slug'] !== '' ? $f['slug'] : '—'); ?></code>
                </p>

                <?php if ($overridden !== [] && $f['is_default']) : ?>
                    <div class="notice notice-info inline">
                        <p>
                            <strong><?php esc_html_e('Some fields are overridden by wp-config.php constants.', 'lrob-email-toolkit'); ?></strong>
                            <?php esc_html_e('Edits below are saved but ignored at runtime — the constant value is used instead.', 'lrob-email-toolkit'); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php $this->render_mailbox_section($f, $overridden, $is_new); ?>
                <?php $this->render_server_section($f, $overridden); ?>
                <?php $this->render_advanced_section($f, $overridden); ?>
                <?php $this->render_status_section($f, $is_first); ?>

                <div class="lrob-etk-form-actions">
                    <?php submit_button(__('Save identity', 'lrob-email-toolkit'), 'primary', 'submit', false); ?>
                    <a href="<?php echo esc_url($list_url); ?>" class="button"><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></a>
                    <?php if (!$is_new && !$f['is_default']) : ?>
                        <?php $this->render_inline_set_default($identity); ?>
                    <?php endif; ?>
                </div>
            </form>

            <datalist id="lrob-etk-host-suggestions"></datalist>

            <?php if (!$is_new && $identity instanceof Identity) : ?>
                <?php $this->render_test_section($identity, $action_url); ?>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            var labelInput = document.getElementById('lrob-etk-label');
            var slugField = document.getElementById('lrob-etk-slug');
            var slugDisplay = document.getElementById('lrob-etk-slug-display');
            var usernameInput = document.getElementById('lrob-etk-username');
            var fromInput = document.getElementById('lrob-etk-from-email');
            var hostInput = document.getElementById('lrob-etk-host');
            var hostList = document.getElementById('lrob-etk-host-suggestions');
            var portInput = document.getElementById('lrob-etk-port');
            var encryptionInputs = document.querySelectorAll('input[name="smtp_encryption"]');

            function slugify(s) {
                return (s || '').toLowerCase()
                    .replace(/[^a-z0-9]+/g, '_')
                    .replace(/^_+|_+$/g, '')
                    .substring(0, 50);
            }

            function syncSlug() {
                if (!labelInput || !slugField) return;
                // If the original slug was manually set (existing identity), don't overwrite.
                var initial = slugField.getAttribute('data-initial');
                if (initial && slugField.value !== '' && slugField.value !== slugify(labelInput.value)) {
                    return;
                }
                var s = slugify(labelInput.value);
                slugField.value = s;
                if (slugDisplay) slugDisplay.textContent = s || '—';
            }
            if (labelInput) {
                labelInput.addEventListener('input', syncSlug);
                if (slugField.value === '') syncSlug();
            }

            // From-email autofill from username (only when empty)
            if (usernameInput && fromInput) {
                usernameInput.addEventListener('blur', function () {
                    if (fromInput.value.trim() === '' && usernameInput.value.indexOf('@') !== -1) {
                        fromInput.value = usernameInput.value;
                    }
                });
            }

            // Host suggestions from email domain
            function updateHostSuggestions() {
                if (!hostList || !usernameInput) return;
                var v = usernameInput.value.trim();
                var at = v.lastIndexOf('@');
                if (at === -1 || at === v.length - 1) {
                    hostList.innerHTML = '';
                    if (hostInput) hostInput.removeAttribute('list');
                    return;
                }
                var domain = v.substring(at + 1);
                var suggestions = ['mail.' + domain, 'smtp.' + domain, domain];
                hostList.innerHTML = suggestions.map(function (s) {
                    return '<option value="' + s.replace(/"/g, '&quot;') + '"></option>';
                }).join('');
                if (hostInput) hostInput.setAttribute('list', 'lrob-etk-host-suggestions');
            }
            if (usernameInput) {
                usernameInput.addEventListener('input', updateHostSuggestions);
                updateHostSuggestions();
            }

            // Encryption → port autofill (but preserve user customisation)
            var portDefaults = { 'tls': 587, 'ssl': 465, '': 25 };
            var portLastDefault = null;
            function syncPort() {
                if (!portInput) return;
                var chosen = '';
                for (var i = 0; i < encryptionInputs.length; i++) {
                    if (encryptionInputs[i].checked) { chosen = encryptionInputs[i].value; break; }
                }
                var defaultForChoice = portDefaults[chosen];
                if (defaultForChoice === undefined) return;
                // If port value is empty or matches the previous default, replace it.
                var current = parseInt(portInput.value, 10);
                if (!portInput.value || current === portLastDefault) {
                    portInput.value = defaultForChoice;
                }
                portLastDefault = defaultForChoice;
            }
            for (var i = 0; i < encryptionInputs.length; i++) {
                encryptionInputs[i].addEventListener('change', syncPort);
            }
            // Initialize portLastDefault so first user edit isn't clobbered
            (function () {
                var chosen = '';
                for (var i = 0; i < encryptionInputs.length; i++) {
                    if (encryptionInputs[i].checked) { chosen = encryptionInputs[i].value; break; }
                }
                portLastDefault = portDefaults[chosen];
            })();
        })();
        </script>
        <?php
    }

    /**
     * @param array<string, mixed> $f
     * @param array<int, string>   $overridden
     */
    private function render_mailbox_section(array $f, array $overridden, bool $is_new): void
    {
        ?>
        <section class="lrob-etk-form-section">
            <h2><?php esc_html_e('Mailbox login', 'lrob-email-toolkit'); ?></h2>

            <div class="lrob-etk-field-row">
                <label for="lrob-etk-username"><?php esc_html_e('Email / username', 'lrob-email-toolkit'); ?></label>
                <div>
                    <input type="text" id="lrob-etk-username" name="smtp_username" class="regular-text"
                           value="<?php echo esc_attr($f['smtp_username']); ?>"
                           autocomplete="off"
                           placeholder="contact@example.com">
                    <?php $this->render_override_note($overridden, 'smtp_username'); ?>
                    <p class="description">
                        <?php esc_html_e('Most SMTP servers use your email address as the login. The "From" address and server suggestions will be derived from this.', 'lrob-email-toolkit'); ?>
                    </p>
                </div>
            </div>

            <div class="lrob-etk-field-row">
                <label for="lrob-etk-password"><?php esc_html_e('Password', 'lrob-email-toolkit'); ?></label>
                <div>
                    <input type="password" id="lrob-etk-password" name="smtp_password" class="regular-text"
                           autocomplete="new-password"
                           <?php echo $is_new ? '' : 'placeholder="' . esc_attr__('(unchanged — type to replace)', 'lrob-email-toolkit') . '"'; ?>>
                    <?php $this->render_override_note($overridden, 'smtp_password_encrypted'); ?>
                    <?php if (!$is_new) : ?>
                        <p>
                            <label>
                                <input type="checkbox" name="smtp_password_clear" value="1">
                                <?php esc_html_e('Clear stored password', 'lrob-email-toolkit'); ?>
                            </label>
                        </p>
                    <?php endif; ?>
                    <p class="description">
                        <?php esc_html_e('Encrypted at rest with AES-256-GCM (key derived from AUTH_KEY). Override via LROB_ETK_SMTP_PASS in wp-config.php.', 'lrob-email-toolkit'); ?>
                    </p>
                </div>
            </div>

            <div class="lrob-etk-field-row">
                <label><?php esc_html_e('Authentication', 'lrob-email-toolkit'); ?></label>
                <div>
                    <label>
                        <input type="checkbox" name="smtp_auth" value="1" <?php checked($f['smtp_auth']); ?>>
                        <?php esc_html_e('Server requires authentication', 'lrob-email-toolkit'); ?>
                    </label>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * @param array<string, mixed> $f
     * @param array<int, string>   $overridden
     */
    private function render_server_section(array $f, array $overridden): void
    {
        ?>
        <section class="lrob-etk-form-section">
            <h2><?php esc_html_e('SMTP server', 'lrob-email-toolkit'); ?></h2>

            <div class="lrob-etk-field-row">
                <label for="lrob-etk-host"><?php esc_html_e('Host', 'lrob-email-toolkit'); ?></label>
                <div>
                    <input type="text" id="lrob-etk-host" name="smtp_host" class="regular-text"
                           value="<?php echo esc_attr($f['smtp_host']); ?>"
                           placeholder="smtp.example.com"
                           required>
                    <?php $this->render_override_note($overridden, 'smtp_host'); ?>
                    <p class="description">
                        <?php esc_html_e('Once you enter your email above, common server addresses will be suggested.', 'lrob-email-toolkit'); ?>
                    </p>
                </div>
            </div>

            <div class="lrob-etk-field-row">
                <label><?php esc_html_e('Encryption & port', 'lrob-email-toolkit'); ?></label>
                <div class="lrob-etk-encryption-port">
                    <fieldset>
                        <label>
                            <input type="radio" name="smtp_encryption" value="<?php echo esc_attr(Identity::ENCRYPTION_STARTTLS); ?>" <?php checked($f['smtp_encryption'], Identity::ENCRYPTION_STARTTLS); ?>>
                            STARTTLS
                        </label>
                        <label>
                            <input type="radio" name="smtp_encryption" value="<?php echo esc_attr(Identity::ENCRYPTION_SSL); ?>" <?php checked($f['smtp_encryption'], Identity::ENCRYPTION_SSL); ?>>
                            SSL/TLS
                        </label>
                        <label>
                            <input type="radio" name="smtp_encryption" value="<?php echo esc_attr(Identity::ENCRYPTION_NONE); ?>" <?php checked($f['smtp_encryption'], Identity::ENCRYPTION_NONE); ?>>
                            <?php esc_html_e('None', 'lrob-email-toolkit'); ?>
                        </label>
                    </fieldset>
                    <div class="port-block">
                        <label for="lrob-etk-port" style="display:block;margin-bottom:4px;font-weight:500;">
                            <?php esc_html_e('Port', 'lrob-email-toolkit'); ?>
                        </label>
                        <input type="number" id="lrob-etk-port" name="smtp_port" class="small-text" required
                               min="1" max="65535" value="<?php echo (int) $f['smtp_port']; ?>">
                    </div>
                </div>
                <?php $this->render_override_note($overridden, 'smtp_encryption'); ?>
                <?php $this->render_override_note($overridden, 'smtp_port'); ?>
            </div>
        </section>
        <?php
    }

    /**
     * @param array<string, mixed> $f
     * @param array<int, string>   $overridden
     */
    private function render_advanced_section(array $f, array $overridden): void
    {
        ?>
        <section class="lrob-etk-form-section">
            <h2><?php esc_html_e('"From" address', 'lrob-email-toolkit'); ?></h2>

            <div class="lrob-etk-field-row">
                <label for="lrob-etk-from-email"><?php esc_html_e('From email', 'lrob-email-toolkit'); ?></label>
                <div>
                    <input type="email" id="lrob-etk-from-email" name="from_email" class="regular-text" required
                           value="<?php echo esc_attr($f['from_email']); ?>">
                    <?php $this->render_override_note($overridden, 'from_email'); ?>
                    <p class="description">
                        <?php esc_html_e('Most SMTP servers require this to match the mailbox login. If you set it differently, the server may reject or rewrite the message.', 'lrob-email-toolkit'); ?>
                    </p>
                </div>
            </div>

            <div class="lrob-etk-field-row">
                <label for="lrob-etk-from-name"><?php esc_html_e('From name', 'lrob-email-toolkit'); ?></label>
                <div>
                    <input type="text" id="lrob-etk-from-name" name="from_name" class="regular-text" required
                           value="<?php echo esc_attr($f['from_name']); ?>">
                    <?php $this->render_override_note($overridden, 'from_name'); ?>
                </div>
            </div>

            <div class="lrob-etk-field-row">
                <label for="lrob-etk-reply-to"><?php esc_html_e('Reply-to', 'lrob-email-toolkit'); ?></label>
                <div>
                    <input type="email" id="lrob-etk-reply-to" name="reply_to_email" class="regular-text"
                           value="<?php echo esc_attr($f['reply_to_email']); ?>">
                    <p class="description"><?php esc_html_e('Optional. Where replies should go if different from "From".', 'lrob-email-toolkit'); ?></p>
                </div>
            </div>

            <div class="lrob-etk-field-row">
                <label><?php esc_html_e('Force "From"', 'lrob-email-toolkit'); ?></label>
                <div>
                    <label>
                        <input type="checkbox" name="force_from" value="1" <?php checked($f['force_from']); ?>>
                        <?php esc_html_e('Override the From address on every outgoing email', 'lrob-email-toolkit'); ?>
                    </label>
                </div>
            </div>
        </section>
        <?php
    }

    /** @param array<string, mixed> $f */
    private function render_status_section(array $f, bool $is_first): void
    {
        ?>
        <section class="lrob-etk-form-section">
            <h2><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></h2>

            <div class="lrob-etk-field-row">
                <label><?php esc_html_e('Active', 'lrob-email-toolkit'); ?></label>
                <div>
                    <label>
                        <input type="checkbox" name="is_active" value="1" <?php checked($f['is_active']); ?>>
                        <?php esc_html_e('This identity can be used for sending', 'lrob-email-toolkit'); ?>
                    </label>
                </div>
            </div>

            <div class="lrob-etk-field-row">
                <label><?php esc_html_e('Default', 'lrob-email-toolkit'); ?></label>
                <div>
                    <label>
                        <input type="checkbox" name="is_default" value="1" <?php checked($f['is_default']); ?> <?php disabled($is_first); ?>>
                        <?php esc_html_e('Use this identity for any source without a specific routing rule', 'lrob-email-toolkit'); ?>
                    </label>
                    <?php if ($is_first) : ?>
                        <input type="hidden" name="is_default" value="1">
                        <p class="description"><?php esc_html_e('The first identity is automatically the default.', 'lrob-email-toolkit'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
    }

    private function render_inline_set_default(Identity $identity): void
    {
        $action_url = admin_url('admin-post.php');
        ?>
        <form method="post" action="<?php echo esc_url($action_url); ?>" style="display:inline">
            <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_SET_DEFAULT); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $identity->id; ?>">
            <?php wp_nonce_field(PageController::ACTION_SET_DEFAULT, '_lrob_etk_nonce'); ?>
            <button type="submit" class="button button-secondary">
                <?php esc_html_e('Set as default', 'lrob-email-toolkit'); ?>
            </button>
        </form>
        <?php
    }

    private function render_test_section(Identity $identity, string $action_url): void
    {
        ?>
        <section class="lrob-etk-form-section">
            <h2><?php esc_html_e('Send a test email', 'lrob-email-toolkit'); ?></h2>
            <p class="description">
                <?php esc_html_e('Verify your configuration. The test bypasses routing and uses this identity directly.', 'lrob-email-toolkit'); ?>
            </p>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_TEST_SEND); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $identity->id; ?>">
                <?php wp_nonce_field(PageController::ACTION_TEST_SEND, '_lrob_etk_nonce'); ?>

                <div class="lrob-etk-field-row">
                    <label for="lrob-etk-test-recipient"><?php esc_html_e('Recipient', 'lrob-email-toolkit'); ?></label>
                    <div>
                        <input type="email" id="lrob-etk-test-recipient" name="recipient" class="regular-text" required
                               value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
                    </div>
                </div>

                <?php submit_button(__('Send test email', 'lrob-email-toolkit'), 'secondary', 'submit', false); ?>
            </form>
        </section>
        <?php
    }

    /** @param array<int, string> $overridden */
    private function render_override_note(array $overridden, string $field): void
    {
        if (!in_array($field, $overridden, true)) {
            return;
        }
        ?>
        <p class="lrob-etk-override-note">
            <span class="dashicons dashicons-lock"></span>
            <?php esc_html_e('Overridden by wp-config.php — value here is ignored at runtime.', 'lrob-email-toolkit'); ?>
        </p>
        <?php
    }

    /**
     * @param string|array<int, string>|null $notice
     * @param string|array<int, string>|null $errors
     */
    private function render_flash($notice, $errors): void
    {
        if (is_string($notice) && $notice !== '') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
        }
        if (is_array($errors)) {
            foreach ($errors as $error) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html((string) $error) . '</p></div>';
            }
        }
    }
}
