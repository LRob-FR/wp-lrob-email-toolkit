<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Tests SMTP host/port/encryption/credentials without sending a message.
 * Uses PHPMailer's smtpConnect() which performs the TLS handshake, EHLO, and
 * AUTH exchange, then closes cleanly. Returns a structured result the AJAX
 * controller can pass straight to the UI.
 *
 * Designed to accept either a saved Identity (look up password from DB) or
 * pending form values (test before save), so the UI "Test connection" button
 * works at any stage of editing.
 */
final class AuthTester
{
    private const CONNECT_TIMEOUT = 10;

    /**
     * @return array{ok: bool, message: string, debug: ?string}
     */
    public function test(Identity $identity): array
    {
        if ($identity->smtp_host === '') {
            return ['ok' => false, 'message' => __('Enter an SMTP host first.', 'lrob-email-toolkit'), 'debug' => null];
        }
        if ($identity->smtp_port < 1 || $identity->smtp_port > 65535) {
            return ['ok' => false, 'message' => __('Invalid SMTP port.', 'lrob-email-toolkit'), 'debug' => null];
        }

        // Bring WordPress's bundled PHPMailer into scope; usually loaded but
        // not guaranteed in admin-ajax context.
        if (!class_exists(PHPMailer::class)) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }

        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $identity->smtp_host;
        $mailer->Port = $identity->smtp_port;
        $mailer->SMTPSecure = $identity->smtp_encryption;
        $mailer->SMTPAuth = $identity->smtp_auth;
        if ($identity->smtp_auth) {
            $mailer->Username = $identity->smtp_username;
            try {
                $mailer->Password = $identity->decrypted_password();
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => __('Could not decrypt stored password. Re-enter it and retry.', 'lrob-email-toolkit'), 'debug' => $e->getMessage()];
            }
        }
        $mailer->Timeout = self::CONNECT_TIMEOUT;

        // Capture PHPMailer's debug output so we can return a useful error
        // line when the connection fails.
        $debug_buffer = [];
        $mailer->SMTPDebug = 1;
        $mailer->Debugoutput = function ($str) use (&$debug_buffer): void {
            $debug_buffer[] = trim((string) $str);
        };

        try {
            $connected = $mailer->smtpConnect();
            if (!$connected) {
                $error = $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : __('Connection failed.', 'lrob-email-toolkit');
                return [
                    'ok'      => false,
                    'message' => $error,
                    'debug'   => $this->summarize_debug($debug_buffer),
                ];
            }

            // Close cleanly: QUIT + drop the socket.
            $smtp = $mailer->getSMTPInstance();
            if ($smtp !== null) {
                $smtp->quit();
                $smtp->close();
            }

            $message = $identity->smtp_auth
                ? __('Connection and authentication successful.', 'lrob-email-toolkit')
                : __('Connection successful (no authentication tested).', 'lrob-email-toolkit');

            return [
                'ok'      => true,
                'message' => $message,
                'debug'   => $this->summarize_debug($debug_buffer),
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'message' => $e->getMessage(),
                'debug'   => $this->summarize_debug($debug_buffer),
            ];
        }
    }

    /** @param array<int, string> $lines */
    private function summarize_debug(array $lines): ?string
    {
        $filtered = array_filter($lines, static fn (string $l): bool => $l !== '');
        if ($filtered === []) {
            return null;
        }
        // Keep the last few lines — those are usually the actionable ones.
        return implode("\n", array_slice($filtered, -6));
    }
}
