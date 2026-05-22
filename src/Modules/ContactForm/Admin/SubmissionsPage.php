<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Forms\FormStructure;
use LRob\EmailToolkit\Modules\ContactForm\CPT;
use LRob\EmailToolkit\Modules\ContactForm\Submission;
use LRob\EmailToolkit\Modules\ContactForm\SubmissionRepository;
use LRob\EmailToolkit\Modules\Logging\Admin\PageController as LogsPageController;

/**
 * Inbox for every contact-form submission (received, delivered, spam-blocked,
 * failed). Lives as a view of FormsPage (?page=lrob-etk-cform&view=submissions);
 * not its own admin slug.
 *
 *   - List + filter + paginate (default)
 *   - Detail (&action=view&id=<n>)  — read-only in this slice; reply
 *     composer ships in slice 2.
 *
 * Filter shape and table chrome mirror `LogsPage` so the two pages feel
 * uniform. Cross-links: each row links to its outbound email log when
 * `log_id` is set; the detail view exposes the reverse link too.
 */
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
        if ($action === 'view') {
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            $submission = $this->repository->find($id);
            if ($submission === null) {
                $this->render_not_found();
                return;
            }
            $this->render_detail($submission);
            return;
        }
        $this->render_list();
    }

    private function render_list(): void
    {
        $filters = $this->parse_filters();
        $per_page = (int) get_option(self::OPTION_PER_PAGE, self::DEFAULT_PER_PAGE);
        $per_page = max(5, min(500, $per_page));
        $page = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $total = $this->repository->count($filters);
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $entries = $this->repository->paginate($filters, $page, $per_page);
        $forms = $this->all_forms();

        ?>
        <div class="wrap lrob-etk lrob-etk-logs-page lrob-etk-cf-submissions-page">
            <header class="lrob-etk-page-header">
                <h1 class="lrob-etk-page-title"><?php esc_html_e('Contact Form Submissions', 'lrob-email-toolkit'); ?></h1>
            </header>

            <?php $this->render_filter_bar($filters, $forms); ?>

            <?php if ($entries === [] && $total === 0) : ?>
                <?php $this->render_empty_state(!empty(array_filter($filters))); ?>
            <?php else : ?>
                <?php $this->render_summary_line($page, $total, $per_page); ?>
                <?php $this->render_table($entries, $forms); ?>
                <?php $this->render_pagination($page, $total_pages); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @return array{form_ids?:array<int,int>, statuses?:array<int,string>,
     *               captcha_outcomes?:array<int,string>, search?:string,
     *               date_from?:string, date_to?:string}
     */
    private function parse_filters(): array
    {
        $f = [];
        if (!empty($_GET['form_id']) && (int) $_GET['form_id'] > 0) {
            $f['form_ids'] = [(int) $_GET['form_id']];
        }
        if (!empty($_GET['status']) && is_string($_GET['status'])) {
            $f['statuses'] = [sanitize_key((string) $_GET['status'])];
        }
        if (!empty($_GET['captcha']) && is_string($_GET['captcha'])) {
            $f['captcha_outcomes'] = [sanitize_key((string) $_GET['captcha'])];
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
        return $f;
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
        <form method="get" class="lrob-etk-logs-filter">
            <input type="hidden" name="page" value="<?php echo esc_attr(FormsPage::SLUG); ?>">
            <input type="hidden" name="view" value="<?php echo esc_attr(FormsPage::VIEW_SUBMISSIONS); ?>">

            <div class="lrob-etk-logs-filter-field">
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

            <div class="lrob-etk-logs-filter-field">
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

            <div class="lrob-etk-logs-filter-field">
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

            <div class="lrob-etk-logs-filter-field">
                <label for="lrob-etk-cf-filter-from"><?php esc_html_e('From', 'lrob-email-toolkit'); ?></label>
                <input type="date" id="lrob-etk-cf-filter-from" name="date_from" value="<?php echo esc_attr($current_from); ?>">
            </div>

            <div class="lrob-etk-logs-filter-field">
                <label for="lrob-etk-cf-filter-to"><?php esc_html_e('To', 'lrob-email-toolkit'); ?></label>
                <input type="date" id="lrob-etk-cf-filter-to" name="date_to" value="<?php echo esc_attr($current_to); ?>">
            </div>

            <div class="lrob-etk-logs-filter-field lrob-etk-logs-filter-search">
                <label for="lrob-etk-cf-filter-search"><?php esc_html_e('Search', 'lrob-email-toolkit'); ?></label>
                <input type="search" id="lrob-etk-cf-filter-search" name="s" value="<?php echo esc_attr($current_search); ?>"
                       placeholder="<?php esc_attr_e('Field values, notes…', 'lrob-email-toolkit'); ?>">
            </div>

            <div class="lrob-etk-logs-filter-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'lrob-email-toolkit'); ?></button>
                <?php if ($has_filter) : ?>
                    <a href="<?php echo esc_url(self::base_url()); ?>" class="button button-link">
                        <?php esc_html_e('Reset', 'lrob-email-toolkit'); ?>
                    </a>
                <?php endif; ?>
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
            <div class="lrob-etk-bulk-selection">
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
        <div class="lrob-etk-logs-table-wrap">
            <table class="lrob-etk-logs-table lrob-etk-cf-submissions-table">
                <thead>
                    <tr>
                        <th class="col-date"><?php esc_html_e('Date', 'lrob-email-toolkit'); ?></th>
                        <th class="col-status"><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                        <th class="col-form"><?php esc_html_e('Form', 'lrob-email-toolkit'); ?></th>
                        <th class="col-preview"><?php esc_html_e('Preview', 'lrob-email-toolkit'); ?></th>
                        <th class="col-captcha"><?php esc_html_e('Captcha', 'lrob-email-toolkit'); ?></th>
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
            ['page' => FormsPage::SLUG, 'view' => FormsPage::VIEW_SUBMISSIONS, 'action' => 'view', 'id' => $entry->id],
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
                    <span class="lrob-etk-cf-form-deleted"><?php echo esc_html($form_label); ?></span>
                <?php else : ?>
                    <?php echo esc_html($form_label); ?>
                <?php endif; ?>
            </td>
            <td class="col-preview">
                <a href="<?php echo esc_url($view_url); ?>" class="lrob-etk-subject-link">
                    <?php echo esc_html($preview !== '' ? $preview : __('(no content)', 'lrob-email-toolkit')); ?>
                </a>
            </td>
            <td class="col-captcha">
                <?php echo esc_html($this->captcha_summary($entry)); ?>
            </td>
            <td class="col-actions">
                <a href="<?php echo esc_url($view_url); ?>" class="lrob-etk-row-action" title="<?php esc_attr_e('View', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('View submission', 'lrob-email-toolkit'); ?>">
                    <span class="dashicons dashicons-visibility"></span>
                </a>
                <?php if ($entry->log_id !== null) : ?>
                    <?php
                    $log_url = add_query_arg(
                        ['page' => LogsPageController::SLUG, 'action' => 'view', 'id' => $entry->log_id],
                        admin_url('admin.php')
                    );
                    ?>
                    <a href="<?php echo esc_url($log_url); ?>" class="lrob-etk-row-action" title="<?php esc_attr_e('View outbound email', 'lrob-email-toolkit'); ?>" aria-label="<?php esc_attr_e('View outbound email log', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-email-alt"></span>
                    </a>
                <?php endif; ?>
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
            <header class="lrob-etk-page-header">
                <h1 class="lrob-etk-page-title"><?php esc_html_e('Submission not found', 'lrob-email-toolkit'); ?></h1>
            </header>
            <p>
                <a href="<?php echo esc_url(self::base_url()); ?>" class="button">
                    <?php esc_html_e('Back to submissions', 'lrob-email-toolkit'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private function render_detail(Submission $entry): void
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
        $back_url = wp_get_referer();
        if (!is_string($back_url) || $back_url === '') {
            $back_url = self::base_url();
        }
        $log_url = null;
        if ($entry->log_id !== null) {
            $log_url = add_query_arg(
                ['page' => LogsPageController::SLUG, 'action' => 'view', 'id' => $entry->log_id],
                admin_url('admin.php')
            );
        }
        ?>
        <div class="wrap lrob-etk lrob-etk-cf-submissions-page lrob-etk-cf-submission-detail">
            <header class="lrob-etk-page-header">
                <h1 class="lrob-etk-page-title">
                    <?php echo esc_html($form_title); ?>
                    <span class="lrob-etk-page-subtitle">#<?php echo (int) $entry->id; ?></span>
                </h1>
                <div class="lrob-etk-page-header-actions">
                    <a href="<?php echo esc_url($back_url); ?>" class="button">
                        <span class="dashicons dashicons-arrow-left-alt"></span>
                        <?php esc_html_e('Back', 'lrob-email-toolkit'); ?>
                    </a>
                    <?php if ($log_url !== null) : ?>
                        <a href="<?php echo esc_url($log_url); ?>" class="button">
                            <span class="dashicons dashicons-email-alt"></span>
                            <?php esc_html_e('View outbound email', 'lrob-email-toolkit'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </header>

            <div class="lrob-etk-cf-detail-strip">
                <div class="lrob-etk-cf-detail-strip-item">
                    <span class="lrob-etk-cf-detail-strip-label"><?php esc_html_e('Submitted', 'lrob-email-toolkit'); ?></span>
                    <span class="lrob-etk-cf-detail-strip-value">
                        <?php echo esc_html($entry->submitted_at->setTimezone(wp_timezone())->format('Y-m-d H:i:s')); ?>
                    </span>
                </div>
                <div class="lrob-etk-cf-detail-strip-item">
                    <span class="lrob-etk-cf-detail-strip-label"><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></span>
                    <span class="lrob-etk-status <?php echo esc_attr($this->status_class($entry->status)); ?>">
                        <?php echo esc_html($this->status_label($entry->status, $entry->notes)); ?>
                    </span>
                </div>
                <div class="lrob-etk-cf-detail-strip-item">
                    <span class="lrob-etk-cf-detail-strip-label"><?php esc_html_e('Captcha', 'lrob-email-toolkit'); ?></span>
                    <span class="lrob-etk-cf-detail-strip-value">
                        <?php echo esc_html($this->captcha_summary($entry)); ?>
                    </span>
                </div>
                <?php if ($entry->notes !== null && $entry->notes !== '' && !$this->is_known_notes_code($entry->notes)) : ?>
                    <div class="lrob-etk-cf-detail-strip-item">
                        <span class="lrob-etk-cf-detail-strip-label"><?php esc_html_e('Notes', 'lrob-email-toolkit'); ?></span>
                        <span class="lrob-etk-cf-detail-strip-value"><?php echo esc_html($entry->notes); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <section class="lrob-etk-cf-detail-card lrob-etk-cf-detail-card--payload">
                <h2><?php esc_html_e('Submitted values', 'lrob-email-toolkit'); ?></h2>
                <?php if ($entry->fields === []) : ?>
                    <p class="lrob-etk-empty"><?php esc_html_e('No fields recorded for this submission.', 'lrob-email-toolkit'); ?></p>
                <?php else : ?>
                    <dl class="lrob-etk-cf-detail-payload">
                        <?php foreach ($entry->fields as $slug => $value) :
                            [$label, $type] = $this->field_label_and_type((string) $slug, $index);
                            ?>
                            <div class="lrob-etk-cf-detail-row">
                                <dt><?php echo esc_html($label); ?></dt>
                                <dd><?php echo $this->render_field_value($value, $type); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </section>

            <details class="lrob-etk-cf-detail-tech">
                <summary>
                    <span class="lrob-etk-cf-detail-tech-caret" aria-hidden="true">▸</span>
                    <span><?php esc_html_e('Technical details', 'lrob-email-toolkit'); ?></span>
                </summary>
                <dl class="lrob-etk-cf-detail-meta">
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
                        <dd><code class="lrob-etk-cf-ua"><?php echo esc_html($entry->user_agent); ?></code></dd>
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
        </div>
        <?php
    }

    /**
     * Resolve a submission field slug to a display label and type. Falls
     * back to a humanized slug (`text_your_name_2` → `Your name`) when the
     * form's current structure no longer contains that slug (renamed,
     * deleted, or form removed). Slug is never shown verbatim — that would
     * surface the internal naming convention to end users.
     *
     * @param array<string, array{label:string, type:string}> $index
     * @return array{0:string, 1:string}
     */
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

    /**
     * Best-effort fallback when no structural label is available. Strips
     * the field-type prefix and the trailing creation index, replaces
     * underscores with spaces, and uppercases the first letter. Yields a
     * label close enough to read; never returns an empty string so the
     * detail-view dt stays non-empty.
     */
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
            $out = '<ul class="lrob-etk-cf-detail-list">';
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
            return '<pre class="lrob-etk-cf-detail-textarea">' . esc_html($text) . '</pre>';
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
        return in_array($notes, ['honeypot_tripped', 'captcha_failed'], true);
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
