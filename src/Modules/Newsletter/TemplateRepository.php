<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Read-side helpers for the email-template CPT. Keeps WP_Query plumbing
 * out of the admin view and gives the send-pipeline a single place to
 * resolve "give me the default template for purpose X".
 *
 * Default resolution: prefer the most-recently-modified template whose
 * `_lrob_etk_nl_template_is_default` meta is true. If none is flagged
 * default (admin deleted the seed and didn't re-mark another), fall back
 * to the most-recent template for that purpose. If still nothing,
 * returns 0 — the send pipeline treats 0 as "no template, refuse to
 * send" and surfaces an admin notice.
 */
final class TemplateRepository
{
    /** @return array<int, \WP_Post> Most-recently-modified first. */
    public function list_by_purpose(string $purpose): array
    {
        $posts = get_posts([
            'post_type'      => TemplateCPT::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'meta_key'       => TemplateCPT::META_PURPOSE,
            'meta_value'     => $purpose,
            'suppress_filters' => true,
        ]);
        return is_array($posts) ? $posts : [];
    }

    /** @return array<string, array<int, \WP_Post>> keyed by purpose. */
    public function list_all_grouped(): array
    {
        $out = [];
        foreach (TemplateCPT::purposes() as $purpose) {
            $out[$purpose] = $this->list_by_purpose($purpose);
        }
        return $out;
    }

    /** Returns post id of the resolved default for $purpose, or 0 if none usable. */
    public function default_id_for_purpose(string $purpose): int
    {
        // Pass 1: an explicitly-flagged default.
        $flagged = get_posts([
            'post_type'      => TemplateCPT::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => TemplateCPT::META_PURPOSE,
                    'value' => $purpose,
                ],
                [
                    'key'     => TemplateCPT::META_IS_DEFAULT,
                    'value'   => '1',
                    'compare' => '=',
                ],
            ],
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        if (!empty($flagged)) {
            return (int) $flagged[0];
        }

        // Pass 2: any template for the purpose (most recent).
        $any = get_posts([
            'post_type'      => TemplateCPT::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'meta_key'       => TemplateCPT::META_PURPOSE,
            'meta_value'     => $purpose,
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        return !empty($any) ? (int) $any[0] : 0;
    }

    public function count_total(): int
    {
        $counts = wp_count_posts(TemplateCPT::POST_TYPE);
        if (!$counts instanceof \stdClass) {
            return 0;
        }
        return (int) ($counts->publish ?? 0) + (int) ($counts->draft ?? 0);
    }

    /**
     * Flag $template_id as the active template for its purpose. Clears
     * the same flag on every other template of that purpose so the
     * default-resolver always finds a single winner. Returns true on
     * success, false if the post isn't a template or has no purpose
     * meta yet.
     */
    public function set_default_for_purpose(int $template_id): bool
    {
        $post = $template_id > 0 ? get_post($template_id) : null;
        if (!$post instanceof \WP_Post || $post->post_type !== TemplateCPT::POST_TYPE) {
            return false;
        }
        $purpose = (string) get_post_meta($template_id, TemplateCPT::META_PURPOSE, true);
        if ($purpose === '') {
            return false;
        }
        foreach ($this->list_by_purpose($purpose) as $sibling) {
            if ((int) $sibling->ID === $template_id) {
                update_post_meta($sibling->ID, TemplateCPT::META_IS_DEFAULT, '1');
            } else {
                delete_post_meta($sibling->ID, TemplateCPT::META_IS_DEFAULT);
            }
        }
        return true;
    }
}
