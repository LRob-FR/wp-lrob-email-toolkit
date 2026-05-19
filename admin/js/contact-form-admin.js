/* LRob Email Toolkit — Contact Form admin page
 *
 * Auto-save for every input inside a .lrob-etk-cf-form-card. Each field has a
 * data-key attribute that tells the server which setting to update. Saves
 * debounce on text inputs (after typing pauses + on blur), fire immediately
 * on selects, and surface a small per-card status indicator.
 *
 * Vanilla JS, no deps. Matches the SMTP card pattern in spirit but is its
 * own implementation — the server endpoint is simpler (single meta save).
 */
(function () {
    'use strict';

    var DATA = window.lrobEtkCfAdmin || {};
    var I18N = DATA.i18n || {};
    var TYPING_DEBOUNCE_MS = 600;

    function init() {
        var cards = document.querySelectorAll('.lrob-etk-cf-form-card');
        Array.prototype.forEach.call(cards, bindCard);
    }

    function bindCard(card) {
        if (card.__lrobEtkCfBound) return;
        card.__lrobEtkCfBound = true;

        var formId = parseInt(card.getAttribute('data-form-id'), 10) || 0;
        if (!formId) return;

        var status = card.querySelector('.lrob-etk-card-status');
        var fields = card.querySelectorAll('.lrob-etk-cf-field');
        var typingTimers = new WeakMap();
        var lastSent = new WeakMap();

        function readValue(field) {
            // Rate-limit window UI is in minutes; storage is seconds.
            if (field.dataset.unit === 'minutes') {
                var n = parseInt(field.value, 10);
                if (!n || n <= 0) return 0;
                return n * 60;
            }
            return field.value;
        }

        function save(field) {
            var key = field.dataset.key;
            if (!key) return;
            var value = readValue(field);
            var serialized = String(value);
            if (lastSent.get(field) === serialized) return;
            lastSent.set(field, serialized);

            setStatus('saving');

            var fd = new FormData();
            fd.append('action', DATA.action);
            fd.append('_nonce', DATA.nonce);
            fd.append('form_id', String(formId));
            fd.append('key', key);
            fd.append('value', serialized);

            fetch(DATA.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            })
                .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                .then(function (resp) {
                    if (resp && resp.success) {
                        setStatus('saved');
                    } else {
                        setStatus('error', (resp && resp.data && resp.data.message) || '');
                        lastSent.delete(field); // let user retry
                    }
                })
                .catch(function () {
                    setStatus('error');
                    lastSent.delete(field);
                });
        }

        function setStatus(state, detail) {
            if (!status) return;
            status.classList.remove('is-saving', 'is-saved', 'is-error');
            if (state === 'saving') {
                status.classList.add('is-saving');
                status.textContent = I18N.saving || 'Saving…';
            } else if (state === 'saved') {
                status.classList.add('is-saved');
                status.textContent = I18N.saved || 'Saved';
                clearTimeout(status.__hideTimer);
                status.__hideTimer = setTimeout(function () {
                    status.classList.remove('is-saved');
                    status.textContent = '';
                }, 1400);
            } else if (state === 'error') {
                status.classList.add('is-error');
                status.textContent = detail ? (I18N.error + ': ' + detail) : (I18N.error || 'Save failed');
            }
        }

        Array.prototype.forEach.call(fields, function (field) {
            // Initialize "what's already on the server" so we don't refire an
            // identical save when blur happens without a real change.
            lastSent.set(field, String(readValue(field)));

            var tag = field.tagName.toLowerCase();
            var type = (field.type || '').toLowerCase();
            var isText = tag === 'textarea' || (tag === 'input' && ['text', 'email', 'number', 'tel', 'url', 'search'].indexOf(type) !== -1);

            if (isText) {
                field.addEventListener('input', function () {
                    clearTimeout(typingTimers.get(field));
                    typingTimers.set(field, setTimeout(function () { save(field); }, TYPING_DEBOUNCE_MS));
                });
                field.addEventListener('blur', function () {
                    clearTimeout(typingTimers.get(field));
                    save(field);
                });
            } else {
                // selects, checkboxes, etc. — save immediately.
                field.addEventListener('change', function () { save(field); });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
