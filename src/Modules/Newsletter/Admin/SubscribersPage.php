<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Modules\Newsletter\SubscriberRepository;
use LRob\EmailToolkit\Modules\Newsletter\TrashCron;

/**
 * Subscribers list + trash management inside the Newsletter hub.
 *
 * Tabs across the top filter by status (all / pending / confirmed /
 * unsubscribed / refused / bounced / trashed). The "all" tab excludes
 * trashed since the trash tab has its own bespoke actions (Restore /
 * Permanently delete / Empty trash). Each row has inline action
 * buttons; pagination + a small search box live at the head.
 *
 * No bulk-actions checkbox UX today — the only meaningfully bulk thing
 * is "Empty trash" which has its own action button on the trashed tab.
 * Bulk trash / bulk restore can land later if anyone asks for them.
 */
final class SubscribersPage
{
    private const PAGE_SIZE = 50;

    private const TAB_STATUSES = [
        ''             => 'All',
        'pending'      => 'Pending',
        'confirmed'    => 'Confirmed',
        'unsubscribed' => 'Unsubscribed',
        'refused'      => 'Refused',
        'bounced'      => 'Bounced',
        'trashed'      => 'Trashed',
    ];

    public function __construct(private SubscriberRepository $subscribers)
    {
    }

    public function render(): void
    {
        $current_status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
        if (!array_key_exists($current_status, self::TAB_STATUSES)) {
            $current_status = '';
        }
        $search = isset($_GET['s']) ? sanitize_text_field((string) $_GET['s']) : '';
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * self::PAGE_SIZE;

        $counts = $this->subscribers->counts_by_status();
        $total = $this->subscribers->count_with_filters($current_status, $search);
        $rows = $this->subscribers->list_with_filters($current_status, $search, self::PAGE_SIZE, $offset);
        $max_page = max(1, (int) ceil($total / self::PAGE_SIZE));

        $base_url = add_query_arg(['page' => PageController::SLUG, 'view' => HomePage::VIEW_SUBSCRIBERS], admin_url('admin.php'));
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce(AjaxController::NONCE_ACTION);

        // Strings used in the inline JS block. Extracted here so wp i18n
        // make-pot picks up the /* translators */ hints — the parser
        // doesn't reliably descend through `wp_json_encode(__(...))`.
        /* translators: %s: subscriber email address */
        $i18n_confirm_trash  = __('Move %s to trash? They will stop receiving emails. You can restore them later.', 'lrob-email-toolkit');
        /* translators: %s: subscriber email address */
        $i18n_confirm_delete = __('Permanently delete %s? This cannot be undone.', 'lrob-email-toolkit');
        /* translators: %d: count of trashed subscribers */
        $i18n_confirm_empty  = __('Permanently delete all %d trashed subscribers? This cannot be undone.', 'lrob-email-toolkit');
        $i18n_error          = __('Could not complete the action.', 'lrob-email-toolkit');
        ?>
        <section class="lrob-etk-nl-subscribers">
            <header class="lrob-etk-nl-resource-head">
                <h2 class="lrob-etk-section-title"><?php esc_html_e('Subscribers', 'lrob-email-toolkit'); ?></h2>
                <p class="lrob-etk-nl-resource-intro">
                    <?php esc_html_e('Email-only subscribers managed by this module. WordPress users are recipients too but appear under Users, not here.', 'lrob-email-toolkit'); ?>
                </p>
            </header>

            <nav class="lrob-etk-nl-subtabs">
                <?php foreach (self::TAB_STATUSES as $slug => $label) : ?>
                    <?php
                    $tab_url = $slug === ''
                        ? $base_url
                        : add_query_arg('status', $slug, $base_url);
                    $count = (int) ($counts[$slug] ?? 0);
                    $is_active = $current_status === $slug;
                    ?>
                    <a href="<?php echo esc_url($tab_url); ?>"
                       class="lrob-etk-nl-subtab<?php echo $is_active ? ' is-active' : ''; ?>">
                        <?php echo esc_html(self::translate_tab_label($slug)); ?>
                        <?php if ($count > 0) : ?>
                            <span class="lrob-etk-nl-subtab-count"><?php echo esc_html(number_format_i18n($count)); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form class="lrob-etk-nl-subscribers-toolbar" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr(PageController::SLUG); ?>">
                <input type="hidden" name="view" value="<?php echo esc_attr(HomePage::VIEW_SUBSCRIBERS); ?>">
                <?php if ($current_status !== '') : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>">
                <?php endif; ?>
                <input type="search"
                       name="s"
                       value="<?php echo esc_attr($search); ?>"
                       placeholder="<?php esc_attr_e('Search email or name', 'lrob-email-toolkit'); ?>"
                       class="lrob-etk-nl-search">
                <button type="submit" class="button"><?php esc_html_e('Search', 'lrob-email-toolkit'); ?></button>
                <?php if ($current_status === 'trashed' && $total > 0) : ?>
                    <button type="button"
                            class="button button-secondary lrob-etk-nl-empty-trash"
                            data-empty-trash-count="<?php echo esc_attr((string) $total); ?>">
                        <?php esc_html_e('Empty trash', 'lrob-email-toolkit'); ?>
                    </button>
                <?php endif; ?>
            </form>

            <?php if ($current_status === 'trashed') : ?>
                <?php $purge_days = (int) get_option(TrashCron::OPTION_DAYS, 0); ?>
                <p class="lrob-etk-nl-trash-note">
                    <?php if ($purge_days > 0) : ?>
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %d: number of days. */
                            _n(
                                'Trashed subscribers are automatically deleted after %d day. Adjust this in Settings.',
                                'Trashed subscribers are automatically deleted after %d days. Adjust this in Settings.',
                                $purge_days,
                                'lrob-email-toolkit'
                            ),
                            $purge_days
                        ));
                        ?>
                    <?php else : ?>
                        <?php esc_html_e('Trashed subscribers stay here until you empty the trash manually. Enable auto-purge in Settings to remove old rows automatically.', 'lrob-email-toolkit'); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($rows === []) : ?>
                <p class="lrob-etk-nl-resource-empty">
                    <?php if ($search !== '') : ?>
                        <?php esc_html_e('No subscribers match this search.', 'lrob-email-toolkit'); ?>
                    <?php elseif ($current_status === '') : ?>
                        <?php esc_html_e('No subscribers yet. They appear once visitors submit one of your subscribe forms.', 'lrob-email-toolkit'); ?>
                    <?php else : ?>
                        <?php esc_html_e('No subscribers with this status.', 'lrob-email-toolkit'); ?>
                    <?php endif; ?>
                </p>
            <?php else : ?>
                <table class="lrob-etk-nl-subscribers-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Email', 'lrob-email-toolkit'); ?></th>
                            <th><?php esc_html_e('Name', 'lrob-email-toolkit'); ?></th>
                            <th><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                            <th><?php esc_html_e('Created', 'lrob-email-toolkit'); ?></th>
                            <th><?php esc_html_e('Source', 'lrob-email-toolkit'); ?></th>
                            <th class="lrob-etk-nl-col-actions"><?php esc_html_e('Actions', 'lrob-email-toolkit'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <?php $this->render_row($row); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($max_page > 1) : ?>
                    <nav class="lrob-etk-nl-pagination">
                        <?php for ($p = 1; $p <= $max_page; $p++) : ?>
                            <?php $page_url = add_query_arg(['paged' => $p, 's' => $search], $current_status === '' ? $base_url : add_query_arg('status', $current_status, $base_url)); ?>
                            <a href="<?php echo esc_url($page_url); ?>" class="lrob-etk-nl-pagenum<?php echo $p === $paged ? ' is-active' : ''; ?>"><?php echo (int) $p; ?></a>
                        <?php endfor; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <script>
        (function () {
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var actions = {
                trash:   <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBER_TRASH); ?>,
                restore: <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBER_RESTORE); ?>,
                del:     <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBER_DELETE); ?>,
                empty:   <?php echo wp_json_encode(AjaxController::ACTION_EMPTY_TRASH); ?>
            };
            var i18n = {
                confirmTrash:   <?php echo wp_json_encode($i18n_confirm_trash); ?>,
                confirmDelete:  <?php echo wp_json_encode($i18n_confirm_delete); ?>,
                confirmEmpty:   <?php echo wp_json_encode($i18n_confirm_empty); ?>,
                error:          <?php echo wp_json_encode($i18n_error); ?>
            };

            function post(action, fields) {
                var fd = new FormData();
                fd.append('action', action);
                fd.append('_nonce', nonce);
                Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
                return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json().catch(function () { return { success: false }; }); });
            }

            document.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('[data-subscriber-action]');
                if (btn) {
                    var act = btn.getAttribute('data-subscriber-action');
                    var id = btn.getAttribute('data-subscriber-id');
                    var email = btn.getAttribute('data-subscriber-email') || '';
                    if (!id) return;
                    if (act === 'trash' && !window.confirm(i18n.confirmTrash.replace('%s', email))) return;
                    if (act === 'delete' && !window.confirm(i18n.confirmDelete.replace('%s', email))) return;
                    var ajaxAction = act === 'trash' ? actions.trash : (act === 'restore' ? actions.restore : (act === 'delete' ? actions.del : null));
                    if (!ajaxAction) return;
                    post(ajaxAction, { id: id }).then(function (resp) {
                        if (resp && resp.success) {
                            window.location.reload();
                        } else {
                            window.alert((resp && resp.data && resp.data.message) || i18n.error);
                        }
                    });
                    return;
                }
                var emptyBtn = e.target.closest && e.target.closest('.lrob-etk-nl-empty-trash');
                if (emptyBtn) {
                    var n = parseInt(emptyBtn.getAttribute('data-empty-trash-count') || '0', 10);
                    if (!window.confirm(i18n.confirmEmpty.replace('%d', n))) return;
                    post(actions.empty, {}).then(function (resp) {
                        if (resp && resp.success) {
                            window.location.reload();
                        } else {
                            window.alert((resp && resp.data && resp.data.message) || i18n.error);
                        }
                    });
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * @param array<string, mixed> $row
     */
    private function render_row(array $row): void
    {
        $id = (int) ($row['id'] ?? 0);
        $email = (string) ($row['email'] ?? '');
        $name = (string) ($row['name'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $created = (string) ($row['created_at'] ?? '');
        $source = (string) ($row['source'] ?? '');
        ?>
        <tr data-subscriber-row="<?php echo $id; ?>">
            <td><?php echo esc_html($email); ?></td>
            <td><?php echo esc_html($name); ?></td>
            <td><span class="lrob-etk-nl-status lrob-etk-nl-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(self::translate_status($status)); ?></span></td>
            <td>
                <time datetime="<?php echo esc_attr($created); ?>">
                    <?php echo esc_html(self::format_date($created)); ?>
                </time>
            </td>
            <td><?php echo esc_html($source !== '' ? $source : '—'); ?></td>
            <td class="lrob-etk-nl-col-actions">
                <?php if ($status === 'trashed') : ?>
                    <button type="button"
                            class="lrob-etk-nl-row-action"
                            data-subscriber-action="restore"
                            data-subscriber-id="<?php echo $id; ?>"
                            data-subscriber-email="<?php echo esc_attr($email); ?>">
                        <?php esc_html_e('Restore', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button"
                            class="lrob-etk-nl-row-action is-danger"
                            data-subscriber-action="delete"
                            data-subscriber-id="<?php echo $id; ?>"
                            data-subscriber-email="<?php echo esc_attr($email); ?>">
                        <?php esc_html_e('Delete permanently', 'lrob-email-toolkit'); ?>
                    </button>
                <?php else : ?>
                    <button type="button"
                            class="lrob-etk-nl-row-action is-danger"
                            data-subscriber-action="trash"
                            data-subscriber-id="<?php echo $id; ?>"
                            data-subscriber-email="<?php echo esc_attr($email); ?>">
                        <?php esc_html_e('Trash', 'lrob-email-toolkit'); ?>
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function translate_tab_label(string $slug): string
    {
        // i18n strings are extracted only from literal __() calls — keep
        // the array values as English keys (for code clarity) but route
        // through translated labels here.
        return match ($slug) {
            ''             => __('All', 'lrob-email-toolkit'),
            'pending'      => __('Pending', 'lrob-email-toolkit'),
            'confirmed'    => __('Confirmed', 'lrob-email-toolkit'),
            'unsubscribed' => __('Unsubscribed', 'lrob-email-toolkit'),
            'refused'      => __('Refused', 'lrob-email-toolkit'),
            'bounced'      => __('Bounced', 'lrob-email-toolkit'),
            'trashed'      => __('Trashed', 'lrob-email-toolkit'),
            default        => $slug,
        };
    }

    private static function translate_status(string $status): string
    {
        return match ($status) {
            'pending'      => __('Pending', 'lrob-email-toolkit'),
            'confirmed'    => __('Confirmed', 'lrob-email-toolkit'),
            'unsubscribed' => __('Unsubscribed', 'lrob-email-toolkit'),
            'refused'      => __('Refused', 'lrob-email-toolkit'),
            'bounced'      => __('Bounced', 'lrob-email-toolkit'),
            'trashed'      => __('Trashed', 'lrob-email-toolkit'),
            default        => $status,
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
}
