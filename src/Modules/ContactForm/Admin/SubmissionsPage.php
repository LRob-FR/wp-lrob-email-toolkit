<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\PageHeader;
use LRob\EmailToolkit\Forms\FormStructure;
use LRob\EmailToolkit\Modules\ContactForm\CPT;
use LRob\EmailToolkit\Modules\ContactForm\Admin\EmailActions;
use LRob\EmailToolkit\Modules\ContactForm\FileRepository;
use LRob\EmailToolkit\Modules\ContactForm\Settings;
use LRob\EmailToolkit\Modules\ContactForm\Submission;
use LRob\EmailToolkit\Modules\ContactForm\SubmissionRepository;
use LRob\EmailToolkit\Modules\Logging\Admin\PageController as LogsPageController;
use LRob\EmailToolkit\Plugin;

// Docs: docs/contact-form.md — view of FormsPage (?view=submissions); detail is an in-page modal.
final class SubmissionsPage
{
    public const OPTION_PER_PAGE = 'lrob_etk_cf_submissions_per_page';

    public const DEFAULT_PER_PAGE = 20;

    public function __construct(private SubmissionRepository $repository)
    {
    }

    /** URL to this view (no params). Callers add filter/action params via add_query_arg. */
    public static function base_url(): string
    {
        return add_query_arg(
            ['page' => FormsPage::SLUG, 'view' => FormsPage::VIEW_SUBMISSIONS],
            admin_url('admin.php')
        );
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';
        if ($action === 'spam-confirm' || $action === 'delete-confirm') {
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            $submission = $this->repository->find($id);
            if ($submission === null) {
                $this->render_not_found();
                return;
            }
            $this->render_confirm($submission, $action === 'delete-confirm' ? 'delete' : 'spam');
            return;
        }
        $this->render_list();
    }

    private function render_list(): void
    {
        $filters = self::parse_filters();
        // Inline session-cookie picker (Admin\PerPagePicker) replaces the
        // former site-level OPTION_PER_PAGE setting.
        $per_page = \LRob\EmailToolkit\Admin\PerPagePicker::parse('cf_submissions', self::DEFAULT_PER_PAGE);
        $page = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $total = $this->repository->count($filters);
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $entries = $this->repository->paginate($filters, $page, $per_page);
        $forms = $this->all_forms();

        $notice = isset($_GET['notice']) ? sanitize_key((string) $_GET['notice']) : '';
        $notice_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        ?>
        <div class="wrap lrob-etk lrob-etk-logs-page lrob-etk-cf-submissions-page">
            <?php if ($notice === 'spam' || $notice === 'unspam' || $notice === 'deleted') : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        if ($notice === 'spam') {
                            /* translators: %d: submission id */
                            printf(esc_html__('Submission #%d marked as spam.', 'lrob-email-toolkit'), $notice_id);
                        } elseif ($notice === 'unspam') {
                            /* translators: %d: submission id */
                            printf(esc_html__('Submission #%d restored from spam.', 'lrob-email-toolkit'), $notice_id);
                        } else {
                            /* translators: %d: submission id */
                            printf(esc_html__('Submission #%d deleted permanently.', 'lrob-email-toolkit'), $notice_id);
                        }
                        ?>
                    </p>
                </div>
            <?php elseif ($notice === 'not-found') : ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php esc_html_e('That submission no longer exists. It may have been deleted already.', 'lrob-email-toolkit'); ?></p>
                </div>
            <?php endif; ?>
            <?php PageHeader::render([
                'title' => __('Contact Form Submissions', 'lrob-email-toolkit'),
                'tools' => [
                    [
                        'label' => __('Storage', 'lrob-email-toolkit'),
                        'icon'  => 'dashicons-database',
                        'id'    => 'lrob-etk-cf-storage-btn',
                    ],
                ],
                'nav'   => [
                    [
                        'label' => __('Forms', 'lrob-email-toolkit'),
                        'icon'  => 'dashicons-feedback',
                        'href'  => admin_url('admin.php?page=' . FormsPage::SLUG),
                    ],
                ],
            ]); ?>

            <?php $this->render_filter_bar($filters, $forms); ?>

            <?php $this->render_list_region($filters, $entries, $page, $total, $total_pages, $per_page, $forms); ?>

            <?php FormsPage::render_storage_modal(); ?>
        </div>
        <?php
    }


    /**
     * @param array<string, mixed>   $filters
     * @param array<int, Submission> $entries
     * @param array<int, \WP_Post>   $forms
     */
    public function render_list_region(array $filters, array $entries, int $page, int $total, int $total_pages, int $per_page, array $forms): void
    {
        ?>
        <div class="lrob-etk-list-region" data-etk-list-region>
            <?php if ($entries === [] && $total === 0) : ?>
                <?php $this->render_empty_state(!empty(array_filter($filters))); ?>
            <?php else : ?>
                <?php $this->render_summary_line($page, $total, $per_page); ?>
                <?php $this->render_table($entries, $forms); ?>
                <?php $this->render_pagination($page, $total_pages); ?>
            <?php endif; ?>
            <div class="lrob-etk-list-loading" aria-hidden="true"><span class="spinner is-active"></span></div>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed>|null $source $_GET for page render; pass $_POST from AJAX filter endpoint.
     * @return array{form_ids?:array<int,int>, statuses?:array<int,string>,
     *               captcha_outcomes?:array<int,string>, search?:string,
     *               date_from?:string, date_to?:string}
     */
    public static function parse_filters(?array $source = null): array
    {
        $src = $source ?? $_GET;
        $f = [];
        if (!empty($src['form_id']) && (int) $src['form_id'] > 0) {
            $f['form_ids'] = [(int) $src['form_id']];
        }
        if (!empty($src['status']) && is_string($src['status'])) {
            $f['statuses'] = [sanitize_key((string) $src['status'])];
        }
        if (!empty($src['captcha']) && is_string($src['captcha'])) {
            $f['captcha_outcomes'] = [sanitize_key((string) $src['captcha'])];
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
    public function render_list_region_for_filters(array $filters, int $page): void
    {
        // Inline session-cookie picker (Admin\PerPagePicker) replaces the
        // former site-level OPTION_PER_PAGE setting.
        $per_page = \LRob\EmailToolkit\Admin\PerPagePicker::parse('cf_submissions', self::DEFAULT_PER_PAGE);
        $total = $this->repository->count($filters);
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $page = max(1, $page);
        $entries = $this->repository->paginate($filters, $page, $per_page);
        $forms = $this->all_forms();
        $this->render_list_region($filters, $entries, $page, $total, $total_pages, $per_page, $forms);
    }

    public function repository(): SubmissionRepository
    {
        return $this->repository;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, \WP_Post> $forms
     */
    private function render_filter_bar(array $filters, array $forms): void
    {
        $current_form = isset($filters['form_ids']) ? (int) ($filters['form_ids'][0] ?? 0) : 0;
        $current_status = isset($filters['statuses']) ? (string) ($filters['statuses'][0] ?? '') : '';
        $current_captcha = isset($filters['captcha_outcomes']) ? (string) ($filters['captcha_outcomes'][0] ?? '') : '';
        $current_search = (string) ($filters['search'] ?? '');
        $current_from = isset($filters['date_from']) ? substr((string) $filters['date_from'], 0, 10) : '';
        $current_to = isset($filters['date_to']) ? substr((string) $filters['date_to'], 0, 10) : '';
        $has_filter = $current_form > 0 || $current_status !== '' || $current_captcha !== '' || $current_search !== '' || $current_from !== '' || $current_to !== '';
        ?>
        <form method="get" class="lrob-etk-filter-bar" data-etk-list-form>
            <input type="hidden" name="page" value="<?php echo esc_attr(FormsPage::SLUG); ?>">
            <input type="hidden" name="view" value="<?php echo esc_attr(FormsPage::VIEW_SUBMISSIONS); ?>">

            <div class="lrob-etk-filter-bar-field">
                <label for="lrob-etk-cf-filter-form"><?php esc_html_e('Form', 'lrob-email-toolkit'); ?></label>
                <select name="form_id" id="lrob-etk-cf-filter-form" class="lrob-etk-select">
                    <option value=""><?php esc_html_e('All forms', 'lrob-email-toolkit'); ?></option>
                    <?php foreach ($forms as $form) : ?>
                        <option value="<?php echo (int) $form->ID; ?>" <?php selected($current_form, $form->ID); ?>>
                            <?php echo esc_html($form->post_title !== '' ? $form->post_title : sprintf('#%d', $form->ID)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="lrob-etk-filter-bar-field">
                <label for="lrob-etk-cf-filter-status"><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></label>
                <select name="status" id="lrob-etk-cf-filter-status" class="lrob-etk-select">
                    <option value=""><?php esc_html_e('All', 'lrob-email-toolkit'); ?></option>
                    <option value="<?php echo esc_attr(SubmissionRepository::STATUS_DELIVERED); ?>" <?php selected($current_status, SubmissionRepository::STATUS_DELIVERED); ?>>
                        <?php esc_html_e('Delivered', 'lrob-email-toolkit'); ?>
                    </option>
                    <option value="<?php echo esc_attr(SubmissionRepository::STATUS_RECEIVED); ?>" <?php selected($current_status, SubmissionRepository::STATUS_RECEIVED); ?>>
                        <?php esc_html_e('Received', 'lrob-email-toolkit'); ?>
                    </option>
                    <option value="<?php echo esc_attr(SubmissionRepository::STATUS_SPAM_BLOCKED); ?>" <?php selected($current_status, SubmissionRepository::STATUS_SPAM_BLOCKED); ?>>
                        <?php esc_html_e('Spam blocked', 'lrob-email-toolkit'); ?>
                    </option>
                    <option value="<?php echo esc_attr(SubmissionRepository::STATUS_FAILED); ?>" <?php selected($current_status, SubmissionRepository::STATUS_FAILED); ?>>
                        <?php esc_html_e('Failed', 'lrob-email-toolkit'); ?>
                    </option>
                </select>
            </div>

            <div class="lrob-etk-filter-bar-field">
                <label for="lrob-etk-cf-filter-captcha"><?php esc_html_e('Captcha', 'lrob-email-toolkit'); ?></label>
                <select name="captcha" id="lrob-etk-cf-filter-captcha" class="lrob-etk-select">
                    <option value=""><?php esc_html_e('Any', 'lrob-email-toolkit'); ?></option>
                    <option value="<?php echo esc_attr(SubmissionRepository::CAPTCHA_OUTCOME_PASSED); ?>" <?php selected($current_captcha, SubmissionRepository::CAPTCHA_OUTCOME_PASSED); ?>>
                        <?php esc_html_e('Passed', 'lrob-email-toolkit'); ?>
                    </option>
                    <option value="<?php echo esc_attr(SubmissionRepository::CAPTCHA_OUTCOME_FAILED); ?>" <?php selected($current_captcha, SubmissionRepository::CAPTCHA_OUTCOME_FAILED); ?>>
                        <?php esc_html_e('Failed', 'lrob-email-toolkit'); ?>
                    </option>
                    <option value="<?php echo esc_attr(SubmissionRepository::CAPTCHA_OUTCOME_SKIPPED); ?>" <?php selected($current_captcha, SubmissionRepository::CAPTCHA_OUTCOME_SKIPPED); ?>>
                        <?php esc_html_e('Skipped', 'lrob-email-toolkit'); ?>
                    </option>
                </select>
            </div>

            <div class="lrob-etk-filter-bar-field">
                <label for="lrob-etk-cf-filter-from"><?php esc_html_e('From', 'lrob-email-toolkit'); ?></label>
                <input type="date" id="lrob-etk-cf-filter-from" name="date_from" value="<?php echo esc_attr($current_from); ?>">
            </div>

            <div class="lrob-etk-filter-bar-field">
                <label for="lrob-etk-cf-filter-to"><?php esc_html_e('To', 'lrob-email-toolkit'); ?></label>
                <input type="date" id="lrob-etk-cf-filter-to" name="date_to" value="<?php echo esc_attr($current_to); ?>">
            </div>

            <div class="lrob-etk-filter-bar-field lrob-etk-filter-bar-field--search">
                <label for="lrob-etk-cf-filter-search"><?php esc_html_e('Search', 'lrob-email-toolkit'); ?></label>
                <input type="search" id="lrob-etk-cf-filter-search" name="s" value="<?php echo esc_attr($current_search); ?>"
                       placeholder="<?php esc_attr_e('Field values, notes…', 'lrob-email-toolkit'); ?>">
            </div>

            <div class="lrob-etk-filter-bar-actions">
                <noscript>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'lrob-email-toolkit'); ?></button>
                </noscript>
                <a href="<?php echo esc_url(self::base_url()); ?>" class="button button-link lrob-etk-cf-reset-filters"<?php echo $has_filter ? '' : ' hidden'; ?>>
                    <?php esc_html_e('Reset', 'lrob-email-toolkit'); ?>
                </a>
            </div>
        </form>
        <?php
    }

    private function render_summary_line(int $page, int $total, int $per_page): void
    {
        $first = ($page - 1) * $per_page + 1;
        $last = min($total, $page * $per_page);
        ?>
        <div class="lrob-etk-bulk-toolbar">
            <div class="lrob-etk-bulk-actions" data-cf-bulk-actions>
                <select class="lrob-etk-select" data-cf-bulk-op>
                    <option value=""><?php esc_html_e('Bulk actions', 'lrob-email-toolkit'); ?></option>
                    <option value="spam"><?php esc_html_e('Mark as spam', 'lrob-email-toolkit'); ?></option>
                    <option value="unspam"><?php esc_html_e('Restore from spam', 'lrob-email-toolkit'); ?></option>
                    <option value="delete"><?php esc_html_e('Delete permanently', 'lrob-email-toolkit'); ?></option>
                </select>
                <button type="button" class="button" data-cf-bulk-apply disabled>
                    <?php esc_html_e('Apply', 'lrob-email-toolkit'); ?>
                </button>
                <span class="lrob-etk-bulk-selected-count" data-cf-bulk-count hidden></span>
            </div>
            <div class="lrob-etk-bulk-action">
                <span class="lrob-etk-bulk-count">
                    <?php
                    printf(
                        /* translators: 1: first index, 2: last index, 3: total count */
                        esc_html__('Showing %1$d–%2$d of %3$d', 'lrob-email-toolkit'),
                        $first,
                        $last,
                        $total
                    );
                    ?>
                </span>
                <?php \LRob\EmailToolkit\Admin\PerPagePicker::render('cf_submissions', $per_page); ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<int, Submission> $entries
     * @param array<int, \WP_Post> $forms
     */
    private function render_table(array $entries, array $forms): void
    {
        $form_titles = [];
        foreach ($forms as $f) {
            $form_titles[(int) $f->ID] = $f->post_title;
        }
        ?>
        <div class="lrob-etk-data-table-wrap">
            <table class="lrob-etk-data-table lrob-etk-cf-submissions-table">
                <thead>
                    <tr>
                        <th class="col-check">
                            <label class="screen-reader-text" for="lrob-etk-cf-check-all"><?php esc_html_e('Select all', 'lrob-email-toolkit'); ?></label>
                            <input type="checkbox" id="lrob-etk-cf-check-all" data-cf-bulk-check-all>
                        </th>
                        <th class="col-date" data-sort-key="submitted_at"><?php esc_html_e('Date', 'lrob-email-toolkit'); ?></th>
                        <th class="col-status" data-sort-key="status"><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                        <th class="col-form" data-sort-key="form_id"><?php esc_html_e('Form', 'lrob-email-toolkit'); ?></th>
                        <th class="col-preview"><?php esc_html_e('Preview', 'lrob-email-toolkit'); ?></th>
                        <th class="col-captcha" data-sort-key="captcha_outcome"><?php esc_html_e('Captcha', 'lrob-email-toolkit'); ?></th>
                        <th class="col-actions"><?php esc_html_e('Actions', 'lrob-email-toolkit'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry) : ?>
                        <?php $this->render_table_row($entry, $form_titles); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** @param array<int, string> $form_titles */
    private function render_table_row(Submission $entry, array $form_titles): void
    {
        $view_url = add_query_arg(
            ['page' => FormsPage::SLUG, 'view' => FormsPage::VIEW_SUBMISSIONS, 'detail' => $entry->id],
            admin_url('admin.php')
        );
        $form_title = $form_titles[$entry->form_id] ?? '';
        $form_label = $form_title !== ''
            ? $form_title
            : sprintf(
                /* translators: %d: form id */
                __('Deleted form #%d', 'lrob-email-toolkit'),
                $entry->form_id
            );
        $is_orphan = $form_title === '';
        $preview = $this->preview_text($entry);
        ?>
        <tr data-submission-id="<?php echo (int) $entry->id; ?>">
            <td class="col-check">
                <label class="screen-reader-text" for="lrob-etk-cf-check-<?php echo (int) $entry->id; ?>">
                    <?php
                    /* translators: %d: submission id */
                    printf(esc_html__('Select submission #%d', 'lrob-email-toolkit'), (int) $entry->id);
                    ?>
                </label>
                <input type="checkbox" id="lrob-etk-cf-check-<?php echo (int) $entry->id; ?>"
                       data-cf-bulk-check value="<?php echo (int) $entry->id; ?>">
            </td>
            <td class="col-date">
                <?php echo esc_html($entry->submitted_at->setTimezone(wp_timezone())->format('Y-m-d H:i:s')); ?>
            </td>
            <td class="col-status">
                <span class="lrob-etk-status <?php echo esc_attr($this->status_class($entry->status)); ?>">
                    <?php echo esc_html($this->status_label($entry->status, $entry->notes)); ?>
                </span>
            </td>
            <td class="col-form">
                <?php if ($is_orphan) : ?>
                    <span class="lrob-etk-form-deleted"><?php echo esc_html($form_label); ?></span>
                <?php else : ?>
                    <?php echo esc_html($form_label); ?>
                <?php endif; ?>
            </td>
            <td class="col-preview">
                <a href="<?php echo esc_url($view_url); ?>" class="lrob-etk-subject-link" data-cf-open-detail data-cf-row-id="<?php echo (int) $entry->id; ?>">
                    <?php echo esc_html($preview !== '' ? $preview : __('(no content)', 'lrob-email-toolkit')); ?>
                </a>
            </td>
            <td class="col-captcha">
                <?php echo esc_html($this->captcha_summary($entry)); ?>
            </td>
            <td class="col-actions">
                <a href="<?php echo esc_url($view_url); ?>" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost" data-cf-open-detail data-cf-row-id="<?php echo (int) $entry->id; ?>" title="<?php esc_attr_e('View', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('View submission', 'lrob-email-toolkit'); ?>">
                    <span class="dashicons dashicons-visibility"></span>
                </a>
                <?php if ($entry->log_id !== null) : ?>
                    <?php
                    $log_url = add_query_arg(
                        ['page' => LogsPageController::SLUG, 'detail' => $entry->log_id],
                        admin_url('admin.php')
                    );
                    ?>
                    <a href="<?php echo esc_url($log_url); ?>" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost" title="<?php esc_attr_e('View outbound email', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('View outbound email log', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-email-alt"></span>
                    </a>
                <?php endif; ?>
                <?php if ($entry->status === SubmissionRepository::STATUS_SPAM_BLOCKED) : ?>
                    <button type="button" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost" data-cf-row-action="unspam" data-cf-row-id="<?php echo (int) $entry->id; ?>" title="<?php esc_attr_e('Restore from spam', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Restore from spam', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-undo"></span>
                    </button>
                <?php else : ?>
                    <button type="button" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-icon-btn--spam" data-cf-row-action="spam" data-cf-row-id="<?php echo (int) $entry->id; ?>" title="<?php esc_attr_e('Mark as spam', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Mark as spam', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-flag"></span>
                    </button>
                <?php endif; ?>
                <button type="button" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-icon-btn--danger" data-cf-row-action="delete" data-cf-row-id="<?php echo (int) $entry->id; ?>" title="<?php esc_attr_e('Delete permanently', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('Delete submission', 'lrob-email-toolkit'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </td>
        </tr>
        <?php
    }

    private function render_pagination(int $page, int $total_pages): void
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
        <nav class="lrob-etk-pagination" aria-label="<?php esc_attr_e('Submissions pagination', 'lrob-email-toolkit'); ?>">
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
                <p><?php esc_html_e('No submissions match your filters.', 'lrob-email-toolkit'); ?></p>
                <p>
                    <a href="<?php echo esc_url(self::base_url()); ?>" class="button">
                        <?php esc_html_e('Clear filters', 'lrob-email-toolkit'); ?>
                    </a>
                </p>
            <?php else : ?>
                <p><?php esc_html_e('No submissions yet. Once visitors send a contact form, they will appear here.', 'lrob-email-toolkit'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_not_found(): void
    {
        ?>
        <div class="wrap lrob-etk lrob-etk-cf-submissions-page">
            <?php PageHeader::render([
                'title' => __('Submission not found', 'lrob-email-toolkit'),
                'nav'   => [[
                    'label' => __('Back to submissions', 'lrob-email-toolkit'),
                    'icon'  => 'dashicons-arrow-left-alt',
                    'href'  => self::base_url(),
                ]],
            ]); ?>
        </div>
        <?php
    }

    public function render_detail_body(Submission $entry): void
    {
        $form = get_post($entry->form_id);
        $form_title = $form instanceof \WP_Post && $form->post_type === CPT::POST_TYPE
            ? $form->post_title
            : sprintf(
                /* translators: %d: form id */
                __('Deleted form #%d', 'lrob-email-toolkit'),
                $entry->form_id
            );
        $index = [];
        if ($form instanceof \WP_Post) {
            $index = FormStructure::fields_index(FormStructure::load($entry->form_id));
        }
        $log_url = null;
        if ($entry->log_id !== null) {
            $log_url = add_query_arg(
                ['page' => LogsPageController::SLUG, 'detail' => $entry->log_id],
                admin_url('admin.php')
            );
        }
        ?>
        <div class="lrob-etk-detail-strip">
            <div class="lrob-etk-detail-strip-item">
                <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Submitted', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-detail-strip-value">
                    <?php echo esc_html($entry->submitted_at->setTimezone(wp_timezone())->format('Y-m-d H:i:s')); ?>
                </span>
            </div>
            <div class="lrob-etk-detail-strip-item">
                <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-status <?php echo esc_attr($this->status_class($entry->status)); ?>">
                    <?php echo esc_html($this->status_label($entry->status, $entry->notes)); ?>
                </span>
            </div>
            <div class="lrob-etk-detail-strip-item">
                <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Captcha', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-detail-strip-value">
                    <?php echo esc_html($this->captcha_summary($entry)); ?>
                </span>
            </div>
            <?php if ($entry->notes !== null && $entry->notes !== '' && !$this->is_known_notes_code($entry->notes)) : ?>
                <div class="lrob-etk-detail-strip-item">
                    <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Notes', 'lrob-email-toolkit'); ?></span>
                    <span class="lrob-etk-detail-strip-value"><?php echo esc_html($entry->notes); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <section class="lrob-etk-detail-card lrob-etk-detail-card--payload">
            <h2><?php esc_html_e('Submitted values', 'lrob-email-toolkit'); ?></h2>
            <?php if ($entry->fields === []) : ?>
                <p class="lrob-etk-empty"><?php esc_html_e('No fields recorded for this submission.', 'lrob-email-toolkit'); ?></p>
            <?php else : ?>
                <dl class="lrob-etk-detail-payload">
                    <?php foreach ($entry->fields as $slug => $value) :
                        [$label, $type] = $this->field_label_and_type((string) $slug, $index);
                        ?>
                        <div class="lrob-etk-detail-row">
                            <dt><?php echo esc_html($label); ?></dt>
                            <dd><?php echo $this->render_field_value($value, $type); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </section>

        <?php $this->render_attached_files($entry, $index); ?>

        <details class="lrob-etk-detail-tech">
            <summary>
                <span class="lrob-etk-detail-tech-caret" aria-hidden="true">▸</span>
                <span><?php esc_html_e('Technical details', 'lrob-email-toolkit'); ?></span>
            </summary>
            <dl class="lrob-etk-detail-meta">
                <?php if ($entry->ip_address !== null) : ?>
                    <dt><?php esc_html_e('IP address', 'lrob-email-toolkit'); ?></dt>
                    <dd><code><?php echo esc_html($entry->ip_address); ?></code></dd>
                <?php elseif ($entry->ip_hash !== '') : ?>
                    <dt><?php esc_html_e('IP hash', 'lrob-email-toolkit'); ?></dt>
                    <dd>
                        <code><?php echo esc_html(substr($entry->ip_hash, 0, 16)); ?>…</code>
                        <span class="description"><?php esc_html_e('(raw IP not stored — enable in settings if needed)', 'lrob-email-toolkit'); ?></span>
                    </dd>
                <?php endif; ?>

                <?php if ($entry->user_agent !== '') : ?>
                    <dt><?php esc_html_e('User agent', 'lrob-email-toolkit'); ?></dt>
                    <dd><code class="lrob-etk-ua-code"><?php echo esc_html($entry->user_agent); ?></code></dd>
                <?php endif; ?>

                <?php if ($entry->referer !== '') : ?>
                    <dt><?php esc_html_e('Referer', 'lrob-email-toolkit'); ?></dt>
                    <dd><a href="<?php echo esc_url($entry->referer); ?>" rel="noopener noreferrer" target="_blank"><?php echo esc_html($entry->referer); ?></a></dd>
                <?php endif; ?>

                <dt><?php esc_html_e('Form', 'lrob-email-toolkit'); ?></dt>
                <dd><?php echo esc_html($form_title); ?> (#<?php echo (int) $entry->form_id; ?>)</dd>

                <?php if ($log_url !== null) : ?>
                    <dt><?php esc_html_e('Outbound email', 'lrob-email-toolkit'); ?></dt>
                    <dd>
                        <a href="<?php echo esc_url($log_url); ?>">
                            <?php
                            /* translators: %d: log entry id */
                            printf(esc_html__('Log entry #%d', 'lrob-email-toolkit'), (int) $entry->log_id);
                            ?>
                        </a>
                    </dd>
                <?php endif; ?>
            </dl>
        </details>
        <?php
    }

    /** Modal header title for a submission — "Form name #N". */
    public function detail_title(Submission $entry): string
    {
        $form = get_post($entry->form_id);
        $form_title = $form instanceof \WP_Post && $form->post_type === CPT::POST_TYPE && $form->post_title !== ''
            ? $form->post_title
            : sprintf(
                /* translators: %d: form id */
                __('Deleted form #%d', 'lrob-email-toolkit'),
                $entry->form_id
            );
        return $form_title . ' #' . (int) $entry->id;
    }

    // $op: 'spam' | 'delete'
    private function render_confirm(Submission $entry, string $op): void
    {
        $is_delete = $op === 'delete';
        $action = $is_delete ? EmailActions::ACTION_DELETE : EmailActions::ACTION_SPAM;
        $title = $is_delete
            ? __('Delete this submission?', 'lrob-email-toolkit')
            : __('Mark this submission as spam?', 'lrob-email-toolkit');
        $confirm_label = $is_delete
            ? __('Yes, delete permanently', 'lrob-email-toolkit')
            : __('Yes, mark as spam', 'lrob-email-toolkit');
        $back_url = self::base_url();

        $form = get_post($entry->form_id);
        $form_title = $form instanceof \WP_Post && $form->post_type === CPT::POST_TYPE
            ? $form->post_title
            : sprintf(
                /* translators: %d: form id */
                __('Deleted form #%d', 'lrob-email-toolkit'),
                $entry->form_id
            );
        $preview = $this->compose_field_preview($entry);
        ?>
        <div class="wrap lrob-etk lrob-etk-cf-submissions-page lrob-etk-confirm-page">
            <?php PageHeader::render(['title' => $title]); ?>
            <div class="lrob-etk-confirm-card">
                <p class="lrob-etk-confirm-summary">
                    <?php
                    printf(
                        /* translators: 1: form title, 2: submission id, 3: submitted-at datetime */
                        esc_html__('Submission to "%1$s" — #%2$d, received %3$s.', 'lrob-email-toolkit'),
                        esc_html($form_title),
                        (int) $entry->id,
                        esc_html($entry->submitted_at->setTimezone(wp_timezone())->format('Y-m-d H:i'))
                    );
                    ?>
                </p>
                <?php if ($preview !== '') : ?>
                    <blockquote class="lrob-etk-confirm-preview"><?php echo esc_html($preview); ?></blockquote>
                <?php endif; ?>

                <?php if ($is_delete) : ?>
                    <p class="lrob-etk-confirm-warn">
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <strong><?php esc_html_e('This is irreversible.', 'lrob-email-toolkit'); ?></strong>
                        <?php esc_html_e('Field data and any attached files will be permanently removed.', 'lrob-email-toolkit'); ?>
                    </p>
                <?php else : ?>
                    <p class="lrob-etk-confirm-info">
                        <?php esc_html_e('Marking as spam keeps the row in the inbox under the Spam filter. You can move it back at any time.', 'lrob-email-toolkit'); ?>
                    </p>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lrob-etk-confirm-form">
                    <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $entry->id; ?>">
                    <?php wp_nonce_field($action . '_' . $entry->id); ?>
                    <div class="lrob-etk-confirm-actions">
                        <a href="<?php echo esc_url($back_url); ?>" class="button"><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></a>
                        <button type="submit" class="button button-primary <?php echo $is_delete ? 'lrob-etk-btn--danger-solid' : 'lrob-etk-btn--warn-solid'; ?>">
                            <?php echo esc_html($confirm_label); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /** Truncate the most useful field's value into a one-line preview. */
    private function compose_field_preview(Submission $entry): string
    {
        foreach ($entry->fields as $value) {
            if (is_string($value) && trim($value) !== '') {
                $s = trim($value);
                if (mb_strlen($s) > 160) {
                    $s = mb_substr($s, 0, 157) . '…';
                }
                return $s;
            }
        }
        return '';
    }

    // All file URLs go through the gated REST endpoint — never the storage path.
    /** @param array<string, array{label:string, type:string}> $index */
    private function render_attached_files(Submission $entry, array $index): void
    {
        $repo = $this->file_repository();
        if ($repo === null) {
            return;
        }
        $files = $repo->find_by_submission($entry->id);
        if ($files === []) {
            return;
        }

        // Group by field slug for label clarity.
        $by_slug = [];
        foreach ($files as $row) {
            $slug = (string) ($row['field_slug'] ?? '');
            $by_slug[$slug][] = $row;
        }
        ?>
        <section class="lrob-etk-detail-card lrob-etk-detail-card--files">
            <h2><?php esc_html_e('Attached files', 'lrob-email-toolkit'); ?></h2>
            <p class="lrob-etk-files-warning">
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <?php esc_html_e('Files come from visitor submissions. Open with care — no malware scan is performed.', 'lrob-email-toolkit'); ?>
            </p>
            <?php foreach ($by_slug as $slug => $group) :
                [$label, ] = $this->field_label_and_type((string) $slug, $index);
                ?>
                <div class="lrob-etk-files-group">
                    <h3 class="lrob-etk-files-group-label"><?php echo esc_html($label); ?></h3>
                    <div class="lrob-etk-files-list">
                        <?php foreach ($group as $file) :
                            $this->render_attached_file($file);
                        endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
        <?php
    }

    /** @param array<string, mixed> $file */
    private function render_attached_file(array $file): void
    {
        $file_id = (int) ($file['id'] ?? 0);
        if ($file_id <= 0) {
            return;
        }
        $name = (string) ($file['original_name'] ?? '');
        $mime = (string) ($file['mime'] ?? '');
        $size = (int) ($file['size_bytes'] ?? 0);
        // wp_rest nonce required for cookie-authenticated REST requests; fresh nonce injected at render.
        $base_url = add_query_arg(
            '_wpnonce',
            wp_create_nonce('wp_rest'),
            rest_url('lrob-etk/v1/cf/file/' . $file_id)
        );
        $download_url = $base_url; // Content-Disposition is decided server-side.
        ?>
        <div class="lrob-etk-file-card" id="file-<?php echo (int) $file_id; ?>">
            <?php if (str_starts_with($mime, 'image/')) : ?>
                <a class="lrob-etk-file-thumb" href="<?php echo esc_url($download_url); ?>" target="_blank" rel="noopener">
                    <img src="<?php echo esc_url(add_query_arg(['w' => 320, 'h' => 320], $base_url)); ?>" alt="<?php echo esc_attr($name); ?>" loading="lazy">
                </a>
            <?php elseif ($mime === 'application/pdf') : ?>
                <div class="lrob-etk-file-pdf">
                    <iframe src="<?php echo esc_url($base_url); ?>" title="<?php echo esc_attr($name); ?>" loading="lazy"></iframe>
                </div>
            <?php endif; ?>
            <div class="lrob-etk-file-meta">
                <a class="lrob-etk-file-name" href="<?php echo esc_url($download_url); ?>" target="_blank" rel="noopener">
                    <span class="dashicons dashicons-media-default" aria-hidden="true"></span>
                    <span><?php echo esc_html($name); ?></span>
                </a>
                <span class="lrob-etk-file-size"><?php echo esc_html(size_format($size)); ?></span>
            </div>
        </div>
        <?php
    }

    private function file_repository(): ?FileRepository
    {
        $container = Plugin::instance()->container();
        return $container->has(FileRepository::class) ? $container->get(FileRepository::class) : null;
    }

    private function field_label_and_type(string $slug, array $index): array
    {
        if (isset($index[$slug])) {
            $entry = $index[$slug];
            $label = trim($entry['label']);
            if ($label !== '') {
                return [$label, $entry['type']];
            }
        }
        return [$this->humanize_slug($slug), 'text'];
    }

    private function humanize_slug(string $slug): string
    {
        if ($slug === '') {
            return __('(unnamed field)', 'lrob-email-toolkit');
        }
        $known_prefixes = ['text', 'email', 'number', 'phone', 'date', 'textarea', 'select', 'radio', 'checkbox', 'submit', 'captcha'];
        foreach ($known_prefixes as $prefix) {
            if (str_starts_with($slug, $prefix . '_')) {
                $slug = substr($slug, strlen($prefix) + 1);
                break;
            }
        }
        $slug = preg_replace('/_\d+$/', '', $slug) ?? $slug;
        $words = str_replace('_', ' ', trim($slug));
        if ($words === '') {
            return __('(unnamed field)', 'lrob-email-toolkit');
        }
        return ucfirst($words);
    }

    private function preview_text(Submission $entry): string
    {
        if ($entry->fields === []) {
            return '';
        }
        // Prefer message-like fields first, then fall through to whatever
        // first non-empty field exists.
        $preferred = ['message', 'body', 'comment', 'content'];
        foreach ($entry->fields as $slug => $value) {
            $slug_lower = strtolower((string) $slug);
            foreach ($preferred as $candidate) {
                if (str_starts_with($slug_lower, $candidate)) {
                    $text = $this->scalarize($value);
                    if ($text !== '') {
                        return $this->truncate($text, 80);
                    }
                }
            }
        }
        foreach ($entry->fields as $value) {
            $text = $this->scalarize($value);
            if ($text !== '') {
                return $this->truncate($text, 80);
            }
        }
        return '';
    }

    private function scalarize(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $v) {
                if (is_scalar($v)) {
                    $parts[] = (string) $v;
                }
            }
            return trim(implode(', ', $parts));
        }
        return '';
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }

    private function render_field_value(mixed $value, string $type): string
    {
        if (is_array($value)) {
            $items = array_filter(array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $value), static fn ($v) => $v !== '');
            if ($items === []) {
                return '<em>' . esc_html__('(empty)', 'lrob-email-toolkit') . '</em>';
            }
            $out = '<ul class="lrob-etk-detail-list">';
            foreach ($items as $item) {
                $out .= '<li>' . esc_html($item) . '</li>';
            }
            $out .= '</ul>';
            return $out;
        }
        $text = is_scalar($value) ? (string) $value : '';
        if ($text === '') {
            return '<em>' . esc_html__('(empty)', 'lrob-email-toolkit') . '</em>';
        }
        if ($type === 'textarea') {
            return '<pre class="lrob-etk-detail-textarea">' . esc_html($text) . '</pre>';
        }
        if ($type === 'email' && is_email($text)) {
            return '<a href="' . esc_url('mailto:' . $text) . '">' . esc_html($text) . '</a>';
        }
        return esc_html($text);
    }

    private function status_class(string $status): string
    {
        return match ($status) {
            SubmissionRepository::STATUS_DELIVERED    => 'lrob-etk-status--on',
            SubmissionRepository::STATUS_RECEIVED     => 'lrob-etk-status--pending',
            SubmissionRepository::STATUS_FAILED       => 'lrob-etk-status--fail',
            SubmissionRepository::STATUS_SPAM_BLOCKED => 'lrob-etk-status--off',
            default                                   => 'lrob-etk-status--off',
        };
    }

    private function status_label(string $status, ?string $notes): string
    {
        if ($status === SubmissionRepository::STATUS_SPAM_BLOCKED) {
            if ($notes === 'honeypot_tripped') {
                return __('Blocked (honeypot)', 'lrob-email-toolkit');
            }
            if ($notes === 'time_trap') {
                return __('Blocked (time-trap)', 'lrob-email-toolkit');
            }
            if ($notes === 'captcha_failed') {
                return __('Blocked (captcha)', 'lrob-email-toolkit');
            }
            return __('Blocked', 'lrob-email-toolkit');
        }
        return match ($status) {
            SubmissionRepository::STATUS_DELIVERED => __('Delivered', 'lrob-email-toolkit'),
            SubmissionRepository::STATUS_RECEIVED  => __('Received', 'lrob-email-toolkit'),
            SubmissionRepository::STATUS_FAILED    => __('Failed', 'lrob-email-toolkit'),
            default                                => $status,
        };
    }

    private function is_known_notes_code(string $notes): bool
    {
        return in_array($notes, ['honeypot_tripped', 'time_trap', 'captcha_failed'], true);
    }

    private function captcha_summary(Submission $entry): string
    {
        if ($entry->captcha_outcome === '' || $entry->captcha_outcome === SubmissionRepository::CAPTCHA_OUTCOME_SKIPPED) {
            return '—';
        }
        $route = $entry->captcha_slug !== '' ? $entry->captcha_slug : '?';
        $outcome = match ($entry->captcha_outcome) {
            SubmissionRepository::CAPTCHA_OUTCOME_PASSED => __('passed', 'lrob-email-toolkit'),
            SubmissionRepository::CAPTCHA_OUTCOME_FAILED => __('failed', 'lrob-email-toolkit'),
            default                                       => $entry->captcha_outcome,
        };
        return sprintf('%s · %s', $route, $outcome);
    }

    /** @return array<int, \WP_Post> */
    private function all_forms(): array
    {
        $posts = get_posts([
            'post_type'        => CPT::POST_TYPE,
            'post_status'      => ['publish', 'draft', 'private'],
            'numberposts'      => -1,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);
        return is_array($posts) ? $posts : [];
    }
}
