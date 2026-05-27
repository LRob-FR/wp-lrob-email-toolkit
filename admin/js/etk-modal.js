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
 * No CSS belongs here — the `.lrob-etk-modal` chrome lives in
 * admin-components.css. This file is JS plumbing only.
 */
(function () {
    'use strict';

    function bindHeader(modalId, openerId) {
        var modal = document.getElementById(modalId);
        var openBtn = document.getElementById(openerId);
        if (!modal || !openBtn) return;

        function open()  { modal.hidden = false; document.body.style.overflow = 'hidden'; }
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

    window.lrobEtkModal = { bindHeader: bindHeader };
})();
