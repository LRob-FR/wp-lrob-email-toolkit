/**
 * Shared modal opener — drives every header-button-triggered .lrob-etk-modal
 * across the plugin (CF Defaults, CF Storage, Logs Storage, future
 * Newsletter modals).
 *
 * Contract:
 *   - The modal element is .lrob-etk-modal with id=<modalId>, normally
 *     `hidden` until opened.
 *   - The opener is a button/anchor with id=<openerId>.
 *   - Inside the modal, any element carrying `data-modal-close` (typically
 *     the × button + the backdrop) closes the dialog when clicked.
 *   - Escape closes the dialog while it's open.
 *   - Body scroll is locked while the dialog is open so the page behind
 *     doesn't drift under the cursor.
 *
 * Save feedback inside modals:
 *   - Every `.lrob-etk-modal-header` automatically gets a status badge
 *     injected on first interaction (next to the close button).
 *   - Any code can dispatch `CustomEvent('lrob-etk:save-status', { detail:
 *     { state, source }})` on its element — the bubble walks up to the
 *     nearest modal and reflects the state on the header badge.
 *   - States: `saving`, `saved`, `error` (with optional `message`).
 *
 * No CSS belongs here — the `.lrob-etk-modal` chrome lives in
 * admin-components.css. This file is JS plumbing only.
 */
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

    // Listen plugin-wide for save-status events and reflect them on the
    // closest enclosing modal's header badge. Lets ad-hoc save handlers
    // (per-key autosave, rule-save, etc.) emit a single event and not
    // worry about discovering / styling the badge themselves.
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

    // Helper for ad-hoc save handlers: pass any element inside a modal
    // plus a state and the badge updates. Returns silently if outside a
    // modal — same handler can be wired from page + modal contexts.
    function reportSave(sourceEl, state, message) {
        if (!sourceEl || !sourceEl.dispatchEvent) return;
        sourceEl.dispatchEvent(new CustomEvent('lrob-etk:save-status', {
            bubbles: true,
            detail: { state: state, message: message || '' },
        }));
    }

    window.lrobEtkModal = { bindHeader: bindHeader, reportSave: reportSave };
})();
