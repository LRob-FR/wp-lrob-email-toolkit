<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Logging\LogEntry;
use LRob\EmailToolkit\Modules\Logging\LogRepository;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\ModuleManager;
use LRob\EmailToolkit\Modules\SMTP\ConstantOverrides;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Modules\SMTP\TestSender;

/**
 * Plugin landing page. Time-range stat cards, 30-day activity chart with
 * failure-rate banner, compact action row, module cards with inline
 * Enable/Disable, and a recent-activity table.
 *
 * The chart is pure CSS divs (no library). Stats come from LogRepository's
 * count() and counts_by_day() queries — both indexed on (status, created_at)
 * so the four range counts + the 30-day grouping are cheap.
 */
final class DashboardPage
{
    public const ACTION_QUICK_TEST = 'lrob_etk_dashboard_quick_test';

    /** @var array<string, string> range key → DateInterval spec */
    private const RANGES = [
        '24h' => 'PT24H',
        '7d'  => 'P7D',
        '30d' => 'P30D',
        '1y'  => 'P1Y',
    ];

    public function __construct(private ModuleManager $manager)
    {
        add_action('admin_post_' . self::ACTION_QUICK_TEST, [$this, 'handle_quick_test']);
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }

        $notice = self::pop_flash('notice');
        $errors = self::pop_flash('errors');

        $logging = $this->manager->get('logging');
        $logging_on = $logging instanceof ModuleInterface && $logging->is_enabled();
        $repository = $logging_on ? new LogRepository() : null;

        $stats = $repository ? $this->compute_stats($repository) : null;
        $chart = $repository ? $this->compute_chart_data($repository) : null;
        $failure_warning = $stats ? $this->compute_failure_warning($stats) : null;
        ?>
        <div class="wrap lrob-etk">
            <h1 class="lrob-etk-page-title"><?php esc_html_e('Email Toolkit', 'lrob-email-toolkit'); ?></h1>

            <?php $this->render_flash($notice, $errors); ?>

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

            <?php $this->render_action_row(); ?>
            <?php $this->render_modules_grid(); ?>
            <?php $this->render_recent_activity($repository); ?>
            <?php $this->render_quick_test_modal(); ?>

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
     * @return array<string, array{sent:int, failed:int, total:int, rate:int}>
     *         e.g. ['24h' => ['sent' => 12, 'failed' => 0, 'total' => 12, 'rate' => 100], ...]
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
            $rate = $total > 0 ? (int) round(($sent / $total) * 100) : 100;
            $stats[$key] = compact('sent', 'failed', 'total', 'rate');
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

        // counts_by_day runs in UTC server-side but key by date; close enough
        // for a 30-day overview (off-by-one-day at the edges for timezones far
        // from UTC isn't load-bearing here).
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
     * @param array<string, array{sent:int, failed:int, total:int, rate:int}> $stats
     * @return array{failed:int, total:int, rate:int}|null Returns null when no warning.
     */
    private function compute_failure_warning(array $stats): ?array
    {
        if (!isset($stats['24h'])) {
            return null;
        }
        $s = $stats['24h'];
        if ($s['total'] < 4) {
            return null;  // not enough samples to warn
        }
        $failure_pct = (int) round(($s['failed'] / $s['total']) * 100);
        if ($failure_pct <= 25) {
            return null;
        }
        return ['failed' => $s['failed'], 'total' => $s['total'], 'rate' => $failure_pct];
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
                <span><?php esc_html_e('Check your SMTP configuration.', 'lrob-email-toolkit'); ?></span>
            </div>
            <a href="<?php echo esc_url($logs_url); ?>" class="button">
                <?php esc_html_e('View failed logs →', 'lrob-email-toolkit'); ?>
            </a>
        </div>
        <?php
    }

    /** @param array<string, array{sent:int, failed:int, total:int, rate:int}> $stats */
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
                $s = $stats[$key] ?? ['sent' => 0, 'failed' => 0, 'total' => 0, 'rate' => 100];
                ?>
                <div class="lrob-etk-stat-card">
                    <p class="lrob-etk-stat-label"><?php echo esc_html($labels[$key] ?? $key); ?></p>
                    <p class="lrob-etk-stat-value"><?php echo number_format_i18n((int) $s['sent']); ?></p>
                    <p class="lrob-etk-stat-detail">
                        <?php
                        printf(
                            /* translators: %s: number of sent emails */
                            esc_html__('%s sent', 'lrob-email-toolkit'),
                            '<strong>' . esc_html((string) number_format_i18n((int) $s['sent'])) . '</strong>'
                        );
                        ?>
                    </p>
                    <p class="lrob-etk-stat-detail <?php echo $s['failed'] > 0 ? 'is-danger' : ''; ?>">
                        <?php
                        printf(
                            /* translators: %s: number of failed emails */
                            esc_html__('%s failed', 'lrob-email-toolkit'),
                            '<strong>' . esc_html((string) number_format_i18n((int) $s['failed'])) . '</strong>'
                        );
                        ?>
                    </p>
                    <p class="lrob-etk-stat-rate"><?php echo (int) $s['rate']; ?>% <?php esc_html_e('ok', 'lrob-email-toolkit'); ?></p>
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

    private function render_action_row(): void
    {
        $logs_url = admin_url('admin.php?page=lrob-etk-logs');
        $identities = (new IdentityRepository())->all();
        $can_test = $identities !== [];
        ?>
        <div class="lrob-etk-action-row">
            <button
                type="button"
                class="button button-primary"
                id="lrob-etk-dashboard-test"
                <?php disabled(!$can_test); ?>>
                <span class="dashicons dashicons-email"></span>
                <?php esc_html_e('Send test email', 'lrob-email-toolkit'); ?>
            </button>
            <a href="<?php echo esc_url($logs_url); ?>" class="button">
                <span class="dashicons dashicons-list-view"></span>
                <?php esc_html_e('View all logs', 'lrob-email-toolkit'); ?>
            </a>
            <?php if (!$can_test) : ?>
                <span class="description"><?php esc_html_e('Configure an SMTP identity first.', 'lrob-email-toolkit'); ?></span>
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
        <table class="widefat striped lrob-etk-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('To', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Subject', 'lrob-email-toolkit'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $entry) : ?>
                    <tr>
                        <td><?php echo esc_html($entry->created_at->setTimezone(wp_timezone())->format('Y-m-d H:i')); ?></td>
                        <td><span class="lrob-etk-status <?php echo esc_attr($this->status_class($entry->status)); ?>"><?php echo esc_html($entry->status); ?></span></td>
                        <td><?php echo esc_html(implode(', ', array_slice($entry->to_emails, 0, 2))); ?></td>
                        <td><?php echo esc_html($entry->subject !== '' ? $entry->subject : '(no subject)'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:8px">
            <a href="<?php echo esc_url(admin_url('admin.php?page=lrob-etk-logs')); ?>" class="button button-link">
                <?php esc_html_e('View all logs →', 'lrob-email-toolkit'); ?>
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

    private function render_quick_test_modal(): void
    {
        $identities = (new IdentityRepository())->all();
        if ($identities === []) {
            return;
        }
        $current_user = wp_get_current_user();
        $admin_email = (string) get_option('admin_email');
        $action_url = admin_url('admin-post.php');
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-dashboard-test-modal" role="dialog" aria-modal="true" hidden>
            <div class="lrob-etk-modal-backdrop" data-lrob-etk-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--small" role="document">
                <header class="lrob-etk-modal-header">
                    <h2 class="lrob-etk-modal-title-text"><?php esc_html_e('Send a test email', 'lrob-email-toolkit'); ?></h2>
                    <button type="button" class="lrob-etk-modal-close" data-lrob-etk-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </header>

                <form method="post" action="<?php echo esc_url($action_url); ?>" class="lrob-etk-modal-body">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_QUICK_TEST); ?>">
                    <?php wp_nonce_field(self::ACTION_QUICK_TEST, '_lrob_etk_nonce'); ?>

                    <?php if (count($identities) > 1) : ?>
                        <div class="lrob-etk-field">
                            <label for="lrob-etk-qtest-identity"><?php esc_html_e('Identity', 'lrob-email-toolkit'); ?></label>
                            <select id="lrob-etk-qtest-identity" name="identity_id">
                                <?php foreach ($identities as $identity) : ?>
                                    <option value="<?php echo (int) $identity->id; ?>" <?php selected($identity->is_default); ?>>
                                        <?php echo esc_html($identity->label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else :
                        $only = reset($identities); ?>
                        <input type="hidden" name="identity_id" value="<?php echo (int) $only->id; ?>">
                    <?php endif; ?>

                    <div class="lrob-etk-field">
                        <label for="lrob-etk-qtest-recipient"><?php esc_html_e('Recipient', 'lrob-email-toolkit'); ?></label>
                        <select id="lrob-etk-qtest-recipient" name="recipient_choice">
                            <option value="current"><?php echo esc_html(sprintf(__('Me (%s)', 'lrob-email-toolkit'), $current_user->user_email)); ?></option>
                            <option value="admin"><?php echo esc_html(sprintf(__('Site admin (%s)', 'lrob-email-toolkit'), $admin_email)); ?></option>
                            <option value="custom"><?php esc_html_e('Custom address…', 'lrob-email-toolkit'); ?></option>
                        </select>
                    </div>

                    <div class="lrob-etk-field" id="lrob-etk-qtest-custom-wrap" hidden>
                        <label for="lrob-etk-qtest-custom"><?php esc_html_e('Custom recipient', 'lrob-email-toolkit'); ?></label>
                        <input type="email" id="lrob-etk-qtest-custom" name="recipient_custom" placeholder="you@example.com">
                    </div>

                    <footer class="lrob-etk-modal-footer">
                        <button type="button" class="button" data-lrob-etk-close><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></button>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Send test', 'lrob-email-toolkit'); ?>
                        </button>
                    </footer>
                </form>
            </div>
        </div>
        <?php
    }

    private function print_inline_js(): void
    {
        ?>
        (function () {
            var btn = document.getElementById('lrob-etk-dashboard-test');
            var modal = document.getElementById('lrob-etk-dashboard-test-modal');
            if (btn && modal) {
                btn.addEventListener('click', function () {
                    modal.hidden = false;
                    document.body.classList.add('lrob-etk-modal-open');
                });
            }
            document.addEventListener('click', function (e) {
                if (e.target.closest && e.target.closest('[data-lrob-etk-close]')) {
                    e.preventDefault();
                    if (modal) modal.hidden = true;
                    document.body.classList.remove('lrob-etk-modal-open');
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal) {
                    modal.hidden = true;
                    document.body.classList.remove('lrob-etk-modal-open');
                }
            });
            var recipientSel = document.getElementById('lrob-etk-qtest-recipient');
            var customWrap = document.getElementById('lrob-etk-qtest-custom-wrap');
            if (recipientSel && customWrap) {
                recipientSel.addEventListener('change', function () {
                    customWrap.hidden = recipientSel.value !== 'custom';
                });
            }
        })();
        <?php
    }

    public function handle_quick_test(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $nonce = isset($_POST['_lrob_etk_nonce']) ? (string) $_POST['_lrob_etk_nonce'] : '';
        if (!wp_verify_nonce($nonce, self::ACTION_QUICK_TEST)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }

        $identity_id = isset($_POST['identity_id']) ? max(0, (int) $_POST['identity_id']) : 0;
        $choice = isset($_POST['recipient_choice']) ? sanitize_key((string) $_POST['recipient_choice']) : 'current';

        $recipient = match ($choice) {
            'admin'  => (string) get_option('admin_email'),
            'custom' => isset($_POST['recipient_custom']) ? sanitize_email((string) wp_unslash($_POST['recipient_custom'])) : '',
            default  => (string) wp_get_current_user()->user_email,
        };

        if ($identity_id === 0 || $recipient === '') {
            self::store_flash('errors', [__('Pick an identity and a recipient.', 'lrob-email-toolkit')]);
            $this->redirect_to_dashboard();
        }

        $identities = new IdentityRepository();
        $overrides = new ConstantOverrides();
        $tester = new TestSender($identities, $overrides);

        $result = $tester->send($identity_id, $recipient);
        if ($result['success']) {
            self::store_flash('notice', sprintf(
                /* translators: %s: recipient email */
                __('Test email sent to %s.', 'lrob-email-toolkit'),
                $recipient
            ));
        } else {
            $error = $result['error'] ?? __('Unknown error.', 'lrob-email-toolkit');
            self::store_flash('errors', [sprintf(
                /* translators: %s: error message */
                __('Test email failed: %s', 'lrob-email-toolkit'),
                $error
            )]);
        }

        $this->redirect_to_dashboard();
    }

    private function redirect_to_dashboard(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=lrob-etk'));
        exit;
    }

    /** @param string|array<int, string> $value */
    public static function store_flash(string $key, $value): void
    {
        $user_id = get_current_user_id();
        set_transient('lrob_etk_dashboard_flash_' . $key . '_' . $user_id, $value, 60);
    }

    /** @return string|array<int, string>|null */
    public static function pop_flash(string $key)
    {
        $user_id = get_current_user_id();
        $transient_key = 'lrob_etk_dashboard_flash_' . $key . '_' . $user_id;
        $value = get_transient($transient_key);
        if ($value !== false) {
            delete_transient($transient_key);
            return $value;
        }
        return null;
    }

    /**
     * @param string|array<int, string>|null $notice
     * @param string|array<int, string>|null $errors
     */
    private function render_flash($notice, $errors): void
    {
        if (is_string($notice) && $notice !== '') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
        }
        if (is_array($errors)) {
            foreach ($errors as $error) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html((string) $error) . '</p></div>';
            }
        }
    }
}
