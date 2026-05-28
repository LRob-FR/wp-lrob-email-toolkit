<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

use LRob\EmailToolkit\Support\Encryption;

/**
 * Immutable value object: one configured set of credentials for a hosted
 * captcha provider (hCaptcha "Main site", Turnstile "Staging", …). Mirrors
 * the SMTP Identity shape so an admin who knows one knows the other.
 *
 * Credentials are stored as AES-256-GCM-encrypted JSON. The provider knows
 * what keys to expect — this class just holds the blob.
 */
final class Identity
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $provider_slug,
        public readonly string $label,
        public readonly string $credentials_encrypted,
        public readonly bool $is_active,
        public readonly string $theme = Appearance::THEME_AUTO,
        public readonly string $size = Appearance::SIZE_NORMAL,
        public readonly ?\DateTimeImmutable $created_at = null,
        public readonly ?\DateTimeImmutable $updated_at = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            provider_slug: (string) ($row['provider_slug'] ?? ''),
            label: (string) ($row['label'] ?? ''),
            credentials_encrypted: (string) ($row['credentials_encrypted'] ?? ''),
            is_active: !empty($row['is_active']),
            theme: Appearance::normalize_theme((string) ($row['theme'] ?? '')),
            size: Appearance::normalize_size((string) ($row['size'] ?? '')),
            created_at: self::parse_datetime($row['created_at'] ?? null),
            updated_at: self::parse_datetime($row['updated_at'] ?? null),
        );
    }

    /**
     * Human-readable stable identifier for this row, matching the Contact
     * Form field pattern (`<type>_<sluggified-label>_<nth>`): here it's
     * `<provider_slug>_<label-slug>_<id>`. Empty for unsaved rows since the
     * id provides the final uniqueness component.
     */
    public function derived_slug(): string
    {
        if ($this->id === null) {
            return '';
        }
        $label_slug = strtolower($this->label);
        $label_slug = (string) preg_replace('/[^a-z0-9]+/', '-', $label_slug);
        $label_slug = trim($label_slug, '-');
        if ($label_slug === '') {
            $label_slug = 'untitled';
        }
        return $this->provider_slug . '_' . $label_slug . '_' . $this->id;
    }

    /**
     * Decrypt and JSON-decode the credentials blob. Returns [] when no
     * credentials are stored. Throws RuntimeException when AUTH_KEY changed
     * or the ciphertext is otherwise unreadable — callers should catch and
     * prompt the admin to re-enter credentials.
     *
     * @return array<string, string>
     */
    public function decrypted_credentials(): array
    {
        if ($this->credentials_encrypted === '') {
            return [];
        }
        $plain = Encryption::decrypt($this->credentials_encrypted);
        $decoded = json_decode($plain, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $out[$key] = is_scalar($value) ? (string) $value : '';
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $merged = array_merge($this->to_array(), $changes);
        return new self(
            id: $merged['id'],
            provider_slug: $merged['provider_slug'],
            label: $merged['label'],
            credentials_encrypted: $merged['credentials_encrypted'],
            is_active: $merged['is_active'],
            theme: $merged['theme'],
            size: $merged['size'],
            created_at: $merged['created_at'],
            updated_at: $merged['updated_at'],
        );
    }

    /** @return array<string, mixed> */
    public function to_array(): array
    {
        return [
            'id'                    => $this->id,
            'provider_slug'         => $this->provider_slug,
            'label'                 => $this->label,
            'credentials_encrypted' => $this->credentials_encrypted,
            'is_active'             => $this->is_active,
            'theme'                 => $this->theme,
            'size'                  => $this->size,
            'created_at'            => $this->created_at,
            'updated_at'            => $this->updated_at,
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
