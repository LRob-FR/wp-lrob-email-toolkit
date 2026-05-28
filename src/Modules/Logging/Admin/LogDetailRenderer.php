<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging\Admin;

use LRob\EmailToolkit\Modules\Logging\LogEntry;

/**
 * Renders the body markup for a single log entry — error banner +
 * metadata grid + custom headers + sandboxed body preview. Consumed by
 * the AJAX detail endpoint that powers the in-page modal on the logs list.
 */
final class LogDetailRenderer
{
    /** Modal title for a log entry. */
    public function detail_title(LogEntry $entry): string
    {
        return $entry->subject !== '' ? $entry->subject : __('(no subject)', 'lrob-email-toolkit');
    }

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
}
