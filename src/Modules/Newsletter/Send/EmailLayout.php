<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Send;

/**
 * Minimum-viable email-layout helpers — applied to every rendered
 * campaign + system email body before it goes out the wire. Two
 * passes:
 *
 *   1. `inline_alignment_classes()` — Gutenberg ships layout via
 *      CSS classes (`has-text-align-center`, `has-text-align-right`,
 *      `has-text-align-left`). Email clients don't load CSS files,
 *      so the class-driven alignment is invisible. Rewrite each
 *      occurrence into an inline `style="text-align:…"` (merging
 *      with any existing style attribute) so the alignment survives.
 *
 *   2. `wrap_email_skeleton()` — wrap the body in a centered
 *      max-width container with a neutral background, so a long
 *      campaign doesn't render edge-to-edge on big webmail viewports
 *      and a short one doesn't float in the top-left corner. Uses
 *      inline styles only — no `<style>` block needed (and no CSS
 *      inliner to teach about it).
 *
 * The proper CSS-to-inline-style transformer is step 7b; this file is
 * the small 80%-fix that keeps the rendered emails looking right
 * until the inliner lands.
 */
final class EmailLayout
{
    public const CONTENT_MAX_WIDTH_PX = 600;

    /**
     * Apply the full layout pass to a campaign / template body.
     * Caller passes already-substituted HTML (post-token-replace).
     */
    public static function apply(string $body_html, string $title): string
    {
        $inlined = self::inline_alignment_classes($body_html);
        return self::wrap_email_skeleton($inlined, $title);
    }

    /**
     * Walk every `has-text-align-{center,right,left}` class on any
     * element and append the matching inline `text-align` style. Done
     * with regex because parsing HTML with DOMDocument loses
     * whitespace and rewrites valid markup; a focused regex is
     * narrower and safer.
     */
    public static function inline_alignment_classes(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $map = [
            'has-text-align-center' => 'text-align:center;',
            'has-text-align-right'  => 'text-align:right;',
            'has-text-align-left'   => 'text-align:left;',
        ];

        foreach ($map as $class => $style_decl) {
            // Match any tag whose class attribute contains the alignment
            // class. Then either merge into an existing style attribute
            // on the same tag or append a fresh one. Done in two passes
            // to keep each regex simple.
            $pattern = '/(<[a-z0-9]+\b[^>]*class\s*=\s*["\'][^"\']*\b' . preg_quote($class, '/') . '\b[^"\']*["\'][^>]*)>/i';
            $html = (string) preg_replace_callback(
                $pattern,
                static function (array $m) use ($style_decl) {
                    $tag = $m[1];
                    if (preg_match('/style\s*=\s*(["\'])([^"\']*)\1/i', $tag)) {
                        // Merge: only inject if the declaration isn't
                        // already there (admin may have set it
                        // explicitly via Gutenberg's style controls).
                        return (string) preg_replace_callback(
                            '/style\s*=\s*(["\'])([^"\']*)\1/i',
                            static function (array $sm) use ($style_decl) {
                                $existing = trim($sm[2]);
                                if (stripos($existing, 'text-align') !== false) {
                                    return $sm[0];
                                }
                                $merged = $existing === '' ? $style_decl : rtrim($existing, '; ') . '; ' . $style_decl;
                                return 'style=' . $sm[1] . $merged . $sm[1];
                            },
                            $tag
                        ) . '>';
                    }
                    return $tag . ' style="' . $style_decl . '">';
                },
                $html
            );
        }

        return $html;
    }

    /**
     * Wrap the (already inlined) body in an email-safe skeleton:
     * full-doc HTML + table-based centering (the only cross-client
     * centering primitive Outlook on Windows actually honours) + a
     * max-width inner container.
     */
    public static function wrap_email_skeleton(string $body_html, string $title): string
    {
        $safe_title = esc_html($title);
        $max = (int) self::CONTENT_MAX_WIDTH_PX;
        return '<!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . $safe_title . '</title>
</head>
<body style="margin:0; padding:0; background:#f5f6f7; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif; color:#1d2327; line-height:1.5; overflow-x:hidden;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f6f7; padding:24px 12px; box-sizing:border-box;">
  <tr><td align="center">
    <table role="presentation" width="' . $max . '" cellpadding="0" cellspacing="0" border="0" style="max-width:' . $max . 'px; width:100%; background:#ffffff; border-radius:8px; padding:32px 28px; text-align:left;">
      <tr><td>' . $body_html . '</td></tr>
    </table>
  </td></tr>
</table>
</body></html>';
    }
}
