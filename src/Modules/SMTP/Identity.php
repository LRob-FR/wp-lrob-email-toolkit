<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

use LRob\EmailToolkit\Support\Encryption;

/**
 * Immutable value object representing one SMTP identity (= one SMTP login
 * paired with one From address). Loaded from the database, applied to
 * PHPMailer at send time, displayed in the admin UI. Mutation goes through
 * `with()` clones; never modify a loaded identity in place.
 *
 * The password is stored as AES-256-GCM ciphertext in the database. Plain
 * text is only ever materialized on demand via decrypted_password().
 */
final class Identity
{
    public const ENCRYPTION_NONE = '';

    public const ENCRYPTION_SSL = 'ssl';

    public const ENCRYPTION_STARTTLS = 'tls';

    public const TRANSPORT_SMTP = 'smtp';

    public const TRANSPORT_MAIL = 'mail';

    public function __construct(
        public readonly ?int $id,
        public readonly string $slug,
        public readonly string $label,
        public readonly string $transport,
        public readonly string $from_email,
        public readonly string $from_name,
        public readonly string $smtp_host,
        public readonly int $smtp_port,
        public readonly string $smtp_encryption,
        public readonly string $smtp_username,
        public readonly string $smtp_password_encrypted,
        public readonly bool $smtp_auth,
        public readonly bool $force_from,
        public readonly ?string $reply_to_email,
        public readonly bool $is_default,
        public readonly bool $is_active,
        public readonly ?\DateTimeImmutable $created_at = null,
        public readonly ?\DateTimeImmutable $updated_at = null,
    ) {
    }

    /** True when this identity uses PHP's native mail() transport (no SMTP server). */
    public function uses_mail_transport(): bool
    {
        return $this->transport === self::TRANSPORT_MAIL;
    }

    /**
     * Build from a raw database row (array of strings as wpdb returns).
     *
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        $transport = (string) ($row['transport'] ?? self::TRANSPORT_SMTP);
        if (!in_array($transport, [self::TRANSPORT_SMTP, self::TRANSPORT_MAIL], true)) {
            $transport = self::TRANSPORT_SMTP;
        }
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            slug: (string) ($row['slug'] ?? ''),
            label: (string) ($row['label'] ?? ''),
            transport: $transport,
            from_email: (string) ($row['from_email'] ?? ''),
            from_name: (string) ($row['from_name'] ?? ''),
            smtp_host: (string) ($row['smtp_host'] ?? ''),
            smtp_port: (int) ($row['smtp_port'] ?? 587),
            smtp_encryption: (string) ($row['smtp_encryption'] ?? self::ENCRYPTION_STARTTLS),
            smtp_username: (string) ($row['smtp_username'] ?? ''),
            smtp_password_encrypted: (string) ($row['smtp_password_encrypted'] ?? ''),
            smtp_auth: !empty($row['smtp_auth']),
            force_from: !empty($row['force_from']),
            reply_to_email: isset($row['reply_to_email']) && $row['reply_to_email'] !== ''
                ? (string) $row['reply_to_email']
                : null,
            is_default: !empty($row['is_default']),
            is_active: !empty($row['is_active']),
            created_at: self::parse_datetime($row['created_at'] ?? null),
            updated_at: self::parse_datetime($row['updated_at'] ?? null),
        );
    }

    /**
     * Materialize the password in plaintext. Returns '' when no password is
     * stored. Throws RuntimeException if AUTH_KEY is missing or the
     * ciphertext is corrupted (e.g. AUTH_KEY changed since encryption).
     */
    public function decrypted_password(): string
    {
        if ($this->smtp_password_encrypted === '') {
            return '';
        }
        return Encryption::decrypt($this->smtp_password_encrypted);
    }

    /**
     * Resolves the From name to send. When the stored value is empty, fall
     * back to the site title at runtime so subsequent site renames flow
     * through without re-saving the identity.
     */
    public function effective_from_name(): string
    {
        if ($this->from_name !== '') {
            return $this->from_name;
        }
        if (function_exists('get_bloginfo')) {
            $title = (string) get_bloginfo('name');
            if ($title !== '') {
                return $title;
            }
        }
        return $this->effective_from_email();
    }

    /**
     * Resolves the From email. When the stored value is empty, fall back to
     * the SMTP username — letting users skip filling in two identical fields
     * for the common case where From email == mailbox login.
     */
    public function effective_from_email(): string
    {
        if ($this->from_email !== '') {
            return $this->from_email;
        }
        return $this->smtp_username;
    }

    /** True when from_name is empty (resolved to site title at runtime). */
    public function is_from_name_automatic(): bool
    {
        return $this->from_name === '';
    }

    /** True when from_email is empty (resolved to SMTP username at runtime). */
    public function is_from_email_automatic(): bool
    {
        return $this->from_email === '';
    }

    /**
     * Return a new Identity with one or more fields replaced. Used in place
     * of mutation since the object is readonly.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $merged = array_merge($this->to_array(), $changes);
        return new self(
            id: $merged['id'],
            slug: $merged['slug'],
            label: $merged['label'],
            transport: $merged['transport'],
            from_email: $merged['from_email'],
            from_name: $merged['from_name'],
            smtp_host: $merged['smtp_host'],
            smtp_port: $merged['smtp_port'],
            smtp_encryption: $merged['smtp_encryption'],
            smtp_username: $merged['smtp_username'],
            smtp_password_encrypted: $merged['smtp_password_encrypted'],
            smtp_auth: $merged['smtp_auth'],
            force_from: $merged['force_from'],
            reply_to_email: $merged['reply_to_email'],
            is_default: $merged['is_default'],
            is_active: $merged['is_active'],
            created_at: $merged['created_at'],
            updated_at: $merged['updated_at'],
        );
    }

    /** @return array<string, mixed> */
    public function to_array(): array
    {
        return [
            'id'                      => $this->id,
            'slug'                    => $this->slug,
            'label'                   => $this->label,
            'transport'               => $this->transport,
            'from_email'              => $this->from_email,
            'from_name'               => $this->from_name,
            'smtp_host'               => $this->smtp_host,
            'smtp_port'               => $this->smtp_port,
            'smtp_encryption'         => $this->smtp_encryption,
            'smtp_username'           => $this->smtp_username,
            'smtp_password_encrypted' => $this->smtp_password_encrypted,
            'smtp_auth'               => $this->smtp_auth,
            'force_from'              => $this->force_from,
            'reply_to_email'          => $this->reply_to_email,
            'is_default'              => $this->is_default,
            'is_active'               => $this->is_active,
            'created_at'              => $this->created_at,
            'updated_at'              => $this->updated_at,
        ];
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
