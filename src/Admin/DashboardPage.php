<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Logging\Admin\PageController as LogsPageController;
use LRob\EmailToolkit\Modules\Logging\LogEntry;
use LRob\EmailToolkit\Modules\Logging\LogRepository;
use LRob\EmailToolkit\Modules\Logging\Module as LoggingModule;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\ModuleManager;
use LRob\EmailToolkit\Modules\SMTP\Admin\AjaxController as SmtpAjaxController;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;

/**
 * Plugin landing page. Stats with prominent total + failed counts, 30-day
 * activity chart, failure-rate banner with cause hints, dedicated inline
 * Test email section, module status grid with toggles, recent activity in
 * the same custom-styled table as the logs page.
 */
final class DashboardPage
{
    /** @var array<string, string> range key → DateInterval spec */
    private const RANGES = [
        '24h' => 'PT24H',
        '7d'  => 'P7D',
        '30d' => 'P30D',
        '1y'  => 'P1Y',
    ];

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
        $chart = $repository ? $this->compute_chart_data($repository) : null;
        $failure_warning = $stats ? $this->compute_failure_warning($stats) : null;
        ?>
        <div class="wrap lrob-etk">
            <h1 class="lrob-etk-page-title"><?php esc_html_e('Email Toolkit', 'lrob-email-toolkit'); ?></h1>

            <div id="lrob-etk-flash" class="lrob-etk-flash" aria-live="polite"></div>

            <?php if ($failure_warning !== null) : ?>
                <?php $this->render_failure_warning($failure_warning); ?>
            <?php endif; ?>

            <?php if ($stats !== null) : ?>
                <h2 class="lrob-etk-section-title"><?php esc_html_e('Email activity', 'lrob-email-toolkit'); ?></h2>
                <?php $this->render_stats_grid($stats); ?>
                <?php if ($chart !== null) : ?>
                    <?php $this->render_chart($chart); ?>
                <?php endif; ?>
            <?php else : ?>
                <h2 class="lrob-etk-section-title"><?php esc_html_e('Email activity', 'lrob-email-toolkit'); ?></h2>
                <p class="lrob-etk-disabled-message">
                    <?php esc_html_e('Enable Email Logging to track sent/failed emails and see activity charts here.', 'lrob-email-toolkit'); ?>
                </p>
            <?php endif; ?>

            <?php $this->render_test_send_section(); ?>
            <?php $this->render_modules_grid(); ?>
            <?php $this->render_recent_activity($repository); ?>

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
        <?php $this->print_inline_js(); ?>
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
        foreach (self::RANGES as $key => $offset) {
            try {
                $from = $now_utc->sub(new \DateInterval($offset));
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
     * @return array{days: array<string, array{sent:int, failed:int}>, max: int}
     */
    private function compute_chart_data(LogRepository $repository): array
    {
        $tz = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $from = $now->modify('-29 days')->setTime(0, 0);
        $to = $now->setTime(23, 59, 59);

        $days = $repository->counts_by_day($from, $to);

        $max = 0;
        $simplified = [];
        foreach ($days as $key => $counts) {
            $sent = (int) ($counts['sent'] ?? 0);
            $failed = (int) ($counts['failed'] ?? 0);
            $total = $sent + $failed;
            $max = max($max, $total);
            $simplified[$key] = ['sent' => $sent, 'failed' => $failed];
        }

        return ['days' => $simplified, 'max' => $max];
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
        ?>
        <div class="lrob-etk-stat-grid">
            <?php foreach (self::RANGES as $key => $_offset) :
                $s = $stats[$key] ?? ['sent' => 0, 'failed' => 0, 'total' => 0, 'fail_rate' => 0];
                $is_danger = $s['fail_rate'] > 25 && $s['total'] >= 4;
                ?>
                <div class="lrob-etk-stat-card <?php echo $is_danger ? 'is-warning' : ''; ?>">
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
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /** @param array{days: array<string, array{sent:int, failed:int}>, max: int} $chart */
    private function render_chart(array $chart): void
    {
        $max = max(1, $chart['max']);
        $days = $chart['days'];
        if ($days === []) {
            return;
        }
        $first_day = (string) array_key_first($days);
        $last_day = (string) array_key_last($days);
        ?>
        <div class="lrob-etk-chart-wrap">
            <p class="lrob-etk-chart-title">
                <?php
                printf(
                    /* translators: 1: start date, 2: end date */
                    esc_html__('Activity, %1$s — %2$s', 'lrob-email-toolkit'),
                    esc_html(mysql2date(get_option('date_format'), $first_day)),
                    esc_html(mysql2date(get_option('date_format'), $last_day))
                );
                ?>
            </p>
            <div class="lrob-etk-chart" role="img" aria-label="<?php esc_attr_e('Daily email activity over the last 30 days', 'lrob-email-toolkit'); ?>">
                <?php foreach ($days as $date => $counts) :
                    $total = $counts['sent'] + $counts['failed'];
                    $total_pct = $total > 0 ? round(($total / $max) * 100, 2) : 0;
                    $sent_pct = $total > 0 ? round(($counts['sent'] / $total) * 100, 2) : 0;
                    $failed_pct = $total > 0 ? round(($counts['failed'] / $total) * 100, 2) : 0;
                    $title = sprintf(
                        /* translators: 1: date, 2: sent count, 3: failed count */
                        __('%1$s — %2$d sent, %3$d failed', 'lrob-email-toolkit'),
                        mysql2date(get_option('date_format'), $date),
                        (int) $counts['sent'],
                        (int) $counts['failed']
                    );
                    ?>
                    <div class="lrob-etk-chart-bar" style="height: <?php echo esc_attr((string) $total_pct); ?>%" title="<?php echo esc_attr($title); ?>">
                        <?php if ($counts['failed'] > 0) : ?>
                            <span class="lrob-etk-chart-bar-failed" style="height: <?php echo esc_attr((string) $failed_pct); ?>%"></span>
                        <?php endif; ?>
                        <?php if ($counts['sent'] > 0) : ?>
                            <span class="lrob-etk-chart-bar-sent" style="height: <?php echo esc_attr((string) $sent_pct); ?>%"></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function render_test_send_section(): void
    {
        $identities = (new IdentityRepository())->all();
        $smtp = $this->manager->get('smtp');
        $smtp_on = $smtp instanceof ModuleInterface && $smtp->is_enabled();
        $current_user = wp_get_current_user();
        $admin_email = (string) get_option('admin_email');
        ?>
        <h2 class="lrob-etk-section-title"><?php esc_html_e('Send test email', 'lrob-email-toolkit'); ?></h2>
        <div class="lrob-etk-test-section">
            <?php if ($identities === []) : ?>
                <p class="lrob-etk-test-section-empty">
                    <?php esc_html_e('Configure at least one SMTP identity to send test emails.', 'lrob-email-toolkit'); ?>
                    <?php if ($smtp instanceof ModuleInterface) : ?>
                        <a href="<?php echo esc_url($smtp->admin_page_url() ?: '#'); ?>" class="button button-primary">
                            <?php esc_html_e('Configure SMTP', 'lrob-email-toolkit'); ?>
                        </a>
                    <?php endif; ?>
                </p>
            <?php else : ?>
                <?php if (!$smtp_on) : ?>
                    <p class="lrob-etk-test-section-note description">
                        <?php esc_html_e('SMTP routing is currently off. Tests will still run against the chosen identity, but normal emails are not being routed.', 'lrob-email-toolkit'); ?>
                    </p>
                <?php endif; ?>
                <form id="lrob-etk-dashboard-test-form" class="lrob-etk-test-form">
                    <div class="lrob-etk-test-form-row">
                        <div class="lrob-etk-field">
                            <label for="lrob-etk-test-identity"><?php esc_html_e('Identity', 'lrob-email-toolkit'); ?></label>
                            <select id="lrob-etk-test-identity" name="identity_id" class="lrob-etk-select">
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
                            <label for="lrob-etk-test-recipient-choice"><?php esc_html_e('Recipient', 'lrob-email-toolkit'); ?></label>
                            <select id="lrob-etk-test-recipient-choice" name="recipient_choice" class="lrob-etk-select">
                                <option value="current"><?php echo esc_html(sprintf(__('Me (%s)', 'lrob-email-toolkit'), $current_user->user_email)); ?></option>
                                <option value="admin"><?php echo esc_html(sprintf(__('Site admin (%s)', 'lrob-email-toolkit'), $admin_email)); ?></option>
                                <option value="custom"><?php esc_html_e('Custom…', 'lrob-email-toolkit'); ?></option>
                            </select>
                        </div>

                        <div class="lrob-etk-test-form-submit">
                            <button type="button" id="lrob-etk-dashboard-test-send" class="button button-primary">
                                <span class="dashicons dashicons-email"></span>
                                <?php esc_html_e('Send', 'lrob-email-toolkit'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="lrob-etk-field" id="lrob-etk-test-custom-wrap" hidden>
                        <label for="lrob-etk-test-recipient-custom"><?php esc_html_e('Custom recipient', 'lrob-email-toolkit'); ?></label>
                        <input type="email" id="lrob-etk-test-recipient-custom" name="recipient_custom" placeholder="you@example.com">
                    </div>

                    <div id="lrob-etk-test-result" class="lrob-etk-test-result" hidden></div>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_modules_grid(): void
    {
        $modules = $this->manager->all();
        $action_url = admin_url('admin-post.php');
        ?>
        <h2 class="lrob-etk-section-title"><?php esc_html_e('Modules', 'lrob-email-toolkit'); ?></h2>
        <div class="lrob-etk-modules-grid">
            <?php foreach ($modules as $module) :
                $enabled = $module->is_enabled();
                $url = $module->admin_page_url();
                $is_coming = $url === null;
                $card_class = $is_coming ? 'is-coming' : ($enabled ? 'is-on' : '');
                ?>
                <div class="lrob-etk-module-card <?php echo esc_attr($card_class); ?>">
                    <div class="lrob-etk-module-card-head">
                        <h3><?php echo esc_html($module->name()); ?></h3>
                        <?php if ($is_coming) : ?>
                            <span class="lrob-etk-status lrob-etk-status--pending"><?php esc_html_e('Coming soon', 'lrob-email-toolkit'); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="lrob-etk-module-card-description"><?php echo esc_html($module->description()); ?></p>
                    <?php if (!$is_coming) : ?>
                        <div class="lrob-etk-module-card-actions">
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
                            <a href="<?php echo esc_url($url); ?>" class="button">
                                <?php esc_html_e('Manage', 'lrob-email-toolkit'); ?>
                                <span aria-hidden="true">→</span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
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
                                <?php echo esc_html($entry->created_at->setTimezone(wp_timezone())->format('Y-m-d H:i')); ?>
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

    private function print_inline_js(): void
    {
        ?>
        window.lrobEtkDashboard = {
            ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
            nonce: <?php echo wp_json_encode(wp_create_nonce(SmtpAjaxController::NONCE_ACTION)); ?>,
            action: <?php echo wp_json_encode(SmtpAjaxController::ACTION_TEST_SEND); ?>,
            i18n: {
                sending:      <?php echo wp_json_encode(__('Sending…', 'lrob-email-toolkit')); ?>,
                sendBtn:      <?php echo wp_json_encode(__('Send', 'lrob-email-toolkit')); ?>,
                unknownError: <?php echo wp_json_encode(__('Something went wrong.', 'lrob-email-toolkit')); ?>
            }
        };

(function () {
    var D = window.lrobEtkDashboard;
    if (!D) return;

    var recipientSel = document.getElementById('lrob-etk-test-recipient-choice');
    var customWrap = document.getElementById('lrob-etk-test-custom-wrap');
    if (recipientSel && customWrap) {
        recipientSel.addEventListener('change', function () {
            customWrap.hidden = recipientSel.value !== 'custom';
        });
    }

    var sendBtn = document.getElementById('lrob-etk-dashboard-test-send');
    if (!sendBtn) return;

    sendBtn.addEventListener('click', function () {
        var identityEl = document.getElementById('lrob-etk-test-identity');
        var choiceEl = document.getElementById('lrob-etk-test-recipient-choice');
        var customEl = document.getElementById('lrob-etk-test-recipient-custom');
        var result = document.getElementById('lrob-etk-test-result');

        if (!identityEl || !choiceEl) return;

        sendBtn.disabled = true;
        var icon = sendBtn.querySelector('.dashicons');
        var originalLabel = sendBtn.lastChild;
        sendBtn.textContent = D.i18n.sending;
        result.hidden = false;
        result.className = 'lrob-etk-test-result is-pending';
        result.textContent = D.i18n.sending;

        var fd = new FormData();
        fd.append('action', D.action);
        fd.append('_nonce', D.nonce);
        fd.append('id', identityEl.value);
        fd.append('recipient_choice', choiceEl.value);
        fd.append('recipient_custom', customEl ? customEl.value : '');

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
                // Rebuild button content (icon + label)
                sendBtn.innerHTML = '<span class="dashicons dashicons-email"></span> ' + D.i18n.sendBtn;
            });
    });
})();
        <?php
    }
}
