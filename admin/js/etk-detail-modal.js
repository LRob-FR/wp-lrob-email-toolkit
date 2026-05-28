/* LRob Email Toolkit — shared list-page detail modal.
 *
 * Generic overlay opened by clicking a row trigger on any admin list
 * page (Contact Form submissions, Email Logs, …). Provides:
 *   - fixed-top layout (so prev/next stay under the cursor between
 *     submissions of different heights)
 *   - no-flicker body swap (current content stays visible, soft dim
 *     while the next is in flight; "Loading…" only on first open)
 *   - prev/next nav via ◀/▶ buttons + ← / → keyboard shortcuts
 *   - ESC + backdrop click closes
 *   - configurable header action buttons (page passes its own HTML +
 *     handler) — used for Spam/Restore/Delete (submissions) and
 *     Resend/Delete (logs).
 *
 * Carries `lrob-etk` on the root so the plugin's `.button .dashicons`
 * fix in admin-base.css applies to the modal's buttons too — they're
 * appended to <body>, outside the page's normal wrap.
 *
 * Usage:
 *   var modal = window.lrobEtkDetailModal.create({
 *       fetcher: function(id) { return Promise<{title, html, status, ...}> },
 *       actionsHtml: '<button data-action="delete">Delete</button>…',
 *       afterFetch: function(modal, resp) { ... refresh action buttons ... },
 *       onAction: function(actionKey, id, modal) { ... },
 *       getVisibleIds: function() { return [1, 4, 7]; },  // for prev/next
 *       i18n: { prev, next, close, loading, error }
 *   });
 *   triggerEl.addEventListener('click', function() { modal.open(id); });
 */
(function (window, document) {
    'use strict';

    function create(config) {
        config = config || {};
        var I18N = config.i18n || {};
        var fetcher       = config.fetcher       || function () { return Promise.reject(new Error('no fetcher')); };
        var actionsHtml   = config.actionsHtml   || '';
        var afterFetch    = config.afterFetch    || null;
        var onAction      = config.onAction      || null;
        var getVisibleIds = config.getVisibleIds || function () { return []; };
        var className     = (config.className || '') + ' lrob-etk lrob-etk-confirm-modal lrob-etk-detail-modal';

        var el = null;
        // Init to '' rather than 0 so composite-string ids work too.
        var currentId = '';

        function ensure() {
            if (el) return el;
            el = document.createElement('div');
            el.className = className;
            el.setAttribute('role', 'dialog');
            el.setAttribute('aria-modal', 'true');
            el.innerHTML = ''
                + '<div class="lrob-etk-confirm-modal-backdrop" data-cf-modal-close></div>'
                + '<div class="lrob-etk-detail-modal-panel" role="document">'
                +   '<header class="lrob-etk-detail-modal-header">'
                +     '<h2 class="lrob-etk-detail-modal-title" data-cf-detail-title></h2>'
                +     '<div class="lrob-etk-detail-modal-controls">'
                +       '<span class="lrob-etk-detail-modal-actions" data-cf-detail-actions>' + (typeof actionsHtml === 'function' ? actionsHtml() : actionsHtml) + '</span>'
                +       (actionsHtml ? '<span class="lrob-etk-detail-modal-controls-spacer" aria-hidden="true"></span>' : '')
                +       '<button type="button" class="lrob-etk-detail-modal-nav" data-cf-detail-prev aria-label="' + escAttr(I18N.prev || 'Previous') + '">'
                +         '<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>'
                +       '</button>'
                +       '<button type="button" class="lrob-etk-detail-modal-nav" data-cf-detail-next aria-label="' + escAttr(I18N.next || 'Next') + '">'
                +         '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>'
                +       '</button>'
                +       '<button type="button" class="lrob-etk-detail-modal-close" data-cf-modal-close aria-label="' + escAttr(I18N.close || 'Close') + '">'
                +         '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>'
                +       '</button>'
                +     '</div>'
                +   '</header>'
                +   '<div class="lrob-etk-detail-modal-body" data-cf-detail-body></div>'
                + '</div>';
            document.body.appendChild(el);

            el.addEventListener('click', function (e) {
                if (e.target.closest('[data-cf-modal-close]')) { close(); return; }
                if (e.target.closest('[data-cf-detail-prev]')) { e.preventDefault(); step(-1); return; }
                if (e.target.closest('[data-cf-detail-next]')) { e.preventDefault(); step(+1); return; }
                var actBtn = e.target.closest('[data-cf-detail-action]');
                if (actBtn && onAction && currentId > 0) {
                    e.preventDefault();
                    var key = actBtn.getAttribute('data-cf-detail-action');
                    // Optional override: button can carry data-cf-current-op to
                    // override the original action key when toggled (e.g.
                    // spam ↔ unspam toggles via the same button).
                    var opOverride = actBtn.getAttribute('data-cf-current-op');
                    onAction(opOverride || key, currentId, api);
                }
            });
            document.addEventListener('keydown', function (e) {
                if (!el.classList.contains('is-open')) return;
                if (e.key === 'Escape')    { close(); return; }
                if (e.key === 'ArrowLeft')  { step(-1); return; }
                if (e.key === 'ArrowRight') { step(+1); return; }
            });
            return el;
        }

        function open(id) {
            var m = ensure();
            m.classList.add('is-open');
            fetch_(id);
        }
        function close() {
            if (el) el.classList.remove('is-open');
            // Reset to '' (not 0) so consumers that pass composite-string
            // ids ("subscriber:42") don't trip the truthy check below.
            currentId = '';
        }
        function fetch_(id) {
            if (!el) return;
            currentId = id;
            refreshNav();

            var body  = el.querySelector('[data-cf-detail-body]');
            var title = el.querySelector('[data-cf-detail-title]');
            var firstOpen = body && body.childNodes.length === 0;
            el.classList.add('is-loading');
            if (firstOpen && body) {
                body.innerHTML = '<p class="lrob-etk-detail-modal-loading">' + escHtml(I18N.loading || 'Loading…') + '</p>';
            }

            Promise.resolve(fetcher(id))
                .then(function (resp) {
                    if (!resp || typeof resp.html !== 'string') {
                        throw new Error('bad-response');
                    }
                    if (title) title.textContent = resp.title || '';
                    if (body)  body.innerHTML = resp.html  || '';
                    if (body) body.scrollTop = 0;
                    if (typeof afterFetch === 'function') afterFetch(api, resp);
                })
                .catch(function () {
                    if (body) body.innerHTML = '<p class="lrob-etk-detail-modal-error">' + escHtml(I18N.error || 'Error') + '</p>';
                })
                .finally(function () {
                    el.classList.remove('is-loading');
                });
        }
        function step(delta) {
            var ids = getVisibleIds();
            if (ids.length === 0) return;
            var idx = ids.indexOf(currentId);
            if (idx < 0) return;
            var next = idx + delta;
            if (next < 0 || next >= ids.length) return;
            fetch_(ids[next]);
        }
        function refreshNav() {
            if (!el) return;
            var ids = getVisibleIds();
            var idx = ids.indexOf(currentId);
            var prev = el.querySelector('[data-cf-detail-prev]');
            var next = el.querySelector('[data-cf-detail-next]');
            if (prev) prev.disabled = idx <= 0;
            if (next) next.disabled = idx < 0 || idx >= ids.length - 1;
        }

        var api = {
            open: open,
            close: close,
            // `currentId` may be a number (legacy: Logs, CF inbox) OR a
            // composite string like "subscriber:42" (unified Subscribers
            // table) — truthy check covers both.
            refresh: function () { if (currentId) fetch_(currentId); },
            currentId: function () { return currentId; },
            element: function () { return el; },
        };
        return api;
    }

    function escHtml(s) {
        return String(s).replace(/[&<>]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
        });
    }
    function escAttr(s) { return String(s).replace(/"/g, '&quot;'); }

    window.lrobEtkDetailModal = { create: create };
})(window, document);
