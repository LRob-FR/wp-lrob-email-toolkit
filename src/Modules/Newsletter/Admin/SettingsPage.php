<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Modules\Newsletter\ReminderCron;
use LRob\EmailToolkit\Modules\Newsletter\Send\NewsletterFooter;
use LRob\EmailToolkit\Modules\Newsletter\TrashCron;

/**
 * Newsletter module settings — central / "see everything" surface
 * that mirrors the contextual settings living on other views. Each
 * settings group has a canonical home (the page where the related
 * feature is managed); the Settings view duplicates the controls
 * for power users who want one screen to tweak everything from.
 *
 * Auto-save endpoint is shared across both surfaces (the same
 * `lrob_etk_nl_setting_save` action), so changes from either page
 * land on the same option keys.
 *
 * Today: only reminder-schedule controls (also rendered on the
 * Onboarding view next to the reminder template). Future module-
 * wide settings (default category, captcha context, throttle map,
 * trash auto-purge, WC integration toggle) join here alongside
 * their contextual homes as they ship.
 */
final class SettingsPage
{
    /** Module-wide Settings modal — opened from the Settings header button
     *  on every Newsletter sub-page (mirror of the CF / Logs Storage modal). */
    public static function render_modal(): void
    {
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-nl-settings-modal" role="dialog" aria-modal="true" aria-labelledby="lrob-etk-nl-settings-title" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--wide">
                <header class="lrob-etk-modal-header">
                    <h3 id="lrob-etk-nl-settings-title" class="lrob-etk-modal-title-text"><?php esc_html_e('Newsletter settings', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p class="description" style="margin-top: 0;">
                        <?php esc_html_e('Module-wide controls. Each section here is mirrored on the page where the related feature is managed — changes flow both ways.', 'lrob-email-toolkit'); ?>
                    </p>
                    <span class="lrob-etk-card-status" aria-live="polite"></span>

                    <?php self::render_reminder_schedule_section(true); ?>
                    <?php self::render_trash_retention_section(); ?>
                    <?php self::render_newsletter_footer_section(); ?>
                    <?php self::render_tracking_section(); ?>
                </div>
            </div>
        </div>
        <script>
        // Wire the Settings header button to open this modal — runs on
        // every Newsletter sub-page since the modal markup is rendered
        // once at the bottom of every view by HomePage.
        (function () {
            if (window.__lrobEtkNlSettingsModalBound) return;
            window.__lrobEtkNlSettingsModalBound = true;
            function whenReady(fn) {
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
                else fn();
            }
            whenReady(function () {
                if (window.lrobEtkModal) {
                    window.lrobEtkModal.bindHeader('lrob-etk-nl-settings-modal', 'lrob-etk-nl-settings-btn');
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Tracking + cold-detection controls (step 9). Three options:
     *
     *  - cold_threshold: how many sends without engagement before a
     *    confirmed subscriber surfaces on the Cold sub-tab. Default 5
     *    — roughly five weekly newsletters with zero engagement is a
     *    fair "they're gone" signal without false-positives on a
     *    monthly schedule.
     *  - engagement_counts_opens: whether open events reset the cold
     *    counter alongside clicks. Default off — Apple MPP server-side
     *    image prefetch inflates open rates to ~100% for Apple Mail
     *    users, so clicks are the reliable proxy for "actually
     *    engaged". Power users with non-Apple-heavy audiences can flip
     *    this on.
     *  - tracking_retention_days: prune tracking_events older than
     *    this. Companion-row aggregate counters and per-subscriber
     *    lifetime stats are kept forever; only per-event detail ages
     *    out. Default 365.
     */
    public static function render_tracking_section(): void
    {
        $cold_threshold = (int) get_option('lrob_etk_nl_cold_threshold', 5);
        $counts_opens   = (bool) get_option('lrob_etk_nl_engagement_counts_opens', false);
        $retention      = (int) get_option('lrob_etk_nl_tracking_retention_days', 365);
        ?>
        <article class="lrob-etk-nl-settings-group">
            <h3 class="lrob-etk-nl-settings-group-title"><?php esc_html_e('Tracking + cold detection', 'lrob-email-toolkit'); ?></h3>
            <p class="lrob-etk-nl-settings-group-intro">
                <?php esc_html_e('Open + click events are recorded per recipient and aggregated into per-newsletter and per-subscriber lifetime stats. Tune cold-subscriber detection here.', 'lrob-email-toolkit'); ?>
            </p>

            <div class="lrob-etk-nl-settings-row">
                <label for="lrob-etk-nl-setting-cold-threshold">
                    <?php esc_html_e('Cold threshold (sends without engagement)', 'lrob-email-toolkit'); ?>
                </label>
                <input type="number"
                       id="lrob-etk-nl-setting-cold-threshold"
                       class="lrob-etk-nl-field"
                       data-key="setting-cold-threshold"
                       data-option-key="lrob_etk_nl_cold_threshold"
                       value="<?php echo esc_attr((string) $cold_threshold); ?>"
                       min="1" max="50" step="1">
                <p class="description"><?php esc_html_e('A confirmed subscriber shows up on the Cold sub-tab after this many sends without engaging. Default: 5.', 'lrob-email-toolkit'); ?></p>
            </div>

            <div class="lrob-etk-nl-settings-row">
                <label for="lrob-etk-nl-setting-engagement-counts-opens">
                    <input type="checkbox"
                           id="lrob-etk-nl-setting-engagement-counts-opens"
                           class="lrob-etk-nl-field"
                           data-key="setting-engagement-counts-opens"
                           data-option-key="lrob_etk_nl_engagement_counts_opens"
                           value="1"
                           <?php checked($counts_opens); ?>>
                    <?php esc_html_e('Count opens as engagement (in addition to clicks)', 'lrob-email-toolkit'); ?>
                </label>
                <p class="description"><?php esc_html_e('Off by default — Apple Mail loads images server-side, which inflates open rates and would mislabel reading recipients as "engaged". Turn on if your audience is mostly non-Apple.', 'lrob-email-toolkit'); ?></p>
            </div>

            <div class="lrob-etk-nl-settings-row">
                <label for="lrob-etk-nl-setting-tracking-retention">
                    <?php esc_html_e('Tracking event retention (days)', 'lrob-email-toolkit'); ?>
                </label>
                <input type="number"
                       id="lrob-etk-nl-setting-tracking-retention"
                       class="lrob-etk-nl-field"
                       data-key="setting-tracking-retention"
                       data-option-key="lrob_etk_nl_tracking_retention_days"
                       value="<?php echo esc_attr((string) $retention); ?>"
                       min="0" max="3650" step="1">
                <p class="description"><?php esc_html_e('Detailed event rows older than this are pruned by a daily cron. Per-newsletter and per-subscriber aggregate counters are kept forever. 0 disables pruning.', 'lrob-email-toolkit'); ?></p>
            </div>
        </article>
        <?php
    }

    /**
     * Newsletter footer section. The footer is appended to every
     * sent newsletter; admins edit three plain text fields and the
     * renderer composes the styled HTML (no manual angle brackets,
     * unsubscribe link structurally guaranteed).
     */
    public static function render_newsletter_footer_section(): void
    {
        $intro       = NewsletterFooter::resolve_intro();
        $prefs_label = NewsletterFooter::resolve_prefs_label();
        $unsub_label = NewsletterFooter::resolve_unsub_label();
        ?>
        <article class="lrob-etk-nl-settings-group">
            <h3 class="lrob-etk-nl-settings-group-title"><?php esc_html_e('Newsletter footer', 'lrob-email-toolkit'); ?></h3>
            <p class="lrob-etk-nl-settings-group-intro">
                <?php esc_html_e('Appended to every sent newsletter. The Preferences + Unsubscribe links are wired in automatically — you just edit the copy.', 'lrob-email-toolkit'); ?>
            </p>

            <div class="lrob-etk-nl-settings-row lrob-etk-nl-settings-row--full">
                <label for="lrob-etk-nl-setting-footer-intro">
                    <?php esc_html_e('Intro line', 'lrob-email-toolkit'); ?>
                </label>
                <input type="text"
                       id="lrob-etk-nl-setting-footer-intro"
                       class="lrob-etk-nl-field"
                       data-key="setting-newsletter-footer-intro"
                       data-option-key="<?php echo esc_attr(NewsletterFooter::OPTION_INTRO); ?>"
                       value="<?php echo esc_attr($intro); ?>">
                <p class="description">
                    <?php esc_html_e('Tokens you can use:', 'lrob-email-toolkit'); ?>
                    <code>{{site_name}}</code> <code>{{site_url}}</code> <code>{{email}}</code> <code>{{name}}</code> <code>{{first_name}}</code>
                </p>
            </div>

            <div class="lrob-etk-nl-settings-row lrob-etk-nl-settings-row--full">
                <label for="lrob-etk-nl-setting-footer-prefs">
                    <?php esc_html_e('Preferences link text', 'lrob-email-toolkit'); ?>
                </label>
                <input type="text"
                       id="lrob-etk-nl-setting-footer-prefs"
                       class="lrob-etk-nl-field"
                       data-key="setting-newsletter-footer-prefs"
                       data-option-key="<?php echo esc_attr(NewsletterFooter::OPTION_PREFS_LABEL); ?>"
                       value="<?php echo esc_attr($prefs_label); ?>">
            </div>

            <div class="lrob-etk-nl-settings-row lrob-etk-nl-settings-row--full">
                <label for="lrob-etk-nl-setting-footer-unsub">
                    <?php esc_html_e('Unsubscribe link text', 'lrob-email-toolkit'); ?>
                </label>
                <input type="text"
                       id="lrob-etk-nl-setting-footer-unsub"
                       class="lrob-etk-nl-field"
                       data-key="setting-newsletter-footer-unsub"
                       data-option-key="<?php echo esc_attr(NewsletterFooter::OPTION_UNSUB_LABEL); ?>"
                       value="<?php echo esc_attr($unsub_label); ?>">
            </div>
        </article>
        <?php
    }

    /**
     * Trash retention section. Auto-purge cron deletes trashed
     * subscribers whose `trashed_at` is older than this many days.
     * 0 (default) keeps the trash forever — the admin clicks Empty
     * Trash manually on the Subscribers view. Lives in the central
     * Settings hub; the Subscribers trash tab shows the same value
     * in its info banner (read-only) so admins know what to expect.
     */
    public static function render_trash_retention_section(): void
    {
        $days = (int) get_option(TrashCron::OPTION_DAYS, 0);
        ?>
        <article class="lrob-etk-nl-settings-group">
            <h3 class="lrob-etk-nl-settings-group-title"><?php esc_html_e('Trash retention', 'lrob-email-toolkit'); ?></h3>
            <p class="lrob-etk-nl-settings-group-intro">
                <?php esc_html_e('Set how many days trashed subscribers stay in the trash before being permanently deleted. 0 (the default) disables auto-purge — trash is kept until you click "Empty trash" manually.', 'lrob-email-toolkit'); ?>
            </p>

            <div class="lrob-etk-nl-settings-row">
                <label for="lrob-etk-nl-setting-trash-days">
                    <?php esc_html_e('Auto-purge trash after (days)', 'lrob-email-toolkit'); ?>
                </label>
                <input type="number"
                       id="lrob-etk-nl-setting-trash-days"
                       class="lrob-etk-nl-field"
                       data-key="setting-trash-purge-days"
                       data-option-key="<?php echo esc_attr(TrashCron::OPTION_DAYS); ?>"
                       value="<?php echo esc_attr((string) $days); ?>"
                       min="0" max="3650" step="1">
                <p class="description"><?php esc_html_e('0 = never auto-purge. A daily cron handles the deletion when this is set above 0.', 'lrob-email-toolkit'); ?></p>
            </div>
        </article>
        <?php
    }

    /**
     * Reminder-schedule section, callable from any view. Onboarding
     * uses it as part of its template-management page (the schedule
     * controls how the reminder template gets dispatched); the
     * Settings view uses it as part of the central hub.
     *
     * @param bool $include_link_back When true, prepends a small hint
     *     pointing at the contextual home so admins know where the
     *     primary place to manage this is. Settings = true (link to
     *     Onboarding); Onboarding = false (this IS the primary home).
     */
    public static function render_reminder_schedule_section(bool $include_link_back = false): void
    {
        $max         = (int) get_option(ReminderCron::OPTION_MAX, 2);
        $first_after = (int) get_option(ReminderCron::OPTION_FIRST_AFTER_DAYS, 3);
        $interval    = (int) get_option(ReminderCron::OPTION_INTERVAL_DAYS, 7);
        $onboarding_url = add_query_arg(
            ['page' => PageController::SLUG, 'view' => HomePage::VIEW_ONBOARDING],
            admin_url('admin.php')
        );
        ?>
        <article class="lrob-etk-nl-settings-group">
            <h3 class="lrob-etk-nl-settings-group-title"><?php esc_html_e('Reminder schedule', 'lrob-email-toolkit'); ?></h3>
            <p class="lrob-etk-nl-settings-group-intro">
                <?php esc_html_e('When a visitor signs up but doesn\'t click the confirmation link, the daily cron nudges them with the reminder template. Set max to 0 to disable reminders entirely.', 'lrob-email-toolkit'); ?>
                <?php if ($include_link_back) : ?>
                    <a href="<?php echo esc_url($onboarding_url); ?>" class="lrob-etk-nl-settings-context-link">
                        <?php esc_html_e('Manage on the Onboarding page →', 'lrob-email-toolkit'); ?>
                    </a>
                <?php endif; ?>
            </p>

            <div class="lrob-etk-nl-settings-row">
                <label for="lrob-etk-nl-setting-max">
                    <?php esc_html_e('Maximum reminders to send', 'lrob-email-toolkit'); ?>
                </label>
                <input type="number"
                       id="lrob-etk-nl-setting-max"
                       class="lrob-etk-nl-field"
                       data-key="setting-reminder-max"
                       data-option-key="<?php echo esc_attr(ReminderCron::OPTION_MAX); ?>"
                       value="<?php echo esc_attr((string) $max); ?>"
                       min="0" max="10" step="1">
                <p class="description"><?php esc_html_e('0 disables reminders for everyone going forward. Already-pending subscribers stop receiving nudges immediately.', 'lrob-email-toolkit'); ?></p>
            </div>

            <div class="lrob-etk-nl-settings-row">
                <label for="lrob-etk-nl-setting-first">
                    <?php esc_html_e('Send first reminder after (days)', 'lrob-email-toolkit'); ?>
                </label>
                <input type="number"
                       id="lrob-etk-nl-setting-first"
                       class="lrob-etk-nl-field"
                       data-key="setting-first-after-days"
                       data-option-key="<?php echo esc_attr(ReminderCron::OPTION_FIRST_AFTER_DAYS); ?>"
                       value="<?php echo esc_attr((string) $first_after); ?>"
                       min="1" max="365" step="1">
                <p class="description"><?php esc_html_e('Days from signup before the first reminder fires. Default: 3.', 'lrob-email-toolkit'); ?></p>
            </div>

            <div class="lrob-etk-nl-settings-row">
                <label for="lrob-etk-nl-setting-interval">
                    <?php esc_html_e('Days between reminders', 'lrob-email-toolkit'); ?>
                </label>
                <input type="number"
                       id="lrob-etk-nl-setting-interval"
                       class="lrob-etk-nl-field"
                       data-key="setting-interval-days"
                       data-option-key="<?php echo esc_attr(ReminderCron::OPTION_INTERVAL_DAYS); ?>"
                       value="<?php echo esc_attr((string) $interval); ?>"
                       min="1" max="365" step="1">
                <p class="description"><?php esc_html_e('Wait this long between subsequent reminders. Default: 7.', 'lrob-email-toolkit'); ?></p>
            </div>
        </article>
        <?php
    }
}
