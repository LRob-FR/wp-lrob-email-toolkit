<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;

/**
 * Newsletters list inside the Newsletter hub. Each row is a CPT post +
 * its companion table state (status, counters, started_at). The list
 * orders newest-first and shows everything except auto-drafts and
 * trashed posts. Per-row actions: Edit (Gutenberg) / Duplicate / Delete.
 *
 * The "+ New newsletter" button creates a draft via wp_insert_post
 * and redirects to the editor. Same admin_post-handler pattern as the
 * Forms admin uses for "+ New form from default" so the create step
 * is nonce-gated against CSRF.
 */
final class NewslettersPage
{
    public const ACTION_CREATE = 'lrob_etk_nl_newsletter_create';

    public const ACTION_DELETE = 'lrob_etk_nl_newsletter_delete';

    public const ACTION_DUPLICATE = 'lrob_etk_nl_newsletter_duplicate';

    public function __construct(private NewsletterRepository $newsletters)
    {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_CREATE, [$this, 'handle_create']);
        add_action('admin_post_' . self::ACTION_DELETE, [$this, 'handle_delete']);
        add_action('admin_post_' . self::ACTION_DUPLICATE, [$this, 'handle_duplicate']);
    }

    public function render(): void
    {
        $rows = $this->newsletters->list_all();
        $create_url = wp_nonce_url(
            add_query_arg(['action' => self::ACTION_CREATE], admin_url('admin-post.php')),
            self::ACTION_CREATE
        );
        ?>
        <section class="lrob-etk-nl-newsletters">
            <header class="lrob-etk-nl-resource-head">
                <h2 class="lrob-etk-section-title"><?php esc_html_e('Newsletters', 'lrob-email-toolkit'); ?></h2>
                <a href="<?php echo esc_url($create_url); ?>" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <?php esc_html_e('New newsletter', 'lrob-email-toolkit'); ?>
                </a>
            </header>
            <p class="lrob-etk-nl-resource-intro">
                <?php esc_html_e('Compose newsletters in the block editor. Drafts and scheduled newsletters sit here until you send them.', 'lrob-email-toolkit'); ?>
            </p>

            <?php if ($rows === []) : ?>
                <p class="lrob-etk-nl-resource-empty">
                    <?php esc_html_e('No newsletters yet. Click "New newsletter" to start one.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else : ?>
                <table class="lrob-etk-nl-subscribers-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Title', 'lrob-email-toolkit'); ?></th>
                            <th><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                            <th><?php esc_html_e('Sent / total', 'lrob-email-toolkit'); ?></th>
                            <th><?php esc_html_e('Last modified', 'lrob-email-toolkit'); ?></th>
                            <th class="lrob-etk-nl-col-actions"><?php esc_html_e('Actions', 'lrob-email-toolkit'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <?php $this->render_row($row); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * @param array<string, mixed> $row
     */
    private function render_row(array $row): void
    {
        $post_id = (int) ($row['post_id'] ?? 0);
        $title = (string) ($row['post_title'] ?? '');
        $status = (string) ($row['status'] ?? NewsletterRepository::STATUS_DRAFT);
        $sent = (int) ($row['sent_count'] ?? 0);
        $total = (int) ($row['total_recipients'] ?? 0);
        $modified = (string) ($row['post_modified_gmt'] ?? '');

        $edit_url = get_edit_post_link($post_id);
        $delete_url = wp_nonce_url(
            add_query_arg(['action' => self::ACTION_DELETE, 'post' => $post_id], admin_url('admin-post.php')),
            self::ACTION_DELETE . '_' . $post_id
        );
        $duplicate_url = wp_nonce_url(
            add_query_arg(['action' => self::ACTION_DUPLICATE, 'post' => $post_id], admin_url('admin-post.php')),
            self::ACTION_DUPLICATE . '_' . $post_id
        );
        ?>
        <tr>
            <td>
                <a href="<?php echo esc_url((string) $edit_url); ?>"><strong><?php echo esc_html($title !== '' ? $title : __('(untitled)', 'lrob-email-toolkit')); ?></strong></a>
            </td>
            <td>
                <span class="lrob-etk-nl-status lrob-etk-nl-status-<?php echo esc_attr($status); ?>">
                    <?php echo esc_html(self::translate_status($status)); ?>
                </span>
            </td>
            <td>
                <?php if ($total > 0) : ?>
                    <?php echo esc_html(sprintf('%s / %s', number_format_i18n($sent), number_format_i18n($total))); ?>
                <?php else : ?>
                    —
                <?php endif; ?>
            </td>
            <td>
                <time datetime="<?php echo esc_attr($modified); ?>"><?php echo esc_html(self::format_date($modified)); ?></time>
            </td>
            <td class="lrob-etk-nl-col-actions">
                <a href="<?php echo esc_url((string) $edit_url); ?>" class="lrob-etk-nl-row-action"><?php esc_html_e('Edit', 'lrob-email-toolkit'); ?></a>
                <a href="<?php echo esc_url($duplicate_url); ?>" class="lrob-etk-nl-row-action"><?php esc_html_e('Duplicate', 'lrob-email-toolkit'); ?></a>
                <a href="<?php echo esc_url($delete_url); ?>"
                   class="lrob-etk-nl-row-action is-danger"
                   onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Delete this newsletter? This cannot be undone.', 'lrob-email-toolkit'))); ?>);">
                    <?php esc_html_e('Delete', 'lrob-email-toolkit'); ?>
                </a>
            </td>
        </tr>
        <?php
    }

    public function handle_create(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::ACTION_CREATE)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }
        $new_id = wp_insert_post([
            'post_type'    => NewsletterCPT::POST_TYPE,
            'post_status'  => 'draft',
            'post_title'   => __('Untitled newsletter', 'lrob-email-toolkit'),
            'post_content' => '',
        ], true);
        if (is_wp_error($new_id) || !is_int($new_id) || $new_id <= 0) {
            wp_die(esc_html__('Could not create the newsletter.', 'lrob-email-toolkit'));
        }
        wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_id));
        exit;
    }

    public function handle_delete(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $post_id = isset($_GET['post']) ? (int) wp_unslash((string) $_GET['post']) : 0;
        $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::ACTION_DELETE . '_' . $post_id)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post instanceof \WP_Post || $post->post_type !== NewsletterCPT::POST_TYPE) {
            wp_die(esc_html__('Newsletter not found.', 'lrob-email-toolkit'));
        }
        wp_delete_post($post_id, true);
        wp_safe_redirect(add_query_arg(
            ['page' => PageController::SLUG, 'view' => HomePage::VIEW_NEWSLETTERS],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Duplicate a newsletter: clone the post (title + content) plus
     * every `_lrob_etk_nl_*` post meta into a new draft. New title
     * gets a "(copy)" suffix; companion-row sync is handled by the
     * normal save_post hook. Counters / send state do NOT carry
     * over — the copy is always a fresh draft.
     */
    public function handle_duplicate(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $post_id = isset($_GET['post']) ? (int) wp_unslash((string) $_GET['post']) : 0;
        $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::ACTION_DUPLICATE . '_' . $post_id)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }
        $source = $post_id > 0 ? get_post($post_id) : null;
        if (!$source instanceof \WP_Post || $source->post_type !== NewsletterCPT::POST_TYPE) {
            wp_die(esc_html__('Newsletter not found.', 'lrob-email-toolkit'));
        }

        /* translators: %s: original item title being cloned (form, newsletter, template, etc.) */
        $copy_title_template = __('%s (copy)', 'lrob-email-toolkit');
        $new_title = sprintf(
            $copy_title_template,
            $source->post_title !== '' ? $source->post_title : __('Untitled newsletter', 'lrob-email-toolkit')
        );

        $new_id = wp_insert_post([
            'post_type'    => NewsletterCPT::POST_TYPE,
            'post_status'  => 'draft',
            'post_title'   => $new_title,
            'post_content' => $source->post_content,
        ], true);
        if (is_wp_error($new_id) || !is_int($new_id) || $new_id <= 0) {
            wp_die(esc_html__('Could not duplicate the newsletter.', 'lrob-email-toolkit'));
        }

        // Clone every `_lrob_etk_nl_*` meta key from the source. WP
        // emits scheduled_at / counters etc. into the same prefix; the
        // companion table state (status/sent_count/started_at/...)
        // lives on the dedicated table and is not copied — the new
        // draft starts at 'draft' status.
        $meta_keys = get_post_custom_keys($post_id) ?: [];
        foreach ($meta_keys as $key) {
            if (!is_string($key) || !str_starts_with($key, '_lrob_etk_nl_')) {
                continue;
            }
            $values = get_post_meta($post_id, $key);
            foreach ($values as $value) {
                add_post_meta($new_id, $key, $value);
            }
        }

        wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_id));
        exit;
    }

    private static function translate_status(string $status): string
    {
        return match ($status) {
            NewsletterRepository::STATUS_DRAFT     => __('Draft', 'lrob-email-toolkit'),
            NewsletterRepository::STATUS_SCHEDULED => __('Scheduled', 'lrob-email-toolkit'),
            NewsletterRepository::STATUS_SENDING   => __('Sending', 'lrob-email-toolkit'),
            NewsletterRepository::STATUS_PAUSED    => __('Paused', 'lrob-email-toolkit'),
            NewsletterRepository::STATUS_SENT      => __('Sent', 'lrob-email-toolkit'),
            NewsletterRepository::STATUS_FAILED    => __('Failed', 'lrob-email-toolkit'),
            NewsletterRepository::STATUS_ABORTED   => __('Aborted', 'lrob-email-toolkit'),
            default                              => $status,
        };
    }

    private static function format_date(string $datetime_utc): string
    {
        if ($datetime_utc === '' || $datetime_utc === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($datetime_utc . ' UTC');
        if ($ts === false) {
            return $datetime_utc;
        }
        return (string) wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $ts);
    }
}
