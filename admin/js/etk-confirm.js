/* LRob Email Toolkit — shared confirm() replacement.
 *
 * `window.confirm()` is ugly, popup-blocker-prone, and breaks the
 * plugin's look-and-feel. This helper exposes a single function:
 *
 *   window.lrobEtkConfirm.prompt({ title, message, confirmLabel, danger })
 *     → Promise<boolean>
 *
 * On first call we lazily append a `.lrob-etk-modal` to <body>; later
 * calls reuse the same element. Escape, backdrop click, the X button,
 * and the Cancel footer button all resolve to `false`. Only the
 * primary action button resolves to `true`.
 *
 * Carries `lrob-etk` on the root so the plugin's `.button .dashicons`
 * fix applies inside the modal.
 */
(function (window, document) {
    'use strict';

    var el = null;

    function ensure() {
        if (el) return el;
        el = document.createElement('div');
        el.className = 'lrob-etk lrob-etk-modal lrob-etk-confirm-prompt';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.hidden = true;
        el.innerHTML = ''
            + '<div class="lrob-etk-modal-backdrop" data-modal-close></div>'
            + '<div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--small">'
            +   '<header class="lrob-etk-modal-header">'
            +     '<h3 class="lrob-etk-modal-title-text" data-confirm-title></h3>'
            +     '<button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="Close">'
            +       '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>'
            +     '</button>'
            +   '</header>'
            +   '<div class="lrob-etk-modal-body">'
            +     '<p class="lrob-etk-confirm-prompt-message" data-confirm-message></p>'
            +   '</div>'
            +   '<footer class="lrob-etk-modal-footer">'
            +     '<button type="button" class="button" data-confirm-cancel></button>'
            +     '<button type="button" class="button button-primary" data-confirm-ok></button>'
            +   '</footer>'
            + '</div>';
        document.body.appendChild(el);
        return el;
    }

    function open(node) {
        node.hidden = false;
        node.classList.add('is-open');
        document.body.classList.add('lrob-etk-modal-open');
    }
    function close(node) {
        node.hidden = true;
        node.classList.remove('is-open');
        document.body.classList.remove('lrob-etk-modal-open');
    }

    function prompt(opts) {
        opts = opts || {};
        var node = ensure();
        var titleEl = node.querySelector('[data-confirm-title]');
        var msgEl   = node.querySelector('[data-confirm-message]');
        var okBtn   = node.querySelector('[data-confirm-ok]');
        var cancel  = node.querySelector('[data-confirm-cancel]');
        if (titleEl) titleEl.textContent = opts.title || 'Confirm';
        if (msgEl)   msgEl.textContent   = opts.message || '';
        if (okBtn)   okBtn.textContent   = opts.confirmLabel || 'Confirm';
        if (cancel)  cancel.textContent  = opts.cancelLabel || 'Cancel';
        if (okBtn) {
            okBtn.classList.remove('button-primary', 'lrob-etk-btn--danger-solid');
            okBtn.classList.add(opts.danger ? 'lrob-etk-btn--danger-solid' : 'button-primary');
        }
        open(node);
        // Focus the cancel button so an accidental Enter doesn't confirm.
        if (cancel) cancel.focus();

        return new Promise(function (resolve) {
            var done = false;
            function finish(result) {
                if (done) return;
                done = true;
                if (okBtn)  okBtn.removeEventListener('click', onOk);
                node.removeEventListener('click', onClose);
                document.removeEventListener('keydown', onKey);
                close(node);
                resolve(result);
            }
            function onOk() { finish(true); }
            function onClose(e) {
                if (e.target === okBtn || (okBtn && okBtn.contains(e.target))) return;
                if (e.target.closest && e.target.closest('[data-modal-close], [data-confirm-cancel]')) {
                    finish(false);
                }
            }
            function onKey(e) {
                if (e.key === 'Escape') { e.preventDefault(); finish(false); }
                else if (e.key === 'Enter' && document.activeElement === okBtn) { e.preventDefault(); finish(true); }
            }
            if (okBtn) okBtn.addEventListener('click', onOk);
            node.addEventListener('click', onClose);
            document.addEventListener('keydown', onKey);
        });
    }

    // Generic intercept for server-rendered forms that used to rely on
    // `onsubmit="return confirm(...)"`. Mark the form with
    // [data-etk-confirm-form] and the user gets the styled prompt instead
    // of the browser dialog. Three attrs read from the form:
    //   data-confirm-title    (modal header)
    //   data-confirm-message  (the question)
    //   data-confirm-label    (text on the primary action)
    document.addEventListener('submit', function (e) {
        var form = e.target.closest && e.target.closest('[data-etk-confirm-form]');
        if (!form || form.__etkConfirmed) return;
        e.preventDefault();
        prompt({
            title:        form.getAttribute('data-confirm-title')   || '',
            message:      form.getAttribute('data-confirm-message') || '',
            confirmLabel: form.getAttribute('data-confirm-label')   || '',
            danger:       true,
        }).then(function (ok) {
            if (!ok) return;
            form.__etkConfirmed = true;
            form.submit();
        });
    }, true);

    window.lrobEtkConfirm = { prompt: prompt };
})(window, document);
