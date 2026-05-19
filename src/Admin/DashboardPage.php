<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\ContactForm\CPT as ContactFormCPT;
use LRob\EmailToolkit\Modules\ContactForm\SubmissionRepository as ContactFormSubmissions;
use LRob\EmailToolkit\Modules\Logging\Admin\PageController as LogsPageController;
use LRob\EmailToolkit\Modules\Logging\LogEntry;
use LRob\EmailToolkit\Modules\Logging\LogRepository;
use LRob\EmailToolkit\Modules\Logging\Module as LoggingModule;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\ModuleManager;
use LRob\EmailToolkit\Modules\SMTP\Admin\AjaxController as SmtpAjaxController;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;

/**
 * Plugin landing page. Stat cards, an SVG activity chart with adaptive
 * range + bucket size + chart type toggle, modules grid (with a Test email
 * button on the SMTP card), failure-rate banner, and recent activity.
 *
 * All chart data for every range is pre-computed server-side and embedded
 * as JSON in the page; range/type changes are handled entirely client-side
 * so they're snappy and don't roundtrip to the server.
 */
final class DashboardPage
{
    /** @var array<string, array{interval:string, bucket_seconds:int}> */
    private const RANGES = [
        '1h'  => ['interval' => 'PT1H',  'bucket_seconds' => 60],      // 60 buckets of 1 min
        '24h' => ['interval' => 'P1D',   'bucket_seconds' => 900],     // 96 buckets of 15 min
        '7d'  => ['interval' => 'P7D',   'bucket_seconds' => 7200],    // 84 buckets of 2 hours
        '30d' => ['interval' => 'P30D',  'bucket_seconds' => 86400],   // 30 buckets of 1 day
        '1y'  => ['interval' => 'P1Y',   'bucket_seconds' => 604800],  // 52 buckets of 1 week
    ];

    /** Stats range keys (subset of RANGES, no "all"). */
    private const STAT_RANGES = ['24h', '7d', '30d', '1y'];

    public function __construct(private ModuleManager $manager)
    {
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }

        $logging = $this->manager->get('logging');
        $logging_on = $logging instanceof ModuleInterface && $logging->is_enabled();
        $repository = $logging_on ? new LogRepository() : null;

        $stats = $repository ? $this->compute_stats($repository) : null;
        $chart_payload = $repository ? $this->compute_chart_payload($repository) : null;
        $failure_warning = $stats ? $this->compute_failure_warning($stats) : null;
        ?>
        <div class="wrap lrob-etk">
            <h1 class="lrob-etk-page-title"><?php esc_html_e('Email Toolkit', 'lrob-email-toolkit'); ?></h1>

            <div id="lrob-etk-flash" class="lrob-etk-flash" aria-live="polite"></div>

            <?php if ($failure_warning !== null) : ?>
                <?php $this->render_failure_warning($failure_warning); ?>
            <?php endif; ?>

            <h2 class="lrob-etk-section-title"><?php esc_html_e('Email activity', 'lrob-email-toolkit'); ?></h2>

            <?php if ($stats !== null) : ?>
                <div class="lrob-etk-activity-layout">
                    <?php $this->render_stats_grid($stats); ?>
                    <?php if ($chart_payload !== null) : ?>
                        <?php $this->render_chart_container(); ?>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <p class="lrob-etk-disabled-message">
                    <?php esc_html_e('Enable Email Logging to track sent/failed emails and see activity charts here.', 'lrob-email-toolkit'); ?>
                </p>
            <?php endif; ?>

            <?php $this->render_modules_grid(); ?>
            <?php $this->render_recent_activity($repository); ?>

            <?php $this->render_test_email_popover(); ?>

            <?php $this->render_plugin_data_card(); ?>

            <p class="lrob-etk-footer">
                <?php
                /* translators: %s: HTML link to LRob's website */
                $message = __('Built with care by %s.', 'lrob-email-toolkit');
                printf(
                    wp_kses($message, ['a' => ['href' => [], 'target' => [], 'rel' => []]]),
                    '<a href="https://www.lrob.fr" target="_blank" rel="noopener">LRob</a>'
                );
                ?>
            </p>
        </div>

        <script>
        <?php $this->print_inline_js($chart_payload); ?>
        </script>
        <?php
    }

    /**
     * @return array<string, array{sent:int, failed:int, total:int, fail_rate:int}>
     */
    private function compute_stats(LogRepository $repository): array
    {
        $now_utc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $stats = [];
        foreach (self::STAT_RANGES as $key) {
            $r = self::RANGES[$key];
            try {
                $from = $now_utc->sub(new \DateInterval($r['interval']));
            } catch (\Exception) {
                continue;
            }
            $from_sql = $from->format('Y-m-d H:i:s');
            $sent   = $repository->count(['status' => LogEntry::STATUS_SENT,   'date_from' => $from_sql]);
            $failed = $repository->count(['status' => LogEntry::STATUS_FAILED, 'date_from' => $from_sql]);
            $total = $sent + $failed;
            $fail_rate = $total > 0 ? (int) round(($failed / $total) * 100) : 0;
            $stats[$key] = compact('sent', 'failed', 'total', 'fail_rate');
        }
        return $stats;
    }

    /**
     * Pre-compute every range's chart data once. JS picks the active range
     * and re-renders the SVG locally without another server roundtrip.
     *
     * @return array{ranges: array<string, array>, default: string, empty: bool}
     */
    private function compute_chart_payload(LogRepository $repository): array
    {
        $oldest = $repository->oldest_log_time();
        if ($oldest === null) {
            return ['ranges' => [], 'default' => '30d', 'empty' => true];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ranges = [];

        // Fixed ranges
        foreach (self::RANGES as $key => $r) {
            try {
                $from = $now->sub(new \DateInterval($r['interval']));
            } catch (\Exception) {
                continue;
            }
            $ranges[$key] = $this->build_range_data($repository, $from, $now, $r['bucket_seconds']);
        }

        // "All" — adaptive bucket size based on actual data span
        $span = max(60, $now->getTimestamp() - $oldest->getTimestamp());
        $all_bucket = $this->pick_bucket_for_span($span);
        $ranges['all'] = $this->build_range_data($repository, $oldest, $now, $all_bucket);

        // Pick a sensible default: pick the smallest range that actually has data,
        // or fall back to 30d.
        $default = '30d';
        foreach (['24h', '7d', '30d', '1y'] as $candidate) {
            if (($ranges[$candidate]['total'] ?? 0) > 0) {
                $default = $candidate;
                break;
            }
        }
        // If even 1y is empty, the data exists older — use 'all'.
        if (($ranges[$default]['total'] ?? 0) === 0 && ($ranges['all']['total'] ?? 0) > 0) {
            $default = 'all';
        }

        return ['ranges' => $ranges, 'default' => $default, 'empty' => false];
    }

    /**
     * @return array{buckets: array<int, array{ts:int, sent:int, failed:int}>, bucket_seconds:int, from:int, to:int, total:int, max:int}
     */
    private function build_range_data(LogRepository $repository, \DateTimeImmutable $from, \DateTimeImmutable $to, int $bucket_seconds): array
    {
        $buckets = $repository->counts_by_bucket($from, $to, $bucket_seconds);
        $total = 0;
        $max = 0;
        foreach ($buckets as $b) {
            $bucket_total = (int) $b['sent'] + (int) $b['failed'];
            $total += $bucket_total;
            if ($bucket_total > $max) {
                $max = $bucket_total;
            }
        }
        return [
            'buckets'        => $buckets,
            'bucket_seconds' => $bucket_seconds,
            'from'           => $from->getTimestamp(),
            'to'             => $to->getTimestamp(),
            'total'          => $total,
            'max'            => $max,
        ];
    }

    /** Pick a bucket size that yields roughly 60 buckets across the span. */
    private function pick_bucket_for_span(int $span_seconds): int
    {
        // target ~60 buckets so "All" looks as detailed as the fixed ranges
        $target = max(1, (int) round($span_seconds / 60));
        // round to a sensible granularity
        $choices = [60, 300, 900, 1800, 3600, 7200, 14400, 21600, 43200, 86400, 172800, 604800, 1209600, 2592000];
        foreach ($choices as $c) {
            if ($target <= $c) {
                return $c;
            }
        }
        return end($choices);
    }

    /**
     * @param array<string, array{sent:int, failed:int, total:int, fail_rate:int}> $stats
     * @return array{failed:int, total:int, rate:int}|null
     */
    private function compute_failure_warning(array $stats): ?array
    {
        if (!isset($stats['24h'])) {
            return null;
        }
        $s = $stats['24h'];
        if ($s['total'] < 4 || $s['fail_rate'] <= 25) {
            return null;
        }
        return ['failed' => $s['failed'], 'total' => $s['total'], 'rate' => $s['fail_rate']];
    }

    /** @param array{failed:int, total:int, rate:int} $w */
    private function render_failure_warning(array $w): void
    {
        $logs_url = admin_url('admin.php?page=lrob-etk-logs&status=' . LogEntry::STATUS_FAILED);
        ?>
        <div class="lrob-etk-failure-banner">
            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
            <div>
                <strong><?php
                    printf(
                        /* translators: 1: failed count, 2: total count, 3: percentage */
                        esc_html__('%1$d of %2$d emails failed in the last 24 hours (%3$d%% failure rate).', 'lrob-email-toolkit'),
                        (int) $w['failed'],
                        (int) $w['total'],
                        (int) $w['rate']
                    );
                ?></strong>
                <span><?php esc_html_e('This may indicate an SMTP misconfiguration, spam being sent from the site (compromised account or vulnerable form), or a poor recipient list (e.g. an outdated newsletter list with bounces).', 'lrob-email-toolkit'); ?></span>
            </div>
            <a href="<?php echo esc_url($logs_url); ?>" class="button">
                <?php esc_html_e('View failed logs →', 'lrob-email-toolkit'); ?>
            </a>
        </div>
        <?php
    }

    /** @param array<string, array{sent:int, failed:int, total:int, fail_rate:int}> $stats */
    private function render_stats_grid(array $stats): void
    {
        $labels = [
            '24h' => __('Last 24 hours', 'lrob-email-toolkit'),
            '7d'  => __('Last 7 days', 'lrob-email-toolkit'),
            '30d' => __('Last 30 days', 'lrob-email-toolkit'),
            '1y'  => __('Last year', 'lrob-email-toolkit'),
        ];
        $logs_base = admin_url('admin.php?page=' . LogsPageController::SLUG);
        // Browser-local "today" reference. The stat windows themselves are
        // computed server-side in UTC (compute_stats), so a click rounds
        // back to the user's calendar day — close enough for a quick drill-in.
        $today = current_time('Y-m-d');
        $now_ts = (int) current_time('timestamp');
        $date_from_for_range = [
            '24h' => gmdate('Y-m-d', $now_ts - DAY_IN_SECONDS),
            '7d'  => gmdate('Y-m-d', $now_ts - 7 * DAY_IN_SECONDS),
            '30d' => gmdate('Y-m-d', $now_ts - 30 * DAY_IN_SECONDS),
            '1y'  => gmdate('Y-m-d', $now_ts - 365 * DAY_IN_SECONDS),
        ];
        ?>
        <div class="lrob-etk-stat-grid">
            <?php foreach (self::STAT_RANGES as $key) :
                $s = $stats[$key] ?? ['sent' => 0, 'failed' => 0, 'total' => 0, 'fail_rate' => 0];
                $is_danger = $s['fail_rate'] > 25 && $s['total'] >= 4;
                $link = add_query_arg([
                    'date_from' => $date_from_for_range[$key] ?? '',
                    'date_to'   => $today,
                ], $logs_base);
                ?>
                <a class="lrob-etk-stat-card <?php echo $is_danger ? 'is-warning' : ''; ?>"
                   href="<?php echo esc_url($link); ?>"
                   aria-label="<?php
                        echo esc_attr(sprintf(
                            /* translators: 1: number of emails, 2: time-range label (e.g. "Last 7 days") */
                            __('%1$s emails — %2$s. Open logs filtered to this range.', 'lrob-email-toolkit'),
                            number_format_i18n((int) $s['total']),
                            $labels[$key] ?? $key
                        ));
                   ?>">
                    <p class="lrob-etk-stat-label"><?php echo esc_html($labels[$key] ?? $key); ?></p>
                    <div class="lrob-etk-stat-main">
                        <span class="lrob-etk-stat-value"><?php echo number_format_i18n((int) $s['total']); ?></span>
                        <span class="lrob-etk-stat-unit"><?php
                            echo esc_html(_n('mail sent', 'mails sent', (int) $s['total'], 'lrob-email-toolkit'));
                        ?></span>
                    </div>
                    <p class="lrob-etk-stat-sub <?php echo $s['failed'] > 0 ? 'is-danger' : ''; ?>">
                        <?php if ($s['failed'] > 0) : ?>
                            <strong><?php echo number_format_i18n((int) $s['failed']); ?></strong>
                            <?php echo esc_html(_n('failed', 'failed', (int) $s['failed'], 'lrob-email-toolkit')); ?>
                            <span class="lrob-etk-stat-rate"><?php echo (int) $s['fail_rate']; ?>%</span>
                        <?php elseif ($s['total'] > 0) : ?>
                            <?php esc_html_e('all delivered', 'lrob-email-toolkit'); ?>
                        <?php else : ?>
                            <span class="lrob-etk-stat-empty"><?php esc_html_e('no activity', 'lrob-email-toolkit'); ?></span>
                        <?php endif; ?>
                    </p>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_chart_container(): void
    {
        ?>
        <div class="lrob-etk-chart-wrap" id="lrob-etk-chart">
            <div class="lrob-etk-chart-controls">
                <div class="lrob-etk-chart-range">
                    <label for="lrob-etk-chart-range"><?php esc_html_e('Range:', 'lrob-email-toolkit'); ?></label>
                    <select id="lrob-etk-chart-range" class="lrob-etk-select">
                        <option value="1h"><?php esc_html_e('Last hour', 'lrob-email-toolkit'); ?></option>
                        <option value="24h"><?php esc_html_e('Last 24 hours', 'lrob-email-toolkit'); ?></option>
                        <option value="7d"><?php esc_html_e('Last 7 days', 'lrob-email-toolkit'); ?></option>
                        <option value="30d"><?php esc_html_e('Last 30 days', 'lrob-email-toolkit'); ?></option>
                        <option value="1y"><?php esc_html_e('Last year', 'lrob-email-toolkit'); ?></option>
                        <option value="all"><?php esc_html_e('Since beginning', 'lrob-email-toolkit'); ?></option>
                    </select>
                </div>
                <div class="lrob-etk-chart-type">
                    <button type="button" data-chart-type="smooth" class="is-active" title="<?php esc_attr_e('Smoothed line', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Smoothed line chart', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-chart-area"></span>
                    </button>
                    <button type="button" data-chart-type="line" title="<?php esc_attr_e('Line', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Line chart', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-chart-line"></span>
                    </button>
                    <button type="button" data-chart-type="bars" title="<?php esc_attr_e('Bars', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Bar chart', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-chart-bar"></span>
                    </button>
                </div>
            </div>
            <div class="lrob-etk-chart-canvas" id="lrob-etk-chart-canvas"></div>
            <div class="lrob-etk-chart-tooltip" id="lrob-etk-chart-tooltip" hidden></div>
            <p class="lrob-etk-chart-empty" id="lrob-etk-chart-empty" hidden>
                <?php esc_html_e('No emails yet — once your site starts sending, activity will appear here.', 'lrob-email-toolkit'); ?>
            </p>
        </div>
        <?php
    }

    private function render_modules_grid(): void
    {
        $modules = $this->manager->all();
        $action_url = admin_url('admin-post.php');
        $identities = (new IdentityRepository())->all();
        ?>
        <h2 class="lrob-etk-section-title"><?php esc_html_e('Modules', 'lrob-email-toolkit'); ?></h2>
        <div class="lrob-etk-modules-grid">
            <?php foreach ($modules as $module) :
                $enabled = $module->is_enabled();
                $url = $module->admin_page_url();
                $is_coming = $url === null;
                $is_service = $module->is_service_module();
                $card_class = $is_coming ? 'is-coming' : ($enabled ? 'is-on' : '');
                $slug = $module->slug();
                $is_smtp = $slug === 'smtp';
                $is_cf = $slug === 'contact_form';
                $can_test = $is_smtp && $identities !== [];

                // Per-module enrichments rendered inside the card body.
                $cf_forms_count = 0;
                $cf_submissions = 0;
                $cf_new_form_url = '';
                if ($is_cf && $enabled) {
                    $cf_forms_count = (int) wp_count_posts(ContactFormCPT::POST_TYPE)->publish
                                    + (int) wp_count_posts(ContactFormCPT::POST_TYPE)->draft;
                    $cf_submissions = (new ContactFormSubmissions())->count_total();
                    $cf_new_form_url = admin_url('post-new.php?post_type=' . ContactFormCPT::POST_TYPE);
                }
                ?>
                <div class="lrob-etk-module-card <?php echo esc_attr($card_class); ?>">
                    <div class="lrob-etk-module-card-head">
                        <h3><?php echo esc_html($module->name()); ?></h3>
                        <?php if ($is_coming) : ?>
                            <span class="lrob-etk-status lrob-etk-status--pending"><?php esc_html_e('Coming soon', 'lrob-email-toolkit'); ?></span>
                        <?php elseif ($is_service) : ?>
                            <span class="lrob-etk-status lrob-etk-status--on"><?php esc_html_e('Always on', 'lrob-email-toolkit'); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="lrob-etk-module-card-description"><?php echo esc_html($module->description()); ?></p>

                    <?php if ($is_cf && $enabled) : ?>
                        <div class="lrob-etk-module-card-summary">
                            <span><strong><?php echo number_format_i18n($cf_forms_count); ?></strong>
                                <?php echo esc_html(_n('form', 'forms', $cf_forms_count, 'lrob-email-toolkit')); ?></span>
                            <span class="lrob-etk-module-card-summary-sep">·</span>
                            <span><strong><?php echo number_format_i18n($cf_submissions); ?></strong>
                                <?php echo esc_html(_n('submission', 'submissions', $cf_submissions, 'lrob-email-toolkit')); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!$is_coming) : ?>
                        <div class="lrob-etk-module-card-actions">
                            <?php if (!$is_service) : ?>
                                <form method="post" action="<?php echo esc_url($action_url); ?>" class="lrob-etk-page-toggle-form">
                                    <input type="hidden" name="action" value="<?php echo esc_attr($module->toggle_action()); ?>">
                                    <?php wp_nonce_field($module->toggle_action(), '_lrob_etk_nonce'); ?>
                                    <label class="lrob-etk-page-toggle">
                                        <input type="checkbox" name="enable" value="1" <?php checked($enabled); ?>
                                               onchange="this.form.submit()">
                                        <span class="lrob-etk-switch-track"></span>
                                        <span class="lrob-etk-page-toggle-state">
                                            <?php echo $enabled
                                                ? esc_html__('Enabled', 'lrob-email-toolkit')
                                                : esc_html__('Disabled', 'lrob-email-toolkit'); ?>
                                        </span>
                                    </label>
                                </form>
                            <?php else : ?>
                                <span></span>
                            <?php endif; ?>
                            <div class="lrob-etk-module-card-buttons">
                                <?php if ($can_test) : ?>
                                    <button type="button" class="button lrob-etk-module-test-btn" data-test-email>
                                        <span class="dashicons dashicons-email"></span>
                                        <?php esc_html_e('Test email', 'lrob-email-toolkit'); ?>
                                    </button>
                                <?php endif; ?>
                                <?php if ($is_cf && $enabled) : ?>
                                    <a href="<?php echo esc_url($cf_new_form_url); ?>" class="button button-primary">
                                        <?php esc_html_e('Add new', 'lrob-email-toolkit'); ?>
                                    </a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url($url); ?>" class="button">
                                    <?php esc_html_e('Manage', 'lrob-email-toolkit'); ?>
                                    <span aria-hidden="true">→</span>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Per-module data summary card with a CTA to the (hidden-in-menu) Data
     * page. Only modules that report a non-empty data_summary() show up;
     * keeps the card compact and useful.
     */
    private function render_plugin_data_card(): void
    {
        $rows = [];
        foreach ($this->manager->all() as $module) {
            $summary = $module->data_summary();
            if ($summary === '') {
                continue;
            }
            $rows[] = ['name' => $module->name(), 'summary' => $summary];
        }
        ?>
        <h2 class="lrob-etk-section-title"><?php esc_html_e('Plugin data', 'lrob-email-toolkit'); ?></h2>
        <div class="lrob-etk-data-summary-card">
            <?php if ($rows === []) : ?>
                <p class="lrob-etk-data-summary-empty">
                    <?php esc_html_e('No data stored yet. Enable a module to start logging activity.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else : ?>
                <ul class="lrob-etk-data-summary-list">
                    <?php foreach ($rows as $row) : ?>
                        <li>
                            <span class="lrob-etk-data-summary-name"><?php echo esc_html($row['name']); ?></span>
                            <span class="lrob-etk-data-summary-value"><?php echo esc_html($row['summary']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="lrob-etk-data-summary-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . DataPage::SLUG)); ?>" class="button">
                    <span class="dashicons dashicons-database-view"></span>
                    <?php esc_html_e('Manage plugin data', 'lrob-email-toolkit'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    private function render_test_email_popover(): void
    {
        $identities = (new IdentityRepository())->all();
        if ($identities === []) {
            return;
        }
        $current_user = wp_get_current_user();
        $admin_email = (string) get_option('admin_email');
        ?>
        <div class="lrob-etk-popover" id="lrob-etk-dashboard-test-popover" role="dialog" aria-label="<?php esc_attr_e('Send test email', 'lrob-email-toolkit'); ?>" hidden>
            <header class="lrob-etk-popover-header">
                <h3><?php esc_html_e('Send test email', 'lrob-email-toolkit'); ?></h3>
                <button type="button" class="lrob-etk-popover-close" data-popover-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </header>
            <div class="lrob-etk-popover-body">
                <div class="lrob-etk-field">
                    <label for="lrob-etk-dashboard-test-identity"><?php esc_html_e('Identity (source)', 'lrob-email-toolkit'); ?></label>
                    <select id="lrob-etk-dashboard-test-identity" class="lrob-etk-select">
                        <?php foreach ($identities as $identity) : ?>
                            <option value="<?php echo (int) $identity->id; ?>" <?php selected($identity->is_default); ?>>
                                <?php echo esc_html($identity->label); ?>
                                <?php if ($identity->is_default) : ?>
                                    <?php esc_html_e(' (default)', 'lrob-email-toolkit'); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lrob-etk-field">
                    <label for="lrob-etk-dashboard-test-choice"><?php esc_html_e('Recipient', 'lrob-email-toolkit'); ?></label>
                    <select id="lrob-etk-dashboard-test-choice" class="lrob-etk-select">
                        <option value="current"><?php echo esc_html(sprintf(
                            /* translators: %s: the current logged-in user's email address */
                            __('Me (%s)', 'lrob-email-toolkit'),
                            $current_user->user_email
                        )); ?></option>
                        <option value="admin"><?php echo esc_html(sprintf(
                            /* translators: %s: the site's admin email address */
                            __('Site admin (%s)', 'lrob-email-toolkit'),
                            $admin_email
                        )); ?></option>
                        <option value="custom"><?php esc_html_e('Custom…', 'lrob-email-toolkit'); ?></option>
                    </select>
                </div>
                <div class="lrob-etk-field" id="lrob-etk-dashboard-test-custom-wrap" hidden>
                    <label for="lrob-etk-dashboard-test-custom"><?php esc_html_e('Custom recipient', 'lrob-email-toolkit'); ?></label>
                    <input type="email" id="lrob-etk-dashboard-test-custom" placeholder="you@example.com">
                </div>
                <div class="lrob-etk-test-result" id="lrob-etk-dashboard-test-result" hidden></div>
            </div>
            <footer class="lrob-etk-popover-footer">
                <button type="button" class="button" data-popover-close><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></button>
                <button type="button" class="button button-primary" id="lrob-etk-dashboard-test-send">
                    <?php esc_html_e('Send', 'lrob-email-toolkit'); ?>
                </button>
            </footer>
        </div>
        <?php
    }

    private function render_recent_activity(?LogRepository $repository): void
    {
        if (!$repository) {
            return;
        }
        $recent = $repository->paginate([], 1, 5);
        if ($recent === []) {
            return;
        }
        ?>
        <h2 class="lrob-etk-section-title"><?php esc_html_e('Recent activity', 'lrob-email-toolkit'); ?></h2>
        <div class="lrob-etk-logs-table-wrap">
            <table class="lrob-etk-logs-table">
                <thead>
                    <tr>
                        <th class="col-date"><?php esc_html_e('Date', 'lrob-email-toolkit'); ?></th>
                        <th class="col-status"><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                        <th class="col-to"><?php esc_html_e('To', 'lrob-email-toolkit'); ?></th>
                        <th class="col-subject"><?php esc_html_e('Subject', 'lrob-email-toolkit'); ?></th>
                        <th class="col-source"><?php esc_html_e('Source', 'lrob-email-toolkit'); ?></th>
                        <th class="col-actions"><?php esc_html_e('Actions', 'lrob-email-toolkit'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $entry) :
                        $view_url = add_query_arg(
                            ['page' => LogsPageController::SLUG, 'action' => 'view', 'id' => $entry->id],
                            admin_url('admin.php')
                        );
                        $to_summary = implode(', ', array_slice($entry->to_emails, 0, 2));
                        if (count($entry->to_emails) > 2) {
                            $to_summary .= ' …';
                        }
                        $subject = $entry->subject !== '' ? $entry->subject : __('(no subject)', 'lrob-email-toolkit');
                        ?>
                        <tr>
                            <td class="col-date">
                                <?php echo esc_html($entry->created_at->setTimezone(wp_timezone())->format('Y-m-d H:i:s')); ?>
                            </td>
                            <td class="col-status">
                                <span class="lrob-etk-status <?php echo esc_attr($this->status_class($entry->status)); ?>">
                                    <?php echo esc_html($entry->status); ?>
                                </span>
                            </td>
                            <td class="col-to"><?php echo esc_html($to_summary); ?></td>
                            <td class="col-subject">
                                <a href="<?php echo esc_url($view_url); ?>" class="lrob-etk-subject-link">
                                    <?php echo esc_html($subject); ?>
                                </a>
                            </td>
                            <td class="col-source"><code><?php echo esc_html($entry->source); ?></code></td>
                            <td class="col-actions">
                                <a href="<?php echo esc_url($view_url); ?>" class="lrob-etk-row-action" title="<?php esc_attr_e('View', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('View log entry', 'lrob-email-toolkit'); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="lrob-etk-recent-activity-footer">
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . LogsPageController::SLUG)); ?>" class="button">
                <?php esc_html_e('View all logs', 'lrob-email-toolkit'); ?>
                <span aria-hidden="true">→</span>
            </a>
        </p>
        <?php
    }

    private function status_class(string $status): string
    {
        return match ($status) {
            LogEntry::STATUS_SENT    => 'lrob-etk-status--on',
            LogEntry::STATUS_FAILED  => 'lrob-etk-status--fail',
            LogEntry::STATUS_SENDING => 'lrob-etk-status--pending',
            default                  => 'lrob-etk-status--off',
        };
    }

    /** @param array{ranges: array, default: string, empty: bool}|null $chart_payload */
    private function print_inline_js(?array $chart_payload): void
    {
        ?>
        window.lrobEtkDashboard = {
            ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
            nonce: <?php echo wp_json_encode(wp_create_nonce(SmtpAjaxController::NONCE_ACTION)); ?>,
            testAction: <?php echo wp_json_encode(SmtpAjaxController::ACTION_TEST_SEND); ?>,
            chart: <?php echo wp_json_encode($chart_payload); ?>,
            i18n: {
                sending:      <?php echo wp_json_encode(__('Sending…', 'lrob-email-toolkit')); ?>,
                sendBtn:      <?php echo wp_json_encode(__('Send', 'lrob-email-toolkit')); ?>,
                unknownError: <?php echo wp_json_encode(__('Something went wrong.', 'lrob-email-toolkit')); ?>,
                sentLabel:    <?php echo wp_json_encode(__('Sent', 'lrob-email-toolkit')); ?>,
                failedLabel:  <?php echo wp_json_encode(__('Failed', 'lrob-email-toolkit')); ?>
            }
        };

(function () {
    var D = window.lrobEtkDashboard;
    if (!D) return;

    function $(id) { return document.getElementById(id); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    // ---- Chart ----
    var chartWrap = $('lrob-etk-chart');
    if (chartWrap && D.chart && !D.chart.empty) {
        initChart();
    } else if (chartWrap && D.chart && D.chart.empty) {
        var empty = $('lrob-etk-chart-empty');
        var canvas = $('lrob-etk-chart-canvas');
        if (empty) empty.hidden = false;
        if (canvas) canvas.style.display = 'none';
    }

    function initChart() {
        var rangeSel = $('lrob-etk-chart-range');
        var typeButtons = $$('.lrob-etk-chart-type button');
        var currentType = 'smooth';

        if (rangeSel) rangeSel.value = D.chart.default || '30d';

        function getActiveRange() {
            return rangeSel ? rangeSel.value : (D.chart.default || '30d');
        }

        function rerender() {
            var range = getActiveRange();
            var data = D.chart.ranges[range];
            if (!data) return;
            renderSvg(data, currentType);
        }

        if (rangeSel) {
            rangeSel.addEventListener('change', rerender);
        }
        typeButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                typeButtons.forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                currentType = btn.getAttribute('data-chart-type') || 'bars';
                rerender();
            });
        });

        rerender();
    }

    function formatBucketLabel(ts, bucketSeconds) {
        var date = new Date(ts * 1000);
        if (bucketSeconds < 3600) {
            // sub-hour: show HH:MM
            return pad(date.getHours()) + ':' + pad(date.getMinutes());
        }
        if (bucketSeconds < 86400) {
            // sub-day: show date HH:MM
            return (date.getMonth() + 1) + '/' + date.getDate() + ' ' + pad(date.getHours()) + ':00';
        }
        if (bucketSeconds < 2592000) {
            // sub-month: show date
            return (date.getMonth() + 1) + '/' + date.getDate();
        }
        // month-ish: show short month + year
        return date.toLocaleString(undefined, { month: 'short', year: '2-digit' });
    }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function niceMax(rawMax) {
        if (rawMax <= 0) return 4;
        // Round up to a nice integer
        var pow = Math.pow(10, Math.max(0, Math.floor(Math.log10(rawMax))));
        var rough = rawMax / pow;
        var nice;
        if (rough <= 1) nice = 1;
        else if (rough <= 2) nice = 2;
        else if (rough <= 5) nice = 5;
        else nice = 10;
        return nice * pow;
    }

    function renderSvg(data, type) {
        var canvas = $('lrob-etk-chart-canvas');
        if (!canvas) return;

        var buckets = data.buckets || [];
        if (buckets.length === 0) {
            canvas.innerHTML = '<p class="lrob-etk-chart-empty-inline">' + 'No data for this range' + '</p>';
            return;
        }

        var width = canvas.clientWidth || 720;
        var height = 200;
        var pad = { top: 12, right: 14, bottom: 28, left: 40 };
        var innerW = width - pad.left - pad.right;
        var innerH = height - pad.top - pad.bottom;

        var n = buckets.length;
        var max = niceMax(data.max || 1);
        var slotW = innerW / n;

        function x(i) { return pad.left + i * slotW + slotW / 2; }
        function y(v) { return pad.top + innerH - (v / max) * innerH; }

        var svg = '<svg viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="none" class="lrob-etk-chart-svg">';

        // Y-axis grid + labels (4 ticks: 0, 33%, 66%, 100%)
        var ticks = [0, max / 4, max / 2, (max * 3) / 4, max];
        ticks.forEach(function (t, i) {
            var yy = y(t);
            svg += '<line class="lrob-etk-chart-grid" x1="' + pad.left + '" x2="' + (width - pad.right) + '" y1="' + yy + '" y2="' + yy + '" />';
            svg += '<text class="lrob-etk-chart-axis" x="' + (pad.left - 4) + '" y="' + (yy + 3) + '" text-anchor="end">' + Math.round(t) + '</text>';
        });

        // X-axis labels: pick ~5 ticks
        var xLabelCount = Math.min(5, n);
        for (var i = 0; i < xLabelCount; i++) {
            var idx = Math.round((i * (n - 1)) / Math.max(1, xLabelCount - 1));
            var b = buckets[idx];
            if (!b) continue;
            var xx = x(idx);
            svg += '<text class="lrob-etk-chart-axis" x="' + xx + '" y="' + (height - pad.bottom + 14) + '" text-anchor="middle">' + escapeXml(formatBucketLabel(b.ts, data.bucket_seconds)) + '</text>';
        }

        // Data
        if (type === 'bars') {
            buckets.forEach(function (b, i) {
                var sent = b.sent || 0;
                var failed = b.failed || 0;
                var total = sent + failed;
                if (total === 0) return;
                var xStart = pad.left + i * slotW + 1;
                var w = Math.max(1, slotW - 2);
                var failedH = (failed / max) * innerH;
                var sentH = (sent / max) * innerH;
                var totalH = failedH + sentH;
                var top = pad.top + innerH - totalH;
                if (failed > 0) {
                    svg += '<rect class="lrob-etk-chart-bar-failed-svg" x="' + xStart + '" y="' + top + '" width="' + w + '" height="' + failedH + '" />';
                }
                if (sent > 0) {
                    svg += '<rect class="lrob-etk-chart-bar-sent-svg" x="' + xStart + '" y="' + (top + failedH) + '" width="' + w + '" height="' + sentH + '" />';
                }
            });
        } else {
            // Line / smooth
            var sentPts = buckets.map(function (b, i) { return [x(i), y(b.sent || 0)]; });
            var failedPts = buckets.map(function (b, i) { return [x(i), y(b.failed || 0)]; });
            var sentD = type === 'smooth' ? smoothPath(sentPts) : linePath(sentPts);
            var failedD = type === 'smooth' ? smoothPath(failedPts) : linePath(failedPts);
            svg += '<path class="lrob-etk-chart-line-sent" d="' + sentD + '" />';
            svg += '<path class="lrob-etk-chart-line-failed" d="' + failedD + '" />';
            // Dots at data points
            buckets.forEach(function (b, i) {
                if ((b.sent || 0) > 0) {
                    svg += '<circle class="lrob-etk-chart-dot-sent" cx="' + x(i) + '" cy="' + y(b.sent) + '" r="2" />';
                }
                if ((b.failed || 0) > 0) {
                    svg += '<circle class="lrob-etk-chart-dot-failed" cx="' + x(i) + '" cy="' + y(b.failed) + '" r="2" />';
                }
            });
        }

        // Hover zones (full height per bucket) — instant tooltip via mouseenter
        buckets.forEach(function (b, i) {
            svg += '<rect class="lrob-etk-chart-hover" x="' + (pad.left + i * slotW) + '" y="' + pad.top + '" width="' + slotW + '" height="' + innerH + '" data-i="' + i + '" />';
        });

        svg += '</svg>';
        canvas.innerHTML = svg;

        // Tooltip wiring
        var tip = $('lrob-etk-chart-tooltip');
        $$('.lrob-etk-chart-hover', canvas).forEach(function (rect) {
            rect.addEventListener('mouseenter', function () {
                var idx = parseInt(rect.getAttribute('data-i'), 10);
                showTooltip(canvas, rect, buckets[idx], data.bucket_seconds);
            });
            rect.addEventListener('mouseleave', function () {
                if (tip) tip.hidden = true;
            });
        });
    }

    function linePath(pts) {
        if (pts.length === 0) return '';
        var d = 'M' + pts[0][0] + ',' + pts[0][1];
        for (var i = 1; i < pts.length; i++) d += 'L' + pts[i][0] + ',' + pts[i][1];
        return d;
    }
    function smoothPath(pts) {
        if (pts.length < 2) return linePath(pts);
        var d = 'M' + pts[0][0] + ',' + pts[0][1];
        for (var i = 1; i < pts.length; i++) {
            var p0 = pts[i - 1];
            var p1 = pts[i];
            var dx = (p1[0] - p0[0]) / 3;
            d += ' C' + (p0[0] + dx) + ',' + p0[1] + ' ' + (p1[0] - dx) + ',' + p1[1] + ' ' + p1[0] + ',' + p1[1];
        }
        return d;
    }
    function escapeXml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c];
        });
    }

    function showTooltip(canvas, anchor, bucket, bucketSeconds) {
        var tip = $('lrob-etk-chart-tooltip');
        if (!tip) return;
        var sent = bucket.sent || 0;
        var failed = bucket.failed || 0;
        var label = formatBucketFullLabel(bucket.ts, bucketSeconds);
        tip.innerHTML = '<div class="lrob-etk-tooltip-title">' + escapeXml(label) + '</div>' +
            '<div class="lrob-etk-tooltip-row"><span class="dot dot-sent"></span>' + D.i18n.sentLabel + ': <strong>' + sent + '</strong></div>' +
            '<div class="lrob-etk-tooltip-row"><span class="dot dot-failed"></span>' + D.i18n.failedLabel + ': <strong>' + failed + '</strong></div>';

        var canvasRect = canvas.getBoundingClientRect();
        var anchorRect = anchor.getBoundingClientRect();
        // Position relative to canvas (which is positioned in CSS)
        tip.hidden = false;
        var tipRect = tip.getBoundingClientRect();
        var left = anchorRect.left + anchorRect.width / 2 - canvasRect.left - tipRect.width / 2;
        var top = anchorRect.top - canvasRect.top - tipRect.height - 6;
        if (left < 4) left = 4;
        if (left + tipRect.width > canvasRect.width - 4) left = canvasRect.width - tipRect.width - 4;
        if (top < 4) top = anchorRect.bottom - canvasRect.top + 6;
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
    }

    function formatBucketFullLabel(ts, bucketSeconds) {
        var date = new Date(ts * 1000);
        var endDate = new Date((ts + bucketSeconds) * 1000);
        if (bucketSeconds < 3600) {
            return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) +
                ' – ' + endDate.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        }
        if (bucketSeconds < 86400) {
            return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        }
        if (bucketSeconds <= 86400) {
            return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        }
        // multi-day buckets
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) +
            ' – ' + new Date((ts + bucketSeconds - 86400) * 1000).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    // Re-render on resize
    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            var rangeSel = $('lrob-etk-chart-range');
            if (!rangeSel || !D.chart || D.chart.empty) return;
            var active = $$('.lrob-etk-chart-type button.is-active')[0];
            var type = active ? active.getAttribute('data-chart-type') : 'smooth';
            var data = D.chart.ranges[rangeSel.value];
            if (data) renderSvg(data, type);
        }, 150);
    });

    // ---- Test email popover ----
    var popover = $('lrob-etk-dashboard-test-popover');
    var anchorBtn = null;

    function anchorPopover(pop, anchor) {
        pop.hidden = false;
        var pRect = pop.getBoundingClientRect();
        var aRect = anchor.getBoundingClientRect();
        var margin = 8;
        var top = aRect.bottom + margin;
        if (top + pRect.height > window.innerHeight - margin) {
            top = aRect.top - pRect.height - margin;
            if (top < margin) top = margin;
        }
        var left = aRect.left;
        if (left + pRect.width > window.innerWidth - margin) left = window.innerWidth - pRect.width - margin;
        if (left < margin) left = margin;
        pop.style.position = 'fixed';
        pop.style.top = top + 'px';
        pop.style.left = left + 'px';
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-test-email]');
        if (btn && popover) {
            e.preventDefault();
            anchorBtn = btn;
            var result = $('lrob-etk-dashboard-test-result');
            if (result) result.hidden = true;
            anchorPopover(popover, btn);
            return;
        }
        if (e.target.closest && e.target.closest('[data-popover-close]')) {
            if (popover) popover.hidden = true;
            return;
        }
        if (popover && !popover.hidden && !popover.contains(e.target) && (!anchorBtn || !anchorBtn.contains(e.target))) {
            popover.hidden = true;
        }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && popover) popover.hidden = true; });

    var choiceSel = $('lrob-etk-dashboard-test-choice');
    var customWrap = $('lrob-etk-dashboard-test-custom-wrap');
    if (choiceSel && customWrap) {
        choiceSel.addEventListener('change', function () {
            customWrap.hidden = choiceSel.value !== 'custom';
        });
    }

    var sendBtn = $('lrob-etk-dashboard-test-send');
    if (sendBtn) {
        sendBtn.addEventListener('click', function () {
            var idEl = $('lrob-etk-dashboard-test-identity');
            var ch = $('lrob-etk-dashboard-test-choice');
            var cu = $('lrob-etk-dashboard-test-custom');
            var result = $('lrob-etk-dashboard-test-result');
            if (!idEl || !ch) return;

            sendBtn.disabled = true;
            sendBtn.textContent = D.i18n.sending;
            result.hidden = false;
            result.className = 'lrob-etk-test-result is-pending';
            result.textContent = D.i18n.sending;

            var fd = new FormData();
            fd.append('action', D.testAction);
            fd.append('_nonce', D.nonce);
            fd.append('id', idEl.value);
            fd.append('recipient_choice', ch.value);
            fd.append('recipient_custom', cu ? cu.value : '');

            fetch(D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        result.className = 'lrob-etk-test-result is-success';
                        result.textContent = '✓ ' + resp.data.message;
                    } else {
                        result.className = 'lrob-etk-test-result is-failure';
                        result.textContent = '✗ ' + ((resp.data && resp.data.message) || D.i18n.unknownError);
                    }
                })
                .catch(function () {
                    result.className = 'lrob-etk-test-result is-failure';
                    result.textContent = D.i18n.unknownError;
                })
                .finally(function () {
                    sendBtn.disabled = false;
                    sendBtn.textContent = D.i18n.sendBtn;
                });
        });
    }
})();
        <?php
    }
}
