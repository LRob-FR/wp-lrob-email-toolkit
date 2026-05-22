<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Send;

use LRob\EmailToolkit\Modules\Newsletter\CampaignCPT;
use LRob\EmailToolkit\Modules\Newsletter\PrefsHandler;

/**
 * Render a campaign CPT post to per-recipient HTML. Mirrors
 * TemplateRenderer's pipeline (do_blocks → token substitute) but with
 * the campaign-specific token list (no confirm/refuse — those are
 * onboarding-only).
 *
 * Tokens substituted per recipient at send time. Personalisation
 * URLs (prefs / unsub) carry the recipient's opaque prefs_token so
 * the link works without the recipient logging in.
 *
 * CSS inliner stub for now — the proper inliner lands as step 7b.
 * Most modern email clients tolerate non-inlined CSS in `<style>`
 * tags better than they used to, and Gutenberg's block markup
 * already inlines common styles.
 */
final class CampaignRenderer
{
    /**
     * Render the campaign body. Caller passes the resolved tokens
     * map (everything per-recipient: name, prefs_url, etc.) — this
     * renderer just substitutes.
     *
     * @param array<string, string> $tokens
     */
    public static function render(int $campaign_id, array $tokens): string
    {
        $post = get_post($campaign_id);
        if (!$post instanceof \WP_Post || $post->post_type !== CampaignCPT::POST_TYPE) {
            return '';
        }

        $html = do_blocks($post->post_content);
        $html = self::substitute($html, $tokens);
        return $html;
    }

    /**
     * Build the per-recipient tokens map. Caller supplies recipient
     * identity + prefs_token; we wrap them in the campaign-token
     * vocabulary.
     *
     * @return array<string, string>
     */
    public static function tokens_for_recipient(string $email, string $name, string $prefs_token): array
    {
        $prefs_url = '';
        $unsub_url = '';
        if ($prefs_token !== '') {
            $prefs_url = add_query_arg(PrefsHandler::QUERY_PREFS, $prefs_token, home_url('/'));
            $unsub_url = add_query_arg(PrefsHandler::QUERY_UNSUB, $prefs_token, home_url('/'));
        }
        return [
            'email'      => $email,
            'name'       => $name !== '' ? $name : $email,
            'first_name' => self::first_name($name),
            'prefs_url'  => $prefs_url,
            'unsub_url'  => $unsub_url,
            'site_name'  => (string) get_bloginfo('name'),
            'site_url'   => (string) home_url('/'),
        ];
    }

    /** Available campaign tokens — used by docs / future editor sidebar. */
    public static function available_tokens(): array
    {
        return [
            'email',
            'name',
            'first_name',
            'prefs_url',
            'unsub_url',
            'site_name',
            'site_url',
        ];
    }

    /**
     * Substitute every supported token in $html with the supplied
     * values. URL tokens pass through esc_url; text tokens through
     * esc_html — same escaping policy as TemplateTokens. Missing
     * values become empty string.
     *
     * @param array<string, string> $values
     */
    private static function substitute(string $html, array $values): string
    {
        if ($html === '') {
            return '';
        }
        $search = [];
        $replace = [];
        foreach (self::available_tokens() as $token) {
            $raw = (string) ($values[$token] ?? '');
            $escaped = str_ends_with($token, '_url')
                ? esc_url($raw)
                : esc_html($raw);
            $search[] = '{{' . $token . '}}';
            $replace[] = $escaped;
        }
        return str_replace($search, $replace, $html);
    }

    private static function first_name(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/', $name, 2);
        return is_array($parts) ? (string) $parts[0] : $name;
    }
}
