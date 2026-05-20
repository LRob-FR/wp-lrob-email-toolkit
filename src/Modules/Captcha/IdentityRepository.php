<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha;

use LRob\EmailToolkit\Support\Encryption;

/**
 * CRUD against the lrob_etk_captcha_identities table. Mirrors SMTP's
 * IdentityRepository: rest of the codebase deals in plaintext credential
 * arrays, only this class touches Encryption directly.
 */
final class IdentityRepository
{
    /** @return array<int, Identity> */
    public function all(): array
    {
        global $wpdb;
        $table = Schema::table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — no user input.
        $rows = $wpdb->get_results("SELECT * FROM `$table` ORDER BY id ASC", ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        return array_map(static fn (array $row): Identity => Identity::from_row($row), $rows);
    }

    /** @return array<int, Identity> */
    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn (Identity $i): bool => $i->is_active));
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

    public function count(): int
    {
        global $wpdb;
        $table = Schema::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
    }

    /**
     * Insert or update an identity. `$plain_credentials`:
     *  - null  → keep existing ciphertext untouched
     *  - []    → clear stored credentials
     *  - other → JSON-encode + encrypt + store
     *
     * Returns the row id (existing on update, new on insert).
     *
     * @param array<string, string>|null $plain_credentials
     */
    public function save(Identity $identity, ?array $plain_credentials = null): int
    {
        global $wpdb;
        $table = Schema::table_name();

        $now = current_time('mysql', true);

        $ciphertext = $identity->credentials_encrypted;
        if ($plain_credentials !== null) {
            $ciphertext = $plain_credentials === []
                ? ''
                : Encryption::encrypt((string) wp_json_encode($plain_credentials));
        }

        $data = [
            'provider_slug'         => $identity->provider_slug,
            'label'                 => $identity->label,
            'credentials_encrypted' => $ciphertext,
            'is_active'             => $identity->is_active ? 1 : 0,
            'updated_at'            => $now,
        ];
        $formats = ['%s', '%s', '%s', '%d', '%s'];

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
}
