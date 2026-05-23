<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Assets as SharedAssets;
use LRob\EmailToolkit\Admin\ModuleToggle;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\Newsletter\Module as NewsletterModule;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendAjaxController;
use LRob\EmailToolkit\Modules\Newsletter\SubscriberRepository;
use LRob\EmailToolkit\Modules\Newsletter\TemplateCPT;
use LRob\EmailToolkit\Modules\Newsletter\TemplateRepository;
use LRob\EmailToolkit\Modules\Newsletter\TemplateTokens;
use LRob\EmailToolkit\Modules\Newsletter\TemplateValidator;

/**
 * Newsletter homepage hub — single page at `?page=lrob-etk-nl` with
 * `&view=` dispatch to keep every Newsletter UI under one URL slug.
 *
 * v0.3.0 step 1: scaffolding only. Each view renders a placeholder card so
 * the navigation is real and the URLs are stable, but the actual screens
 * land in later steps (newsletters / subscribers / lists / categories /
 * onboarding / forms / import / settings). The dashboard (no `&view=`)
 * shows a brief "what is this module" intro plus what little data we
 * already have (subscriber count, once anything lands there).
 */
final class HomePage
{
    public const VIEW_NEWSLETTERS = 'newsletters';

    public const VIEW_SUBSCRIBERS = 'subscribers';

    public const VIEW_LISTS       = 'lists';

    public const VIEW_CATEGORIES  = 'categories';

    public const VIEW_ONBOARDING  = 'onboarding';

    public const VIEW_FORMS       = 'forms';

    public const VIEW_IMPORT      = 'import';

    public const VIEW_SETTINGS    = 'settings';

    /**
     * Auto-save listener handle. Loaded hub-wide (not per-view) since
     * every CRUD page in the hub (Forms, Categories, Lists, future
     * Subscribers, …) wires fields to it.
     */
    public const HANDLE_ADMIN_JS = 'lrob-etk-nl-admin';

    public function __construct(
        private ModuleInterface $module,
        private SubscriberRepository $subscribers,
        private TemplateRepository $templates,
        private FormsPage $forms_page,
        private CategoriesPage $categories_page,
        private ListsPage $lists_page,
        private SettingsPage $settings_page,
        private SubscribersPage $subscribers_page,
        private NewslettersPage $newsletters_page,
    ) {
    }

    /**
     * Hub-wide assets. Loaded on every view since each page wires
     * auto-save through the same listener. View-specific assets
     * (form-builder editor, frontend CSS preview, etc.) live in
     * each page class's own enqueue_assets.
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        if (!str_contains($hook_suffix, 'lrob-etk-nl')) {
            return;
        }
        wp_enqueue_script(
            self::HANDLE_ADMIN_JS,
            LROB_ETK_URL . 'admin/js/newsletter-admin.js',
            [SharedAssets::HANDLE_CONTROLS_JS],
            SharedAssets::asset_version_for('admin/js/newsletter-admin.js'),
            true
        );
        wp_localize_script(self::HANDLE_ADMIN_JS, 'lrobEtkNlAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(AjaxController::NONCE_ACTION),
            'actions' => [
                'saveMeta'           => AjaxController::ACTION_SAVE_META,
                'saveStructure'      => AjaxController::ACTION_SAVE_STRUCTURE,
                'saveNewsletterMeta' => AjaxController::ACTION_NEWSLETTER_SAVE_META,
            ],
            'i18n'    => [
                'saving' => __('Saving…', 'lrob-email-toolkit'),
                'saved'  => __('Saved', 'lrob-email-toolkit'),
                'error'  => __('Save failed', 'lrob-email-toolkit'),
            ],
        ]);

        // Newsletter cards send-pipeline script. Uses its own nonce
        // action (SendAjaxController gates the send endpoints) so it
        // can't be used to forge generic admin saves and vice-versa.
        $send_handle = 'lrob-etk-nl-cards';
        wp_enqueue_script(
            $send_handle,
            LROB_ETK_URL . 'admin/js/newsletter-cards.js',
            [],
            SharedAssets::asset_version_for('admin/js/newsletter-cards.js'),
            true
        );
        /* translators: %1$d: sent count, %2$d: failed count */
        $i18n_test_done = __('Test done: %1$d sent, %2$d failed.', 'lrob-email-toolkit');
        $i18n_test_failed = __('Test send failed.', 'lrob-email-toolkit');
        $i18n_tick_failed = __('Send tick failed. Stopped.', 'lrob-email-toolkit');
        wp_localize_script($send_handle, 'lrobEtkNlSend', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(SendAjaxController::NONCE_ACTION),
            'actions' => [
                'tick'              => SendAjaxController::ACTION_TICK,
                'test'              => SendAjaxController::ACTION_TEST_SEND,
                'pause'             => SendAjaxController::ACTION_PAUSE,
                'resume'            => SendAjaxController::ACTION_RESUME,
                'abort'             => SendAjaxController::ACTION_ABORT,
                'retryFailed'       => SendAjaxController::ACTION_RETRY_FAILED,
                'preview'           => SendAjaxController::ACTION_PREVIEW,
                'recipientsPreview' => SendAjaxController::ACTION_RECIPIENTS_PREVIEW,
            ],
            'i18n'    => [
                'tickFailed'           => $i18n_tick_failed,
                'testDone'             => $i18n_test_done,
                'testFailed'           => $i18n_test_failed,
                'previewFailed'        => __('Could not load the preview.', 'lrob-email-toolkit'),
                'recipientsLoading'    => __('Computing recipient set…', 'lrob-email-toolkit'),
                'recipientsTotal'      => __('recipients', 'lrob-email-toolkit'),
                'recipientsSubscribers' => __('subscribers', 'lrob-email-toolkit'),
                'recipientsUsers'      => __('WordPress users', 'lrob-email-toolkit'),
                /* translators: %d: sample size */
                'recipientsSample'     => __('Sample (first %d):', 'lrob-email-toolkit'),
                'snapshotNote'         => __('frozen at send time', 'lrob-email-toolkit'),
                'viewInLogs'           => __('View in Logs →', 'lrob-email-toolkit'),
                'minutes'              => __('minutes', 'lrob-email-toolkit'),
                'minuteSingular'       => __('minute', 'lrob-email-toolkit'),
                'hours'                => __('hours', 'lrob-email-toolkit'),
                'hourSingular'         => __('hour', 'lrob-email-toolkit'),
                'days'                 => __('days', 'lrob-email-toolkit'),
                'daySingular'          => __('day', 'lrob-email-toolkit'),
                /* translators: %1$s: relative time until send (e.g. "2 days"), %2$s: absolute datetime */
                'scheduledTemplate'    => __('Scheduled to send in %1$s — %2$s', 'lrob-email-toolkit'),
                'scheduledOverdue'     => __('in the past (will send on next click)', 'lrob-email-toolkit'),
                'abortTitle'           => __('Abort send', 'lrob-email-toolkit'),
                'abortConfirm'         => __('Abort', 'lrob-email-toolkit'),
                'retryFailedTitle'     => __('Retry failed recipients', 'lrob-email-toolkit'),
                /* translators: %d: number of failed recipients to re-queue */
                'retryFailedConfirm'   => __('Re-queue %d failed recipient(s) for another send attempt? Make sure SMTP is healthy first.', 'lrob-email-toolkit'),
                'retryFailedAction'    => __('Retry', 'lrob-email-toolkit'),
                /* translators: %d: number of recipients re-queued */
                'retryFailedDone'      => __('%d recipient(s) re-queued.', 'lrob-email-toolkit'),
            ],
        ]);
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }

        $view = isset($_GET['view']) && is_string($_GET['view']) ? sanitize_key((string) $_GET['view']) : '';
        $enabled = $this->module->is_enabled();
        ?>
        <div class="wrap lrob-etk lrob-etk-nl-page">
            <header class="lrob-etk-page-header">
                <h1 class="lrob-etk-page-title">
                    <?php esc_html_e('Newsletter', 'lrob-email-toolkit'); ?>
                    <?php if ($view !== '') : ?>
                        <span class="lrob-etk-page-title-sub">— <?php echo esc_html($this->view_label($view)); ?></span>
                    <?php endif; ?>
                </h1>
                <?php
                // The enable/disable toggle reads as a property of the
                // whole module, not of the current view — surfacing it
                // next to a subpage title makes it look like it gates
                // just that subpage. Keep it on the Dashboard only.
                if ($view === '') {
                    ModuleToggle::render_inline($this->module);
                }
                ?>
            </header>

            <?php if (!$enabled) : ?>
                <p class="lrob-etk-disabled-message">
                    <?php esc_html_e('Enable the Newsletter module to start managing newsletters and subscribers.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else : ?>
                <?php $this->render_nav($view); ?>
                <?php $this->render_view($view); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Sticky-style sub-nav across the hub views. Inert "Coming soon" until each view lands. */
    private function render_nav(string $current): void
    {
        $base = admin_url('admin.php?page=' . PageController::SLUG);
        $tabs = [
            ''                       => __('Dashboard', 'lrob-email-toolkit'),
            self::VIEW_NEWSLETTERS     => __('Newsletters', 'lrob-email-toolkit'),
            self::VIEW_SUBSCRIBERS   => __('Subscribers', 'lrob-email-toolkit'),
            self::VIEW_LISTS         => __('Lists', 'lrob-email-toolkit'),
            self::VIEW_CATEGORIES    => __('Categories', 'lrob-email-toolkit'),
            self::VIEW_ONBOARDING     => __('Onboarding', 'lrob-email-toolkit'),
            self::VIEW_FORMS         => __('Forms', 'lrob-email-toolkit'),
            self::VIEW_IMPORT        => __('Import', 'lrob-email-toolkit'),
            self::VIEW_SETTINGS      => __('Settings', 'lrob-email-toolkit'),
        ];
        ?>
        <nav class="lrob-etk-nl-tabs">
            <?php foreach ($tabs as $slug => $label) : ?>
                <?php $url = $slug === '' ? $base : add_query_arg('view', $slug, $base); ?>
                <a href="<?php echo esc_url($url); ?>"
                   class="lrob-etk-nl-tab<?php echo $current === $slug ? ' is-active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    private function render_view(string $view): void
    {
        match ($view) {
            self::VIEW_ONBOARDING  => $this->render_onboarding(),
            self::VIEW_FORMS       => $this->forms_page->render(),
            self::VIEW_CATEGORIES  => $this->categories_page->render(),
            self::VIEW_LISTS       => $this->lists_page->render(),
            self::VIEW_SUBSCRIBERS => $this->subscribers_page->render(),
            self::VIEW_NEWSLETTERS   => $this->newsletters_page->render(),
            self::VIEW_SETTINGS    => $this->settings_page->render(),
            self::VIEW_IMPORT      => $this->render_placeholder($view),
            default                => $this->render_dashboard(),
        };
    }

    /**
     * Onboarding view: one section per system-email purpose
     * (confirmation, reminder, refuse_ack). These are the emails the
     * newsletter sends automatically as subscribers move through the
     * signup flow — distinct from newsletter content the admin composes.
     * Each row links to the Gutenberg post editor; defaults carry a
     * "Default" badge; validator issues surface inline.
     */
    private function render_onboarding(): void
    {
        $grouped = $this->templates->list_all_grouped();
        $resolved_defaults = [];
        foreach (TemplateCPT::purposes() as $purpose) {
            $resolved_defaults[$purpose] = $this->templates->default_id_for_purpose($purpose);
        }
        ?>
        <section class="lrob-etk-nl-templates">
            <p class="lrob-etk-nl-templates-intro">
                <?php esc_html_e('System emails sent automatically during subscription onboarding. Edit any template in the block editor. Tokens marked with an asterisk (*) are required for the email to function.', 'lrob-email-toolkit'); ?>
            </p>

            <?php foreach (TemplateCPT::purposes() as $purpose) : ?>
                <?php
                $posts = $grouped[$purpose] ?? [];
                $default_id = $resolved_defaults[$purpose] ?? 0;
                $tokens = TemplateTokens::available_tokens($purpose);
                $required = TemplateTokens::required_tokens($purpose);
                $new_url = wp_nonce_url(
                    add_query_arg(
                        [
                            'action'  => NewsletterModule::ACTION_NEW_FROM_DEFAULT,
                            'purpose' => $purpose,
                        ],
                        admin_url('admin.php')
                    ),
                    NewsletterModule::ACTION_NEW_FROM_DEFAULT
                );
                ?>
                <article class="lrob-etk-nl-template-group">
                    <header class="lrob-etk-nl-template-group-head">
                        <h2 class="lrob-etk-nl-template-group-title"><?php echo esc_html(TemplateCPT::purpose_label($purpose)); ?></h2>
                        <a class="button button-secondary" href="<?php echo esc_url($new_url); ?>" title="<?php esc_attr_e('Start a new draft pre-filled with the default content for this purpose.', 'lrob-email-toolkit'); ?>">
                            <?php esc_html_e('+ New from default', 'lrob-email-toolkit'); ?>
                        </a>
                    </header>

                    <p class="lrob-etk-nl-template-group-tokens">
                        <?php esc_html_e('Available tokens:', 'lrob-email-toolkit'); ?>
                        <?php foreach ($tokens as $i => $token) : ?>
                            <?php $is_req = in_array($token, $required, true); ?>
                            <code<?php echo $is_req ? ' class="is-required" title="' . esc_attr__('Required for this onboarding purpose', 'lrob-email-toolkit') . '"' : ''; ?>>{{<?php echo esc_html($token); ?>}}<?php echo $is_req ? '*' : ''; ?></code><?php echo $i < count($tokens) - 1 ? ' ' : ''; ?>
                        <?php endforeach; ?>
                    </p>

                    <?php if ($posts === []) : ?>
                        <p class="lrob-etk-nl-template-empty">
                            <?php esc_html_e('No templates yet for this purpose.', 'lrob-email-toolkit'); ?>
                        </p>
                    <?php else : ?>
                        <ul class="lrob-etk-nl-template-list">
                            <?php foreach ($posts as $post) : ?>
                                <?php
                                $edit_url = get_edit_post_link($post->ID);
                                $is_default_seed = (bool) get_post_meta($post->ID, TemplateCPT::META_IS_DEFAULT, true);
                                $is_resolved_default = ((int) $post->ID === $default_id);
                                $validation = TemplateValidator::validate($post->ID);
                                ?>
                                <li class="lrob-etk-nl-template-row">
                                    <a class="lrob-etk-nl-template-title" href="<?php echo esc_url((string) $edit_url); ?>">
                                        <?php echo esc_html($post->post_title !== '' ? $post->post_title : __('(untitled)', 'lrob-email-toolkit')); ?>
                                    </a>
                                    <?php if ($is_resolved_default) : ?>
                                        <span class="lrob-etk-nl-template-badge is-default" title="<?php esc_attr_e('Currently used by the newsletter for this purpose.', 'lrob-email-toolkit'); ?>"><?php esc_html_e('Default', 'lrob-email-toolkit'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($is_default_seed && !$is_resolved_default) : ?>
                                        <span class="lrob-etk-nl-template-badge is-seed" title="<?php esc_attr_e('Auto-created on module install.', 'lrob-email-toolkit'); ?>"><?php esc_html_e('Seeded', 'lrob-email-toolkit'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($validation['valid']) : ?>
                                        <span class="lrob-etk-nl-template-status is-valid"><?php esc_html_e('OK', 'lrob-email-toolkit'); ?></span>
                                    <?php else : ?>
                                        <span class="lrob-etk-nl-template-status is-invalid" title="<?php echo esc_attr(implode(' · ', $validation['issues'])); ?>">
                                            <?php echo esc_html(sprintf(
                                                /* translators: %d: number of validation issues. */
                                                _n('%d issue', '%d issues', count($validation['issues']), 'lrob-email-toolkit'),
                                                count($validation['issues'])
                                            )); ?>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>

        <?php
        // Reminder schedule lives below the templates because that's the
        // schedule that drives the reminder template's dispatch. Same
        // controls also live on the Settings hub — both surfaces auto-
        // save to the same option keys.
        ?>
        <section class="lrob-etk-nl-settings lrob-etk-nl-settings--inline">
            <?php SettingsPage::render_reminder_schedule_section(false); ?>
        </section>
        <?php
    }

    private function render_dashboard(): void
    {
        $subs = $this->subscribers->count_total();
        ?>
        <section class="lrob-etk-nl-dashboard">
            <p class="lrob-etk-nl-intro">
                <?php esc_html_e('Send newsletters to your WordPress users and subscribers. Pick a section above to start.', 'lrob-email-toolkit'); ?>
            </p>
            <div class="lrob-etk-nl-tiles">
                <div class="lrob-etk-nl-tile">
                    <span class="lrob-etk-nl-tile-value"><?php echo esc_html(number_format_i18n($subs)); ?></span>
                    <span class="lrob-etk-nl-tile-label"><?php esc_html_e('Email-only subscribers', 'lrob-email-toolkit'); ?></span>
                </div>
                <div class="lrob-etk-nl-tile">
                    <span class="lrob-etk-nl-tile-value"><?php echo esc_html(number_format_i18n((int) count_users()['total_users'])); ?></span>
                    <span class="lrob-etk-nl-tile-label"><?php esc_html_e('WP users (potential recipients)', 'lrob-email-toolkit'); ?></span>
                </div>
            </div>
            <p class="lrob-etk-nl-skeleton-note">
                <?php esc_html_e('This module is in active development — dashboard polish, tracking, bounce handling, and import/export land across the next few releases.', 'lrob-email-toolkit'); ?>
            </p>
        </section>
        <?php
    }

    private function render_placeholder(string $view): void
    {
        ?>
        <section class="lrob-etk-nl-placeholder">
            <div class="lrob-etk-nl-placeholder-icon dashicons dashicons-clock" aria-hidden="true"></div>
            <h2 class="lrob-etk-nl-placeholder-title"><?php echo esc_html($this->view_label($view)); ?></h2>
            <p class="lrob-etk-nl-placeholder-text">
                <?php esc_html_e('This section is part of the Newsletter module rollout and will land in a coming release.', 'lrob-email-toolkit'); ?>
            </p>
        </section>
        <?php
    }

    private function view_label(string $view): string
    {
        return match ($view) {
            self::VIEW_NEWSLETTERS   => __('Newsletters', 'lrob-email-toolkit'),
            self::VIEW_SUBSCRIBERS => __('Subscribers', 'lrob-email-toolkit'),
            self::VIEW_LISTS       => __('Lists', 'lrob-email-toolkit'),
            self::VIEW_CATEGORIES  => __('Categories', 'lrob-email-toolkit'),
            self::VIEW_ONBOARDING  => __('Onboarding', 'lrob-email-toolkit'),
            self::VIEW_FORMS       => __('Forms', 'lrob-email-toolkit'),
            self::VIEW_IMPORT      => __('Import', 'lrob-email-toolkit'),
            self::VIEW_SETTINGS    => __('Settings', 'lrob-email-toolkit'),
            default                => __('Dashboard', 'lrob-email-toolkit'),
        };
    }
}
