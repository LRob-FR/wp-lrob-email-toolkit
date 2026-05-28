<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Support;

/**
 * Symmetric encryption for sensitive plugin data (SMTP passwords,
 * captcha-provider credentials, etc.). Uses AES-256-GCM with a key derived
 * from WordPress's AUTH_KEY via HKDF-SHA256.
 *
 * Output format (base64-encoded): version(1) || iv(12) || tag(16) || ciphertext
 *
 * Note: if AUTH_KEY changes (e.g. site moved + wp-config regenerated),
 * previously-encrypted values cannot be decrypted. Callers must handle the
 * resulting RuntimeException and prompt the user to re-enter the secret.
 */
final class Encryption
{
    private const CIPHER = 'aes-256-gcm';

    /** Format version. Bump if we ever change cipher or layout. */
    private const VERSION = "\x01";

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    public static function encrypt(string $plaintext): string
    {
        $key = self::derive_key();
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('lrob-etk: openssl_encrypt failed');
        }

        return base64_encode(self::VERSION . $iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        $min_length = 1 + self::IV_LENGTH + self::TAG_LENGTH;
        if ($raw === false || strlen($raw) < $min_length) {
            throw new \RuntimeException('lrob-etk: invalid ciphertext');
        }

        if ($raw[0] !== self::VERSION) {
            throw new \RuntimeException('lrob-etk: unsupported ciphertext version');
        }

        $iv = substr($raw, 1, self::IV_LENGTH);
        $tag = substr($raw, 1 + self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, $min_length);

        $key = self::derive_key();
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('lrob-etk: openssl_decrypt failed (wrong key or tampered data)');
        }

        return $plaintext;
    }

    public static function is_available(): bool
    {
        try {
            self::derive_key();
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private static function derive_key(): string
    {
        $material = defined('AUTH_KEY') ? (string) AUTH_KEY : '';
        if ($material === '' || str_contains($material, 'put your unique phrase here')) {
            throw new \RuntimeException(
                'lrob-etk: AUTH_KEY is not configured in wp-config.php; cannot encrypt sensitive data'
            );
        }

        return hash_hkdf('sha256', $material, 32, 'lrob_etk_v1', '');
    }
}
