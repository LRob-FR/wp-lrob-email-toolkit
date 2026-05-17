<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends a one-off test email through a specific identity so the admin can
 * verify configuration before going live. Self-contained: registers its own
 * phpmailer_init hook at high priority (after MailRouter's normal 9) and
 * tears it down after the call, so test sends work whether or not the SMTP
 * module is enabled.
 *
 * Capture pattern: wp_mail_failed is hooked at priority 1 just for this call
 * so we can return the actual WP_Error message — wp_mail itself only returns
 * a boolean.
 */
final class TestSender
{
    private const PRIORITY = 100;

    public function __construct(
        private IdentityRepository $identities,
        private ConstantOverrides $overrides,
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
            return [
                'success'       => false,
                'error'         => 'Recipient is not a valid email address.',
                'identity_slug' => $identity->slug,
            ];
        }

        $identity = $this->overrides->apply($identity);

        $configure = static function (PHPMailer $mailer) use ($identity): void {
            if ($identity->smtp_host === '') {
                return;
            }
            $mailer->isSMTP();
            $mailer->Host = $identity->smtp_host;
            $mailer->Port = $identity->smtp_port;
            $mailer->SMTPSecure = $identity->smtp_encryption;
            $mailer->SMTPAuth = $identity->smtp_auth;
            if ($identity->smtp_auth) {
                $mailer->Username = $identity->smtp_username;
                $mailer->Password = $identity->decrypted_password();
            }
            $mailer->setFrom($identity->from_email, $identity->effective_from_name(), false);
            if ($identity->reply_to_email !== null && $identity->reply_to_email !== '') {
                $mailer->addReplyTo($identity->reply_to_email);
            }
        };

        $force_from_email = static fn (): string => $identity->from_email;
        $force_from_name = static fn (): string => $identity->effective_from_name();

        $captured_error = null;
        $capture = static function (\WP_Error $err) use (&$captured_error): void {
            $captured_error = $err;
        };

        add_action('phpmailer_init', $configure, self::PRIORITY);
        add_filter('wp_mail_from', $force_from_email, self::PRIORITY);
        add_filter('wp_mail_from_name', $force_from_name, self::PRIORITY);
        add_action('wp_mail_failed', $capture, 1);

        $subject = sprintf(
            /* translators: %s: site name */
            __('[Email Toolkit] Test email from %s', 'lrob-email-toolkit'),
            get_bloginfo('name')
        );
        $body = $this->test_body($identity);

        try {
            $success = wp_mail($recipient, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
        } finally {
            remove_action('phpmailer_init', $configure, self::PRIORITY);
            remove_filter('wp_mail_from', $force_from_email, self::PRIORITY);
            remove_filter('wp_mail_from_name', $force_from_name, self::PRIORITY);
            remove_action('wp_mail_failed', $capture, 1);
        }

        return [
            'success'       => (bool) $success,
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
