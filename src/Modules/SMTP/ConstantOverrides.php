<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

use LRob\EmailToolkit\Support\Encryption;

// Docs: docs/smtp.md
final class ConstantOverrides
{
    /** @var array<string, string> */
    private const MAP = [
        'LROB_ETK_SMTP_HOST'       => 'smtp_host',
        'LROB_ETK_SMTP_PORT'       => 'smtp_port',
        'LROB_ETK_SMTP_ENCRYPTION' => 'smtp_encryption',
        'LROB_ETK_SMTP_USER'       => 'smtp_username',
        'LROB_ETK_SMTP_PASS'       => 'smtp_password_encrypted',
        'LROB_ETK_SMTP_AUTH'       => 'smtp_auth',
        'LROB_ETK_SMTP_FROM'       => 'from_email',
        'LROB_ETK_SMTP_FROM_NAME'  => 'from_name',
    ];

    /** Non-default identities are returned unchanged. Returns a with() clone; DB row is untouched. */
    public function apply(Identity $identity): Identity
    {
        if (!$identity->is_default) {
            return $identity;
        }

        $changes = [];
        foreach (self::MAP as $constant => $field) {
            if (!defined($constant)) {
                continue;
            }
            $changes[$field] = $this->coerce($field, constant($constant));
        }

        return $changes === [] ? $identity : $identity->with($changes);
    }

    /** @return array<int, string> */
    public function overridden_fields(): array
    {
        $fields = [];
        foreach (self::MAP as $constant => $field) {
            if (defined($constant)) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /** Is this specific field overridden? */
    public function is_overridden(string $field): bool
    {
        foreach (self::MAP as $constant => $mapped) {
            if ($mapped === $field && defined($constant)) {
                return true;
            }
        }
        return false;
    }

    private function coerce(string $field, mixed $value): mixed
    {
        return match ($field) {
            'smtp_port'               => (int) $value,
            'smtp_auth'               => (bool) $value,
            'override_mode'           => Identity::normalize_override_mode($value),
            'smtp_password_encrypted' => Encryption::encrypt((string) $value),
            default                   => (string) $value,
        };
    }
}
