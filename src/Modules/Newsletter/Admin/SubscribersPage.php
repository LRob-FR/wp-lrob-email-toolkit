<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Admin\PageHeader;
use LRob\EmailToolkit\Modules\Newsletter\ListRepository;
use LRob\EmailToolkit\Modules\Newsletter\SubscriberFields;
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

    public const USER_META_COLUMNS = 'lrob_etk_nl_subscribers_columns';

    /** @return array<string, string> slug → human label */
    public static function available_columns(): array
    {
        return [
            'email'      => __('Email', 'lrob-email-toolkit'),
            'name'       => __('Name', 'lrob-email-toolkit'),
            'first_name' => __('First name', 'lrob-email-toolkit'),
            'last_name'  => __('Last name', 'lrob-email-toolkit'),
            'phone'      => __('Phone', 'lrob-email-toolkit'),
            'gender'     => __('Gender', 'lrob-email-toolkit'),
            'language'   => __('Language', 'lrob-email-toolkit'),
            'status'     => __('Status', 'lrob-email-toolkit'),
            'created_at' => __('Created', 'lrob-email-toolkit'),
            'source'     => __('Source', 'lrob-email-toolkit'),
            'lists'      => __('Lists', 'lrob-email-toolkit'),
            'opens'      => __('Opens', 'lrob-email-toolkit'),
            'clicks'     => __('Clicks', 'lrob-email-toolkit'),
            'sends'      => __('Sends', 'lrob-email-toolkit'),
            'confirmed_at' => __('Confirmed', 'lrob-email-toolkit'),
        ];
    }

    /** @return array<int, string> Default columns shown to a fresh admin. */
    public static function default_columns(): array
    {
        return ['email', 'name', 'status', 'created_at', 'source', 'lists'];
    }

    /** @return array<int, string> Columns the current admin wants to see, validated. */
    public static function user_columns(): array
    {
        $raw = get_user_meta(get_current_user_id(), self::USER_META_COLUMNS, true);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded) || $decoded === []) {
            return self::default_columns();
        }
        $allowed = array_keys(self::available_columns());
        $clean = array_values(array_intersect($decoded, $allowed));
        return $clean !== [] ? $clean : self::default_columns();
    }

    private const TAB_STATUSES = [
        ''             => 'All',
        'pending'      => 'Pending',
        'confirmed'    => 'Confirmed',
        'cold'         => 'Cold',
        'unsubscribed' => 'Unsubscribed',
        'refused'      => 'Refused',
        'bounced'      => 'Bounced',
        'trashed'      => 'Trashed',
    ];

    /** Default cold threshold — overridable via lrob_etk_nl_cold_threshold option. */
    private const DEFAULT_COLD_THRESHOLD = 5;

    public function __construct(private SubscriberRepository $subscribers)
    {
    }

    public function render(?HomePage $hub = null): void
    {
        $current_status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
        if (!array_key_exists($current_status, self::TAB_STATUSES)) {
            $current_status = '';
        }
        $search = isset($_GET['s']) ? sanitize_text_field((string) $_GET['s']) : '';
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * self::PAGE_SIZE;

        $cold_threshold = max(1, (int) get_option('lrob_etk_nl_cold_threshold', self::DEFAULT_COLD_THRESHOLD));

        $counts = $this->subscribers->counts_by_status();
        $counts['cold'] = $this->subscribers->count_cold($cold_threshold);
        if ($current_status === 'cold') {
            $total = $counts['cold'];
            $rows = $this->subscribers->list_cold($cold_threshold, self::PAGE_SIZE, $offset);
        } else {
            $total = $this->subscribers->count_with_filters($current_status, $search);
            $rows = $this->subscribers->list_with_filters($current_status, $search, self::PAGE_SIZE, $offset);
        }
        $max_page = max(1, (int) ceil($total / self::PAGE_SIZE));

        // Build list-name lookup + bulk membership map for the column.
        // Two cheap queries (whole lists table + one IN-clause) — drops
        // the page from O(rows) memberships to O(1).
        $list_repo = new ListRepository();
        $all_lists = $list_repo->list_all();
        $lists_by_id = [];
        foreach ($all_lists as $l) {
            $lists_by_id[(int) ($l['id'] ?? 0)] = (string) ($l['name'] ?? '');
        }
        $row_ids = array_map(static fn ($r) => (int) ($r['id'] ?? 0), $rows);
        $memberships_map = $list_repo->memberships_for_recipients('subscriber', $row_ids);

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
        PageHeader::render([
            'title'   => sprintf(__('Newsletters — %s', 'lrob-email-toolkit'), __('Subscribers', 'lrob-email-toolkit')),
            'primary' => [
                'label' => __('Add subscribers', 'lrob-email-toolkit'),
                'icon'  => 'dashicons-plus-alt2',
                'id'    => 'lrob-etk-nl-add-subscribers-btn',
            ],
            'tools' => [ListsPage::lists_tool(), HomePage::subscription_emails_tool(), HomePage::settings_tool()],
        ]);
        if ($hub) $hub->render_section_tabs(HomePage::VIEW_SUBSCRIBERS);
        ?>
        <section class="lrob-etk-nl-subscribers">
            <p class="lrob-etk-nl-resource-intro">
                <?php esc_html_e('Email-only subscribers managed by this module. WordPress users are recipients too but appear under Users, not here.', 'lrob-email-toolkit'); ?>
            </p>

            <nav class="lrob-etk-section-tabs">
                <?php foreach (self::TAB_STATUSES as $slug => $label) : ?>
                    <?php
                    $tab_url = $slug === ''
                        ? $base_url
                        : add_query_arg('status', $slug, $base_url);
                    $count = (int) ($counts[$slug] ?? 0);
                    $is_active = $current_status === $slug;
                    ?>
                    <a href="<?php echo esc_url($tab_url); ?>"
                       class="lrob-etk-section-tab<?php echo $is_active ? ' is-active' : ''; ?>">
                        <?php echo esc_html(self::translate_tab_label($slug)); ?>
                        <?php if ($count > 0) : ?>
                            <span class="lrob-etk-section-tab-count"><?php echo esc_html(number_format_i18n($count)); ?></span>
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

            <?php if ($current_status === 'cold') : ?>
                <p class="lrob-etk-nl-trash-note">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %d: cold threshold (sends without engagement) */
                        _n(
                            'Recipients whose last engagement (click, or open when enabled) was %d send or more ago. Adjust the threshold + open-counts-as-engagement in Settings.',
                            'Recipients whose last engagement (click, or open when enabled) was %d sends or more ago. Adjust the threshold + open-counts-as-engagement in Settings.',
                            $cold_threshold,
                            'lrob-email-toolkit'
                        ),
                        $cold_threshold
                    ));
                    ?>
                </p>
            <?php endif; ?>

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
            <?php else :
                $user_cols = self::user_columns();
                $available = self::available_columns();
                ?>
                <div class="lrob-etk-bulk-toolbar">
                    <label class="lrob-etk-bulk-selection">
                        <input type="checkbox" id="lrob-etk-nl-subscribers-select-all">
                        <span class="lrob-etk-bulk-count"><?php
                            /* translators: %d: count of selected subscribers */
                            echo esc_html(sprintf(__('Select all (%d on this page)', 'lrob-email-toolkit'), count($rows)));
                        ?></span>
                    </label>
                    <div class="lrob-etk-bulk-toolbar-right">
                        <button type="button"
                                id="lrob-etk-nl-subscribers-columns-btn"
                                class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost"
                                title="<?php esc_attr_e('Columns', 'lrob-email-toolkit'); ?>"
                                aria-label="<?php esc_attr_e('Columns', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-editor-table" aria-hidden="true"></span>
                        </button>
                        <div class="lrob-etk-bulk-action">
                            <select id="lrob-etk-nl-subscribers-bulk-select">
                                <option value=""><?php esc_html_e('Bulk actions', 'lrob-email-toolkit'); ?></option>
                                <option value="trash"><?php esc_html_e('Move to trash', 'lrob-email-toolkit'); ?></option>
                                <option value="restore"><?php esc_html_e('Restore from trash', 'lrob-email-toolkit'); ?></option>
                                <option value="delete"><?php esc_html_e('Delete permanently', 'lrob-email-toolkit'); ?></option>
                            </select>
                            <button type="button" id="lrob-etk-nl-subscribers-bulk-apply" class="button"><?php esc_html_e('Apply', 'lrob-email-toolkit'); ?></button>
                        </div>
                    </div>
                </div>
                <div class="lrob-etk-data-table-wrap">
                <table class="lrob-etk-data-table">
                    <thead>
                        <tr>
                            <th class="col-bulk-check"><input type="checkbox" class="lrob-etk-bulk-head-check"></th>
                            <?php foreach ($user_cols as $col) : ?>
                                <th class="col-<?php echo esc_attr($col); ?>"><?php echo esc_html($available[$col] ?? $col); ?></th>
                            <?php endforeach; ?>
                            <th class="col-actions"><?php esc_html_e('Actions', 'lrob-email-toolkit'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) :
                            $row_id = (int) ($row['id'] ?? 0);
                            $row_lists = isset($memberships_map[$row_id]) ? $memberships_map[$row_id] : [];
                            ?>
                            <?php $this->render_row($row, $row_lists, $lists_by_id, $user_cols); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

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

        <?php $this->render_import_modal($all_lists); ?>
        <?php $this->render_columns_modal(); ?>

        <script>
        (function () {
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var actions = {
                trash:      <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBER_TRASH); ?>,
                restore:    <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBER_RESTORE); ?>,
                del:        <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBER_DELETE); ?>,
                import:     <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBERS_IMPORT); ?>,
                update:     <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBER_UPDATE); ?>,
                columnsPref: <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBERS_COLUMNS_PREF); ?>,
                bulk:       <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBERS_BULK); ?>,
                empty:      <?php echo wp_json_encode(AjaxController::ACTION_EMPTY_TRASH); ?>,
                detail:     <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBER_DETAIL); ?>,
                listToggle: <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBER_LIST_TOGGLE); ?>
            };
            var i18n = {
                confirmTrash:       <?php echo wp_json_encode($i18n_confirm_trash); ?>,
                confirmTrashTitle:  <?php echo wp_json_encode(__('Trash this subscriber?', 'lrob-email-toolkit')); ?>,
                confirmDelete:      <?php echo wp_json_encode($i18n_confirm_delete); ?>,
                confirmDeleteTitle: <?php echo wp_json_encode(__('Delete permanently?', 'lrob-email-toolkit')); ?>,
                confirmEmpty:       <?php echo wp_json_encode($i18n_confirm_empty); ?>,
                confirmEmptyTitle:  <?php echo wp_json_encode(__('Empty trash?', 'lrob-email-toolkit')); ?>,
                error:              <?php echo wp_json_encode($i18n_error); ?>,
                detailPrev:         <?php echo wp_json_encode(__('Previous', 'lrob-email-toolkit')); ?>,
                detailNext:         <?php echo wp_json_encode(__('Next', 'lrob-email-toolkit')); ?>,
                detailClose:        <?php echo wp_json_encode(__('Close', 'lrob-email-toolkit')); ?>,
                detailLoading:      <?php echo wp_json_encode(__('Loading…', 'lrob-email-toolkit')); ?>,
                actTrash:           <?php echo wp_json_encode(__('Trash', 'lrob-email-toolkit')); ?>,
                actRestore:         <?php echo wp_json_encode(__('Restore', 'lrob-email-toolkit')); ?>,
                actDelete:          <?php echo wp_json_encode(__('Delete', 'lrob-email-toolkit')); ?>,
                bulkNoSelection:    <?php echo wp_json_encode(__('Select at least one subscriber.', 'lrob-email-toolkit')); ?>,
                bulkNoAction:       <?php echo wp_json_encode(__('Pick a bulk action first.', 'lrob-email-toolkit')); ?>,
                bulkConfirmTrash:   <?php
                    /* translators: %d: count of selected subscribers */
                    echo wp_json_encode(__('Move %d subscribers to trash?', 'lrob-email-toolkit'));
                ?>,
                bulkConfirmDelete:  <?php
                    /* translators: %d: count of selected (already trashed) subscribers */
                    echo wp_json_encode(__('Permanently delete %d trashed subscribers? This cannot be undone.', 'lrob-email-toolkit'));
                ?>,
                bulkConfirmRestore: <?php
                    /* translators: %d: count of selected trashed subscribers to restore */
                    echo wp_json_encode(__('Restore %d subscribers from trash?', 'lrob-email-toolkit'));
                ?>
            };

            function post(action, fields) {
                var fd = new FormData();
                fd.append('action', action);
                fd.append('_nonce', nonce);
                Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
                return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json().catch(function () { return { success: false }; }); });
            }

            function ask(opts) {
                if (!window.lrobEtkConfirm) return Promise.resolve(true);
                return window.lrobEtkConfirm.prompt(opts);
            }

            // ---- Bulk selection + bulk action toolbar ----
            document.addEventListener('change', function (e) {
                var sa = e.target.closest && e.target.closest('#lrob-etk-nl-subscribers-select-all, .lrob-etk-bulk-head-check');
                if (!sa) return;
                document.querySelectorAll('.lrob-etk-nl-subscriber-check').forEach(function (cb) {
                    cb.checked = sa.checked;
                });
                // Mirror state between both select-all controls.
                document.querySelectorAll('#lrob-etk-nl-subscribers-select-all, .lrob-etk-bulk-head-check').forEach(function (other) {
                    other.checked = sa.checked;
                });
            });
            document.addEventListener('click', function (e) {
                var apply = e.target.closest && e.target.closest('#lrob-etk-nl-subscribers-bulk-apply');
                if (!apply) return;
                e.preventDefault();
                var sel = document.getElementById('lrob-etk-nl-subscribers-bulk-select');
                var op = sel ? sel.value : '';
                if (!op) { window.alert(i18n.bulkNoAction); return; }
                var ids = [];
                document.querySelectorAll('.lrob-etk-nl-subscriber-check:checked').forEach(function (cb) {
                    var v = parseInt(cb.value, 10) || 0;
                    if (v > 0) ids.push(v);
                });
                if (ids.length === 0) { window.alert(i18n.bulkNoSelection); return; }
                var confirmText = op === 'trash'   ? i18n.bulkConfirmTrash.replace('%d', ids.length)
                                : op === 'delete'  ? i18n.bulkConfirmDelete.replace('%d', ids.length)
                                : op === 'restore' ? i18n.bulkConfirmRestore.replace('%d', ids.length)
                                : '';
                ask({
                    title: op === 'delete' ? i18n.confirmDeleteTitle : i18n.confirmTrashTitle,
                    message: confirmText,
                    confirmLabel: op === 'restore' ? i18n.actRestore : (op === 'delete' ? i18n.actDelete : i18n.actTrash),
                    danger: (op !== 'restore')
                }).then(function (ok) {
                    if (!ok) return;
                    apply.disabled = true;
                    var fd = new FormData();
                    fd.append('action', actions.bulk);
                    fd.append('_nonce', nonce);
                    fd.append('op', op);
                    ids.forEach(function (id) { fd.append('ids[]', String(id)); });
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                        .then(function (resp) {
                            apply.disabled = false;
                            if (resp && resp.success) {
                                window.location.reload();
                            } else {
                                window.alert((resp && resp.data && resp.data.message) || i18n.error);
                            }
                        });
                });
            });

            // ---- Row-level actions (trash / restore / delete / empty) ----
            document.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('[data-subscriber-action]');
                if (btn) {
                    var act = btn.getAttribute('data-subscriber-action');
                    var id = btn.getAttribute('data-subscriber-id');
                    var email = btn.getAttribute('data-subscriber-email') || '';
                    if (!id) return;
                    var ajaxAction = act === 'trash' ? actions.trash : (act === 'restore' ? actions.restore : (act === 'delete' ? actions.del : null));
                    if (!ajaxAction) return;
                    var needsConfirm = (act === 'trash' || act === 'delete');
                    var confirmPromise = needsConfirm
                        ? ask({
                            title: act === 'trash' ? i18n.confirmTrashTitle : i18n.confirmDeleteTitle,
                            message: (act === 'trash' ? i18n.confirmTrash : i18n.confirmDelete).replace('%s', email),
                            confirmLabel: act === 'trash' ? i18n.actTrash : i18n.actDelete,
                            danger: true
                        })
                        : Promise.resolve(true);
                    confirmPromise.then(function (ok) {
                        if (!ok) return;
                        post(ajaxAction, { id: id }).then(function (resp) {
                            if (resp && resp.success) {
                                window.location.reload();
                            } else {
                                window.alert((resp && resp.data && resp.data.message) || i18n.error);
                            }
                        });
                    });
                    return;
                }
                var emptyBtn = e.target.closest && e.target.closest('.lrob-etk-nl-empty-trash');
                if (emptyBtn) {
                    var n = parseInt(emptyBtn.getAttribute('data-empty-trash-count') || '0', 10);
                    ask({
                        title: i18n.confirmEmptyTitle,
                        message: i18n.confirmEmpty.replace('%d', n),
                        confirmLabel: i18n.actDelete,
                        danger: true
                    }).then(function (ok) {
                        if (!ok) return;
                        post(actions.empty, {}).then(function (resp) {
                            if (resp && resp.success) {
                                window.location.reload();
                            } else {
                                window.alert((resp && resp.data && resp.data.message) || i18n.error);
                            }
                        });
                    });
                }
            });

            // ---- Detail modal (shared etk-detail-modal helper) ----
            function whenReady(fn) {
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', fn);
                } else {
                    fn();
                }
            }

            function actionsHtml(status) {
                var html = '';
                if (status === 'trashed') {
                    html += '<button type="button" class="button lrob-etk-detail-modal-action" data-cf-detail-action="restore">'
                          +   '<span class="dashicons dashicons-undo" aria-hidden="true"></span>'
                          +   '<span>' + i18n.actRestore + '</span>'
                          + '</button>';
                    html += '<button type="button" class="button lrob-etk-detail-modal-action lrob-etk-btn--danger" data-cf-detail-action="delete">'
                          +   '<span class="dashicons dashicons-trash" aria-hidden="true"></span>'
                          +   '<span>' + i18n.actDelete + '</span>'
                          + '</button>';
                } else {
                    html += '<button type="button" class="button lrob-etk-detail-modal-action lrob-etk-btn--danger" data-cf-detail-action="trash">'
                          +   '<span class="dashicons dashicons-trash" aria-hidden="true"></span>'
                          +   '<span>' + i18n.actTrash + '</span>'
                          + '</button>';
                }
                return html;
            }

            whenReady(function () {
                if (!window.lrobEtkDetailModal) return;

                var modal = window.lrobEtkDetailModal.create({
                    actionsHtml: actionsHtml(''),
                    fetcher: function (id) {
                        var fd = new FormData();
                        fd.append('action', actions.detail);
                        fd.append('_nonce', nonce);
                        fd.append('id', String(id));
                        return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(function (r) { return r.json(); })
                            .then(function (resp) {
                                if (!resp || !resp.success || !resp.data) throw new Error('bad');
                                return resp.data;
                            });
                    },
                    afterFetch: function (api, resp) {
                        // Swap the action buttons to match the loaded subscriber's status.
                        var el = api.element();
                        if (!el) return;
                        var slot = el.querySelector('[data-cf-detail-actions]');
                        if (slot) slot.innerHTML = actionsHtml(resp.status || '');
                    },
                    onAction: function (op, id, api) {
                        var email = '';
                        var row = document.querySelector('tr[data-subscriber-row="' + id + '"] a[data-etk-open-detail]');
                        if (row) email = row.textContent.trim();
                        var ajaxAction = op === 'trash' ? actions.trash : (op === 'restore' ? actions.restore : (op === 'delete' ? actions.del : null));
                        if (!ajaxAction) return;
                        var needsConfirm = (op === 'trash' || op === 'delete');
                        var confirmPromise = needsConfirm
                            ? ask({
                                title: op === 'trash' ? i18n.confirmTrashTitle : i18n.confirmDeleteTitle,
                                message: (op === 'trash' ? i18n.confirmTrash : i18n.confirmDelete).replace('%s', email),
                                confirmLabel: op === 'trash' ? i18n.actTrash : i18n.actDelete,
                                danger: true
                            })
                            : Promise.resolve(true);
                        confirmPromise.then(function (ok) {
                            if (!ok) return;
                            post(ajaxAction, { id: id }).then(function (resp) {
                                if (resp && resp.success) {
                                    window.location.reload();
                                } else {
                                    window.alert((resp && resp.data && resp.data.message) || i18n.error);
                                }
                            });
                        });
                    },
                    getVisibleIds: function () {
                        var rows = document.querySelectorAll('tr[data-subscriber-row]');
                        var ids = [];
                        Array.prototype.forEach.call(rows, function (tr) {
                            var v = parseInt(tr.getAttribute('data-subscriber-row'), 10) || 0;
                            if (v > 0) ids.push(v);
                        });
                        return ids;
                    },
                    i18n: {
                        prev:    i18n.detailPrev,
                        next:    i18n.detailNext,
                        close:   i18n.detailClose,
                        loading: i18n.detailLoading,
                        error:   i18n.error,
                    },
                });

                // Trigger: any element with [data-etk-open-detail] in a subscriber row.
                document.addEventListener('click', function (e) {
                    var trig = e.target.closest && e.target.closest('[data-etk-open-detail]');
                    if (!trig) return;
                    // Don't hijack the click if it bubbles up from a contained action button.
                    if (e.target.closest('[data-subscriber-action]')) return;
                    e.preventDefault();
                    var id = parseInt(trig.getAttribute('data-etk-row-id') || '0', 10);
                    if (id > 0) {
                        document.querySelectorAll('tr.is-active').forEach(function (tr) { tr.classList.remove('is-active'); });
                        var tr = document.querySelector('tr[data-subscriber-row="' + id + '"]');
                        if (tr) tr.classList.add('is-active');
                        modal.open(id);
                    }
                });

                // Inline subscriber profile autosave inside the modal body.
                // Per-field PATCH-style update — fires once on blur/change
                // when the value actually changed. Whitelist + sanitisation
                // run server-side via SubscriberFields::PROFILE_COLUMNS.
                function saveSubscriberField(fld) {
                    var wrap = fld.closest('[data-subscriber-edit]');
                    if (!wrap) return;
                    var subId = parseInt(wrap.getAttribute('data-subscriber-id') || '0', 10);
                    if (!subId) return;
                    var fieldName = fld.getAttribute('data-subscriber-edit-field');
                    if (!fieldName) return;
                    if (typeof fld.__original === 'undefined') { fld.__original = fld.value; return; }
                    if (fld.__original === fld.value) return;
                    var status = wrap.querySelector('[data-subscriber-edit-status]');
                    if (status) { status.textContent = <?php echo wp_json_encode(__('Saving…', 'lrob-email-toolkit')); ?>; status.className = 'lrob-etk-nl-subscriber-edit-status is-pending'; }
                    var fd = new FormData();
                    fd.append('action', actions.update);
                    fd.append('_nonce', nonce);
                    fd.append('id', String(subId));
                    fd.append('field', fieldName);
                    fd.append('value', fld.value);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                        .then(function (resp) {
                            if (resp && resp.success) {
                                fld.__original = fld.value;
                                if (status) { status.textContent = '✓ ' + <?php echo wp_json_encode(__('Saved', 'lrob-email-toolkit')); ?>; status.className = 'lrob-etk-nl-subscriber-edit-status is-success'; }
                                // Keep the underlying table row in sync.
                                var tr = document.querySelector('tr[data-subscriber-row="' + subId + '"]');
                                if (tr) {
                                    var cells = tr.querySelectorAll('td');
                                    if (fieldName === 'email' && cells[0]) {
                                        var anchor = cells[0].querySelector('a[data-etk-open-detail]');
                                        if (anchor) anchor.textContent = fld.value;
                                    }
                                    if (fieldName === 'name' && cells[1]) cells[1].textContent = fld.value;
                                }
                            } else {
                                if (status) { status.textContent = '✗ ' + ((resp && resp.data && resp.data.message) || i18n.error); status.className = 'lrob-etk-nl-subscriber-edit-status is-failure'; }
                            }
                        });
                }
                document.addEventListener('blur', function (e) {
                    var fld = e.target.closest && e.target.closest('input[data-subscriber-edit-field]');
                    if (fld) saveSubscriberField(fld);
                }, true);
                document.addEventListener('change', function (e) {
                    var fld = e.target.closest && e.target.closest('select[data-subscriber-edit-field]');
                    if (fld) saveSubscriberField(fld);
                });

                // List-membership toggle inside the modal body.
                document.addEventListener('change', function (e) {
                    var cb = e.target.closest && e.target.closest('[data-subscriber-list-toggle]');
                    if (!cb) return;
                    var wrap = cb.closest('[data-subscriber-lists]');
                    if (!wrap) return;
                    var subId = parseInt(wrap.getAttribute('data-subscriber-id') || '0', 10);
                    var listId = parseInt(cb.getAttribute('data-list-id') || '0', 10);
                    if (!subId || !listId) return;
                    var add = cb.checked ? '1' : '0';
                    cb.disabled = true;
                    post(actions.listToggle, { id: subId, list_id: listId, add: add }).then(function (resp) {
                        cb.disabled = false;
                        if (!resp || !resp.success) {
                            // Revert on failure.
                            cb.checked = !cb.checked;
                            window.alert((resp && resp.data && resp.data.message) || i18n.error);
                        }
                    });
                });

                // ---- "Add subscribers" modal (paste + CSV import) ----
                if (window.lrobEtkModal) {
                    window.lrobEtkModal.bindHeader('lrob-etk-nl-add-subscribers-modal', 'lrob-etk-nl-add-subscribers-btn');
                    window.lrobEtkModal.bindHeader('lrob-etk-nl-subscribers-columns-modal', 'lrob-etk-nl-subscribers-columns-btn');
                }

                // ---- Columns picker autosave ----
                // Pref save is AJAX (no reload mid-edit). The table can't
                // redraw without server help; we defer the page refresh
                // to when the admin closes the modal. `is-dirty` tracks
                // whether anything changed during the session.
                var columnsModal = document.getElementById('lrob-etk-nl-subscribers-columns-modal');
                var columnsPicker = document.querySelector('[data-columns-picker]');
                var columnsDirty = false;
                if (columnsPicker) {
                    columnsPicker.addEventListener('change', function (e) {
                        var cb = e.target.closest && e.target.closest('[data-column-slug]');
                        if (!cb) return;
                        cb.disabled = true;
                        if (cb.dispatchEvent) cb.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'saving' } }));
                        var picked = [];
                        columnsPicker.querySelectorAll('[data-column-slug]:checked').forEach(function (c) {
                            picked.push(c.getAttribute('data-column-slug'));
                        });
                        var fd = new FormData();
                        fd.append('action', actions.columnsPref);
                        fd.append('_nonce', nonce);
                        picked.forEach(function (slug) { fd.append('columns[]', slug); });
                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                            .then(function (resp) {
                                cb.disabled = false;
                                if (resp && resp.success) {
                                    columnsDirty = true;
                                    if (cb.dispatchEvent) cb.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'saved' } }));
                                } else {
                                    if (cb.dispatchEvent) cb.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'error' } }));
                                }
                            });
                    });
                }
                if (columnsModal) {
                    // Reload only when the admin closes the modal AND the
                    // pref actually changed — keeps the modal stable while
                    // they pick, then the table re-renders.
                    columnsModal.addEventListener('click', function (e) {
                        var resetBtn = e.target.closest && e.target.closest('[data-columns-reset-defaults]');
                        if (resetBtn) {
                            e.preventDefault();
                            var defaults = <?php echo self::default_columns_js(); ?>;
                            var defaultSet = {};
                            defaults.forEach(function (slug) { defaultSet[slug] = true; });
                            columnsPicker.querySelectorAll('[data-column-slug]').forEach(function (cb) {
                                cb.checked = !!defaultSet[cb.getAttribute('data-column-slug')];
                            });
                            // Persist server-side once.
                            resetBtn.disabled = true;
                            if (resetBtn.dispatchEvent) resetBtn.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'saving' } }));
                            var fd = new FormData();
                            fd.append('action', actions.columnsPref);
                            fd.append('_nonce', nonce);
                            defaults.forEach(function (slug) { fd.append('columns[]', slug); });
                            fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                                .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                                .then(function (resp) {
                                    resetBtn.disabled = false;
                                    if (resp && resp.success) {
                                        columnsDirty = true;
                                        if (resetBtn.dispatchEvent) resetBtn.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'saved' } }));
                                    } else {
                                        if (resetBtn.dispatchEvent) resetBtn.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'error' } }));
                                    }
                                });
                            return;
                        }
                        if (!e.target.closest || !e.target.closest('[data-modal-close]')) return;
                        if (columnsDirty) setTimeout(function () { window.location.reload(); }, 200);
                    });
                }

                var importModal = document.getElementById('lrob-etk-nl-add-subscribers-modal');
                if (!importModal) return;
                var paste     = importModal.querySelector('#lrob-etk-nl-import-paste');
                var fileInput = importModal.querySelector('[data-import-csv]');
                var preview   = importModal.querySelector('[data-import-csv-preview]');
                var countEl   = importModal.querySelector('[data-import-count]');
                var submitBtn = importModal.querySelector('[data-import-submit]');
                var resultEl  = importModal.querySelector('[data-import-result]');
                var fileLabel = importModal.querySelector('.lrob-etk-nl-import-file-text');
                var paneByMode = {};
                importModal.querySelectorAll('[data-import-pane]').forEach(function (p) {
                    paneByMode[p.getAttribute('data-import-pane')] = p;
                });
                var currentMode = 'paste';

                // Mode-switch tabs.
                importModal.querySelectorAll('[data-import-mode]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        currentMode = btn.getAttribute('data-import-mode');
                        importModal.querySelectorAll('[data-import-mode]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                        Object.keys(paneByMode).forEach(function (m) { paneByMode[m].hidden = (m !== currentMode); });
                        updatePreviewCount();
                    });
                });

                function parsePaste(text) {
                    var rows = [];
                    String(text || '').split(/\r?\n/).forEach(function (line) {
                        line = line.trim();
                        if (!line) return;
                        // Accept "email" or "email, name" or "email; name".
                        var parts = line.split(/[,;\t]/).map(function (s) { return s.trim(); });
                        var email = parts[0];
                        var name = parts.length > 1 ? parts.slice(1).join(' ').trim() : '';
                        if (email) rows.push({ email: email, name: name });
                    });
                    return rows;
                }

                // CSV parsing: respects commas inside double-quoted fields,
                // auto-detects separator (comma/semicolon/tab) on the first
                // line, and header row when the first row contains a column
                // literally named "email" (case-insensitive).
                // Recognised header names → subscriber column. Server-side
                // re-checks every key against PROFILE_COLUMNS before any
                // write reaches the DB.
                var CSV_HEADER_MAP = <?php echo wp_json_encode(\LRob\EmailToolkit\Modules\Newsletter\SubscriberFields::PROFILE_COLUMNS); ?>;
                function parseCsv(text) {
                    var lines = String(text || '').replace(/\r/g, '').split('\n').filter(function (l) { return l.trim() !== ''; });
                    if (lines.length === 0) return [];
                    var sep = detectSep(lines[0]);
                    var cells = lines.map(function (l) { return splitCsvLine(l, sep); });
                    var headers = cells[0].map(function (h) { return String(h || '').toLowerCase().trim().replace(/\s+/g, '_'); });
                    // Build a column-index → profile-column map.
                    var colMap = {};
                    var hasHeader = headers.indexOf('email') >= 0;
                    if (hasHeader) {
                        headers.forEach(function (h, idx) {
                            if (CSV_HEADER_MAP.indexOf(h) >= 0) colMap[idx] = h;
                        });
                    } else {
                        // No header — assume first column is email, optional 2nd is name.
                        colMap[0] = 'email';
                        if (cells[0].length > 1) colMap[1] = 'name';
                    }
                    var startIdx = hasHeader ? 1 : 0;
                    var rows = [];
                    for (var i = startIdx; i < cells.length; i++) {
                        var c = cells[i];
                        var row = {};
                        Object.keys(colMap).forEach(function (idx) {
                            var key = colMap[idx];
                            var val = (c[idx] || '').trim();
                            if (val !== '') row[key] = val;
                        });
                        if (row.email) rows.push(row);
                    }
                    return rows;
                }
                function detectSep(line) {
                    var counts = { ',': 0, ';': 0, '\t': 0 };
                    for (var i = 0; i < line.length; i++) {
                        if (counts.hasOwnProperty(line[i])) counts[line[i]]++;
                    }
                    var best = ',', bestN = 0;
                    Object.keys(counts).forEach(function (k) {
                        if (counts[k] > bestN) { best = k; bestN = counts[k]; }
                    });
                    return best;
                }
                function splitCsvLine(line, sep) {
                    var out = [];
                    var buf = '';
                    var inQ = false;
                    for (var i = 0; i < line.length; i++) {
                        var ch = line[i];
                        if (ch === '"') {
                            if (inQ && line[i + 1] === '"') { buf += '"'; i++; }
                            else inQ = !inQ;
                        } else if (ch === sep && !inQ) {
                            out.push(buf);
                            buf = '';
                        } else {
                            buf += ch;
                        }
                    }
                    out.push(buf);
                    return out;
                }

                var csvRows = [];
                function readFile(file) {
                    var reader = new FileReader();
                    reader.onload = function (e) {
                        var text = String(e.target.result || '');
                        csvRows = parseCsv(text);
                        if (preview) {
                            preview.hidden = csvRows.length === 0;
                            // Show first 5 rows as JSON-like preview.
                            preview.textContent = csvRows.slice(0, 5).map(function (r) {
                                return r.email + (r.name ? '  —  ' + r.name : '');
                            }).join('\n') + (csvRows.length > 5 ? '\n…' : '');
                        }
                        if (fileLabel) fileLabel.textContent = file.name;
                        updatePreviewCount();
                    };
                    reader.readAsText(file);
                }
                if (fileInput) {
                    fileInput.addEventListener('change', function () {
                        var f = fileInput.files && fileInput.files[0];
                        if (f) readFile(f);
                    });
                }
                if (paste) {
                    paste.addEventListener('input', updatePreviewCount);
                }

                function currentRows() {
                    return currentMode === 'csv' ? csvRows : parsePaste(paste ? paste.value : '');
                }
                function updatePreviewCount() {
                    var n = currentRows().length;
                    submitBtn.disabled = (n === 0);
                    if (countEl) {
                        countEl.textContent = n === 0 ? '' :
                            n === 1 ? <?php echo wp_json_encode(__('1 subscriber detected', 'lrob-email-toolkit')); ?> :
                            <?php
                            /* translators: %d: number of subscribers parsed from paste/CSV */
                            echo wp_json_encode(__('%d subscribers detected', 'lrob-email-toolkit'));
                            ?>.replace('%d', n);
                    }
                }

                if (submitBtn) {
                    submitBtn.addEventListener('click', function () {
                        var rows = currentRows();
                        if (rows.length === 0) return;
                        var statusInput = importModal.querySelector('input[name="lrob-etk-nl-import-status"]:checked');
                        var status = statusInput ? statusInput.value : 'pending';
                        var listIds = [];
                        importModal.querySelectorAll('[data-import-list-id]:checked').forEach(function (cb) {
                            var v = parseInt(cb.getAttribute('data-import-list-id'), 10) || 0;
                            if (v > 0) listIds.push(v);
                        });
                        submitBtn.disabled = true;
                        if (resultEl) { resultEl.hidden = false; resultEl.className = 'lrob-etk-nl-import-result is-pending'; resultEl.textContent = <?php echo wp_json_encode(__('Importing…', 'lrob-email-toolkit')); ?>; }
                        var fd = new FormData();
                        fd.append('action', actions.import);
                        fd.append('_nonce', nonce);
                        fd.append('status', status);
                        rows.forEach(function (r, i) {
                            // Fan out every profile column the row carries.
                            // Server-side whitelists keys against PROFILE_COLUMNS.
                            Object.keys(r).forEach(function (k) {
                                if (k === 'email' || k === 'name' || CSV_HEADER_MAP.indexOf(k) >= 0) {
                                    fd.append('rows[' + i + '][' + k + ']', r[k] || '');
                                }
                            });
                            if (!r.email) return;
                            if (!('name' in r)) fd.append('rows[' + i + '][name]', '');
                        });
                        listIds.forEach(function (id) { fd.append('list_ids[]', String(id)); });
                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                            .then(function (resp) {
                                if (resp && resp.success && resp.data) {
                                    if (resultEl) {
                                        resultEl.className = 'lrob-etk-nl-import-result is-success';
                                        resultEl.textContent = '✓ ' + (
                                            <?php
                                            /* translators: 1: new rows, 2: existing rows updated, 3: rows skipped (bad/missing email) */
                                            echo wp_json_encode(__('Done — added %1$d, updated %2$d, skipped %3$d.', 'lrob-email-toolkit'));
                                            ?>
                                                .replace('%1$d', resp.data.added)
                                                .replace('%2$d', resp.data.updated)
                                                .replace('%3$d', resp.data.skipped));
                                    }
                                    setTimeout(function () { window.location.reload(); }, 1200);
                                } else {
                                    if (resultEl) {
                                        resultEl.className = 'lrob-etk-nl-import-result is-failure';
                                        resultEl.textContent = '✗ ' + ((resp && resp.data && resp.data.message) || i18n.error);
                                    }
                                    submitBtn.disabled = false;
                                }
                            });
                    });
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Columns picker — opened by the "Columns" header tool button.
     * Per-admin user-meta. Toggling a checkbox autosaves and reloads.
     */
    private function render_columns_modal(): void
    {
        $current = self::user_columns();
        $current_set = array_flip($current);
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-nl-subscribers-columns-modal" role="dialog" aria-modal="true" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--small">
                <header class="lrob-etk-modal-header">
                    <h3 class="lrob-etk-modal-title-text"><?php esc_html_e('Columns', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p class="description">
                        <?php esc_html_e('Pick which columns appear in the Subscribers table. Saved per-admin.', 'lrob-email-toolkit'); ?>
                    </p>
                    <ul class="lrob-etk-nl-columns-picker" data-columns-picker>
                        <?php foreach (self::available_columns() as $slug => $label) :
                            $checked = isset($current_set[$slug]);
                            ?>
                            <li>
                                <label>
                                    <input type="checkbox"
                                           data-column-slug="<?php echo esc_attr($slug); ?>"
                                           <?php checked($checked); ?>>
                                    <span><?php echo esc_html($label); ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <footer class="lrob-etk-modal-footer">
                    <button type="button" class="button" data-columns-reset-defaults>
                        <span class="dashicons dashicons-image-rotate" aria-hidden="true"></span>
                        <?php esc_html_e('Reset to defaults', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button" class="button" data-modal-close><?php esc_html_e('Done', 'lrob-email-toolkit'); ?></button>
                </footer>
            </div>
        </div>
        <?php
    }

    /** Default columns list — exposed for the JS reset handler. */
    public static function default_columns_js(): string
    {
        return (string) wp_json_encode(self::default_columns());
    }

    /**
     * Modal opened by the "Add subscribers" header button. Two input
     * paths: paste (one email per line, optional "email,name" pairs)
     * and CSV upload (auto-detects header row + email/name columns).
     * CSV parsing happens client-side so the server endpoint stays
     * uniform — it just receives `rows[]` of {email,name} pairs.
     *
     * @param array<int, array<string, mixed>> $all_lists
     */
    private function render_import_modal(array $all_lists): void
    {
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-nl-add-subscribers-modal" role="dialog" aria-modal="true" aria-labelledby="lrob-etk-nl-add-subscribers-title" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog">
                <header class="lrob-etk-modal-header">
                    <h3 id="lrob-etk-nl-add-subscribers-title" class="lrob-etk-modal-title-text"><?php esc_html_e('Add subscribers', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p class="description">
                        <?php esc_html_e('Add subscribers in bulk. Existing email addresses are merged into the existing row (status is preserved unless they were trashed/refused/unsubscribed, in which case they get re-enabled).', 'lrob-email-toolkit'); ?>
                    </p>

                    <nav class="lrob-etk-section-tabs lrob-etk-nl-import-tabs" data-import-tabs>
                        <button type="button" class="lrob-etk-section-tab is-active" data-import-mode="paste"><?php esc_html_e('Paste', 'lrob-email-toolkit'); ?></button>
                        <button type="button" class="lrob-etk-section-tab" data-import-mode="csv"><?php esc_html_e('CSV upload', 'lrob-email-toolkit'); ?></button>
                    </nav>

                    <section class="lrob-etk-nl-import-pane" data-import-pane="paste">
                        <label for="lrob-etk-nl-import-paste"><?php esc_html_e('One per line — email only, or "email, name".', 'lrob-email-toolkit'); ?></label>
                        <textarea id="lrob-etk-nl-import-paste"
                                  class="lrob-etk-nl-import-textarea"
                                  rows="8"
                                  placeholder="alice@example.com&#10;bob@example.com, Bob Smith&#10;…"></textarea>
                    </section>

                    <section class="lrob-etk-nl-import-pane" data-import-pane="csv" hidden>
                        <label class="lrob-etk-nl-import-file-label">
                            <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                            <span class="lrob-etk-nl-import-file-text"><?php esc_html_e('Pick a CSV file', 'lrob-email-toolkit'); ?></span>
                            <input type="file" accept=".csv,text/csv" data-import-csv hidden>
                        </label>
                        <p class="description">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %s: comma-separated list of supported CSV column names */
                                __('Accepts a CSV with an "email" column (required) plus any of: %s. Header row auto-detected; commas, semicolons, and tabs all supported as separators.', 'lrob-email-toolkit'),
                                implode(', ', \LRob\EmailToolkit\Modules\Newsletter\SubscriberFields::PROFILE_COLUMNS)
                            ));
                            ?>
                        </p>
                        <pre class="lrob-etk-nl-import-csv-preview" data-import-csv-preview hidden></pre>
                    </section>

                    <section class="lrob-etk-nl-import-options">
                        <fieldset class="lrob-etk-nl-import-options-status">
                            <legend><?php esc_html_e('Initial status', 'lrob-email-toolkit'); ?></legend>
                            <label>
                                <input type="radio" name="lrob-etk-nl-import-status" value="pending" checked>
                                <?php esc_html_e('Pending — they\'ll get the confirmation email', 'lrob-email-toolkit'); ?>
                            </label>
                            <label>
                                <input type="radio" name="lrob-etk-nl-import-status" value="confirmed">
                                <?php esc_html_e('Confirmed — they\'ve already opted in elsewhere', 'lrob-email-toolkit'); ?>
                            </label>
                        </fieldset>

                        <?php if ($all_lists !== []) : ?>
                            <fieldset class="lrob-etk-nl-import-options-lists">
                                <legend><?php esc_html_e('Add to lists (optional)', 'lrob-email-toolkit'); ?></legend>
                                <ul class="lrob-etk-nl-subscriber-lists">
                                    <?php foreach ($all_lists as $l) : ?>
                                        <li>
                                            <label>
                                                <input type="checkbox" data-import-list-id="<?php echo (int) ($l['id'] ?? 0); ?>">
                                                <span><?php echo esc_html((string) ($l['name'] ?? '')); ?></span>
                                            </label>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </fieldset>
                        <?php endif; ?>
                    </section>

                    <div class="lrob-etk-nl-import-result" data-import-result hidden></div>
                </div>
                <footer class="lrob-etk-modal-footer">
                    <span class="lrob-etk-nl-import-count" data-import-count></span>
                    <button type="button" class="button" data-modal-close><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></button>
                    <button type="button" class="button button-primary" data-import-submit disabled>
                        <?php esc_html_e('Import', 'lrob-email-toolkit'); ?>
                    </button>
                </footer>
            </div>
        </div>
        <?php
    }

    /**
     * Per-column cell renderer. `email` is always a clickable trigger
     * that opens the detail modal — any other column stays inert (admins
     * click the eye icon in the actions column to open the modal when
     * the email column isn't shown).
     *
     * @param array<string, mixed> $row
     * @param array<int, int>      $list_ids
     * @param array<int, string>   $list_names_by_id
     */
    private function render_column_cell(string $col, array $row, array $list_ids, array $list_names_by_id): void
    {
        $id = (int) ($row['id'] ?? 0);
        switch ($col) {
            case 'email':
                $email = (string) ($row['email'] ?? '');
                ?>
                <a href="#"
                   class="lrob-etk-nl-subscriber-trigger"
                   data-etk-open-detail
                   data-etk-row-id="<?php echo $id; ?>"><?php echo esc_html($email !== '' ? $email : __('(no email)', 'lrob-email-toolkit')); ?></a>
                <?php
                break;
            case 'status':
                $status = (string) ($row['status'] ?? '');
                ?>
                <span class="lrob-etk-nl-status lrob-etk-nl-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(self::translate_status($status)); ?></span>
                <?php
                break;
            case 'created_at':
            case 'confirmed_at':
                $val = (string) ($row[$col] ?? '');
                ?>
                <time datetime="<?php echo esc_attr($val); ?>"><?php echo esc_html(self::format_date($val)); ?></time>
                <?php
                break;
            case 'lists':
                if ($list_ids === []) {
                    echo '<span class="lrob-etk-nl-subscriber-lists-empty">—</span>';
                } else {
                    echo '<span class="lrob-etk-nl-subscriber-list-chips">';
                    foreach ($list_ids as $lid) {
                        $list_name = $list_names_by_id[$lid] ?? '';
                        if ($list_name === '') {
                            continue;
                        }
                        echo '<span class="lrob-etk-nl-subscriber-list-chip">' . esc_html($list_name) . '</span>';
                    }
                    echo '</span>';
                }
                break;
            case 'gender':
                $g = (string) ($row['gender'] ?? '');
                echo esc_html($g !== '' ? \LRob\EmailToolkit\Modules\Newsletter\SubscriberFields::gender_label($g) : '—');
                break;
            case 'opens':
                echo (int) ($row['total_opened'] ?? 0);
                break;
            case 'clicks':
                echo (int) ($row['total_clicked'] ?? 0);
                break;
            case 'sends':
                echo (int) ($row['total_sent'] ?? 0);
                break;
            default:
                $val = (string) ($row[$col] ?? '');
                echo esc_html($val !== '' ? $val : '—');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, int>      $list_ids
     * @param array<int, string>   $list_names_by_id
     * @param array<int, string>   $user_cols  Column slugs the current admin wants
     */
    private function render_row(array $row, array $list_ids = [], array $list_names_by_id = [], array $user_cols = []): void
    {
        if ($user_cols === []) {
            $user_cols = self::default_columns();
        }
        $id = (int) ($row['id'] ?? 0);
        $email = (string) ($row['email'] ?? '');
        $status = (string) ($row['status'] ?? '');
        ?>
        <tr data-subscriber-row="<?php echo $id; ?>">
            <td class="col-bulk-check">
                <input type="checkbox" class="lrob-etk-nl-subscriber-check" value="<?php echo $id; ?>">
            </td>
            <?php foreach ($user_cols as $col) : ?>
                <td class="col-<?php echo esc_attr($col); ?>"><?php $this->render_column_cell($col, $row, $list_ids, $list_names_by_id); ?></td>
            <?php endforeach; ?>
            <td class="col-actions">
                <button type="button"
                        class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost"
                        data-etk-open-detail
                        data-etk-row-id="<?php echo $id; ?>"
                        title="<?php esc_attr_e('View / edit', 'lrob-email-toolkit'); ?>"
                        aria-label="<?php esc_attr_e('View subscriber details', 'lrob-email-toolkit'); ?>">
                    <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                </button>
                <?php if ($status === 'trashed') : ?>
                    <button type="button"
                            class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost"
                            data-subscriber-action="restore"
                            data-subscriber-id="<?php echo $id; ?>"
                            data-subscriber-email="<?php echo esc_attr($email); ?>"
                            title="<?php esc_attr_e('Restore', 'lrob-email-toolkit'); ?>"
                            aria-label="<?php esc_attr_e('Restore subscriber', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-undo" aria-hidden="true"></span>
                    </button>
                    <button type="button"
                            class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-icon-btn--danger"
                            data-subscriber-action="delete"
                            data-subscriber-id="<?php echo $id; ?>"
                            data-subscriber-email="<?php echo esc_attr($email); ?>"
                            title="<?php esc_attr_e('Delete permanently', 'lrob-email-toolkit'); ?>"
                            aria-label="<?php esc_attr_e('Delete subscriber permanently', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                    </button>
                <?php else : ?>
                    <button type="button"
                            class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-icon-btn--danger"
                            data-subscriber-action="trash"
                            data-subscriber-id="<?php echo $id; ?>"
                            data-subscriber-email="<?php echo esc_attr($email); ?>"
                            title="<?php esc_attr_e('Trash', 'lrob-email-toolkit'); ?>"
                            aria-label="<?php esc_attr_e('Move subscriber to trash', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Title for the detail-modal header. Used both directly when
     * rendering and by the AJAX response payload so the modal keeps a
     * consistent label as the user steps through subscribers.
     *
     * @param array<string, mixed> $row
     */
    public function detail_title(array $row): string
    {
        $email = (string) ($row['email'] ?? '');
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '' && $email !== '') {
            return $name . ' <' . $email . '>';
        }
        return $email !== '' ? $email : __('(unknown subscriber)', 'lrob-email-toolkit');
    }

    /**
     * Detail-modal body for one subscriber row. Detail strip on top
     * (status pill, key dates, engagement counters), full attribute
     * grid, then a list-membership editor wired to the AJAX toggle
     * endpoint. The AJAX detail handler buffers this method's output.
     *
     * @param array<string, mixed> $row
     */
    public function render_detail_body(array $row): void
    {
        $id = (int) ($row['id'] ?? 0);
        $status = (string) ($row['status'] ?? '');
        $created = (string) ($row['created_at'] ?? '');
        $confirmed = (string) ($row['confirmed_at'] ?? '');
        $language = (string) ($row['language'] ?? '');
        $source = (string) ($row['source'] ?? '');
        $bounce = (int) ($row['bounce_count'] ?? 0);
        $reminder_count = (int) ($row['reminder_count'] ?? 0);
        $last_reminder = (string) ($row['last_reminder_at'] ?? '');
        $total_sent = (int) ($row['total_sent'] ?? 0);
        $total_opened = (int) ($row['total_opened'] ?? 0);
        $total_clicked = (int) ($row['total_clicked'] ?? 0);
        $cold = (int) ($row['sends_since_engagement'] ?? 0);
        $last_sent = (string) ($row['last_sent_at'] ?? '');
        $last_engagement = (string) ($row['last_engagement_at'] ?? '');
        $trashed_at = (string) ($row['trashed_at'] ?? '');
        $trashed_reason = (string) ($row['trashed_reason'] ?? '');
        $prefs_token = (string) ($row['prefs_token'] ?? '');

        $lists = (new ListRepository())->list_all();
        $memberships = (new ListRepository())->memberships_for_recipient('subscriber', $id);
        $member_map = array_flip(array_map('intval', $memberships));
        $email = (string) ($row['email'] ?? '');
        $name = (string) ($row['name'] ?? '');
        ?>
        <?php
        // Profile fields rendered as plain text inputs with per-key
        // autosave. The `Identity` block is always open; the
        // `Profile` block is collapsible (extra fields are optional —
        // most sites won't fill them all).
        $profile_layout = [
            'identity' => [
                __('Identity', 'lrob-email-toolkit'),
                ['name', 'email'],
                false, // collapsible?
            ],
            'names' => [
                __('Names', 'lrob-email-toolkit'),
                ['first_name', 'last_name', 'gender'],
                true,
            ],
            'contact' => [
                __('Contact', 'lrob-email-toolkit'),
                ['phone', 'language'],
                true,
            ],
            'address' => [
                __('Postal address', 'lrob-email-toolkit'),
                ['address_line', 'address_line2', 'address_postcode', 'address_city', 'address_region', 'address_country'],
                true,
            ],
        ];
        ?>
        <section class="lrob-etk-nl-subscriber-edit" data-subscriber-edit data-subscriber-id="<?php echo (int) $id; ?>">
            <?php foreach ($profile_layout as $section_key => [$section_label, $columns, $collapsible]) :
                $any_filled = false;
                foreach ($columns as $col) {
                    if (!empty($row[$col])) { $any_filled = true; break; }
                }
                $open_attr = (!$collapsible || $any_filled) ? ' open' : '';
                $tag_open = $collapsible ? '<details class="lrob-etk-nl-subscriber-edit-group"' . $open_attr . '><summary>' . esc_html($section_label) . '</summary>' : '<div class="lrob-etk-nl-subscriber-edit-group is-static"><h4 class="lrob-etk-nl-subscriber-edit-group-title">' . esc_html($section_label) . '</h4>';
                $tag_close = $collapsible ? '</details>' : '</div>';
                echo $tag_open;
                ?>
                <div class="lrob-etk-nl-subscriber-edit-row">
                    <?php foreach ($columns as $col) :
                        $val = (string) ($row[$col] ?? '');
                        $field_label = SubscriberFields::label($col);
                        ?>
                        <label>
                            <span class="lrob-etk-nl-subscriber-edit-label"><?php echo esc_html($field_label); ?></span>
                            <?php if ($col === 'gender') : ?>
                                <select data-subscriber-edit-field="<?php echo esc_attr($col); ?>">
                                    <option value=""><?php esc_html_e('— (not set)', 'lrob-email-toolkit'); ?></option>
                                    <?php foreach (SubscriberFields::GENDER_VALUES as $g) : ?>
                                        <option value="<?php echo esc_attr($g); ?>" <?php selected($g, $val); ?>>
                                            <?php echo esc_html(SubscriberFields::gender_label($g)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else : ?>
                                <input type="<?php echo $col === 'email' ? 'email' : ($col === 'phone' ? 'tel' : 'text'); ?>"
                                       data-subscriber-edit-field="<?php echo esc_attr($col); ?>"
                                       value="<?php echo esc_attr($val); ?>"
                                       autocomplete="off">
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php echo $tag_close; ?>
            <?php endforeach; ?>
            <span class="lrob-etk-nl-subscriber-edit-status" data-subscriber-edit-status aria-live="polite"></span>
        </section>

        <div class="lrob-etk-detail-strip">
            <div class="lrob-etk-detail-strip-item">
                <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-detail-strip-value">
                    <span class="lrob-etk-nl-status lrob-etk-nl-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(self::translate_status($status)); ?></span>
                </span>
            </div>
            <div class="lrob-etk-detail-strip-item">
                <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Subscribed', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-detail-strip-value"><?php echo esc_html(self::format_date($created)); ?></span>
            </div>
            <?php if ($confirmed !== '' && $confirmed !== '0000-00-00 00:00:00') : ?>
                <div class="lrob-etk-detail-strip-item">
                    <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Confirmed', 'lrob-email-toolkit'); ?></span>
                    <span class="lrob-etk-detail-strip-value"><?php echo esc_html(self::format_date($confirmed)); ?></span>
                </div>
            <?php endif; ?>
            <div class="lrob-etk-detail-strip-item">
                <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Source', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-detail-strip-value"><?php echo esc_html($source !== '' ? $source : '—'); ?></span>
            </div>
            <?php if ($language !== '') : ?>
                <div class="lrob-etk-detail-strip-item">
                    <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Language', 'lrob-email-toolkit'); ?></span>
                    <span class="lrob-etk-detail-strip-value"><code><?php echo esc_html($language); ?></code></span>
                </div>
            <?php endif; ?>
            <div class="lrob-etk-detail-strip-item">
                <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Sends', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-detail-strip-value"><?php echo (int) $total_sent; ?></span>
            </div>
            <div class="lrob-etk-detail-strip-item">
                <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Opens / Clicks', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-detail-strip-value"><?php echo (int) $total_opened; ?> / <?php echo (int) $total_clicked; ?></span>
            </div>
            <?php if ($cold > 0) : ?>
                <div class="lrob-etk-detail-strip-item">
                    <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Sends since engagement', 'lrob-email-toolkit'); ?></span>
                    <span class="lrob-etk-detail-strip-value"><?php echo (int) $cold; ?></span>
                </div>
            <?php endif; ?>
        </div>

        <section class="lrob-etk-nl-subscriber-section">
            <h3 class="lrob-etk-nl-subscriber-section-title"><?php esc_html_e('Lists', 'lrob-email-toolkit'); ?></h3>
            <?php if ($lists === []) : ?>
                <p class="lrob-etk-nl-subscriber-empty"><?php esc_html_e('No lists yet — open Manage lists from the header to create one.', 'lrob-email-toolkit'); ?></p>
            <?php else : ?>
                <ul class="lrob-etk-nl-subscriber-lists" data-subscriber-lists data-subscriber-id="<?php echo $id; ?>">
                    <?php foreach ($lists as $list) :
                        $list_id = (int) ($list['id'] ?? 0);
                        $list_name = (string) ($list['name'] ?? '');
                        $checked = isset($member_map[$list_id]);
                        ?>
                        <li>
                            <label>
                                <input type="checkbox"
                                       data-subscriber-list-toggle
                                       data-list-id="<?php echo $list_id; ?>"
                                       <?php checked($checked); ?>>
                                <span><?php echo esc_html($list_name); ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="lrob-etk-nl-subscriber-section">
            <h3 class="lrob-etk-nl-subscriber-section-title"><?php esc_html_e('Details', 'lrob-email-toolkit'); ?></h3>
            <dl class="lrob-etk-nl-subscriber-meta">
                <dt><?php esc_html_e('Bounce count', 'lrob-email-toolkit'); ?></dt>
                <dd><?php echo (int) $bounce; ?></dd>
                <dt><?php esc_html_e('Reminders sent', 'lrob-email-toolkit'); ?></dt>
                <dd><?php echo (int) $reminder_count; ?><?php if ($last_reminder !== '' && $last_reminder !== '0000-00-00 00:00:00') : ?>
                    — <?php echo esc_html(self::format_date($last_reminder)); ?>
                <?php endif; ?></dd>
                <?php if ($last_sent !== '' && $last_sent !== '0000-00-00 00:00:00') : ?>
                    <dt><?php esc_html_e('Last send', 'lrob-email-toolkit'); ?></dt>
                    <dd><?php echo esc_html(self::format_date($last_sent)); ?></dd>
                <?php endif; ?>
                <?php if ($last_engagement !== '' && $last_engagement !== '0000-00-00 00:00:00') : ?>
                    <dt><?php esc_html_e('Last engagement', 'lrob-email-toolkit'); ?></dt>
                    <dd><?php echo esc_html(self::format_date($last_engagement)); ?></dd>
                <?php endif; ?>
                <?php if ($status === 'trashed' && $trashed_at !== '') : ?>
                    <dt><?php esc_html_e('Trashed', 'lrob-email-toolkit'); ?></dt>
                    <dd><?php echo esc_html(self::format_date($trashed_at)); ?><?php if ($trashed_reason !== '') : ?>
                        — <code><?php echo esc_html($trashed_reason); ?></code>
                    <?php endif; ?></dd>
                <?php endif; ?>
                <?php if ($prefs_token !== '') : ?>
                    <dt><?php esc_html_e('Prefs token', 'lrob-email-toolkit'); ?></dt>
                    <dd><code><?php echo esc_html(substr($prefs_token, 0, 8) . '…'); ?></code></dd>
                <?php endif; ?>
            </dl>
        </section>
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
            'cold'         => __('Cold', 'lrob-email-toolkit'),
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
