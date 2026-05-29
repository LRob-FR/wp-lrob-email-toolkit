<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

// Every delete method here ALSO removes the underlying file via FileStorage.
final class FileRepository
{
    public function insert(
        int $submission_id,
        int $form_id,
        string $field_slug,
        string $original_name,
        string $stored_path,
        int $size_bytes,
        string $mime,
    ): int {
        global $wpdb;
        $table = Schema::files_table();
        $ok = $wpdb->insert(
            $table,
            [
                'submission_id' => $submission_id,
                'form_id'       => $form_id,
                'field_slug'    => $field_slug,
                'original_name' => $original_name,
                'stored_path'   => $stored_path,
                'size_bytes'    => $size_bytes,
                'mime'          => $mime,
                'created_at'    => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
        );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /** @return array<string, mixed>|null */
    public function find_by_id(int $id): ?array
    {
        global $wpdb;
        $table = Schema::files_table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `$table` WHERE id = %d", $id),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function find_by_submission(int $submission_id): array
    {
        global $wpdb;
        $table = Schema::files_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `$table` WHERE submission_id = %d ORDER BY id ASC",
                $submission_id
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string, mixed>> */
    public function find_by_ids(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0));
        if ($ids === []) {
            return [];
        }
        global $wpdb;
        $table = Schema::files_table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `$table` WHERE id IN ($placeholders) ORDER BY id ASC", ...$ids),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    /** Deletes a single row and its on-disk file. Returns true if the row was removed. */
    public function delete_by_id(int $id): bool
    {
        $row = $this->find_by_id($id);
        if ($row === null) {
            return false;
        }
        if (!empty($row['stored_path'])) {
            FileStorage::delete((string) $row['stored_path']);
        }
        global $wpdb;
        $table = Schema::files_table();
        return (bool) $wpdb->delete($table, ['id' => $id], ['%d']);
    }

    /** Bulk-delete every file attached to a submission. Used by submission deletion + retention cron. */
    public function delete_by_submission(int $submission_id): int
    {
        $rows = $this->find_by_submission($submission_id);
        $count = 0;
        foreach ($rows as $row) {
            if (!empty($row['stored_path'])) {
                FileStorage::delete((string) $row['stored_path']);
            }
            $count++;
        }
        global $wpdb;
        $table = Schema::files_table();
        $wpdb->delete($table, ['submission_id' => $submission_id], ['%d']);
        return $count;
    }

    /** Bulk-delete every file from a form (used by the cascade modal when a form CPT is removed). */
    public function delete_by_form(int $form_id): int
    {
        global $wpdb;
        $table = Schema::files_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, stored_path FROM `$table` WHERE form_id = %d", $form_id),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return 0;
        }
        foreach ($rows as $row) {
            if (!empty($row['stored_path'])) {
                FileStorage::delete((string) $row['stored_path']);
            }
        }
        $wpdb->delete($table, ['form_id' => $form_id], ['%d']);
        return count($rows);
    }

    /**
     * Bulk-delete rows whose created_at is older than $days. Used by the
     * Storage maintenance UI's "delete files older than X" action.
     */
    public function delete_older_than(int $days): int
    {
        global $wpdb;
        $table = Schema::files_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, stored_path FROM `$table` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return 0;
        }
        foreach ($rows as $row) {
            if (!empty($row['stored_path'])) {
                FileStorage::delete((string) $row['stored_path']);
            }
        }
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `$table` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
        return count($rows);
    }

    /** @return array<int, array{form_id:int, file_count:int, total_bytes:int}> */
    public function disk_usage_by_form(): array
    {
        global $wpdb;
        $table = Schema::files_table();
        $rows = $wpdb->get_results(
            "SELECT form_id, COUNT(*) AS file_count, SUM(size_bytes) AS total_bytes
             FROM `$table` GROUP BY form_id ORDER BY total_bytes DESC",
            ARRAY_A
        );
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $out[] = [
                    'form_id'     => (int) $row['form_id'],
                    'file_count'  => (int) $row['file_count'],
                    'total_bytes' => (int) $row['total_bytes'],
                ];
            }
        }
        return $out;
    }

    public function total_disk_usage(): int
    {
        global $wpdb;
        $table = Schema::files_table();
        return (int) $wpdb->get_var("SELECT SUM(size_bytes) FROM `$table`");
    }

    public function count_total(): int
    {
        global $wpdb;
        $table = Schema::files_table();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
    }
}
