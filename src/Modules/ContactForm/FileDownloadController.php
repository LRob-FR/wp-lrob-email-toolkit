<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use WP_REST_Request;

/**
 * Gated REST endpoint that streams uploaded files to authenticated admins.
 *
 *   GET /wp-json/lrob-etk/v1/cf/file/<file_id>
 *   GET /wp-json/lrob-etk/v1/cf/file/<file_id>?inline=1
 *   GET /wp-json/lrob-etk/v1/cf/file/<file_id>?w=200&h=200   (images only)
 *
 * Permission: `manage_lrob_etk`. No HMAC tokens needed — the cap check is
 * the access boundary. We don't expose any public-facing URL to the file's
 * storage path, and `.htaccess` (Apache) plus a `<Files>`-equivalent block
 * (nginx, documented) ensure the file can't be fetched directly.
 *
 * Content-Disposition is `inline` for previewable types (image/*,
 * application/pdf) so the admin's browser embeds them in iframes, and
 * `attachment` for everything else so the click-to-download UX is correct.
 */
final class FileDownloadController
{
    public const REST_NAMESPACE = 'lrob-etk/v1';
    public const REST_ROUTE     = '/cf/file/(?P<file_id>\d+)';

    public function __construct(private FileRepository $files)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
            'permission_callback' => static fn(): bool => current_user_can('manage_lrob_etk'),
            'args'                => [
                'file_id' => ['required' => true, 'type' => 'integer'],
                'inline'  => ['required' => false, 'type' => 'integer'],
                'w'       => ['required' => false, 'type' => 'integer'],
                'h'       => ['required' => false, 'type' => 'integer'],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): void
    {
        $file_id = (int) $request->get_param('file_id');
        $row = $this->files->find_by_id($file_id);
        if ($row === null) {
            self::send_status_and_exit(404, 'Not found');
        }
        $abs = FileStorage::absolute_path((string) $row['stored_path']);
        if (!is_file($abs)) {
            self::send_status_and_exit(404, 'File missing on disk');
        }

        $mime = (string) ($row['mime'] ?: 'application/octet-stream');
        $name = (string) $row['original_name'];

        // Image resize on demand for inbox thumbnails. Cheap, on-the-fly,
        // not cached — the inbox loads few images and admins are rare.
        $w = (int) $request->get_param('w');
        $h = (int) $request->get_param('h');
        if (($w > 0 || $h > 0) && self::is_resizable_image($mime)) {
            self::stream_resized_image($abs, $mime, $w, $h);
            exit;
        }

        $is_previewable = self::is_previewable($mime);
        $disposition = $is_previewable ? 'inline' : 'attachment';
        $size = (int) filesize($abs);

        // Private — admin-only content. Don't ever cache through proxies.
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Content-Disposition: ' . $disposition . '; filename="' . self::header_safe($name) . '"');
        header('X-Content-Type-Options: nosniff');
        // Defense-in-depth against rendering inside the admin frame chain.
        header('X-Frame-Options: SAMEORIGIN');

        // Flush WP's buffered output and stream — fpassthru handles large
        // files without holding them in memory.
        if (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $fh = @fopen($abs, 'rb');
        if ($fh === false) {
            self::send_status_and_exit(500, 'Cannot read file');
        }
        fpassthru($fh);
        fclose($fh);
        exit;
    }

    private static function is_previewable(string $mime): bool
    {
        return str_starts_with($mime, 'image/') || $mime === 'application/pdf';
    }

    private static function is_resizable_image(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    private static function stream_resized_image(string $abs, string $mime, int $target_w, int $target_h): void
    {
        if (!function_exists('imagecreatefromjpeg')) {
            // GD missing — bail, stream the original (admins see a full-size
            // image; their browser shrinks via CSS).
            header('Content-Type: ' . $mime);
            readfile($abs);
            return;
        }
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($abs),
            'image/png'  => @imagecreatefrompng($abs),
            'image/gif'  => @imagecreatefromgif($abs),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs) : false,
            default      => false,
        };
        if (!$src) {
            header('Content-Type: ' . $mime);
            readfile($abs);
            return;
        }
        $src_w = imagesx($src);
        $src_h = imagesy($src);
        if ($target_w <= 0) {
            $target_w = (int) round($src_w * ($target_h / $src_h));
        } elseif ($target_h <= 0) {
            $target_h = (int) round($src_h * ($target_w / $src_w));
        } else {
            // Letterbox fit — preserve aspect.
            $ratio = min($target_w / $src_w, $target_h / $src_h);
            $target_w = max(1, (int) round($src_w * $ratio));
            $target_h = max(1, (int) round($src_h * $ratio));
        }
        $dst = imagecreatetruecolor($target_w, $target_h);
        if ($mime === 'image/png' || $mime === 'image/webp' || $mime === 'image/gif') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $target_w, $target_h, $transparent);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $target_w, $target_h, $src_w, $src_h);

        if (ob_get_level() > 0) {
            @ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        switch ($mime) {
            case 'image/jpeg': imagejpeg($dst, null, 82); break;
            case 'image/png':  imagepng($dst); break;
            case 'image/gif':  imagegif($dst); break;
            case 'image/webp': imagewebp($dst, null, 82); break;
        }
        imagedestroy($src);
        imagedestroy($dst);
    }

    /** Strip CR/LF + double quotes from a filename before putting it in a Content-Disposition header. */
    private static function header_safe(string $name): string
    {
        $name = str_replace(["\r", "\n", '"'], '', $name);
        return $name !== '' ? $name : 'file';
    }

    private static function send_status_and_exit(int $code, string $body): void
    {
        status_header($code);
        nocache_headers();
        header('Content-Type: text/plain; charset=UTF-8');
        echo esc_html($body);
        exit;
    }
}
