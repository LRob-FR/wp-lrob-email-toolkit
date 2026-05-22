<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Container;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository as SMTPIdentityRepository;

/**
 * Campaign editor side panels (preview text + sender, audience,
 * schedule, tracking). Standard add_meta_box surfaces — matches the
 * codebase's preference for vanilla PHP/JS over JSX/React. Each box
 * renders a wp_nonce_field; the shared save_post hook validates every
 * nonce + writes the meta. The companion-row sync (CampaignRepository
 * ensure_row) runs unconditionally on save so a new campaign always
 * has a draft companion row by the time the admin first edits it.
 *
 * Sender identity picker pulls from the SMTP module's
 * IdentityRepository when SMTP is available; falls back to a
 * "(default routing)" placeholder otherwise. Send pipeline (step 7)
 * is the consumer.
 */
final class CampaignMetaboxes
{
    private const NONCE_FIELD = '_lrob_etk_nl_campaign_nonce';

    private const NONCE_ACTION = 'lrob_etk_nl_campaign_save';

    public function __construct(
        private CampaignRepository $campaigns,
        private CategoryRepository $categories,
        private ListRepository $lists,
        private Container $container,
    ) {
    }

    public function register(): void
    {
        add_action('add_meta_boxes_' . CampaignCPT::POST_TYPE, [$this, 'register_boxes']);
        add_action('save_post_' . CampaignCPT::POST_TYPE, [$this, 'save'], 10, 2);
        add_action('before_delete_post', [$this, 'on_before_delete']);
    }

    public function register_boxes(\WP_Post $post): void
    {
        add_meta_box(
            'lrob-etk-nl-campaign-sender',
            __('Sender & preview', 'lrob-email-toolkit'),
            [$this, 'render_sender_box'],
            CampaignCPT::POST_TYPE,
            'side',
            'high'
        );
        add_meta_box(
            'lrob-etk-nl-campaign-audience',
            __('Audience', 'lrob-email-toolkit'),
            [$this, 'render_audience_box'],
            CampaignCPT::POST_TYPE,
            'normal',
            'high'
        );
        add_meta_box(
            'lrob-etk-nl-campaign-schedule',
            __('Schedule', 'lrob-email-toolkit'),
            [$this, 'render_schedule_box'],
            CampaignCPT::POST_TYPE,
            'side',
            'default'
        );
        add_meta_box(
            'lrob-etk-nl-campaign-tracking',
            __('Tracking', 'lrob-email-toolkit'),
            [$this, 'render_tracking_box'],
            CampaignCPT::POST_TYPE,
            'side',
            'default'
        );
    }

    public function render_sender_box(\WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        $preview_text = (string) get_post_meta($post->ID, CampaignCPT::META_PREVIEW_TEXT, true);
        $from_name = (string) get_post_meta($post->ID, CampaignCPT::META_FROM_NAME_OVERRIDE, true);
        $reply_to = (string) get_post_meta($post->ID, CampaignCPT::META_REPLY_TO_OVERRIDE, true);
        $identity_id = (int) get_post_meta($post->ID, CampaignCPT::META_SMTP_IDENTITY, true);
        $identities = $this->available_identities();
        ?>
        <div class="lrob-etk-nl-campaign-meta">
            <p>
                <label for="lrob-etk-nl-campaign-preview-text"><strong><?php esc_html_e('Preview text', 'lrob-email-toolkit'); ?></strong></label>
                <input type="text"
                       id="lrob-etk-nl-campaign-preview-text"
                       name="lrob_etk_nl_preview_text"
                       value="<?php echo esc_attr($preview_text); ?>"
                       maxlength="200"
                       class="widefat"
                       placeholder="<?php esc_attr_e('Snippet shown in the inbox preview', 'lrob-email-toolkit'); ?>">
                <span class="description"><?php esc_html_e('Up to ~150 characters. Most clients show the first ~90.', 'lrob-email-toolkit'); ?></span>
            </p>
            <p>
                <label for="lrob-etk-nl-campaign-from-name"><strong><?php esc_html_e('From name override', 'lrob-email-toolkit'); ?></strong></label>
                <input type="text"
                       id="lrob-etk-nl-campaign-from-name"
                       name="lrob_etk_nl_from_name_override"
                       value="<?php echo esc_attr($from_name); ?>"
                       class="widefat"
                       placeholder="<?php esc_attr_e('(use identity default)', 'lrob-email-toolkit'); ?>">
            </p>
            <p>
                <label for="lrob-etk-nl-campaign-reply-to"><strong><?php esc_html_e('Reply-to override', 'lrob-email-toolkit'); ?></strong></label>
                <input type="email"
                       id="lrob-etk-nl-campaign-reply-to"
                       name="lrob_etk_nl_reply_to_override"
                       value="<?php echo esc_attr($reply_to); ?>"
                       class="widefat"
                       placeholder="<?php esc_attr_e('(use identity reply-to)', 'lrob-email-toolkit'); ?>">
            </p>
            <p>
                <label for="lrob-etk-nl-campaign-identity"><strong><?php esc_html_e('SMTP identity', 'lrob-email-toolkit'); ?></strong></label>
                <select id="lrob-etk-nl-campaign-identity" name="lrob_etk_nl_smtp_identity_id" class="widefat">
                    <option value="0"><?php esc_html_e('(default routing)', 'lrob-email-toolkit'); ?></option>
                    <?php foreach ($identities as $opt) : ?>
                        <option value="<?php echo (int) $opt['id']; ?>" <?php selected($identity_id, (int) $opt['id']); ?>>
                            <?php echo esc_html($opt['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description"><?php esc_html_e('Pick which sending mailbox this campaign uses. Default routing follows the SMTP module\'s rules.', 'lrob-email-toolkit'); ?></span>
            </p>
        </div>
        <?php
    }

    public function render_audience_box(\WP_Post $post): void
    {
        $category_id = (int) get_post_meta($post->ID, CampaignCPT::META_CATEGORY_ID, true);
        $target_raw = (string) get_post_meta($post->ID, CampaignCPT::META_TARGET_SPEC, true);
        $target = $target_raw !== '' ? (array) json_decode($target_raw, true) : [];
        $target_kind = (string) ($target['kind'] ?? CampaignCPT::TARGET_KIND_ALL);
        $target_list_id = (int) ($target['list_id'] ?? 0);

        $cats = $this->categories->list_all();
        $lists = $this->lists->list_all();
        ?>
        <div class="lrob-etk-nl-campaign-audience">
            <p>
                <label for="lrob-etk-nl-campaign-category"><strong><?php esc_html_e('Category', 'lrob-email-toolkit'); ?></strong></label>
                <select id="lrob-etk-nl-campaign-category" name="lrob_etk_nl_category_id" class="widefat" required>
                    <?php if ($cats === []) : ?>
                        <option value="0"><?php esc_html_e('(no categories defined)', 'lrob-email-toolkit'); ?></option>
                    <?php else : ?>
                        <?php foreach ($cats as $cat) : ?>
                            <option value="<?php echo (int) ($cat['id'] ?? 0); ?>" <?php selected($category_id, (int) ($cat['id'] ?? 0)); ?>>
                                <?php echo esc_html((string) ($cat['name'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <span class="description"><?php esc_html_e('Recipients who opted out of this category are excluded at send time.', 'lrob-email-toolkit'); ?></span>
            </p>

            <fieldset class="lrob-etk-nl-campaign-target">
                <legend><strong><?php esc_html_e('Send to', 'lrob-email-toolkit'); ?></strong></legend>
                <label>
                    <input type="radio" name="lrob_etk_nl_target_kind" value="<?php echo esc_attr(CampaignCPT::TARGET_KIND_ALL); ?>" <?php checked($target_kind, CampaignCPT::TARGET_KIND_ALL); ?>>
                    <?php esc_html_e('All recipients (confirmed subscribers + opted-in WP users)', 'lrob-email-toolkit'); ?>
                </label>
                <label>
                    <input type="radio" name="lrob_etk_nl_target_kind" value="<?php echo esc_attr(CampaignCPT::TARGET_KIND_ALL_SUBSCRIBERS); ?>" <?php checked($target_kind, CampaignCPT::TARGET_KIND_ALL_SUBSCRIBERS); ?>>
                    <?php esc_html_e('All confirmed subscribers (email-only)', 'lrob-email-toolkit'); ?>
                </label>
                <label>
                    <input type="radio" name="lrob_etk_nl_target_kind" value="<?php echo esc_attr(CampaignCPT::TARGET_KIND_ALL_USERS); ?>" <?php checked($target_kind, CampaignCPT::TARGET_KIND_ALL_USERS); ?>>
                    <?php esc_html_e('All opted-in WordPress users', 'lrob-email-toolkit'); ?>
                </label>
                <label>
                    <input type="radio" name="lrob_etk_nl_target_kind" value="<?php echo esc_attr(CampaignCPT::TARGET_KIND_LIST); ?>" <?php checked($target_kind, CampaignCPT::TARGET_KIND_LIST); ?>>
                    <?php esc_html_e('Specific list', 'lrob-email-toolkit'); ?>
                </label>
                <div class="lrob-etk-nl-campaign-list-picker" data-target-list-picker>
                    <select name="lrob_etk_nl_target_list_id" class="widefat">
                        <option value="0"><?php esc_html_e('— Select a list —', 'lrob-email-toolkit'); ?></option>
                        <?php foreach ($lists as $list) : ?>
                            <option value="<?php echo (int) ($list['id'] ?? 0); ?>" <?php selected($target_list_id, (int) ($list['id'] ?? 0)); ?>>
                                <?php echo esc_html((string) ($list['name'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </fieldset>
        </div>
        <script>
        (function () {
            var root = document.querySelector('.lrob-etk-nl-campaign-audience');
            if (!root) return;
            var picker = root.querySelector('[data-target-list-picker]');
            function sync() {
                var sel = root.querySelector('input[name="lrob_etk_nl_target_kind"]:checked');
                var on = sel && sel.value === <?php echo wp_json_encode(CampaignCPT::TARGET_KIND_LIST); ?>;
                picker.style.display = on ? '' : 'none';
            }
            root.addEventListener('change', function (e) {
                if (e.target && e.target.name === 'lrob_etk_nl_target_kind') sync();
            });
            sync();
        })();
        </script>
        <style>
            .lrob-etk-nl-campaign-audience fieldset.lrob-etk-nl-campaign-target { border: 0; padding: 0; margin: 0.75em 0 0; }
            .lrob-etk-nl-campaign-audience fieldset.lrob-etk-nl-campaign-target legend { padding: 0; }
            .lrob-etk-nl-campaign-audience fieldset.lrob-etk-nl-campaign-target label { display: block; padding: 0.2em 0; }
            .lrob-etk-nl-campaign-audience .lrob-etk-nl-campaign-list-picker { padding-left: 1.6em; margin-top: 0.4em; }
        </style>
        <?php
    }

    public function render_schedule_box(\WP_Post $post): void
    {
        $scheduled_at = (string) get_post_meta($post->ID, CampaignCPT::META_SCHEDULED_AT, true);
        $companion = $this->campaigns->find_by_post_id($post->ID);
        $status = (string) ($companion['status'] ?? CampaignRepository::STATUS_DRAFT);
        // Convert UTC stored value to local datetime-local input value.
        $input_value = '';
        if ($scheduled_at !== '') {
            $ts = strtotime($scheduled_at . ' UTC');
            if ($ts !== false) {
                $input_value = (string) wp_date('Y-m-d\TH:i', $ts);
            }
        }
        ?>
        <div class="lrob-etk-nl-campaign-meta">
            <p>
                <strong><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></strong>:
                <span class="lrob-etk-nl-status lrob-etk-nl-status-<?php echo esc_attr($status); ?>">
                    <?php echo esc_html(self::translate_status($status)); ?>
                </span>
            </p>
            <p>
                <label for="lrob-etk-nl-campaign-scheduled-at"><strong><?php esc_html_e('Send at', 'lrob-email-toolkit'); ?></strong></label>
                <input type="datetime-local"
                       id="lrob-etk-nl-campaign-scheduled-at"
                       name="lrob_etk_nl_scheduled_at"
                       value="<?php echo esc_attr($input_value); ?>"
                       class="widefat">
                <span class="description"><?php esc_html_e('Leave blank to send immediately when you trigger Send. (Send pipeline lands in a coming release — this preference is stored now and used then.)', 'lrob-email-toolkit'); ?></span>
            </p>
        </div>
        <?php
    }

    public function render_tracking_box(\WP_Post $post): void
    {
        $existing = (string) get_post_meta($post->ID, CampaignCPT::META_TRACK_OPENS, true);
        $opens = $existing === '' ? true : (bool) $existing;
        $existing_clicks = (string) get_post_meta($post->ID, CampaignCPT::META_TRACK_CLICKS, true);
        $clicks = $existing_clicks === '' ? true : (bool) $existing_clicks;
        $log_all = (bool) get_post_meta($post->ID, CampaignCPT::META_LOG_ALL_SENDS, true);
        ?>
        <div class="lrob-etk-nl-campaign-meta">
            <p>
                <label>
                    <input type="checkbox" name="lrob_etk_nl_track_opens" value="1" <?php checked($opens); ?>>
                    <?php esc_html_e('Track opens', 'lrob-email-toolkit'); ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="lrob_etk_nl_track_clicks" value="1" <?php checked($clicks); ?>>
                    <?php esc_html_e('Track clicks', 'lrob-email-toolkit'); ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="lrob_etk_nl_log_all_sends" value="1" <?php checked($log_all); ?>>
                    <?php esc_html_e('Log every send for this campaign', 'lrob-email-toolkit'); ?>
                </label>
                <span class="description"><?php esc_html_e('Overrides the Logging module\'s default sampling.', 'lrob-email-toolkit'); ?></span>
            </p>
        </div>
        <?php
    }

    /**
     * Combined save handler for every metabox on this CPT. WP fires
     * `save_post_<cpt>` once per save; we use the shared nonce + cap
     * check to gate every meta write below.
     */
    public function save(int $post_id, \WP_Post $post): void
    {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        if ($post->post_type !== CampaignCPT::POST_TYPE) {
            return;
        }
        $nonce = isset($_POST[self::NONCE_FIELD]) ? (string) wp_unslash((string) $_POST[self::NONCE_FIELD]) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            // No nonce = Gutenberg REST save (uses register_meta's
            // own auth_callback). Skip the metabox-write path; the
            // companion-row sync below still runs.
            $this->campaigns->ensure_row($post_id);
            return;
        }
        if (!current_user_can(Activator::CAPABILITY)) {
            return;
        }

        $string_fields = [
            CampaignCPT::META_PREVIEW_TEXT       => 'lrob_etk_nl_preview_text',
            CampaignCPT::META_FROM_NAME_OVERRIDE => 'lrob_etk_nl_from_name_override',
        ];
        foreach ($string_fields as $meta_key => $post_key) {
            $value = isset($_POST[$post_key]) ? sanitize_text_field(wp_unslash((string) $_POST[$post_key])) : '';
            update_post_meta($post_id, $meta_key, $value);
        }

        $reply_to = isset($_POST['lrob_etk_nl_reply_to_override'])
            ? sanitize_email(wp_unslash((string) $_POST['lrob_etk_nl_reply_to_override']))
            : '';
        update_post_meta($post_id, CampaignCPT::META_REPLY_TO_OVERRIDE, $reply_to);

        $identity_id = isset($_POST['lrob_etk_nl_smtp_identity_id'])
            ? (int) wp_unslash((string) $_POST['lrob_etk_nl_smtp_identity_id'])
            : 0;
        update_post_meta($post_id, CampaignCPT::META_SMTP_IDENTITY, $identity_id);

        $category_id = isset($_POST['lrob_etk_nl_category_id'])
            ? (int) wp_unslash((string) $_POST['lrob_etk_nl_category_id'])
            : 0;
        update_post_meta($post_id, CampaignCPT::META_CATEGORY_ID, $category_id);

        $target_kind = isset($_POST['lrob_etk_nl_target_kind'])
            ? sanitize_key(wp_unslash((string) $_POST['lrob_etk_nl_target_kind']))
            : CampaignCPT::TARGET_KIND_ALL;
        $allowed = [
            CampaignCPT::TARGET_KIND_ALL,
            CampaignCPT::TARGET_KIND_ALL_USERS,
            CampaignCPT::TARGET_KIND_ALL_SUBSCRIBERS,
            CampaignCPT::TARGET_KIND_LIST,
        ];
        if (!in_array($target_kind, $allowed, true)) {
            $target_kind = CampaignCPT::TARGET_KIND_ALL;
        }
        $target_spec = ['kind' => $target_kind];
        if ($target_kind === CampaignCPT::TARGET_KIND_LIST) {
            $target_spec['list_id'] = isset($_POST['lrob_etk_nl_target_list_id'])
                ? (int) wp_unslash((string) $_POST['lrob_etk_nl_target_list_id'])
                : 0;
        }
        update_post_meta($post_id, CampaignCPT::META_TARGET_SPEC, (string) wp_json_encode($target_spec));

        $scheduled_raw = isset($_POST['lrob_etk_nl_scheduled_at'])
            ? trim((string) wp_unslash((string) $_POST['lrob_etk_nl_scheduled_at']))
            : '';
        if ($scheduled_raw === '') {
            update_post_meta($post_id, CampaignCPT::META_SCHEDULED_AT, '');
        } else {
            // Input is local time (datetime-local has no tz). Read it
            // through wp_date's clock so the stored UTC matches what
            // the admin typed.
            $ts = strtotime($scheduled_raw . ' ' . wp_timezone_string());
            update_post_meta(
                $post_id,
                CampaignCPT::META_SCHEDULED_AT,
                $ts === false ? '' : gmdate('Y-m-d H:i:s', $ts)
            );
        }

        update_post_meta($post_id, CampaignCPT::META_TRACK_OPENS, !empty($_POST['lrob_etk_nl_track_opens']));
        update_post_meta($post_id, CampaignCPT::META_TRACK_CLICKS, !empty($_POST['lrob_etk_nl_track_clicks']));
        update_post_meta($post_id, CampaignCPT::META_LOG_ALL_SENDS, !empty($_POST['lrob_etk_nl_log_all_sends']));

        // Companion row always exists by the time the editor renders
        // again. ensure_row is idempotent (INSERT IGNORE).
        $this->campaigns->ensure_row($post_id);

        // Reflect scheduled-vs-draft on the companion status if it's
        // still in a pre-send state. Once the send pipeline starts the
        // companion through sending/sent/etc, manual scheduling
        // changes shouldn't yank the row back.
        $row = $this->campaigns->find_by_post_id($post_id);
        $current = (string) ($row['status'] ?? CampaignRepository::STATUS_DRAFT);
        if (in_array($current, [CampaignRepository::STATUS_DRAFT, CampaignRepository::STATUS_SCHEDULED], true)) {
            $next = $scheduled_raw !== ''
                ? CampaignRepository::STATUS_SCHEDULED
                : CampaignRepository::STATUS_DRAFT;
            if ($next !== $current) {
                $this->campaigns->update_status($post_id, $next);
            }
        }
    }

    public function on_before_delete(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || $post->post_type !== CampaignCPT::POST_TYPE) {
            return;
        }
        $this->campaigns->delete_for_post($post_id);
    }

    /**
     * @return array<int, array{id:int, label:string}>
     */
    private function available_identities(): array
    {
        $smtp_repo = $this->container->has(SMTPIdentityRepository::class)
            ? $this->container->get(SMTPIdentityRepository::class)
            : null;
        if (!$smtp_repo instanceof SMTPIdentityRepository) {
            return [];
        }
        $out = [];
        foreach ($smtp_repo->all() as $identity) {
            if (!$identity->is_active) {
                continue;
            }
            $out[] = [
                'id'    => (int) $identity->id,
                'label' => $identity->label !== '' ? $identity->label : $identity->slug,
            ];
        }
        return $out;
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
}
