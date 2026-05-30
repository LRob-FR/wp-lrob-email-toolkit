<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Admin\Combobox;
use LRob\EmailToolkit\Admin\StatusPill;
use LRob\EmailToolkit\Admin\Tooltip;
use LRob\EmailToolkit\Container;
use LRob\EmailToolkit\Modules\Newsletter\ListRepository;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendCron;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendLoop;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository as SMTPIdentityRepository;

/**
 * Renders one newsletter card (settings + audience picker + send
 * pipeline UI) for the Newsletters list. Split out of NewslettersPage
 * to keep that file focused on page chrome + admin-post handlers.
 *
 * Docs: docs/newsletter-internals.md
 */
final class NewsletterCardRenderer
{
    public function __construct(
        private ListRepository $lists,
        private Container $container,
    ) {
    }

    /**
     * Render a span carrying `data-relative-to="<unix>"` so the JS
     * clock-tick can refresh its text every few seconds without a
     * server round-trip. Server-rendered text is the WP `human_time_diff`
     * value (correct on page load); the JS swaps in its own formatter
     * that mirrors the same buckets, with second-level granularity
     * added (human_time_diff floors anything under a minute to "1 min").
     */
    private static function relative_span(int $ts, int $now): string
    {
        return sprintf(
            '<span data-relative-to="%d">%s</span>',
            $ts,
            esc_html(human_time_diff($ts, $now))
        );
    }

    /** @param array<string, mixed> $row */
    public function render(array $row, array $lists, array $identities, string $tab): void
    {
        $post_id = (int) ($row['post_id'] ?? 0);
        $title = (string) ($row['post_title'] ?? '');
        $status = (string) ($row['status'] ?? NewsletterRepository::STATUS_DRAFT);
        $is_trashed = (string) ($row['wp_status'] ?? '') === 'trash';
        $pause_reason = (string) ($row['pause_reason'] ?? '');
        $sent = (int) ($row['sent_count'] ?? 0);
        $failed = (int) ($row['failed_count'] ?? 0);
        $total = (int) ($row['total_recipients'] ?? 0);
        $opens_unique = (int) ($row['opens_unique'] ?? 0);
        $clicks_unique = (int) ($row['clicks_unique'] ?? 0);
        $created = (string) ($row['post_date_gmt'] ?? '');

        $preview_text  = (string) get_post_meta($post_id, NewsletterCPT::META_PREVIEW_TEXT, true);
        $from_name     = (string) get_post_meta($post_id, NewsletterCPT::META_FROM_NAME_OVERRIDE, true);
        $reply_to      = (string) get_post_meta($post_id, NewsletterCPT::META_REPLY_TO_OVERRIDE, true);
        $identity_id   = (int) get_post_meta($post_id, NewsletterCPT::META_SMTP_IDENTITY, true);
        $target_raw    = (string) get_post_meta($post_id, NewsletterCPT::META_TARGET_SPEC, true);
        $target        = $target_raw !== '' ? (array) json_decode($target_raw, true) : [];
        $target_kind   = (string) ($target['kind'] ?? NewsletterCPT::TARGET_KIND_ALL);
        $target_list_id = (int) ($target['list_id'] ?? 0);
        $scheduled_at  = (string) get_post_meta($post_id, NewsletterCPT::META_SCHEDULED_AT, true);
        $scheduled_local = '';
        if ($scheduled_at !== '') {
            $ts = strtotime($scheduled_at . ' UTC');
            if ($ts !== false) {
                $scheduled_local = (string) wp_date('Y-m-d\TH:i', $ts);
            }
        }
        $track_opens = get_post_meta($post_id, NewsletterCPT::META_TRACK_OPENS, true);
        $track_opens = $track_opens === '' ? true : (bool) $track_opens;
        $track_clicks = get_post_meta($post_id, NewsletterCPT::META_TRACK_CLICKS, true);
        $track_clicks = $track_clicks === '' ? true : (bool) $track_clicks;
        $log_all = (bool) get_post_meta($post_id, NewsletterCPT::META_LOG_ALL_SENDS, true);

        $edit_url = get_edit_post_link($post_id);
        $is_draft = $status === NewsletterRepository::STATUS_DRAFT;
        $is_sending = $status === NewsletterRepository::STATUS_SENDING;
        $is_paused = $status === NewsletterRepository::STATUS_PAUSED;
        $is_terminal = in_array($status, [
            NewsletterRepository::STATUS_SENT,
            NewsletterRepository::STATUS_FAILED,
            NewsletterRepository::STATUS_ABORTED,
        ], true);
        $is_locked = $is_sending || $is_paused || $is_terminal || $is_trashed;
        $open_pct = $sent > 0 ? (int) round(($opens_unique * 100) / $sent) : 0;
        $click_pct = $sent > 0 ? (int) round(($clicks_unique * 100) / $sent) : 0;
        // Synthetic "trash" status drives the badge label + the
        // `data-status` selector the card-poller uses. The companion
        // row's real status is preserved in $status for restore-time
        // decisions.
        $effective_status = $is_trashed ? 'trashed' : $status;

        // Delete/trash URLs (used by the header trash icon-btn). Trashing is
        // forbidden mid-send — admin must abort first.
        $shown_title_attr = $title !== '' ? $title : __('(untitled)', 'lrob-email-toolkit');
        $can_trash = !$is_sending && !$is_paused;
        if ($is_trashed) {
            $delete_action_url = wp_nonce_url(
                add_query_arg(['action' => NewslettersPage::ACTION_DELETE_PERMANENT, 'post' => $post_id], admin_url('admin-post.php')),
                NewslettersPage::ACTION_DELETE_PERMANENT . '_' . $post_id
            );
            $delete_mode = 'permanent';
        } else {
            $delete_action_url = wp_nonce_url(
                add_query_arg(['action' => NewslettersPage::ACTION_DELETE, 'post' => $post_id], admin_url('admin-post.php')),
                NewslettersPage::ACTION_DELETE . '_' . $post_id
            );
            $delete_mode = 'trash';
        }
        ?>
        <article class="lrob-etk-card lrob-etk-nl-card<?php echo $is_trashed ? ' is-trashed' : ''; ?><?php echo (!$is_trashed && $status === NewsletterRepository::STATUS_SENT) ? ' lrob-etk-is-dimmed' : ''; ?><?php echo ($_GET['created'] ?? '') === (string) $post_id ? ' is-just-created' : ''; ?>" data-newsletter-status="<?php echo esc_attr((string) ($row['status'] ?? 'draft')); ?>"
                 data-newsletter-id="<?php echo $post_id; ?>"
                 data-status="<?php echo esc_attr($effective_status); ?>"
                 id="newsletter-<?php echo $post_id; ?>">
            <div class="lrob-etk-card-form">
                <header class="lrob-etk-card-form-head">
                    <div class="lrob-etk-nl-card-title-wrap">
                        <div class="lrob-etk-nl-card-title-row">
                            <span class="lrob-etk-nl-card-title-label"><?php esc_html_e('Subject', 'lrob-email-toolkit'); ?></span>
                            <span class="lrob-etk-nl-status <?php echo esc_attr(StatusPill::state_class($effective_status)); ?>"
                                  data-send-status
                                  <?php echo ($is_draft && !$is_trashed) ? 'hidden' : ''; ?>>
                                <?php echo esc_html(self::translate_status($effective_status)); ?>
                            </span>
                        </div>
                        <input type="text"
                               name="title"
                               class="lrob-etk-title-input lrob-etk-nl-field"
                               data-key="title"
                               value="<?php echo esc_attr($title); ?>"
                               placeholder="<?php esc_attr_e('Subject — used as both the title and the email\'s subject line', 'lrob-email-toolkit'); ?>"
                               autocomplete="off"
                               <?php disabled($is_locked); ?>>
                    </div>
                    <span class="lrob-etk-card-status" aria-live="polite"></span>
                </header>

                <?php if ($is_sending || $is_paused || ($total > 0 && !$is_terminal)) : ?>
                    <div class="lrob-etk-nl-send-progress" data-send-progress>
                        <div class="lrob-etk-nl-send-progress-bar">
                            <div class="lrob-etk-nl-send-progress-fill" data-progress-fill
                                 style="width: <?php echo $total > 0 ? esc_attr((string) min(100, (int) round(($sent + $failed) * 100 / max(1, $total)))) : '0'; ?>%"></div>
                        </div>
                        <p class="lrob-etk-nl-send-progress-text">
                            <span data-progress-sent><?php echo (int) $sent; ?></span> /
                            <span data-progress-total><?php echo (int) $total; ?></span>
                            <?php esc_html_e('sent', 'lrob-email-toolkit'); ?>,
                            <span data-progress-failed><?php echo (int) $failed; ?></span> <?php esc_html_e('failed', 'lrob-email-toolkit'); ?>
                        </p>
                    </div>
                <?php endif; ?>


                <?php
                // Resolve the "automatic" identity label so the dropdown
                // shows which identity would actually be used when the
                // admin leaves this on automatic. Falls back to a plain
                // "(automatic)" if SMTP isn't enabled or no default is
                // configured.
                $default_identity_label = $this->default_identity_label();
                $automatic_label = $default_identity_label !== ''
                    ? sprintf(
                        /* translators: %s: label of the SMTP identity used when no override is picked */
                        __('Automatic (%s)', 'lrob-email-toolkit'),
                        $default_identity_label
                    )
                    : __('Automatic (no default configured)', 'lrob-email-toolkit');

                $identity_options = [
                    ['value' => '0', 'label' => $automatic_label],
                ];
                foreach ($identities as $opt) {
                    $identity_options[] = ['value' => (string) $opt['id'], 'label' => (string) $opt['label']];
                }
                // Quick-link URL to the SMTP management screen — admin
                // jumps there to add a missing identity without losing
                // context.
                $smtp_admin_url = admin_url('admin.php?page=lrob-etk-smtp');
                ?>
                <fieldset class="lrob-etk-nl-card-settings" <?php disabled($is_locked); ?>>
                    <div class="lrob-etk-field">
                        <label>
                            <?php esc_html_e('Short description (preview text)', 'lrob-email-toolkit'); ?>
                            <?php
                            Tooltip::render(
                                __('The snippet shown next to the subject in the recipient\'s inbox. Most email clients show the first ~90 characters. Used to entice the recipient to open — write a teaser, not a recap. Some clients show none of this; treat it as a bonus, not a guaranteed surface.', 'lrob-email-toolkit')
                            );
                            ?>
                        </label>
                        <input type="text" class="lrob-etk-nl-field"
                               data-key="<?php echo esc_attr(NewsletterCPT::META_PREVIEW_TEXT); ?>"
                               value="<?php echo esc_attr($preview_text); ?>" maxlength="200"
                               placeholder="<?php esc_attr_e('Inbox preview snippet (~90 chars visible)', 'lrob-email-toolkit'); ?>">
                    </div>

                    <div class="lrob-etk-field">
                        <label>
                            <?php esc_html_e('SMTP identity', 'lrob-email-toolkit'); ?>
                            <?php
                            Tooltip::render(
                                __('The mailbox this newsletter goes out from. From-name, From-email, and Reply-to are all controlled by the picked identity — to change any of those, edit the identity itself rather than per-newsletter.', 'lrob-email-toolkit')
                            );
                            ?>
                            <a href="<?php echo esc_url($smtp_admin_url); ?>" class="lrob-etk-nl-field-link">
                                <?php esc_html_e('Manage identities →', 'lrob-email-toolkit'); ?>
                            </a>
                        </label>
                        <?php
                        Combobox::render_fixed_select(
                            NewsletterCPT::META_SMTP_IDENTITY,
                            (string) $identity_id,
                            $identity_options,
                            '0',
                            'lrob-etk-nl-field'
                        );
                        ?>
                    </div>

                    <?php
                    // Recipient block — picker for editable cards,
                    // stats-only for terminal ones. Audience + Recipients
                    // collapsed into a single field (the picker IS the
                    // audience definition; the count is the result).
                    $target = $target_raw !== '' ? (array) json_decode($target_raw, true) : [];
                    $target_list_ids = isset($target['list_ids']) && is_array($target['list_ids'])
                        ? array_values(array_unique(array_map('intval', $target['list_ids'])))
                        : [];
                    // Back-compat: single legacy list_id rolls into list_ids[].
                    if ($target_list_ids === [] && $target_kind === NewsletterCPT::TARGET_KIND_LIST && $target_list_id > 0) {
                        $target_list_ids = [$target_list_id];
                    }
                    $list_counts = $this->lists->member_counts();
                    $list_opted_out = $this->lists->opted_out_counts_per_list();
                    $by_id = [];
                    foreach ($lists as $l) { $by_id[(int) ($l['id'] ?? 0)] = $l; }
                    // Provider registry (for the badge label on rule-
                    // based users-kind lists, matching the Lists modal).
                    $rule_providers = \LRob\EmailToolkit\Modules\Newsletter\Lists\RuleRegistry::all();
                    // Group lists by kind, system rows pushed to the
                    // end of each group (consistent with the Lists
                    // modal ordering goal).
                    $subs_lists = [];
                    $user_lists = [];
                    $subs_system = [];
                    $user_system = [];
                    foreach ($lists as $list) {
                        $k = ListRepository::kind_of($list);
                        $is_sys = ListRepository::is_system($list);
                        if ($k === ListRepository::KIND_ALL_SUBSCRIBERS || $k === ListRepository::KIND_SUBSCRIBERS) {
                            $is_sys ? $subs_system[] = $list : $subs_lists[] = $list;
                        } elseif ($k === ListRepository::KIND_USERS) {
                            $is_sys ? $user_system[] = $list : $user_lists[] = $list;
                        }
                    }
                    $subs_lists = array_merge($subs_lists, $subs_system);
                    $user_lists = array_merge($user_lists, $user_system);
                    ?>
                    <?php if ($is_terminal) : ?>
                        <div class="lrob-etk-field lrob-etk-nl-card-recipients">
                            <label><?php esc_html_e('Stats', 'lrob-email-toolkit'); ?></label>
                            <div class="lrob-etk-nl-card-recipients-body">
                                <div class="lrob-etk-nl-card-recipients-head">
                                    <span class="lrob-etk-nl-card-recipients-count" data-recipients-count><?php echo esc_html(number_format_i18n($total)); ?></span>
                                    <span class="lrob-etk-nl-card-recipients-label"><?php esc_html_e('targeted', 'lrob-email-toolkit'); ?></span>
                                    <a href="#" class="lrob-etk-nl-card-recipients-show" data-card-recipients role="button">
                                        <?php esc_html_e('Show list', 'lrob-email-toolkit'); ?>
                                    </a>
                                </div>
                                <?php if ($total > 0) : ?>
                                    <ul class="lrob-etk-nl-card-recipients-stats">
                                        <li>
                                            <strong><?php echo esc_html(number_format_i18n($sent)); ?></strong>
                                            <?php esc_html_e('sent', 'lrob-email-toolkit'); ?>
                                        </li>
                                        <?php if ($failed > 0) : ?>
                                            <li class="is-warn">
                                                <strong><?php echo esc_html(number_format_i18n($failed)); ?></strong>
                                                <?php esc_html_e('failed', 'lrob-email-toolkit'); ?>
                                            </li>
                                        <?php endif; ?>
                                        <li>
                                            <strong><?php echo esc_html(number_format_i18n($opens_unique)); ?></strong>
                                            <?php if ($sent > 0 && $opens_unique > 0) {
                                                printf(
                                                    /* translators: %d: open rate as integer percent */
                                                    esc_html__('opens (%d%%)', 'lrob-email-toolkit'),
                                                    $open_pct
                                                );
                                            } else {
                                                esc_html_e('opens', 'lrob-email-toolkit');
                                            } ?>
                                        </li>
                                        <li>
                                            <strong><?php echo esc_html(number_format_i18n($clicks_unique)); ?></strong>
                                            <?php if ($sent > 0 && $clicks_unique > 0) {
                                                printf(
                                                    /* translators: %d: click-through rate as integer percent */
                                                    esc_html__('clicks (%d%%)', 'lrob-email-toolkit'),
                                                    $click_pct
                                                );
                                            } else {
                                                esc_html_e('clicks', 'lrob-email-toolkit');
                                            } ?>
                                        </li>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="lrob-etk-field">
                            <label>
                                <?php esc_html_e('Recipients', 'lrob-email-toolkit'); ?>
                                <button type="button"
                                        class="lrob-etk-nl-field-link lrob-etk-nl-open-lists-modal"
                                        title="<?php esc_attr_e('Manage lists', 'lrob-email-toolkit'); ?>">
                                    <?php esc_html_e('Manage lists →', 'lrob-email-toolkit'); ?>
                                </button>
                            </label>
                            <div class="lrob-etk-nl-audience"
                                 data-audience-picker
                                 data-newsletter-id="<?php echo (int) $post_id; ?>"
                                 data-audience-action="lrob_etk_nl_newsletter_save_meta"
                                 data-audience-key="target_list_ids"
                                 data-audience-id-param="newsletter_id"
                                 data-audience-id="<?php echo (int) $post_id; ?>"
                                 data-audience-saved-event="lrob-etk-nl-saved"
                                 data-audience-saved-id-key="newsletterId"
                                 data-audience-nonce="<?php echo esc_attr(wp_create_nonce(AjaxController::NONCE_ACTION)); ?>"
                                 data-audience-ajax-url="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>"
                                 data-audience-empty-label="<?php esc_attr_e('no list picked — pick one to send', 'lrob-email-toolkit'); ?>">
                                <button type="button"
                                        class="lrob-etk-nl-audience-trigger"
                                        data-audience-toggle
                                        aria-haspopup="true"
                                        aria-expanded="false">
                                    <span class="lrob-etk-nl-audience-summary">
                                        <strong data-recipients-count>—</strong>
                                        <span class="lrob-etk-nl-audience-summary-label"><?php esc_html_e('recipients', 'lrob-email-toolkit'); ?></span>
                                        <span class="lrob-etk-nl-audience-summary-optout" data-recipients-optout hidden></span>
                                        <span class="lrob-etk-nl-audience-summary-bypass" data-recipients-bypass hidden><?php esc_html_e('(opt-outs bypassed)', 'lrob-email-toolkit'); ?></span>
                                        <em data-audience-lists-summary class="lrob-etk-nl-audience-summary-lists">
                                            <?php
                                            if ($target_list_ids === []) {
                                                esc_html_e('no list picked — pick one to send', 'lrob-email-toolkit');
                                            } else {
                                                echo esc_html(self::summarize_picked_lists($target_list_ids, $by_id));
                                            }
                                            ?>
                                        </em>
                                    </span>
                                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                                </button>
                                <div class="lrob-etk-nl-audience-menu" data-audience-menu hidden role="menu">
                                    <?php
                                    $render_section = function (string $title, array $section_lists) use ($list_counts, $list_opted_out, $target_list_ids, $rule_providers): void {
                                        if ($section_lists === []) return;
                                        ?>
                                        <div class="lrob-etk-nl-audience-section">
                                            <h4 class="lrob-etk-nl-audience-section-title"><?php echo esc_html($title); ?></h4>
                                            <ul class="lrob-etk-nl-audience-list">
                                                <?php foreach ($section_lists as $list) :
                                                    $lid = (int) ($list['id'] ?? 0);
                                                    if ($lid <= 0) continue;
                                                    $cnt = (int) ($list_counts[$lid] ?? 0);
                                                    $opted_out = (int) ($list_opted_out[$lid] ?? 0);
                                                    $checked = in_array($lid, $target_list_ids, true);
                                                    $is_sys = ListRepository::is_system($list);
                                                    $rule = ListRepository::decode_rule((string) ($list['rule_json'] ?? ''));
                                                    $provider_slug = $rule['provider'] ?? '';
                                                    ?>
                                                    <li class="lrob-etk-nl-audience-item">
                                                        <label>
                                                            <input type="checkbox" data-audience-list="<?php echo $lid; ?>" <?php checked($checked); ?>>
                                                            <span class="lrob-etk-nl-audience-item-name"><?php echo esc_html((string) ($list['name'] ?? '')); ?></span>
                                                            <?php if ($is_sys) : ?>
                                                                <span class="lrob-etk-nl-list-system-badge"
                                                                      title="<?php esc_attr_e('Built-in list — cannot be renamed or deleted.', 'lrob-email-toolkit'); ?>">
                                                                    <?php esc_html_e('System', 'lrob-email-toolkit'); ?>
                                                                </span>
                                                            <?php endif; ?>
                                                            <?php if ($provider_slug !== '' && isset($rule_providers[$provider_slug])) : ?>
                                                                <span class="lrob-etk-nl-list-provider-badge"
                                                                      title="<?php echo esc_attr($rule_providers[$provider_slug]->label()); ?>">
                                                                    <?php echo esc_html($rule_providers[$provider_slug]->label()); ?>
                                                                </span>
                                                            <?php endif; ?>
                                                            <span class="lrob-etk-nl-audience-item-counts">
                                                                <?php if ($opted_out > 0) : ?>
                                                                    <span class="lrob-etk-nl-audience-item-optout" title="<?php esc_attr_e('Matching users who opted out — not sent unless you bypass opt-outs for this newsletter.', 'lrob-email-toolkit'); ?>">
                                                                        <?php
                                                                        printf(
                                                                            /* translators: %s: number of opted-out users (already formatted). */
                                                                            esc_html__('−%s opt-out', 'lrob-email-toolkit'),
                                                                            esc_html(number_format_i18n($opted_out))
                                                                        );
                                                                        ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                                <span class="lrob-etk-nl-audience-item-count" title="<?php esc_attr_e('Opted-in members reachable in this list.', 'lrob-email-toolkit'); ?>">
                                                                    <?php echo esc_html(number_format_i18n($cnt)); ?>
                                                                </span>
                                                            </span>
                                                        </label>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                        <?php
                                    };
                                    $render_section(__('Subscribers lists', 'lrob-email-toolkit'), $subs_lists);
                                    $render_section(__('WP users lists', 'lrob-email-toolkit'), $user_lists);
                                    ?>
                                </div>
                                <a href="#" class="lrob-etk-nl-card-recipients-show" data-card-recipients role="button">
                                    <?php esc_html_e('Show list', 'lrob-email-toolkit'); ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="lrob-etk-nl-card-schedule" data-schedule-block>
                        <label class="lrob-etk-nl-card-check">
                            <input type="checkbox" data-schedule-toggle <?php checked($scheduled_local !== ''); ?>>
                            <?php esc_html_e('Schedule send for later', 'lrob-email-toolkit'); ?>
                        </label>
                        <div class="lrob-etk-field lrob-etk-nl-card-schedule-input" data-schedule-input <?php echo $scheduled_local !== '' ? '' : 'hidden'; ?>>
                            <label><?php esc_html_e('Send at', 'lrob-email-toolkit'); ?></label>
                            <input type="datetime-local" class="lrob-etk-nl-field"
                                   data-key="<?php echo esc_attr(NewsletterCPT::META_SCHEDULED_AT); ?>"
                                   value="<?php echo esc_attr($scheduled_local); ?>">
                        </div>
                    </div>

                    <div class="lrob-etk-nl-card-checks">
                        <label class="lrob-etk-nl-card-check">
                            <input type="checkbox" class="lrob-etk-nl-field"
                                   data-key="<?php echo esc_attr(NewsletterCPT::META_TRACK_OPENS); ?>"
                                   value="1" <?php checked($track_opens); ?>>
                            <?php esc_html_e('Track opens', 'lrob-email-toolkit'); ?>
                        </label>
                        <label class="lrob-etk-nl-card-check">
                            <input type="checkbox" class="lrob-etk-nl-field"
                                   data-key="<?php echo esc_attr(NewsletterCPT::META_TRACK_CLICKS); ?>"
                                   value="1" <?php checked($track_clicks); ?>>
                            <?php esc_html_e('Track clicks', 'lrob-email-toolkit'); ?>
                        </label>
                        <label class="lrob-etk-nl-card-check">
                            <input type="checkbox" class="lrob-etk-nl-field"
                                   data-key="<?php echo esc_attr(NewsletterCPT::META_LOG_ALL_SENDS); ?>"
                                   value="1" <?php checked($log_all); ?>>
                            <?php esc_html_e('Log every send', 'lrob-email-toolkit'); ?>
                        </label>
                    </div>
                </fieldset>

                <div class="lrob-etk-nl-card-actions">
                    <?php if ($is_locked) : ?>
                        <button type="button" class="button" disabled
                                title="<?php echo esc_attr($is_trashed
                                    ? __('Restore this newsletter to edit its content.', 'lrob-email-toolkit')
                                    : __('Content is locked once the newsletter has been sent or is being sent. Duplicate to start a new one.', 'lrob-email-toolkit')); ?>">
                            <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                            <?php esc_html_e('Content', 'lrob-email-toolkit'); ?>
                        </button>
                    <?php else : ?>
                        <a href="<?php echo esc_url((string) $edit_url); ?>" class="button">
                            <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                            <?php esc_html_e('Content', 'lrob-email-toolkit'); ?>
                        </a>
                    <?php endif; ?>
                    <button type="button" class="button" data-card-preview>
                        <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                        <?php esc_html_e('Preview', 'lrob-email-toolkit'); ?>
                    </button>
                </div>

                <?php
                // Test + Send cluster — captured into a string, emitted in the
                // footer below so it shares the bottom row with Duplicate/Trash.
                ob_start();
                ?>
                    <button type="button" class="button" data-card-test
                            <?php echo ($is_terminal || $is_sending || $is_trashed) ? 'disabled' : ''; ?>
                            <?php echo ($is_terminal || $is_sending) ? 'title="' . esc_attr__('Test sends are disabled once the newsletter is sending or done. Duplicate to start a new one.', 'lrob-email-toolkit') . '"' : ($is_trashed ? 'title="' . esc_attr__('Restore this newsletter to send tests.', 'lrob-email-toolkit') . '"' : ''); ?>>
                        <span class="dashicons dashicons-email" aria-hidden="true"></span>
                        <?php esc_html_e('Test', 'lrob-email-toolkit'); ?>
                    </button>
                    <?php if (!$is_trashed) : ?>
                    <?php
                    // Three button states share the primary CTA slot:
                    //  - committed + future schedule → "Unschedule" (red);
                    //    clicking flips scheduled → draft.
                    //  - committed + overdue → "Send now"; clicking
                    //    materializes immediately (manual override for
                    //    sites without working pseudo-cron).
                    //  - draft → "Schedule" (when a date is set) or
                    //    "Send now" (no date), both via the existing
                    //    immediate / commit-schedule paths.
                    $sched_ts_for_btn = $scheduled_at !== '' ? strtotime($scheduled_at . ' UTC') : false;
                    $is_overdue_scheduled = $status === NewsletterRepository::STATUS_SCHEDULED
                        && $sched_ts_for_btn !== false
                        && $sched_ts_for_btn <= time();
                    $is_committed_scheduled = $status === NewsletterRepository::STATUS_SCHEDULED && !$is_overdue_scheduled;
                    $has_schedule = ($scheduled_local !== '') && !$is_overdue_scheduled && !$is_committed_scheduled;
                    $shown_title = $title !== '' ? $title : __('(untitled)', 'lrob-email-toolkit');
                    /* translators: %s: newsletter title in confirmation prompt */
                    $confirm_send = sprintf(__('Send "%s" to every targeted recipient? This cannot be undone once it starts.', 'lrob-email-toolkit'), $shown_title);
                    /* translators: %s: newsletter title in confirmation prompt */
                    $confirm_schedule = sprintf(__('Schedule "%s" to be sent at the configured time? You can still edit or cancel it before sending starts.', 'lrob-email-toolkit'), $shown_title);
                    $send_label = $has_schedule
                        ? __('Schedule', 'lrob-email-toolkit')
                        : __('Send now', 'lrob-email-toolkit');
                    ?>
                    <?php if ($is_committed_scheduled) : ?>
                        <button type="button"
                                class="button lrob-etk-nl-send-unschedule"
                                data-send-unschedule>
                            <span class="dashicons dashicons-calendar" aria-hidden="true"></span>
                            <?php esc_html_e('Unschedule', 'lrob-email-toolkit'); ?>
                        </button>
                    <?php else : ?>
                        <button type="button"
                                class="button button-primary"
                                data-send-now
                                data-label-send="<?php echo esc_attr__('Send now', 'lrob-email-toolkit'); ?>"
                                data-label-schedule="<?php echo esc_attr__('Schedule', 'lrob-email-toolkit'); ?>"
                                data-confirm-send="<?php echo esc_attr($confirm_send); ?>"
                                data-confirm-schedule="<?php echo esc_attr($confirm_schedule); ?>"
                                <?php echo ($is_terminal || $is_sending || $is_paused) ? 'disabled' : ''; ?>>
                            <span class="dashicons <?php echo $has_schedule ? 'dashicons-calendar-alt' : 'dashicons-email-alt'; ?>" aria-hidden="true" data-send-icon></span>
                            <span data-send-label><?php echo esc_html($send_label); ?></span>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="button" data-send-pause <?php echo $is_sending ? '' : 'hidden'; ?>>
                        <?php esc_html_e('Pause', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button" class="button button-primary" data-send-resume <?php echo $is_paused ? '' : 'hidden'; ?>>
                        <?php esc_html_e('Resume', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button"
                            class="button lrob-etk-nl-send-abort"
                            data-send-abort
                            data-confirm="<?php echo esc_attr__('Abort this newsletter? Pending recipients will be skipped.', 'lrob-email-toolkit'); ?>"
                            <?php echo ($is_sending || $is_paused) ? '' : 'hidden'; ?>>
                        <?php esc_html_e('Abort', 'lrob-email-toolkit'); ?>
                    </button>
                    <?php if ($failed > 0) :
                        // Re-queue failed rows back to pending. Only meaningful
                        // when there's something to re-queue; shown for both
                        // mid-send (paused) and post-mortem (sent / failed)
                        // states.
                        ?>
                        <button type="button"
                                class="button"
                                data-send-retry-failed
                                data-failed-count="<?php echo (int) $failed; ?>"
                                title="<?php esc_attr_e('Re-queue every failed recipient for another send attempt.', 'lrob-email-toolkit'); ?>">
                            <?php
                            /* translators: %d: number of failed recipients */
                            echo esc_html(sprintf(__('Retry failed (%d)', 'lrob-email-toolkit'), $failed));
                            ?>
                        </button>
                    <?php endif; ?>
                    <?php endif; // !$is_trashed ?>
                <?php $send_actions_html = ob_get_clean(); ?>

                <?php if ($is_paused && $pause_reason === SendLoop::PAUSE_REASON_SMTP_UNHEALTHY) : ?>
                    <div class="lrob-etk-nl-card-banner is-error" role="alert">
                        <strong><?php esc_html_e('SMTP looks unhealthy — sending paused.', 'lrob-email-toolkit'); ?></strong>
                        <span>
                            <?php
                            printf(
                                /* translators: %d: consecutive-failure threshold */
                                esc_html__('We hit %d consecutive failed sends in a row. Fix your SMTP identity, then click Resume — the next tick will re-test and either continue or pause again.', 'lrob-email-toolkit'),
                                (int) SendLoop::CONSECUTIVE_FAILURE_THRESHOLD
                            );
                            ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php
                // Status message under the action row.
                $status_msg = '';
                $status_msg_class = 'info';
                if ($status === NewsletterRepository::STATUS_SENT) {
                    $sent_ts = strtotime(((string) ($row['completed_at'] ?? $row['started_at'] ?? $created)) . ' UTC');
                    if ($sent_ts !== false) {
                        $status_msg = sprintf(
                            /* translators: %1$s: relative time span (e.g. "5 hours"), %2$s: absolute datetime */
                            wp_kses(__('Sent %1$s ago, on %2$s', 'lrob-email-toolkit'), ['span' => ['data-relative-to' => true]]),
                            self::relative_span($sent_ts, time()),
                            (string) wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $sent_ts)
                        );
                    }
                    $status_msg_class = 'on';
                } elseif ($status === NewsletterRepository::STATUS_FAILED) {
                    $status_msg = __('This send failed. Duplicate and retry if needed.', 'lrob-email-toolkit');
                    $status_msg_class = 'fail';
                } elseif ($status === NewsletterRepository::STATUS_ABORTED) {
                    $status_msg = __('This send was aborted.', 'lrob-email-toolkit');
                    $status_msg_class = 'fail';
                } elseif ($scheduled_local !== '' && !$is_terminal && !$is_trashed) {
                    $sched_ts = strtotime($scheduled_at . ' UTC');
                    $sched_pretty = $sched_ts !== false
                        ? (string) wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $sched_ts)
                        : '';
                    if ($sched_ts !== false) {
                        if ($status === NewsletterRepository::STATUS_DRAFT) {
                            // Date saved but the admin hasn't clicked Schedule
                            // yet — the commit step is explicit. The date is
                            // shown by the input, so the message is just the CTA.
                            $status_msg = __('Click Schedule to confirm', 'lrob-email-toolkit');
                            $status_msg_class = 'info';
                        } elseif ($sched_ts > time()) {
                            $status_msg = sprintf(
                                /* translators: %1$s: relative time span (e.g. "2 days"), %2$s: absolute datetime */
                                wp_kses(__('Scheduled to send in %1$s — %2$s', 'lrob-email-toolkit'), ['span' => ['data-relative-to' => true]]),
                                self::relative_span($sched_ts, time()),
                                $sched_pretty
                            );
                        } else {
                            // Overdue and committed. Show *when* the next
                            // cron tick is scheduled — if that's also in
                            // the past, pseudo-cron is stalled (no
                            // traffic / loopback HTTP failing / etc.) and
                            // the admin should know to either click
                            // "Send now" (the button morphs to that
                            // label in the overdue case) or fix the
                            // cron pipeline.
                            $next_tick = wp_next_scheduled(SendCron::CRON_HOOK);
                            // 60s grace: a tick that's a few seconds late
                            // is just normal pseudo-cron timing (one
                            // page-load away), not a stalled cron. Only
                            // flip to the alarming "stalled" message when
                            // the tick is meaningfully late.
                            $stall_threshold = 60;
                            if ($next_tick === false) {
                                $cron_info = __('the cron tick is not scheduled — re-enable the Newsletter module to fix', 'lrob-email-toolkit');
                            } elseif ($next_tick > time()) {
                                $cron_info = sprintf(
                                    /* translators: %s: relative time span until next cron tick (e.g. "30 seconds") */
                                    wp_kses(__('next cron tick in %s', 'lrob-email-toolkit'), ['span' => ['data-relative-to' => true]]),
                                    self::relative_span($next_tick, time())
                                );
                            } elseif ((time() - $next_tick) <= $stall_threshold) {
                                $cron_info = __('cron tick firing imminently', 'lrob-email-toolkit');
                            } else {
                                $cron_info = sprintf(
                                    /* translators: %s: relative time span (how long ago the cron should have run) */
                                    wp_kses(__('cron stalled — the tick was due %s ago, pseudo-cron isn\'t firing on this site', 'lrob-email-toolkit'), ['span' => ['data-relative-to' => true]]),
                                    self::relative_span($next_tick, time())
                                );
                                $status_msg_class = 'fail';
                            }
                            $status_msg = sprintf(
                                /* translators: %1$s: absolute datetime of the scheduled send, %2$s: cron-tick info */
                                __('Scheduled for %1$s (overdue — %2$s).', 'lrob-email-toolkit'),
                                $sched_pretty,
                                $cron_info
                            );
                            if (!isset($status_msg_class) || $status_msg_class === 'info') {
                                $status_msg_class = 'pending';
                            }
                        }
                    }
                }
                ?>

                <footer class="lrob-etk-card-footer">
                    <div class="lrob-etk-nl-card-footer-left">
                        <?php if ($is_trashed) :
                            $restore_url = wp_nonce_url(
                                add_query_arg(['action' => NewslettersPage::ACTION_RESTORE, 'post' => $post_id], admin_url('admin-post.php')),
                                NewslettersPage::ACTION_RESTORE . '_' . $post_id
                            );
                            ?>
                            <a href="<?php echo esc_url($restore_url); ?>" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-nl-card-restore-link"
                               title="<?php esc_attr_e('Restore', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Restore', 'lrob-email-toolkit'); ?>">
                                <span class="dashicons dashicons-undo" aria-hidden="true"></span>
                            </a>
                        <?php else :
                            $duplicate_url = wp_nonce_url(
                                add_query_arg(['action' => NewslettersPage::ACTION_DUPLICATE, 'post' => $post_id], admin_url('admin-post.php')),
                                NewslettersPage::ACTION_DUPLICATE . '_' . $post_id
                            );
                            ?>
                            <a href="<?php echo esc_url($duplicate_url); ?>" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-nl-card-duplicate-link"
                               title="<?php esc_attr_e('Duplicate', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Duplicate', 'lrob-email-toolkit'); ?>">
                                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                            </a>
                        <?php endif; ?>
                        <?php if ($is_trashed || $can_trash) : ?>
                            <button type="button"
                                    class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-icon-btn--danger lrob-etk-nl-card-delete"
                                    data-card-delete
                                    data-delete-mode="<?php echo esc_attr($delete_mode); ?>"
                                    data-newsletter-title="<?php echo esc_attr($shown_title_attr); ?>"
                                    data-delete-url="<?php echo esc_attr($delete_action_url); ?>"
                                    title="<?php echo $is_trashed ? esc_attr__('Delete permanently', 'lrob-email-toolkit') : esc_attr__('Trash', 'lrob-email-toolkit'); ?>"
                                    aria-label="<?php echo $is_trashed ? esc_attr__('Delete permanently', 'lrob-email-toolkit') : esc_attr__('Trash', 'lrob-email-toolkit'); ?>">
                                <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                            </button>
                        <?php else : ?>
                            <button type="button"
                                    class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-nl-card-delete"
                                    disabled
                                    title="<?php esc_attr_e('Abort the send first, then this can be moved to trash.', 'lrob-email-toolkit'); ?>"
                                    aria-label="<?php esc_attr_e('Trash', 'lrob-email-toolkit'); ?>">
                                <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="lrob-etk-nl-card-send-actions">
                        <?php echo $send_actions_html; // phpcs:ignore — pre-built button markup ?>
                    </div>
                </footer>

                <?php // Status note sits at the very bottom, below the action buttons. ?>
                <p class="lrob-etk-nl-card-status-msg lrob-etk-state--<?php echo esc_attr($status_msg_class); ?>" data-status-msg <?php echo $status_msg === '' ? 'hidden' : ''; ?>>
                    <?php
                    // Some branches inject `<span data-relative-to>` for the live
                    // clock-tick — wp_kses allows it through. Plain-text branches
                    // pass through untouched.
                    echo wp_kses($status_msg, ['span' => ['data-relative-to' => true]]);
                    ?>
                </p>
            </div>
        </article>
        <?php
    }

    /**
     * Resolve the label of the SMTP identity that "automatic" routing
     * would actually use. Empty string when SMTP is disabled or no
     * default is configured — the dropdown then says so explicitly.
     */
    private function default_identity_label(): string
    {
        $smtp_repo = $this->container->has(SMTPIdentityRepository::class)
            ? $this->container->get(SMTPIdentityRepository::class)
            : null;
        if (!$smtp_repo instanceof SMTPIdentityRepository) {
            return '';
        }
        $default = $smtp_repo->find_default();
        if ($default === null || !$default->is_active) {
            return '';
        }
        return $default->label !== '' ? $default->label : $default->slug;
    }
    /**
     * Comma-joined names for the audience trigger when one or more
     * lists are picked. CSS `text-overflow: ellipsis` gracefully
     * truncates the rendered text when too many lists overflow the
     * trigger width.
     *
     * @param array<int, int>                       $picked
     * @param array<int, array<string, mixed>>      $by_id
     */
    private static function summarize_picked_lists(array $picked, array $by_id): string
    {
        $names = [];
        foreach ($picked as $lid) {
            if (isset($by_id[$lid])) {
                $names[] = (string) ($by_id[$lid]['name'] ?? '');
            }
        }
        return implode(', ', $names);
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
            'trashed'                              => __('Trashed', 'lrob-email-toolkit'),
            default                              => $status,
        };
    }
}
