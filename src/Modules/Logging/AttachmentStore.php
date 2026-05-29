<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging;

// Docs: docs/logging.md
final class AttachmentStore
{
    public const ROOT_DIR = 'lrob-etk-logs';

    /**
     * Copy an existing file into the store. Returns the absolute path of the
     * copy, or null on any failure (caller falls back to the source path).
     */
    public static function persist(string $source_abs, string $original_name): ?string
    {
        if (!is_file($source_abs) || !is_readable($source_abs)) {
            return null;
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return null;
        }
        $base_dir = self::root_dir();
        if (!wp_mkdir_p($base_dir)) {
            return null;
        }
        self::ensure_htaccess($base_dir);
        self::ensure_blank_index($base_dir);

        $now   = current_time('mysql');
        $year  = substr($now, 0, 4);
        $month = substr($now, 5, 2);
        $dir   = $base_dir . '/' . $year . '/' . $month;
        if (!wp_mkdir_p($dir)) {
            return null;
        }

        $safe_name = sanitize_file_name($original_name);
        if ($safe_name === '' || $safe_name === '.' || $safe_name === '..') {
            $safe_name = 'attachment';
        }
        $final = $dir . '/' . bin2hex(random_bytes(4)) . '_' . $safe_name;

        if (!@copy($source_abs, $final)) {
            return null;
        }
        @chmod($final, 0640);

        return $final;
    }

    /** Absolute path of the store root (uploads/lrob-etk-logs). */
    public static function root_dir(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit((string) $uploads['basedir']) . self::ROOT_DIR;
    }

    /** True when an absolute path lives inside the store — i.e. a file we own. */
    public static function is_managed(string $abs_path): bool
    {
        if ($abs_path === '') {
            return false;
        }
        $root = self::root_dir();
        return str_starts_with($abs_path, $root . '/') || str_starts_with($abs_path, $root . DIRECTORY_SEPARATOR);
    }

    /** Delete a stored file — no-op unless the path is one we manage. */
    public static function delete(string $abs_path): void
    {
        if (self::is_managed($abs_path) && is_file($abs_path)) {
            @unlink($abs_path);
        }
    }

    private static function ensure_htaccess(string $base_dir): void
    {
        $path = $base_dir . '/.htaccess';
        if (is_file($path)) {
            return;
        }
        $contents = "# LRob Email Toolkit — saved outbound email attachments.\n"
            . "# Direct HTTP access is denied; files are only re-attached server-side on resend.\n"
            . "Options -Indexes\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order allow,deny\n"
            . "    Deny from all\n"
            . "</IfModule>\n"
            . "<IfModule mod_php.c>\n"
            . "    php_flag engine off\n"
            . "</IfModule>\n"
            . "<IfModule mod_php7.c>\n"
            . "    php_flag engine off\n"
            . "</IfModule>\n"
            . "<IfModule mod_php8.c>\n"
            . "    php_flag engine off\n"
            . "</IfModule>\n";
        @file_put_contents($path, $contents);
    }

    private static function ensure_blank_index(string $dir): void
    {
        $path = $dir . '/index.html';
        if (!is_file($path)) {
            @file_put_contents($path, '');
        }
    }
}
