/* LRob — Email Toolkit · Newsletter admin auto-save
 *
 * Listens to blur/change on every input/select/textarea carrying the
 * `lrob-etk-nl-field` class on the Newsletter Forms admin views. Sends
 * { form_id, key, value } to the AjaxController's save-meta endpoint
 * (or save-title via the dedicated `title` key path).
 *
 * Status indicator: each input may have a sibling `.lrob-etk-card-status`
 * element; we flash saving / saved / error states on it.
 *
 * Mirrors the Contact Form admin auto-save pattern but doesn't bundle the
 * combobox / recipient picker UI — newsletter forms don't have those.
 */
(function () {
    var ADMIN = window.lrobEtkNlAdmin || {};
    if (!ADMIN.ajaxUrl || !ADMIN.nonce) {
        return;
    }
    var I18N = ADMIN.i18n || {};
    var ACTIONS = ADMIN.actions || {};

    document.addEventListener('blur', onMaybeSave, true);
    document.addEventListener('change', onMaybeSave, true);

    function onMaybeSave(e) {
        var el = e.target;
        if (!el || !el.classList || !el.classList.contains('lrob-etk-nl-field')) return;
        var key = el.getAttribute('data-key');
        if (!key) return;

        // Four save dispatches:
        //   - newsletter-card meta: requires a [data-newsletter-id] ancestor.
        //   - form-card meta: requires a [data-form-id] ancestor.
        //   - resource renames (category/list): carry data-resource-id
        //     instead, no form/newsletter id.
        //   - module setting saves: carry their option key as the value
        //     of data-option-key, no form/newsletter id either.
        var isResourceRename = (key === 'rename-category' || key === 'rename-list');
        var isSettingSave = (el.getAttribute('data-option-key') !== null);
        var formId = 0;
        var newsletterId = 0;
        if (!isResourceRename && !isSettingSave) {
            var newsletterSection = el.closest('[data-newsletter-id]');
            if (newsletterSection) {
                newsletterId = newsletterSection.getAttribute('data-newsletter-id');
            } else {
                var formSection = el.closest('[data-form-id]');
                if (!formSection) return;
                formId = formSection.getAttribute('data-form-id');
                if (!formId) return;
            }
        }

        // Only save changed values; track on focus and compare on blur.
        if (e.type === 'blur') {
            if (typeof el.__original === 'undefined' || el.__original === el.value) {
                el.__original = el.value;
                return;
            }
        }

        sendSave(formId, newsletterId, key, el);
    }

    document.addEventListener('focus', function (e) {
        var el = e.target;
        if (!el || !el.classList || !el.classList.contains('lrob-etk-nl-field')) return;
        el.__original = el.value;
    }, true);

    function sendSave(formId, newsletterId, key, sourceEl) {
        var statusEl = findStatusEl(sourceEl);
        setStatus(statusEl, 'saving', null, sourceEl);

        // Resource-rename dispatches go to dedicated endpoints rather
        // than save_meta — categories and lists aren't forms and their
        // payload shape is different ({id, name} not {form_id, key,
        // value}).
        var fd = new FormData();
        var optionKey = sourceEl.getAttribute('data-option-key');
        var valueToSend = sourceEl.type === 'checkbox' ? (sourceEl.checked ? '1' : '0') : sourceEl.value;
        if (optionKey) {
            // Module setting save — option name lives on data-option-key,
            // value is the field's current value.
            fd.append('action', 'lrob_etk_nl_setting_save');
            fd.append('_nonce', ADMIN.nonce);
            fd.append('key', optionKey);
            fd.append('value', valueToSend);
        } else if (key === 'rename-category' || key === 'rename-list') {
            var resourceId = sourceEl.getAttribute('data-resource-id');
            if (!resourceId) {
                setStatus(statusEl, 'error');
                return;
            }
            var action = key === 'rename-category'
                ? 'lrob_etk_nl_category_rename'
                : 'lrob_etk_nl_list_rename';
            fd.append('action', action);
            fd.append('_nonce', ADMIN.nonce);
            fd.append('id', resourceId);
            fd.append('name', sourceEl.value);
        } else if (newsletterId) {
            // Newsletter-card meta save.
            fd.append('action', ACTIONS.saveNewsletterMeta || 'lrob_etk_nl_newsletter_save_meta');
            fd.append('_nonce', ADMIN.nonce);
            fd.append('newsletter_id', newsletterId);
            fd.append('key', key);
            fd.append('value', valueToSend);
        } else {
            fd.append('action', ACTIONS.saveMeta || 'lrob_etk_nl_form_save_meta');
            fd.append('_nonce', ADMIN.nonce);
            fd.append('form_id', formId);
            fd.append('key', key);
            fd.append('value', valueToSend);
        }

        fetch(ADMIN.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
            .then(function (resp) {
                if (resp && resp.success) {
                    sourceEl.__original = sourceEl.value;
                    setStatus(statusEl, 'saved', null, sourceEl);
                    // Emit a save-complete event so per-card scripts can
                    // react (e.g. refresh the recipients count after an
                    // audience change). Detail carries newsletterId so
                    // listeners can target the right card.
                    if (newsletterId) {
                        document.dispatchEvent(new CustomEvent('lrob-etk-nl-saved', {
                            detail: { newsletterId: newsletterId, key: key }
                        }));
                    }
                } else {
                    setStatus(statusEl, 'error', resp && resp.data && resp.data.message, sourceEl);
                }
            })
            .catch(function () { setStatus(statusEl, 'error', null, sourceEl); });
    }

    function findStatusEl(sourceEl) {
        // Walk up to the nearest header/section/card and find the first
        // .lrob-etk-card-status inside. Lets us share one status indicator
        // per card without per-input data attributes.
        var ancestor = sourceEl.closest('header, section, article, .lrob-etk-nl-form-edit');
        if (!ancestor) return null;
        return ancestor.querySelector('.lrob-etk-card-status');
    }

    function setStatus(el, state, detail, sourceEl) {
        // Bubble for any enclosing .lrob-etk-modal (list / category /
        // settings modal) to mirror on its header badge. Use sourceEl
        // when supplied (the field itself bubbles even when there's no
        // per-card status element), falling back to the status badge.
        var emitter = sourceEl || el;
        if (emitter && emitter.dispatchEvent) {
            emitter.dispatchEvent(new CustomEvent('lrob-etk:save-status', {
                bubbles: true,
                detail: { state: state, message: detail || '' },
            }));
        }
        if (!el) return;
        el.classList.remove('is-saving', 'is-saved', 'is-error');
        if (state === 'saving') {
            el.classList.add('is-saving');
            el.textContent = I18N.saving || 'Saving…';
        } else if (state === 'saved') {
            el.classList.add('is-saved');
            el.textContent = I18N.saved || 'Saved';
            clearTimeout(el.__hideTimer);
            el.__hideTimer = setTimeout(function () {
                el.classList.remove('is-saved');
                el.textContent = '';
            }, 1400);
        } else if (state === 'error') {
            el.classList.add('is-error');
            el.textContent = (I18N.error || 'Save failed') + (detail ? ': ' + detail : '');
        }
    }
})();
