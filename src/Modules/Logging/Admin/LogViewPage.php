<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging\Admin;

use LRob\EmailToolkit\Modules\ContactForm\Admin\SubmissionsPage as ContactFormSubmissionsPage;
use LRob\EmailToolkit\Modules\ContactForm\SubmissionRepository as ContactFormSubmissions;
use LRob\EmailToolkit\Modules\Logging\LogEntry;

/**
 * Per-entry detail view. Compact metadata grid + sandboxed body preview.
 */
final class LogViewPage
{
    public function render(?LogEntry $entry): void
    {
        $list_url = admin_url('admin.php?page=' . PageController::SLUG);

        if (!$entry instanceof LogEntry) {
            ?>
            <div class="wrap lrob-etk">
                <h1 class="lrob-etk-page-title"><?php esc_html_e('Log entry not found', 'lrob-email-toolkit'); ?></h1>
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
            <h1 class="lrob-etk-page-title">
                <?php echo esc_html($this->detail_title($entry)); ?>
            </h1>

            <?php $this->render_flash($notice, $errors); ?>

            <p>
                <a href="<?php echo esc_url($list_url); ?>" class="button button-link">
                    ← <?php esc_html_e('Back to logs', 'lrob-email-toolkit'); ?>
                </a>
                <?php
                if (class_exists(ContactFormSubmissions::class)) {
                    $submission = (new ContactFormSubmissions())->find_by_log_id($entry->id);
                    if ($submission !== null) {
                        $submission_url = add_query_arg(
                            ['action' => 'view', 'id' => $submission->id],
                            ContactFormSubmissionsPage::base_url()
                        );
                        ?>
                        <a href="<?php echo esc_url($submission_url); ?>" class="button">
                            <span class="dashicons dashicons-feedback"></span>
                            <?php esc_html_e('View source submission', 'lrob-email-toolkit'); ?>
                        </a>
                        <?php
                    }
                }
                ?>
            </p>

            <?php $this->render_detail_body($entry); ?>

            <div class="lrob-etk-form-actions">
                <?php $this->render_resend_form($entry, $action_url); ?>
                <?php $this->render_delete_form($entry, $action_url); ?>
            </div>
        </div>
        <?php
    }

    /** Modal-friendly title for a log entry. */
    public function detail_title(LogEntry $entry): string
    {
        return $entry->subject !== '' ? $entry->subject : __('(no subject)', 'lrob-email-toolkit');
    }

    /**
     * The "body" content of the log detail — error banner + metadata
     * grid + custom headers + email body. Reused by the AJAX detail
     * endpoint that powers the in-page modal on the logs list.
     */
    public function render_detail_body(LogEntry $entry): void
    {
        ?>
        <?php if ($entry->error_message !== null) : ?>
            <div class="lrob-etk-log-error">
                <strong><?php esc_html_e('Send failed:', 'lrob-email-toolkit'); ?></strong>
                <?php echo esc_html($entry->error_message); ?>
            </div>
        <?php endif; ?>

        <div class="lrob-etk-log-meta">
            <?php $this->meta_row(__('Date', 'lrob-email-toolkit'), $entry->created_at->setTimezone(wp_timezone())->format('Y-m-d H:i:s')); ?>
            <?php if ($entry->sent_at instanceof \DateTimeImmutable) : ?>
                <?php $this->meta_row(__('Sent at', 'lrob-email-toolkit'), $entry->sent_at->setTimezone(wp_timezone())->format('Y-m-d H:i:s')); ?>
            <?php endif; ?>
            <?php $this->meta_row(__('Status', 'lrob-email-toolkit'), $this->status_html($entry->status), allow_html: true); ?>
            <?php $this->meta_row(__('Source', 'lrob-email-toolkit'), '<code>' . esc_html($entry->source) . '</code>', allow_html: true); ?>
            <?php if ($entry->identity_id !== null) : ?>
                <?php $this->meta_row(__('Identity', 'lrob-email-toolkit'), '#' . (int) $entry->identity_id); ?>
            <?php endif; ?>
            <?php $this->meta_row(__('From', 'lrob-email-toolkit'),
                $entry->from_name !== null && $entry->from_name !== ''
                    ? $entry->from_name . ' <' . $entry->from_email . '>'
                    : $entry->from_email,
                wide: true
            ); ?>
            <?php $this->meta_row(__('To', 'lrob-email-toolkit'), implode(', ', $entry->to_emails), wide: true); ?>
            <?php if ($entry->cc_emails !== []) : ?>
                <?php $this->meta_row(__('Cc', 'lrob-email-toolkit'), implode(', ', $entry->cc_emails), wide: true); ?>
            <?php endif; ?>
            <?php if ($entry->bcc_emails !== []) : ?>
                <?php $this->meta_row(__('Bcc', 'lrob-email-toolkit'), implode(', ', $entry->bcc_emails), wide: true); ?>
            <?php endif; ?>
            <?php if ($entry->reply_to !== null) : ?>
                <?php $this->meta_row(__('Reply-to', 'lrob-email-toolkit'), $entry->reply_to, wide: true); ?>
            <?php endif; ?>
            <?php if ($entry->attachments !== []) : ?>
                <?php $this->meta_row(
                    __('Attachments', 'lrob-email-toolkit'),
                    $this->render_attachments_html($entry->attachments),
                    allow_html: true,
                    wide: true
                ); ?>
            <?php endif; ?>
        </div>

        <?php if ($entry->headers !== []) : ?>
            <details class="lrob-etk-log-body">
                <summary><h3 style="display:inline-block;margin:0"><?php esc_html_e('Custom headers', 'lrob-email-toolkit'); ?></h3></summary>
                <table class="widefat striped" style="margin-top:8px">
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
            </details>
        <?php endif; ?>

        <?php $this->render_body($entry); ?>
        <?php
    }

    /** @param array<int, array{name: string, path: ?string}> $attachments */
    private function render_attachments_html(array $attachments): string
    {
        $items = [];
        foreach ($attachments as $a) {
            $name = (string) ($a['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $path = $a['path'] ?? null;
            $status = '';
            if ($path === null) {
                $status = ' <span class="lrob-etk-attachment-status">' . esc_html__('(no path — inline content)', 'lrob-email-toolkit') . '</span>';
            } elseif (!is_file($path)) {
                $status = ' <span class="lrob-etk-attachment-status is-missing">' . esc_html__('(file missing — won\'t re-send)', 'lrob-email-toolkit') . '</span>';
            } else {
                $status = ' <span class="lrob-etk-attachment-status is-ok">' . esc_html__('(still on disk)', 'lrob-email-toolkit') . '</span>';
            }
            $items[] = esc_html($name) . $status;
        }
        return implode('<br>', $items);
    }

    private function meta_row(string $label, string $value, bool $allow_html = false, bool $wide = false): void
    {
        $class = $wide ? 'lrob-etk-log-meta-row is-wide' : 'lrob-etk-log-meta-row';
        ?>
        <div class="<?php echo esc_attr($class); ?>">
            <span class="label"><?php echo esc_html($label); ?></span>
            <span class="value">
                <?php echo $allow_html ? $value : esc_html($value); ?>
            </span>
        </div>
        <?php
    }

    private function status_html(string $status): string
    {
        $class = match ($status) {
            LogEntry::STATUS_SENT    => 'lrob-etk-status--on',
            LogEntry::STATUS_FAILED  => 'lrob-etk-status--fail',
            LogEntry::STATUS_SENDING => 'lrob-etk-status--pending',
            default                  => 'lrob-etk-status--off',
        };
        return sprintf('<span class="lrob-etk-status %s">%s</span>', esc_attr($class), esc_html($status));
    }

    private function render_body(LogEntry $entry): void
    {
        $has_html = $entry->body_html !== null && $entry->body_html !== '';
        $has_text = $entry->body_text !== null && $entry->body_text !== '';

        if (!$has_html && !$has_text) {
            return;
        }

        if ($has_html) {
            ?>
            <div class="lrob-etk-log-body">
                <h3><?php esc_html_e('HTML body', 'lrob-email-toolkit'); ?></h3>
                <iframe
                    sandbox=""
                    srcdoc="<?php echo esc_attr((string) $entry->body_html); ?>"
                ></iframe>
            </div>
            <?php
        }

        if ($has_text) {
            ?>
            <div class="lrob-etk-log-body">
                <h3>
                    <?php echo $has_html
                        ? esc_html__('Plain-text alternative', 'lrob-email-toolkit')
                        : esc_html__('Plain-text body', 'lrob-email-toolkit'); ?>
                </h3>
                <pre><?php echo esc_html((string) $entry->body_text); ?></pre>
            </div>
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
