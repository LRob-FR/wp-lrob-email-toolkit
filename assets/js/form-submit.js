/* Docs: docs/forms.md */
(function () {
    'use strict';

    var DATA = window.lrobEtkForm || {};
    var I18N = DATA.i18n || {};
    var AJAX_URL = DATA.ajaxUrl || '';

    function init(form) {
        if (form.__lrobEtkFormBound) return;
        form.__lrobEtkFormBound = true;
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

        var invisible = form.querySelector('[data-lrob-etk-invisible]');
        if (invisible && !invisibleTokenReady(form, invisible)) {
            runInvisibleCaptcha(form, invisible);
            return;
        }

        var recaptchaV3 = form.querySelector('[data-lrob-etk-recaptcha-v3]');
        if (recaptchaV3 && !recaptchaV3TokenReady(recaptchaV3)) {
            runRecaptchaV3(form, recaptchaV3);
            return;
        }

        var submitBtn = form.querySelector('.lrob-etk-form-submit');
        var labelEl = submitBtn ? submitBtn.querySelector('.lrob-etk-form-submit-label') : null;
        var originalLabel = labelEl ? labelEl.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.classList.add('is-busy');
            if (labelEl && I18N.sending) labelEl.textContent = I18N.sending;
        }
        form.classList.add('is-busy');

        var fd = new FormData(form);
        joinPhonesInto(fd, form);

        fetch(AJAX_URL, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        })
            .then(function (r) {
                return r.text().then(function (txt) {
                    try { return JSON.parse(txt); }
                    catch (e) {
                        if (window.console && console.warn) {
                            console.warn('lrob-etk-form: non-JSON response from submit endpoint:', txt);
                        }
                        return { success: false, data: { message: (I18N.unknownError || 'Error') + ' (server returned a non-JSON response — check the browser console).' } };
                    }
                });
            })
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
        // Drop spent reCAPTCHA v3 token so the next submit fetches a fresh one.
        var v3 = form.querySelector('[data-lrob-etk-recaptcha-v3] input[type="hidden"]');
        if (v3) v3.value = '';
    }

    function validateClient(form) {
        var errors = [];
        var fields = form.querySelectorAll('.lrob-etk-form-field[data-field]');
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
            status.className = 'lrob-etk-form-status';
            status.textContent = '';
        }
    }

    function showFieldErrors(form, errors) {
        if (!errors || !errors.length) return;
        var firstField = null;
        errors.forEach(function (err) {
            var field = form.querySelector('.lrob-etk-form-field[data-field="' + cssEscape(err.field) + '"]');
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
        status.className = 'lrob-etk-form-status is-' + kind;
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
        var forms = document.querySelectorAll('.lrob-etk-form');
        Array.prototype.forEach.call(forms, init);
        var pickers = document.querySelectorAll('.lrob-etk-form-phone[data-country-picker]');
        Array.prototype.forEach.call(pickers, attachPicker);
        var fileEls = document.querySelectorAll('.lrob-etk-form-file[data-file-upload]');
        Array.prototype.forEach.call(fileEls, attachFileUpload);
    }

    // --- Country picker ---
    var COUNTRY_LIST = (DATA.countries || []).slice();
    var COUNTRY_BY_ISO = (function () {
        var m = {};
        for (var i = 0; i < COUNTRY_LIST.length; i++) m[COUNTRY_LIST[i].iso] = COUNTRY_LIST[i];
        return m;
    })();

    function attachPicker(el) {
        if (el.__lrobEtkPhoneBound) return;
        el.__lrobEtkPhoneBound = true;

        var trigger      = el.querySelector('[data-phone-trigger]');
        var flagEl       = el.querySelector('[data-phone-flag]');
        var dialEl       = el.querySelector('[data-phone-dial]');
        var countryInput = el.querySelector('[data-phone-country]');
        var telInput     = el.querySelector('[data-phone-number]');
        if (!trigger || !flagEl || !dialEl || !countryInput || !telInput) return;

        var menu = document.createElement('div');
        menu.className = 'lrob-etk-form-phone-menu';
        menu.hidden = true;
        menu.innerHTML = ''
            + '<input type="search" class="lrob-etk-form-phone-search" placeholder="' + escAttr(I18N.searchCountry || 'Search country…') + '" autocomplete="off">'
            + '<ul class="lrob-etk-form-phone-list" role="listbox"></ul>';
        el.appendChild(menu);
        var searchInput = menu.querySelector('.lrob-etk-form-phone-search');
        var listEl      = menu.querySelector('.lrob-etk-form-phone-list');
        var rendered    = false;

        function renderList(filter) {
            var fl = (filter || '').toLowerCase().trim();
            var html = '';
            for (var i = 0; i < COUNTRY_LIST.length; i++) {
                var c = COUNTRY_LIST[i];
                if (fl !== '') {
                    if (c.name.toLowerCase().indexOf(fl) === -1
                        && c.iso.toLowerCase().indexOf(fl) === -1
                        && c.dial.indexOf(fl) === -1) continue;
                }
                html += '<li role="option" data-iso="' + escAttr(c.iso) + '">'
                    +   '<span class="lrob-etk-form-phone-list-flag">' + c.flag + '</span>'
                    +   '<span class="lrob-etk-form-phone-list-name">' + escHtml(c.name) + '</span>'
                    +   '<span class="lrob-etk-form-phone-list-dial">+' + escHtml(c.dial) + '</span>'
                    + '</li>';
            }
            listEl.innerHTML = html;
            rendered = true;
        }
        function setCountry(iso) {
            var entry = COUNTRY_BY_ISO[iso];
            if (!entry) return;
            flagEl.textContent = entry.flag;
            dialEl.textContent = '+' + entry.dial;
            countryInput.value = iso;
            el.setAttribute('data-default-country', iso);
            el.setAttribute('data-dial', entry.dial);
        }
        function open() {
            if (!menu.hidden) return;
            if (!rendered) renderList('');
            menu.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            searchInput.value = '';
            try { searchInput.focus(); } catch (e) {}
        }
        function close() {
            if (menu.hidden) return;
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        }

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            if (menu.hidden) open(); else close();
        });
        searchInput.addEventListener('input', function () { renderList(searchInput.value); });
        listEl.addEventListener('click', function (e) {
            var li = e.target.closest('li[data-iso]');
            if (!li) return;
            setCountry(li.getAttribute('data-iso'));
            close();
            try { telInput.focus(); } catch (err) {}
        });
        document.addEventListener('click', function (e) {
            if (!menu.hidden && !el.contains(e.target)) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !menu.hidden) close();
        });

        if (el.hasAttribute('data-auto-detect')) {
            var detected = detectCountryFromBrowser();
            if (detected && COUNTRY_BY_ISO[detected]) setCountry(detected);
        }
    }

    function detectCountryFromBrowser() {
        var lang = (navigator.language || '');
        var m = lang.match(/^[a-z]{2,3}-([A-Z]{2})/i);
        if (m && COUNTRY_BY_ISO[m[1].toUpperCase()]) return m[1].toUpperCase();
        return null;
    }

    function joinPhonesInto(fd, form) {
        var pickers = form.querySelectorAll('.lrob-etk-form-phone[data-country-picker]');
        Array.prototype.forEach.call(pickers, function (picker) {
            var country = picker.querySelector('[data-phone-country]');
            var tel = picker.querySelector('[data-phone-number]');
            if (!tel || !country || !tel.name) return;
            var entry = COUNTRY_BY_ISO[country.value];
            var raw = (tel.value || '').replace(/[\s\-().]/g, '');
            if (raw === '' || !entry) return;
            if (raw.charAt(0) === '+') return; // visitor already typed E.164 — trust them
            if (raw.charAt(0) === '0') raw = raw.substring(1); // national trunk prefix
            fd.set(tel.name, '+' + entry.dial + raw);
        });
    }

    function escHtml(s) {
        return String(s).replace(/[&<>]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
        });
    }
    function escAttr(s) { return String(s).replace(/"/g, '&quot;'); }

    window.lrobEtkPhone = {
        attach: attachPicker,
        joinForSubmit: joinPhonesInto,
    };

    // --- File upload ---
    function attachFileUpload(el) {
        if (el.__lrobEtkFileBound) return;
        el.__lrobEtkFileBound = true;

        var input = el.querySelector('input[type="file"]');
        var trigger = el.querySelector('.lrob-etk-form-file-trigger');
        var list = el.querySelector('[data-file-list]');
        if (!input || !trigger || !list) return;

        trigger.addEventListener('click', function (e) {
            if (e.target.tagName === 'INPUT') return;
        });

        input.addEventListener('change', function () {
            renderFileList(el, input, list);
        });
    }

    function renderFileList(el, input, list) {
        var files = Array.prototype.slice.call(input.files || []);
        if (files.length === 0) {
            list.innerHTML = '';
            return;
        }
        var maxSizeMb   = parseInt(el.getAttribute('data-max-size-mb')   || '15', 10);
        var totalSizeMb = parseInt(el.getAttribute('data-total-size-mb') || '50', 10);
        var maxCount    = parseInt(el.getAttribute('data-max-count')     || '1', 10);
        var allowedExts = (el.getAttribute('data-accept-exts') || '').split(',').filter(Boolean);

        var html = '';
        var total = 0;
        var errors = [];
        files.forEach(function (f, i) {
            var size = f.size || 0;
            total += size;
            var name = f.name || '';
            var ext = (name.split('.').pop() || '').toLowerCase();
            var bad = '';
            if (i >= maxCount) {
                bad = (I18N.tooManyFiles || 'Too many files');
            } else if (size > maxSizeMb * 1024 * 1024) {
                bad = (I18N.fileTooLarge || 'File too large');
            } else if (allowedExts.length > 0 && allowedExts.indexOf(ext) === -1) {
                bad = (I18N.fileTypeRejected || 'Type not allowed');
            }
            if (bad) errors.push(name + ': ' + bad);
            html += '<li class="lrob-etk-form-file-item' + (bad ? ' is-invalid' : '') + '">'
                +   '<span class="lrob-etk-form-file-item-name">' + escHtml(name) + '</span>'
                +   '<span class="lrob-etk-form-file-item-size">' + escHtml(formatBytes(size)) + '</span>'
                + '</li>';
        });
        if (total > totalSizeMb * 1024 * 1024) {
            errors.push(I18N.totalTooLarge || 'Combined size exceeds the limit');
        }
        list.innerHTML = html;

        var fieldWrap = el.closest('.lrob-etk-form-field');
        var errEl = fieldWrap ? fieldWrap.querySelector('[data-field-error]') : null;
        if (errEl) {
            if (errors.length > 0) {
                fieldWrap.classList.add('is-invalid');
                errEl.textContent = errors.join('  •  ');
                errEl.hidden = false;
            } else {
                fieldWrap.classList.remove('is-invalid');
                errEl.textContent = '';
                errEl.hidden = true;
            }
        }
    }

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    // --- Invisible captcha ---
    var pendingInvisibleForm = null;

    function invisibleTokenReady(form, fieldEl) {
        var name = fieldEl.getAttribute('data-lrob-etk-response');
        if (!name) return false;
        var resp = form.querySelector('[name="' + cssEscape(name) + '"]');
        return !!(resp && resp.value);
    }

    function runInvisibleCaptcha(form, fieldEl) {
        if (pendingInvisibleForm === form) return;
        var globalName = fieldEl.getAttribute('data-lrob-etk-global');
        var api = globalName ? window[globalName] : null;
        if (!api || typeof api.execute !== 'function') {
            form.__invisibleRetries = (form.__invisibleRetries || 0) + 1;
            if (form.__invisibleRetries > 25) {
                form.__invisibleRetries = 0;
                showStatus(form, 'error', I18N.captchaUnavailable || I18N.unknownError || 'Error');
                return;
            }
            setTimeout(function () { runInvisibleCaptcha(form, fieldEl); }, 200);
            return;
        }
        form.__invisibleRetries = 0;
        pendingInvisibleForm = form;
        var submitBtn = form.querySelector('.lrob-etk-form-submit');
        if (submitBtn) submitBtn.disabled = true;
        var widget = fieldEl.querySelector('[data-hcaptcha-widget-id]');
        var widgetId = widget ? widget.getAttribute('data-hcaptcha-widget-id') : null;
        try {
            if (widgetId !== null) api.execute(widgetId); else api.execute();
        } catch (err) {
            pendingInvisibleForm = null;
            if (submitBtn) submitBtn.disabled = false;
            showStatus(form, 'error', I18N.captchaUnavailable || I18N.unknownError || 'Error');
        }
    }

    function resumeInvisibleSubmit() {
        var form = pendingInvisibleForm;
        pendingInvisibleForm = null;
        if (!form) return;
        var submitBtn = form.querySelector('.lrob-etk-form-submit');
        if (submitBtn) submitBtn.disabled = false;
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        }
    }

    function failInvisible() {
        var form = pendingInvisibleForm;
        pendingInvisibleForm = null;
        if (!form) return;
        var submitBtn = form.querySelector('.lrob-etk-form-submit');
        if (submitBtn) submitBtn.disabled = false;
        showStatus(form, 'error', I18N.captchaFailed || I18N.unknownError || 'Error');
    }

    window.lrobEtkInvisibleResolve = resumeInvisibleSubmit;
    window.lrobEtkInvisibleFailed = failInvisible;
    window.lrobEtkInvisibleExpired = function () { pendingInvisibleForm = null; };

    // --- reCAPTCHA v3 ---
    function recaptchaV3TokenReady(fieldEl) {
        var hidden = fieldEl.querySelector('input[type="hidden"]');
        return !!(hidden && hidden.value);
    }

    function runRecaptchaV3(form, fieldEl) {
        if (pendingInvisibleForm === form) return;
        var api = window.grecaptcha;
        if (!api || typeof api.execute !== 'function' || typeof api.ready !== 'function') {
            form.__v3Retries = (form.__v3Retries || 0) + 1;
            if (form.__v3Retries > 25) {
                form.__v3Retries = 0;
                showStatus(form, 'error', I18N.captchaUnavailable || I18N.unknownError || 'Error');
                return;
            }
            setTimeout(function () { runRecaptchaV3(form, fieldEl); }, 200);
            return;
        }
        form.__v3Retries = 0;
        var siteKey = fieldEl.getAttribute('data-sitekey');
        var action = fieldEl.getAttribute('data-action') || 'submit';
        var hidden = fieldEl.querySelector('input[type="hidden"]');
        if (!siteKey || !hidden) {
            showStatus(form, 'error', I18N.captchaUnavailable || I18N.unknownError || 'Error');
            return;
        }
        pendingInvisibleForm = form;
        var submitBtn = form.querySelector('.lrob-etk-form-submit');
        if (submitBtn) submitBtn.disabled = true;
        api.ready(function () {
            api.execute(siteKey, { action: action }).then(function (token) {
                hidden.value = token || '';
                resumeInvisibleSubmit();
            }).catch(function () {
                failInvisible();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', discoverAndInit);
    } else {
        discoverAndInit();
    }
    if ('MutationObserver' in window) {
        new MutationObserver(function () { discoverAndInit(); })
            .observe(document.documentElement, { childList: true, subtree: true });
    }
})();
