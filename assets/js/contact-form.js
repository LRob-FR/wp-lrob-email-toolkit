/* LRob Email Toolkit — Contact Form frontend script
 *
 * Handles every form rendered with .lrob-etk-cf-form. Plain vanilla JS, no
 * deps. Submits via fetch to admin-ajax, locks the submit button while
 * in-flight, surfaces per-field errors returned by the server, and shows
 * the success / error banner above the form.
 */
(function () {
    'use strict';

    var DATA = window.lrobEtkCf || {};
    var I18N = DATA.i18n || {};
    var AJAX_URL = DATA.ajaxUrl || '';

    function init(form) {
        if (form.__lrobEtkCfBound) return;
        form.__lrobEtkCfBound = true;
        form.addEventListener('submit', onSubmit);
    }

    function onSubmit(e) {
        e.preventDefault();
        var form = e.currentTarget;
        if (form.classList.contains('is-busy') || form.classList.contains('is-sent')) {
            return;
        }

        clearErrors(form);

        var clientErrors = validateClient(form);
        if (clientErrors.length) {
            showFieldErrors(form, clientErrors);
            return;
        }

        var submitBtn = form.querySelector('.lrob-etk-cf-submit');
        var labelEl = submitBtn ? submitBtn.querySelector('.lrob-etk-cf-submit-label') : null;
        var originalLabel = labelEl ? labelEl.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.classList.add('is-busy');
            if (labelEl && I18N.sending) labelEl.textContent = I18N.sending;
        }
        form.classList.add('is-busy');

        var fd = new FormData(form);
        // The form already has action=lrob_etk_cf_submit as a hidden input.

        fetch(AJAX_URL, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        })
            .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
            .then(function (resp) { handleResponse(form, resp); })
            .catch(function () {
                showStatus(form, 'error', I18N.unknownError || 'Error');
            })
            .finally(function () {
                form.classList.remove('is-busy');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('is-busy');
                    if (labelEl) labelEl.textContent = originalLabel;
                }
            });
    }

    function handleResponse(form, resp) {
        if (resp && resp.success) {
            var msg = (resp.data && resp.data.message) || I18N.success;
            showStatus(form, 'success', msg);
            form.classList.add('is-sent');
            scrollIntoViewSoftly(form);
            return;
        }
        var data = (resp && resp.data) || {};
        if (data.fieldErrors && typeof data.fieldErrors === 'object') {
            showFieldErrors(form, fieldErrorsObjToArray(data.fieldErrors));
        }
        var topMsg = data.message || I18N.unknownError || 'Error';
        showStatus(form, 'error', topMsg);
    }

    function validateClient(form) {
        var errors = [];
        var fields = form.querySelectorAll('.lrob-etk-cf-field[data-field]');
        Array.prototype.forEach.call(fields, function (field) {
            var slug = field.getAttribute('data-field');
            if (!slug || slug.charAt(0) === '_') return;
            var inputs = field.querySelectorAll('input, select, textarea');
            if (!inputs.length) return;

            var first = inputs[0];
            var type = (first.getAttribute('type') || first.tagName.toLowerCase());
            var isOptionGroup = first.type === 'radio' || first.type === 'checkbox';

            if (isOptionGroup) {
                var anyChecked = Array.prototype.some.call(inputs, function (i) { return i.checked; });
                var anyRequired = Array.prototype.some.call(inputs, function (i) { return i.required; });
                if (anyRequired && !anyChecked) {
                    errors.push({ field: slug, message: I18N.required });
                }
                return;
            }

            var value = (first.value || '').trim();
            if (first.required && value === '') {
                errors.push({ field: slug, message: I18N.required });
                return;
            }
            if (value !== '' && type === 'email' && !looksLikeEmail(value)) {
                errors.push({ field: slug, message: I18N.invalidEmail });
            }
        });
        return errors;
    }

    function looksLikeEmail(v) {
        // Same pragmatic regex the WP admin uses.
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    }

    function fieldErrorsObjToArray(obj) {
        var arr = [];
        Object.keys(obj).forEach(function (k) {
            arr.push({ field: k, message: String(obj[k]) });
        });
        return arr;
    }

    function clearErrors(form) {
        Array.prototype.forEach.call(form.querySelectorAll('.is-invalid'), function (f) {
            f.classList.remove('is-invalid');
        });
        Array.prototype.forEach.call(form.querySelectorAll('[data-field-error]'), function (e) {
            e.textContent = '';
            e.hidden = true;
        });
        var status = form.querySelector('[data-form-status]');
        if (status) {
            status.hidden = true;
            status.className = 'lrob-etk-cf-status';
            status.textContent = '';
        }
    }

    function showFieldErrors(form, errors) {
        if (!errors || !errors.length) return;
        var firstField = null;
        errors.forEach(function (err) {
            var field = form.querySelector('.lrob-etk-cf-field[data-field="' + cssEscape(err.field) + '"]');
            if (!field) return;
            field.classList.add('is-invalid');
            var slot = field.querySelector('[data-field-error]');
            if (slot) {
                slot.textContent = err.message;
                slot.hidden = false;
            }
            if (!firstField) firstField = field;
        });
        if (firstField) {
            var ctrl = firstField.querySelector('input, select, textarea');
            if (ctrl && ctrl.focus) {
                try { ctrl.focus({ preventScroll: false }); } catch (e) { ctrl.focus(); }
            }
        }
    }

    function showStatus(form, kind, message) {
        var status = form.querySelector('[data-form-status]');
        if (!status) return;
        status.className = 'lrob-etk-cf-status is-' + kind;
        status.textContent = message;
        status.hidden = false;
    }

    function scrollIntoViewSoftly(el) {
        if (typeof el.scrollIntoView === 'function') {
            try { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) { el.scrollIntoView(); }
        }
    }

    function cssEscape(s) {
        if (window.CSS && CSS.escape) return CSS.escape(s);
        return String(s).replace(/["\\]/g, '\\$&');
    }

    function discoverAndInit() {
        var forms = document.querySelectorAll('.lrob-etk-cf-form');
        Array.prototype.forEach.call(forms, init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', discoverAndInit);
    } else {
        discoverAndInit();
    }
    // Also watch for forms inserted later (e.g. in modal popovers).
    if ('MutationObserver' in window) {
        new MutationObserver(function () { discoverAndInit(); })
            .observe(document.documentElement, { childList: true, subtree: true });
    }
})();
