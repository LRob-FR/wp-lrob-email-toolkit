<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

use LRob\EmailToolkit\Support\Encryption;

/**
 * Applies wp-config.php constant overrides to the *default* identity at
 * runtime. Lets ops teams keep SMTP secrets out of the database while still
 * editing the rest of the identity in the admin UI.
 *
 * Currently only the default identity is overridable. The constants are not
 * per-slug — adding LROB_ETK_SMTP_HOST_NEWSLETTER and friends is straightforward
 * later if needed.
 *
 * Constants honored:
 *   LROB_ETK_SMTP_HOST          (string)
 *   LROB_ETK_SMTP_PORT          (int)
 *   LROB_ETK_SMTP_ENCRYPTION    (string: '' | 'ssl' | 'tls')
 *   LROB_ETK_SMTP_USER          (string)
 *   LROB_ETK_SMTP_PASS          (string)  ← stored re-encrypted in memory only
 *   LROB_ETK_SMTP_AUTH          (bool/int)
 *   LROB_ETK_SMTP_FROM          (string, email)
 *   LROB_ETK_SMTP_FROM_NAME     (string)
 */
final class ConstantOverrides
{
    /**
     * Map of constant name → Identity field name. Order is preserved.
     *
     * @var array<string, string>
     */
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

    /**
     * Return a new Identity with overridable fields replaced by their wp-config
     * constant value, where defined. Non-default identities are returned
     * unchanged. The original DB-loaded ciphertext is preserved on the input
     * Identity — this returns a separate clone for use at send time only.
     */
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

    /**
     * Field names that are currently being overridden by a wp-config constant.
     * The admin UI uses this list to show a lock notice next to those fields
     * (the DB value is still editable, but ignored at runtime).
     *
     * @return array<int, string>
     */
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
            'smtp_auth', 'force_from' => (bool) $value,
            'smtp_password_encrypted' => Encryption::encrypt((string) $value),
            default                   => (string) $value,
        };
    }
}
