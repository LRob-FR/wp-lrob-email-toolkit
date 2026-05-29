<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use RuntimeException;

// Docs: docs/contact-form.md — path: lrob-etk-cf/<form-id>/<YYYY>/<MM>/<DD>/<hex8>_<sanitized>.<ext>
final class FileStorage
{
    public const ROOT_DIR = 'lrob-etk-cf';

    /**
     * Move an uploaded file (PHP `$_FILES['…']['tmp_name']`) into its final
     * storage location and return its metadata.
     *
     * @return array{relative_path:string, absolute_path:string, size:int, original_name:string}
     * @throws RuntimeException on write failure or path-traversal attempt.
     */
    public static function store_uploaded_file(string $tmp_path, string $original_name, int $form_id): array
    {
        if (!is_file($tmp_path) || !is_uploaded_file($tmp_path)) {
            throw new RuntimeException('Upload temp file missing or not from an HTTP upload.');
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            throw new RuntimeException('WordPress uploads dir error: ' . (string) $uploads['error']);
        }
        $base_dir = trailingslashit((string) $uploads['basedir']) . self::ROOT_DIR;

        if (!wp_mkdir_p($base_dir)) {
            throw new RuntimeException("Failed to create upload root: $base_dir");
        }
        self::ensure_htaccess($base_dir);
        self::ensure_blank_index($base_dir);

        // Per-form subdir + date-organised folder. wp_mkdir_p again is safe.
        $now = current_time('mysql');
        $year  = substr($now, 0, 4);
        $month = substr($now, 5, 2);
        $day   = substr($now, 8, 2);
        $form_dir = $base_dir . '/' . (int) $form_id . '/' . $year . '/' . $month . '/' . $day;
        if (!wp_mkdir_p($form_dir)) {
            throw new RuntimeException("Failed to create upload subdir: $form_dir");
        }
        self::ensure_blank_index($base_dir . '/' . (int) $form_id);

        $safe_name = sanitize_file_name($original_name);
        if ($safe_name === '' || $safe_name === '.' || $safe_name === '..') {
            $safe_name = 'upload';
        }
        $hex   = bin2hex(random_bytes(4)); // 8 chars
        $final = $form_dir . '/' . $hex . '_' . $safe_name;

        // Realpath sanity: ensure destination stays inside the upload root.
        $resolved_parent = realpath($form_dir);
        $resolved_root   = realpath($base_dir);
        if ($resolved_parent === false || $resolved_root === false
            || !str_starts_with($resolved_parent, $resolved_root . DIRECTORY_SEPARATOR)
            && $resolved_parent !== $resolved_root) {
            throw new RuntimeException("Upload path escaped the root: $final");
        }

        if (!@move_uploaded_file($tmp_path, $final)) {
            throw new RuntimeException("move_uploaded_file failed: $final");
        }

        // Tighten permissions — read for owner + group, no world.
        @chmod($final, 0640);

        $size = (int) filesize($final);
        $relative = self::ROOT_DIR . '/' . (int) $form_id . '/' . $year . '/' . $month . '/' . $day . '/' . $hex . '_' . $safe_name;

        return [
            'relative_path' => $relative,
            'absolute_path' => $final,
            'size'          => $size,
            'original_name' => $safe_name,
        ];
    }

    /** Resolve a stored relative path back to an absolute filesystem path. */
    public static function absolute_path(string $relative_path): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit((string) $uploads['basedir']) . ltrim($relative_path, '/');
    }

    /** Delete a stored file from disk. Silent on missing — caller decides if that's a problem. */
    public static function delete(string $relative_path): bool
    {
        $abs = self::absolute_path($relative_path);
        if (!is_file($abs)) {
            return false;
        }
        return @unlink($abs);
    }

    // Written once; skips existing so admins can hand-tune. Belt-and-suspenders against direct HTTP access.
    private static function ensure_htaccess(string $base_dir): void
    {
        $path = $base_dir . '/.htaccess';
        if (is_file($path)) {
            return;
        }
        $contents = "# LRob Email Toolkit — Contact Form file uploads.\n"
            . "# Direct HTTP access to these files is disabled — downloads go through\n"
            . "# the gated REST endpoint /wp-json/lrob-etk/v1/cf/file/<id>.\n"
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
