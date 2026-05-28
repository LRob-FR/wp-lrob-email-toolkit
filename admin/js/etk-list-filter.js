/* LRob Email Toolkit — shared list-page filter helper.
 *
 * Bound to any admin list page that pairs a filter <form> with a
 * swap-able list region. Wires the form inputs (search, selects, dates)
 * to a debounced AJAX reload that replaces the region's HTML in place,
 * keeps the browser URL in sync via history.replaceState, intercepts
 * pagination links to keep them on the AJAX path, and handles back /
 * forward via popstate.
 *
 * Used by:
 *   - Contact Form submissions inbox  (contact-form-submissions-inbox.js)
 *   - Email Logs list                  (email-logs-list.js)
 *
 * Both pages mark up the same way:
 *   <form data-etk-list-form ...>
 *   <div data-etk-list-region>...</div>
 *
 * Bulk actions, row actions, detail modals stay page-specific — the
 * helper only owns the filter ⇄ region-swap loop.
 */
(function (window, document) {
    'use strict';

    function attach(opts) {
        var formSelector   = opts.formSelector   || '[data-etk-list-form]';
        var regionSelector = opts.regionSelector || '[data-etk-list-region]';
        var ajaxUrl        = opts.ajaxUrl        || '';
        var nonce          = opts.nonce          || '';
        var action         = opts.action         || '';
        var onAfterReload  = typeof opts.onAfterReload === 'function' ? opts.onAfterReload : null;
        var typingDelay    = typeof opts.typingDelay === 'number' ? opts.typingDelay : 300;

        var form = document.querySelector(formSelector);
        if (!form || !document.querySelector(regionSelector)) {
            return null;
        }

        function currentRegion() {
            return document.querySelector(regionSelector);
        }

        var inputs = form.querySelectorAll('input, select');
        var typingTimer = null;

        Array.prototype.forEach.call(inputs, function (input) {
            if (input.type === 'hidden') return;
            var debounced = function () {
                clearTimeout(typingTimer);
                typingTimer = setTimeout(function () { reload(1); }, typingDelay);
            };
            // Listen to both events so a paste, a clear, or a select all
            // converge on the same debounced reload.
            input.addEventListener('input', debounced);
            input.addEventListener('change', debounced);
        });

        // Hitting Enter inside any input would otherwise submit the form
        // and trigger a full page reload — intercept and route to AJAX.
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearTimeout(typingTimer);
            reload(1);
        });

        // Optional Reset link — when present, hijack to clear the inputs
        // without a page reload.
        var reset = form.querySelector('[data-etk-list-reset], .lrob-etk-cf-reset-filters');
        if (reset) {
            reset.addEventListener('click', function (e) {
                e.preventDefault();
                Array.prototype.forEach.call(inputs, function (input) {
                    if (input.type === 'hidden') return;
                    if (input.tagName === 'SELECT') input.selectedIndex = 0;
                    else input.value = '';
                });
                reset.hidden = true;
                reload(1);
            });
        }

        // Browser navigation (back/forward): restore inputs from the URL
        // and refetch with skipUrlUpdate so we don't push a new entry.
        window.addEventListener('popstate', function () {
            var params = new URLSearchParams(window.location.search);
            Array.prototype.forEach.call(inputs, function (input) {
                if (input.type === 'hidden') return;
                var v = params.get(input.name);
                input.value = v || '';
            });
            reload(parseInt(params.get('paged') || '1', 10) || 1, true);
        });

        // Pagination links inside the region: intercept and AJAX-load
        // the requested page instead of a full reload.
        document.addEventListener('click', function (e) {
            var region = currentRegion();
            if (!region) return;
            var link = e.target.closest && e.target.closest('a.page-numbers, a.prev, a.next');
            if (!link || !region.contains(link) || link.classList.contains('current')) return;
            e.preventDefault();
            var u = new URL(link.href, window.location.origin);
            var page = parseInt(u.searchParams.get('paged') || '1', 10) || 1;
            reload(page);
        });

        function reload(page, skipUrlUpdate) {
            var region = currentRegion();
            if (!region) return Promise.resolve();

            var fd = new FormData(form);
            fd.append('action', action);
            // Two nonce field names sent so the helper works with both
            // check_ajax_referer (falls back to `_ajax_nonce`) and the
            // stricter Newsletter `$_POST['_nonce']` guard.
            fd.append('_ajax_nonce', nonce);
            fd.append('_nonce', nonce);
            fd.append('paged', String(page));

            if (!skipUrlUpdate) {
                updateUrl(form, page);
            }
            region.classList.add('is-loading');

            return fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
            })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (!resp || !resp.success || !resp.data || typeof resp.data.html !== 'string') {
                        throw new Error('bad-response');
                    }
                    var tmp = document.createElement('div');
                    tmp.innerHTML = resp.data.html;
                    var next = tmp.firstElementChild;
                    var live = currentRegion();
                    if (next && live && live.parentNode) {
                        live.parentNode.replaceChild(next, live);
                        if (onAfterReload) onAfterReload(next);
                        // Fan-out signal for table chrome that lives
                        // inside the region (sortable headers, etc.) —
                        // the swap nukes their DOM refs, so they
                        // re-paint on this event instead.
                        document.dispatchEvent(new CustomEvent('etk:list-region-swapped', {
                            detail: { region: next },
                        }));
                    }
                })
                .catch(function () {
                    if (window.console && console.warn) {
                        console.warn('etk-list-filter: reload failed');
                    }
                })
                .finally(function () {
                    var live = currentRegion();
                    if (live) live.classList.remove('is-loading');
                });
        }

        function updateUrl(form, page) {
            var fd = new FormData(form);
            var params = new URLSearchParams();
            fd.forEach(function (value, key) {
                if (value === '' || value === null) return;
                params.set(key, String(value));
            });
            if (page > 1) params.set('paged', String(page));
            var url = window.location.pathname + '?' + params.toString();
            try {
                window.history.replaceState({}, '', url);
            } catch (e) {
                // older browsers — silently no-op.
            }
        }

        return { reload: reload, currentRegion: currentRegion };
    }

    window.lrobEtkListFilter = { attach: attach };
})(window, document);
