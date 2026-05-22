<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Renders an email-template post to ready-to-send HTML. Pipeline:
 *
 *   1. Load the template post; bail (empty string) if missing or wrong type.
 *   2. Run `do_blocks()` on `post_content` so Gutenberg block markup
 *      becomes plain HTML.
 *   3. TemplateTokens::substitute() replaces every `{{token}}` with the
 *      caller-supplied value (escaped at substitution time).
 *   4. CSS inliner stub — leaves HTML unchanged for now. The real
 *      transformer lands with the send-pipeline step (campaigns share
 *      the same pipeline) and will convert <style> rules into inline
 *      `style="…"` attributes for Outlook/Gmail compatibility.
 *
 * Each step is a small static helper so the campaign renderer can pick
 * the same primitives once it lands.
 */
final class TemplateRenderer
{
    /** @param array<string, string> $tokens */
    public static function render(int $template_id, array $tokens): string
    {
        $post = get_post($template_id);
        if (!$post instanceof \WP_Post || $post->post_type !== TemplateCPT::POST_TYPE) {
            return '';
        }

        $html = do_blocks($post->post_content);
        $html = TemplateTokens::substitute($html, $tokens);
        $html = self::inline_css($html);

        return $html;
    }

    /**
     * CSS-to-inline-style transformer. Stub for now — returns input
     * unchanged. Will land alongside the send-pipeline implementation
     * since campaigns share this pass.
     */
    private static function inline_css(string $html): string
    {
        return $html;
    }
}
