/* Docs: docs/admin-ui.md */
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
            input.addEventListener('input', debounced);
            input.addEventListener('change', debounced);
        });

        // Intercept Enter to avoid a full page reload.
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearTimeout(typingTimer);
            reload(1);
        });

        // Optional Reset link — clear inputs without a page reload.
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

        // Back/forward: restore inputs from URL and refetch without pushing a new history entry.
        window.addEventListener('popstate', function () {
            var params = new URLSearchParams(window.location.search);
            Array.prototype.forEach.call(inputs, function (input) {
                if (input.type === 'hidden') return;
                var v = params.get(input.name);
                input.value = v || '';
            });
            reload(parseInt(params.get('paged') || '1', 10) || 1, true);
        });

        // Intercept pagination links inside the region.
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
            // Both nonce names: _ajax_nonce (WP standard) + _nonce (Newsletter guard).
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
                        // Signal for region-local chrome (sortable headers, etc.) to reinitialize.
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
