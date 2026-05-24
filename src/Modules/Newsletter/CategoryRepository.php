<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * CRUD for newsletter email categories. Categories are required on
 * every newsletter — the per-recipient preference granularity.
 * Subscribers store category opt-outs as a JSON array of category SLUGS
 * so renames don't break references.
 *
 * The "general" category is seeded on module install (see
 * Module::seed_default_category) and protected from deletion at the
 * admin layer — sites never end up with zero categories that way.
 */
final class CategoryRepository
{
    public const PROTECTED_SLUG = 'general';

    /** @return array<int, array<string, mixed>> Sort-order ASC, then created_at ASC. */
    public function list_all(): array
    {
        global $wpdb;
        $table = Schema::categories_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT * FROM `$table` ORDER BY sort_order ASC, created_at ASC",
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $table = Schema::categories_table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `$table` WHERE id = %d", $id),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /** Slug-keyed map of category names — used by the picker field. */
    public function slug_label_map(): array
    {
        $out = [];
        foreach ($this->list_all() as $row) {
            $out[(string) $row['slug']] = (string) $row['name'];
        }
        return $out;
    }

    /**
     * Create a category. Auto-generates a unique slug from the name
     * if not supplied. Returns the new id on success, 0 if the slug
     * collides with an existing row (caller's job to surface the
     * collision message).
     */
    public function insert(string $name, string $description = '', string $slug = ''): int
    {
        global $wpdb;
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $slug = $slug !== '' ? sanitize_title($slug) : $this->generate_unique_slug($name);
        if ($slug === '' || $this->slug_exists($slug)) {
            return 0;
        }
        $ok = $wpdb->insert(Schema::categories_table(), [
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description,
            'sort_order'  => $this->next_sort_order(),
            'created_at'  => current_time('mysql', true),
        ], ['%s', '%s', '%s', '%d', '%s']);
        return $ok !== false ? (int) $wpdb->insert_id : 0;
    }

    /** Rename a category (slug stays — renames must not orphan opt-outs). */
    public function rename(int $id, string $name): bool
    {
        global $wpdb;
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        $count = $wpdb->update(
            Schema::categories_table(),
            ['name' => $name],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
        return $count !== false && $count > 0;
    }

    /**
     * Delete a category. The "general" default is protected at this
     * layer — admin UI doesn't even surface the delete button for it.
     * Deleting a category does NOT remove it from existing
     * subscribers' opt-outs JSON; orphaned slugs are harmless (the
     * filter at send time just treats them as "opted out of a
     * category that no longer exists").
     */
    public function delete(int $id): bool
    {
        $row = $this->find($id);
        if ($row === null || ($row['slug'] ?? '') === self::PROTECTED_SLUG) {
            return false;
        }
        global $wpdb;
        $count = $wpdb->delete(Schema::categories_table(), ['id' => $id], ['%d']);
        return $count !== false && $count > 0;
    }

    public function count_total(): int
    {
        global $wpdb;
        $table = Schema::categories_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
    }

    private function slug_exists(string $slug): bool
    {
        global $wpdb;
        $table = Schema::categories_table();
        $hit = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM `$table` WHERE slug = %s LIMIT 1", $slug)
        );
        return $hit !== null;
    }

    private function generate_unique_slug(string $name): string
    {
        $base = sanitize_title($name);
        if ($base === '') {
            return '';
        }
        $candidate = $base;
        $n = 2;
        while ($this->slug_exists($candidate)) {
            $candidate = $base . '-' . $n;
            $n++;
        }
        return $candidate;
    }

    private function next_sort_order(): int
    {
        global $wpdb;
        $table = Schema::categories_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $max = $wpdb->get_var("SELECT MAX(sort_order) FROM `$table`");
        return ((int) $max) + 10;
    }
}
