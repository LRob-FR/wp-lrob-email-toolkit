<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

use LRob\EmailToolkit\Support\Encryption;

/**
 * CRUD against the lrob_etk_identities table. Handles the encryption boundary:
 * the rest of the codebase deals in plaintext (via Identity::decrypted_password())
 * and plain-password method arguments to save(); only this class touches the
 * Encryption support directly when persisting.
 */
final class IdentityRepository
{
    /** @return array<int, Identity> */
    public function all(): array
    {
        global $wpdb;
        $table = Schema::table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — no user input
        $rows = $wpdb->get_results("SELECT * FROM `$table` ORDER BY is_default DESC, id ASC", ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $row): Identity => Identity::from_row($row), $rows);
    }

    public function find(int $id): ?Identity
    {
        global $wpdb;
        $table = Schema::table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `$table` WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Identity::from_row($row) : null;
    }

    public function find_by_slug(string $slug): ?Identity
    {
        global $wpdb;
        $table = Schema::table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `$table` WHERE slug = %s LIMIT 1", $slug),
            ARRAY_A
        );

        return is_array($row) ? Identity::from_row($row) : null;
    }

    public function find_default(): ?Identity
    {
        global $wpdb;
        $table = Schema::table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row(
            "SELECT * FROM `$table` WHERE is_default = 1 AND is_active = 1 LIMIT 1",
            ARRAY_A
        );

        return is_array($row) ? Identity::from_row($row) : null;
    }

    public function count(): int
    {
        global $wpdb;
        $table = Schema::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
    }

    /**
     * Insert or update an identity. The password argument is the *plain*
     * password from the form:
     *  - null  → keep the existing ciphertext untouched (the common case when
     *            editing an identity without retyping the password)
     *  - ''    → clear the stored password (empty ciphertext)
     *  - other → encrypt and store
     *
     * Returns the identity's id (existing on update, newly assigned on insert).
     */
    public function save(Identity $identity, ?string $plain_password = null): int
    {
        global $wpdb;
        $table = Schema::table_name();

        $now = current_time('mysql', true);

        $ciphertext = $identity->smtp_password_encrypted;
        if ($plain_password !== null) {
            $ciphertext = $plain_password === '' ? '' : Encryption::encrypt($plain_password);
        }

        $data = [
            'slug'                    => $identity->slug,
            'label'                   => $identity->label,
            'transport'               => $identity->transport,
            'from_email'              => $identity->from_email,
            'from_name'               => $identity->from_name,
            'smtp_host'               => $identity->smtp_host,
            'smtp_port'               => $identity->smtp_port,
            'smtp_encryption'         => $identity->smtp_encryption,
            'smtp_username'           => $identity->smtp_username,
            'smtp_password_encrypted' => $ciphertext,
            'smtp_auth'               => $identity->smtp_auth ? 1 : 0,
            'override_mode'           => $identity->override_mode,
            'reply_to_email'          => $identity->reply_to_email,
            'is_default'              => $identity->is_default ? 1 : 0,
            'is_active'               => $identity->is_active ? 1 : 0,
            'updated_at'              => $now,
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s'];

        if ($identity->id === null) {
            $data['created_at'] = $now;
            $formats[] = '%s';
            $wpdb->insert($table, $data, $formats);
            return (int) $wpdb->insert_id;
        }

        $wpdb->update($table, $data, ['id' => $identity->id], $formats, ['%d']);
        return $identity->id;
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(Schema::table_name(), ['id' => $id], ['%d']);
    }

    /**
     * Marks one identity as the default and clears the flag on every other
     * identity. Runs in a single transaction so we never end up with zero or
     * two defaults visible to a concurrent reader.
     */
    public function set_default(int $id): void
    {
        global $wpdb;
        $table = Schema::table_name();

        $wpdb->query('START TRANSACTION');
        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("UPDATE `$table` SET is_default = 0");
            $wpdb->update($table, ['is_default' => 1], ['id' => $id], ['%d'], ['%d']);
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /** Is a slug taken by an identity other than $excluding_id? */
    public function slug_exists(string $slug, ?int $excluding_id = null): bool
    {
        global $wpdb;
        $table = Schema::table_name();

        if ($excluding_id === null) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE slug = %s", $slug)
            );
        } else {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE slug = %s AND id <> %d", $slug, $excluding_id)
            );
        }

        return $count > 0;
    }
}
