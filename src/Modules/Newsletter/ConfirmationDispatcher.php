<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Sends the double-opt-in confirmation email for a freshly-created
 * (or re-pending) subscriber row. Walks: pick the form's confirmation
 * template (per-form override → resolved default), generate confirm +
 * refuse tokens, render via TemplateRenderer, post to wp_mail.
 *
 * On send-failure we still treat the submission as a success for the
 * visitor — the subscriber row exists, the admin can re-trigger the
 * email manually later (UI for that lands with the reminder cron).
 * Logging the failure is the SMTP module's job.
 *
 * Subject line is the template post's title — admin can rename to
 * customise. From-name + reply-to come from the SMTP module's
 * resolved identity for the newsletter context. CSS inlining happens
 * inside TemplateRenderer (stub today; real inliner with send pipeline).
 */
final class ConfirmationDispatcher
{
    public function send(int $subscriber_id, string $email, string $name, int $form_id): bool
    {
        $template_id = $this->resolve_template_id($form_id);
        if ($template_id <= 0) {
            // No template available — can't send a meaningful email.
            // Subscriber row still exists; admin will see the issue in
            // the Onboarding view's validation status.
            return false;
        }

        $confirm_token = ConfirmationTokens::generate($subscriber_id, ConfirmationTokens::ACTION_CONFIRM);
        $refuse_token  = ConfirmationTokens::generate($subscriber_id, ConfirmationTokens::ACTION_REFUSE);
        $confirm_url   = add_query_arg('lrob-etk-nl-confirm', $confirm_token, home_url('/'));
        $refuse_url    = add_query_arg('lrob-etk-nl-refuse',  $refuse_token,  home_url('/'));

        $tokens = [
            'confirm_url' => $confirm_url,
            'refuse_url'  => $refuse_url,
            'prefs_url'   => '',
            'name'        => $name !== '' ? $name : $email,
            'first_name'  => self::first_name($name),
            'email'       => $email,
            'site_name'   => (string) get_bloginfo('name'),
            'site_url'    => (string) home_url('/'),
        ];

        $body_html = TemplateRenderer::render($template_id, $tokens);
        if ($body_html === '') {
            return false;
        }

        $template_post = get_post($template_id);
        $subject = $template_post instanceof \WP_Post && $template_post->post_title !== ''
            ? $template_post->post_title
            : sprintf(
                /* translators: %s: site name. */
                __('Confirm your subscription to %s', 'lrob-email-toolkit'),
                (string) get_bloginfo('name')
            );

        // wp_mail honours SMTP module routing via the `lrob_etk_email_sending`
        // filter chain — no special handling here. Header marks this as a
        // newsletter message so the Logging module's filter can suppress
        // success rows (only failures hit the global log by default).
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'X-Lrob-Etk-Newsletter-Confirmation: 1',
        ];

        return wp_mail($email, $subject, $body_html, $headers);
    }

    /**
     * Pick the form's confirmation template:
     *   1. Per-form META_CONFIRMATION_TEMPLATE_ID if set.
     *   2. Default template for the `confirmation` purpose.
     *   3. 0 if no template exists at all (caller bails).
     */
    private function resolve_template_id(int $form_id): int
    {
        $per_form = (int) get_post_meta($form_id, FormCPT::META_CONFIRMATION_TEMPLATE_ID, true);
        if ($per_form > 0 && get_post_type($per_form) === TemplateCPT::POST_TYPE) {
            return $per_form;
        }
        return (new TemplateRepository())->default_id_for_purpose(TemplateCPT::PURPOSE_CONFIRMATION);
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
