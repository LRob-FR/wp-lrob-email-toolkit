<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Logging\Admin;

use LRob\EmailToolkit\Modules\Logging\LogEntry;
use LRob\EmailToolkit\Modules\Logging\LogRepository;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WordPress-native list table for browsing email logs. Supports status filter
 * (via views), source filter (via extra_tablenav dropdown), text search across
 * subject/from/to, and pagination.
 */
final class LogsListTable extends \WP_List_Table
{
    public function __construct(private LogRepository $repository)
    {
        parent::__construct([
            'singular' => 'lrob_etk_log',
            'plural'   => 'lrob_etk_logs',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'created_at' => __('Date', 'lrob-email-toolkit'),
            'status'     => __('Status', 'lrob-email-toolkit'),
            'from_email' => __('From', 'lrob-email-toolkit'),
            'to_emails'  => __('To', 'lrob-email-toolkit'),
            'subject'    => __('Subject', 'lrob-email-toolkit'),
            'source'     => __('Source', 'lrob-email-toolkit'),
            'actions'    => __('Actions', 'lrob-email-toolkit'),
        ];
    }

    public function prepare_items(): void
    {
        $this->_column_headers = [$this->get_columns(), [], []];

        $per_page = 20;
        $page = $this->get_pagenum();

        $filters = [
            'status'    => isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '',
            'source'    => isset($_GET['source']) ? sanitize_key((string) $_GET['source']) : '',
            'search'    => isset($_GET['s']) ? sanitize_text_field((string) wp_unslash($_GET['s'])) : '',
        ];
        // Drop empty values so the repository builds the right WHERE.
        $filters = array_filter($filters, static fn ($v): bool => $v !== '');

        $total = $this->repository->count($filters);
        $this->items = $this->repository->paginate($filters, $page, $per_page);

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);
    }

    public function get_views(): array
    {
        $current = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
        $base = remove_query_arg(['status', 'paged']);

        $all_count    = $this->repository->count();
        $sent_count   = $this->repository->count(['status' => LogEntry::STATUS_SENT]);
        $failed_count = $this->repository->count(['status' => LogEntry::STATUS_FAILED]);
        $sending_count = $this->repository->count(['status' => LogEntry::STATUS_SENDING]);

        $views = [];
        $views['all'] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
            esc_url($base),
            $current === '' ? ' class="current"' : '',
            esc_html__('All', 'lrob-email-toolkit'),
            $all_count
        );
        $views['sent'] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
            esc_url(add_query_arg('status', LogEntry::STATUS_SENT)),
            $current === LogEntry::STATUS_SENT ? ' class="current"' : '',
            esc_html__('Sent', 'lrob-email-toolkit'),
            $sent_count
        );
        $views['failed'] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
            esc_url(add_query_arg('status', LogEntry::STATUS_FAILED)),
            $current === LogEntry::STATUS_FAILED ? ' class="current"' : '',
            esc_html__('Failed', 'lrob-email-toolkit'),
            $failed_count
        );
        $views['sending'] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
            esc_url(add_query_arg('status', LogEntry::STATUS_SENDING)),
            $current === LogEntry::STATUS_SENDING ? ' class="current"' : '',
            esc_html__('Sending', 'lrob-email-toolkit'),
            $sending_count
        );
        return $views;
    }

    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }
        $sources = $this->repository->distinct_sources();
        if ($sources === []) {
            return;
        }
        $current = isset($_GET['source']) ? sanitize_key((string) $_GET['source']) : '';
        ?>
        <div class="alignleft actions">
            <select name="source">
                <option value=""><?php esc_html_e('All sources', 'lrob-email-toolkit'); ?></option>
                <?php foreach ($sources as $source) : ?>
                    <option value="<?php echo esc_attr($source); ?>" <?php selected($current, $source); ?>>
                        <?php echo esc_html($source); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Filter', 'lrob-email-toolkit'), '', 'filter_action', false); ?>
        </div>
        <?php
    }

    public function column_default($item, $column_name): string
    {
        if (!$item instanceof LogEntry) {
            return '';
        }
        return match ($column_name) {
            'created_at' => esc_html($item->created_at->setTimezone(wp_timezone())->format('Y-m-d H:i:s')),
            'status'     => $this->render_status_badge($item->status),
            'from_email' => esc_html($item->from_email),
            'to_emails'  => esc_html(implode(', ', array_slice($item->to_emails, 0, 3))) . (count($item->to_emails) > 3 ? '…' : ''),
            'subject'    => $this->render_subject_link($item),
            'source'     => '<code>' . esc_html($item->source) . '</code>',
            'actions'    => $this->render_row_actions($item),
            default      => '',
        };
    }

    private function render_status_badge(string $status): string
    {
        $label = match ($status) {
            LogEntry::STATUS_SENT    => __('Sent', 'lrob-email-toolkit'),
            LogEntry::STATUS_FAILED  => __('Failed', 'lrob-email-toolkit'),
            LogEntry::STATUS_SENDING => __('Sending', 'lrob-email-toolkit'),
            LogEntry::STATUS_RETRIED => __('Retried', 'lrob-email-toolkit'),
            default                  => $status,
        };
        $class = match ($status) {
            LogEntry::STATUS_SENT    => 'lrob-etk-status--on',
            LogEntry::STATUS_FAILED  => 'lrob-etk-status--fail',
            LogEntry::STATUS_SENDING => 'lrob-etk-status--pending',
            LogEntry::STATUS_RETRIED => 'lrob-etk-status--off',
            default                  => 'lrob-etk-status--off',
        };
        return sprintf('<span class="lrob-etk-status %s">%s</span>', esc_attr($class), esc_html($label));
    }

    private function render_subject_link(LogEntry $entry): string
    {
        $url = add_query_arg(
            ['page' => PageController::SLUG, 'action' => 'view', 'id' => $entry->id],
            admin_url('admin.php')
        );
        return sprintf(
            '<strong><a href="%s">%s</a></strong>',
            esc_url($url),
            esc_html($entry->subject !== '' ? $entry->subject : __('(no subject)', 'lrob-email-toolkit'))
        );
    }

    private function render_row_actions(LogEntry $entry): string
    {
        $view_url = add_query_arg(
            ['page' => PageController::SLUG, 'action' => 'view', 'id' => $entry->id],
            admin_url('admin.php')
        );
        return sprintf(
            '<a href="%s" class="button button-small">%s</a>',
            esc_url($view_url),
            esc_html__('View', 'lrob-email-toolkit')
        );
    }
}
