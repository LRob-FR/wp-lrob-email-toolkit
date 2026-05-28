<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Admin\PageHeader;
use LRob\EmailToolkit\Modules\Newsletter\ListRepository;
use LRob\EmailToolkit\Modules\Newsletter\SubscriberFields;
use LRob\EmailToolkit\Modules\Newsletter\SubscriberRepository;
use LRob\EmailToolkit\Modules\Newsletter\TrashCron;
use LRob\EmailToolkit\Modules\Newsletter\WpUserRepository;

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
            'type'       => __('Type', 'lrob-email-toolkit'),
            'first_name' => __('First name', 'lrob-email-toolkit'),
            'last_name'  => __('Last name', 'lrob-email-toolkit'),
            'phone'      => __('Phone', 'lrob-email-toolkit'),
            'gender'     => __('Gender', 'lrob-email-toolkit'),
            'language'   => __('Language', 'lrob-email-toolkit'),
            'status'     => __('Status', 'lrob-email-toolkit'),
            'opted_in'   => __('Opted-in', 'lrob-email-toolkit'),
            'opted_out'  => __('Opted-out', 'lrob-email-toolkit'),
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
        return ['email', 'name', 'type', 'status', 'created_at', 'lists'];
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

    public function __construct(
        private SubscriberRepository $subscribers,
        private WpUserRepository $wp_users,
    ) {
    }

    public function render(?HomePage $hub = null): void
    {
        $filters = self::parse_filters();
        $current_status = (string) ($filters['status'] ?? '');
        $search = (string) ($filters['search'] ?? '');
        $list_id = (int) ($filters['list_id'] ?? 0);
        $paged = max(1, (int) ($_GET['paged'] ?? 1));

        $cold_threshold = max(1, (int) get_option('lrob_etk_nl_cold_threshold', self::DEFAULT_COLD_THRESHOLD));
        $counts = $this->subscribers->counts_by_status();
        $counts['cold'] = $this->subscribers->count_cold($cold_threshold);

        // The list-repo lookup is needed both here (to render the filter
        // dropdown options) and inside the region (for membership chips +
        // the import modal). Computed once and reused.
        $list_repo = new ListRepository();
        $all_lists = $list_repo->list_all();

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
            <nav class="lrob-etk-section-tabs">
                <?php
                // Tabs preserve every active non-status filter (search +
                // list_id) so flipping between Pending/Confirmed/… doesn't
                // wipe the rest of the filter state.
                $tab_carry = [];
                if ($search !== '') $tab_carry['s'] = $search;
                if ($list_id > 0)   $tab_carry['list_id'] = $list_id;
                foreach (self::TAB_STATUSES as $slug => $label) :
                    $tab_url = add_query_arg(
                        $slug === '' ? $tab_carry : (['status' => $slug] + $tab_carry),
                        $base_url
                    );
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

            <?php $this->render_filter_bar($filters, $all_lists); ?>

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

            <?php $this->render_list_region($filters, $paged); ?>
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
                ?>,
                bulkConfirmOptIn: <?php
                    /* translators: %d: count of recipients to opt-in */
                    echo wp_json_encode(__('Opt-in %d recipients?', 'lrob-email-toolkit'));
                ?>,
                bulkConfirmOptOut: <?php
                    /* translators: %d: count of recipients to opt-out */
                    echo wp_json_encode(__('Opt-out %d recipients?', 'lrob-email-toolkit'));
                ?>,
                bulkOptTitle:        <?php echo wp_json_encode(__('Opt-in / Opt-out', 'lrob-email-toolkit')); ?>,
                actOptIn:            <?php echo wp_json_encode(__('Opt-in', 'lrob-email-toolkit')); ?>,
                actOptOut:           <?php echo wp_json_encode(__('Opt-out', 'lrob-email-toolkit')); ?>,
                bulkSubsOnly:        <?php echo wp_json_encode(__('That action applies to subscribers only — none of the selected rows are subscribers.', 'lrob-email-toolkit')); ?>,
                bulkPickList:        <?php echo wp_json_encode(__('Pick a list first.', 'lrob-email-toolkit')); ?>,
                bulkAddToListTitle:  <?php echo wp_json_encode(__('Add to list', 'lrob-email-toolkit')); ?>,
                bulkConfirmAddToList: <?php
                    /* translators: %d: count of recipients to add to a list */
                    echo wp_json_encode(__('Add %d recipients to the selected list?', 'lrob-email-toolkit'));
                ?>,
                actAddToList:        <?php echo wp_json_encode(__('Add to list', 'lrob-email-toolkit')); ?>
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
            // Toggle the inline list-picker when the bulk-action select
            // sits on "add_to_list" (the picker carries the target list_id).
            document.addEventListener('change', function (e) {
                var sel = e.target.closest && e.target.closest('#lrob-etk-nl-subscribers-bulk-select');
                if (!sel) return;
                var picker = document.getElementById('lrob-etk-nl-subscribers-bulk-list');
                if (picker) picker.hidden = (sel.value !== 'add_to_list');
            });
            document.addEventListener('click', function (e) {
                var apply = e.target.closest && e.target.closest('#lrob-etk-nl-subscribers-bulk-apply');
                if (!apply) return;
                e.preventDefault();
                var sel = document.getElementById('lrob-etk-nl-subscribers-bulk-select');
                var op = sel ? sel.value : '';
                if (!op) { window.alert(i18n.bulkNoAction); return; }
                // add_to_list needs a non-zero target list.
                var pickedListId = 0;
                if (op === 'add_to_list') {
                    var picker = document.getElementById('lrob-etk-nl-subscribers-bulk-list');
                    pickedListId = picker ? (parseInt(picker.value, 10) || 0) : 0;
                    if (pickedListId <= 0) { window.alert(i18n.bulkPickList); return; }
                }
                // Bulk checkboxes now carry `type:id` so we can route
                // each selected row to the right server side.
                var sub_ids = [];
                var wpu_ids = [];
                document.querySelectorAll('.lrob-etk-nl-subscriber-check:checked').forEach(function (cb) {
                    var raw = String(cb.value || '');
                    var parts = raw.split(':');
                    if (parts.length !== 2) return;
                    var v = parseInt(parts[1], 10) || 0;
                    if (v <= 0) return;
                    if (parts[0] === 'subscriber') sub_ids.push(v);
                    else if (parts[0] === 'user')  wpu_ids.push(v);
                });
                var total = sub_ids.length + wpu_ids.length;
                if (total === 0) { window.alert(i18n.bulkNoSelection); return; }

                // Subscriber-only ops: silently skip any WP-user rows
                // in the selection. Opt-in / opt-out apply to both.
                var subOnlyOp = (op === 'trash' || op === 'restore' || op === 'delete');
                if (subOnlyOp && sub_ids.length === 0) {
                    window.alert(i18n.bulkSubsOnly);
                    return;
                }
                var applyCount = subOnlyOp ? sub_ids.length : total;
                var confirmText = op === 'trash'       ? i18n.bulkConfirmTrash.replace('%d', applyCount)
                                : op === 'delete'      ? i18n.bulkConfirmDelete.replace('%d', applyCount)
                                : op === 'restore'     ? i18n.bulkConfirmRestore.replace('%d', applyCount)
                                : op === 'opt_in'      ? i18n.bulkConfirmOptIn.replace('%d', applyCount)
                                : op === 'opt_out'     ? i18n.bulkConfirmOptOut.replace('%d', applyCount)
                                : op === 'add_to_list' ? i18n.bulkConfirmAddToList.replace('%d', applyCount)
                                : '';
                ask({
                    title: op === 'delete' ? i18n.confirmDeleteTitle
                         : (op === 'opt_in' || op === 'opt_out') ? i18n.bulkOptTitle
                         : (op === 'add_to_list') ? i18n.bulkAddToListTitle
                         : i18n.confirmTrashTitle,
                    message: confirmText,
                    confirmLabel: op === 'restore'     ? i18n.actRestore
                                : op === 'delete'      ? i18n.actDelete
                                : op === 'opt_in'      ? i18n.actOptIn
                                : op === 'opt_out'     ? i18n.actOptOut
                                : op === 'add_to_list' ? i18n.actAddToList
                                : i18n.actTrash,
                    danger: (op === 'delete' || op === 'trash')
                }).then(function (ok) {
                    if (!ok) return;
                    apply.disabled = true;
                    var fd = new FormData();
                    fd.append('action', actions.bulk);
                    fd.append('_nonce', nonce);
                    fd.append('op', op);
                    if (op === 'add_to_list') fd.append('list_id', String(pickedListId));
                    sub_ids.forEach(function (id) { fd.append('subscriber_ids[]', String(id)); });
                    wpu_ids.forEach(function (id) { fd.append('wp_user_ids[]', String(id)); });
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

            // ---- Inline WP-user opt-in toggle (instant save). ----
            document.addEventListener('change', function (e) {
                var cb = e.target.closest && e.target.closest('[data-wp-user-opt-toggle]');
                if (!cb) return;
                var uid = parseInt(cb.getAttribute('data-user-id') || '0', 10);
                if (!uid) return;
                var optIn = cb.checked ? '1' : '0';
                cb.disabled = true;
                var fd = new FormData();
                fd.append('action', <?php echo wp_json_encode(AjaxController::ACTION_WP_USER_OPT_TOGGLE); ?>);
                fd.append('_nonce', nonce);
                fd.append('user_id', String(uid));
                fd.append('opted_in', optIn);
                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                    .then(function (resp) {
                        cb.disabled = false;
                        if (!resp || !resp.success) {
                            cb.checked = !cb.checked;
                            window.alert((resp && resp.data && resp.data.message) || i18n.error);
                        }
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

                // ---- Single unified detail modal — walks BOTH subscriber
                //      and WP-user rows in DOM order. Composite IDs of
                //      the shape "subscriber:42" / "user:42" let one
                //      modal route to either AJAX endpoint AND let
                //      prev/next cross the type boundary seamlessly.
                var WP_USER_DETAIL_ACTION = <?php echo wp_json_encode(AjaxController::ACTION_WP_USER_DETAIL); ?>;
                var WP_USER_LIST_TOGGLE_ACTION = <?php echo wp_json_encode(AjaxController::ACTION_WP_USER_LIST_TOGGLE); ?>;
                function splitCompositeId(cid) {
                    var s = String(cid || '');
                    var i = s.indexOf(':');
                    if (i < 0) return { type: 'subscriber', id: parseInt(s, 10) || 0 };
                    return { type: s.slice(0, i), id: parseInt(s.slice(i + 1), 10) || 0 };
                }
                var modal = window.lrobEtkDetailModal.create({
                    actionsHtml: actionsHtml(''),
                    fetcher: function (cid) {
                        var parts = splitCompositeId(cid);
                        var action = parts.type === 'user' ? WP_USER_DETAIL_ACTION : actions.detail;
                        var fd = new FormData();
                        fd.append('action', action);
                        fd.append('_nonce', nonce);
                        fd.append('id', String(parts.id));
                        return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(function (r) { return r.json(); })
                            .then(function (resp) {
                                if (!resp || !resp.success || !resp.data) throw new Error('bad');
                                return resp.data;
                            });
                    },
                    afterFetch: function (api, resp) {
                        // Subscriber rows get trash/restore/delete; WP
                        // users currently have no in-modal actions
                        // (opt-in toggle + list toggles live inside the
                        // body itself).
                        var el = api.element();
                        if (!el) return;
                        var slot = el.querySelector('[data-cf-detail-actions]');
                        if (!slot) return;
                        var t = (resp && resp.type) || 'subscriber';
                        slot.innerHTML = (t === 'user') ? '' : actionsHtml(resp.status || '');
                        // Sync active-row highlight as the user steps
                        // through prev/next across types.
                        document.querySelectorAll('tr.is-active').forEach(function (tr) { tr.classList.remove('is-active'); });
                        var rowAttr = (t === 'user') ? 'data-wp-user-row' : 'data-subscriber-row';
                        var tr = document.querySelector('tr[' + rowAttr + '="' + (resp.id || '') + '"]');
                        if (tr) tr.classList.add('is-active');
                    },
                    onAction: function (op, cid, api) {
                        var parts = splitCompositeId(cid);
                        if (parts.type !== 'subscriber') return; // WP users have no in-modal actions
                        var id = parts.id;
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
                        // Walk every table row in DOM order, mapping
                        // each to its composite id. Prev/next then steps
                        // across the type boundary naturally.
                        var rows = document.querySelectorAll('tr[data-subscriber-row], tr[data-wp-user-row]');
                        var ids = [];
                        Array.prototype.forEach.call(rows, function (tr) {
                            if (tr.hasAttribute('data-subscriber-row')) {
                                var sid = parseInt(tr.getAttribute('data-subscriber-row'), 10) || 0;
                                if (sid > 0) ids.push('subscriber:' + sid);
                            } else if (tr.hasAttribute('data-wp-user-row')) {
                                var uid = parseInt(tr.getAttribute('data-wp-user-row'), 10) || 0;
                                if (uid > 0) ids.push('user:' + uid);
                            }
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

                // Trigger: subscriber row → modal.open('subscriber:N').
                document.addEventListener('click', function (e) {
                    var trig = e.target.closest && e.target.closest('[data-etk-open-detail]');
                    if (!trig) return;
                    if (e.target.closest('[data-subscriber-action]')) return;
                    e.preventDefault();
                    var id = parseInt(trig.getAttribute('data-etk-row-id') || '0', 10);
                    if (id > 0) {
                        document.querySelectorAll('tr.is-active').forEach(function (tr) { tr.classList.remove('is-active'); });
                        var tr = document.querySelector('tr[data-subscriber-row="' + id + '"]');
                        if (tr) tr.classList.add('is-active');
                        modal.open('subscriber:' + id);
                    }
                });
                // Trigger: WP user row → modal.open('user:N').
                document.addEventListener('click', function (e) {
                    var trig = e.target.closest && e.target.closest('[data-etk-open-wp-user-detail]');
                    if (!trig) return;
                    e.preventDefault();
                    var id = parseInt(trig.getAttribute('data-etk-row-id') || '0', 10);
                    if (id > 0) {
                        document.querySelectorAll('tr.is-active').forEach(function (tr) { tr.classList.remove('is-active'); });
                        var tr = document.querySelector('tr[data-wp-user-row="' + id + '"]');
                        if (tr) tr.classList.add('is-active');
                        modal.open('user:' + id);
                    }
                });
                // List-membership toggle inside the WP-user modal body.
                document.addEventListener('change', function (e) {
                    var cb = e.target.closest && e.target.closest('[data-wp-user-list-toggle]');
                    if (!cb) return;
                    var wrap = cb.closest('[data-wp-user-lists]');
                    if (!wrap) return;
                    var uid = parseInt(wrap.getAttribute('data-user-id') || '0', 10);
                    var listId = parseInt(cb.getAttribute('data-list-id') || '0', 10);
                    if (!uid || !listId) return;
                    var add = cb.checked ? '1' : '0';
                    cb.disabled = true;
                    var fd = new FormData();
                    fd.append('action', WP_USER_LIST_TOGGLE_ACTION);
                    fd.append('_nonce', nonce);
                    fd.append('id', String(uid));
                    fd.append('list_id', String(listId));
                    fd.append('add', add);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                        .then(function (resp) {
                            cb.disabled = false;
                            if (!resp || !resp.success) {
                                cb.checked = !cb.checked;
                                window.alert((resp && resp.data && resp.data.message) || i18n.error);
                            }
                        });
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
                }
                // Columns-modal opener: bound via document delegation
                // because the columns button is inside the AJAX-swapped
                // list region — bindHeader's direct ref would die after
                // the first filter reload.
                (function () {
                    var columnsModal = document.getElementById('lrob-etk-nl-subscribers-columns-modal');
                    if (!columnsModal) return;
                    function openCols()  { columnsModal.hidden = false; document.body.style.overflow = 'hidden'; }
                    function closeCols() { columnsModal.hidden = true;  document.body.style.overflow = ''; }
                    columnsModal.addEventListener('click', function (e) {
                        if (e.target.closest && e.target.closest('[data-modal-close]')) closeCols();
                    });
                    document.addEventListener('keydown', function (e) {
                        if (!columnsModal.hidden && e.key === 'Escape') closeCols();
                    });
                    document.addEventListener('click', function (e) {
                        if (e.target.closest && e.target.closest('#lrob-etk-nl-subscribers-columns-btn')) {
                            e.preventDefault();
                            openCols();
                        }
                    });
                })();

                // ---- Shared list-filter wiring ----
                // Mirrors Email Logs + CF Submissions: the filter form
                // POSTs to ACTION_SUBSCRIBERS_LIST_FILTER and the helper
                // swaps the [data-etk-list-region] HTML in place. Tabs
                // (status) stay full-reload links — they live outside
                // the region and carry the canonical state via URL.
                var filterApi = null;
                if (window.lrobEtkListFilter) {
                    filterApi = window.lrobEtkListFilter.attach({
                        formSelector:   '[data-etk-list-form]',
                        regionSelector: '[data-etk-list-region]',
                        ajaxUrl:        ajaxUrl,
                        nonce:          nonce,
                        action:         <?php echo wp_json_encode(AjaxController::ACTION_SUBSCRIBERS_LIST_FILTER); ?>,
                    });
                }
                if (window.lrobEtkSortable) {
                    window.lrobEtkSortable.attach({
                        cookieKey:      'lrob_etk_sort_subscribers',
                        formSelector:   '[data-etk-list-form]',
                        regionSelector: '[data-etk-list-region]',
                        filterApi:      filterApi,
                    });
                }
                if (window.lrobEtkPerPage) {
                    window.lrobEtkPerPage.attach({
                        slug:         'subscribers',
                        formSelector: '[data-etk-list-form]',
                        filterApi:    filterApi,
                    });
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

    /** Whitelist of column slugs accepted as `orderby`. */
    public const SORTABLE_KEYS = ['email', 'name', 'type', 'status', 'created_at', 'source', 'opens', 'clicks', 'sends'];

    /**
     * @param array<string, mixed>|null $source defaults to `$_GET`. The
     *        AJAX list-filter endpoint passes `$_POST` so the parser is
     *        shared. `cold` is a derived tab status (not a stored DB
     *        value) — the page-level logic handles it separately.
     * @return array{status:string, search:string, list_id:int, wp_users:string, orderby:string, order:string}
     */
    public static function parse_filters(?array $source = null): array
    {
        $src = $source ?? $_GET;
        $status = isset($src['status']) && is_string($src['status']) ? sanitize_key((string) $src['status']) : '';
        if (!array_key_exists($status, self::TAB_STATUSES) && $status !== 'cold') {
            $status = '';
        }
        $search = isset($src['s']) && is_string($src['s']) ? sanitize_text_field(wp_unslash((string) $src['s'])) : '';
        $list_id = isset($src['list_id']) ? max(0, (int) $src['list_id']) : 0;
        $wp = isset($src['wp_users']) && is_string($src['wp_users']) ? sanitize_key((string) $src['wp_users']) : 'include';
        if (!in_array($wp, ['include', 'only', 'exclude'], true)) {
            $wp = 'include';
        }
        $orderby = isset($src['orderby']) && is_string($src['orderby']) ? sanitize_key((string) $src['orderby']) : '';
        if (!in_array($orderby, self::SORTABLE_KEYS, true)) {
            $orderby = '';
        }
        $order = isset($src['order']) && is_string($src['order']) ? sanitize_key((string) $src['order']) : '';
        if (!in_array($order, ['asc', 'desc'], true)) {
            $order = $orderby !== '' ? 'asc' : '';
        }
        return [
            'status'   => $status,
            'search'   => $search,
            'list_id'  => $list_id,
            'wp_users' => $wp,
            'orderby'  => $orderby,
            'order'    => $order,
        ];
    }

    /**
     * Top-of-list filter bar — search + list + WP-users include/only/
     * exclude + reset. Matches the Logs / CF Submissions inbox layout
     * so the shared `etk-list-filter` JS owns the AJAX swap loop.
     *
     * @param array{status:string, search:string, list_id:int, wp_users:string} $filters
     * @param array<int, array<string, mixed>>                                   $all_lists
     */
    private function render_filter_bar(array $filters, array $all_lists): void
    {
        $current_status = $filters['status'];
        $current_search = $filters['search'];
        $current_list_id = $filters['list_id'];
        $current_wp = $filters['wp_users'];
        $has_filter = $current_search !== '' || $current_list_id > 0 || $current_wp !== 'include';
        // The "All subscribers" pseudo-system list is dropped from the
        // dropdown: it overlaps with the Confirmed status tab + with the
        // unified-table default behavior (already shows every subscriber
        // and every WP user). System lists targeting WP users still
        // appear under "WP users lists" — they're useful to scope the
        // table to a specific computed audience.
        $grouped = [
            ListRepository::KIND_SUBSCRIBERS => [],
            ListRepository::KIND_USERS       => [],
        ];
        foreach ($all_lists as $l) {
            $k = ListRepository::kind_of($l);
            if ($k === ListRepository::KIND_ALL_SUBSCRIBERS) continue;
            if (!isset($grouped[$k])) {
                $grouped[$k] = [];
            }
            $grouped[$k][] = $l;
        }
        $reset_url = add_query_arg(
            ['page' => PageController::SLUG, 'view' => HomePage::VIEW_SUBSCRIBERS]
                + ($current_status !== '' ? ['status' => $current_status] : []),
            admin_url('admin.php')
        );
        ?>
        <form method="get" class="lrob-etk-filter-bar" data-etk-list-form action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(PageController::SLUG); ?>">
            <input type="hidden" name="view" value="<?php echo esc_attr(HomePage::VIEW_SUBSCRIBERS); ?>">
            <?php if ($current_status !== '') : ?>
                <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>">
            <?php endif; ?>

            <div class="lrob-etk-filter-bar-field">
                <label for="lrob-etk-nl-subscribers-list-filter"><?php esc_html_e('List', 'lrob-email-toolkit'); ?></label>
                <select name="list_id" id="lrob-etk-nl-subscribers-list-filter" class="lrob-etk-select">
                    <option value="0"><?php esc_html_e('All lists', 'lrob-email-toolkit'); ?></option>
                    <?php
                    $group_labels = [
                        ListRepository::KIND_SUBSCRIBERS => __('Subscribers lists', 'lrob-email-toolkit'),
                        ListRepository::KIND_USERS       => __('WP users lists', 'lrob-email-toolkit'),
                    ];
                    foreach ($grouped as $kind_key => $kind_lists) :
                        if ($kind_lists === []) continue;
                        ?>
                        <optgroup label="<?php echo esc_attr($group_labels[$kind_key] ?? $kind_key); ?>">
                            <?php foreach ($kind_lists as $l) :
                                $lid = (int) ($l['id'] ?? 0);
                                $lname = (string) ($l['name'] ?? '');
                                ?>
                                <option value="<?php echo $lid; ?>" <?php selected($current_list_id, $lid); ?>>
                                    <?php echo esc_html($lname); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="lrob-etk-filter-bar-field">
                <label for="lrob-etk-nl-subscribers-wp-filter"><?php esc_html_e('WP users', 'lrob-email-toolkit'); ?></label>
                <select name="wp_users" id="lrob-etk-nl-subscribers-wp-filter" class="lrob-etk-select">
                    <option value="include" <?php selected($current_wp, 'include'); ?>><?php esc_html_e('Include', 'lrob-email-toolkit'); ?></option>
                    <option value="only"    <?php selected($current_wp, 'only');    ?>><?php esc_html_e('Only', 'lrob-email-toolkit'); ?></option>
                    <option value="exclude" <?php selected($current_wp, 'exclude'); ?>><?php esc_html_e('Exclude', 'lrob-email-toolkit'); ?></option>
                </select>
            </div>

            <div class="lrob-etk-filter-bar-field lrob-etk-filter-bar-field--search">
                <label for="lrob-etk-nl-subscribers-search"><?php esc_html_e('Search', 'lrob-email-toolkit'); ?></label>
                <input type="search"
                       id="lrob-etk-nl-subscribers-search"
                       name="s"
                       value="<?php echo esc_attr($current_search); ?>"
                       placeholder="<?php esc_attr_e('Search email or name', 'lrob-email-toolkit'); ?>">
            </div>

            <div class="lrob-etk-filter-bar-actions">
                <noscript>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'lrob-email-toolkit'); ?></button>
                </noscript>
                <a href="<?php echo esc_url($reset_url); ?>" class="button button-link" data-etk-list-reset<?php echo $has_filter ? '' : ' hidden'; ?>>
                    <?php esc_html_e('Reset', 'lrob-email-toolkit'); ?>
                </a>
            </div>
        </form>
        <?php
    }

    /**
     * Renders the AJAX-swappable list region: bulk toolbar + table +
     * pagination, OR an empty state. Public because the AJAX list-
     * filter endpoint reuses it via `render_list_region_for_filters()`.
     *
     * @param array{status:string, search:string, list_id:int} $filters
     */
    public function render_list_region(array $filters, int $page): void
    {
        $current_status = $filters['status'];
        $search = $filters['search'];
        $list_id = $filters['list_id'];
        $wp_filter = $filters['wp_users']; // include | only | exclude
        $cold_threshold = max(1, (int) get_option('lrob_etk_nl_cold_threshold', self::DEFAULT_COLD_THRESHOLD));

        $list_kind = '';
        if ($list_id > 0) {
            $list_row = (new ListRepository())->find($list_id);
            $list_kind = is_array($list_row) ? ListRepository::kind_of($list_row) : '';
        }
        $is_users_kind_filter = ($list_kind === ListRepository::KIND_USERS);

        // Build the unified row set: subscribers + WP users, merged in
        // PHP, deduped by email (WP user wins — matches the Materializer
        // convention so the table mirrors what a send would actually
        // produce). Fetch-all + slice approach: simple, fine for typical
        // site sizes. Sites with tens of thousands of users on each side
        // should migrate to SQL UNION ALL with proper LIMIT/OFFSET.
        $sub_rows = [];
        $wpu_rows = [];

        if ($wp_filter !== 'only' && !$is_users_kind_filter) {
            $effective_list_id = $list_id;
            $effective_status  = $current_status;
            if ($list_kind === ListRepository::KIND_ALL_SUBSCRIBERS) {
                $effective_list_id = 0;
                if ($effective_status === '') {
                    $effective_status = 'confirmed';
                }
            }
            if ($current_status === 'cold') {
                $sub_rows = $list_id > 0
                    ? []
                    : $this->subscribers->list_cold($cold_threshold, 50000, 0);
            } else {
                $sub_rows = $this->subscribers->list_with_filters($effective_status, $search, 50000, 0, $effective_list_id);
            }
        }

        if ($wp_filter !== 'exclude' && self::status_applies_to_wp_users($current_status)) {
            $wp_filters = [
                'search'     => $search,
                'list_id'    => $list_id,
                'opt_status' => self::wp_opt_status_for_tab($current_status),
            ];
            $wpu_rows = $this->wp_users->paginate($wp_filters, 50000, 0);
        }

        // Normalize + dedup
        $merged = [];
        foreach ($wpu_rows as $u) {
            $email = strtolower(trim((string) $u['email']));
            if ($email === '') continue;
            $merged[$email] = self::normalize_wp_user_row($u);
        }
        foreach ($sub_rows as $s) {
            $email = strtolower(trim((string) ($s['email'] ?? '')));
            if ($email === '') continue;
            if (isset($merged[$email])) continue; // WP user wins
            $merged[$email] = self::normalize_subscriber_row($s);
        }
        $merged = array_values($merged);
        // Sort: explicit `orderby` wins; otherwise default created/registered DESC.
        $orderby = (string) ($filters['orderby'] ?? '');
        $order = (string) ($filters['order'] ?? '');
        if ($orderby !== '') {
            $dir = $order === 'desc' ? -1 : 1;
            usort($merged, static function ($a, $b) use ($orderby, $dir) {
                $va = self::sort_value_for($a, $orderby);
                $vb = self::sort_value_for($b, $orderby);
                if (is_int($va) || is_float($va)) {
                    return ($va <=> $vb) * $dir;
                }
                return strcasecmp((string) $va, (string) $vb) * $dir;
            });
        } else {
            usort($merged, static fn ($a, $b) => strcmp((string) $b['__sort_at'], (string) $a['__sort_at']));
        }

        $total = count($merged);
        $per_page = \LRob\EmailToolkit\Admin\PerPagePicker::parse('subscribers', self::PAGE_SIZE);
        $offset = ($page - 1) * $per_page;
        $rows = array_slice($merged, $offset, $per_page);
        $max_page = max(1, (int) ceil($total / $per_page));

        $list_repo = new ListRepository();
        $all_lists = $list_repo->list_all();
        $lists_by_id = [];
        foreach ($all_lists as $l) {
            $lists_by_id[(int) ($l['id'] ?? 0)] = (string) ($l['name'] ?? '');
        }
        // Two memberships maps: one per recipient_kind. Build the id list
        // per kind from the current page's rows so the lookup is O(page).
        $sub_ids_in_page = [];
        $usr_ids_in_page = [];
        foreach ($rows as $r) {
            if ($r['__type'] === 'subscriber') $sub_ids_in_page[] = (int) $r['__id'];
            elseif ($r['__type'] === 'user')   $usr_ids_in_page[] = (int) $r['__id'];
        }
        $memberships_map = [
            'subscriber' => $list_repo->memberships_for_recipients('subscriber', $sub_ids_in_page),
            'user'       => $list_repo->memberships_for_recipients('user', $usr_ids_in_page),
        ];
        $user_cols = self::user_columns();
        $available = self::available_columns();
        $is_filtered = $search !== '' || $list_id > 0 || $wp_filter !== 'include';
        ?>
        <div class="lrob-etk-list-region" data-etk-list-region>
            <?php if ($rows === []) : ?>
                <p class="lrob-etk-nl-resource-empty">
                    <?php if ($is_users_kind_filter) : ?>
                        <?php esc_html_e('That list targets WordPress users, not subscribers — no rows to show here.', 'lrob-email-toolkit'); ?>
                    <?php elseif ($is_filtered) : ?>
                        <?php esc_html_e('No subscribers match these filters.', 'lrob-email-toolkit'); ?>
                    <?php elseif ($current_status === '') : ?>
                        <?php esc_html_e('No subscribers yet. They appear once visitors submit one of your subscribe forms.', 'lrob-email-toolkit'); ?>
                    <?php else : ?>
                        <?php esc_html_e('No subscribers with this status.', 'lrob-email-toolkit'); ?>
                    <?php endif; ?>
                </p>
            <?php else :
                $first = $offset + 1;
                $last  = min($total, $offset + count($rows));
                $bulk_eligible_lists = array_values(array_filter(
                    $all_lists,
                    static fn (array $l): bool => ListRepository::kind_of($l) === ListRepository::KIND_SUBSCRIBERS
                        && !ListRepository::is_system($l)
                ));
                ?>
                <div class="lrob-etk-bulk-toolbar">
                    <div class="lrob-etk-bulk-action">
                        <select id="lrob-etk-nl-subscribers-bulk-select">
                            <option value=""><?php esc_html_e('Bulk actions', 'lrob-email-toolkit'); ?></option>
                            <optgroup label="<?php esc_attr_e('Opt-in / Opt-out', 'lrob-email-toolkit'); ?>">
                                <option value="opt_in"><?php esc_html_e('Opt-in', 'lrob-email-toolkit'); ?></option>
                                <option value="opt_out"><?php esc_html_e('Opt-out', 'lrob-email-toolkit'); ?></option>
                            </optgroup>
                            <optgroup label="<?php esc_attr_e('Lists', 'lrob-email-toolkit'); ?>">
                                <option value="add_to_list"><?php esc_html_e('Add to list…', 'lrob-email-toolkit'); ?></option>
                            </optgroup>
                            <optgroup label="<?php esc_attr_e('Subscribers only', 'lrob-email-toolkit'); ?>">
                                <option value="trash"><?php esc_html_e('Move to trash', 'lrob-email-toolkit'); ?></option>
                                <option value="restore"><?php esc_html_e('Restore from trash', 'lrob-email-toolkit'); ?></option>
                                <option value="delete"><?php esc_html_e('Delete permanently', 'lrob-email-toolkit'); ?></option>
                            </optgroup>
                        </select>
                        <select id="lrob-etk-nl-subscribers-bulk-list" class="lrob-etk-select" hidden>
                            <option value="0"><?php esc_html_e('Pick a list…', 'lrob-email-toolkit'); ?></option>
                            <?php foreach ($bulk_eligible_lists as $l) : ?>
                                <option value="<?php echo (int) ($l['id'] ?? 0); ?>"><?php echo esc_html((string) ($l['name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="lrob-etk-nl-subscribers-bulk-apply" class="button"><?php esc_html_e('Apply', 'lrob-email-toolkit'); ?></button>
                    </div>
                    <div class="lrob-etk-bulk-action">
                        <span class="lrob-etk-bulk-count"><?php
                            printf(
                                /* translators: 1: first index, 2: last index, 3: total count */
                                esc_html__('Showing %1$d–%2$d of %3$d', 'lrob-email-toolkit'),
                                (int) $first,
                                (int) $last,
                                (int) $total
                            );
                        ?></span>
                        <?php \LRob\EmailToolkit\Admin\PerPagePicker::render('subscribers', $per_page); ?>
                        <?php if ($current_status === 'trashed' && $total > 0) : ?>
                            <button type="button"
                                    class="button lrob-etk-btn--danger lrob-etk-nl-empty-trash"
                                    data-empty-trash-count="<?php echo esc_attr((string) $total); ?>">
                                <?php esc_html_e('Empty trash', 'lrob-email-toolkit'); ?>
                            </button>
                        <?php endif; ?>
                        <button type="button"
                                id="lrob-etk-nl-subscribers-columns-btn"
                                class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost"
                                title="<?php esc_attr_e('Columns', 'lrob-email-toolkit'); ?>"
                                aria-label="<?php esc_attr_e('Columns', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-editor-table" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
                <div class="lrob-etk-data-table-wrap">
                    <table class="lrob-etk-data-table">
                        <thead>
                            <tr>
                                <th class="col-bulk-check"><input type="checkbox" class="lrob-etk-bulk-head-check"></th>
                                <?php foreach ($user_cols as $col) :
                                    $sortable = in_array($col, self::SORTABLE_KEYS, true);
                                    ?>
                                    <th class="col-<?php echo esc_attr($col); ?>"<?php echo $sortable ? ' data-sort-key="' . esc_attr($col) . '"' : ''; ?>><?php echo esc_html($available[$col] ?? $col); ?></th>
                                <?php endforeach; ?>
                                <th class="col-actions"><?php esc_html_e('Actions', 'lrob-email-toolkit'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) :
                                $row_id = (int) ($row['__id'] ?? 0);
                                $row_type = (string) ($row['__type'] ?? 'subscriber');
                                $row_lists = $memberships_map[$row_type][$row_id] ?? [];
                                $this->render_row($row, $row_lists, $lists_by_id, $user_cols);
                            endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php $this->render_pagination($page, $max_page, $filters); ?>
            <?php endif; ?>
            <div class="lrob-etk-list-loading" aria-hidden="true"><span class="spinner is-active"></span></div>
        </div>
        <?php
    }

    /**
     * AJAX entry point — wraps `render_list_region()` with the count +
     * pagination clamp so the page can recover gracefully when the
     * requested page number outruns the new filter's total.
     *
     * @param array{status:string, search:string, list_id:int} $filters
     */
    public function render_list_region_for_filters(array $filters, int $page): void
    {
        $this->render_list_region($filters, max(1, $page));
    }

    /**
     * @param array{status:string, search:string, list_id:int} $filters
     */
    private function render_pagination(int $page, int $total_pages, array $filters): void
    {
        if ($total_pages <= 1) {
            return;
        }
        // Build an explicit admin-URL base so right-click "Open in new
        // tab" works and the URL stays sane when the region is AJAX-
        // swapped (current URL would resolve to admin-ajax.php).
        $params = [
            'page' => PageController::SLUG,
            'view' => HomePage::VIEW_SUBSCRIBERS,
        ];
        if ($filters['status'] !== '')        $params['status']   = $filters['status'];
        if ($filters['search'] !== '')        $params['s']        = $filters['search'];
        if ($filters['list_id'] > 0)          $params['list_id']  = $filters['list_id'];
        if (($filters['wp_users'] ?? 'include') !== 'include') $params['wp_users'] = $filters['wp_users'];
        if (!empty($filters['orderby']))      $params['orderby']  = $filters['orderby'];
        if (!empty($filters['order']))        $params['order']    = $filters['order'];
        $base = add_query_arg(array_merge($params, ['paged' => '%#%']), admin_url('admin.php'));

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
        <nav class="lrob-etk-pagination" aria-label="<?php esc_attr_e('Subscribers pagination', 'lrob-email-toolkit'); ?>">
            <?php foreach ($links as $link) : ?>
                <?php echo wp_kses_post($link); ?>
            <?php endforeach; ?>
        </nav>
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

                        <?php
                        // Same eligibility rule as the detail-modal picker
                        // + the form-default-lists picker: only manual-
                        // membership subscribers-kind lists accept new
                        // members from this import path.
                        $eligible_lists = array_values(array_filter(
                            $all_lists,
                            static fn (array $l): bool => ListRepository::kind_of($l) === ListRepository::KIND_SUBSCRIBERS
                                && !ListRepository::is_system($l)
                        ));
                        ?>
                        <?php if ($eligible_lists !== []) : ?>
                            <fieldset class="lrob-etk-nl-import-options-lists">
                                <legend><?php esc_html_e('Add to lists (optional)', 'lrob-email-toolkit'); ?></legend>
                                <ul class="lrob-etk-nl-subscriber-lists">
                                    <?php foreach ($eligible_lists as $l) : ?>
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
        $id = (int) ($row['__id'] ?? $row['id'] ?? 0);
        $type = (string) ($row['__type'] ?? 'subscriber');
        $is_wp_user = ($type === 'user');
        switch ($col) {
            case 'email':
                $email = (string) ($row['email'] ?? '');
                $opener_attr = $is_wp_user ? 'data-etk-open-wp-user-detail' : 'data-etk-open-detail';
                if ($is_wp_user) : ?>
                    <span class="lrob-etk-nl-type-glyph lrob-etk-nl-type-glyph--user"
                          title="<?php esc_attr_e('WordPress user', 'lrob-email-toolkit'); ?>"
                          aria-label="<?php esc_attr_e('WordPress user', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
                    </span>
                <?php endif; ?>
                <a href="#"
                   class="lrob-etk-nl-subscriber-trigger"
                   <?php echo $opener_attr; ?>
                   data-etk-row-id="<?php echo $id; ?>"><?php echo esc_html($email !== '' ? $email : __('(no email)', 'lrob-email-toolkit')); ?></a>
                <?php
                break;
            case 'type':
                // Plain neutral pill (base `.lrob-etk-nl-status` only) —
                // no per-type colour to avoid adding new pill variants.
                ?>
                <span class="lrob-etk-nl-status">
                    <?php echo esc_html($is_wp_user ? __('WP user', 'lrob-email-toolkit') : __('Subscriber', 'lrob-email-toolkit')); ?>
                </span>
                <?php
                break;
            case 'status':
                if ($is_wp_user) {
                    $effective = (string) ($row['effective_status'] ?? '');
                    // Reuse the existing pill colour set instead of
                    // defining new `-opted_in/-opted_out` rules.
                    $pill = self::status_pill_class($effective);
                    ?>
                    <span class="lrob-etk-nl-status lrob-etk-nl-status-<?php echo esc_attr($pill); ?>"><?php echo esc_html(self::translate_effective_label($effective)); ?></span>
                    <?php
                } else {
                    $status = (string) ($row['status'] ?? '');
                    ?>
                    <span class="lrob-etk-nl-status lrob-etk-nl-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(self::translate_status($status)); ?></span>
                    <?php
                }
                break;
            case 'opted_in':
            case 'opted_out':
                $opt_state = (string) ($row['__opt_state'] ?? '');
                $is_in = ($opt_state === 'in');
                $want_in = ($col === 'opted_in');
                $match = ($is_in === $want_in);
                if ($is_wp_user && $col === 'opted_in') {
                    ?>
                    <label class="lrob-etk-nl-opt-toggle">
                        <input type="checkbox"
                               data-wp-user-opt-toggle
                               data-user-id="<?php echo $id; ?>"
                               <?php checked($is_in); ?>>
                    </label>
                    <?php
                } else {
                    echo $match ? '✓' : '—';
                }
                break;
            case 'created_at':
                $val = $is_wp_user ? (string) ($row['registered'] ?? '') : (string) ($row['created_at'] ?? '');
                ?>
                <time datetime="<?php echo esc_attr($val); ?>"><?php echo esc_html(self::format_date($val)); ?></time>
                <?php
                break;
            case 'confirmed_at':
                $val = (string) ($row[$col] ?? '');
                ?>
                <time datetime="<?php echo esc_attr($val); ?>"><?php echo esc_html(self::format_date($val)); ?></time>
                <?php
                break;
            case 'name':
                $name = $is_wp_user ? (string) ($row['display_name'] ?? '') : (string) ($row['name'] ?? '');
                echo esc_html($name !== '' ? $name : '—');
                break;
            case 'source':
                if ($is_wp_user) {
                    $roles = (array) ($row['roles'] ?? []);
                    echo esc_html($roles ? implode(', ', $roles) : '—');
                } else {
                    $val = (string) ($row['source'] ?? '');
                    echo esc_html($val !== '' ? $val : '—');
                }
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
        $id = (int) ($row['__id'] ?? $row['id'] ?? 0);
        $type = (string) ($row['__type'] ?? 'subscriber');
        $is_wp_user = ($type === 'user');
        $email = (string) ($row['email'] ?? '');
        $status = (string) ($row['status'] ?? '');
        // Bulk checkbox carries `type:id` so the server can route each
        // selected row to the right side when a mixed selection lands.
        $bulk_value = $type . ':' . $id;
        $row_attr = $is_wp_user ? 'data-wp-user-row' : 'data-subscriber-row';
        ?>
        <tr <?php echo esc_attr($row_attr); ?>="<?php echo $id; ?>" data-row-type="<?php echo esc_attr($type); ?>">
            <td class="col-bulk-check">
                <input type="checkbox" class="lrob-etk-nl-subscriber-check" value="<?php echo esc_attr($bulk_value); ?>">
            </td>
            <?php foreach ($user_cols as $col) : ?>
                <td class="col-<?php echo esc_attr($col); ?>"><?php $this->render_column_cell($col, $row, $list_ids, $list_names_by_id); ?></td>
            <?php endforeach; ?>
            <td class="col-actions">
                <?php if ($is_wp_user) : ?>
                    <button type="button"
                            class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost"
                            data-etk-open-wp-user-detail
                            data-etk-row-id="<?php echo $id; ?>"
                            title="<?php esc_attr_e('View / edit', 'lrob-email-toolkit'); ?>"
                            aria-label="<?php esc_attr_e('View WP user details', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                    </button>
                    <?php $edit_url = get_edit_user_link($id); ?>
                    <?php if ($edit_url) : ?>
                        <a href="<?php echo esc_url($edit_url); ?>"
                           class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost"
                           title="<?php esc_attr_e('Open in WordPress user editor', 'lrob-email-toolkit'); ?>"
                           aria-label="<?php esc_attr_e('Open in WordPress user editor', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                        </a>
                    <?php endif; ?>
                <?php else : ?>
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
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /** True when the current status tab has any WP-user analog (some don't). */
    private static function status_applies_to_wp_users(string $status): bool
    {
        return !in_array($status, ['pending', 'refused', 'trashed', 'cold'], true);
    }

    /** Map a status tab slug to a WP user opt_status filter, or '' for "any". */
    private static function wp_opt_status_for_tab(string $status): string
    {
        return match ($status) {
            'confirmed'    => 'in',
            'unsubscribed' => 'out',
            'bounced'      => 'bounced',
            default        => '',
        };
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private static function normalize_subscriber_row(array $s): array
    {
        $status = (string) ($s['status'] ?? '');
        $opt_state = in_array($status, ['confirmed', 'pending'], true) ? 'in' : 'out';
        return array_merge($s, [
            '__type'      => 'subscriber',
            '__id'        => (int) ($s['id'] ?? 0),
            '__sort_at'   => (string) ($s['created_at'] ?? ''),
            '__opt_state' => $opt_state,
        ]);
    }

    /**
     * @param array<string, mixed> $u
     * @return array<string, mixed>
     */
    private static function normalize_wp_user_row(array $u): array
    {
        $effective = (string) ($u['effective_status'] ?? 'opted_out');
        $opt_state = ($effective === 'opted_in') ? 'in' : 'out';
        return array_merge($u, [
            '__type'      => 'user',
            '__id'        => (int) ($u['ID'] ?? 0),
            '__sort_at'   => (string) ($u['registered'] ?? ''),
            '__opt_state' => $opt_state,
        ]);
    }

    /** Detail-modal title for a WP user row. */
    public function wp_user_detail_title(array $row): string
    {
        $email = (string) ($row['email'] ?? '');
        $name = trim((string) ($row['display_name'] ?? ''));
        if ($name !== '' && $email !== '') {
            return $name . ' <' . $email . '>';
        }
        return $email !== '' ? $email : __('(unknown WP user)', 'lrob-email-toolkit');
    }

    /**
     * Detail-modal body for a WP user. Mirrors render_detail_body's
     * shape (detail strip + sections) so the two modals feel
     * consistent. Identity fields (email / display_name / roles) are
     * read-only — they're WP-core-managed; we link out to the WP user
     * editor for changes.
     *
     * @param array<string, mixed> $row  Output of WpUserRepository::row_from_user
     */
    public function render_wp_user_detail_body(array $row): void
    {
        $id = (int) ($row['ID'] ?? 0);
        $email = (string) ($row['email'] ?? '');
        $display = (string) ($row['display_name'] ?? '');
        $roles = (array) ($row['roles'] ?? []);
        $registered = (string) ($row['registered'] ?? '');
        $confirmed = (string) ($row['confirmed_at'] ?? '');
        $source = (string) ($row['source'] ?? '');
        $bounce = (int) ($row['bounce_count'] ?? 0);
        $total_sent = (int) ($row['total_sent'] ?? 0);
        $total_opened = (int) ($row['total_opened'] ?? 0);
        $total_clicked = (int) ($row['total_clicked'] ?? 0);
        $last_sent = (string) ($row['last_sent_at'] ?? '');
        $last_engagement = (string) ($row['last_engagement_at'] ?? '');
        $effective = (string) ($row['effective_status'] ?? '');
        $opted_in = (bool) ($row['opted_in'] ?? false);
        $edit_url = get_edit_user_link($id);

        $list_repo = new ListRepository();
        // Toggleable lists: only manual-membership subscribers-kind
        // non-system lists, same eligibility rule as the subscriber-
        // side modal. Users-kind / system lists either resolve via rule
        // or have no admin-managed membership row.
        $toggleable_lists = array_values(array_filter(
            $list_repo->list_all(),
            static fn (array $l): bool => ListRepository::kind_of($l) === ListRepository::KIND_SUBSCRIBERS
                && !ListRepository::is_system($l)
        ));
        $manual_memberships = $list_repo->memberships_for_recipient('user', $id);
        $manual_member_map = array_flip(array_map('intval', $manual_memberships));
        ?>
        <section class="lrob-etk-nl-subscriber-edit" data-wp-user-edit data-user-id="<?php echo $id; ?>">
            <div class="lrob-etk-nl-subscriber-edit-group is-static">
                <h4 class="lrob-etk-nl-subscriber-edit-group-title"><?php esc_html_e('Identity (WP user)', 'lrob-email-toolkit'); ?></h4>
                <div class="lrob-etk-nl-subscriber-edit-row">
                    <label>
                        <span class="lrob-etk-nl-subscriber-edit-label"><?php esc_html_e('Email', 'lrob-email-toolkit'); ?></span>
                        <input type="email" value="<?php echo esc_attr($email); ?>" readonly>
                    </label>
                    <label>
                        <span class="lrob-etk-nl-subscriber-edit-label"><?php esc_html_e('Display name', 'lrob-email-toolkit'); ?></span>
                        <input type="text" value="<?php echo esc_attr($display); ?>" readonly>
                    </label>
                    <label>
                        <span class="lrob-etk-nl-subscriber-edit-label"><?php esc_html_e('Role(s)', 'lrob-email-toolkit'); ?></span>
                        <input type="text" value="<?php echo esc_attr(implode(', ', $roles)); ?>" readonly>
                    </label>
                </div>
                <?php if ($edit_url) : ?>
                    <p class="description">
                        <a href="<?php echo esc_url($edit_url); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Edit identity fields in the WordPress user editor →', 'lrob-email-toolkit'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </section>

        <div class="lrob-etk-detail-strip">
            <div class="lrob-etk-detail-strip-item">
                <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-detail-strip-value">
                    <span class="lrob-etk-nl-status lrob-etk-nl-status-<?php echo esc_attr($effective); ?>"><?php echo esc_html(self::translate_effective_label($effective)); ?></span>
                </span>
            </div>
            <div class="lrob-etk-detail-strip-item">
                <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Registered', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-detail-strip-value"><?php echo esc_html(self::format_date($registered)); ?></span>
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
            <div class="lrob-etk-detail-strip-item">
                <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Sends', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-detail-strip-value"><?php echo (int) $total_sent; ?></span>
            </div>
            <div class="lrob-etk-detail-strip-item">
                <span class="lrob-etk-detail-strip-label"><?php esc_html_e('Opens / Clicks', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-detail-strip-value"><?php echo (int) $total_opened; ?> / <?php echo (int) $total_clicked; ?></span>
            </div>
        </div>

        <section class="lrob-etk-nl-subscriber-section">
            <h3 class="lrob-etk-nl-subscriber-section-title"><?php esc_html_e('Newsletter preferences', 'lrob-email-toolkit'); ?></h3>
            <label class="lrob-etk-nl-opt-toggle" style="font-size:14px;">
                <input type="checkbox"
                       data-wp-user-opt-toggle
                       data-user-id="<?php echo $id; ?>"
                       <?php checked($opted_in); ?>>
                <span><?php esc_html_e('Receive newsletter emails (opt-in)', 'lrob-email-toolkit'); ?></span>
            </label>
            <p class="description">
                <?php esc_html_e('WP users are opt-out by default — uncheck to stop sending. Transactional emails (password resets, etc.) are unaffected.', 'lrob-email-toolkit'); ?>
            </p>
        </section>

        <section class="lrob-etk-nl-subscriber-section">
            <h3 class="lrob-etk-nl-subscriber-section-title"><?php esc_html_e('Manual list memberships', 'lrob-email-toolkit'); ?></h3>
            <?php if ($toggleable_lists === []) : ?>
                <p class="lrob-etk-nl-subscriber-empty"><?php esc_html_e('No lists yet — open Manage lists from the header to create one.', 'lrob-email-toolkit'); ?></p>
            <?php else : ?>
                <ul class="lrob-etk-nl-subscriber-lists" data-wp-user-lists data-user-id="<?php echo $id; ?>">
                    <?php foreach ($toggleable_lists as $list) :
                        $list_id = (int) ($list['id'] ?? 0);
                        $list_name = (string) ($list['name'] ?? '');
                        $checked = isset($manual_member_map[$list_id]);
                        ?>
                        <li>
                            <label>
                                <input type="checkbox"
                                       data-wp-user-list-toggle
                                       data-list-id="<?php echo $list_id; ?>"
                                       <?php checked($checked); ?>>
                                <span><?php echo esc_html($list_name); ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="description">
                    <?php esc_html_e('Rule-based (WP users) lists auto-include this user when the rule matches at send time — not shown here.', 'lrob-email-toolkit'); ?>
                </p>
            <?php endif; ?>
        </section>

        <section class="lrob-etk-nl-subscriber-section">
            <h3 class="lrob-etk-nl-subscriber-section-title"><?php esc_html_e('Engagement', 'lrob-email-toolkit'); ?></h3>
            <dl class="lrob-etk-nl-subscriber-meta">
                <dt><?php esc_html_e('Bounce count', 'lrob-email-toolkit'); ?></dt>
                <dd><?php echo (int) $bounce; ?></dd>
                <?php if ($last_sent !== '' && $last_sent !== '0000-00-00 00:00:00') : ?>
                    <dt><?php esc_html_e('Last send', 'lrob-email-toolkit'); ?></dt>
                    <dd><?php echo esc_html(self::format_date($last_sent)); ?></dd>
                <?php endif; ?>
                <?php if ($last_engagement !== '' && $last_engagement !== '0000-00-00 00:00:00') : ?>
                    <dt><?php esc_html_e('Last engagement', 'lrob-email-toolkit'); ?></dt>
                    <dd><?php echo esc_html(self::format_date($last_engagement)); ?></dd>
                <?php endif; ?>
            </dl>
        </section>
        <?php
    }

    /**
     * Extract a sortable value from a normalized row for `$orderby`. WP
     * users + subscribers carry slightly different fields under the hood
     * (registered vs created_at, display_name vs name, role vs source),
     * so each sort key falls back across both.
     *
     * @param array<string, mixed> $row
     * @return string|int|float
     */
    private static function sort_value_for(array $row, string $key)
    {
        $is_wp_user = (($row['__type'] ?? '') === 'user');
        switch ($key) {
            case 'email':
                return strtolower((string) ($row['email'] ?? ''));
            case 'name':
                return strtolower((string) ($is_wp_user ? ($row['display_name'] ?? '') : ($row['name'] ?? '')));
            case 'type':
                return (string) ($row['__type'] ?? '');
            case 'status':
                return $is_wp_user ? (string) ($row['effective_status'] ?? '') : (string) ($row['status'] ?? '');
            case 'created_at':
                return (string) ($row['__sort_at'] ?? '');
            case 'source':
                if ($is_wp_user) {
                    $roles = (array) ($row['roles'] ?? []);
                    return strtolower(implode(',', $roles));
                }
                return strtolower((string) ($row['source'] ?? ''));
            case 'opens':
                return (int) ($row['total_opened'] ?? 0);
            case 'clicks':
                return (int) ($row['total_clicked'] ?? 0);
            case 'sends':
                return (int) ($row['total_sent'] ?? 0);
            default:
                return '';
        }
    }

    /**
     * Map a WP-user effective status onto an existing status pill class so
     * the unified table reuses the colour set already defined for
     * subscribers — no duplicate CSS rules needed.
     */
    public static function status_pill_class(string $effective): string
    {
        return match ($effective) {
            'opted_in'  => 'confirmed',
            'opted_out' => 'unsubscribed',
            'bounced'   => 'bounced',
            'refused'   => 'refused',
            default     => $effective,
        };
    }

    /** Human label for either a subscriber status or a WP-user effective status. */
    public static function translate_effective_label(string $effective): string
    {
        return match ($effective) {
            'opted_in'  => __('Opted-in', 'lrob-email-toolkit'),
            'opted_out' => __('Opted-out', 'lrob-email-toolkit'),
            'bounced'   => __('Bounced', 'lrob-email-toolkit'),
            'refused'   => __('Refused', 'lrob-email-toolkit'),
            default     => self::translate_status($effective),
        };
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

        // Only manual-membership subscribers-kind lists are toggleable
        // here. System lists (All subscribers / All WP members / …) and
        // users-kind lists target WP users, not subscribers — surfacing
        // them would mislead the admin into thinking the toggle has
        // any effect. Same eligibility rule as the form-default-lists
        // picker (`handle_save_meta` → `default_list_ids`).
        $lists = array_values(array_filter(
            (new ListRepository())->list_all(),
            static fn (array $l): bool => ListRepository::kind_of($l) === ListRepository::KIND_SUBSCRIBERS
                && !ListRepository::is_system($l)
        ));
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
