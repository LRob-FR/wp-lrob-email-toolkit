/* Docs: docs/newsletter-internals.md → "audience picker" */
(function () {
    if (window.__lrobEtkAudiencePickerBound) return;
    window.__lrobEtkAudiencePickerBound = true;

    function readPickedIds(picker) {
        var ids = [];
        picker.querySelectorAll('[data-audience-list]:checked').forEach(function (cb) {
            var v = parseInt(cb.getAttribute('data-audience-list'), 10) || 0;
            if (v > 0) ids.push(v);
        });
        return ids;
    }

    function updateSummary(picker) {
        var summaryEl = picker.querySelector('[data-audience-lists-summary]');
        if (!summaryEl) return;
        var checked = picker.querySelectorAll('[data-audience-list]:checked');
        if (checked.length === 0) {
            summaryEl.textContent = picker.getAttribute('data-audience-empty-label') || '';
            return;
        }
        var names = [];
        Array.prototype.forEach.call(checked, function (cb) {
            var label = cb.closest('label');
            if (!label) return;
            var nameEl = label.querySelector('.lrob-etk-nl-audience-item-name');
            if (nameEl) names.push(nameEl.textContent.trim());
        });
        summaryEl.textContent = names.join(', ');
    }

    function persistAudience(picker) {
        var action  = picker.getAttribute('data-audience-action') || '';
        var key     = picker.getAttribute('data-audience-key') || '';
        var idParam = picker.getAttribute('data-audience-id-param') || '';
        var idValue = picker.getAttribute('data-audience-id') || '';
        var nonce   = picker.getAttribute('data-audience-nonce') || '';
        var url     = picker.getAttribute('data-audience-ajax-url') || '';
        if (!action || !key || !idParam || !idValue || !nonce || !url) return;

        var ids = readPickedIds(picker);
        picker.dispatchEvent(new CustomEvent('lrob-etk:save-status', {
            bubbles: true,
            detail: { state: 'saving' }
        }));
        var fd = new FormData();
        fd.append('action', action);
        fd.append('_nonce', nonce);
        fd.append(idParam, idValue);
        fd.append('key', key);
        fd.append('value', ids.join(','));
        fetch(url, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
            .then(function (resp) {
                picker.dispatchEvent(new CustomEvent('lrob-etk:save-status', {
                    bubbles: true,
                    detail: { state: (resp && resp.success) ? 'saved' : 'error' }
                }));
                // Optional downstream event — newsletter cards listen
                // for `lrob-etk-nl-saved` to refresh recipient counts.
                var eventName = picker.getAttribute('data-audience-saved-event');
                if (eventName) {
                    var detailIdKey = picker.getAttribute('data-audience-saved-id-key') || 'id';
                    var detail = { key: key };
                    detail[detailIdKey] = idValue;
                    document.dispatchEvent(new CustomEvent(eventName, { detail: detail }));
                }
            });
    }

    function closeAllMenus() {
        document.querySelectorAll('[data-audience-menu]').forEach(function (m) {
            m.setAttribute('hidden', '');
        });
        document.querySelectorAll('[data-audience-toggle]').forEach(function (t) {
            t.setAttribute('aria-expanded', 'false');
        });
    }

    // Toggle open / close on trigger click; outside-click + Escape
    // close every open menu. Multiple pickers per page coexist
    // because every handler is scoped to the trigger's picker.
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest && e.target.closest('[data-audience-toggle]');
        if (trigger) {
            var picker = trigger.closest('[data-audience-picker]');
            if (!picker) return;
            var menu = picker.querySelector('[data-audience-menu]');
            if (!menu) return;
            var willOpen = menu.hasAttribute('hidden');
            closeAllMenus();
            if (willOpen) {
                menu.removeAttribute('hidden');
                trigger.setAttribute('aria-expanded', 'true');
            }
            return;
        }
        if (!e.target.closest('[data-audience-menu]')) {
            closeAllMenus();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllMenus();
    });

    document.addEventListener('change', function (e) {
        var cb = e.target.closest && e.target.closest('[data-audience-list]');
        if (!cb) return;
        var picker = cb.closest('[data-audience-picker]');
        if (!picker) return;
        updateSummary(picker);
        persistAudience(picker);
    });
})();
