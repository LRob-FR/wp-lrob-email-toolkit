<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Modules\Newsletter\ReminderCron;
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
    public function render(): void
    {
        ?>
        <section class="lrob-etk-nl-settings">
            <header class="lrob-etk-nl-settings-head">
                <h2 class="lrob-etk-section-title"><?php esc_html_e('Newsletter settings', 'lrob-email-toolkit'); ?></h2>
                <span class="lrob-etk-card-status" aria-live="polite"></span>
            </header>
            <p class="lrob-etk-nl-settings-intro">
                <?php esc_html_e('Module-wide controls. Each section here is mirrored on the page where the related feature is managed — changes flow both ways.', 'lrob-email-toolkit'); ?>
            </p>

            <?php self::render_reminder_schedule_section(true); ?>
            <?php self::render_trash_retention_section(); ?>
        </section>
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
