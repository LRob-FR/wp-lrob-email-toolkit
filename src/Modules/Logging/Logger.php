<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging;

use LRob\EmailToolkit\Support\Events;
use PHPMailer\PHPMailer\PHPMailer;

// Docs: docs/logging.md
final class Logger
{
    public const HEADER_NEWSLETTER_ID           = 'X-Lrob-Etk-Newsletter-ID';

    public const HEADER_NEWSLETTER_RECIPIENT_ID = 'X-Lrob-Etk-Newsletter-Recipient-ID';

    public const HEADER_NEWSLETTER_TEST         = 'X-Lrob-Etk-Newsletter-Test';

    public const META_LOG_ALL_SENDS             = '_lrob_etk_nl_log_all_sends';

    private ?int $current_log_id = null;

    private ?int $current_newsletter_id = null;

    private bool $current_is_test = false;

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

            [$nl_id, $rcp_id, $is_test] = self::extract_newsletter_headers($entry->headers);
            if ($nl_id !== null) {
                $changes['newsletter_id'] = $nl_id;
                $changes['source'] = $is_test ? 'newsletter_test' : 'newsletter';
            }
            if ($rcp_id !== null) {
                $changes['recipient_id'] = $rcp_id;
            }

            if (!empty($this->pending_sending_event['save_attachments']) && $entry->attachments !== []) {
                $changes['attachments'] = $this->persist_attachments($entry->attachments);
            }

            if ($changes !== []) {
                $entry = $entry->with($changes);
            }

            $this->current_log_id        = $this->repository->insert($entry);
            $this->current_newsletter_id = $nl_id;
            $this->current_is_test       = $is_test;
        } catch (\Throwable $e) {
            // Logging must never break wp_mail. Surface the failure to the PHP
            // error log so site owners can find it, then move on.
            error_log('[lrob-etk] Logger insert failed: ' . $e->getMessage());
            $this->current_log_id = null;
            $this->current_newsletter_id = null;
            $this->current_is_test = false;
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
            if ($this->should_suppress_success_log()) {
                $this->repository->delete($this->current_log_id);
            } else {
                $this->repository->update_status($this->current_log_id, LogEntry::STATUS_SENT);
            }
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

    /**
     * @param  array<int, array{name: string, path: ?string}> $attachments
     * @return array<int, array{name: string, path: ?string}>
     */
    private function persist_attachments(array $attachments): array
    {
        $out = [];
        foreach ($attachments as $a) {
            $path = $a['path'] ?? null;
            if ($path !== null && $path !== '') {
                $copy = AttachmentStore::persist($path, (string) ($a['name'] ?? ''));
                if ($copy !== null) {
                    $out[] = ['name' => (string) ($a['name'] ?? ''), 'path' => $copy];
                    continue;
                }
            }
            $out[] = $a;
        }
        return $out;
    }

    /**
     * @param  array<int, array{name: string, value: string}> $headers
     * @return array{0: ?int, 1: ?int, 2: bool}  [newsletter_id, recipient_id, is_test]
     */
    private static function extract_newsletter_headers(array $headers): array
    {
        $nl_id = null;
        $rcp_id = null;
        $is_test = false;
        foreach ($headers as $h) {
            $name = strtolower((string) ($h['name'] ?? ''));
            $value = (string) ($h['value'] ?? '');
            if ($name === strtolower(self::HEADER_NEWSLETTER_ID) && $value !== '') {
                $nl_id = (int) $value > 0 ? (int) $value : null;
            } elseif ($name === strtolower(self::HEADER_NEWSLETTER_RECIPIENT_ID) && $value !== '') {
                $rcp_id = (int) $value > 0 ? (int) $value : null;
            } elseif ($name === strtolower(self::HEADER_NEWSLETTER_TEST) && $value !== '') {
                $is_test = true;
            }
        }
        return [$nl_id, $rcp_id, $is_test];
    }

    private function should_suppress_success_log(): bool
    {
        if ($this->current_newsletter_id === null) {
            return false;
        }
        $log_all = (string) get_post_meta($this->current_newsletter_id, self::META_LOG_ALL_SENDS, true);
        return $log_all !== '1';
    }

    private function reset_current(): void
    {
        $this->current_log_id        = null;
        $this->current_newsletter_id = null;
        $this->current_is_test       = false;
        $this->pending_sending_event = [];
    }
}
