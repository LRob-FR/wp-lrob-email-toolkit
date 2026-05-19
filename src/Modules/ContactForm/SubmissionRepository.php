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

    public const CAPTCHA_OUTCOME_PASSED = 'passed';

    public const CAPTCHA_OUTCOME_FAILED = 'failed';

    public const CAPTCHA_OUTCOME_SKIPPED = 'skipped';

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $context  keys: ip_hash, user_agent, referer, notes,
     *                                              captcha_slug, captcha_outcome
     */
    public function insert(int $form_id, array $fields, array $context, string $status = self::STATUS_RECEIVED): int
    {
        global $wpdb;
        // Submissions inserts run inside the AJAX submit pipeline. If
        // anything echoes here (WP_DEBUG_DISPLAY + a stale schema column,
        // for example), the JSON response gets prefixed with HTML and the
        // frontend falls back to its generic "Something went wrong"
        // message. Silence wpdb output during the insert; the call's
        // return value still tells us success/failure.
        $suppress_was = $wpdb->suppress_errors(true);
        $show_was = $wpdb->show_errors(false);
        $wpdb->insert(
            Schema::submissions_table(),
            [
                'form_id'         => $form_id,
                'submitted_at'    => gmdate('Y-m-d H:i:s'),
                'status'          => $status,
                'ip_hash'         => substr($context['ip_hash'] ?? '', 0, 64),
                'user_agent'      => mb_substr($context['user_agent'] ?? '', 0, 500),
                'referer'         => mb_substr($context['referer'] ?? '', 0, 500),
                'fields_json'     => (string) wp_json_encode($fields),
                'log_id'          => null,
                'notes'           => $context['notes'] ?? null,
                'captcha_slug'    => substr((string) ($context['captcha_slug'] ?? ''), 0, 40),
                'captcha_outcome' => substr((string) ($context['captcha_outcome'] ?? ''), 0, 20),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', null, '%s', '%s', '%s']
        );
        $insert_id = (int) $wpdb->insert_id;
        $wpdb->suppress_errors($suppress_was);
        $wpdb->show_errors($show_was);
        return $insert_id;
    }

    /**
     * Recent captcha activity for the dashboard widget: count by
     * (captcha_slug, captcha_outcome) over the last N days. Excludes rows
     * with no recorded captcha (legacy submissions from before this column
     * existed).
     *
     * @return array<int, array{captcha_slug:string, captcha_outcome:string, n:int}>
     */
    public function captcha_breakdown(int $days = 30): array
    {
        global $wpdb;
        $table = Schema::submissions_table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT captcha_slug, captcha_outcome, COUNT(*) AS n
                 FROM `$table`
                 WHERE submitted_at >= %s AND captcha_slug <> ''
                 GROUP BY captcha_slug, captcha_outcome",
                $cutoff
            ),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        return array_map(
            static fn($r) => [
                'captcha_slug'    => (string) $r['captcha_slug'],
                'captcha_outcome' => (string) $r['captcha_outcome'],
                'n'               => (int) $r['n'],
            ],
            $rows
        );
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
