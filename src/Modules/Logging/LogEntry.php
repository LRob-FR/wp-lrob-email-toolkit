<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Immutable log entry record. Constructed from a DB row, from a PHPMailer
 * instance at send time, or directly for tests. Multi-recipient and address
 * fields are kept as PHP arrays; JSON encoding happens in the repository at
 * the persistence boundary.
 */
final class LogEntry
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RETRIED = 'retried';

    /**
     * @param array<int, string>                                $to_emails
     * @param array<int, string>                                $cc_emails
     * @param array<int, string>                                $bcc_emails
     * @param array<int, array{name: string, value: string}>    $headers
     * @param array<int, array{name: string, path: ?string}>    $attachments
     */
    public function __construct(
        public readonly ?int $id,
        public readonly \DateTimeImmutable $created_at,
        public readonly ?\DateTimeImmutable $sent_at,
        public readonly string $status,
        public readonly string $source,
        public readonly ?int $identity_id,
        public readonly ?int $newsletter_id,
        public readonly ?int $recipient_id,
        public readonly string $from_email,
        public readonly ?string $from_name,
        public readonly array $to_emails,
        public readonly array $cc_emails,
        public readonly array $bcc_emails,
        public readonly ?string $reply_to,
        public readonly string $subject,
        public readonly ?string $body_html,
        public readonly ?string $body_text,
        public readonly array $headers,
        public readonly array $attachments,
        public readonly ?string $message_id,
        public readonly ?string $error_message,
        public readonly int $retry_count,
    ) {
    }

    /**
     * Build a fresh log entry from the PHPMailer object inside wp_mail().
     * Status starts as 'sending'; the repository's update_status() flips it
     * to 'sent' / 'failed' after the send completes.
     */
    public static function from_phpmailer(PHPMailer $mailer): self
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $to  = self::extract_addresses($mailer->getToAddresses());
        $cc  = self::extract_addresses($mailer->getCcAddresses());
        $bcc = self::extract_addresses($mailer->getBccAddresses());

        $reply_addresses = $mailer->getReplyToAddresses();
        $reply_to = null;
        if (is_array($reply_addresses) && $reply_addresses !== []) {
            $first = reset($reply_addresses);
            $reply_to = is_array($first) ? ((string) ($first[0] ?? '')) : (string) $first;
            if ($reply_to === '') {
                $reply_to = (string) array_key_first($reply_addresses);
            }
        }

        $headers = [];
        foreach ($mailer->getCustomHeaders() as $h) {
            $headers[] = ['name' => (string) ($h[0] ?? ''), 'value' => (string) ($h[1] ?? '')];
        }

        $attachments = [];
        foreach ($mailer->getAttachments() as $a) {
            // PHPMailer attachment row layout:
            //   [0] content/path, [1] name, [2] encoded_name, [3] encoding,
            //   [4] type, [5] isString, [6] disposition, [7] cid
            $name = (string) ($a[2] ?? $a[1] ?? '');
            if ($name === '') {
                continue;
            }
            $is_string = !empty($a[5]);
            $path = !$is_string && isset($a[0]) ? (string) $a[0] : null;
            $attachments[] = ['name' => $name, 'path' => $path];
        }

        $is_html  = stripos((string) $mailer->ContentType, 'html') !== false;
        $body     = (string) $mailer->Body;
        $alt_body = (string) $mailer->AltBody;

        return new self(
            id: null,
            created_at: $now,
            sent_at: null,
            status: self::STATUS_SENDING,
            source: 'unknown',
            identity_id: null,
            newsletter_id: null,
            recipient_id: null,
            from_email: (string) $mailer->From,
            from_name: $mailer->FromName !== '' ? (string) $mailer->FromName : null,
            to_emails: $to,
            cc_emails: $cc,
            bcc_emails: $bcc,
            reply_to: $reply_to !== '' ? $reply_to : null,
            subject: (string) $mailer->Subject,
            body_html: $is_html ? $body : null,
            body_text: $is_html ? ($alt_body !== '' ? $alt_body : null) : $body,
            headers: $headers,
            attachments: $attachments,
            message_id: $mailer->MessageID !== '' ? (string) $mailer->MessageID : null,
            error_message: null,
            retry_count: 0,
        );
    }

    /**
     * Build from a wpdb-fetched associative row. JSON columns are decoded.
     *
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            created_at: self::parse_datetime($row['created_at'] ?? null) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            sent_at: self::parse_datetime($row['sent_at'] ?? null),
            status: (string) ($row['status'] ?? self::STATUS_SENDING),
            source: (string) ($row['source'] ?? 'unknown'),
            identity_id: isset($row['identity_id']) && $row['identity_id'] !== null ? (int) $row['identity_id'] : null,
            newsletter_id: isset($row['newsletter_id']) && $row['newsletter_id'] !== null ? (int) $row['newsletter_id'] : null,
            recipient_id: isset($row['recipient_id']) && $row['recipient_id'] !== null ? (int) $row['recipient_id'] : null,
            from_email: (string) ($row['from_email'] ?? ''),
            from_name: isset($row['from_name']) && $row['from_name'] !== '' ? (string) $row['from_name'] : null,
            to_emails: self::decode_array($row['to_emails'] ?? null),
            cc_emails: self::decode_array($row['cc_emails'] ?? null),
            bcc_emails: self::decode_array($row['bcc_emails'] ?? null),
            reply_to: isset($row['reply_to']) && $row['reply_to'] !== '' ? (string) $row['reply_to'] : null,
            subject: (string) ($row['subject'] ?? ''),
            body_html: isset($row['body_html']) && $row['body_html'] !== null ? (string) $row['body_html'] : null,
            body_text: isset($row['body_text']) && $row['body_text'] !== null ? (string) $row['body_text'] : null,
            headers: self::decode_array($row['headers'] ?? null),
            attachments: self::normalize_attachments(self::decode_array($row['attachments'] ?? null)),
            message_id: isset($row['message_id']) && $row['message_id'] !== '' ? (string) $row['message_id'] : null,
            error_message: isset($row['error_message']) && $row['error_message'] !== '' ? (string) $row['error_message'] : null,
            retry_count: isset($row['retry_count']) ? (int) $row['retry_count'] : 0,
        );
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        $merged = array_merge($this->to_array(), $changes);
        return new self(
            id: $merged['id'],
            created_at: $merged['created_at'],
            sent_at: $merged['sent_at'],
            status: $merged['status'],
            source: $merged['source'],
            identity_id: $merged['identity_id'],
            newsletter_id: $merged['newsletter_id'],
            recipient_id: $merged['recipient_id'],
            from_email: $merged['from_email'],
            from_name: $merged['from_name'],
            to_emails: $merged['to_emails'],
            cc_emails: $merged['cc_emails'],
            bcc_emails: $merged['bcc_emails'],
            reply_to: $merged['reply_to'],
            subject: $merged['subject'],
            body_html: $merged['body_html'],
            body_text: $merged['body_text'],
            headers: $merged['headers'],
            attachments: $merged['attachments'],
            message_id: $merged['message_id'],
            error_message: $merged['error_message'],
            retry_count: $merged['retry_count'],
        );
    }

    /** @return array<string, mixed> */
    public function to_array(): array
    {
        return [
            'id'            => $this->id,
            'created_at'    => $this->created_at,
            'sent_at'       => $this->sent_at,
            'status'        => $this->status,
            'source'        => $this->source,
            'identity_id'   => $this->identity_id,
            'newsletter_id'   => $this->newsletter_id,
            'recipient_id'  => $this->recipient_id,
            'from_email'    => $this->from_email,
            'from_name'     => $this->from_name,
            'to_emails'     => $this->to_emails,
            'cc_emails'     => $this->cc_emails,
            'bcc_emails'    => $this->bcc_emails,
            'reply_to'      => $this->reply_to,
            'subject'       => $this->subject,
            'body_html'     => $this->body_html,
            'body_text'     => $this->body_text,
            'headers'       => $this->headers,
            'attachments'   => $this->attachments,
            'message_id'    => $this->message_id,
            'error_message' => $this->error_message,
            'retry_count'   => $this->retry_count,
        ];
    }

    /**
     * @param array<int, array{0?: string, 1?: string}> $rows
     * @return array<int, string>
     */
    private static function extract_addresses(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $email = is_array($row) ? (string) ($row[0] ?? '') : (string) $row;
            if ($email !== '') {
                $out[] = $email;
            }
        }
        return $out;
    }

    /** @return array<int, mixed> */
    private static function decode_array(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_values($value);
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Normalize stored attachments to the canonical shape. Old rows stored
     * plain filename strings; new rows store {name, path} objects. Both
     * variants are accepted on read.
     *
     * @param  array<int, mixed> $raw
     * @return array<int, array{name: string, path: ?string}>
     */
    private static function normalize_attachments(array $raw): array
    {
        $out = [];
        foreach ($raw as $a) {
            if (is_string($a) && $a !== '') {
                $out[] = ['name' => $a, 'path' => null];
                continue;
            }
            if (is_array($a)) {
                $name = isset($a['name']) ? (string) $a['name'] : '';
                if ($name === '') {
                    continue;
                }
                $path = isset($a['path']) && $a['path'] !== null && $a['path'] !== '' ? (string) $a['path'] : null;
                $out[] = ['name' => $name, 'path' => $path];
            }
        }
        return $out;
    }

    private static function parse_datetime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
