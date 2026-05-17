<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging\Admin;

use LRob\EmailToolkit\Modules\Logging\LogEntry;

/**
 * Per-entry detail view. Shows everything we know about the email, plus
 * resend / delete actions.
 *
 * HTML preview safety: the stored body comes from `wp_mail()` callers who
 * may have rendered template variables. We render it inside a sandboxed
 * iframe with srcdoc to prevent any embedded scripts or styles from leaking
 * into the admin UI.
 */
final class LogViewPage
{
    public function render(?LogEntry $entry): void
    {
        $list_url = admin_url('admin.php?page=' . PageController::SLUG);

        if (!$entry instanceof LogEntry) {
            ?>
            <div class="wrap lrob-etk">
                <h1><?php esc_html_e('Log entry not found', 'lrob-email-toolkit'); ?></h1>
                <p>
                    <a href="<?php echo esc_url($list_url); ?>" class="button">
                        <?php esc_html_e('Back to logs', 'lrob-email-toolkit'); ?>
                    </a>
                </p>
            </div>
            <?php
            return;
        }

        $notice = PageController::pop_flash('notice');
        $errors = PageController::pop_flash('errors');
        $action_url = admin_url('admin-post.php');
        ?>
        <div class="wrap lrob-etk">
            <h1>
                <?php esc_html_e('Email log entry', 'lrob-email-toolkit'); ?>
                <span class="title-count theme-count">#<?php echo (int) $entry->id; ?></span>
            </h1>

            <?php $this->render_flash($notice, $errors); ?>

            <p>
                <a href="<?php echo esc_url($list_url); ?>" class="button">
                    ← <?php esc_html_e('Back to logs', 'lrob-email-toolkit'); ?>
                </a>
            </p>

            <table class="form-table">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Date', 'lrob-email-toolkit'); ?></th>
                        <td><?php echo esc_html($entry->created_at->setTimezone(wp_timezone())->format('Y-m-d H:i:s T')); ?></td>
                    </tr>
                    <?php if ($entry->sent_at instanceof \DateTimeImmutable) : ?>
                        <tr>
                            <th><?php esc_html_e('Sent at', 'lrob-email-toolkit'); ?></th>
                            <td><?php echo esc_html($entry->sent_at->setTimezone(wp_timezone())->format('Y-m-d H:i:s T')); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                        <td><code><?php echo esc_html($entry->status); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Source', 'lrob-email-toolkit'); ?></th>
                        <td><code><?php echo esc_html($entry->source); ?></code></td>
                    </tr>
                    <?php if ($entry->identity_id !== null) : ?>
                        <tr>
                            <th><?php esc_html_e('Identity ID', 'lrob-email-toolkit'); ?></th>
                            <td><?php echo (int) $entry->identity_id; ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e('From', 'lrob-email-toolkit'); ?></th>
                        <td>
                            <?php
                            if ($entry->from_name !== null && $entry->from_name !== '') {
                                echo esc_html($entry->from_name) . ' &lt;' . esc_html($entry->from_email) . '&gt;';
                            } else {
                                echo esc_html($entry->from_email);
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('To', 'lrob-email-toolkit'); ?></th>
                        <td><?php echo esc_html(implode(', ', $entry->to_emails)); ?></td>
                    </tr>
                    <?php if ($entry->cc_emails !== []) : ?>
                        <tr>
                            <th><?php esc_html_e('Cc', 'lrob-email-toolkit'); ?></th>
                            <td><?php echo esc_html(implode(', ', $entry->cc_emails)); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($entry->bcc_emails !== []) : ?>
                        <tr>
                            <th><?php esc_html_e('Bcc', 'lrob-email-toolkit'); ?></th>
                            <td><?php echo esc_html(implode(', ', $entry->bcc_emails)); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($entry->reply_to !== null) : ?>
                        <tr>
                            <th><?php esc_html_e('Reply-to', 'lrob-email-toolkit'); ?></th>
                            <td><?php echo esc_html($entry->reply_to); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e('Subject', 'lrob-email-toolkit'); ?></th>
                        <td><strong><?php echo esc_html($entry->subject); ?></strong></td>
                    </tr>
                    <?php if ($entry->attachments !== []) : ?>
                        <tr>
                            <th><?php esc_html_e('Attachments', 'lrob-email-toolkit'); ?></th>
                            <td>
                                <ul style="margin:0">
                                    <?php foreach ($entry->attachments as $name) : ?>
                                        <li><span class="dashicons dashicons-paperclip"></span> <?php echo esc_html((string) $name); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($entry->error_message !== null) : ?>
                        <tr>
                            <th><?php esc_html_e('Error', 'lrob-email-toolkit'); ?></th>
                            <td><pre style="white-space:pre-wrap;color:#a00"><?php echo esc_html($entry->error_message); ?></pre></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($entry->headers !== []) : ?>
                <h2 class="title"><?php esc_html_e('Custom headers', 'lrob-email-toolkit'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Name', 'lrob-email-toolkit'); ?></th>
                            <th><?php esc_html_e('Value', 'lrob-email-toolkit'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entry->headers as $h) :
                            if (!is_array($h)) {
                                continue;
                            }
                            ?>
                            <tr>
                                <td><code><?php echo esc_html((string) ($h['name'] ?? '')); ?></code></td>
                                <td><code><?php echo esc_html((string) ($h['value'] ?? '')); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 class="title"><?php esc_html_e('Body', 'lrob-email-toolkit'); ?></h2>
            <?php $this->render_body($entry); ?>

            <p class="submit">
                <?php $this->render_resend_form($entry, $action_url); ?>
                <?php $this->render_delete_form($entry, $action_url); ?>
            </p>
        </div>
        <?php
    }

    private function render_body(LogEntry $entry): void
    {
        $has_html = $entry->body_html !== null && $entry->body_html !== '';
        $has_text = $entry->body_text !== null && $entry->body_text !== '';

        if (!$has_html && !$has_text) {
            echo '<p><em>' . esc_html__('(no body)', 'lrob-email-toolkit') . '</em></p>';
            return;
        }

        if ($has_html) {
            ?>
            <p><strong><?php esc_html_e('HTML body (sandboxed preview):', 'lrob-email-toolkit'); ?></strong></p>
            <iframe
                sandbox=""
                srcdoc="<?php echo esc_attr((string) $entry->body_html); ?>"
                style="width:100%;min-height:400px;border:1px solid #c3c4c7;background:#fff"
            ></iframe>
            <?php
        }

        if ($has_text) {
            ?>
            <p><strong><?php echo $has_html
                ? esc_html__('Plain-text alternative:', 'lrob-email-toolkit')
                : esc_html__('Plain-text body:', 'lrob-email-toolkit'); ?></strong></p>
            <pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;border:1px solid #c3c4c7;max-height:400px;overflow:auto"><?php echo esc_html((string) $entry->body_text); ?></pre>
            <?php
        }
    }

    private function render_resend_form(LogEntry $entry, string $action_url): void
    {
        ?>
        <form method="post" action="<?php echo esc_url($action_url); ?>" style="display:inline">
            <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_RESEND); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $entry->id; ?>">
            <?php wp_nonce_field(PageController::ACTION_RESEND, '_lrob_etk_nonce'); ?>
            <button type="submit" class="button button-primary"><?php esc_html_e('Resend', 'lrob-email-toolkit'); ?></button>
        </form>
        <?php
    }

    private function render_delete_form(LogEntry $entry, string $action_url): void
    {
        ?>
        <form method="post" action="<?php echo esc_url($action_url); ?>" style="display:inline"
              onsubmit="return confirm('<?php echo esc_js(__('Delete this log entry?', 'lrob-email-toolkit')); ?>');">
            <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_DELETE); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $entry->id; ?>">
            <?php wp_nonce_field(PageController::ACTION_DELETE, '_lrob_etk_nonce'); ?>
            <button type="submit" class="button button-link-delete"><?php esc_html_e('Delete', 'lrob-email-toolkit'); ?></button>
        </form>
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
