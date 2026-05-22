<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Modules\Newsletter\Send\EmailLayout;

/**
 * Renders an email-template post to ready-to-send HTML. Pipeline:
 *
 *   1. Load the template post; bail (empty string) if missing or wrong type.
 *   2. Run `do_blocks()` on `post_content` so Gutenberg block markup
 *      becomes plain HTML.
 *   3. TemplateTokens::substitute() replaces every `{{token}}` with the
 *      caller-supplied value (escaped at substitution time).
 *   4. EmailLayout::apply() inlines Gutenberg's alignment classes +
 *      wraps the body in a centered max-width container. The full
 *      CSS-to-inline-style transformer (Outlook/Gmail compatibility)
 *      lands as step 7b.
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
        $title = $post->post_title !== '' ? $post->post_title : (string) get_bloginfo('name');
        return EmailLayout::apply($html, $title);
    }
}
