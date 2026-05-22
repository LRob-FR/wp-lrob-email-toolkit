<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging\Admin;

use LRob\EmailToolkit\Admin\ModuleToggle;
use LRob\EmailToolkit\Modules\ContactForm\Admin\SubmissionsPage as ContactFormSubmissionsPage;
use LRob\EmailToolkit\Modules\ContactForm\SubmissionRepository as ContactFormSubmissions;
use LRob\EmailToolkit\Modules\Logging\LogEntry;
use LRob\EmailToolkit\Modules\Logging\LogRepository;
use LRob\EmailToolkit\Modules\Logging\RetentionCron;
use LRob\EmailToolkit\Modules\ModuleInterface;

/**
 * Custom-styled logs page. Drops WP_List_Table entirely; renders its own
 * filter bar, bulk-action toolbar, table, pagination, and retention/cleanup
 * controls. Cleanup is an anchored popover from the header button.
 */
final class LogsPage
{
    public function __construct(
        private ModuleInterface $module,
        private LogRepository $repository,
    ) {
    }

    public function render(): void
    {
        $notice = PageController::pop_flash('notice');
        $errors = PageController::pop_flash('errors');
        $enabled = $this->module->is_enabled();
        // Count across everything (including newsletter rows) so the "no
        // logs yet" disabled-message doesn't fire on sites that have only
        // newsletter-failure logs (default behaviour hides those from the
        // main view but they still exist in the table).
        $log_count = $this->repository->count(['newsletter_mode' => 'include']);

        ?>
        <div class="wrap lrob-etk lrob-etk-logs-page">
            <header class="lrob-etk-page-header">
                <h1 class="lrob-etk-page-title"><?php esc_html_e('Email Logs', 'lrob-email-toolkit'); ?></h1>
                <?php ModuleToggle::render_inline($this->module); ?>
                <?php if ($enabled || $log_count > 0) : ?>
                    <div class="lrob-etk-page-header-actions">
                        <button type="button" id="lrob-etk-logs-settings-btn" class="button">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <?php esc_html_e('Settings', 'lrob-email-toolkit'); ?>
                        </button>
                        <button type="button" id="lrob-etk-logs-cleanup-btn" class="button">
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e('Cleanup', 'lrob-email-toolkit'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </header>

            <div id="lrob-etk-flash" class="lrob-etk-flash" aria-live="polite"></div>
            <?php $this->render_flash($notice, $errors); ?>
            <?php $this->render_toggle_notice(); ?>

            <?php if (!$enabled && $log_count === 0) : ?>
                <p class="lrob-etk-disabled-message">
                    <?php esc_html_e('Enable email logging to capture every outgoing email and let you audit deliverability, retry failed sends, and (later) archive copies to your IMAP Sent folder.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else : ?>
                <?php $this->render_logs_view(); ?>
            <?php endif; ?>

            <?php $this->render_cleanup_popover(); ?>
            <?php $this->render_settings_popover(); ?>
        </div>

        <script>
        <?php $this->print_inline_js(); ?>
        </script>
        <?php
    }

    private function render_logs_view(): void
    {
        $filters = $this->parse_filters();
        $per_page = (int) get_option(AjaxController::OPTION_PER_PAGE, AjaxController::DEFAULT_PER_PAGE);
        $per_page = max(5, min(500, $per_page));
        $page = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $total = $this->repository->count($filters);
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $entries = $this->repository->paginate($filters, $page, $per_page);

        $this->render_filter_bar($filters);

        if ($entries === [] && $total === 0) {
            $this->render_empty_state(!empty($filters));
            return;
        }

        $this->render_bulk_toolbar($page, $total, $per_page);
        $this->render_table($entries, $this->submission_link_map($entries));
        $this->render_pagination($page, $total_pages, $total);
    }

    /**
     * Batched log_id → submission_id lookup for the current page. Returns an
     * empty map when the Contact Form module isn't installed or has no
     * submissions table.
     *
     * @param array<int, LogEntry> $entries
     * @return array<int, int>
     */
    private function submission_link_map(array $entries): array
    {
        if (!class_exists(ContactFormSubmissions::class)) {
            return [];
        }
        $log_ids = array_map(static fn (LogEntry $e): int => $e->id, $entries);
        if ($log_ids === []) {
            return [];
        }
        return (new ContactFormSubmissions())->submission_ids_for_log_ids($log_ids);
    }

    /**
     * @return array{status?: string, source?: string, search?: string, date_from?: string, date_to?: string, newsletter_id?: int, newsletter_mode?: string}
     */
    private function parse_filters(): array
    {
        $f = [];
        if (!empty($_GET['status']) && is_string($_GET['status'])) {
            $f['status'] = sanitize_key($_GET['status']);
        }
        if (!empty($_GET['source']) && is_string($_GET['source'])) {
            $f['source'] = sanitize_key($_GET['source']);
        }
        if (!empty($_GET['s']) && is_string($_GET['s'])) {
            $f['search'] = sanitize_text_field(wp_unslash($_GET['s']));
        }
        if (!empty($_GET['date_from']) && is_string($_GET['date_from'])) {
            $d = sanitize_text_field(wp_unslash($_GET['date_from']));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $f['date_from'] = $d . ' 00:00:00';
            }
        }
        if (!empty($_GET['date_to']) && is_string($_GET['date_to'])) {
            $d = sanitize_text_field(wp_unslash($_GET['date_to']));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $f['date_to'] = $d . ' 23:59:59';
            }
        }
        // Newsletter visibility — exclude by default. The recipients-drawer
        // cross-link passes both newsletter_id and newsletter_mode=only so
        // landing from there shows the right rows immediately.
        if (!empty($_GET['newsletter_id'])) {
            $f['newsletter_id'] = (int) $_GET['newsletter_id'];
        }
        $mode = isset($_GET['newsletter_mode']) && is_string($_GET['newsletter_mode'])
            ? sanitize_key($_GET['newsletter_mode'])
            : '';
        if (in_array($mode, ['include', 'only'], true)) {
            $f['newsletter_mode'] = $mode;
        }
        return $f;
    }

    /** @param array<string, mixed> $filters */
    private function render_filter_bar(array $filters): void
    {
        $sources = $this->repository->distinct_sources();
        $current_status = (string) ($filters['status'] ?? '');
        $current_source = (string) ($filters['source'] ?? '');
        $current_search = (string) ($filters['search'] ?? '');
        // The filter array keeps full timestamps; the UI just shows the date.
        $current_from = isset($filters['date_from']) ? substr((string) $filters['date_from'], 0, 10) : '';
        $current_to = isset($filters['date_to']) ? substr((string) $filters['date_to'], 0, 10) : '';
        $current_nl_id = isset($filters['newsletter_id']) ? (int) $filters['newsletter_id'] : 0;
        $current_nl_mode = (string) ($filters['newsletter_mode'] ?? 'exclude');
        $has_filter = $current_status !== '' || $current_source !== '' || $current_search !== ''
            || $current_from !== '' || $current_to !== ''
            || $current_nl_id > 0 || $current_nl_mode !== 'exclude';
        ?>
        <form method="get" class="lrob-etk-logs-filter">
            <input type="hidden" name="page" value="<?php echo esc_attr(PageController::SLUG); ?>">
            <?php if ($current_nl_id > 0) : ?>
                <input type="hidden" name="newsletter_id" value="<?php echo (int) $current_nl_id; ?>">
            <?php endif; ?>

            <div class="lrob-etk-logs-filter-field">
                <label for="lrob-etk-filter-status"><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></label>
                <select name="status" id="lrob-etk-filter-status" class="lrob-etk-select">
                    <option value=""><?php esc_html_e('All', 'lrob-email-toolkit'); ?></option>
                    <option value="<?php echo esc_attr(LogEntry::STATUS_SENT); ?>" <?php selected($current_status, LogEntry::STATUS_SENT); ?>>
                        <?php esc_html_e('Sent', 'lrob-email-toolkit'); ?>
                    </option>
                    <option value="<?php echo esc_attr(LogEntry::STATUS_FAILED); ?>" <?php selected($current_status, LogEntry::STATUS_FAILED); ?>>
                        <?php esc_html_e('Failed', 'lrob-email-toolkit'); ?>
                    </option>
                    <option value="<?php echo esc_attr(LogEntry::STATUS_SENDING); ?>" <?php selected($current_status, LogEntry::STATUS_SENDING); ?>>
                        <?php esc_html_e('Sending', 'lrob-email-toolkit'); ?>
                    </option>
                    <option value="<?php echo esc_attr(LogEntry::STATUS_RETRIED); ?>" <?php selected($current_status, LogEntry::STATUS_RETRIED); ?>>
                        <?php esc_html_e('Retried', 'lrob-email-toolkit'); ?>
                    </option>
                </select>
            </div>

            <div class="lrob-etk-logs-filter-field">
                <label for="lrob-etk-filter-source"><?php esc_html_e('Source', 'lrob-email-toolkit'); ?></label>
                <select name="source" id="lrob-etk-filter-source" class="lrob-etk-select">
                    <option value=""><?php esc_html_e('All', 'lrob-email-toolkit'); ?></option>
                    <?php foreach ($sources as $source) : ?>
                        <option value="<?php echo esc_attr($source); ?>" <?php selected($current_source, $source); ?>>
                            <?php echo esc_html($source); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($current_nl_id === 0) : ?>
                <div class="lrob-etk-logs-filter-field">
                    <label for="lrob-etk-filter-newsletter"><?php esc_html_e('Newsletter', 'lrob-email-toolkit'); ?></label>
                    <select name="newsletter_mode" id="lrob-etk-filter-newsletter" class="lrob-etk-select">
                        <option value="exclude" <?php selected($current_nl_mode, 'exclude'); ?>><?php esc_html_e('Hide newsletter sends', 'lrob-email-toolkit'); ?></option>
                        <option value="include" <?php selected($current_nl_mode, 'include'); ?>><?php esc_html_e('Show all', 'lrob-email-toolkit'); ?></option>
                        <option value="only"    <?php selected($current_nl_mode, 'only');    ?>><?php esc_html_e('Newsletter only', 'lrob-email-toolkit'); ?></option>
                    </select>
                </div>
            <?php endif; ?>

            <div class="lrob-etk-logs-filter-field">
                <label for="lrob-etk-filter-date-from"><?php esc_html_e('From', 'lrob-email-toolkit'); ?></label>
                <input type="date" id="lrob-etk-filter-date-from" name="date_from" value="<?php echo esc_attr($current_from); ?>">
            </div>

            <div class="lrob-etk-logs-filter-field">
                <label for="lrob-etk-filter-date-to"><?php esc_html_e('To', 'lrob-email-toolkit'); ?></label>
                <input type="date" id="lrob-etk-filter-date-to" name="date_to" value="<?php echo esc_attr($current_to); ?>">
            </div>

            <div class="lrob-etk-logs-filter-field lrob-etk-logs-filter-search">
                <label for="lrob-etk-filter-search"><?php esc_html_e('Search', 'lrob-email-toolkit'); ?></label>
                <input type="search" id="lrob-etk-filter-search" name="s" value="<?php echo esc_attr($current_search); ?>"
                       placeholder="<?php esc_attr_e('Subject, from, to…', 'lrob-email-toolkit'); ?>">
            </div>

            <div class="lrob-etk-logs-filter-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'lrob-email-toolkit'); ?></button>
                <?php if ($has_filter) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . PageController::SLUG)); ?>" class="button button-link">
                        <?php esc_html_e('Reset', 'lrob-email-toolkit'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </form>
        <?php
    }

    private function render_bulk_toolbar(int $page, int $total, int $per_page): void
    {
        $first = ($page - 1) * $per_page + 1;
        $last = min($total, $page * $per_page);
        ?>
        <div class="lrob-etk-bulk-toolbar">
            <div class="lrob-etk-bulk-selection">
                <label>
                    <input type="checkbox" id="lrob-etk-select-all">
                    <span class="lrob-etk-bulk-count"><?php
                        printf(
                            /* translators: 1: first index, 2: last index, 3: total count */
                            esc_html__('Showing %1$d–%2$d of %3$d', 'lrob-email-toolkit'),
                            $first,
                            $last,
                            $total
                        );
                    ?></span>
                </label>
            </div>
            <div class="lrob-etk-bulk-action">
                <select id="lrob-etk-bulk-select" class="lrob-etk-select">
                    <option value=""><?php esc_html_e('Bulk action…', 'lrob-email-toolkit'); ?></option>
                    <option value="delete"><?php esc_html_e('Delete', 'lrob-email-toolkit'); ?></option>
                </select>
                <button type="button" id="lrob-etk-bulk-apply" class="button"><?php esc_html_e('Apply', 'lrob-email-toolkit'); ?></button>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<int, LogEntry> $entries
     * @param array<int, int> $submission_link_map  log_id → submission_id
     */
    private function render_table(array $entries, array $submission_link_map = []): void
    {
        ?>
        <div class="lrob-etk-logs-table-wrap">
            <table class="lrob-etk-logs-table">
                <thead>
                    <tr>
                        <th class="col-check"></th>
                        <th class="col-date"><?php esc_html_e('Date', 'lrob-email-toolkit'); ?></th>
                        <th class="col-status"><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                        <th class="col-from"><?php esc_html_e('From', 'lrob-email-toolkit'); ?></th>
                        <th class="col-to"><?php esc_html_e('To', 'lrob-email-toolkit'); ?></th>
                        <th class="col-subject"><?php esc_html_e('Subject', 'lrob-email-toolkit'); ?></th>
                        <th class="col-source"><?php esc_html_e('Source', 'lrob-email-toolkit'); ?></th>
                        <th class="col-actions"><?php esc_html_e('Actions', 'lrob-email-toolkit'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry) : ?>
                        <?php $this->render_table_row($entry, $submission_link_map[$entry->id] ?? null); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_table_row(LogEntry $entry, ?int $submission_id = null): void
    {
        $view_url = add_query_arg(
            ['page' => PageController::SLUG, 'action' => 'view', 'id' => $entry->id],
            admin_url('admin.php')
        );
        $to_summary = implode(', ', array_slice($entry->to_emails, 0, 2));
        if (count($entry->to_emails) > 2) {
            $to_summary .= ' …';
        }
        $subject = $entry->subject !== '' ? $entry->subject : __('(no subject)', 'lrob-email-toolkit');
        ?>
        <tr data-log-id="<?php echo (int) $entry->id; ?>">
            <td class="col-check">
                <input type="checkbox" class="lrob-etk-row-check" value="<?php echo (int) $entry->id; ?>">
            </td>
            <td class="col-date">
                <?php echo esc_html($entry->created_at->setTimezone(wp_timezone())->format('Y-m-d H:i:s')); ?>
            </td>
            <td class="col-status">
                <span class="lrob-etk-status <?php echo esc_attr($this->status_class($entry->status)); ?>">
                    <?php echo esc_html($this->status_label($entry->status)); ?>
                </span>
            </td>
            <td class="col-from"><?php echo esc_html($entry->from_email); ?></td>
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
                <?php if ($submission_id !== null) :
                    $submission_url = add_query_arg(
                        ['action' => 'view', 'id' => $submission_id],
                        ContactFormSubmissionsPage::base_url()
                    );
                    ?>
                    <a href="<?php echo esc_url($submission_url); ?>" class="lrob-etk-row-action" title="<?php esc_attr_e('View submission', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('View contact form submission', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-feedback"></span>
                    </a>
                <?php endif; ?>
                <button type="button" class="lrob-etk-row-action lrob-etk-row-resend" data-id="<?php echo (int) $entry->id; ?>" title="<?php esc_attr_e('Resend', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Resend email', 'lrob-email-toolkit'); ?>">
                    <span class="dashicons dashicons-update"></span>
                </button>
                <button type="button" class="lrob-etk-row-action lrob-etk-row-delete" data-id="<?php echo (int) $entry->id; ?>" title="<?php esc_attr_e('Delete', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Delete log entry', 'lrob-email-toolkit'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </td>
        </tr>
        <?php
    }

    private function render_pagination(int $page, int $total_pages, int $total): void
    {
        if ($total_pages <= 1) {
            return;
        }
        $base = add_query_arg('paged', '%#%');
        $links = paginate_links([
            'base'      => $base,
            'format'    => '',
            'current'   => $page,
            'total'     => $total_pages,
            'prev_text' => '‹ ' . __('Previous', 'lrob-email-toolkit'),
            'next_text' => __('Next', 'lrob-email-toolkit') . ' ›',
            'type'      => 'array',
        ]);
        if (!is_array($links) || $links === []) {
            return;
        }
        ?>
        <nav class="lrob-etk-pagination" aria-label="<?php esc_attr_e('Logs pagination', 'lrob-email-toolkit'); ?>">
            <?php foreach ($links as $link) : ?>
                <?php echo wp_kses_post($link); ?>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    private function render_empty_state(bool $filtered): void
    {
        ?>
        <div class="lrob-etk-empty">
            <?php if ($filtered) : ?>
                <p><?php esc_html_e('No log entries match your filters.', 'lrob-email-toolkit'); ?></p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . PageController::SLUG)); ?>" class="button">
                        <?php esc_html_e('Clear filters', 'lrob-email-toolkit'); ?>
                    </a>
                </p>
            <?php else : ?>
                <p><?php esc_html_e('No emails logged yet.', 'lrob-email-toolkit'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_cleanup_popover(): void
    {
        ?>
        <div class="lrob-etk-popover lrob-etk-logs-cleanup-popover" id="lrob-etk-logs-cleanup-popover" role="dialog" aria-label="<?php esc_attr_e('Cleanup logs', 'lrob-email-toolkit'); ?>" hidden>
            <header class="lrob-etk-popover-header">
                <h3><?php esc_html_e('Cleanup logs', 'lrob-email-toolkit'); ?></h3>
                <button type="button" class="lrob-etk-popover-close" aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>" data-cleanup-close>
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </header>
            <div class="lrob-etk-popover-body">
                <p class="lrob-etk-popover-help"><?php esc_html_e('One-shot manual cleanup. Automatic retention is configured in Settings.', 'lrob-email-toolkit'); ?></p>
                <div class="lrob-etk-cleanup-option">
                    <label>
                        <?php esc_html_e('Delete logs older than', 'lrob-email-toolkit'); ?>
                        <input type="number" id="lrob-etk-cleanup-days" class="small-text" min="1" max="3650" value="30">
                        <?php esc_html_e('days', 'lrob-email-toolkit'); ?>
                    </label>
                </div>
                <div class="lrob-etk-cleanup-result lrob-etk-test-result" hidden></div>
            </div>
            <footer class="lrob-etk-popover-footer">
                <button type="button" class="button" data-cleanup-close><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></button>
                <button type="button" class="button button-primary" data-cleanup-action="older_than">
                    <?php esc_html_e('Delete matching logs', 'lrob-email-toolkit'); ?>
                </button>
            </footer>
        </div>
        <?php
    }

    private function render_settings_popover(): void
    {
        $retention = (int) get_option(RetentionCron::OPTION_RETENTION_DAYS, RetentionCron::DEFAULT_RETENTION_DAYS);
        $per_page = (int) get_option(AjaxController::OPTION_PER_PAGE, AjaxController::DEFAULT_PER_PAGE);
        ?>
        <div class="lrob-etk-popover lrob-etk-logs-settings-popover" id="lrob-etk-logs-settings-popover" role="dialog" aria-label="<?php esc_attr_e('Log settings', 'lrob-email-toolkit'); ?>" hidden>
            <header class="lrob-etk-popover-header">
                <h3><?php esc_html_e('Log settings', 'lrob-email-toolkit'); ?></h3>
                <button type="button" class="lrob-etk-popover-close" aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>" data-settings-close>
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </header>
            <div class="lrob-etk-popover-body">
                <div class="lrob-etk-field">
                    <label for="lrob-etk-settings-retention"><?php esc_html_e('Retention', 'lrob-email-toolkit'); ?></label>
                    <p class="lrob-etk-popover-help"><?php esc_html_e('Older entries are deleted daily by a cron event. 0 = keep forever.', 'lrob-email-toolkit'); ?></p>
                    <p>
                        <input type="number" id="lrob-etk-settings-retention" class="small-text" min="0" max="3650" value="<?php echo (int) $retention; ?>">
                        <?php esc_html_e('days', 'lrob-email-toolkit'); ?>
                    </p>
                </div>
                <hr style="margin: 12px 0; border: 0; border-top: 1px solid var(--etk-soft);">
                <div class="lrob-etk-field">
                    <label for="lrob-etk-settings-perpage"><?php esc_html_e('Entries per page', 'lrob-email-toolkit'); ?></label>
                    <p>
                        <select id="lrob-etk-settings-perpage" class="lrob-etk-select">
                            <?php foreach ([10, 20, 50, 100, 200] as $opt) : ?>
                                <option value="<?php echo (int) $opt; ?>" <?php selected($per_page, $opt); ?>><?php echo (int) $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                </div>
                <div class="lrob-etk-test-result lrob-etk-settings-result" hidden></div>
            </div>
            <footer class="lrob-etk-popover-footer">
                <button type="button" class="button" data-settings-close><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></button>
                <button type="button" class="button button-primary" id="lrob-etk-settings-save">
                    <?php esc_html_e('Save', 'lrob-email-toolkit'); ?>
                </button>
            </footer>
        </div>
        <?php
    }

    private function print_inline_js(): void
    {
        ?>
        window.lrobEtkLogs = {
            ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
            nonce: <?php echo wp_json_encode(wp_create_nonce(AjaxController::NONCE_ACTION)); ?>,
            actions: {
                delete:       <?php echo wp_json_encode(AjaxController::ACTION_DELETE); ?>,
                bulkDelete:   <?php echo wp_json_encode(AjaxController::ACTION_BULK_DELETE); ?>,
                purge:        <?php echo wp_json_encode(AjaxController::ACTION_PURGE); ?>,
                resend:       <?php echo wp_json_encode(AjaxController::ACTION_RESEND); ?>,
                saveSettings: <?php echo wp_json_encode(AjaxController::ACTION_SAVE_SETTINGS); ?>
            },
            i18n: {
                confirmDelete:     <?php echo wp_json_encode(__('Delete this log entry?', 'lrob-email-toolkit')); ?>,
                confirmBulkDelete: <?php
                    /* translators: %d: number of selected log entries */
                    echo wp_json_encode(__('Delete %d selected log entries?', 'lrob-email-toolkit'));
                ?>,
                confirmResend:     <?php echo wp_json_encode(__('Resend this email now?', 'lrob-email-toolkit')); ?>,
                noSelection:       <?php echo wp_json_encode(__('Select at least one entry.', 'lrob-email-toolkit')); ?>,
                pickAction:        <?php echo wp_json_encode(__('Pick an action first.', 'lrob-email-toolkit')); ?>,
                resending:         <?php echo wp_json_encode(__('Resending…', 'lrob-email-toolkit')); ?>,
                working:           <?php echo wp_json_encode(__('Working…', 'lrob-email-toolkit')); ?>,
                saving:            <?php echo wp_json_encode(__('Saving…', 'lrob-email-toolkit')); ?>,
                unknownError:      <?php echo wp_json_encode(__('Something went wrong.', 'lrob-email-toolkit')); ?>
            }
        };

(function () {
    var L = window.lrobEtkLogs;
    if (!L) return;

    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function ajax(action, params) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('_nonce', L.nonce);
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (v === undefined || v === null) return;
            if (Array.isArray(v)) { v.forEach(function (item) { fd.append(k + '[]', item); }); return; }
            fd.append(k, v);
        });
        return fetch(L.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); });
    }

    function flash(msg, type) {
        var holder = document.getElementById('lrob-etk-flash');
        if (!holder) return;
        var div = document.createElement('div');
        div.className = 'notice notice-' + (type === 'error' ? 'error' : 'success') + ' is-dismissible';
        var p = document.createElement('p'); p.textContent = msg; div.appendChild(p);
        holder.appendChild(div);
        setTimeout(function () { if (div.parentNode) div.parentNode.removeChild(div); }, 5000);
    }

    // ---- Select-all + bulk action ----
    var selectAll = document.getElementById('lrob-etk-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            $$('.lrob-etk-row-check').forEach(function (cb) { cb.checked = selectAll.checked; });
        });
    }

    var bulkApply = document.getElementById('lrob-etk-bulk-apply');
    if (bulkApply) {
        bulkApply.addEventListener('click', function () {
            var actionSel = document.getElementById('lrob-etk-bulk-select');
            var action = actionSel ? actionSel.value : '';
            if (!action) { flash(L.i18n.pickAction, 'error'); return; }
            var ids = $$('.lrob-etk-row-check:checked').map(function (cb) { return cb.value; });
            if (ids.length === 0) { flash(L.i18n.noSelection, 'error'); return; }
            if (action === 'delete') {
                if (!confirm(L.i18n.confirmBulkDelete.replace('%d', ids.length))) return;
                bulkApply.disabled = true;
                ajax(L.actions.bulkDelete, { ids: ids }).then(function (resp) {
                    if (resp.success) {
                        flash(resp.data.message, 'success');
                        setTimeout(function () { window.location.reload(); }, 400);
                    } else {
                        flash((resp.data && resp.data.message) || L.i18n.unknownError, 'error');
                        bulkApply.disabled = false;
                    }
                });
            }
        });
    }

    // ---- Per-row delete ----
    $$('.lrob-etk-row-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm(L.i18n.confirmDelete)) return;
            var id = btn.getAttribute('data-id');
            ajax(L.actions.delete, { id: id }).then(function (resp) {
                if (resp.success) {
                    var row = btn.closest('tr');
                    if (row) row.parentNode.removeChild(row);
                    flash(resp.data.message, 'success');
                } else {
                    flash((resp.data && resp.data.message) || L.i18n.unknownError, 'error');
                }
            });
        });
    });

    // ---- Per-row resend ----
    $$('.lrob-etk-row-resend').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm(L.i18n.confirmResend)) return;
            var id = btn.getAttribute('data-id');
            var icon = btn.querySelector('.dashicons');
            btn.disabled = true;
            if (icon) icon.classList.add('lrob-etk-spin');
            ajax(L.actions.resend, { id: id }).then(function (resp) {
                btn.disabled = false;
                if (icon) icon.classList.remove('lrob-etk-spin');
                if (resp.success) {
                    flash(resp.data.message, 'success');
                    setTimeout(function () { window.location.reload(); }, 800);
                } else {
                    flash((resp.data && resp.data.message) || L.i18n.unknownError, 'error');
                }
            }).catch(function () {
                btn.disabled = false;
                if (icon) icon.classList.remove('lrob-etk-spin');
                flash(L.i18n.unknownError, 'error');
            });
        });
    });

    // ---- Cleanup popover ----
    var cleanupBtn = document.getElementById('lrob-etk-logs-cleanup-btn');
    var cleanupPop = document.getElementById('lrob-etk-logs-cleanup-popover');
    function positionPopover(popover, anchor) {
        popover.hidden = false;
        var pRect = popover.getBoundingClientRect();
        var aRect = anchor.getBoundingClientRect();
        var margin = 8;
        var top = aRect.bottom + margin;
        if (top + pRect.height > window.innerHeight - margin) {
            top = aRect.top - pRect.height - margin;
            if (top < margin) top = margin;
        }
        var left = aRect.right - pRect.width;
        if (left < margin) left = margin;
        if (left + pRect.width > window.innerWidth - margin) left = window.innerWidth - pRect.width - margin;
        popover.style.position = 'fixed';
        popover.style.top = top + 'px';
        popover.style.left = left + 'px';
    }
    if (cleanupBtn && cleanupPop) {
        cleanupBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (cleanupPop.hidden) {
                positionPopover(cleanupPop, cleanupBtn);
            } else {
                cleanupPop.hidden = true;
            }
        });
        document.addEventListener('click', function (e) {
            if (!cleanupPop.hidden && !cleanupPop.contains(e.target) && e.target !== cleanupBtn && !cleanupBtn.contains(e.target)) {
                cleanupPop.hidden = true;
            }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cleanupPop.hidden = true; });
        cleanupPop.querySelectorAll('[data-cleanup-close]').forEach(function (b) {
            b.addEventListener('click', function () { cleanupPop.hidden = true; });
        });

        cleanupPop.querySelectorAll('[data-cleanup-action]').forEach(function (b) {
            b.addEventListener('click', function () {
                var mode = b.getAttribute('data-cleanup-action');
                var result = cleanupPop.querySelector('.lrob-etk-cleanup-result');
                var params = { mode: mode };
                if (mode === 'older_than') {
                    var daysEl = document.getElementById('lrob-etk-cleanup-days');
                    params.days = daysEl ? daysEl.value : 30;
                }
                b.disabled = true;
                result.hidden = false;
                result.className = 'lrob-etk-test-result lrob-etk-cleanup-result is-pending';
                result.textContent = L.i18n.working;
                ajax(L.actions.purge, params).then(function (resp) {
                    b.disabled = false;
                    if (resp.success) {
                        result.className = 'lrob-etk-test-result lrob-etk-cleanup-result is-success';
                        result.textContent = '✓ ' + resp.data.message;
                        setTimeout(function () { window.location.reload(); }, 800);
                    } else {
                        result.className = 'lrob-etk-test-result lrob-etk-cleanup-result is-failure';
                        result.textContent = '✗ ' + ((resp.data && resp.data.message) || L.i18n.unknownError);
                    }
                });
            });
        });
    }

    // ---- Settings popover ----
    var settingsBtn = document.getElementById('lrob-etk-logs-settings-btn');
    var settingsPop = document.getElementById('lrob-etk-logs-settings-popover');
    if (settingsBtn && settingsPop) {
        settingsBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (settingsPop.hidden) positionPopover(settingsPop, settingsBtn);
            else settingsPop.hidden = true;
        });
        document.addEventListener('click', function (e) {
            if (!settingsPop.hidden && !settingsPop.contains(e.target) && e.target !== settingsBtn && !settingsBtn.contains(e.target)) {
                settingsPop.hidden = true;
            }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') settingsPop.hidden = true; });
        settingsPop.querySelectorAll('[data-settings-close]').forEach(function (b) {
            b.addEventListener('click', function () { settingsPop.hidden = true; });
        });
        var saveBtn = document.getElementById('lrob-etk-settings-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var ret = document.getElementById('lrob-etk-settings-retention');
                var per = document.getElementById('lrob-etk-settings-perpage');
                var result = settingsPop.querySelector('.lrob-etk-settings-result');
                saveBtn.disabled = true;
                result.hidden = false;
                result.className = 'lrob-etk-test-result lrob-etk-settings-result is-pending';
                result.textContent = L.i18n.saving;
                ajax(L.actions.saveSettings, {
                    retention_days: ret ? ret.value : 365,
                    per_page: per ? per.value : 20
                }).then(function (resp) {
                    saveBtn.disabled = false;
                    if (resp.success) {
                        result.className = 'lrob-etk-test-result lrob-etk-settings-result is-success';
                        result.textContent = '✓ ' + resp.data.message;
                        setTimeout(function () { window.location.reload(); }, 600);
                    } else {
                        result.className = 'lrob-etk-test-result lrob-etk-settings-result is-failure';
                        result.textContent = '✗ ' + ((resp.data && resp.data.message) || L.i18n.unknownError);
                    }
                });
            });
        }
    }
})();
        <?php
    }

    private function status_class(string $status): string
    {
        return match ($status) {
            LogEntry::STATUS_SENT    => 'lrob-etk-status--on',
            LogEntry::STATUS_FAILED  => 'lrob-etk-status--fail',
            LogEntry::STATUS_SENDING => 'lrob-etk-status--pending',
            LogEntry::STATUS_RETRIED => 'lrob-etk-status--off',
            default                  => 'lrob-etk-status--off',
        };
    }

    private function status_label(string $status): string
    {
        return match ($status) {
            LogEntry::STATUS_SENT    => __('Sent', 'lrob-email-toolkit'),
            LogEntry::STATUS_FAILED  => __('Failed', 'lrob-email-toolkit'),
            LogEntry::STATUS_SENDING => __('Sending', 'lrob-email-toolkit'),
            LogEntry::STATUS_RETRIED => __('Retried', 'lrob-email-toolkit'),
            default                  => $status,
        };
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

    private function render_toggle_notice(): void
    {
        if (!isset($_GET['toggled'])) {
            return;
        }
        $toggled = sanitize_key((string) $_GET['toggled']);
        $message = $toggled === 'on'
            ? __('Email logging is now active.', 'lrob-email-toolkit')
            : __('Email logging is now off. Existing logs are preserved.', 'lrob-email-toolkit');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
