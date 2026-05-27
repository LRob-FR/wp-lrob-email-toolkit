/**
 * Shared per-key autosave for any settings card (.lrob-etk-card-status
 * badge included).
 *
 * Each card declares its fields with a CSS class that the consuming
 * module picks (e.g. `.lrob-etk-cf-field` for Contact Form,
 * `.lrob-etk-logs-field` for Logs). Every field carries `data-key`. The
 * shared helper handles:
 *
 *   - Iterating the fields, binding the right event per input type
 *     (input + blur for text/textarea with debounce; change for
 *     selects/checkboxes/hidden mirrors).
 *   - lastSent tracking so identical blur values don't re-fire saves.
 *   - Status badge state machine (saving / saved / error) using the
 *     existing `.lrob-etk-card-status` markup.
 *
 * The consuming module supplies a `save(field)` callback that knows how
 * to send the value to its endpoint and returns a Promise resolving to
 * `{ success: bool, message?: string }`. The helper handles the rest.
 *
 * Usage:
 *   window.lrobEtkAutosave.attach(cardElement, {
 *       fieldSelector: '.lrob-etk-cf-field',
 *       readValue:     function (field) { return field.value; }, // optional
 *       save:          function (field, value) {
 *           return fetch(ajaxUrl, {...}).then(r => r.json());
 *       },
 *       i18n: { saving: 'Saving…', saved: 'Saved', error: 'Save failed' },
 *       debounceMs: 600 // optional, default 600 for text inputs
 *   });
 *
 * No AJAX shape is hardcoded — each consumer wires its own fetch call
 * because endpoints differ in action / extra params / nonce.
 */
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
            // Initialize "what's already on the server" so blur doesn't
            // fire a no-op save.
            lastSent.set(field, String(readValue(field)));

            // Sibling "Using default: …" hint hides as soon as the user
            // types. The convention is a sibling .lrob-etk-default-hint
            // immediately after the field.
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
                // Hidden mirror inputs (combobox carriers, recipient list,
                // retention toggle) dispatch 'change' when their widget
                // updates the canonical value. Save on that.
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
