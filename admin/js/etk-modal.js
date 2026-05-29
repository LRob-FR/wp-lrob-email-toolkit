/* Docs: docs/admin-ui.md */
(function () {
    'use strict';

    var I18N = (window.lrobEtkModalI18n || {});
    var TEXT_SAVING = I18N.saving || 'Saving…';
    var TEXT_SAVED  = I18N.saved  || 'Saved';
    var TEXT_ERROR  = I18N.error  || 'Save failed';

    function ensureHeaderStatus(modal) {
        var header = modal.querySelector('.lrob-etk-modal-header');
        if (!header) return null;
        var status = header.querySelector('[data-modal-status]');
        if (status) return status;
        status = document.createElement('span');
        status.className = 'lrob-etk-modal-header-status';
        status.setAttribute('data-modal-status', '');
        status.setAttribute('aria-live', 'polite');
        // Insert before the close button if present, else append.
        var closeBtn = header.querySelector('.lrob-etk-modal-close');
        if (closeBtn) header.insertBefore(status, closeBtn);
        else header.appendChild(status);
        return status;
    }

    function setStatus(status, state, message) {
        if (!status) return;
        status.className = 'lrob-etk-modal-header-status is-' + state;
        status.textContent = (state === 'saving' ? TEXT_SAVING
                            : state === 'saved'  ? '✓ ' + TEXT_SAVED
                            : '✗ ' + (message || TEXT_ERROR));
        if (state === 'saved') {
            clearTimeout(status.__etkFade);
            status.__etkFade = setTimeout(function () {
                status.className = 'lrob-etk-modal-header-status';
                status.textContent = '';
            }, 2000);
        }
    }

    // Plugin-wide save-status listener — reflects on the nearest modal header badge.
    document.addEventListener('lrob-etk:save-status', function (e) {
        var source = e.target instanceof Element ? e.target : null;
        if (!source) return;
        var modal = source.closest('.lrob-etk-modal');
        if (!modal) return;
        var detail = e.detail || {};
        var status = ensureHeaderStatus(modal);
        setStatus(status, detail.state || 'saved', detail.message || '');
    });

    function bindHeader(modalId, openerId) {
        var modal = document.getElementById(modalId);
        var openBtn = document.getElementById(openerId);
        if (!modal || !openBtn) return;

        function open()  { modal.hidden = false; document.body.style.overflow = 'hidden'; ensureHeaderStatus(modal); }
        function close() { modal.hidden = true;  document.body.style.overflow = ''; }

        openBtn.addEventListener('click', open);
        modal.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('[data-modal-close]')) close();
        });
        document.addEventListener('keydown', function (e) {
            if (!modal.hidden && e.key === 'Escape') close();
        });

        return { open: open, close: close };
    }

    // Ad-hoc helper: dispatches the save-status event; returns silently outside modals.
    function reportSave(sourceEl, state, message) {
        if (!sourceEl || !sourceEl.dispatchEvent) return;
        sourceEl.dispatchEvent(new CustomEvent('lrob-etk:save-status', {
            bubbles: true,
            detail: { state: state, message: message || '' },
        }));
    }

    window.lrobEtkModal = { bindHeader: bindHeader, reportSave: reportSave };
})();
