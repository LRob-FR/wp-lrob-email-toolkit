<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Read-side query helpers for newsletter subscribe forms. Mirrors the
 * shape of TemplateRepository / SubscriberRepository — keeps WP_Query
 * plumbing out of the admin renderers and gives the submit handler a
 * single point of resolution.
 */
final class FormRepository
{
    /**
     * Oldest first: newly-created forms land at the END of the card grid,
     * matching the natural creation timeline. The new-form picker
     * navigates to `#form-<id>` after creation so the browser scrolls
     * straight to the freshly-added card. Matches ContactForm's
     * fetch_forms ordering.
     *
     * @return array<int, \WP_Post>
     */
    public function list_published(): array
    {
        $posts = get_posts([
            'post_type'        => FormCPT::POST_TYPE,
            'post_status'      => ['publish', 'draft'],
            'posts_per_page'   => -1,
            'orderby'          => 'date',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);
        return is_array($posts) ? $posts : [];
    }

    public function count_total(): int
    {
        $counts = wp_count_posts(FormCPT::POST_TYPE);
        if (!$counts instanceof \stdClass) {
            return 0;
        }
        return (int) ($counts->publish ?? 0) + (int) ($counts->draft ?? 0);
    }

    /** Returns the form's `WP_Post` if it exists and matches our CPT, or null. */
    public function find(int $form_id): ?\WP_Post
    {
        if ($form_id <= 0) {
            return null;
        }
        $post = get_post($form_id);
        if (!$post instanceof \WP_Post || $post->post_type !== FormCPT::POST_TYPE) {
            return null;
        }
        return $post;
    }
}
