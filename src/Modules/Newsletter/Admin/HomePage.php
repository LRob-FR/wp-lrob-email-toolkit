<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Assets as SharedAssets;
use LRob\EmailToolkit\Admin\ModuleToggle;
use LRob\EmailToolkit\Admin\PageHeader;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\Newsletter\Module as NewsletterModule;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendAjaxController;
use LRob\EmailToolkit\Modules\Newsletter\SubscriberRepository;
use LRob\EmailToolkit\Modules\Newsletter\TemplateCPT;
use LRob\EmailToolkit\Modules\Newsletter\TemplateRepository;
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
        wp_enqueue_script(
            'lrob-etk-audience-picker',
            LROB_ETK_URL . 'admin/js/etk-audience-picker.js',
            [],
            SharedAssets::asset_version_for('admin/js/etk-audience-picker.js'),
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
            'statusModifiers' => \LRob\EmailToolkit\Admin\StatusPill::modifier_map(),
            'actions' => [
                'tick'              => SendAjaxController::ACTION_TICK,
                'test'              => SendAjaxController::ACTION_TEST_SEND,
                'pause'             => SendAjaxController::ACTION_PAUSE,
                'resume'            => SendAjaxController::ACTION_RESUME,
                'abort'             => SendAjaxController::ACTION_ABORT,
                'retryFailed'       => SendAjaxController::ACTION_RETRY_FAILED,
                'commitSchedule'    => SendAjaxController::ACTION_COMMIT_SCHEDULE,
                'uncommitSchedule'  => SendAjaxController::ACTION_UNCOMMIT_SCHEDULE,
                'cardStates'        => SendAjaxController::ACTION_CARD_STATES,
                'preview'           => SendAjaxController::ACTION_PREVIEW,
                'recipientsPreview' => SendAjaxController::ACTION_RECIPIENTS_PREVIEW,
                'forceOverridesSave' => SendAjaxController::ACTION_FORCE_OVERRIDES_SAVE,
            ],
            'i18n'    => [
                'tickFailed'           => $i18n_tick_failed,
                'testDone'             => $i18n_test_done,
                'testFailed'           => $i18n_test_failed,
                'previewFailed'        => __('Could not load the preview.', 'lrob-email-toolkit'),
                'recipientsLoading'    => __('Computing recipient set…', 'lrob-email-toolkit'),
                'recipientsTotal'      => __('recipients', 'lrob-email-toolkit'),
                'recipientsOptedOut'   => __('opted out', 'lrob-email-toolkit'),
                'recipientsBypassShort'    => __('Bypass', 'lrob-email-toolkit'),
                'recipientsBypassWarn'     => __('⚠ Opt-outs bypassed — this newsletter will reach recipients who explicitly opted out. Only use for legitimate operational / legal communications.', 'lrob-email-toolkit'),
                'recipientsForceInclude'   => __('Send anyway', 'lrob-email-toolkit'),
                'recipientsForceIncluded'  => __('force include', 'lrob-email-toolkit'),
                'recipientsForceExclude'   => __('Exclude', 'lrob-email-toolkit'),
                'recipientsForceExcluded'  => __('force exclude', 'lrob-email-toolkit'),
                'recipientsUndoForce'      => __('Undo', 'lrob-email-toolkit'),
                'recipientsWillSend'       => __('will send', 'lrob-email-toolkit'),
                'recipientsSkipped'        => __('skipped', 'lrob-email-toolkit'),
                'recipientsTabAll'         => __('All', 'lrob-email-toolkit'),
                'recipientsTabOptedIn'     => __('Opted-in', 'lrob-email-toolkit'),
                'recipientsTabOptedOut'    => __('Opted-out', 'lrob-email-toolkit'),
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
                /* translators: %1$s: relative time span (e.g. "2 days"), %2$s: absolute datetime */
                'scheduledTemplate'    => __('Scheduled to send in %1$s — %2$s', 'lrob-email-toolkit'),
                'scheduleConfirm'      => __('Click Schedule to confirm', 'lrob-email-toolkit'),
                /* translators: %s: absolute datetime */
                'scheduledOverdueTemplate' => __('Scheduled for %s (overdue — will run on the next cron tick).', 'lrob-email-toolkit'),
                'abortTitle'           => __('Abort send', 'lrob-email-toolkit'),
                'abortConfirm'         => __('Abort', 'lrob-email-toolkit'),
                'retryFailedTitle'     => __('Retry failed recipients', 'lrob-email-toolkit'),
                /* translators: %d: number of failed recipients to re-queue */
                'retryFailedConfirm'   => __('Re-queue %d failed recipient(s) for another send attempt? Make sure SMTP is healthy first.', 'lrob-email-toolkit'),
                'retryFailedAction'    => __('Retry', 'lrob-email-toolkit'),
                /* translators: %d: number of recipients re-queued */
                'retryFailedDone'      => __('%d recipient(s) re-queued.', 'lrob-email-toolkit'),
                'unscheduleTitle'      => __('Unschedule send', 'lrob-email-toolkit'),
                'unscheduleConfirm'    => __('Drop this scheduled send back to draft? The date stays saved so you can re-commit later.', 'lrob-email-toolkit'),
                'unscheduleAction'     => __('Unschedule', 'lrob-email-toolkit'),
                'seconds'              => __('seconds', 'lrob-email-toolkit'),
                'secondSingular'       => __('second', 'lrob-email-toolkit'),
                'refresh'              => __('Refresh', 'lrob-email-toolkit'),
                'recipientsFilterAll'     => __('All', 'lrob-email-toolkit'),
                'recipientsFilterPending' => __('Pending', 'lrob-email-toolkit'),
                'recipientsFilterSent'    => __('Sent', 'lrob-email-toolkit'),
                'recipientsFilterFailed'  => __('Failed', 'lrob-email-toolkit'),
                'recipientsFilterSkipped' => __('Skipped', 'lrob-email-toolkit'),
                'recipientsColKind'       => __('Kind', 'lrob-email-toolkit'),
                'recipientsColEmail'      => __('Email', 'lrob-email-toolkit'),
                'recipientsColStatus'     => __('Status', 'lrob-email-toolkit'),
                'recipientsColSentAt'     => __('Sent at', 'lrob-email-toolkit'),
                'recipientsColLogs'       => __('Logs', 'lrob-email-toolkit'),
                'recipientsNoneMatch'     => __('No recipients match this filter.', 'lrob-email-toolkit'),
                /* translators: %1$s: range start (e.g. "1"), %2$s: range end (e.g. "50"), %3$s: total recipients */
                'recipientsRange'         => __('Showing %1$s–%2$s of %3$s', 'lrob-email-toolkit'),
                'previous'                => __('Previous', 'lrob-email-toolkit'),
                'next'                    => __('Next', 'lrob-email-toolkit'),
            ],
        ]);
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }

        $view = isset($_GET['view']) && is_string($_GET['view']) ? sanitize_key((string) $_GET['view']) : '';
        // Settings used to be a sub-view; it's now a modal on the
        // Dashboard. A stale ?view=settings URL falls back to Dashboard.
        if ($view === self::VIEW_SETTINGS) {
            $view = '';
        }
        $enabled = $this->module->is_enabled();
        ?>
        <div class="wrap lrob-etk lrob-etk-nl-page">
            <?php if (!$enabled) : ?>
                <?php PageHeader::render([
                    'title'  => __('Newsletters', 'lrob-email-toolkit'),
                    'module' => $this->module,
                ]); ?>
                <p class="lrob-etk-disabled-message">
                    <?php esc_html_e('Enable the Newsletters module to start managing newsletters and subscribers.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else : ?>
                <?php $this->render_view($view); ?>
                <?php SettingsPage::render_modal(); ?>
                <?php $this->lists_page->render_modal(); ?>
                <?php $this->render_subscription_emails_modal(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Standard "Settings" tools button used by every Newsletter sub-page. */
    public static function settings_tool(): array
    {
        return [
            'label' => __('Settings', 'lrob-email-toolkit'),
            'icon'  => 'dashicons-admin-generic',
            'id'    => 'lrob-etk-nl-settings-btn',
        ];
    }

    /** Page-internal section tabs — stable order. Renders the strip used
     *  on every Newsletter sub-page right under the PageHeader. Each
     *  sub-page calls `$hub->render_section_tabs(HomePage::VIEW_X)`. */
    public function render_section_tabs(string $current): void
    {
        $base = admin_url('admin.php?page=' . PageController::SLUG);
        $tabs = [
            ''                     => [__('Dashboard', 'lrob-email-toolkit'),    'dashicons-chart-bar'],
            self::VIEW_NEWSLETTERS => [__('Newsletters', 'lrob-email-toolkit'),  'dashicons-email'],
            self::VIEW_SUBSCRIBERS => [__('Subscribers', 'lrob-email-toolkit'),  'dashicons-groups'],
            self::VIEW_FORMS       => [__('Forms', 'lrob-email-toolkit'),        'dashicons-feedback'],
            self::VIEW_IMPORT      => [__('Import', 'lrob-email-toolkit'),       'dashicons-upload'],
        ];
        ?>
        <nav class="lrob-etk-section-tabs" aria-label="<?php esc_attr_e('Newsletter sections', 'lrob-email-toolkit'); ?>">
            <?php foreach ($tabs as $slug => [$label, $icon]) :
                $url = $slug === '' ? $base : add_query_arg('view', $slug, $base);
                $active = $current === $slug;
                $class = 'lrob-etk-section-tab' . ($active ? ' is-active' : '');
                ?>
                <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($class); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                    <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    private function render_view(string $view): void
    {
        // Stale URLs for views that have been folded into a parent:
        //   ?view=categories  → render Newsletters (Categories now lives there)
        //   ?view=lists       → render Subscribers (Lists lives there)
        //   ?view=onboarding  → render Subscribers (Onboarding lives there)
        // No HTTP redirect — just delegate to the consolidated parent so
        // the URL stays stable and the user sees the new home.
        match ($view) {
            self::VIEW_FORMS                        => $this->forms_page->render($this),
            self::VIEW_CATEGORIES,
            self::VIEW_NEWSLETTERS                  => $this->newsletters_page->render($this),
            self::VIEW_LISTS,
            self::VIEW_ONBOARDING,
            self::VIEW_SUBSCRIBERS                  => $this->subscribers_page->render($this),
            self::VIEW_IMPORT                       => $this->render_placeholder($view),
            default                                 => $this->render_dashboard(),
        };
    }

    /** Embedded passthroughs — sub-pages call these to render absorbed
     *  sections (Lists + Onboarding inside Subscribers). Embedded mode
     *  skips the inner PageHeader + section-tabs. */
    public function render_lists_embedded(): void      { $this->lists_page->render($this, true); }
    public function render_onboarding_embedded(): void { $this->render_onboarding(true); }

    /** Standard "Subscription emails" tools button for the Subscribers page. */
    public static function subscription_emails_tool(): array
    {
        return [
            'label' => __('Subscription emails', 'lrob-email-toolkit'),
            'icon'  => 'dashicons-email',
            'id'    => 'lrob-etk-nl-subscription-emails-btn',
        ];
    }

    /** Modal wrapper for the Subscription-emails (onboarding) UI. */
    public function render_subscription_emails_modal(): void
    {
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-nl-subscription-emails-modal" role="dialog" aria-modal="true" aria-labelledby="lrob-etk-nl-subscription-emails-title" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--wide">
                <header class="lrob-etk-modal-header">
                    <h3 id="lrob-etk-nl-subscription-emails-title" class="lrob-etk-modal-title-text"><?php esc_html_e('Subscription emails', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <?php $this->render_onboarding(true); ?>
                </div>
            </div>
        </div>
        <script>
        (function () {
            if (window.__lrobEtkNlSubscriptionEmailsModalBound) return;
            window.__lrobEtkNlSubscriptionEmailsModalBound = true;
            function whenReady(fn) {
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
                else fn();
            }
            whenReady(function () {
                if (window.lrobEtkModal) {
                    window.lrobEtkModal.bindHeader('lrob-etk-nl-subscription-emails-modal', 'lrob-etk-nl-subscription-emails-btn');
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Onboarding view: one section per system-email purpose
     * (confirmation, reminder, refuse_ack). These are the emails the
     * newsletter sends automatically as subscribers move through the
     * signup flow — distinct from newsletter content the admin composes.
     * Each row links to the Gutenberg post editor; defaults carry a
     * "Default" badge; validator issues surface inline.
     */
    public function render_onboarding(bool $embedded = false): void
    {
        $grouped = $this->templates->list_all_grouped();
        $resolved_defaults = [];
        foreach (TemplateCPT::purposes() as $purpose) {
            $resolved_defaults[$purpose] = $this->templates->default_id_for_purpose($purpose);
        }
        if (!$embedded) {
            PageHeader::render([
                'title' => sprintf(__('Newsletters — %s', 'lrob-email-toolkit'), __('Subscription emails', 'lrob-email-toolkit')),
                'tools' => [self::settings_tool()],
            ]);
            $this->render_section_tabs(self::VIEW_ONBOARDING);
        } else {
            echo '<h2 class="lrob-etk-section-title">' . esc_html__('Subscription emails', 'lrob-email-toolkit') . '</h2>';
        }
        ?>
        <section class="lrob-etk-nl-templates">
            <p class="description">
                <?php esc_html_e('System emails fired automatically as a visitor moves through signup. Each purpose has one active template — the rest are alternates you can swap in. Click the title to edit in the block editor.', 'lrob-email-toolkit'); ?>
            </p>

            <?php
            $purpose_descriptions = [
                TemplateCPT::PURPOSE_CONFIRMATION => __('Sent when someone signs up — contains the click-to-confirm link.', 'lrob-email-toolkit'),
                TemplateCPT::PURPOSE_REMINDER     => __('Sent on a schedule to subscribers who signed up but haven\'t clicked the confirmation link yet.', 'lrob-email-toolkit'),
                TemplateCPT::PURPOSE_REFUSE_ACK   => __('Sent after a subscriber declines confirmation, acknowledging the choice.', 'lrob-email-toolkit'),
            ];
            ?>

            <?php
            $nonce = wp_create_nonce(AjaxController::NONCE_ACTION);
            $ajax_url = admin_url('admin-ajax.php');
            ?>
            <?php foreach (TemplateCPT::purposes() as $purpose) : ?>
                <?php
                $posts = $grouped[$purpose] ?? [];
                $default_id = $resolved_defaults[$purpose] ?? 0;
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
                <article class="lrob-etk-card lrob-etk-nl-template-group">
                    <div class="lrob-etk-card-form">
                        <header class="lrob-etk-nl-template-group-head">
                            <h3 class="lrob-etk-nl-template-group-title"><?php echo esc_html(TemplateCPT::purpose_label($purpose)); ?></h3>
                            <span class="lrob-etk-nl-template-group-purpose"><?php echo esc_html($purpose_descriptions[$purpose] ?? ''); ?></span>
                        </header>

                        <?php if ($posts === []) : ?>
                            <div class="lrob-etk-empty-state">
                                <span class="dashicons dashicons-email lrob-etk-empty-state-icon" aria-hidden="true"></span>
                                <p class="lrob-etk-empty-state-text">
                                    <?php esc_html_e('No template configured for this purpose. Visitors who reach this step would get nothing.', 'lrob-email-toolkit'); ?>
                                </p>
                                <a class="button button-primary" href="<?php echo esc_url($new_url); ?>">
                                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                    <?php esc_html_e('Create from default', 'lrob-email-toolkit'); ?>
                                </a>
                            </div>
                        <?php else : ?>
                            <ul class="lrob-etk-nl-template-list" data-template-purpose="<?php echo esc_attr($purpose); ?>">
                                <?php foreach ($posts as $p) :
                                    $is_active = ((int) $p->ID === $default_id);
                                    $edit_url = get_edit_post_link($p->ID);
                                    $validation = TemplateValidator::validate($p->ID);
                                    $is_seed = (bool) get_post_meta($p->ID, TemplateCPT::META_IS_DEFAULT, true);
                                    ?>
                                    <li class="lrob-etk-nl-template-row<?php echo $is_active ? ' is-active' : ''; ?>"
                                        data-template-id="<?php echo (int) $p->ID; ?>">
                                        <?php if ($is_active) : ?>
                                            <span class="lrob-etk-default-badge" title="<?php esc_attr_e('This is the active template — actually sent.', 'lrob-email-toolkit'); ?>">
                                                <span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
                                                <?php esc_html_e('Active', 'lrob-email-toolkit'); ?>
                                            </span>
                                        <?php else : ?>
                                            <button type="button"
                                                    class="lrob-etk-set-default"
                                                    data-set-default-template="<?php echo (int) $p->ID; ?>"
                                                    title="<?php esc_attr_e('Make this the active template for this purpose.', 'lrob-email-toolkit'); ?>">
                                                <span class="dashicons dashicons-star-empty" aria-hidden="true"></span>
                                                <?php esc_html_e('Make active', 'lrob-email-toolkit'); ?>
                                            </button>
                                        <?php endif; ?>
                                        <a class="lrob-etk-nl-template-title" href="<?php echo esc_url((string) $edit_url); ?>">
                                            <?php echo esc_html($p->post_title !== '' ? $p->post_title : __('(untitled)', 'lrob-email-toolkit')); ?>
                                        </a>
                                        <?php if ($validation['valid']) : ?>
                                            <span class="lrob-etk-status lrob-etk-state--on"><?php esc_html_e('Valid', 'lrob-email-toolkit'); ?></span>
                                        <?php else : ?>
                                            <span class="lrob-etk-status lrob-etk-state--fail" title="<?php echo esc_attr(implode(' · ', $validation['issues'])); ?>">
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
                            <div class="lrob-etk-nl-template-group-footer">
                                <a class="button" href="<?php echo esc_url($new_url); ?>" title="<?php esc_attr_e('Create a new draft pre-filled with the seeded default content.', 'lrob-email-toolkit'); ?>">
                                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                    <?php esc_html_e('New variant', 'lrob-email-toolkit'); ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if ($purpose === TemplateCPT::PURPOSE_REMINDER) : ?>
                            <div class="lrob-etk-nl-template-reminder-schedule">
                                <?php SettingsPage::render_reminder_schedule_section(false); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <script>
        (function () {
            if (window.__lrobEtkNlTemplateDefaultBound) return;
            window.__lrobEtkNlTemplateDefaultBound = true;
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var action = <?php echo wp_json_encode(AjaxController::ACTION_TEMPLATE_SET_DEFAULT); ?>;
            var i18nError = <?php echo wp_json_encode(__('Could not change the active template.', 'lrob-email-toolkit')); ?>;

            var i18nActive    = <?php echo wp_json_encode(__('Active', 'lrob-email-toolkit')); ?>;
            var i18nMakeActive= <?php echo wp_json_encode(__('Make active', 'lrob-email-toolkit')); ?>;
            var i18nActiveTip = <?php echo wp_json_encode(__('This is the active template — actually sent.', 'lrob-email-toolkit')); ?>;
            var i18nMakeActiveTip = <?php echo wp_json_encode(__('Make this the active template for this purpose.', 'lrob-email-toolkit')); ?>;

            document.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('[data-set-default-template]');
                if (!btn) return;
                e.preventDefault();
                var id = parseInt(btn.getAttribute('data-set-default-template'), 10);
                if (!id) return;
                var row = btn.closest('[data-template-id]');
                var list = row ? row.closest('[data-template-purpose]') : null;
                if (!list) return;
                btn.disabled = true;
                var fd = new FormData();
                fd.append('action', action);
                fd.append('_nonce', nonce);
                fd.append('template_id', String(id));
                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                    .then(function (resp) {
                        if (resp && resp.success) {
                            // Flip badges in place — no reload, no modal close.
                            list.querySelectorAll('[data-template-id]').forEach(function (other) {
                                var otherId = parseInt(other.getAttribute('data-template-id'), 10);
                                var firstChild = other.firstElementChild;
                                if (!firstChild) return;
                                if (otherId === id) {
                                    other.classList.add('is-active');
                                    firstChild.outerHTML = ''
                                        + '<span class="lrob-etk-default-badge" title="' + i18nActiveTip + '">'
                                        +   '<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>'
                                        +   i18nActive
                                        + '</span>';
                                } else {
                                    other.classList.remove('is-active');
                                    // Only rewrite if the row currently shows the active badge;
                                    // otherwise we'd erase a fresh "Make active" button.
                                    if (firstChild.classList && firstChild.classList.contains('lrob-etk-default-badge')) {
                                        firstChild.outerHTML = ''
                                            + '<button type="button" class="lrob-etk-set-default" '
                                            +   'data-set-default-template="' + otherId + '" '
                                            +   'title="' + i18nMakeActiveTip + '">'
                                            +   '<span class="dashicons dashicons-star-empty" aria-hidden="true"></span>'
                                            +   i18nMakeActive
                                            + '</button>';
                                    }
                                }
                            });
                        } else {
                            btn.disabled = false;
                            window.alert((resp && resp.data && resp.data.message) || i18nError);
                        }
                    });
            });
        })();
        </script>
        <?php
    }

    private function render_dashboard(): void
    {
        $subs = $this->subscribers->count_total();
        $wp_users = (int) count_users()['total_users'];
        $nl_in_prep = $this->newsletters_page->count_for_dashboard('in_prep');
        $nl_sent = $this->newsletters_page->count_for_dashboard('sent');
        $forms_count = $this->forms_page->count_for_dashboard();
        $base = admin_url('admin.php?page=' . PageController::SLUG);
        PageHeader::render([
            'title'  => __('Newsletters', 'lrob-email-toolkit'),
            'module' => $this->module,
            'tools'  => [self::settings_tool()],
        ]);
        $this->render_section_tabs('');
        ?>
        <section class="lrob-etk-nl-dashboard">
            <div class="lrob-etk-nl-tiles">
                <a class="lrob-etk-nl-tile" href="<?php echo esc_url(add_query_arg('view', self::VIEW_SUBSCRIBERS, $base)); ?>">
                    <span class="lrob-etk-nl-tile-value"><?php echo esc_html(number_format_i18n($subs)); ?></span>
                    <span class="lrob-etk-nl-tile-label"><?php esc_html_e('Email-only subscribers', 'lrob-email-toolkit'); ?></span>
                </a>
                <div class="lrob-etk-nl-tile">
                    <span class="lrob-etk-nl-tile-value"><?php echo esc_html(number_format_i18n($wp_users)); ?></span>
                    <span class="lrob-etk-nl-tile-label"><?php esc_html_e('WP users (potential recipients)', 'lrob-email-toolkit'); ?></span>
                </div>
                <a class="lrob-etk-nl-tile" href="<?php echo esc_url(add_query_arg('view', self::VIEW_NEWSLETTERS, $base)); ?>">
                    <span class="lrob-etk-nl-tile-value"><?php echo esc_html(number_format_i18n($nl_in_prep)); ?></span>
                    <span class="lrob-etk-nl-tile-label"><?php esc_html_e('Newsletters in preparation', 'lrob-email-toolkit'); ?></span>
                </a>
                <a class="lrob-etk-nl-tile" href="<?php echo esc_url(add_query_arg(['view' => self::VIEW_NEWSLETTERS, 'tab' => 'sent'], $base)); ?>">
                    <span class="lrob-etk-nl-tile-value"><?php echo esc_html(number_format_i18n($nl_sent)); ?></span>
                    <span class="lrob-etk-nl-tile-label"><?php esc_html_e('Newsletters sent', 'lrob-email-toolkit'); ?></span>
                </a>
                <a class="lrob-etk-nl-tile" href="<?php echo esc_url(add_query_arg('view', self::VIEW_FORMS, $base)); ?>">
                    <span class="lrob-etk-nl-tile-value"><?php echo esc_html(number_format_i18n($forms_count)); ?></span>
                    <span class="lrob-etk-nl-tile-label"><?php esc_html_e('Subscribe forms', 'lrob-email-toolkit'); ?></span>
                </a>
            </div>
        </section>
        <?php
    }

    private function render_placeholder(string $view): void
    {
        $labels = [
            self::VIEW_IMPORT => __('Import', 'lrob-email-toolkit'),
        ];
        $label = $labels[$view] ?? __('Coming soon', 'lrob-email-toolkit');
        PageHeader::render([
            'title' => sprintf(__('Newsletters — %s', 'lrob-email-toolkit'), $label),
            'tools' => [self::settings_tool()],
        ]);
        $this->render_section_tabs($view);
        ?>
        <section class="lrob-etk-nl-placeholder">
            <div class="lrob-etk-nl-placeholder-icon dashicons dashicons-clock" aria-hidden="true"></div>
            <p class="lrob-etk-nl-placeholder-text">
                <?php esc_html_e('This section is part of the Newsletter module rollout and will land in a coming release.', 'lrob-email-toolkit'); ?>
            </p>
        </section>
        <?php
    }
}
