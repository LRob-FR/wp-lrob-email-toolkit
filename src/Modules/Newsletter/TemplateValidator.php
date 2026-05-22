<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Lightweight template sanity check, separate from save-time blocking:
 * we DON'T reject saves with missing tokens (Gutenberg's UX gets nasty
 * fast when post saves error out, and we'd risk losing the author's
 * work). Instead we stash a transient with the issues on save_post, and
 * surface them as an admin notice on the next page load — the author
 * sees the warning, can edit again to fix, and never loses content.
 *
 * The send-pipeline step will additionally hard-fail any campaign whose
 * confirmation/reminder template is incomplete. That's the safety net;
 * this class is the friendly preflight.
 */
final class TemplateValidator
{
    private const NOTICE_TRANSIENT_PREFIX = 'lrob_etk_nl_tpl_issues_';

    public function register(): void
    {
        add_action('save_post_' . TemplateCPT::POST_TYPE, [$this, 'on_save_post'], 20, 3);
        add_action('admin_notices', [$this, 'render_notice']);
    }

    /**
     * Inspect a freshly-saved template; stash any issues for the next
     * admin page load. Skipped during autosave/revisions so transient
     * spam doesn't accumulate.
     *
     * @param int      $post_id
     * @param \WP_Post $post
     * @param bool     $update
     */
    public function on_save_post(int $post_id, \WP_Post $post, bool $update): void
    {
        unset($update);
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $result = self::validate($post_id);
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return;
        }
        $key = self::NOTICE_TRANSIENT_PREFIX . $user_id . '_' . $post_id;
        if ($result['valid']) {
            delete_transient($key);
            return;
        }
        set_transient($key, $result['issues'], 60);
    }

    /**
     * Render any pending issues as a dismissible admin notice. Only
     * shown on the template's own edit screen so unrelated pages stay
     * clean.
     */
    public function render_notice(): void
    {
        global $post;
        if (!$post instanceof \WP_Post || $post->post_type !== TemplateCPT::POST_TYPE) {
            return;
        }
        $user_id = get_current_user_id();
        $key = self::NOTICE_TRANSIENT_PREFIX . $user_id . '_' . $post->ID;
        $issues = get_transient($key);
        if (!is_array($issues) || $issues === []) {
            return;
        }
        delete_transient($key);
        printf(
            '<div class="notice notice-warning is-dismissible"><p><strong>%s</strong></p><ul style="list-style: disc inside; margin: 0.5em 0;">',
            esc_html__('This email template has issues that will prevent it from being used:', 'lrob-email-toolkit')
        );
        foreach ($issues as $msg) {
            printf('<li>%s</li>', esc_html((string) $msg));
        }
        echo '</ul></div>';
    }

    /**
     * Pure check: load post, compute issues. Returned shape:
     *   ['valid' => bool, 'issues' => string[]]
     *
     * @return array{valid:bool, issues:array<int, string>}
     */
    public static function validate(int $post_id): array
    {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || $post->post_type !== TemplateCPT::POST_TYPE) {
            return ['valid' => false, 'issues' => [__('Template post not found.', 'lrob-email-toolkit')]];
        }
        $purpose = (string) get_post_meta($post_id, TemplateCPT::META_PURPOSE, true);
        if ($purpose === '') {
            return ['valid' => false, 'issues' => [__('Template purpose is not set.', 'lrob-email-toolkit')]];
        }
        if (!in_array($purpose, TemplateCPT::purposes(), true)) {
            return [
                'valid'  => false,
                'issues' => [sprintf(
                    /* translators: %s: purpose value stored in post meta. */
                    __('Unknown template purpose "%s".', 'lrob-email-toolkit'),
                    $purpose
                )],
            ];
        }

        $issues = [];
        $missing = TemplateTokens::missing_required($post->post_content, $purpose);
        foreach ($missing as $token) {
            $issues[] = sprintf(
                /* translators: %s: required token name, e.g. "{{confirm_url}}". */
                __('Missing required token: %s', 'lrob-email-toolkit'),
                '{{' . $token . '}}'
            );
        }

        return ['valid' => $issues === [], 'issues' => $issues];
    }
}
