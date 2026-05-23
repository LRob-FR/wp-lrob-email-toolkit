<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Combobox;
use LRob\EmailToolkit\Admin\Tooltip;
use LRob\EmailToolkit\Container;
use LRob\EmailToolkit\Modules\Newsletter\CategoryRepository;
use LRob\EmailToolkit\Modules\Newsletter\ListRepository;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendAjaxController;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendLoop;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository as SMTPIdentityRepository;

/**
 * Newsletters list inside the Newsletter hub. Each newsletter renders
 * as a card (mirroring the Contact Form Forms admin pattern) with
 * all settings inline-editable + the send pipeline action buttons
 * right there — no Gutenberg metaboxes, no jumping to another screen
 * for audience / category / sender / send.
 *
 * The Gutenberg editor (post.php) is kept ONLY for composing the
 * newsletter body content. Everything else lives here.
 *
 * Auto-save: inputs carry `lrob-etk-nl-field` + `data-key` and rely
 * on the shared newsletter-admin.js auto-save listener. Status
 * indicator pulses on each card's header.
 *
 * Send pipeline UI: each card has its own progress bar + status
 * badge + Send/Pause/Resume/Abort/Test buttons. Driven by
 * newsletter-cards.js (one global delegation listener, not N
 * per-card inline scripts).
 */
final class NewslettersPage
{
    public const ACTION_CREATE = 'lrob_etk_nl_newsletter_create';

    public const ACTION_DELETE = 'lrob_etk_nl_newsletter_delete';

    public const ACTION_DUPLICATE = 'lrob_etk_nl_newsletter_duplicate';

    public function __construct(
        private NewsletterRepository $newsletters,
        private CategoryRepository $categories,
        private ListRepository $lists,
        private Container $container,
    ) {
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
        $categories = $this->categories->list_all();
        $lists = $this->lists->list_all();
        $identities = $this->available_identities();
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
                <?php esc_html_e('Each newsletter\'s settings, audience, schedule, and send actions live on its card here. Click "Edit content" on a card to compose the body in the block editor.', 'lrob-email-toolkit'); ?>
            </p>

            <?php if ($rows === []) : ?>
                <p class="lrob-etk-nl-resource-empty">
                    <?php esc_html_e('No newsletters yet. Click "New newsletter" to start one.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else : ?>
                <div class="lrob-etk-nl-newsletter-cards">
                    <?php foreach ($rows as $row) : ?>
                        <?php $this->render_card($row, $categories, $lists, $identities); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php $this->render_shared_modals(); ?>
        <?php
    }

    /**
     * Render a single newsletter card. Compact by default: title +
     * status + counters + action buttons + delete icon. Settings + test-
     * send live inside `<details>` panels that collapse the card back
     * to the compact view when closed. Preview / Recipients / Delete
     * trigger modals (rendered once per page below the cards loop).
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $categories
     * @param array<int, array<string, mixed>> $lists
     * @param array<int, array{id:int, label:string}> $identities
     */
    private function render_card(array $row, array $categories, array $lists, array $identities): void
    {
        $post_id = (int) ($row['post_id'] ?? 0);
        $title = (string) ($row['post_title'] ?? '');
        $status = (string) ($row['status'] ?? NewsletterRepository::STATUS_DRAFT);
        $pause_reason = (string) ($row['pause_reason'] ?? '');
        $sent = (int) ($row['sent_count'] ?? 0);
        $failed = (int) ($row['failed_count'] ?? 0);
        $total = (int) ($row['total_recipients'] ?? 0);
        $opens_unique = (int) ($row['opens_unique'] ?? 0);
        $created = (string) ($row['post_date_gmt'] ?? '');

        $preview_text  = (string) get_post_meta($post_id, NewsletterCPT::META_PREVIEW_TEXT, true);
        $from_name     = (string) get_post_meta($post_id, NewsletterCPT::META_FROM_NAME_OVERRIDE, true);
        $reply_to      = (string) get_post_meta($post_id, NewsletterCPT::META_REPLY_TO_OVERRIDE, true);
        $identity_id   = (int) get_post_meta($post_id, NewsletterCPT::META_SMTP_IDENTITY, true);
        $category_id   = (int) get_post_meta($post_id, NewsletterCPT::META_CATEGORY_ID, true);
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
        $is_locked = $is_sending || $is_paused || $is_terminal;
        $open_pct = $sent > 0 ? (int) round(($opens_unique * 100) / $sent) : 0;
        ?>
        <article class="lrob-etk-identity-card lrob-etk-nl-card" data-newsletter-id="<?php echo $post_id; ?>" id="newsletter-<?php echo $post_id; ?>">
            <div class="lrob-etk-card-form">
                <header class="lrob-etk-card-form-head">
                    <div class="lrob-etk-nl-card-title-wrap">
                        <span class="lrob-etk-nl-card-title-label"><?php esc_html_e('Subject', 'lrob-email-toolkit'); ?></span>
                        <input type="text"
                               name="title"
                               class="lrob-etk-title-input lrob-etk-nl-field"
                               data-key="title"
                               value="<?php echo esc_attr($title); ?>"
                               placeholder="<?php esc_attr_e('Subject — used as both the title and the email\'s subject line', 'lrob-email-toolkit'); ?>"
                               autocomplete="off"
                               <?php disabled($is_locked); ?>>
                    </div>
                    <span class="lrob-etk-nl-status lrob-etk-nl-status-<?php echo esc_attr($status); ?>"
                          data-send-status
                          <?php echo $is_draft ? 'hidden' : ''; ?>>
                        <?php echo esc_html(self::translate_status($status)); ?>
                    </span>
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
                $category_options = [];
                if ($categories === []) {
                    $category_options[] = ['value' => '0', 'label' => __('(no categories defined)', 'lrob-email-toolkit')];
                } else {
                    foreach ($categories as $cat) {
                        $category_options[] = ['value' => (string) ($cat['id'] ?? 0), 'label' => (string) ($cat['name'] ?? '')];
                    }
                }

                // Quick-link URLs to the management screens for each
                // resource — admins jump to the right place to add a
                // missing identity / category without losing context.
                $smtp_admin_url = admin_url('admin.php?page=lrob-etk-smtp');
                $categories_admin_url = add_query_arg(
                    ['page' => PageController::SLUG, 'view' => HomePage::VIEW_CATEGORIES],
                    admin_url('admin.php')
                );
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

                    <div class="lrob-etk-modal-columns">
                        <div class="lrob-etk-field">
                            <label>
                                <?php esc_html_e('Category', 'lrob-email-toolkit'); ?>
                                <?php
                                Tooltip::render(
                                    __('Recipients can opt out of specific categories (e.g. product news, weekly digest). This newsletter only reaches recipients who haven\'t opted out of the picked category.', 'lrob-email-toolkit')
                                );
                                ?>
                                <a href="<?php echo esc_url($categories_admin_url); ?>" class="lrob-etk-nl-field-link">
                                    <?php esc_html_e('Edit categories →', 'lrob-email-toolkit'); ?>
                                </a>
                            </label>
                            <?php
                            Combobox::render_fixed_select(
                                NewsletterCPT::META_CATEGORY_ID,
                                (string) $category_id,
                                $category_options,
                                '0',
                                'lrob-etk-nl-field'
                            );
                            ?>
                        </div>
                        <div class="lrob-etk-field">
                            <label>
                                <?php esc_html_e('Sender identity', 'lrob-email-toolkit'); ?>
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
                    </div>

                    <div class="lrob-etk-modal-columns lrob-etk-nl-card-audience-row">
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('Audience', 'lrob-email-toolkit'); ?></label>
                            <?php
                            $kinds = [
                                NewsletterCPT::TARGET_KIND_ALL              => __('All recipients (subscribers + WP users)', 'lrob-email-toolkit'),
                                NewsletterCPT::TARGET_KIND_ALL_SUBSCRIBERS  => __('Subscribers only', 'lrob-email-toolkit'),
                                NewsletterCPT::TARGET_KIND_ALL_USERS        => __('WordPress users only', 'lrob-email-toolkit'),
                                NewsletterCPT::TARGET_KIND_LIST             => __('Specific list', 'lrob-email-toolkit'),
                            ];
                            foreach ($kinds as $value => $label) :
                                ?>
                                <label class="lrob-etk-nl-card-radio">
                                    <input type="radio" class="lrob-etk-nl-field" data-key="target_kind"
                                           name="target_kind_<?php echo $post_id; ?>"
                                           value="<?php echo esc_attr($value); ?>" <?php checked($target_kind, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                            <div class="lrob-etk-nl-card-list-picker" data-target-list-picker>
                                <?php
                                $list_options = [['value' => '0', 'label' => __('— Select a list —', 'lrob-email-toolkit')]];
                                foreach ($lists as $list) {
                                    $list_options[] = ['value' => (string) ($list['id'] ?? 0), 'label' => (string) ($list['name'] ?? '')];
                                }
                                Combobox::render_fixed_select('target_list_id', (string) $target_list_id, $list_options, '0', 'lrob-etk-nl-field');
                                ?>
                            </div>
                        </div>
                        <div class="lrob-etk-field lrob-etk-nl-card-recipients">
                            <label>
                                <?php echo $is_terminal ? esc_html__('Stats', 'lrob-email-toolkit') : esc_html__('Recipients', 'lrob-email-toolkit'); ?>
                            </label>
                            <div class="lrob-etk-nl-card-recipients-body">
                                <div class="lrob-etk-nl-card-recipients-head">
                                    <span class="lrob-etk-nl-card-recipients-count" data-recipients-count><?php echo $is_terminal ? esc_html(number_format_i18n($total)) : '—'; ?></span>
                                    <span class="lrob-etk-nl-card-recipients-label"><?php esc_html_e('targeted', 'lrob-email-toolkit'); ?></span>
                                    <a href="#" class="lrob-etk-nl-card-recipients-show" data-card-recipients role="button">
                                        <?php esc_html_e('Show list', 'lrob-email-toolkit'); ?>
                                    </a>
                                </div>
                                <?php if ($is_terminal && $total > 0) : ?>
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
                                            <?php
                                            if ($sent > 0 && $opens_unique > 0) {
                                                printf(
                                                    /* translators: %d: open rate as integer percent */
                                                    esc_html__('opens (%d%%)', 'lrob-email-toolkit'),
                                                    $open_pct
                                                );
                                            } else {
                                                esc_html_e('opens', 'lrob-email-toolkit');
                                            }
                                            ?>
                                        </li>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

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
                    <?php if ($is_terminal || $is_sending) : ?>
                        <button type="button" class="button" disabled
                                title="<?php esc_attr_e('Content is locked once the newsletter has been sent or is being sent. Duplicate to start a new one.', 'lrob-email-toolkit'); ?>">
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
                    <button type="button" class="button" data-card-test
                            <?php echo ($is_terminal || $is_sending) ? 'disabled' : ''; ?>
                            <?php echo ($is_terminal || $is_sending) ? 'title="' . esc_attr__('Test sends are disabled once the newsletter is sending or done. Duplicate to start a new one.', 'lrob-email-toolkit') . '"' : ''; ?>>
                        <span class="dashicons dashicons-email" aria-hidden="true"></span>
                        <?php esc_html_e('Test', 'lrob-email-toolkit'); ?>
                    </button>
                    <?php
                    $has_schedule = ($scheduled_local !== '');
                    $shown_title = $title !== '' ? $title : __('(untitled)', 'lrob-email-toolkit');
                    /* translators: %s: newsletter title in confirmation prompt */
                    $confirm_send = sprintf(__('Send "%s" to every targeted recipient? This cannot be undone once it starts.', 'lrob-email-toolkit'), $shown_title);
                    /* translators: %s: newsletter title in confirmation prompt */
                    $confirm_schedule = sprintf(__('Schedule "%s" to be sent at the configured time? You can still edit or cancel it before sending starts.', 'lrob-email-toolkit'), $shown_title);
                    $send_label = $has_schedule
                        ? __('Schedule', 'lrob-email-toolkit')
                        : __('Send now', 'lrob-email-toolkit');
                    ?>
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
                </div>

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
                $status_msg_class = 'is-info';
                if ($status === NewsletterRepository::STATUS_SENT) {
                    $sent_ts = strtotime(((string) ($row['completed_at'] ?? $row['started_at'] ?? $created)) . ' UTC');
                    if ($sent_ts !== false) {
                        $status_msg = sprintf(
                            /* translators: %1$s: relative time (e.g. "5 hours"), %2$s: absolute datetime */
                            __('Sent %1$s ago, on %2$s', 'lrob-email-toolkit'),
                            human_time_diff($sent_ts, time()),
                            (string) wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $sent_ts)
                        );
                    }
                    $status_msg_class = 'is-sent';
                } elseif ($status === NewsletterRepository::STATUS_FAILED) {
                    $status_msg = __('This send failed. Duplicate and retry if needed.', 'lrob-email-toolkit');
                    $status_msg_class = 'is-error';
                } elseif ($status === NewsletterRepository::STATUS_ABORTED) {
                    $status_msg = __('This send was aborted.', 'lrob-email-toolkit');
                    $status_msg_class = 'is-error';
                } elseif ($scheduled_local !== '' && !$is_terminal) {
                    $sched_ts = strtotime($scheduled_at . ' UTC');
                    if ($sched_ts !== false) {
                        if ($sched_ts > time()) {
                            $status_msg = sprintf(
                                /* translators: %1$s: relative time until send (e.g. "2 days"), %2$s: absolute datetime */
                                __('Scheduled to send in %1$s — %2$s', 'lrob-email-toolkit'),
                                human_time_diff(time(), $sched_ts),
                                (string) wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $sched_ts)
                            );
                        } else {
                            $status_msg = sprintf(
                                /* translators: %s: absolute datetime */
                                __('Scheduled for %s (now overdue — will send on next click).', 'lrob-email-toolkit'),
                                (string) wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $sched_ts)
                            );
                            $status_msg_class = 'is-warn';
                        }
                    }
                }
                ?>
                <p class="lrob-etk-nl-card-status-msg <?php echo esc_attr($status_msg_class); ?>" data-status-msg <?php echo $status_msg === '' ? 'hidden' : ''; ?>>
                    <?php echo esc_html($status_msg); ?>
                </p>

                <?php
                $delete_url = wp_nonce_url(
                    add_query_arg(['action' => self::ACTION_DELETE, 'post' => $post_id], admin_url('admin-post.php')),
                    self::ACTION_DELETE . '_' . $post_id
                );
                $duplicate_url = wp_nonce_url(
                    add_query_arg(['action' => self::ACTION_DUPLICATE, 'post' => $post_id], admin_url('admin-post.php')),
                    self::ACTION_DUPLICATE . '_' . $post_id
                );
                ?>
                <footer class="lrob-etk-card-footer">
                    <a href="<?php echo esc_url($duplicate_url); ?>" class="lrob-etk-card-delete-link lrob-etk-nl-card-duplicate-link">
                        <?php esc_html_e('Duplicate', 'lrob-email-toolkit'); ?>
                    </a>
                    <button type="button"
                            class="lrob-etk-card-delete-link"
                            data-card-delete
                            data-newsletter-title="<?php echo esc_attr($title !== '' ? $title : __('(untitled)', 'lrob-email-toolkit')); ?>"
                            data-delete-url="<?php echo esc_attr($delete_url); ?>">
                        <?php esc_html_e('Delete', 'lrob-email-toolkit'); ?>
                    </button>
                </footer>
            </div>
        </article>
        <?php
    }

    private function render_shared_modals(): void
    {
        $test_list_id = (int) get_option(SendAjaxController::OPTION_TEST_LIST_ID, 0);
        $test_list = $test_list_id > 0 ? $this->lists->find($test_list_id) : null;
        $self = wp_get_current_user();
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-nl-modal-test" role="dialog" aria-modal="true" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--small">
                <header class="lrob-etk-modal-header">
                    <h3 class="lrob-etk-modal-title-text"><?php esc_html_e('Send test email', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p>
                        <label>
                            <input type="radio" name="lrob_etk_nl_test_target" data-test-target value="self" checked>
                            <?php
                            printf(
                                /* translators: %s: current admin email */
                                esc_html__('Send to me (%s)', 'lrob-email-toolkit'),
                                esc_html((string) ($self->user_email ?? ''))
                            );
                            ?>
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="radio" name="lrob_etk_nl_test_target" data-test-target value="adhoc">
                            <?php esc_html_e('Send to a specific address', 'lrob-email-toolkit'); ?>
                        </label>
                        <span class="lrob-etk-nl-test-adhoc-input" data-test-adhoc-input hidden>
                            <input type="email" data-test-email
                                   placeholder="<?php esc_attr_e('email@example.com', 'lrob-email-toolkit'); ?>"
                                   style="margin-top: 0.25rem; width: 100%;">
                        </span>
                    </p>
                    <?php if ($test_list !== null) : ?>
                        <p>
                            <label>
                                <input type="radio" name="lrob_etk_nl_test_target" data-test-target value="list">
                                <?php
                                printf(
                                    /* translators: %s: configured test list name */
                                    esc_html__('Send to test list (%s)', 'lrob-email-toolkit'),
                                    esc_html((string) ($test_list['name'] ?? ''))
                                );
                                ?>
                            </label>
                        </p>
                    <?php endif; ?>
                    <p class="lrob-etk-nl-send-test-result" data-test-result aria-live="polite"></p>
                </div>
                <footer class="lrob-etk-modal-footer">
                    <button type="button" class="button" data-modal-close><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></button>
                    <button type="button" class="button button-primary" data-test-send>
                        <?php esc_html_e('Send test', 'lrob-email-toolkit'); ?>
                    </button>
                </footer>
            </div>
        </div>

        <div class="lrob-etk-modal" id="lrob-etk-nl-modal-preview" role="dialog" aria-modal="true" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog">
                <header class="lrob-etk-modal-header">
                    <h3 class="lrob-etk-modal-title-text"><?php esc_html_e('Email preview', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <iframe data-preview-iframe sandbox style="width: 100%; min-height: 60vh; border: 1px solid #dcdcde; border-radius: 4px; display: block;"></iframe>
                </div>
            </div>
        </div>

        <div class="lrob-etk-modal" id="lrob-etk-nl-modal-recipients" role="dialog" aria-modal="true" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog">
                <header class="lrob-etk-modal-header">
                    <h3 class="lrob-etk-modal-title-text"><?php esc_html_e('Recipients preview', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body" data-recipients-body>
                    <p class="lrob-etk-nl-recipients-loading"><?php esc_html_e('Computing recipient set…', 'lrob-email-toolkit'); ?></p>
                </div>
            </div>
        </div>

        <div class="lrob-etk-modal" id="lrob-etk-nl-modal-confirm" role="dialog" aria-modal="true" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--small">
                <header class="lrob-etk-modal-header">
                    <h3 class="lrob-etk-modal-title-text" data-confirm-title></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p data-confirm-body></p>
                </div>
                <footer class="lrob-etk-modal-footer">
                    <button type="button" class="button" data-modal-close><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></button>
                    <button type="button" class="button button-primary" data-confirm-ok></button>
                </footer>
            </div>
        </div>

        <div class="lrob-etk-modal" id="lrob-etk-nl-modal-delete" role="dialog" aria-modal="true" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--small">
                <header class="lrob-etk-modal-header">
                    <h3 class="lrob-etk-modal-title-text"><?php esc_html_e('Delete newsletter', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p><?php esc_html_e('Permanently delete this newsletter?', 'lrob-email-toolkit'); ?>
                       <strong data-delete-title></strong></p>
                    <p class="description"><?php esc_html_e('This cannot be undone. Already-sent recipients are not affected.', 'lrob-email-toolkit'); ?></p>
                </div>
                <footer class="lrob-etk-modal-footer">
                    <button type="button" class="button" data-modal-close><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></button>
                    <a href="#" class="button lrob-etk-nl-modal-confirm-danger" data-delete-confirm>
                        <?php esc_html_e('Delete permanently', 'lrob-email-toolkit'); ?>
                    </a>
                </footer>
            </div>
        </div>
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
        // After creating, drop the admin on the newsletters list view
        // with the new card scrolled into focus — the card itself
        // exposes all settings + the Edit-content link, so we no
        // longer auto-jump into Gutenberg.
        wp_safe_redirect(add_query_arg(
            ['page' => PageController::SLUG, 'view' => HomePage::VIEW_NEWSLETTERS],
            admin_url('admin.php')
        ) . '#newsletter-' . $new_id);
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

        wp_safe_redirect(add_query_arg(
            ['page' => PageController::SLUG, 'view' => HomePage::VIEW_NEWSLETTERS],
            admin_url('admin.php')
        ) . '#newsletter-' . $new_id);
        exit;
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
     * @return array<int, array{id:int, label:string, from_name:string, reply_to:string}>
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
                'id'        => (int) $identity->id,
                'label'     => $identity->label !== '' ? $identity->label : $identity->slug,
                'from_name' => (string) $identity->from_name,
                'reply_to'  => (string) ($identity->reply_to_email ?? ''),
            ];
        }
        return $out;
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
