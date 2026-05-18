<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging;

/**
 * Re-sends a previously logged email by reconstructing wp_mail() arguments
 * from the stored entry. The retry produces a new log row with current
 * timestamp and its own status (sent / failed). The original entry is left
 * untouched — it represents a separate, earlier send event.
 *
 * Note: earlier versions of this class flipped the original entry's status
 * to `retried`. That made the stats undercount real activity (one resend
 * net zero in the "sent" count). The original stays as it was now.
 *
 * Attachments: we store both filename and full path for each. At resend time,
 * each attachment is re-attached if its file still exists on disk. Files that
 * are gone are skipped silently; the response reports how many were dropped.
 * Inline-string attachments (rare in wp_mail flows) have no path and are
 * never re-attachable.
 */
final class Resender
{
    public function __construct(private LogRepository $repository)
    {
    }

    /**
     * @return array{success: bool, error: ?string, attachments_dropped: bool, attachments_total?: int, attachments_sent?: int}
     */
    public function resend(int $log_id): array
    {
        $entry = $this->repository->find($log_id);
        if (!$entry instanceof LogEntry) {
            return [
                'success'             => false,
                'error'               => 'Log entry not found.',
                'attachments_dropped' => false,
            ];
        }

        $to = $entry->to_emails;
        $subject = $entry->subject;
        $message = $entry->body_html ?? $entry->body_text ?? '';
        $is_html = $entry->body_html !== null && $entry->body_html !== '';

        $headers = $this->build_headers($entry, $is_html);

        // Resolve attachment paths that still exist on disk.
        $available = [];
        $dropped = 0;
        foreach ($entry->attachments as $a) {
            $path = $a['path'] ?? null;
            if ($path !== null && $path !== '' && is_file($path) && is_readable($path)) {
                $available[] = $path;
            } else {
                $dropped++;
            }
        }

        // Do NOT touch the original entry. The new log row that wp_mail
        // creates via the Logger represents the resend with the current
        // timestamp and its own success/failure status.

        $captured_error = null;
        $capture = static function (\WP_Error $err) use (&$captured_error): void {
            $captured_error = $err;
        };
        add_action('wp_mail_failed', $capture, 1);

        try {
            $success = wp_mail($to, $subject, $message, $headers, $available);
        } finally {
            remove_action('wp_mail_failed', $capture, 1);
        }

        return [
            'success'             => (bool) $success,
            'error'               => $captured_error instanceof \WP_Error ? $captured_error->get_error_message() : null,
            'attachments_dropped' => $dropped > 0,
            'attachments_total'   => count($entry->attachments),
            'attachments_sent'    => count($available),
        ];
    }

    /** @return array<int, string> */
    private function build_headers(LogEntry $entry, bool $is_html): array
    {
        $headers = [];

        if ($is_html) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }

        if ($entry->from_email !== '') {
            $headers[] = $entry->from_name !== null && $entry->from_name !== ''
                ? sprintf('From: %s <%s>', $entry->from_name, $entry->from_email)
                : sprintf('From: %s', $entry->from_email);
        }

        if ($entry->reply_to !== null) {
            $headers[] = sprintf('Reply-To: %s', $entry->reply_to);
        }

        foreach ($entry->cc_emails as $cc) {
            if (is_string($cc) && $cc !== '') {
                $headers[] = 'Cc: ' . $cc;
            }
        }
        foreach ($entry->bcc_emails as $bcc) {
            if (is_string($bcc) && $bcc !== '') {
                $headers[] = 'Bcc: ' . $bcc;
            }
        }

        $already = ['content-type', 'from', 'reply-to', 'cc', 'bcc'];
        foreach ($entry->headers as $h) {
            if (!is_array($h)) {
                continue;
            }
            $name = (string) ($h['name'] ?? '');
            $value = (string) ($h['value'] ?? '');
            if ($name === '' || in_array(strtolower($name), $already, true)) {
                continue;
            }
            $headers[] = $name . ': ' . $value;
        }

        return $headers;
    }
}
