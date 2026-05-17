<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging;

/**
 * Re-sends a previously logged email by reconstructing wp_mail() arguments
 * from the stored entry. The retry produces a new log row (status=sent or
 * failed); the original entry is marked as 'retried' for traceability.
 *
 * Limitation: attachments aren't re-sent. We store the filename only — the
 * original file may have been a temporary upload that's since been deleted,
 * and even if it's still on disk, the full path isn't preserved. The retry
 * goes out without attachments and notes that in the response.
 */
final class Resender
{
    public function __construct(private LogRepository $repository)
    {
    }

    /**
     * @return array{success: bool, error: ?string, attachments_dropped: bool}
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

        // Mark original as retried before re-sending so the new entry's row
        // appears distinct from the old one in the list.
        $this->repository->update_status($log_id, LogEntry::STATUS_RETRIED);

        $captured_error = null;
        $capture = static function (\WP_Error $err) use (&$captured_error): void {
            $captured_error = $err;
        };
        add_action('wp_mail_failed', $capture, 1);

        try {
            $success = wp_mail($to, $subject, $message, $headers);
        } finally {
            remove_action('wp_mail_failed', $capture, 1);
        }

        return [
            'success'             => (bool) $success,
            'error'               => $captured_error instanceof \WP_Error ? $captured_error->get_error_message() : null,
            'attachments_dropped' => $entry->attachments !== [],
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
