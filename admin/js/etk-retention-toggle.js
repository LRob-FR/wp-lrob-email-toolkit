/**
 * Auto-delete-after toggle (Admin\RetentionToggle PHP renderer).
 *
 * Drives a [checkbox + number-input + hidden] triplet:
 *   - Checkbox tracks the "enabled" state.
 *   - Number input shows the days value (always visible, never 0).
 *   - Hidden input carries the canonical setting that the module's
 *     auto-save listener picks up (via its data-key + marker class).
 *
 * The hidden field gets a `change` event dispatched after every flip so
 * the module's existing save plumbing (Contact Form's `lrob-etk-cf-field`
 * listener, Logging's `lrob-etk-logs-field` listener, etc.) writes
 * automatically — no per-module wiring needed.
 */
(function () {
    'use strict';

    function dispatch(hidden) {
        try {
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (e) {
            // IE11-ish fallback; should never hit in modern WP admin.
            var evt = document.createEvent('Event');
            evt.initEvent('change', true, true);
            hidden.dispatchEvent(evt);
        }
    }

    function clamp(value, min, max) {
        if (!isFinite(value)) return min;
        if (value < min) return min;
        if (value > max) return max;
        return value;
    }

    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.matches) return;

        if (t.matches('[data-retention-enable]')) {
            var widget = t.closest('[data-retention-toggle]');
            if (!widget) return;
            var days = widget.querySelector('[data-retention-days]');
            var hidden = widget.querySelector('input[type="hidden"][data-key]');
            if (!days || !hidden) return;

            if (t.checked) {
                days.disabled = false;
                var max = parseInt(days.getAttribute('max'), 10) || 3650;
                var current = parseInt(days.value, 10);
                if (!current || current < 1) {
                    current = parseInt(days.getAttribute('data-default-days'), 10) || 365;
                }
                current = clamp(current, 1, max);
                days.value = String(current);
                hidden.value = String(current);
            } else {
                days.disabled = true;
                hidden.value = '0';
            }
            dispatch(hidden);
            return;
        }

        if (t.matches('[data-retention-days]')) {
            var widget2 = t.closest('[data-retention-toggle]');
            if (!widget2) return;
            var enable = widget2.querySelector('[data-retention-enable]');
            var hidden2 = widget2.querySelector('input[type="hidden"][data-key]');
            if (!enable || !hidden2 || !enable.checked) return;

            var max2 = parseInt(t.getAttribute('max'), 10) || 3650;
            var v = parseInt(t.value, 10);
            if (!v || v < 1) {
                v = parseInt(t.getAttribute('data-default-days'), 10) || 365;
            }
            v = clamp(v, 1, max2);
            t.value = String(v);
            hidden2.value = String(v);
            dispatch(hidden2);
        }
    });
})();
