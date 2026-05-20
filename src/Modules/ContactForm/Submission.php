<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Value object for one row of `lrob_etk_contact_submissions`. Decoded from
 * the raw row by ::from_row(). Field values live in `fields` as decoded
 * key→value pairs ready for rendering.
 *
 * Renderers are responsible for escaping — values stored here are verbatim
 * user input.
 */
final class Submission
{
    /** @param array<string, mixed> $fields */
    public function __construct(
        public readonly int $id,
        public readonly int $form_id,
        public readonly \DateTimeImmutable $submitted_at,
        public readonly string $status,
        public readonly string $ip_hash,
        public readonly ?string $ip_address,
        public readonly string $user_agent,
        public readonly string $referer,
        public readonly array $fields,
        public readonly ?int $log_id,
        public readonly ?string $notes,
        public readonly string $captcha_slug,
        public readonly string $captcha_outcome,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function from_row(array $row): self
    {
        $fields = [];
        if (isset($row['fields_json']) && is_string($row['fields_json'])) {
            $decoded = json_decode($row['fields_json'], true);
            if (is_array($decoded)) {
                $fields = $decoded;
            }
        }
        try {
            $submitted_at = new \DateTimeImmutable(
                (string) ($row['submitted_at'] ?? 'now'),
                new \DateTimeZone('UTC')
            );
        } catch (\Exception) {
            $submitted_at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        $ip_address = null;
        if (isset($row['ip_address']) && is_string($row['ip_address']) && $row['ip_address'] !== '') {
            $ip_address = $row['ip_address'];
        }
        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['form_id'] ?? 0),
            $submitted_at,
            (string) ($row['status'] ?? ''),
            (string) ($row['ip_hash'] ?? ''),
            $ip_address,
            (string) ($row['user_agent'] ?? ''),
            (string) ($row['referer'] ?? ''),
            $fields,
            isset($row['log_id']) && $row['log_id'] !== null ? (int) $row['log_id'] : null,
            isset($row['notes']) && $row['notes'] !== null ? (string) $row['notes'] : null,
            (string) ($row['captcha_slug'] ?? ''),
            (string) ($row['captcha_outcome'] ?? ''),
        );
    }
}
