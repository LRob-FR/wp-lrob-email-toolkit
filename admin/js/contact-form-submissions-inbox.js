/* LRob Email Toolkit — Contact Form submissions inbox.
 *
 * Drives two interactive surfaces on the submissions list page:
 *
 *   1. Live filter UX — typing in the search box / changing any dropdown
 *      or date input triggers a debounced AJAX reload of the list region
 *      (summary + table + pagination). URL is kept in sync via
 *      history.replaceState so back/forward + bookmarks stay accurate.
 *
 *   2. Bulk selection + actions — a checkbox column + select-all in the
 *      header, plus a "Bulk actions" dropdown above the table for "Mark as
 *      spam" / "Delete permanently". Delete requires an explicit confirm
 *      (window.confirm, mirrors WP comment moderation).
 *
 * Vanilla JS, no deps. The script guards itself: if the inbox root is
 * absent on the current page it's a no-op.
 */
(function () {
    'use strict';

    var DATA = window.lrobEtkCfInbox || {};
    var I18N = DATA.i18n || {};

    // Holds the shared filter helper's API once attached. Provides
    // `reload(page, skipUrlUpdate)` + `currentRegion()`. Falls back to
    // a no-op shim when the helper script isn't on the page (detail
    // page or unrelated admin screens).
    var filterApi = null;

    function init() {
        var form = document.querySelector('[data-etk-list-form]');
        // The list-page wiring needs both. The detail-page only needs the
        // row-action binding (its Spam/Delete buttons reuse the same
        // data-cf-row-action attribute). Bind whatever is present.
        if (form && currentRegion() && window.lrobEtkListFilter) {
            filterApi = window.lrobEtkListFilter.attach({
                formSelector:   '[data-etk-list-form]',
                regionSelector: '[data-etk-list-region]',
                ajaxUrl:        DATA.ajaxUrl || '',
                nonce:          DATA.nonce || '',
                action:         DATA.actionFilter || 'lrob_etk_cf_submissions_filter',
            });
        }
        if (window.lrobEtkSortable) {
            window.lrobEtkSortable.attach({
                cookieKey:      'lrob_etk_sort_cf_submissions',
                formSelector:   '[data-etk-list-form]',
                regionSelector: '[data-etk-list-region]',
                filterApi:      filterApi,
            });
        }
        if (window.lrobEtkPerPage) {
            window.lrobEtkPerPage.attach({
                slug:         'cf_submissions',
                formSelector: '[data-etk-list-form]',
                filterApi:    filterApi,
            });
        }
        bindDelegatedHandlers();
    }
    // Thin wrapper so callers that fired the old in-file reloadList don't
    // need to change. The helper owns the actual fetch + region swap.
    function reloadList(form, page, skipUrlUpdate) {
        if (!filterApi) return Promise.resolve();
        return filterApi.reload(page, skipUrlUpdate);
    }

    // All click / change handlers below the filter form go through
    // document-level delegation — the list region is swapped on every
    // AJAX reload, so per-element listeners would die after the first swap.
    function bindDelegatedHandlers() {
        document.addEventListener('click', function (e) {
            // Pagination links are handled by the shared list-filter helper.

            // Bulk Apply button.
            var apply = e.target.closest && e.target.closest('[data-cf-bulk-apply]');
            if (apply) {
                e.preventDefault();
                handleBulkApply();
                return;
            }

            // Row + detail-page action buttons (Spam / Unspam / Delete).
            var rowBtn = e.target.closest && e.target.closest('[data-cf-row-action]');
            if (rowBtn) {
                e.preventDefault();
                var op = rowBtn.getAttribute('data-cf-row-action');
                var id = parseInt(rowBtn.getAttribute('data-cf-row-id') || '0', 10);
                if (op && id > 0) {
                    handleRowAction(op, id);
                }
                return;
            }

            // Detail-open link (preview text + view-eye icon in the row).
            var openDetail = e.target.closest && e.target.closest('[data-cf-open-detail]');
            if (openDetail) {
                e.preventDefault();
                var detailId = parseInt(openDetail.getAttribute('data-cf-row-id') || '0', 10);
                if (detailId > 0) {
                    openDetailModal(detailId);
                }
                return;
            }
        });

        // Checkbox handling: select-all toggles the column, individual
        // checkboxes update the counter + Apply button enable state.
        document.addEventListener('change', function (e) {
            var checkAll = e.target.closest && e.target.closest('[data-cf-bulk-check-all]');
            if (checkAll) {
                var region = currentRegion();
                if (!region) return;
                var boxes = region.querySelectorAll('[data-cf-bulk-check]');
                Array.prototype.forEach.call(boxes, function (c) { c.checked = checkAll.checked; });
                refreshBulkUiState();
                return;
            }
            var rowBox = e.target.closest && e.target.closest('[data-cf-bulk-check]');
            if (rowBox) {
                if (!rowBox.checked) {
                    var ca = document.querySelector('[data-cf-bulk-check-all]');
                    if (ca) ca.checked = false;
                }
                refreshBulkUiState();
                return;
            }
        });

        // Initial state sync (e.g. on page load there might be saved
        // checkbox state from browser memory of a back-button restore).
        refreshBulkUiState();
    }

    function refreshBulkUiState() {
        var region = currentRegion();
        if (!region) return;
        var ids = selectedRowIds();
        var counter = region.querySelector('[data-cf-bulk-count]');
        var apply = region.querySelector('[data-cf-bulk-apply]');
        if (counter) {
            if (ids.length > 0) {
                counter.textContent = (I18N.selectedCount || '%d selected').replace('%d', String(ids.length));
                counter.hidden = false;
            } else {
                counter.hidden = true;
            }
        }
        if (apply) apply.disabled = (ids.length === 0);
    }

    function selectedRowIds() {
        var region = currentRegion();
        if (!region) return [];
        var ids = [];
        Array.prototype.forEach.call(region.querySelectorAll('[data-cf-bulk-check]:checked'), function (el) {
            var v = parseInt(el.value, 10) || 0;
            if (v > 0) ids.push(v);
        });
        return ids;
    }

    function handleBulkApply() {
        var region = currentRegion();
        if (!region) return;
        var ids = selectedRowIds();
        if (ids.length === 0) {
            openConfirmModal({
                title: I18N.nothingPicked || 'Nothing selected',
                body:  '',
                tone:  'info',
                confirmLabel: 'OK',
                onConfirm: null,
            });
            return;
        }
        var opSelect = region.querySelector('[data-cf-bulk-op]');
        var op = opSelect ? opSelect.value : '';
        if (!op) {
            openConfirmModal({
                title: I18N.selectAction || 'Pick an action',
                body:  '',
                tone:  'info',
                confirmLabel: 'OK',
                onConfirm: null,
            });
            return;
        }
        var spec = modalSpecFor(op, ids.length);
        openConfirmModal({
            title: spec.title,
            body: spec.body,
            tone: spec.tone,
            confirmLabel: spec.confirmLabel,
            onConfirm: function () { runBulk(op, ids); },
        });
    }

    function handleRowAction(op, id) {
        // Unspam is non-destructive — no modal, fire immediately.
        if (op === 'unspam') {
            runBulk('unspam', [id]);
            return;
        }
        var spec = modalSpecFor(op, 1);
        openConfirmModal({
            title: spec.title,
            body: spec.body,
            tone: spec.tone,
            confirmLabel: spec.confirmLabel,
            onConfirm: function () { runBulk(op, [id]); },
        });
    }

    function modalSpecFor(op, count) {
        if (op === 'delete') {
            return {
                title: count === 1
                    ? (I18N.confirmDeleteOne || 'Delete this submission?')
                    : (I18N.confirmDelete    || 'Delete the selected submissions?').replace('%d', String(count)),
                body: I18N.deleteIrrev || 'This is irreversible. Field data and any attached files will be permanently removed.',
                tone: 'danger',
                confirmLabel: I18N.confirmDeleteBtn || 'Yes, delete permanently',
            };
        }
        // spam (single or bulk)
        return {
            title: count === 1
                ? (I18N.confirmSpamOne || 'Mark this submission as spam?')
                : (I18N.confirmSpam    || 'Mark the selected submissions as spam?').replace('%d', String(count)),
            body: I18N.spamReversible || 'Spam-marked submissions stay in the inbox under the Spam filter — you can restore them at any time.',
            tone: 'warn',
            confirmLabel: I18N.confirmSpamBtn || 'Yes, mark as spam',
        };
    }

    // The list region gets *replaced* on every AJAX reload, so any cached
    // reference goes stale after one swap. Always look it up fresh.
    function currentRegion() {
        return document.querySelector('[data-etk-list-region]');
    }

    // Live-filter / pagination / popstate now live in the shared
    // `etk-list-filter.js` helper (attached above from init via
    // `window.lrobEtkListFilter`). The bulk + row + modal handlers
    // below stay page-specific.

    // --- Bulk + row actions execution --------------------------------
    function runBulk(op, ids) {
        var region = currentRegion();
        if (region) region.classList.add('is-loading');

        var fd = new FormData();
        fd.append('action', DATA.actionBulk || 'lrob_etk_cf_submissions_bulk');
        fd.append('_ajax_nonce', DATA.nonce || '');
        fd.append('op', op);
        ids.forEach(function (id) { fd.append('ids[]', String(id)); });

        fetch(DATA.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    throw new Error('bad-response');
                }
                // List page: refresh the region with current filters so the
                // affected rows disappear (delete) or move statuses (spam /
                // unspam). Detail page: redirect back to the inbox with a
                // notice so the user sees a clear confirmation — the detail
                // page no longer represents reality after a delete anyway.
                var form = document.querySelector('[data-etk-list-form]');
                if (form) {
                    // If the detail modal is currently open AND the action
                    // targets the row being viewed, refresh / close the
                    // modal accordingly. Spam/unspam keeps the modal open
                    // and reflects the new status; delete closes it since
                    // the row no longer exists.
                    var modalOpen = detailEl && detailEl.classList.contains('is-open');
                    var actsOnViewed = modalOpen && ids.length === 1 && ids[0] === detailCurrentId;
                    var reload = reloadList(form, 1, true);
                    if (actsOnViewed) {
                        if (op === 'delete') {
                            closeDetailModal();
                        } else {
                            // Re-fetch when the list reload finishes so the
                            // visible-row list (for prev/next) is up to date.
                            if (reload && reload.then) {
                                reload.then(function () { fetchDetail(detailCurrentId); });
                            } else {
                                fetchDetail(detailCurrentId);
                            }
                        }
                    }
                } else {
                    var noticeMap = { spam: 'spam', unspam: 'unspam', delete: 'deleted' };
                    var redirect = (DATA.baseUrl || '') + (DATA.baseUrl && DATA.baseUrl.indexOf('?') !== -1 ? '&' : '?')
                        + 'notice=' + encodeURIComponent(noticeMap[op] || '') + '&id=' + encodeURIComponent(String(ids[0] || 0));
                    window.location.href = redirect;
                }
            })
            .catch(function () {
                var r2 = currentRegion();
                if (r2) r2.classList.remove('is-loading');
                openConfirmModal({
                    title: I18N.error || 'Error',
                    body: '',
                    tone: 'danger',
                    confirmLabel: 'OK',
                    onConfirm: null,
                });
            });
    }

    // --- Confirm modal ------------------------------------------------
    // Single lazily-built dialog reused for spam / delete confirmations
    // and for the rare info-only popups (nothing selected, etc.).
    var modalEl = null;
    function ensureModal() {
        if (modalEl) return modalEl;
        modalEl = document.createElement('div');
        modalEl.className = 'lrob-etk lrob-etk-confirm-modal';
        modalEl.setAttribute('role', 'dialog');
        modalEl.setAttribute('aria-modal', 'true');
        modalEl.innerHTML = ''
            + '<div class="lrob-etk-confirm-modal-backdrop" data-cf-modal-close></div>'
            + '<div class="lrob-etk-confirm-modal-panel" role="document">'
            +   '<h2 class="lrob-etk-confirm-modal-title" data-cf-modal-title></h2>'
            +   '<div class="lrob-etk-confirm-modal-body" data-cf-modal-body></div>'
            +   '<div class="lrob-etk-confirm-modal-actions">'
            +     '<button type="button" class="button" data-cf-modal-cancel></button>'
            +     '<button type="button" class="button button-primary" data-cf-modal-confirm></button>'
            +   '</div>'
            + '</div>';
        document.body.appendChild(modalEl);

        // ESC key closes (when open).
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modalEl.classList.contains('is-open')) {
                closeModal();
            }
        });
        modalEl.addEventListener('click', function (e) {
            if (e.target.closest('[data-cf-modal-close]') || e.target.closest('[data-cf-modal-cancel]')) {
                closeModal();
            }
        });
        return modalEl;
    }
    function openConfirmModal(opts) {
        var m = ensureModal();
        m.querySelector('[data-cf-modal-title]').textContent = opts.title || '';
        m.querySelector('[data-cf-modal-body]').textContent  = opts.body  || '';
        m.querySelector('[data-cf-modal-cancel]').textContent = opts.onConfirm ? (I18N.cancel || 'Cancel') : (I18N.close || 'Close');
        var confirm = m.querySelector('[data-cf-modal-confirm]');
        confirm.textContent = opts.confirmLabel || 'OK';
        confirm.className = 'button button-primary';
        if (opts.tone === 'danger') confirm.classList.add('lrob-etk-btn--danger-solid');
        if (opts.tone === 'warn')   confirm.classList.add('lrob-etk-btn--warn-solid');
        // info-only modals have no confirm callback — hide the confirm btn.
        if (!opts.onConfirm) {
            confirm.hidden = true;
            m.querySelector('[data-cf-modal-cancel]').textContent = I18N.close || 'Close';
        } else {
            confirm.hidden = false;
        }
        confirm.onclick = function () {
            closeModal();
            if (typeof opts.onConfirm === 'function') opts.onConfirm();
        };
        m.classList.add('is-open');
        setTimeout(function () { confirm.focus(); }, 0);
    }
    function closeModal() {
        if (modalEl) modalEl.classList.remove('is-open');
    }

    // --- Detail modal -------------------------------------------------
    // Larger modal opened by clicking a submission preview or the view
    // eye-icon. Carries prev/next buttons to walk through the currently
    // visible row IDs without leaving the inbox. The underlying row gets
    // an `.is-active` class so admins can locate themselves at a glance.
    var detailEl = null;
    var detailCurrentId = 0;
    function ensureDetailModal() {
        if (detailEl) return detailEl;
        detailEl = document.createElement('div');
        // Carry `lrob-etk` so the plugin-wide button/dashicon fixes in
        // admin-base.css (line-height: 1 on .button .dashicons, plus the
        // WP 7.0 dashicons override) apply to the modal's buttons too —
        // they're appended to body, outside the page's normal wrap.
        detailEl.className = 'lrob-etk lrob-etk-confirm-modal lrob-etk-detail-modal';
        detailEl.setAttribute('role', 'dialog');
        detailEl.setAttribute('aria-modal', 'true');
        // Header layout: title on the left, the entire controls cluster
        // pinned to the right — action buttons first (Spam/Restore +
        // Delete), then nav arrows, then close. Order is intentional so
        // arrows sit just next to the close X for easy thumb travel.
        detailEl.innerHTML = ''
            + '<div class="lrob-etk-confirm-modal-backdrop" data-cf-modal-close></div>'
            + '<div class="lrob-etk-detail-modal-panel" role="document">'
            +   '<header class="lrob-etk-detail-modal-header">'
            +     '<h2 class="lrob-etk-detail-modal-title" data-cf-detail-title></h2>'
            +     '<div class="lrob-etk-detail-modal-controls">'
            +       '<button type="button" class="button lrob-etk-detail-modal-action" data-cf-detail-spam>'
            +         '<span class="dashicons dashicons-flag" aria-hidden="true"></span>'
            +         '<span class="lrob-etk-detail-modal-action-label"></span>'
            +       '</button>'
            +       '<button type="button" class="button lrob-etk-detail-modal-action lrob-etk-btn--danger" data-cf-detail-delete>'
            +         '<span class="dashicons dashicons-trash" aria-hidden="true"></span>'
            +         '<span class="lrob-etk-detail-modal-action-label">' + escHtml(I18N.detailDelete || 'Delete') + '</span>'
            +       '</button>'
            +       '<span class="lrob-etk-detail-modal-controls-spacer" aria-hidden="true"></span>'
            +       '<button type="button" class="lrob-etk-detail-modal-nav" data-cf-detail-prev aria-label="' + escAttr(I18N.detailPrev || 'Previous') + '">'
            +         '<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>'
            +       '</button>'
            +       '<button type="button" class="lrob-etk-detail-modal-nav" data-cf-detail-next aria-label="' + escAttr(I18N.detailNext || 'Next') + '">'
            +         '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>'
            +       '</button>'
            +       '<button type="button" class="lrob-etk-detail-modal-close" data-cf-modal-close aria-label="' + escAttr(I18N.close || 'Close') + '">'
            +         '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>'
            +       '</button>'
            +     '</div>'
            +   '</header>'
            +   '<div class="lrob-etk-detail-modal-body" data-cf-detail-body></div>'
            + '</div>';
        document.body.appendChild(detailEl);

        detailEl.addEventListener('click', function (e) {
            if (e.target.closest('[data-cf-modal-close]')) {
                closeDetailModal();
                return;
            }
            if (e.target.closest('[data-cf-detail-prev]')) {
                e.preventDefault();
                stepDetail(-1);
                return;
            }
            if (e.target.closest('[data-cf-detail-next]')) {
                e.preventDefault();
                stepDetail(+1);
                return;
            }
            var spamBtn = e.target.closest('[data-cf-detail-spam]');
            if (spamBtn && detailCurrentId > 0) {
                e.preventDefault();
                handleRowAction(spamBtn.getAttribute('data-cf-current-op') || 'spam', detailCurrentId);
                return;
            }
            var delBtn = e.target.closest('[data-cf-detail-delete]');
            if (delBtn && detailCurrentId > 0) {
                e.preventDefault();
                handleRowAction('delete', detailCurrentId);
                return;
            }
        });
        document.addEventListener('keydown', function (e) {
            if (!detailEl.classList.contains('is-open')) return;
            if (e.key === 'Escape') { closeDetailModal(); return; }
            if (e.key === 'ArrowLeft')  { stepDetail(-1); return; }
            if (e.key === 'ArrowRight') { stepDetail(+1); return; }
        });
        return detailEl;
    }
    function openDetailModal(id) {
        var m = ensureDetailModal();
        m.classList.add('is-open');
        fetchDetail(id);
    }
    function closeDetailModal() {
        if (detailEl) detailEl.classList.remove('is-open');
        clearActiveRow();
        detailCurrentId = 0;
    }
    function fetchDetail(id) {
        if (!detailEl) return;
        detailCurrentId = id;
        markActiveRow(id);
        refreshDetailNav();

        // No-flicker swap: keep the previous content visible, just dim
        // the panel via `.is-loading` while the new HTML is in flight.
        // First-open (empty body) shows a small spinner instead of a
        // blank area.
        var body = detailEl.querySelector('[data-cf-detail-body]');
        var title = detailEl.querySelector('[data-cf-detail-title]');
        var firstOpen = body && body.childNodes.length === 0;
        detailEl.classList.add('is-loading');
        if (firstOpen && body) {
            body.innerHTML = '<p class="lrob-etk-detail-modal-loading">' + escHtml(I18N.detailLoading || 'Loading…') + '</p>';
        }

        var fd = new FormData();
        fd.append('action', DATA.actionDetail || 'lrob_etk_cf_submissions_detail');
        fd.append('_ajax_nonce', DATA.nonce || '');
        fd.append('id', String(id));

        fetch(DATA.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp || !resp.success || !resp.data) {
                    throw new Error('bad-response');
                }
                // Atomic swap — keeps the modal scroll position stable
                // and avoids the blank flash between navigations.
                if (title) title.textContent = resp.data.title || '';
                if (body)  body.innerHTML = resp.data.html  || '';
                if (body) body.scrollTop = 0;
                updateDetailActions(resp.data.status || '');
            })
            .catch(function () {
                if (body) body.innerHTML = '<p class="lrob-etk-detail-modal-error">' + escHtml(I18N.error || 'Error') + '</p>';
            })
            .finally(function () {
                detailEl.classList.remove('is-loading');
            });
    }
    function updateDetailActions(status) {
        if (!detailEl) return;
        var spamBtn = detailEl.querySelector('[data-cf-detail-spam]');
        if (!spamBtn) return;
        var label = spamBtn.querySelector('.lrob-etk-detail-modal-action-label');
        var icon  = spamBtn.querySelector('.dashicons');
        var isSpam = status === 'spam_blocked';
        spamBtn.setAttribute('data-cf-current-op', isSpam ? 'unspam' : 'spam');
        spamBtn.classList.toggle('lrob-etk-btn--spam', !isSpam);
        if (label) {
            label.textContent = isSpam
                ? (I18N.detailRestore || 'Restore from spam')
                : (I18N.detailMarkSpam || 'Mark as spam');
        }
        if (icon) {
            icon.classList.remove('dashicons-flag', 'dashicons-undo');
            icon.classList.add(isSpam ? 'dashicons-undo' : 'dashicons-flag');
        }
    }
    function visibleRowIds() {
        var region = currentRegion();
        if (!region) return [];
        var ids = [];
        Array.prototype.forEach.call(region.querySelectorAll('tr[data-submission-id]'), function (tr) {
            var v = parseInt(tr.getAttribute('data-submission-id'), 10) || 0;
            if (v > 0) ids.push(v);
        });
        return ids;
    }
    function stepDetail(delta) {
        var ids = visibleRowIds();
        if (ids.length === 0) return;
        var idx = ids.indexOf(detailCurrentId);
        if (idx < 0) return;
        var next = idx + delta;
        if (next < 0 || next >= ids.length) return;
        fetchDetail(ids[next]);
    }
    function refreshDetailNav() {
        if (!detailEl) return;
        var ids = visibleRowIds();
        var idx = ids.indexOf(detailCurrentId);
        var prev = detailEl.querySelector('[data-cf-detail-prev]');
        var next = detailEl.querySelector('[data-cf-detail-next]');
        if (prev) prev.disabled = idx <= 0;
        if (next) next.disabled = idx < 0 || idx >= ids.length - 1;
    }
    function markActiveRow(id) {
        clearActiveRow();
        var region = currentRegion();
        if (!region) return;
        var tr = region.querySelector('tr[data-submission-id="' + id + '"]');
        if (tr) tr.classList.add('is-active');
    }
    function clearActiveRow() {
        var region = currentRegion();
        if (!region) return;
        Array.prototype.forEach.call(region.querySelectorAll('tr.is-active'), function (tr) {
            tr.classList.remove('is-active');
        });
    }
    function escAttr(s) { return String(s).replace(/"/g, '&quot;'); }
    function escHtml(s) {
        return String(s).replace(/[&<>]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
