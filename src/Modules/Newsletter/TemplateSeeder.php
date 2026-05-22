<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Auto-seed default system-email templates on install. Idempotent — if a
 * template already exists for a given purpose (regardless of who created
 * it), the seeder skips that purpose. Means: deleting a default and
 * re-enabling the module won't clobber the empty state; you'd have to
 * manually create one or call the seeder explicitly.
 *
 * Each default's post_content is plain Gutenberg block markup with the
 * `{{token}}` placeholders baked in. Authoring the markup as serialised
 * blocks (not parse_blocks-generated arrays) keeps install-time work
 * tiny and round-trips cleanly into the editor.
 */
final class TemplateSeeder
{
    public static function seed_defaults(): void
    {
        foreach (TemplateCPT::purposes() as $purpose) {
            if (self::any_template_exists_for_purpose($purpose)) {
                continue;
            }
            self::create_default($purpose);
        }
    }

    private static function any_template_exists_for_purpose(string $purpose): bool
    {
        $found = get_posts([
            'post_type'        => TemplateCPT::POST_TYPE,
            'post_status'      => 'any',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'meta_key'         => TemplateCPT::META_PURPOSE,
            'meta_value'       => $purpose,
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        return !empty($found);
    }

    private static function create_default(string $purpose): void
    {
        $title = self::default_title($purpose);
        $content = self::default_content($purpose);

        $post_id = wp_insert_post([
            'post_type'    => TemplateCPT::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => $content,
            'meta_input'   => [
                TemplateCPT::META_PURPOSE    => $purpose,
                TemplateCPT::META_IS_DEFAULT => true,
            ],
        ], false);

        if (is_wp_error($post_id) || !is_int($post_id) || $post_id <= 0) {
            return;
        }
    }

    /** Public so the "new from default" action can reuse the same starter copy. */
    public static function default_title(string $purpose): string
    {
        return match ($purpose) {
            TemplateCPT::PURPOSE_CONFIRMATION => __('Default — Confirm your subscription', 'lrob-email-toolkit'),
            TemplateCPT::PURPOSE_REMINDER     => __('Default — Reminder: confirm your subscription', 'lrob-email-toolkit'),
            TemplateCPT::PURPOSE_REFUSE_ACK   => __('Default — Subscription declined', 'lrob-email-toolkit'),
            default                           => __('Default email template', 'lrob-email-toolkit'),
        };
    }

    /**
     * Block markup for each purpose. Keep it short — admins WILL edit
     * these. The placeholders are the load-bearing parts; everything
     * around them is replaceable copy. Public so the "new from default"
     * action can pre-fill fresh drafts from the same source.
     */
    public static function default_content(string $purpose): string
    {
        return match ($purpose) {
            TemplateCPT::PURPOSE_CONFIRMATION => self::confirmation_blocks(),
            TemplateCPT::PURPOSE_REMINDER     => self::reminder_blocks(),
            TemplateCPT::PURPOSE_REFUSE_ACK   => self::refuse_ack_blocks(),
            default                           => '',
        };
    }

    private static function confirmation_blocks(): string
    {
        $greeting = __('Hi {{name}},', 'lrob-email-toolkit');
        $intro = __('Thanks for signing up to <strong>{{site_name}}</strong>. Please confirm your subscription using the button below.', 'lrob-email-toolkit');
        $confirm_label = __('Confirm subscription', 'lrob-email-toolkit');
        $refuse_label = __('No thanks — don\'t subscribe me', 'lrob-email-toolkit');
        $footer = __('Didn\'t sign up? You can safely ignore this email or click "No thanks" above and we\'ll never bother you again.', 'lrob-email-toolkit');

        return self::compose_block_markup([
            self::block_paragraph($greeting),
            self::block_paragraph($intro),
            self::block_buttons([
                ['url' => '{{confirm_url}}', 'label' => $confirm_label],
            ]),
            self::block_paragraph('<a href="{{refuse_url}}">' . esc_html($refuse_label) . '</a>'),
            self::block_separator(),
            self::block_paragraph($footer, true),
        ]);
    }

    private static function reminder_blocks(): string
    {
        $greeting = __('Hi {{name}},', 'lrob-email-toolkit');
        $intro = __('You started signing up to <strong>{{site_name}}</strong> but haven\'t confirmed yet. Want to finish?', 'lrob-email-toolkit');
        $confirm_label = __('Yes, confirm my subscription', 'lrob-email-toolkit');
        $refuse_label = __('No, I changed my mind', 'lrob-email-toolkit');
        $footer = __('If you ignore this email we won\'t send another reminder.', 'lrob-email-toolkit');

        return self::compose_block_markup([
            self::block_paragraph($greeting),
            self::block_paragraph($intro),
            self::block_buttons([
                ['url' => '{{confirm_url}}', 'label' => $confirm_label],
            ]),
            self::block_paragraph('<a href="{{refuse_url}}">' . esc_html($refuse_label) . '</a>'),
            self::block_separator(),
            self::block_paragraph($footer, true),
        ]);
    }

    private static function refuse_ack_blocks(): string
    {
        $greeting = __('Hi {{name}},', 'lrob-email-toolkit');
        $body = __('You\'ve declined to subscribe to <strong>{{site_name}}</strong>. We won\'t add you and we won\'t email you again about this. Sorry for the noise.', 'lrob-email-toolkit');
        $signature = __('— The {{site_name}} team', 'lrob-email-toolkit');

        return self::compose_block_markup([
            self::block_paragraph($greeting),
            self::block_paragraph($body),
            self::block_paragraph($signature),
        ]);
    }

    /** @param array<int, string> $blocks */
    private static function compose_block_markup(array $blocks): string
    {
        return implode("\n\n", $blocks);
    }

    private static function block_paragraph(string $html, bool $muted = false): string
    {
        // Serialised <!-- wp:paragraph --> block. The HTML inside is
        // preserved on round-trips because the block parser tolerates
        // raw HTML in core/paragraph.
        $attrs = $muted ? '{"fontSize":"small"}' : '';
        $opening = $attrs !== '' ? '<!-- wp:paragraph ' . $attrs . ' -->' : '<!-- wp:paragraph -->';
        $class = $muted ? ' class="has-small-font-size"' : '';
        return $opening . "\n<p" . $class . '>' . $html . "</p>\n<!-- /wp:paragraph -->";
    }

    /**
     * Build a core/buttons block. Button URLs are NOT escaped — seed
     * URLs are `{{token}}` placeholders that esc_url_raw would strip
     * the braces from (`{` and `}` aren't valid URL characters). Token
     * substitution + URL escaping happen at render time via
     * TemplateRenderer + TemplateTokens; raw seed values are
     * developer-controlled and safe to embed verbatim.
     *
     * @param array<int, array{url:string, label:string}> $buttons
     */
    private static function block_buttons(array $buttons): string
    {
        $inner = '';
        foreach ($buttons as $btn) {
            $inner .= "\n<!-- wp:button -->\n"
                . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="'
                . $btn['url'] . '">' . esc_html($btn['label']) . '</a></div>'
                . "\n<!-- /wp:button -->";
        }
        return "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">" . $inner . "\n</div>\n<!-- /wp:buttons -->";
    }

    private static function block_separator(): string
    {
        return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
    }
}
