<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP\Admin;

use LRob\EmailToolkit\Modules\SMTP\ConstantOverrides;
use LRob\EmailToolkit\Modules\SMTP\Identity;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;

/**
 * Edit / create form for a single SMTP identity. Also embeds the "Send test
 * email" form when editing an existing identity.
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

        // Defaults for a new identity
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
            <h1>
                <?php echo $is_new
                    ? esc_html__('Add SMTP identity', 'lrob-email-toolkit')
                    : esc_html__('Edit SMTP identity', 'lrob-email-toolkit'); ?>
            </h1>

            <?php $this->render_flash($notice, $errors); ?>

            <?php if ($overridden !== [] && $f['is_default']) : ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php esc_html_e('Some fields are overridden by wp-config.php constants.', 'lrob-email-toolkit'); ?></strong>
                        <?php esc_html_e('You can still edit them here, but the saved value is ignored at runtime — the constant value is used instead.', 'lrob-email-toolkit'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_SAVE); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                <?php wp_nonce_field(PageController::ACTION_SAVE, '_lrob_etk_nonce'); ?>

                <h2 class="title"><?php esc_html_e('Identity', 'lrob-email-toolkit'); ?></h2>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="lrob-etk-label"><?php esc_html_e('Label', 'lrob-email-toolkit'); ?> <span class="description">*</span></label>
                            </th>
                            <td>
                                <input type="text" id="lrob-etk-label" name="label" class="regular-text" required
                                       value="<?php echo esc_attr($f['label']); ?>">
                                <p class="description"><?php esc_html_e('Internal name shown in the admin (e.g. "Main site", "Newsletter sender").', 'lrob-email-toolkit'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="lrob-etk-slug"><?php esc_html_e('Slug', 'lrob-email-toolkit'); ?> <span class="description">*</span></label>
                            </th>
                            <td>
                                <input type="text" id="lrob-etk-slug" name="slug" class="regular-text code" required pattern="[a-z0-9_]+" maxlength="50"
                                       value="<?php echo esc_attr($f['slug']); ?>">
                                <p class="description"><?php esc_html_e('Lowercase letters, digits, underscores. Used in routing rules. Cannot be reused across identities.', 'lrob-email-toolkit'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2 class="title"><?php esc_html_e('From address', 'lrob-email-toolkit'); ?></h2>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="lrob-etk-from-email"><?php esc_html_e('From email', 'lrob-email-toolkit'); ?> <span class="description">*</span></label>
                            </th>
                            <td>
                                <input type="email" id="lrob-etk-from-email" name="from_email" class="regular-text" required
                                       value="<?php echo esc_attr($f['from_email']); ?>">
                                <?php $this->render_override_note($overridden, 'from_email'); ?>
                                <p class="description">
                                    <?php esc_html_e('Most SMTP servers require this to match the SMTP username below — many will reject or rewrite mismatched messages.', 'lrob-email-toolkit'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="lrob-etk-from-name"><?php esc_html_e('From name', 'lrob-email-toolkit'); ?> <span class="description">*</span></label>
                            </th>
                            <td>
                                <input type="text" id="lrob-etk-from-name" name="from_name" class="regular-text" required
                                       value="<?php echo esc_attr($f['from_name']); ?>">
                                <?php $this->render_override_note($overridden, 'from_name'); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="lrob-etk-reply-to"><?php esc_html_e('Reply-to', 'lrob-email-toolkit'); ?></label>
                            </th>
                            <td>
                                <input type="email" id="lrob-etk-reply-to" name="reply_to_email" class="regular-text"
                                       value="<?php echo esc_attr($f['reply_to_email']); ?>">
                                <p class="description"><?php esc_html_e('Optional. Where replies should be sent if different from "From".', 'lrob-email-toolkit'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Force "From"', 'lrob-email-toolkit'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="force_from" value="1" <?php checked($f['force_from']); ?>>
                                    <?php esc_html_e('Override the From address on every outgoing email with the values above.', 'lrob-email-toolkit'); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2 class="title"><?php esc_html_e('SMTP server', 'lrob-email-toolkit'); ?></h2>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="lrob-etk-host"><?php esc_html_e('Host', 'lrob-email-toolkit'); ?> <span class="description">*</span></label>
                            </th>
                            <td>
                                <input type="text" id="lrob-etk-host" name="smtp_host" class="regular-text" required
                                       value="<?php echo esc_attr($f['smtp_host']); ?>" placeholder="smtp.example.com">
                                <?php $this->render_override_note($overridden, 'smtp_host'); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="lrob-etk-port"><?php esc_html_e('Port', 'lrob-email-toolkit'); ?> <span class="description">*</span></label>
                            </th>
                            <td>
                                <input type="number" id="lrob-etk-port" name="smtp_port" class="small-text" required min="1" max="65535"
                                       value="<?php echo (int) $f['smtp_port']; ?>">
                                <?php $this->render_override_note($overridden, 'smtp_port'); ?>
                                <p class="description"><?php esc_html_e('Common: 587 (STARTTLS), 465 (SSL/SMTPS), 25 (plain).', 'lrob-email-toolkit'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Encryption', 'lrob-email-toolkit'); ?></th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="radio" name="smtp_encryption" value="<?php echo esc_attr(Identity::ENCRYPTION_STARTTLS); ?>" <?php checked($f['smtp_encryption'], Identity::ENCRYPTION_STARTTLS); ?>>
                                        <?php esc_html_e('STARTTLS (recommended, port 587)', 'lrob-email-toolkit'); ?>
                                    </label><br>
                                    <label>
                                        <input type="radio" name="smtp_encryption" value="<?php echo esc_attr(Identity::ENCRYPTION_SSL); ?>" <?php checked($f['smtp_encryption'], Identity::ENCRYPTION_SSL); ?>>
                                        <?php esc_html_e('SSL/TLS implicit (port 465)', 'lrob-email-toolkit'); ?>
                                    </label><br>
                                    <label>
                                        <input type="radio" name="smtp_encryption" value="<?php echo esc_attr(Identity::ENCRYPTION_NONE); ?>" <?php checked($f['smtp_encryption'], Identity::ENCRYPTION_NONE); ?>>
                                        <?php esc_html_e('None — not recommended', 'lrob-email-toolkit'); ?>
                                    </label>
                                </fieldset>
                                <?php $this->render_override_note($overridden, 'smtp_encryption'); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Authentication', 'lrob-email-toolkit'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="smtp_auth" value="1" <?php checked($f['smtp_auth']); ?>>
                                    <?php esc_html_e('SMTP server requires authentication', 'lrob-email-toolkit'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="lrob-etk-username"><?php esc_html_e('Username', 'lrob-email-toolkit'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="lrob-etk-username" name="smtp_username" class="regular-text" autocomplete="off"
                                       value="<?php echo esc_attr($f['smtp_username']); ?>">
                                <?php $this->render_override_note($overridden, 'smtp_username'); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="lrob-etk-password"><?php esc_html_e('Password', 'lrob-email-toolkit'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="lrob-etk-password" name="smtp_password" class="regular-text" autocomplete="new-password"
                                       <?php echo $is_new ? '' : 'placeholder="' . esc_attr__('(unchanged — type to replace)', 'lrob-email-toolkit') . '"'; ?>
                                       value="">
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
                                    <?php esc_html_e('Stored encrypted at rest. To override via wp-config.php, define LROB_ETK_SMTP_PASS.', 'lrob-email-toolkit'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2 class="title"><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></h2>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Active', 'lrob-email-toolkit'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="is_active" value="1" <?php checked($f['is_active']); ?>>
                                    <?php esc_html_e('This identity can be used for sending', 'lrob-email-toolkit'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Default', 'lrob-email-toolkit'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="is_default" value="1" <?php checked($f['is_default']); ?> <?php disabled($is_first); ?>>
                                    <?php esc_html_e('Use this identity for any source without a specific routing rule', 'lrob-email-toolkit'); ?>
                                </label>
                                <?php if ($is_first) : ?>
                                    <input type="hidden" name="is_default" value="1">
                                    <p class="description"><?php esc_html_e('The first identity is automatically the default.', 'lrob-email-toolkit'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <?php submit_button(__('Save identity', 'lrob-email-toolkit'), 'primary', 'submit', false); ?>
                    <a href="<?php echo esc_url($list_url); ?>" class="button button-secondary"><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></a>
                </p>
            </form>

            <?php if (!$is_new && $identity instanceof Identity) : ?>
                <?php $this->render_test_section($identity, $action_url); ?>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            // Auto-suggest from-email from SMTP username when from-email is empty.
            var $username = document.getElementById('lrob-etk-username');
            var $fromEmail = document.getElementById('lrob-etk-from-email');
            if (!$username || !$fromEmail) return;
            $username.addEventListener('blur', function () {
                if ($fromEmail.value.trim() === '' && $username.value.indexOf('@') !== -1) {
                    $fromEmail.value = $username.value;
                }
            });

            // Warn when from-email diverges from SMTP username.
            var $warn = document.getElementById('lrob-etk-from-mismatch-warning');
            function checkMatch() {
                if (!$warn) return;
                var u = $username.value.trim();
                var f = $fromEmail.value.trim();
                if (u !== '' && f !== '' && u !== f) {
                    $warn.style.display = 'block';
                } else {
                    $warn.style.display = 'none';
                }
            }
            $username.addEventListener('input', checkMatch);
            $fromEmail.addEventListener('input', checkMatch);
        })();
        </script>
        <?php
    }

    private function render_test_section(Identity $identity, string $action_url): void
    {
        ?>
        <hr>
        <h2 class="title"><?php esc_html_e('Send a test email', 'lrob-email-toolkit'); ?></h2>
        <p class="description">
            <?php esc_html_e('Verify your configuration by sending a test email through this identity. The test bypasses routing rules and uses this identity directly.', 'lrob-email-toolkit'); ?>
        </p>
        <form method="post" action="<?php echo esc_url($action_url); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_TEST_SEND); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $identity->id; ?>">
            <?php wp_nonce_field(PageController::ACTION_TEST_SEND, '_lrob_etk_nonce'); ?>

            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="lrob-etk-test-recipient"><?php esc_html_e('Recipient', 'lrob-email-toolkit'); ?></label>
                        </th>
                        <td>
                            <input type="email" id="lrob-etk-test-recipient" name="recipient" class="regular-text" required
                                   value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="submit">
                <?php submit_button(__('Send test email', 'lrob-email-toolkit'), 'secondary', 'submit', false); ?>
            </p>
        </form>
        <?php
    }

    /** @param array<int, string> $overridden */
    private function render_override_note(array $overridden, string $field): void
    {
        if (!in_array($field, $overridden, true)) {
            return;
        }
        ?>
        <p class="description lrob-etk-override-note">
            <span class="dashicons dashicons-lock"></span>
            <?php esc_html_e('Overridden by wp-config.php — the value above is ignored at runtime.', 'lrob-email-toolkit'); ?>
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
