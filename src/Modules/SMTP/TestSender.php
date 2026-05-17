<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

/**
 * Sends a one-off test email through a specific identity so the admin can
 * verify configuration before going live. Goes through wp_mail() so the full
 * routing path is exercised (and the email is logged once Logging is on).
 *
 * Identity selection bypasses routing rules: the chosen identity is forced
 * via MailRouter::force_identity() so the test always reflects *that*
 * identity's config, not whatever the current routing rules would do.
 *
 * Capture pattern: the wp_mail_failed action is hooked at priority 1 just
 * for this call so we can return the actual WP_Error message, since wp_mail
 * itself only returns a boolean.
 */
final class TestSender
{
    public function __construct(
        private IdentityRepository $identities,
        private MailRouter $router,
    ) {
    }

    /**
     * @return array{success: bool, error: ?string, identity_slug: ?string}
     */
    public function send(int $identity_id, string $recipient): array
    {
        $identity = $this->identities->find($identity_id);
        if (!$identity instanceof Identity) {
            return ['success' => false, 'error' => 'Identity not found.', 'identity_slug' => null];
        }

        if (!is_email($recipient)) {
            return ['success' => false, 'error' => 'Recipient is not a valid email address.', 'identity_slug' => $identity->slug];
        }

        $captured_error = null;
        $capture = static function (\WP_Error $err) use (&$captured_error): void {
            $captured_error = $err;
        };
        add_action('wp_mail_failed', $capture, 1);

        $this->router->force_identity($identity->slug);

        $subject = sprintf(
            /* translators: %s: site name */
            __('[Email Toolkit] Test email from %s', 'lrob-email-toolkit'),
            get_bloginfo('name')
        );
        $body = $this->test_body($identity);

        $success = false;
        try {
            $success = SourceResolver::with(SourceResolver::SOURCE_DEFAULT, static fn (): bool =>
                wp_mail($recipient, $subject, $body, ['Content-Type: text/plain; charset=UTF-8'])
            );
        } finally {
            $this->router->force_identity(null);
            remove_action('wp_mail_failed', $capture, 1);
        }

        return [
            'success'       => $success,
            'error'         => $captured_error instanceof \WP_Error ? $captured_error->get_error_message() : null,
            'identity_slug' => $identity->slug,
        ];
    }

    private function test_body(Identity $identity): string
    {
        $lines = [
            __('This is a test email sent by LRob — Email Toolkit.', 'lrob-email-toolkit'),
            '',
            sprintf(
                /* translators: %s: identity label */
                __('Identity: %s', 'lrob-email-toolkit'),
                $identity->label
            ),
            sprintf(
                /* translators: 1: SMTP host, 2: port, 3: encryption */
                __('Server: %1$s:%2$d (%3$s)', 'lrob-email-toolkit'),
                $identity->smtp_host,
                $identity->smtp_port,
                $identity->smtp_encryption !== '' ? $identity->smtp_encryption : 'plain'
            ),
            sprintf(
                /* translators: %s: from address */
                __('From: %s', 'lrob-email-toolkit'),
                $identity->from_email
            ),
            '',
            __('If you received this, your SMTP configuration is working.', 'lrob-email-toolkit'),
        ];
        return implode("\n", $lines);
    }
}
