<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms\Upload;

// Docs: docs/forms.md
final class UploadPolicy
{
    public const PRESET_IMAGES     = 'images';
    public const PRESET_DOCUMENTS  = 'documents';
    public const PRESET_PDF        = 'pdf';
    public const PRESET_VCARD      = 'vcard';
    public const PRESET_VIDEOS     = 'videos';
    public const PRESET_AUDIO      = 'audio';
    public const PRESET_ARCHIVES   = 'archives';
    public const PRESET_CUSTOM     = 'custom';

    public const DELIVERY_WEBSERVER  = 'webserver';
    public const DELIVERY_ATTACHMENT = 'attachment';
    public const DELIVERY_BOTH       = 'both';

    /**
     * Server-executable formats — always rejected, admin cannot override.
     *
     * @return list<string>
     */
    public static function tier1_extensions(): array
    {
        return [
            'php', 'phtml', 'phar', 'phps', 'pht',
            'asp', 'aspx', 'jsp', 'jspx',
            'cgi', 'pl', 'py', 'sh', 'bash',
            'hta', 'htaccess', 'htpasswd', 'htgroup',
        ];
    }

    /**
     * XSS-risk / client-executable formats — allowed when `allow_dangerous` is set.
     *
     * @return list<string>
     */
    public static function tier2_extensions(): array
    {
        return [
            'html', 'htm', 'xhtml', 'svg', 'js', 'xml',
            'mht', 'mhtml',
            'exe', 'bat', 'cmd', 'com', 'scr', 'dll', 'msi',
            'vbs', 'ps1', 'jar',
            'dmg', 'app',
        ];
    }

    /**
     * @return array<string, list<string>> preset slug → extension list
     */
    public static function presets(): array
    {
        return [
            self::PRESET_IMAGES    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'avif'],
            self::PRESET_DOCUMENTS => ['pdf', 'doc', 'docx', 'odt', 'txt', 'rtf', 'xlsx', 'xls', 'ods', 'csv'],
            self::PRESET_PDF       => ['pdf'],
            self::PRESET_VCARD     => ['vcf'],
            self::PRESET_VIDEOS    => ['mp4', 'webm', 'mov', 'mkv', 'avi', 'm4v'],
            self::PRESET_AUDIO     => ['mp3', 'wav', 'ogg', 'm4a', 'flac'],
            self::PRESET_ARCHIVES  => ['zip', '7z', 'rar', 'tar', 'gz', 'bz2'],
            self::PRESET_CUSTOM    => [],
        ];
    }

    /**
     * @return array<string, string> preset slug → translated label
     */
    public static function preset_labels(): array
    {
        return [
            self::PRESET_IMAGES    => __('Images', 'lrob-email-toolkit'),
            self::PRESET_DOCUMENTS => __('Documents & spreadsheets', 'lrob-email-toolkit'),
            self::PRESET_PDF       => __('PDF only', 'lrob-email-toolkit'),
            self::PRESET_VCARD     => __('vCard', 'lrob-email-toolkit'),
            self::PRESET_VIDEOS    => __('Videos', 'lrob-email-toolkit'),
            self::PRESET_AUDIO     => __('Audio', 'lrob-email-toolkit'),
            self::PRESET_ARCHIVES  => __('Archives', 'lrob-email-toolkit'),
            self::PRESET_CUSTOM    => __('Custom (advanced)', 'lrob-email-toolkit'),
        ];
    }

    /**
     * @return list<string> lowercase extensions, no dot prefix
     */
    public static function resolve_extensions(string $preset, string $custom_csv, bool $allow_dangerous): array
    {
        $preset = self::is_known_preset($preset) ? $preset : self::PRESET_CUSTOM;
        if ($preset === self::PRESET_CUSTOM) {
            $raw = preg_split('/[,;\s]+/', strtolower($custom_csv)) ?: [];
        } else {
            $raw = self::presets()[$preset] ?? [];
        }

        $tier1 = self::tier1_extensions();
        $tier2 = self::tier2_extensions();

        $out = [];
        foreach ($raw as $ext) {
            $ext = ltrim(strtolower(trim((string) $ext)), '.');
            if ($ext === '' || !preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
                continue;
            }
            if (in_array($ext, $tier1, true)) {
                continue;
            }
            if (in_array($ext, $tier2, true) && !$allow_dangerous) {
                continue;
            }
            if (!in_array($ext, $out, true)) {
                $out[] = $ext;
            }
        }
        return $out;
    }

    public static function is_known_preset(string $preset): bool
    {
        return array_key_exists($preset, self::presets());
    }

    public static function is_tier1(string $ext): bool
    {
        return in_array(strtolower(ltrim($ext, '.')), self::tier1_extensions(), true);
    }

    public static function is_tier2(string $ext): bool
    {
        return in_array(strtolower(ltrim($ext, '.')), self::tier2_extensions(), true);
    }

    public static function accept_attribute(array $exts): string
    {
        $out = [];
        foreach ($exts as $ext) {
            $ext = strtolower(ltrim((string) $ext, '.'));
            if ($ext !== '' && preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
                $out[] = '.' . $ext;
            }
        }
        return implode(',', $out);
    }

    public static function mime_hint(string $ext): string
    {
        $ext = strtolower(ltrim($ext, '.'));
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif',
            'avif' => 'image/avif',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'txt' => 'text/plain', 'rtf' => 'application/rtf',
            'csv' => 'text/csv',
            'vcf' => 'text/vcard',
            'mp4' => 'video/mp4', 'webm' => 'video/webm',
            'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo', 'm4v' => 'video/x-m4v',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
            'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'flac' => 'audio/flac',
            'zip' => 'application/zip',
            '7z'  => 'application/x-7z-compressed',
            'rar' => 'application/vnd.rar',
            'tar' => 'application/x-tar',
            'gz'  => 'application/gzip',
            'bz2' => 'application/x-bzip2',
        ];
        return $map[$ext] ?? '';
    }
}
