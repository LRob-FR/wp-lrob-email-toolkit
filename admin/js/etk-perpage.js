/* Docs: docs/admin-ui.md */
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
            // Session cookie (no expires) → drops when browser closes.
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
