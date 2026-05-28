/* LRob Email Toolkit — per-page picker glue.
 *
 * Pairs with the server-side `Admin\PerPagePicker`. Wires changes on a
 * `<select data-per-page="<slug>">` to:
 *   1. write a session cookie (`lrob_etk_per_page_<slug>=<n>`),
 *   2. mirror the new value into a hidden `per_page` input in the
 *      paired filter form so `etk-list-filter` picks it up on reload,
 *   3. trigger the next AJAX reload (page 1) via the filter helper, or
 *      fall back to a full form submit when no filter helper is wired.
 *
 * Each consumer page calls:
 *   window.lrobEtkPerPage.attach({
 *       slug:         'subscribers',
 *       formSelector: '[data-etk-list-form]',
 *       filterApi:    filterApi,
 *   });
 */
(function (window, document) {
    'use strict';

    function attach(opts) {
        var slug = opts.slug || '';
        if (!slug) return;
        var formSelector = opts.formSelector || '[data-etk-list-form]';
        var filterApi = opts.filterApi || null;
        var cookieName = 'lrob_etk_per_page_' + slug;
        var attrSelector = '[data-per-page="' + slug + '"]';

        document.addEventListener('change', function (e) {
            var sel = e.target.closest && e.target.closest(attrSelector);
            if (!sel) return;
            var n = parseInt(sel.value, 10);
            if (!n || n < 5 || n > 500) return;
            // Session cookie — no `expires` → drops when the browser closes.
            document.cookie = cookieName + '=' + n + ';path=/;SameSite=Lax';
            var form = document.querySelector(formSelector);
            if (form) {
                var hidden = form.querySelector('input[name="per_page"]');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'per_page';
                    form.appendChild(hidden);
                }
                hidden.value = String(n);
            }
            if (filterApi && typeof filterApi.reload === 'function') {
                filterApi.reload(1);
            } else if (form) {
                form.submit();
            }
        });
    }

    window.lrobEtkPerPage = { attach: attach };
})(window, document);
