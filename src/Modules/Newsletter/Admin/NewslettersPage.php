<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\PageHeader;
use LRob\EmailToolkit\Container;
use LRob\EmailToolkit\Modules\Newsletter\ListRepository;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendAjaxController;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendCron;
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

    public const ACTION_RESTORE = 'lrob_etk_nl_newsletter_restore';

    public const ACTION_DELETE_PERMANENT = 'lrob_etk_nl_newsletter_delete_permanent';

    public const ACTION_EMPTY_TRASH = 'lrob_etk_nl_newsletter_empty_trash';

    private const TAB_QUERY_VAR = 'tab';

    private NewsletterCardRenderer $cards;

    public function __construct(
        private NewsletterRepository $newsletters,
        private ListRepository $lists,
        private Container $container,
    ) {
        $this->cards = new NewsletterCardRenderer($lists, $container);
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_CREATE, [$this, 'handle_create']);
        add_action('admin_post_' . self::ACTION_DELETE, [$this, 'handle_delete']);
        add_action('admin_post_' . self::ACTION_DUPLICATE, [$this, 'handle_duplicate']);
        add_action('admin_post_' . self::ACTION_RESTORE, [$this, 'handle_restore']);
        add_action('admin_post_' . self::ACTION_DELETE_PERMANENT, [$this, 'handle_delete_permanent']);
        add_action('admin_post_' . self::ACTION_EMPTY_TRASH, [$this, 'handle_empty_trash']);
    }

    /**
     * Resolve the current tab from `?tab=` with a fall-through to
     * "in_prep". Any unknown value collapses to in_prep so a malformed
     * URL can't pin the admin on an empty view forever.
     */
    private function current_tab(): string
    {
        $raw = isset($_GET[self::TAB_QUERY_VAR]) ? (string) $_GET[self::TAB_QUERY_VAR] : '';
        return in_array($raw, [
            NewsletterRepository::TAB_IN_PREP,
            NewsletterRepository::TAB_SENT,
            NewsletterRepository::TAB_TRASH,
        ], true) ? $raw : '';
    }

    public function render(?HomePage $hub = null): void
    {
        $tab = $this->current_tab();
        $rows = $this->newsletters->list_all(50, 0, $tab);
        $counts = $this->newsletters->counts_by_tab();
        $lists = $this->lists->list_all();
        $identities = $this->available_identities();
        $create_url = wp_nonce_url(
            add_query_arg(['action' => self::ACTION_CREATE], admin_url('admin-post.php')),
            self::ACTION_CREATE
        );
        PageHeader::render([
            'title'   => __('Newsletters', 'lrob-email-toolkit'),
            'primary' => [
                'label' => __('New newsletter', 'lrob-email-toolkit'),
                'icon'  => 'dashicons-plus-alt2',
                'href'  => $create_url,
            ],
            'tools' => [HomePage::settings_tool()],
        ]);
        if ($hub) $hub->render_section_tabs(HomePage::VIEW_NEWSLETTERS);
        $this->render_cron_warning();
        ?>
        <section class="lrob-etk-nl-newsletters">
            <?php $this->render_tabs($tab, $counts); ?>

            <?php if ($rows === []) : ?>
                <div class="lrob-etk-empty-state">
                    <span class="dashicons dashicons-email-alt lrob-etk-empty-state-icon" aria-hidden="true"></span>
                    <p class="lrob-etk-empty-state-text">
                        <?php echo esc_html($this->empty_tab_message($tab)); ?>
                    </p>
                    <?php if ($tab === '' || $tab === NewsletterRepository::TAB_IN_PREP) : ?>
                        <a class="button button-primary" href="<?php echo esc_url($create_url); ?>">
                            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                            <?php esc_html_e('New newsletter', 'lrob-email-toolkit'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div class="lrob-etk-nl-newsletter-cards">
                    <?php foreach ($rows as $row) : ?>
                        <?php $this->cards->render($row, $lists, $identities, $tab); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php $this->render_shared_modals(); ?>
        <?php
    }

    /**
     * Pill-row tab nav: In preparation / Sent / Trash, each with a
     * count badge. Tabs are plain `<a>` links so reloading preserves
     * the selected tab (the JS card-poller already calls
     * `window.location.reload()` on state transitions).
     *
     * @param array{in_prep:int, sent:int, trash:int} $counts
     */
    private function render_tabs(string $current, array $counts): void
    {
        $base = add_query_arg(
            ['page' => PageController::SLUG, 'view' => HomePage::VIEW_NEWSLETTERS],
            admin_url('admin.php')
        );
        $all_total = (int) (($counts[NewsletterRepository::TAB_IN_PREP] ?? 0)
            + ($counts[NewsletterRepository::TAB_SENT] ?? 0)
            + ($counts[NewsletterRepository::TAB_TRASH] ?? 0));
        $tabs = [
            ''                                => [__('All', 'lrob-email-toolkit'),            $all_total],
            NewsletterRepository::TAB_IN_PREP => [__('In preparation', 'lrob-email-toolkit'), (int) ($counts[NewsletterRepository::TAB_IN_PREP] ?? 0)],
            NewsletterRepository::TAB_SENT    => [__('Sent', 'lrob-email-toolkit'),           (int) ($counts[NewsletterRepository::TAB_SENT] ?? 0)],
            NewsletterRepository::TAB_TRASH   => [__('Trash', 'lrob-email-toolkit'),          (int) ($counts[NewsletterRepository::TAB_TRASH] ?? 0)],
        ];
        ?>
        <nav class="lrob-etk-section-tabs" role="tablist" aria-label="<?php esc_attr_e('Newsletter filter', 'lrob-email-toolkit'); ?>">
            <?php foreach ($tabs as $tab => [$label, $count]) :
                $url = $tab === '' ? $base : add_query_arg([self::TAB_QUERY_VAR => $tab], $base);
                $is_active = $tab === $current;
                ?>
                <a href="<?php echo esc_url($url); ?>"
                   class="lrob-etk-section-tab<?php echo $is_active ? ' is-active' : ''; ?>"
                   role="tab"
                   aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
                    <?php echo esc_html($label); ?>
                    <span class="lrob-etk-section-tab-count" aria-label="<?php
                        /* translators: %d: count of newsletters in this tab */
                        echo esc_attr(sprintf(__('%d item(s)', 'lrob-email-toolkit'), $count));
                    ?>"><?php echo esc_html(number_format_i18n($count)); ?></span>
                </a>
            <?php endforeach; ?>
            <?php if ($current === NewsletterRepository::TAB_TRASH && ((int) ($counts[NewsletterRepository::TAB_TRASH] ?? 0)) > 0) :
                $empty_url = wp_nonce_url(
                    add_query_arg(['action' => self::ACTION_EMPTY_TRASH], admin_url('admin-post.php')),
                    self::ACTION_EMPTY_TRASH
                );
                ?>
                <span class="lrob-etk-section-tabs-end">
                <button type="button"
                        class="button button-link-delete"
                        data-empty-trash
                        data-empty-trash-url="<?php echo esc_attr($empty_url); ?>"
                        data-empty-trash-confirm="<?php echo esc_attr(sprintf(
                            /* translators: %d: number of trashed newsletters about to be permanently deleted */
                            _n(
                                'Permanently delete %d trashed newsletter? This cannot be undone.',
                                'Permanently delete all %d trashed newsletters? This cannot be undone.',
                                (int) $counts[NewsletterRepository::TAB_TRASH],
                                'lrob-email-toolkit'
                            ),
                            (int) $counts[NewsletterRepository::TAB_TRASH]
                        )); ?>">
                    <?php esc_html_e('Empty trash', 'lrob-email-toolkit'); ?>
                </button>
                </span>
            <?php endif; ?>
        </nav>
        <?php
    }

    private function empty_tab_message(string $tab): string
    {
        return match ($tab) {
            NewsletterRepository::TAB_SENT    => __('No sent newsletters yet. Drafts and scheduled sends live in the "In preparation" tab.', 'lrob-email-toolkit'),
            NewsletterRepository::TAB_TRASH   => __('Trash is empty.', 'lrob-email-toolkit'),
            NewsletterRepository::TAB_IN_PREP => __('No newsletters in preparation yet.', 'lrob-email-toolkit'),
            default                            => __('No newsletters yet. Start one to send to your subscribers.', 'lrob-email-toolkit'),
        };
    }


    /**
     * Inline warning banner shown only when WP-Cron is slow or stalled.
     * Hidden when everything is healthy — no visual noise on the normal path.
     */
    private function render_cron_warning(): void
    {
        $next_tick   = wp_next_scheduled(SendCron::CRON_HOOK);
        $last_tick_s = (string) get_option(SendCron::OPTION_LAST_TICK, '');
        $last_tick   = $last_tick_s !== '' ? strtotime($last_tick_s . ' UTC') : 0;
        $now         = time();
        $disabled    = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        if ($next_tick === false) {
            $level = 'error';
            $message = __('The newsletter cron event is not scheduled. Disable and re-enable the Newsletter module to fix this.', 'lrob-email-toolkit');
        } elseif ($disabled && $last_tick === 0) {
            $level = 'error';
            $message = __('WordPress pseudo-cron is disabled and the server cron has not run yet. Enable the server cron from the WordPress Toolkit in Plesk (one click), or ask your host\'s support team.', 'lrob-email-toolkit');
        } elseif ($last_tick === 0) {
            $level = 'warn';
            $message = __('The cron is scheduled but has not run yet. Wait a couple of minutes or load any page on your site to trigger it.', 'lrob-email-toolkit');
        } elseif (($now - $last_tick) > 300) {
            $level = 'error';
            $message = __('Cron has not fired in over 5 minutes — scheduled sends will stall. Enable the server cron from the WordPress Toolkit in Plesk, or set up a system cron hitting wp-cron.php every minute.', 'lrob-email-toolkit');
        } elseif (($now - $last_tick) > 120) {
            $level = 'warn';
            $message = __('Cron is firing slowly — sends may stall during low-traffic periods. For reliability, enable the server cron from the WordPress Toolkit in Plesk.', 'lrob-email-toolkit');
        } else {
            return;
        }

        $icon = $level === 'error' ? 'dashicons-warning' : 'dashicons-info';
        $class = $level === 'error' ? 'lrob-etk-banner-error' : 'lrob-etk-banner-warning';
        ?>
        <div class="<?php echo esc_attr($class); ?>" style="margin-top: var(--etk-space-3)">
            <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
            <span><?php echo esc_html($message); ?></span>
        </div>
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
     * @param array<int, array<string, mixed>> $lists
     * @param array<int, array{id:int, label:string}> $identities
     */
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
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--wide">
                <header class="lrob-etk-modal-header">
                    <h3 class="lrob-etk-modal-title-text"><?php esc_html_e('Email preview', 'lrob-email-toolkit'); ?></h3>
                    <nav class="lrob-etk-nl-preview-viewport" role="tablist" aria-label="<?php esc_attr_e('Viewport size', 'lrob-email-toolkit'); ?>">
                        <button type="button" class="lrob-etk-icon-btn lrob-etk-nl-preview-vp is-active" data-preview-viewport="desktop" title="<?php esc_attr_e('Desktop', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Desktop preview', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-desktop" aria-hidden="true"></span>
                        </button>
                        <button type="button" class="lrob-etk-icon-btn lrob-etk-nl-preview-vp" data-preview-viewport="mobile" title="<?php esc_attr_e('Mobile', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Mobile preview', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-smartphone" aria-hidden="true"></span>
                        </button>
                    </nav>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body lrob-etk-nl-preview-body" data-preview-vp="desktop">
                    <iframe data-preview-iframe sandbox></iframe>
                </div>
            </div>
        </div>
        <script>
        // Viewport switcher for the email preview — JS toggles the body's
        // data-preview-vp attribute; CSS handles the iframe sizing.
        (function () {
            if (window.__lrobEtkNlPreviewVpBound) return;
            window.__lrobEtkNlPreviewVpBound = true;
            document.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('[data-preview-viewport]');
                if (!btn) return;
                var modal = btn.closest('.lrob-etk-modal');
                if (!modal) return;
                var vp = btn.getAttribute('data-preview-viewport');
                modal.querySelectorAll('[data-preview-viewport]').forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                });
                var body = modal.querySelector('[data-preview-vp]');
                if (body) body.setAttribute('data-preview-vp', vp);
            });
        })();
        </script>

        <div class="lrob-etk-modal" id="lrob-etk-nl-modal-recipients" role="dialog" aria-modal="true" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--wide">
                <header class="lrob-etk-modal-header">
                    <h3 class="lrob-etk-modal-title-text" data-recipients-title><?php esc_html_e('Recipients', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <!-- Persistent filter + search row. JS hides it for the
                         pre-send "preview" mode (everything's pending, nothing
                         to filter); populates the filter chips per load. -->
                    <div class="lrob-etk-nl-recipients-controls" data-recipients-controls hidden>
                        <div class="lrob-etk-nl-recipients-filters" data-recipients-filters role="tablist" aria-label="<?php esc_attr_e('Recipient status filter', 'lrob-email-toolkit'); ?>"></div>
                        <input type="search"
                               class="lrob-etk-nl-recipients-search"
                               data-recipients-search
                               placeholder="<?php esc_attr_e('Search email or name…', 'lrob-email-toolkit'); ?>"
                               aria-label="<?php esc_attr_e('Search recipients', 'lrob-email-toolkit'); ?>"
                               autocomplete="off">
                    </div>
                    <div data-recipients-body>
                        <p class="lrob-etk-nl-recipients-loading"><?php esc_html_e('Computing recipient set…', 'lrob-email-toolkit'); ?></p>
                    </div>
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
                    <h3 class="lrob-etk-modal-title-text">
                        <span data-delete-variant="trash"><?php esc_html_e('Move to trash', 'lrob-email-toolkit'); ?></span>
                        <span data-delete-variant="permanent" hidden><?php esc_html_e('Delete permanently', 'lrob-email-toolkit'); ?></span>
                    </h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p>
                        <span data-delete-variant="trash"><?php esc_html_e('Move this newsletter to trash?', 'lrob-email-toolkit'); ?></span>
                        <span data-delete-variant="permanent" hidden><?php esc_html_e('Permanently delete this newsletter?', 'lrob-email-toolkit'); ?></span>
                        <strong data-delete-title></strong>
                    </p>
                    <p class="description">
                        <span data-delete-variant="trash"><?php esc_html_e('You can restore it from the Trash tab. Already-sent recipients are not affected.', 'lrob-email-toolkit'); ?></span>
                        <span data-delete-variant="permanent" hidden><?php esc_html_e('This cannot be undone. Already-sent recipients are not affected.', 'lrob-email-toolkit'); ?></span>
                    </p>
                </div>
                <footer class="lrob-etk-modal-footer">
                    <button type="button" class="button" data-modal-close><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></button>
                    <a href="#" class="button lrob-etk-nl-modal-confirm-danger" data-delete-confirm>
                        <span data-delete-variant="trash"><?php esc_html_e('Move to trash', 'lrob-email-toolkit'); ?></span>
                        <span data-delete-variant="permanent" hidden><?php esc_html_e('Delete permanently', 'lrob-email-toolkit'); ?></span>
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
        // After creating, drop the admin on the All tab (newest first) with
        // ?created=<id> so the new card highlights via the .is-just-created
        // fade-in / zoom animation defined in admin-newsletter.css.
        wp_safe_redirect(add_query_arg(
            [
                'page'    => PageController::SLUG,
                'view'    => HomePage::VIEW_NEWSLETTERS,
                'created' => $new_id,
            ],
            admin_url('admin.php')
        ) . '#newsletter-' . $new_id);
        exit;
    }

    /**
     * Soft-delete a newsletter into trash — recoverable via the Trash
     * tab. Sending / paused newsletters can't be trashed (would strand
     * pending recipient rows); the admin must abort first. Permanent
     * deletion lives at handle_delete_permanent().
     */
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
        if ($post->post_status === 'trash') {
            // Idempotent — already trashed, just bounce back.
            $this->redirect_to_tab(NewsletterRepository::TAB_TRASH);
        }
        $companion = $this->newsletters->find_by_post_id($post_id);
        $status = (string) ($companion['status'] ?? NewsletterRepository::STATUS_DRAFT);
        if (in_array($status, [NewsletterRepository::STATUS_SENDING, NewsletterRepository::STATUS_PAUSED], true)) {
            wp_die(esc_html__('This newsletter is currently sending. Abort the send before trashing it.', 'lrob-email-toolkit'));
        }
        // A scheduled newsletter goes to trash unscheduled — flip the
        // companion row back to draft so the cron tick won't pick it
        // up if the post is later restored. Doesn't touch terminal
        // statuses (sent / failed / aborted stay frozen).
        if ($status === NewsletterRepository::STATUS_SCHEDULED) {
            $this->newsletters->update_status($post_id, NewsletterRepository::STATUS_DRAFT);
        }
        wp_trash_post($post_id);
        $this->redirect_to_tab(NewsletterRepository::TAB_TRASH);
    }

    /**
     * Restore a trashed newsletter back into the In-preparation tab.
     * WP's wp_untrash_post() puts the post back at its pre-trash
     * status; we additionally ensure the companion row is draft (the
     * scheduled flip we did on trash means this is usually already
     * the case, but a sent / failed / aborted newsletter can also be
     * restored — those keep their terminal status so they re-appear
     * on the Sent tab).
     */
    public function handle_restore(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $post_id = isset($_GET['post']) ? (int) wp_unslash((string) $_GET['post']) : 0;
        $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::ACTION_RESTORE . '_' . $post_id)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post instanceof \WP_Post || $post->post_type !== NewsletterCPT::POST_TYPE) {
            wp_die(esc_html__('Newsletter not found.', 'lrob-email-toolkit'));
        }
        if ($post->post_status !== 'trash') {
            $this->redirect_to_tab(NewsletterRepository::TAB_IN_PREP);
        }
        wp_untrash_post($post_id);
        $companion = $this->newsletters->find_by_post_id($post_id);
        $status = (string) ($companion['status'] ?? NewsletterRepository::STATUS_DRAFT);
        $is_terminal = in_array($status, [
            NewsletterRepository::STATUS_SENT,
            NewsletterRepository::STATUS_FAILED,
            NewsletterRepository::STATUS_ABORTED,
        ], true);
        $target_tab = $is_terminal ? NewsletterRepository::TAB_SENT : NewsletterRepository::TAB_IN_PREP;
        $this->redirect_to_tab($target_tab, '#newsletter-' . $post_id);
    }

    /**
     * Hard-delete a trashed newsletter. Only allowed on already-trashed
     * posts — call handle_delete() to put a live newsletter in trash
     * first. NewsletterLifecycle::before_delete_post cleans up the
     * companion + recipient rows.
     */
    public function handle_delete_permanent(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $post_id = isset($_GET['post']) ? (int) wp_unslash((string) $_GET['post']) : 0;
        $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::ACTION_DELETE_PERMANENT . '_' . $post_id)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post instanceof \WP_Post || $post->post_type !== NewsletterCPT::POST_TYPE) {
            wp_die(esc_html__('Newsletter not found.', 'lrob-email-toolkit'));
        }
        if ($post->post_status !== 'trash') {
            wp_die(esc_html__('Move this newsletter to trash before permanently deleting it.', 'lrob-email-toolkit'));
        }
        wp_delete_post($post_id, true);
        $this->redirect_to_tab(NewsletterRepository::TAB_TRASH);
    }

    /**
     * Bulk hard-delete every trashed newsletter. Single-tx behaviour
     * isn't worth the complexity here — typical trash is small and
     * each post triggers its own before_delete_post cleanup.
     */
    public function handle_empty_trash(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::ACTION_EMPTY_TRASH)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }
        $trashed = get_posts([
            'post_type'      => NewsletterCPT::POST_TYPE,
            'post_status'    => 'trash',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        foreach ($trashed as $post_id) {
            wp_delete_post((int) $post_id, true);
        }
        $this->redirect_to_tab(NewsletterRepository::TAB_TRASH);
    }

    /**
     * Send the admin back to the Newsletters view with a specific tab
     * preselected. Optional fragment lets restore land on the freshly-
     * restored card.
     */
    private function redirect_to_tab(string $tab, string $fragment = ''): void
    {
        $url = add_query_arg(
            [
                'page'                 => PageController::SLUG,
                'view'                 => HomePage::VIEW_NEWSLETTERS,
                self::TAB_QUERY_VAR    => $tab,
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($url . $fragment);
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

    /** Dashboard tile counter — proxies to the repo so HomePage can show
     *  counts without injecting the repository directly. */
    public function count_for_dashboard(string $bucket): int
    {
        $counts = $this->newsletters->counts_by_tab();
        return (int) ($counts[$bucket] ?? 0);
    }

}
