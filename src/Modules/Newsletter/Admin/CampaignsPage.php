<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Newsletter\CampaignCPT;
use LRob\EmailToolkit\Modules\Newsletter\CampaignRepository;

/**
 * Campaigns list inside the Newsletter hub. Each row is a CPT post +
 * its companion table state (status, counters, started_at). The list
 * orders newest-first and shows everything except auto-drafts and
 * trashed posts. Per-row actions: Edit (Gutenberg) / Delete.
 *
 * The "+ New campaign" button creates a draft via wp_insert_post and
 * redirects to the editor. Same admin_post-handler pattern as the
 * Forms admin uses for "+ New form from default" so the create step
 * is nonce-gated against CSRF.
 */
final class CampaignsPage
{
    public const ACTION_CREATE = 'lrob_etk_nl_campaign_create';

    public const ACTION_DELETE = 'lrob_etk_nl_campaign_delete';

    public function __construct(private CampaignRepository $campaigns)
    {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_CREATE, [$this, 'handle_create']);
        add_action('admin_post_' . self::ACTION_DELETE, [$this, 'handle_delete']);
    }

    public function render(): void
    {
        $rows = $this->campaigns->list_all();
        $create_url = wp_nonce_url(
            add_query_arg(['action' => self::ACTION_CREATE], admin_url('admin-post.php')),
            self::ACTION_CREATE
        );
        ?>
        <section class="lrob-etk-nl-campaigns">
            <header class="lrob-etk-nl-resource-head">
                <h2 class="lrob-etk-section-title"><?php esc_html_e('Campaigns', 'lrob-email-toolkit'); ?></h2>
                <a href="<?php echo esc_url($create_url); ?>" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <?php esc_html_e('New campaign', 'lrob-email-toolkit'); ?>
                </a>
            </header>
            <p class="lrob-etk-nl-resource-intro">
                <?php esc_html_e('Compose campaigns in the block editor. Sending lands in a coming release — drafts and scheduled campaigns sit here until then.', 'lrob-email-toolkit'); ?>
            </p>

            <?php if ($rows === []) : ?>
                <p class="lrob-etk-nl-resource-empty">
                    <?php esc_html_e('No campaigns yet. Click "New campaign" to start one.', 'lrob-email-toolkit'); ?>
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
        $status = (string) ($row['status'] ?? CampaignRepository::STATUS_DRAFT);
        $sent = (int) ($row['sent_count'] ?? 0);
        $total = (int) ($row['total_recipients'] ?? 0);
        $modified = (string) ($row['post_modified_gmt'] ?? '');

        $edit_url = get_edit_post_link($post_id);
        $delete_url = wp_nonce_url(
            add_query_arg(['action' => self::ACTION_DELETE, 'post' => $post_id], admin_url('admin-post.php')),
            self::ACTION_DELETE . '_' . $post_id
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
                <a href="<?php echo esc_url($delete_url); ?>"
                   class="lrob-etk-nl-row-action is-danger"
                   onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Delete this campaign? This cannot be undone.', 'lrob-email-toolkit'))); ?>);">
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
            'post_type'    => CampaignCPT::POST_TYPE,
            'post_status'  => 'draft',
            'post_title'   => __('Untitled campaign', 'lrob-email-toolkit'),
            'post_content' => '',
        ], true);
        if (is_wp_error($new_id) || !is_int($new_id) || $new_id <= 0) {
            wp_die(esc_html__('Could not create the campaign.', 'lrob-email-toolkit'));
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
        if (!$post instanceof \WP_Post || $post->post_type !== CampaignCPT::POST_TYPE) {
            wp_die(esc_html__('Campaign not found.', 'lrob-email-toolkit'));
        }
        wp_delete_post($post_id, true);
        wp_safe_redirect(add_query_arg(
            ['page' => PageController::SLUG, 'view' => HomePage::VIEW_CAMPAIGNS],
            admin_url('admin.php')
        ));
        exit;
    }

    private static function translate_status(string $status): string
    {
        return match ($status) {
            CampaignRepository::STATUS_DRAFT     => __('Draft', 'lrob-email-toolkit'),
            CampaignRepository::STATUS_SCHEDULED => __('Scheduled', 'lrob-email-toolkit'),
            CampaignRepository::STATUS_SENDING   => __('Sending', 'lrob-email-toolkit'),
            CampaignRepository::STATUS_PAUSED    => __('Paused', 'lrob-email-toolkit'),
            CampaignRepository::STATUS_SENT      => __('Sent', 'lrob-email-toolkit'),
            CampaignRepository::STATUS_FAILED    => __('Failed', 'lrob-email-toolkit'),
            CampaignRepository::STATUS_ABORTED   => __('Aborted', 'lrob-email-toolkit'),
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
