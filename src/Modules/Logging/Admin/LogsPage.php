<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging\Admin;

use LRob\EmailToolkit\Admin\PageHeader;
use LRob\EmailToolkit\Admin\RetentionToggle;
use LRob\EmailToolkit\Modules\ContactForm\Admin\SubmissionsPage as ContactFormSubmissionsPage;
use LRob\EmailToolkit\Modules\ContactForm\SubmissionRepository as ContactFormSubmissions;
use LRob\EmailToolkit\Modules\Logging\LogEntry;
use LRob\EmailToolkit\Modules\Logging\LogRepository;
use LRob\EmailToolkit\Modules\Logging\RetentionCron;
use LRob\EmailToolkit\Modules\ModuleInterface;

// Docs: docs/logging.md
final class LogsPage
{
    public function __construct(
        // Nullable: AJAX list-filter endpoint uses only render_list_region_for_filters(); render() requires non-null.
        private ?ModuleInterface $module,
        private LogRepository $repository,
    ) {
    }

    public function render(): void
    {
        $notice = PageController::pop_flash('notice');
        $errors = PageController::pop_flash('errors');
        $enabled = $this->module->is_enabled();
        // Include newsletter rows: a site with only newsletter-failure logs should not show the "enable" empty state.
        $log_count = $this->repository->count(['newsletter_mode' => 'include']);

        ?>
        <div class="wrap lrob-etk lrob-etk-logs-page">
            <?php PageHeader::render([
                'title'  => __('Email Logs', 'lrob-email-toolkit'),
                'module' => $this->module,
                'gate'   => $enabled || $log_count > 0,
                'tools'  => [
                    [
                        'label' => __('Storage', 'lrob-email-toolkit'),
                        'icon'  => 'dashicons-database',
                        'id'    => 'lrob-etk-logs-storage-btn',
                    ],
                ],
                'nav'    => [
                    [
                        'label' => __('Contact form submissions', 'lrob-email-toolkit'),
                        'icon'  => 'dashicons-feedback',
                        'href'  => admin_url('admin.php?page=lrob-etk-cform&view=submissions'),
                    ],
                ],
            ]); ?>

            <div id="lrob-etk-flash" class="lrob-etk-flash" aria-live="polite"></div>
            <?php $this->render_flash($notice, $errors); ?>
            <?php $this->render_toggle_notice(); ?>

            <?php if (!$enabled && $log_count === 0) : ?>
                <p class="lrob-etk-disabled-message">
                    <?php esc_html_e('Enable email logging to capture every outgoing email and let you audit deliverability and retry failed sends.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else : ?>
                <?php $this->render_logs_view(); ?>
            <?php endif; ?>

            <?php $this->render_storage_modal(); ?>
        </div>

        <script>
        <?php $this->print_inline_js(); ?>
        </script>
        <?php
    }

    private function render_logs_view(): void
    {
        $filters = self::parse_filters();
        $per_page = \LRob\EmailToolkit\Admin\PerPagePicker::parse('logs', AjaxController::DEFAULT_PER_PAGE);
        $page = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $total = $this->repository->count($filters);
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $entries = $this->repository->paginate($filters, $page, $per_page);

        $this->render_filter_bar($filters);
        $this->render_list_region($filters, $entries, $page, $total, $total_pages, $per_page);
    }

    /**
     * @param array<string, mixed>  $filters
     * @param array<int, LogEntry>  $entries
     */
    public function render_list_region(array $filters, array $entries, int $page, int $total, int $total_pages, int $per_page): void
    {
        ?>
        <div class="lrob-etk-list-region" data-etk-list-region>
            <?php if ($entries === [] && $total === 0) : ?>
                <?php $this->render_empty_state(!empty($filters)); ?>
            <?php else : ?>
                <?php $this->render_bulk_toolbar($page, $total, $per_page); ?>
                <?php $this->render_table($entries, $this->submission_link_map($entries)); ?>
                <?php $this->render_pagination($page, $total_pages, $total); ?>
            <?php endif; ?>
            <div class="lrob-etk-list-loading" aria-hidden="true"><span class="spinner is-active"></span></div>
        </div>
        <?php
    }

    public function render_list_region_for_filters(array $filters, int $page): void
    {
        $per_page = \LRob\EmailToolkit\Admin\PerPagePicker::parse('logs', AjaxController::DEFAULT_PER_PAGE);
        $total = $this->repository->count($filters);
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $page = max(1, $page);
        $entries = $this->repository->paginate($filters, $page, $per_page);
        $this->render_list_region($filters, $entries, $page, $total, $total_pages, $per_page);
    }


    /**
     * @param array<int, LogEntry> $entries
     * @return array<int, int>  log_id → submission_id
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
     * @param array<string, mixed>|null $source defaults to $_GET; pass $_POST from the AJAX filter endpoint.
     * @return array{status?: string, source?: string, search?: string, date_from?: string, date_to?: string, newsletter_id?: int, newsletter_mode?: string}
     */
    public static function parse_filters(?array $source = null): array
    {
        $src = $source ?? $_GET;
        $f = [];
        if (!empty($src['status']) && is_string($src['status'])) {
            $f['status'] = sanitize_key($src['status']);
        }
        if (!empty($src['source']) && is_string($src['source'])) {
            $f['source'] = sanitize_key($src['source']);
        }
        if (!empty($src['s']) && is_string($src['s'])) {
            $f['search'] = sanitize_text_field(wp_unslash($src['s']));
        }
        if (!empty($src['date_from']) && is_string($src['date_from'])) {
            $d = sanitize_text_field(wp_unslash($src['date_from']));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $f['date_from'] = $d . ' 00:00:00';
            }
        }
        if (!empty($src['date_to']) && is_string($src['date_to'])) {
            $d = sanitize_text_field(wp_unslash($src['date_to']));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $f['date_to'] = $d . ' 23:59:59';
            }
        }
        if (!empty($src['newsletter_id'])) {
            $f['newsletter_id'] = (int) $src['newsletter_id'];
        }
        $mode = isset($src['newsletter_mode']) && is_string($src['newsletter_mode'])
            ? sanitize_key($src['newsletter_mode'])
            : '';
        if (in_array($mode, ['include', 'only'], true)) {
            $f['newsletter_mode'] = $mode;
        }
        if (!empty($src['orderby']) && is_string($src['orderby'])) {
            $f['orderby'] = sanitize_key((string) $src['orderby']);
        }
        if (!empty($src['order']) && is_string($src['order'])) {
            $o = sanitize_key((string) $src['order']);
            if (in_array($o, ['asc', 'desc'], true)) {
                $f['order'] = $o;
            }
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
        <form method="get" class="lrob-etk-filter-bar" data-etk-list-form>
            <input type="hidden" name="page" value="<?php echo esc_attr(PageController::SLUG); ?>">
            <?php if ($current_nl_id > 0) : ?>
                <input type="hidden" name="newsletter_id" value="<?php echo (int) $current_nl_id; ?>">
            <?php endif; ?>

            <div class="lrob-etk-filter-bar-field">
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

            <div class="lrob-etk-filter-bar-field">
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
                <div class="lrob-etk-filter-bar-field">
                    <label for="lrob-etk-filter-newsletter"><?php esc_html_e('Newsletter', 'lrob-email-toolkit'); ?></label>
                    <select name="newsletter_mode" id="lrob-etk-filter-newsletter" class="lrob-etk-select">
                        <option value="exclude" <?php selected($current_nl_mode, 'exclude'); ?>><?php esc_html_e('Hide newsletter sends', 'lrob-email-toolkit'); ?></option>
                        <option value="include" <?php selected($current_nl_mode, 'include'); ?>><?php esc_html_e('Show all', 'lrob-email-toolkit'); ?></option>
                        <option value="only"    <?php selected($current_nl_mode, 'only');    ?>><?php esc_html_e('Newsletter only', 'lrob-email-toolkit'); ?></option>
                    </select>
                </div>
            <?php endif; ?>

            <div class="lrob-etk-filter-bar-field">
                <label for="lrob-etk-filter-date-from"><?php esc_html_e('From', 'lrob-email-toolkit'); ?></label>
                <input type="date" id="lrob-etk-filter-date-from" name="date_from" value="<?php echo esc_attr($current_from); ?>">
            </div>

            <div class="lrob-etk-filter-bar-field">
                <label for="lrob-etk-filter-date-to"><?php esc_html_e('To', 'lrob-email-toolkit'); ?></label>
                <input type="date" id="lrob-etk-filter-date-to" name="date_to" value="<?php echo esc_attr($current_to); ?>">
            </div>

            <div class="lrob-etk-filter-bar-field lrob-etk-filter-bar-field--search">
                <label for="lrob-etk-filter-search"><?php esc_html_e('Search', 'lrob-email-toolkit'); ?></label>
                <input type="search" id="lrob-etk-filter-search" name="s" value="<?php echo esc_attr($current_search); ?>"
                       placeholder="<?php esc_attr_e('Subject, from, to…', 'lrob-email-toolkit'); ?>">
            </div>

            <div class="lrob-etk-filter-bar-actions">
                <noscript>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'lrob-email-toolkit'); ?></button>
                </noscript>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . PageController::SLUG)); ?>" class="button button-link" data-etk-list-reset<?php echo $has_filter ? '' : ' hidden'; ?>>
                    <?php esc_html_e('Reset', 'lrob-email-toolkit'); ?>
                </a>
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
            <div class="lrob-etk-bulk-action">
                <select id="lrob-etk-bulk-select" class="lrob-etk-select">
                    <option value=""><?php esc_html_e('Bulk action…', 'lrob-email-toolkit'); ?></option>
                    <option value="delete"><?php esc_html_e('Delete', 'lrob-email-toolkit'); ?></option>
                </select>
                <button type="button" id="lrob-etk-bulk-apply" class="button"><?php esc_html_e('Apply', 'lrob-email-toolkit'); ?></button>
            </div>
            <div class="lrob-etk-bulk-action">
                <span class="lrob-etk-bulk-count"><?php
                    printf(
                        /* translators: 1: first index, 2: last index, 3: total count */
                        esc_html__('Showing %1$d–%2$d of %3$d', 'lrob-email-toolkit'),
                        $first,
                        $last,
                        $total
                    );
                ?></span>
                <?php \LRob\EmailToolkit\Admin\PerPagePicker::render('logs', $per_page); ?>
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
        <div class="lrob-etk-data-table-wrap">
            <table class="lrob-etk-data-table">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" id="lrob-etk-select-all" aria-label="<?php esc_attr_e('Select all', 'lrob-email-toolkit'); ?>"></th>
                        <th class="col-date" data-sort-key="created_at"><?php esc_html_e('Date', 'lrob-email-toolkit'); ?></th>
                        <th class="col-status" data-sort-key="status"><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                        <th class="col-from" data-sort-key="from_email"><?php esc_html_e('From', 'lrob-email-toolkit'); ?></th>
                        <th class="col-to" data-sort-key="to_emails"><?php esc_html_e('To', 'lrob-email-toolkit'); ?></th>
                        <th class="col-subject" data-sort-key="subject"><?php esc_html_e('Subject', 'lrob-email-toolkit'); ?></th>
                        <th class="col-source" data-sort-key="source"><?php esc_html_e('Source', 'lrob-email-toolkit'); ?></th>
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
            ['page' => PageController::SLUG, 'detail' => $entry->id],
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
                <span class="lrob-etk-status <?php echo esc_attr(LogEntry::status_class($entry->status)); ?>">
                    <?php echo esc_html(LogEntry::status_label($entry->status)); ?>
                </span>
            </td>
            <td class="col-from"><?php echo esc_html($entry->from_email); ?></td>
            <td class="col-to"><?php echo esc_html($to_summary); ?></td>
            <td class="col-subject">
                <a href="<?php echo esc_url($view_url); ?>" class="lrob-etk-subject-link" data-etk-open-detail data-etk-row-id="<?php echo (int) $entry->id; ?>">
                    <?php echo esc_html($subject); ?>
                </a>
            </td>
            <td class="col-source"><code><?php echo esc_html($entry->source); ?></code></td>
            <td class="col-actions">
                <a href="<?php echo esc_url($view_url); ?>" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost" data-etk-open-detail data-etk-row-id="<?php echo (int) $entry->id; ?>" title="<?php esc_attr_e('View', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('View log entry', 'lrob-email-toolkit'); ?>">
                    <span class="dashicons dashicons-visibility"></span>
                </a>
                <?php if ($submission_id !== null) :
                    $submission_url = add_query_arg(
                        ['detail' => $submission_id],
                        ContactFormSubmissionsPage::base_url()
                    );
                    ?>
                    <a href="<?php echo esc_url($submission_url); ?>" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost" title="<?php esc_attr_e('View submission', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('View contact form submission', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-feedback"></span>
                    </a>
                <?php endif; ?>
                <button type="button" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-row-resend" data-id="<?php echo (int) $entry->id; ?>" title="<?php esc_attr_e('Resend', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Resend email', 'lrob-email-toolkit'); ?>">
                    <span class="dashicons dashicons-update"></span>
                </button>
                <button type="button" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-row-delete" data-id="<?php echo (int) $entry->id; ?>" title="<?php esc_attr_e('Delete', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Delete log entry', 'lrob-email-toolkit'); ?>">
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

    private function render_storage_modal(): void
    {
        $retention = (int) get_option(RetentionCron::OPTION_RETENTION_DAYS, RetentionCron::DEFAULT_RETENTION_DAYS);
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-logs-storage-modal" role="dialog" aria-modal="true" aria-labelledby="lrob-etk-logs-storage-title" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog">
                <header class="lrob-etk-modal-header">
                    <h3 id="lrob-etk-logs-storage-title" class="lrob-etk-modal-title-text"><?php esc_html_e('Email logs storage', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p class="description" style="margin-top: 0;">
                        <?php esc_html_e('Automatic retention, display preferences, and one-shot manual cleanup. Settings save automatically.', 'lrob-email-toolkit'); ?>
                    </p>

                    <article class="lrob-etk-card lrob-etk-logs-storage-card" data-defaults-card="1">
                        <form class="lrob-etk-card-form" onsubmit="return false">
                            <header class="lrob-etk-card-form-head">
                                <span class="lrob-etk-card-status" aria-live="polite"></span>
                            </header>

                            <section class="lrob-etk-logs-storage-section">
                                <h4 class="lrob-etk-popover-section-title"><?php esc_html_e('Retention', 'lrob-email-toolkit'); ?></h4>
                                <p class="description"><?php esc_html_e('Daily cron prunes older log entries. Turn off to keep every entry forever.', 'lrob-email-toolkit'); ?></p>
                                <div class="lrob-etk-field">
                                    <?php RetentionToggle::render([
                                        'key'              => 'retention_days',
                                        'value'            => $retention,
                                        'auto_save_marker' => 'lrob-etk-logs-field',
                                        'default_days'     => RetentionCron::DEFAULT_RETENTION_DAYS,
                                    ]); ?>
                                </div>

                                <h4 class="lrob-etk-popover-section-title"><?php esc_html_e('Manual cleanup', 'lrob-email-toolkit'); ?></h4>
                                <p class="description"><?php esc_html_e('One-shot deletion, independent from the retention cron above.', 'lrob-email-toolkit'); ?></p>
                                <div class="lrob-etk-cleanup-row">
                                    <label>
                                        <?php esc_html_e('Delete logs older than', 'lrob-email-toolkit'); ?>
                                        <input type="number" id="lrob-etk-cleanup-days" class="small-text" min="1" max="3650" value="30">
                                        <?php esc_html_e('days', 'lrob-email-toolkit'); ?>
                                    </label>
                                    <button type="button" class="button button-secondary" data-cleanup-action="older_than">
                                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                        <?php esc_html_e('Delete matching logs', 'lrob-email-toolkit'); ?>
                                    </button>
                                </div>
                                <div class="lrob-etk-cleanup-result lrob-etk-test-result" hidden></div>
                            </section>
                        </form>
                    </article>
                </div>
            </div>
        </div>
        <?php
    }

    private function print_inline_js(): void
    {
        // ?detail=N: direct link (dashboard, email "View", cross-link) → auto-open detail modal.
        $auto_open_id = isset($_GET['detail']) ? max(0, (int) $_GET['detail']) : 0;
        ?>
        window.lrobEtkLogs = {
            ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
            nonce: <?php echo wp_json_encode(wp_create_nonce(AjaxController::NONCE_ACTION)); ?>,
            autoOpenId: <?php echo (int) $auto_open_id; ?>,
            actions: {
                delete:       <?php echo wp_json_encode(AjaxController::ACTION_DELETE); ?>,
                bulkDelete:   <?php echo wp_json_encode(AjaxController::ACTION_BULK_DELETE); ?>,
                purge:        <?php echo wp_json_encode(AjaxController::ACTION_PURGE); ?>,
                resend:       <?php echo wp_json_encode(AjaxController::ACTION_RESEND); ?>,
                saveSetting:  <?php echo wp_json_encode(AjaxController::ACTION_SAVE_SETTING); ?>,
                listFilter:   <?php echo wp_json_encode(AjaxController::ACTION_LIST_FILTER); ?>,
                detail:       <?php echo wp_json_encode(AjaxController::ACTION_DETAIL); ?>
            },
            i18n: {
                confirmDelete:     <?php echo wp_json_encode(__('Delete this log entry?', 'lrob-email-toolkit')); ?>,
                confirmBulkDelete: <?php
                    /* translators: %d: number of selected log entries */
                    echo wp_json_encode(__('Delete %d selected log entries?', 'lrob-email-toolkit'));
                ?>,
                confirmResend:     <?php echo wp_json_encode(__('Resend this email now?', 'lrob-email-toolkit')); ?>,
                confirmCleanup:    <?php
                    /* translators: %d: number of days */
                    echo wp_json_encode(__('Delete every log entry older than %d days? This cannot be undone.', 'lrob-email-toolkit'));
                ?>,
                noSelection:       <?php echo wp_json_encode(__('Select at least one entry.', 'lrob-email-toolkit')); ?>,
                pickAction:        <?php echo wp_json_encode(__('Pick an action first.', 'lrob-email-toolkit')); ?>,
                resending:         <?php echo wp_json_encode(__('Resending…', 'lrob-email-toolkit')); ?>,
                working:           <?php echo wp_json_encode(__('Working…', 'lrob-email-toolkit')); ?>,
                saving:            <?php echo wp_json_encode(__('Saving…', 'lrob-email-toolkit')); ?>,
                saved:             <?php echo wp_json_encode(__('Saved', 'lrob-email-toolkit')); ?>,
                saveError:         <?php echo wp_json_encode(__('Save failed', 'lrob-email-toolkit')); ?>,
                unknownError:      <?php echo wp_json_encode(__('Something went wrong.', 'lrob-email-toolkit')); ?>,
                detailPrev:        <?php echo wp_json_encode(__('Previous', 'lrob-email-toolkit')); ?>,
                detailNext:        <?php echo wp_json_encode(__('Next', 'lrob-email-toolkit')); ?>,
                detailClose:       <?php echo wp_json_encode(__('Close', 'lrob-email-toolkit')); ?>,
                detailLoading:     <?php echo wp_json_encode(__('Loading…', 'lrob-email-toolkit')); ?>,
                detailResend:      <?php echo wp_json_encode(__('Resend', 'lrob-email-toolkit')); ?>,
                detailDelete:      <?php echo wp_json_encode(__('Delete', 'lrob-email-toolkit')); ?>
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

    function ask(opts) {
        if (!window.lrobEtkConfirm) return Promise.resolve(true);
        return window.lrobEtkConfirm.prompt(opts);
    }

    // ---- List-region interactions (select-all, bulk, per-row) ----
    document.addEventListener('change', function (e) {
        var sa = e.target.closest && e.target.closest('#lrob-etk-select-all');
        if (sa) {
            $$('.lrob-etk-row-check').forEach(function (cb) { cb.checked = sa.checked; });
        }
    });

    document.addEventListener('click', function (e) {
        var bulkApply = e.target.closest && e.target.closest('#lrob-etk-bulk-apply');
        if (bulkApply) {
            e.preventDefault();
            var actionSel = document.getElementById('lrob-etk-bulk-select');
            var action = actionSel ? actionSel.value : '';
            if (!action) { flash(L.i18n.pickAction, 'error'); return; }
            var ids = $$('.lrob-etk-row-check:checked').map(function (cb) { return cb.value; });
            if (ids.length === 0) { flash(L.i18n.noSelection, 'error'); return; }
            if (action === 'delete') {
                ask({
                    title: L.i18n.detailDelete,
                    message: L.i18n.confirmBulkDelete.replace('%d', ids.length),
                    confirmLabel: L.i18n.detailDelete,
                    danger: true
                }).then(function (ok) {
                    if (!ok) return;
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
                });
            }
            return;
        }
        var del = e.target.closest && e.target.closest('.lrob-etk-row-delete');
        if (del) {
            e.preventDefault();
            var did = del.getAttribute('data-id');
            ask({
                title: L.i18n.detailDelete,
                message: L.i18n.confirmDelete,
                confirmLabel: L.i18n.detailDelete,
                danger: true
            }).then(function (ok) {
                if (!ok) return;
                ajax(L.actions.delete, { id: did }).then(function (resp) {
                    if (resp.success) {
                        var row = del.closest('tr');
                        if (row) row.parentNode.removeChild(row);
                        flash(resp.data.message, 'success');
                    } else {
                        flash((resp.data && resp.data.message) || L.i18n.unknownError, 'error');
                    }
                });
            });
            return;
        }
        var resend = e.target.closest && e.target.closest('.lrob-etk-row-resend');
        if (resend) {
            e.preventDefault();
            var rid = resend.getAttribute('data-id');
            ask({
                title: L.i18n.detailResend,
                message: L.i18n.confirmResend,
                confirmLabel: L.i18n.detailResend
            }).then(function (ok) {
                if (!ok) return;
                resendNow(resend, rid);
            });
            return;
        }
        // pre-extracted resend body, used by both row click and detail modal action
        function resendNow(resend, rid) {
            var icon = resend.querySelector('.dashicons');
            resend.disabled = true;
            if (icon) icon.classList.add('lrob-etk-spin');
            ajax(L.actions.resend, { id: rid }).then(function (resp) {
                resend.disabled = false;
                if (icon) icon.classList.remove('lrob-etk-spin');
                if (resp.success) {
                    flash(resp.data.message, 'success');
                    setTimeout(function () { window.location.reload(); }, 800);
                } else {
                    flash((resp.data && resp.data.message) || L.i18n.unknownError, 'error');
                }
            }).catch(function () {
                resend.disabled = false;
                if (icon) icon.classList.remove('lrob-etk-spin');
                flash(L.i18n.unknownError, 'error');
            });
            return;
        }
    });

    function whenReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    whenReady(function () {
    // ---- Live filter (shared etk-list-filter helper) ----
    var filterApi = null;
    if (window.lrobEtkListFilter) {
        filterApi = window.lrobEtkListFilter.attach({
            formSelector:   '[data-etk-list-form]',
            regionSelector: '[data-etk-list-region]',
            ajaxUrl:        L.ajaxUrl,
            nonce:          L.nonce,
            action:         L.actions.listFilter,
        });
    }
    if (window.lrobEtkSortable) {
        window.lrobEtkSortable.attach({
            cookieKey:      'lrob_etk_sort_logs',
            formSelector:   '[data-etk-list-form]',
            regionSelector: '[data-etk-list-region]',
            filterApi:      filterApi,
        });
    }
    if (window.lrobEtkPerPage) {
        window.lrobEtkPerPage.attach({
            slug:         'logs',
            formSelector: '[data-etk-list-form]',
            filterApi:    filterApi,
        });
    }

    // ---- Detail modal (shared etk-detail-modal helper) ----
    if (window.lrobEtkDetailModal) {
        var detailModal = window.lrobEtkDetailModal.create({
            actionsHtml: ''
                + '<button type="button" class="button lrob-etk-detail-modal-action" data-cf-detail-action="resend">'
                +   '<span class="dashicons dashicons-update" aria-hidden="true"></span>'
                +   '<span>' + L.i18n.detailResend + '</span>'
                + '</button>'
                + '<button type="button" class="button lrob-etk-detail-modal-action lrob-etk-btn--danger" data-cf-detail-action="delete">'
                +   '<span class="dashicons dashicons-trash" aria-hidden="true"></span>'
                +   '<span>' + L.i18n.detailDelete + '</span>'
                + '</button>',
            fetcher: function (id) {
                var fd = new FormData();
                fd.append('action', L.actions.detail);
                fd.append('_nonce', L.nonce);
                fd.append('id', String(id));
                return fetch(L.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (!resp || !resp.success || !resp.data) throw new Error('bad');
                        return resp.data;
                    });
            },
            onAction: function (op, id, modal) {
                if (op === 'resend') {
                    ask({
                        title: L.i18n.detailResend,
                        message: L.i18n.confirmResend,
                        confirmLabel: L.i18n.detailResend
                    }).then(function (ok) {
                        if (!ok) return;
                        ajax(L.actions.resend, { id: id }).then(function (resp) {
                            if (resp.success) {
                                flash(resp.data.message, 'success');
                                modal.refresh();
                            } else {
                                flash((resp.data && resp.data.message) || L.i18n.unknownError, 'error');
                            }
                        });
                    });
                } else if (op === 'delete') {
                    ask({
                        title: L.i18n.detailDelete,
                        message: L.i18n.confirmDelete,
                        confirmLabel: L.i18n.detailDelete,
                        danger: true
                    }).then(function (ok) {
                        if (!ok) return;
                        ajax(L.actions.delete, { id: id }).then(function (resp) {
                            if (resp.success) {
                                var row = document.querySelector('tr[data-log-id="' + id + '"]');
                                if (row) row.parentNode.removeChild(row);
                                modal.close();
                                flash(resp.data.message, 'success');
                            } else {
                                flash((resp.data && resp.data.message) || L.i18n.unknownError, 'error');
                            }
                        });
                    });
                }
            },
            getVisibleIds: function () {
                var rows = document.querySelectorAll('tr[data-log-id]');
                var ids = [];
                Array.prototype.forEach.call(rows, function (tr) {
                    var v = parseInt(tr.getAttribute('data-log-id'), 10) || 0;
                    if (v > 0) ids.push(v);
                });
                return ids;
            },
            i18n: {
                prev:    L.i18n.detailPrev,
                next:    L.i18n.detailNext,
                close:   L.i18n.detailClose,
                loading: L.i18n.detailLoading,
                error:   L.i18n.unknownError,
            },
        });

        function openDetail(id) {
            $$('tr.is-active').forEach(function (tr) { tr.classList.remove('is-active'); });
            var tr = document.querySelector('tr[data-log-id="' + id + '"]');
            if (tr) tr.classList.add('is-active');
            detailModal.open(id);
        }

        // Highlight the active row + close on outside row click.
        document.addEventListener('click', function (e) {
            var trig = e.target.closest && e.target.closest('[data-etk-open-detail]');
            if (!trig) return;
            e.preventDefault();
            var id = parseInt(trig.getAttribute('data-etk-row-id') || '0', 10);
            if (id > 0) openDetail(id);
        });

        // Direct link (?detail=N) → open the modal on load.
        if (L.autoOpenId > 0) openDetail(L.autoOpenId);
    }
    }); // whenReady

    // ---- Storage modal + autosave ----
    whenReady(function () {
        if (window.lrobEtkModal) {
            window.lrobEtkModal.bindHeader('lrob-etk-logs-storage-modal', 'lrob-etk-logs-storage-btn');
        }
        if (window.lrobEtkAutosave) {
            var storageCard = document.querySelector('.lrob-etk-logs-storage-card');
            if (storageCard) {
                window.lrobEtkAutosave.attach(storageCard, {
                    fieldSelector: '.lrob-etk-logs-field',
                    debounceMs: 0,
                    save: function (field, value) {
                        return ajax(L.actions.saveSetting, { key: field.dataset.key, value: String(value) });
                    },
                    i18n: { saving: L.i18n.saving, saved: L.i18n.saved, error: L.i18n.saveError }
                });
            }
        }
    });

    // ---- Manual cleanup (one-shot, destructive — explicit Run button) ----
    (function () {
        var btn = document.querySelector('[data-cleanup-action="older_than"]');
        if (!btn) return;
        var result = document.querySelector('.lrob-etk-cleanup-result');
        btn.addEventListener('click', function () {
            var daysEl = document.getElementById('lrob-etk-cleanup-days');
            var days = parseInt(daysEl ? daysEl.value : '30', 10);
            if (!days || days < 1) { days = 30; if (daysEl) daysEl.value = '30'; }
            ask({
                title: L.i18n.detailDelete,
                message: L.i18n.confirmCleanup.replace('%d', days),
                confirmLabel: L.i18n.detailDelete,
                danger: true
            }).then(function (ok) {
                if (!ok) return;
                btn.disabled = true;
                if (result) {
                    result.hidden = false;
                    result.className = 'lrob-etk-test-result lrob-etk-cleanup-result lrob-etk-state--off';
                    result.textContent = L.i18n.working;
                }
                ajax(L.actions.purge, { mode: 'older_than', days: days }).then(function (resp) {
                    btn.disabled = false;
                    if (resp.success) {
                        if (result) {
                            result.className = 'lrob-etk-test-result lrob-etk-cleanup-result lrob-etk-state--on';
                            result.textContent = '✓ ' + resp.data.message;
                        }
                        setTimeout(function () { window.location.reload(); }, 800);
                    } else if (result) {
                        result.className = 'lrob-etk-test-result lrob-etk-cleanup-result lrob-etk-state--fail';
                        result.textContent = '✗ ' + ((resp.data && resp.data.message) || L.i18n.unknownError);
                    }
                });
            });
        });
    })();
})();
        <?php
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
