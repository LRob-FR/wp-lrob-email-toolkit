<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Token registry for system email templates. Templates author with
 * `{{token_name}}` placeholders; TemplateRenderer substitutes at send
 * time. The list per purpose drives:
 *
 *   - the validator (confirmation templates MUST contain
 *     {{confirm_url}} + {{refuse_url}});
 *   - the Gutenberg editor's "available tokens" hint (rendered by a
 *     future sidebar panel — for now the docs surface them);
 *   - the substitution call itself (only registered tokens are
 *     replaced; unknown ones survive as literal text so the admin can
 *     see what was typed wrong).
 *
 * Substituted values are escaped via esc_html before insertion to
 * defang any HTML in subscriber names or category labels. URL tokens
 * carry full URLs and pass through esc_url instead.
 */
final class TemplateTokens
{
    /** Always-available tokens, regardless of template purpose. */
    private const COMMON_TOKENS = [
        'prefs_url',
        'first_name',
        'name',
        'email',
        'site_name',
        'site_url',
    ];

    /** Extra tokens that only make sense for specific template purposes. */
    private const PURPOSE_SPECIFIC_TOKENS = [
        TemplateCPT::PURPOSE_CONFIRMATION => ['confirm_url', 'refuse_url'],
        TemplateCPT::PURPOSE_REMINDER     => ['confirm_url', 'refuse_url'],
        TemplateCPT::PURPOSE_REFUSE_ACK   => [],
    ];

    /** @return array<int, string> The full token list available to templates of $purpose. */
    public static function available_tokens(string $purpose): array
    {
        $extra = self::PURPOSE_SPECIFIC_TOKENS[$purpose] ?? [];
        return array_values(array_unique(array_merge(self::COMMON_TOKENS, $extra)));
    }

    /**
     * Required tokens for the purpose — validator uses these to flag
     * incomplete templates. Confirmation/reminder templates lose all
     * meaning without confirm/refuse buttons.
     *
     * @return array<int, string>
     */
    public static function required_tokens(string $purpose): array
    {
        return match ($purpose) {
            TemplateCPT::PURPOSE_CONFIRMATION,
            TemplateCPT::PURPOSE_REMINDER => ['confirm_url', 'refuse_url'],
            default                       => [],
        };
    }

    /**
     * Substitute every registered token in $html with the value supplied
     * in $values. URL tokens (anything ending in _url) pass through
     * esc_url; text tokens through esc_html. Missing values silently
     * become empty string — leaving the literal `{{token}}` in place
     * would surface "you forgot to provide this" to the recipient,
     * which is worse than a missing word.
     *
     * @param array<string, string> $values
     */
    public static function substitute(string $html, array $values): string
    {
        if ($html === '') {
            return $html;
        }
        $search = [];
        $replace = [];
        foreach (self::all_known_tokens() as $token) {
            $raw = (string) ($values[$token] ?? '');
            $escaped = str_ends_with($token, '_url')
                ? esc_url($raw)
                : esc_html($raw);
            $search[] = '{{' . $token . '}}';
            $replace[] = $escaped;
        }
        return str_replace($search, $replace, $html);
    }

    /**
     * Quick check for whether a template's content contains ALL required
     * tokens for its purpose. Substring match — the literal `{{token}}`
     * must appear at least once. Doesn't validate URL well-formedness;
     * that's the renderer's job at send time.
     *
     * @return array<int, string> Missing token names. Empty array = all present.
     */
    public static function missing_required(string $content, string $purpose): array
    {
        $missing = [];
        foreach (self::required_tokens($purpose) as $token) {
            if (!str_contains($content, '{{' . $token . '}}')) {
                $missing[] = $token;
            }
        }
        return $missing;
    }

    /** Union of every token name we know about — used to drive the substitute() loop. */
    private static function all_known_tokens(): array
    {
        $all = self::COMMON_TOKENS;
        foreach (self::PURPOSE_SPECIFIC_TOKENS as $extra) {
            $all = array_merge($all, $extra);
        }
        return array_values(array_unique($all));
    }
}
