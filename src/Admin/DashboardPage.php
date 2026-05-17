<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\Logging\LogEntry;
use LRob\EmailToolkit\Modules\Logging\LogRepository;
use LRob\EmailToolkit\Modules\Logging\Module as LoggingModule;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\ModuleManager;
use LRob\EmailToolkit\Modules\SMTP\Admin\PageController as SmtpPageController;
use LRob\EmailToolkit\Modules\SMTP\Identity;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Modules\SMTP\Module as SmtpModule;
use LRob\EmailToolkit\Modules\SMTP\TestSender;

/**
 * Plugin landing page. Stats from the Logging module (if enabled), module
 * status grid, quick-test form pulling from SMTP identities (if any),
 * recent-activity table.
 */
final class DashboardPage
{
    public const ACTION_QUICK_TEST = 'lrob_etk_dashboard_quick_test';

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
        ?>
        <div class="wrap lrob-etk">
            <h1 class="lrob-etk-page-title"><?php esc_html_e('Email Toolkit', 'lrob-email-toolkit'); ?></h1>

            <?php $this->render_flash($notice, $errors); ?>

            <?php $this->render_stats(); ?>
            <?php $this->render_quick_test(); ?>
            <?php $this->render_modules_grid(); ?>
            <?php $this->render_recent_activity(); ?>

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
        <?php
    }

    private function render_stats(): void
    {
        $logging = $this->manager->get('logging');
        $logging_on = $logging instanceof ModuleInterface && $logging->is_enabled();
        $repository = $logging_on ? new LogRepository() : null;

        $now = current_time('mysql', true);
        $thirty_days_ago = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
            ->sub(new \DateInterval('P30D'))
            ->format('Y-m-d H:i:s');

        $sent_30d = $repository ? $repository->count([
            'status' => LogEntry::STATUS_SENT,
            'date_from' => $thirty_days_ago,
        ]) : null;
        $failed_30d = $repository ? $repository->count([
            'status' => LogEntry::STATUS_FAILED,
            'date_from' => $thirty_days_ago,
        ]) : null;
        $total = $repository ? $repository->count() : null;
        $active_modules = 0;
        foreach ($this->manager->all() as $module) {
            if ($module->is_enabled()) {
                $active_modules++;
            }
        }
        $total_modules = count($this->manager->all());

        ?>
        <div class="lrob-etk-dashboard-grid">
            <?php $this->render_stat_card(
                __('Sent (last 30 days)', 'lrob-email-toolkit'),
                $sent_30d,
                'is-success'
            ); ?>
            <?php $this->render_stat_card(
                __('Failed (last 30 days)', 'lrob-email-toolkit'),
                $failed_30d,
                $failed_30d > 0 ? 'is-danger' : ''
            ); ?>
            <?php $this->render_stat_card(
                __('Total logged', 'lrob-email-toolkit'),
                $total
            ); ?>
            <?php $this->render_stat_card(
                __('Active modules', 'lrob-email-toolkit'),
                $active_modules . ' / ' . $total_modules
            ); ?>
        </div>
        <?php
    }

    private function render_stat_card(string $label, mixed $value, string $modifier = ''): void
    {
        $display = $value === null ? '—' : (string) $value;
        ?>
        <div class="lrob-etk-stat-card">
            <p class="lrob-etk-stat-label"><?php echo esc_html($label); ?></p>
            <p class="lrob-etk-stat-value <?php echo esc_attr($modifier); ?>"><?php echo esc_html($display); ?></p>
            <?php if ($value === null) : ?>
                <p class="lrob-etk-stat-trend"><?php esc_html_e('Enable Logging to track this.', 'lrob-email-toolkit'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_quick_test(): void
    {
        $identities = new IdentityRepository();
        $list = $identities->all();
        $smtp = $this->manager->get('smtp');
        $smtp_on = $smtp instanceof ModuleInterface && $smtp->is_enabled();

        // If no identities exist, nudge to the SMTP page instead.
        if ($list === []) {
            ?>
            <div class="lrob-etk-quick-test">
                <h2><?php esc_html_e('Quick test', 'lrob-email-toolkit'); ?></h2>
                <p>
                    <?php esc_html_e('Configure at least one SMTP identity to send test emails from here.', 'lrob-email-toolkit'); ?>
                </p>
                <?php if ($smtp instanceof ModuleInterface) : ?>
                    <a href="<?php echo esc_url($smtp->admin_page_url() ?: '#'); ?>" class="button button-primary">
                        <?php esc_html_e('Configure SMTP', 'lrob-email-toolkit'); ?>
                    </a>
                <?php endif; ?>
            </div>
            <?php
            return;
        }

        $action_url = admin_url('admin-post.php');
        $current_user = wp_get_current_user();
        $admin_email = get_option('admin_email');
        ?>
        <div class="lrob-etk-quick-test">
            <h2><?php esc_html_e('Send a test email', 'lrob-email-toolkit'); ?></h2>
            <?php if (!$smtp_on) : ?>
                <p class="description">
                    <?php esc_html_e('SMTP routing is currently off. Tests will still run against the chosen identity, but normal emails are not being routed.', 'lrob-email-toolkit'); ?>
                </p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_QUICK_TEST); ?>">
                <?php wp_nonce_field(self::ACTION_QUICK_TEST, '_lrob_etk_nonce'); ?>

                <div class="lrob-etk-quick-test-grid">
                    <div>
                        <label for="lrob-etk-qtest-identity"><?php esc_html_e('Identity', 'lrob-email-toolkit'); ?></label>
                        <select id="lrob-etk-qtest-identity" name="identity_id">
                            <?php foreach ($list as $identity) :
                                $default_flag = $identity->is_default ? ' ★' : '';
                                ?>
                                <option value="<?php echo (int) $identity->id; ?>" <?php selected($identity->is_default); ?>>
                                    <?php echo esc_html($identity->label . $default_flag); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="lrob-etk-qtest-recipient-choice"><?php esc_html_e('Recipient', 'lrob-email-toolkit'); ?></label>
                        <select id="lrob-etk-qtest-recipient-choice" name="recipient_choice">
                            <option value="current"><?php echo esc_html(sprintf(
                                /* translators: %s: current user email */
                                __('Me (%s)', 'lrob-email-toolkit'),
                                $current_user->user_email
                            )); ?></option>
                            <option value="admin"><?php echo esc_html(sprintf(
                                /* translators: %s: site admin email */
                                __('Site admin (%s)', 'lrob-email-toolkit'),
                                (string) $admin_email
                            )); ?></option>
                            <option value="custom"><?php esc_html_e('Custom address…', 'lrob-email-toolkit'); ?></option>
                        </select>
                    </div>
                    <div id="lrob-etk-qtest-custom-wrap" style="display:none;grid-column:1 / -1">
                        <label for="lrob-etk-qtest-custom"><?php esc_html_e('Custom recipient', 'lrob-email-toolkit'); ?></label>
                        <input type="email" id="lrob-etk-qtest-custom" name="recipient_custom" class="regular-text" placeholder="you@example.com">
                    </div>
                </div>

                <p class="submit" style="margin-top:16px">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Send test', 'lrob-email-toolkit'); ?>
                    </button>
                </p>
            </form>
        </div>

        <script>
        (function () {
            var choice = document.getElementById('lrob-etk-qtest-recipient-choice');
            var customWrap = document.getElementById('lrob-etk-qtest-custom-wrap');
            if (!choice || !customWrap) return;
            function toggle() { customWrap.style.display = choice.value === 'custom' ? '' : 'none'; }
            choice.addEventListener('change', toggle);
            toggle();
        })();
        </script>
        <?php
    }

    private function render_modules_grid(): void
    {
        $modules = $this->manager->all();
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
                        <?php elseif ($enabled) : ?>
                            <span class="lrob-etk-status lrob-etk-status--on"><?php esc_html_e('Active', 'lrob-email-toolkit'); ?></span>
                        <?php else : ?>
                            <span class="lrob-etk-status lrob-etk-status--off"><?php esc_html_e('Inactive', 'lrob-email-toolkit'); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="lrob-etk-module-card-description"><?php echo esc_html($module->description()); ?></p>
                    <?php if ($url !== null) : ?>
                        <div class="lrob-etk-module-card-actions">
                            <a href="<?php echo esc_url($url); ?>" class="button">
                                <?php echo $enabled
                                    ? esc_html__('Manage →', 'lrob-email-toolkit')
                                    : esc_html__('Set up →', 'lrob-email-toolkit'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_recent_activity(): void
    {
        $logging = $this->manager->get('logging');
        if (!$logging instanceof ModuleInterface || !$logging->is_enabled()) {
            return;
        }

        $repository = new LogRepository();
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
        $overrides = new \LRob\EmailToolkit\Modules\SMTP\ConstantOverrides();
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
