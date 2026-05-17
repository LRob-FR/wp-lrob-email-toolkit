<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging;

use LRob\EmailToolkit\Support\Events;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Captures every outgoing email by hooking phpmailer_init at priority 999 —
 * after the SMTP module's MailRouter at priority 9 has run, so the PHPMailer
 * object already reflects the final From, Subject, Body, etc.
 *
 * Source / identity context, when available, comes from the SMTP module's
 * `lrob_etk_email_sending` event which fires earlier in the same hook chain.
 * Logger captures that payload into a property and includes it in the insert.
 * If the SMTP module is disabled, source defaults to 'unknown' and identity
 * stays null — basic logging still works without SMTP.
 *
 * After PHPMailer::send() returns, wp_mail fires `wp_mail_succeeded` or
 * `wp_mail_failed` and Logger flips the row's status accordingly.
 */
final class Logger
{
    private ?int $current_log_id = null;

    /** @var array<string, mixed> */
    private array $pending_sending_event = [];

    public function __construct(private LogRepository $repository)
    {
    }

    public function register(): void
    {
        add_action('lrob_etk_email_sending', [$this, 'capture_sending']);

        add_action('phpmailer_init', [$this, 'log_outgoing'], 999);

        add_action('wp_mail_succeeded', [$this, 'on_succeeded']);
        add_action('wp_mail_failed', [$this, 'on_failed']);
    }

    /** @param array<string, mixed> $payload */
    public function capture_sending(array $payload): void
    {
        $this->pending_sending_event = $payload;
    }

    public function log_outgoing(PHPMailer $mailer): void
    {
        try {
            $entry = LogEntry::from_phpmailer($mailer);

            $changes = [];
            if (!empty($this->pending_sending_event['source'])) {
                $changes['source'] = (string) $this->pending_sending_event['source'];
            }
            if (!empty($this->pending_sending_event['identity_id'])) {
                $changes['identity_id'] = (int) $this->pending_sending_event['identity_id'];
            }
            if ($changes !== []) {
                $entry = $entry->with($changes);
            }

            $this->current_log_id = $this->repository->insert($entry);
        } catch (\Throwable $e) {
            // Logging must never break wp_mail. Surface the failure to the PHP
            // error log so site owners can find it, then move on.
            error_log('[lrob-etk] Logger insert failed: ' . $e->getMessage());
            $this->current_log_id = null;
        } finally {
            $this->pending_sending_event = [];
        }
    }

    /** @param array<string, mixed> $mail_data */
    public function on_succeeded(array $mail_data): void
    {
        if ($this->current_log_id === null) {
            return;
        }
        try {
            $this->repository->update_status($this->current_log_id, LogEntry::STATUS_SENT);
        } catch (\Throwable $e) {
            error_log('[lrob-etk] Logger update_status sent failed: ' . $e->getMessage());
        }
        $this->reset_current();
    }

    public function on_failed(\WP_Error $error): void
    {
        if ($this->current_log_id === null) {
            return;
        }
        try {
            $this->repository->update_status(
                $this->current_log_id,
                LogEntry::STATUS_FAILED,
                $error->get_error_message()
            );
        } catch (\Throwable $e) {
            error_log('[lrob-etk] Logger update_status failed: ' . $e->getMessage());
        }
        $this->reset_current();
    }

    private function reset_current(): void
    {
        $this->current_log_id = null;
        $this->pending_sending_event = [];
    }
}
