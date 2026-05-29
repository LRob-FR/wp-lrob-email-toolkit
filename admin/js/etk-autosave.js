/* Docs: docs/admin-ui.md */
(function () {
    'use strict';

    var TEXT_TYPES = ['text', 'email', 'number', 'tel', 'url', 'search'];

    function attach(card, opts) {
        if (!card || card.__lrobEtkAutosaveBound) return;
        card.__lrobEtkAutosaveBound = true;

        var fieldSelector = opts.fieldSelector;
        var save = opts.save;
        var readValue = opts.readValue || function (f) { return f.value; };
        var i18n = opts.i18n || {};
        var debounceMs = typeof opts.debounceMs === 'number' ? opts.debounceMs : 600;

        if (!fieldSelector || typeof save !== 'function') return;

        var status = card.querySelector('.lrob-etk-card-status');
        var fields = card.querySelectorAll(fieldSelector);
        var typingTimers = new WeakMap();
        var lastSent = new WeakMap();

        function setStatus(state, detail) {
            // Bubble save-status so enclosing modal can mirror it (etk-modal.js listens).
            if (card && card.dispatchEvent) {
                card.dispatchEvent(new CustomEvent('lrob-etk:save-status', {
                    bubbles: true,
                    detail: { state: state, message: detail || '' },
                }));
            }
            if (!status) return;
            status.classList.remove('is-saving', 'is-saved', 'is-error');
            if (state === 'saving') {
                status.classList.add('is-saving');
                status.textContent = i18n.saving || 'Saving…';
            } else if (state === 'saved') {
                status.classList.add('is-saved');
                status.textContent = i18n.saved || 'Saved';
                clearTimeout(status.__hideTimer);
                status.__hideTimer = setTimeout(function () {
                    status.classList.remove('is-saved');
                    status.textContent = '';
                }, 1400);
            } else if (state === 'error') {
                status.classList.add('is-error');
                var base = i18n.error || 'Save failed';
                status.textContent = detail ? (base + ': ' + detail) : base;
            }
        }

        function flush(field) {
            var value = readValue(field);
            var serialized = String(value);
            if (lastSent.get(field) === serialized) return;
            lastSent.set(field, serialized);

            setStatus('saving');
            Promise.resolve(save(field, value))
                .then(function (resp) {
                    if (resp && resp.success) {
                        setStatus('saved');
                    } else {
                        setStatus('error', (resp && resp.data && resp.data.message) || '');
                        lastSent.delete(field);
                    }
                })
                .catch(function () {
                    setStatus('error');
                    lastSent.delete(field);
                });
        }

        Array.prototype.forEach.call(fields, function (field) {
            // Seed lastSent so initial blur doesn't fire a no-op save.
            lastSent.set(field, String(readValue(field)));

            // .lrob-etk-default-hint sibling hides as soon as the user types.
            var hintEl = field.nextElementSibling;
            if (!hintEl || !hintEl.classList || !hintEl.classList.contains('lrob-etk-default-hint')) {
                hintEl = null;
            }
            function syncHint() {
                if (!hintEl) return;
                hintEl.hidden = String(field.value || '').trim() !== '';
            }
            syncHint();
            field.addEventListener('input', syncHint);

            var tag = field.tagName.toLowerCase();
            var type = (field.type || '').toLowerCase();
            var isHidden = type === 'hidden';
            var isText = tag === 'textarea'
                || (tag === 'input' && TEXT_TYPES.indexOf(type) !== -1);

            if (isHidden) {
                // Hidden mirror inputs (combobox, recipient list, retention toggle) fire 'change'.
                field.addEventListener('change', function () { flush(field); });
            } else if (isText && debounceMs > 0) {
                field.addEventListener('input', function () {
                    clearTimeout(typingTimers.get(field));
                    typingTimers.set(field, setTimeout(function () { flush(field); }, debounceMs));
                });
                field.addEventListener('blur', function () {
                    clearTimeout(typingTimers.get(field));
                    flush(field);
                });
            } else {
                // Selects, checkboxes, etc. — save immediately on change.
                field.addEventListener('change', function () { flush(field); });
            }
        });

        return { flush: flush, setStatus: setStatus };
    }

    window.lrobEtkAutosave = { attach: attach };
})();
