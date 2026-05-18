<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

/**
 * Thin CRUD over the lrob_etk_contact_submissions table. Submissions hold a
 * JSON copy of the user-submitted fields, the IP hash (never the raw IP),
 * user agent, referer, status, and an optional log_id linking back to the
 * outgoing email entry created by the Logging module.
 *
 * Stored field values are kept verbatim — any escaping happens at *render*
 * time. CSV export will need to neuter spreadsheet formula injection
 * (=, +, -, @ prefixes) before writing the file — flagged in CLAUDE.md
 * backlog. Do not pre-neuter on insert.
 */
final class SubmissionRepository
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SPAM_BLOCKED = 'spam_blocked';

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $context  keys: ip_hash, user_agent, referer, notes
     */
    public function insert(int $form_id, array $fields, array $context, string $status = self::STATUS_RECEIVED): int
    {
        global $wpdb;
        $wpdb->insert(
            Schema::submissions_table(),
            [
                'form_id'      => $form_id,
                'submitted_at' => gmdate('Y-m-d H:i:s'),
                'status'       => $status,
                'ip_hash'      => substr($context['ip_hash'] ?? '', 0, 64),
                'user_agent'   => mb_substr($context['user_agent'] ?? '', 0, 500),
                'referer'      => mb_substr($context['referer'] ?? '', 0, 500),
                'fields_json'  => (string) wp_json_encode($fields),
                'log_id'       => null,
                'notes'        => $context['notes'] ?? null,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', null, '%s']
        );
        return (int) $wpdb->insert_id;
    }

    public function update_status(int $id, string $status, ?int $log_id = null, ?string $notes = null): void
    {
        global $wpdb;
        $data = ['status' => $status];
        $formats = ['%s'];
        if ($log_id !== null) {
            $data['log_id'] = $log_id;
            $formats[] = '%d';
        }
        if ($notes !== null) {
            $data['notes'] = $notes;
            $formats[] = '%s';
        }
        $wpdb->update(Schema::submissions_table(), $data, ['id' => $id], $formats, ['%d']);
    }

    public function count_for_form(int $form_id): int
    {
        global $wpdb;
        $table = Schema::submissions_table();
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE form_id = %d", $form_id)
        );
    }

    public function count_total(): int
    {
        global $wpdb;
        $table = Schema::submissions_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
    }
}
