/* LRob Email Toolkit — shared sortable-column helper.
 *
 * Wires clicks on `<th data-sort-key="...">` cells inside a list-region
 * to cycle the sort state: asc → desc → (unsorted) → asc … The helper
 * writes hidden `orderby` + `order` inputs into the paired filter form
 * so `etk-list-filter.js` picks them up on the next reload, and
 * persists the chosen sort to a cookie so reloading the page (without a
 * URL sort param) restores the admin's last choice.
 *
 * Wire-up at page-init time:
 *
 *   var filterApi = window.lrobEtkListFilter.attach({...});
 *   window.lrobEtkSortable.attach({
 *       cookieKey:      'lrob_etk_sort_subscribers',  // unique per table
 *       formSelector:   '[data-etk-list-form]',
 *       regionSelector: '[data-etk-list-region]',
 *       filterApi:      filterApi,                    // reload trigger
 *   });
 *
 * Each `<th>` that should be sortable carries a `data-sort-key` attribute
 * matching a whitelisted column slug the server-side parse_filters knows
 * about. The helper adds `.is-sort-asc` / `.is-sort-desc` to the active
 * TH and a ▲/▼ glyph via CSS.
 */
(function (window, document) {
    'use strict';

    function attach(opts) {
        var cookieKey      = opts.cookieKey      || '';
        var formSelector   = opts.formSelector   || '[data-etk-list-form]';
        var regionSelector = opts.regionSelector || '[data-etk-list-region]';
        var filterApi      = opts.filterApi      || null;

        // On boot: if no URL sort param but the cookie has one, hydrate
        // the form's hidden inputs so the first AJAX reload (triggered
        // by any filter change or pagination click) picks it up. The
        // server's parse_filters reads $_POST so it'll be picked up.
        var urlParams = new URLSearchParams(window.location.search);
        var urlOrderby = urlParams.get('orderby') || '';
        if (!urlOrderby && cookieKey) {
            var fromCookie = readCookie(cookieKey);
            if (fromCookie) {
                var parts = fromCookie.split(':');
                if (parts.length === 2 && parts[0] && parts[1]) {
                    setHiddenInput(formSelector, 'orderby', parts[0]);
                    setHiddenInput(formSelector, 'order',   parts[1]);
                    paintHeader(regionSelector, parts[0], parts[1]);
                }
            }
        } else {
            paintHeader(regionSelector, urlOrderby, urlParams.get('order') || 'asc');
        }

        // Repaint after every AJAX region swap so the active-column
        // glyph stays in sync with the URL state.
        document.addEventListener('etk:list-region-swapped', function () {
            var p = new URLSearchParams(window.location.search);
            paintHeader(regionSelector, p.get('orderby') || '', p.get('order') || 'asc');
        });

        document.addEventListener('click', function (e) {
            var th = e.target.closest && e.target.closest('th[data-sort-key]');
            if (!th) return;
            var region = document.querySelector(regionSelector);
            if (!region || !region.contains(th)) return;
            e.preventDefault();

            var key = th.getAttribute('data-sort-key') || '';
            if (!key) return;

            var form = document.querySelector(formSelector);
            var currentOrderby = form ? readFormValue(form, 'orderby') : '';
            var currentOrder   = form ? readFormValue(form, 'order')   : '';
            var nextKey, nextOrder;
            if (currentOrderby !== key) {
                nextKey = key; nextOrder = 'asc';
            } else if (currentOrder === 'asc') {
                nextKey = key; nextOrder = 'desc';
            } else {
                // desc → clear sort
                nextKey = ''; nextOrder = '';
            }
            setHiddenInput(formSelector, 'orderby', nextKey);
            setHiddenInput(formSelector, 'order',   nextOrder);
            saveCookie(cookieKey, nextKey, nextOrder);
            paintHeader(regionSelector, nextKey, nextOrder);
            if (filterApi && typeof filterApi.reload === 'function') {
                filterApi.reload(1);
            } else if (form) {
                // No filter helper attached — submit the form for a full reload.
                form.submit();
            }
        });
    }

    function readFormValue(form, name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? String(el.value || '') : '';
    }
    function setHiddenInput(formSel, name, value) {
        var form = document.querySelector(formSel);
        if (!form) return;
        var existing = form.querySelector('input[name="' + name + '"]');
        if (!existing) {
            existing = document.createElement('input');
            existing.type = 'hidden';
            existing.name = name;
            form.appendChild(existing);
        }
        existing.value = value || '';
    }
    function paintHeader(regionSel, key, order) {
        var region = document.querySelector(regionSel);
        if (!region) return;
        var ths = region.querySelectorAll('th[data-sort-key]');
        Array.prototype.forEach.call(ths, function (th) {
            th.classList.remove('is-sort-asc', 'is-sort-desc');
            if (key && th.getAttribute('data-sort-key') === key) {
                th.classList.add(order === 'desc' ? 'is-sort-desc' : 'is-sort-asc');
            }
        });
    }

    function readCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }
    function saveCookie(name, key, order) {
        if (!name) return;
        var value = (key && order) ? key + ':' + order : '';
        var expires = new Date(Date.now() + 365 * 86400000).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value) + ';path=/;expires=' + expires + ';SameSite=Lax';
    }

    window.lrobEtkSortable = { attach: attach };
})(window, document);
