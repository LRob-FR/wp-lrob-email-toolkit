<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Lifecycle hooks for the newsletter CPT — the things that have to
 * happen around save_post / before_delete_post regardless of which
 * surface (admin cards / Gutenberg / REST) drives the save.
 *
 * Previously these lived alongside the editor metaboxes in
 * NewsletterMetaboxes; the metaboxes themselves are gone now that
 * the NewslettersPage card surface owns every setting. The lifecycle
 * still matters: the companion-table row must exist by the time the
 * admin first edits the post, and deleting a newsletter post must
 * cascade to its companion row + per-recipient state.
 */
final class NewsletterLifecycle
{
    public function __construct(private NewsletterRepository $newsletters)
    {
    }

    public function register(): void
    {
        add_action('save_post_' . NewsletterCPT::POST_TYPE, [$this, 'on_save'], 10, 2);
        add_action('before_delete_post', [$this, 'on_before_delete']);
    }

    /**
     * Ensure the companion row exists for any newsletter save —
     * Gutenberg REST save, NewslettersPage create, REST API write,
     * or wp-cli post insert all route through save_post. INSERT
     * IGNORE makes this idempotent.
     */
    public function on_save(int $post_id, \WP_Post $post): void
    {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        if ($post->post_type !== NewsletterCPT::POST_TYPE) {
            return;
        }
        $this->newsletters->ensure_row($post_id);
    }

    public function on_before_delete(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || $post->post_type !== NewsletterCPT::POST_TYPE) {
            return;
        }
        $this->newsletters->delete_for_post($post_id);
    }
}
