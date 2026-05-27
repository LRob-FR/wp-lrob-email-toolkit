<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Combobox;
use LRob\EmailToolkit\Admin\PageHeader;
use LRob\EmailToolkit\Admin\Tooltip;
use LRob\EmailToolkit\Container;
use LRob\EmailToolkit\Modules\Newsletter\ListRepository;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterCPT;
use LRob\EmailToolkit\Modules\Newsletter\NewsletterRepository;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendAjaxController;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendCron;
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

    public const ACTION_RESTORE = 'lrob_etk_nl_newsletter_restore';

    public const ACTION_DELETE_PERMANENT = 'lrob_etk_nl_newsletter_delete_permanent';

    public const ACTION_EMPTY_TRASH = 'lrob_etk_nl_newsletter_empty_trash';

    private const TAB_QUERY_VAR = 'tab';

    public function __construct(
        private NewsletterRepository $newsletters,
        private ListRepository $lists,
        private Container $container,
    ) {
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
                        <?php $this->render_card($row, $lists, $identities, $tab); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php $this->render_cron_diagnostic(); ?>
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

    /**
     * Footer panel surfacing WP-Cron health. The Newsletter send
     * pipeline depends on `SendCron` ticking every minute, which on
     * default WP installs relies on pseudo-cron — a non-blocking HTTP
     * loopback to wp-cron.php fired on each page view. Many self-hosted
     * setups (loopback HTTP blocked, low traffic, DISABLE_WP_CRON set,
     * slow servers) break that silently; without this panel the admin
     * only finds out when a scheduled newsletter is overdue.
     *
     * Three health levels:
     *   ok    — last tick within 2 minutes, next tick scheduled in the future.
     *   warn  — last tick 2–5 min ago, OR next tick is overdue but recent.
     *   error — never ticked, last tick >5 min ago, or next tick is not scheduled.
     */
    private function render_cron_diagnostic(): void
    {
        $next_tick   = wp_next_scheduled(SendCron::CRON_HOOK);
        $last_tick_s = (string) get_option(SendCron::OPTION_LAST_TICK, '');
        $last_tick   = $last_tick_s !== '' ? strtotime($last_tick_s . ' UTC') : 0;
        $now         = time();
        $disabled    = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $alt_used    = defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON;

        // Health verdict + level.
        if ($next_tick === false) {
            $level = 'error';
            $verdict = __('The send-tick cron event is not scheduled. Disable and re-enable the Newsletter module to register it again.', 'lrob-email-toolkit');
        } elseif ($disabled && $last_tick === 0) {
            $level = 'error';
            $verdict = __('DISABLE_WP_CRON is set and nothing has triggered the tick yet. You need an external trigger (system cron or a service hitting wp-cron.php).', 'lrob-email-toolkit');
        } elseif ($last_tick === 0) {
            $level = 'warn';
            $verdict = __('The cron is scheduled but has not run yet. Wait a couple of minutes or load any front-end page to fire pseudo-cron.', 'lrob-email-toolkit');
        } elseif (($now - $last_tick) > 300) {
            $level = 'error';
            $verdict = __('Cron has not fired in more than 5 minutes. Scheduled newsletters and crash-recovery ticks will NOT run automatically. Set up a system cron (every minute) hitting wp-cron.php, or use a service like cron-job.org.', 'lrob-email-toolkit');
        } elseif (($now - $last_tick) > 120) {
            $level = 'warn';
            $verdict = __('Cron is firing but slowly — sends may stall during low-traffic periods. Consider a system cron for reliability.', 'lrob-email-toolkit');
        } else {
            $level = 'ok';
            $verdict = __('Cron is healthy.', 'lrob-email-toolkit');
        }

        $date_fmt = get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i:s');
        ?>
        <section class="lrob-etk-nl-cron-diagnostic is-<?php echo esc_attr($level); ?>" data-cron-diagnostic>
            <header class="lrob-etk-nl-cron-diagnostic-head">
                <span class="lrob-etk-nl-cron-badge"><?php
                    echo esc_html(match ($level) {
                        'ok'    => __('Cron healthy', 'lrob-email-toolkit'),
                        'warn'  => __('Cron slow', 'lrob-email-toolkit'),
                        'error' => __('Cron stalled', 'lrob-email-toolkit'),
                        default => $level,
                    });
                ?></span>
                <h3 class="lrob-etk-section-title">
                    <?php esc_html_e('WP-Cron health', 'lrob-email-toolkit'); ?>
                    <?php Tooltip::render(__('A cron is a task that runs on a schedule — every minute, every hour, etc. WordPress uses one to fire off scheduled work like sending queued newsletters, cleaning up trash, or running maintenance. By default it\'s triggered by visitors loading any page on your site (called "pseudo-cron"); a real server cron is more reliable, especially during quiet hours.', 'lrob-email-toolkit')); ?>
                </h3>
                <button type="button" class="button button-small lrob-etk-nl-cron-refresh" data-cron-refresh
                        title="<?php esc_attr_e('Re-fetch cron health + every card state.', 'lrob-email-toolkit'); ?>">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <?php esc_html_e('Refresh', 'lrob-email-toolkit'); ?>
                </button>
                <label class="lrob-etk-nl-cron-autorefresh" title="<?php esc_attr_e('Poll the server every 10 seconds. Off by default — turn it on while diagnosing or watching a send.', 'lrob-email-toolkit'); ?>">
                    <input type="checkbox" data-cron-autorefresh>
                    <?php esc_html_e('Auto-refresh', 'lrob-email-toolkit'); ?>
                </label>
            </header>
            <dl class="lrob-etk-nl-cron-diagnostic-grid">
                <dt><?php esc_html_e('Last observed tick', 'lrob-email-toolkit'); ?></dt>
                <dd data-cron-last-tick="<?php echo (int) $last_tick; ?>">
                    <?php if ($last_tick > 0) : ?>
                        <?php
                        printf(
                            /* translators: 1: relative time span (e.g. "30 seconds"), 2: absolute datetime */
                            wp_kses(__('%1$s ago — %2$s', 'lrob-email-toolkit'), ['span' => ['data-relative-to' => true]]),
                            self::relative_span($last_tick, $now),
                            esc_html((string) wp_date($date_fmt, $last_tick))
                        );
                        ?>
                    <?php else : ?>
                        <?php esc_html_e('never', 'lrob-email-toolkit'); ?>
                    <?php endif; ?>
                </dd>
                <dt><?php esc_html_e('Next scheduled tick', 'lrob-email-toolkit'); ?></dt>
                <dd data-cron-next-tick="<?php echo $next_tick !== false ? (int) $next_tick : 0; ?>">
                    <?php if ($next_tick === false) : ?>
                        <?php esc_html_e('not scheduled', 'lrob-email-toolkit'); ?>
                    <?php elseif ($next_tick > $now) : ?>
                        <?php
                        printf(
                            /* translators: 1: relative time span (e.g. "30 seconds"), 2: absolute datetime */
                            wp_kses(__('in %1$s — %2$s', 'lrob-email-toolkit'), ['span' => ['data-relative-to' => true]]),
                            self::relative_span($next_tick, $now),
                            esc_html((string) wp_date($date_fmt, $next_tick))
                        );
                        ?>
                    <?php else : ?>
                        <?php
                        printf(
                            /* translators: %s: relative time span (how long the tick has been overdue) */
                            wp_kses(__('overdue by %s', 'lrob-email-toolkit'), ['span' => ['data-relative-to' => true]]),
                            self::relative_span($next_tick, $now)
                        );
                        ?>
                    <?php endif; ?>
                </dd>
                <dt><?php esc_html_e('DISABLE_WP_CRON', 'lrob-email-toolkit'); ?></dt>
                <dd><?php echo $disabled ? esc_html__('yes — pseudo-cron is off, you need an external trigger', 'lrob-email-toolkit') : esc_html__('no — pseudo-cron runs on page hits', 'lrob-email-toolkit'); ?></dd>
                <?php if ($alt_used) : ?>
                    <dt><?php esc_html_e('ALTERNATE_WP_CRON', 'lrob-email-toolkit'); ?></dt>
                    <dd><?php esc_html_e('yes', 'lrob-email-toolkit'); ?></dd>
                <?php endif; ?>
            </dl>
            <p class="lrob-etk-nl-cron-diagnostic-verdict"><?php echo esc_html($verdict); ?></p>
            <?php if (!$disabled) : ?>
                <p class="lrob-etk-nl-cron-tip">
                    <strong><?php esc_html_e('Tip:', 'lrob-email-toolkit'); ?></strong>
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: %s: <a href="…">LRob</a> link to lrob.fr */
                            __('You can use %s to switch to a server cron instead.', 'lrob-email-toolkit'),
                            '<a href="https://www.lrob.fr/" target="_blank" rel="noopener noreferrer">LRob</a>'
                        ),
                        ['a' => ['href' => true, 'target' => true, 'rel' => true]]
                    );
                    Tooltip::render(__('Server crons run every minute regardless of traffic — more reliable for scheduled sends on low-traffic sites, and no per-page loopback HTTP request. LRob configures this in a click (or assists at no extra cost) and ships with 2000 mails/h enabled by default (more on demand).', 'lrob-email-toolkit'));
                    ?>
                </p>
            <?php endif; ?>
        </section>
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
    private function render_card(array $row, array $lists, array $identities, string $tab): void
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
        ?>
        <article class="lrob-etk-card lrob-etk-nl-card<?php echo $is_trashed ? ' is-trashed' : ''; ?><?php echo ($_GET['created'] ?? '') === (string) $post_id ? ' is-just-created' : ''; ?>" data-newsletter-status="<?php echo esc_attr((string) ($row['status'] ?? 'draft')); ?>"
                 data-newsletter-id="<?php echo $post_id; ?>"
                 data-status="<?php echo esc_attr($effective_status); ?>"
                 id="newsletter-<?php echo $post_id; ?>">
            <div class="lrob-etk-card-form">
                <header class="lrob-etk-card-form-head">
                    <div class="lrob-etk-nl-card-title-wrap">
                        <div class="lrob-etk-nl-card-title-row">
                            <span class="lrob-etk-nl-card-title-label"><?php esc_html_e('Subject', 'lrob-email-toolkit'); ?></span>
                            <span class="lrob-etk-nl-status lrob-etk-nl-status-<?php echo esc_attr($effective_status); ?>"
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
                            /* translators: %1$s: relative time span (e.g. "5 hours"), %2$s: absolute datetime */
                            wp_kses(__('Sent %1$s ago, on %2$s', 'lrob-email-toolkit'), ['span' => ['data-relative-to' => true]]),
                            self::relative_span($sent_ts, time()),
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
                } elseif ($scheduled_local !== '' && !$is_terminal && !$is_trashed) {
                    $sched_ts = strtotime($scheduled_at . ' UTC');
                    $sched_pretty = $sched_ts !== false
                        ? (string) wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $sched_ts)
                        : '';
                    if ($sched_ts !== false) {
                        if ($status === NewsletterRepository::STATUS_DRAFT) {
                            // Date saved but the admin hasn't clicked Schedule
                            // yet — the commit step is now explicit (see
                            // SendAjaxController::handle_commit_schedule).
                            $status_msg = sprintf(
                                /* translators: %s: absolute datetime */
                                __('Schedule set for %s — click Schedule to commit.', 'lrob-email-toolkit'),
                                $sched_pretty
                            );
                            $status_msg_class = 'is-info';
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
                                $status_msg_class = 'is-error';
                            }
                            $status_msg = sprintf(
                                /* translators: %1$s: absolute datetime of the scheduled send, %2$s: cron-tick info */
                                __('Scheduled for %1$s (overdue — %2$s).', 'lrob-email-toolkit'),
                                $sched_pretty,
                                $cron_info
                            );
                            if (!isset($status_msg_class) || $status_msg_class === 'is-info') {
                                $status_msg_class = 'is-warn';
                            }
                        }
                    }
                }
                ?>
                <p class="lrob-etk-nl-card-status-msg <?php echo esc_attr($status_msg_class); ?>" data-status-msg <?php echo $status_msg === '' ? 'hidden' : ''; ?>>
                    <?php
                    // Some branches inject `<span data-relative-to>` for
                    // the live clock-tick — wp_kses allows it through.
                    // Other branches are plain text; wp_kses leaves them
                    // alone (no HTML to strip).
                    echo wp_kses($status_msg, ['span' => ['data-relative-to' => true]]);
                    ?>
                </p>

                <?php
                $shown_title_attr = $title !== '' ? $title : __('(untitled)', 'lrob-email-toolkit');
                if ($is_trashed) {
                    $restore_url = wp_nonce_url(
                        add_query_arg(['action' => self::ACTION_RESTORE, 'post' => $post_id], admin_url('admin-post.php')),
                        self::ACTION_RESTORE . '_' . $post_id
                    );
                    $delete_permanent_url = wp_nonce_url(
                        add_query_arg(['action' => self::ACTION_DELETE_PERMANENT, 'post' => $post_id], admin_url('admin-post.php')),
                        self::ACTION_DELETE_PERMANENT . '_' . $post_id
                    );
                    ?>
                    <footer class="lrob-etk-card-footer">
                        <a href="<?php echo esc_url($restore_url); ?>" class="lrob-etk-card-delete-link lrob-etk-nl-card-restore-link">
                            <span class="dashicons dashicons-undo" aria-hidden="true"></span>
                            <?php esc_html_e('Restore', 'lrob-email-toolkit'); ?>
                        </a>
                        <button type="button"
                                class="lrob-etk-card-delete-link"
                                data-card-delete
                                data-delete-mode="permanent"
                                data-newsletter-title="<?php echo esc_attr($shown_title_attr); ?>"
                                data-delete-url="<?php echo esc_attr($delete_permanent_url); ?>">
                            <?php esc_html_e('Delete permanently', 'lrob-email-toolkit'); ?>
                        </button>
                    </footer>
                    <?php
                } else {
                    $delete_url = wp_nonce_url(
                        add_query_arg(['action' => self::ACTION_DELETE, 'post' => $post_id], admin_url('admin-post.php')),
                        self::ACTION_DELETE . '_' . $post_id
                    );
                    $duplicate_url = wp_nonce_url(
                        add_query_arg(['action' => self::ACTION_DUPLICATE, 'post' => $post_id], admin_url('admin-post.php')),
                        self::ACTION_DUPLICATE . '_' . $post_id
                    );
                    // Trashing is forbidden while a send is mid-flight to
                    // avoid stranding pending recipient rows; admin must
                    // abort first.
                    $can_trash = !$is_sending && !$is_paused;
                    ?>
                    <footer class="lrob-etk-card-footer">
                        <a href="<?php echo esc_url($duplicate_url); ?>" class="lrob-etk-card-delete-link lrob-etk-nl-card-duplicate-link">
                            <?php esc_html_e('Duplicate', 'lrob-email-toolkit'); ?>
                        </a>
                        <?php if ($can_trash) : ?>
                            <button type="button"
                                    class="lrob-etk-card-delete-link"
                                    data-card-delete
                                    data-delete-mode="trash"
                                    data-newsletter-title="<?php echo esc_attr($shown_title_attr); ?>"
                                    data-delete-url="<?php echo esc_attr($delete_url); ?>">
                                <?php esc_html_e('Trash', 'lrob-email-toolkit'); ?>
                            </button>
                        <?php else : ?>
                            <button type="button"
                                    class="lrob-etk-card-delete-link"
                                    disabled
                                    title="<?php esc_attr_e('Abort the send first, then this can be moved to trash.', 'lrob-email-toolkit'); ?>">
                                <?php esc_html_e('Trash', 'lrob-email-toolkit'); ?>
                            </button>
                        <?php endif; ?>
                    </footer>
                    <?php
                }
                ?>
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
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--wide">
                <header class="lrob-etk-modal-header">
                    <h3 class="lrob-etk-modal-title-text"><?php esc_html_e('Email preview', 'lrob-email-toolkit'); ?></h3>
                    <nav class="lrob-etk-nl-preview-viewport" role="tablist" aria-label="<?php esc_attr_e('Viewport size', 'lrob-email-toolkit'); ?>">
                        <button type="button" class="lrob-etk-icon-btn lrob-etk-nl-preview-vp is-active" data-preview-viewport="desktop" title="<?php esc_attr_e('Desktop', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Desktop preview', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-desktop" aria-hidden="true"></span>
                        </button>
                        <button type="button" class="lrob-etk-icon-btn lrob-etk-nl-preview-vp" data-preview-viewport="tablet" title="<?php esc_attr_e('Tablet', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Tablet preview', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-tablet" aria-hidden="true"></span>
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
