/* LRob — Email Toolkit · Newsletter admin auto-save
 * Docs: docs/newsletter-internals.md → "Admin JS overview"
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
        var statusEls = findStatusEls(sourceEl);
        setStatus(statusEls, 'saving', null, sourceEl);

        // Resource-rename dispatches go to dedicated endpoints rather
        // than save_meta — categories and lists aren't forms and their
        // payload shape is different ({id, name} not {form_id, key,
        // value}).
        var fd = new FormData();
        var optionKey = sourceEl.getAttribute('data-option-key');
        var valueToSend = sourceEl.type === 'checkbox' ? (sourceEl.checked ? '1' : '0') : sourceEl.value;
        if (optionKey) {
            fd.append('action', 'lrob_etk_nl_setting_save');
            fd.append('_nonce', ADMIN.nonce);
            fd.append('key', optionKey);
            fd.append('value', valueToSend);
        } else if (key === 'rename-category' || key === 'rename-list') {
            var resourceId = sourceEl.getAttribute('data-resource-id');
            if (!resourceId) {
                setStatus(statusEls, 'error');
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
                    setStatus(statusEls, 'saved', null, sourceEl);
                    if (newsletterId) {
                        document.dispatchEvent(new CustomEvent('lrob-etk-nl-saved', {
                            detail: { newsletterId: newsletterId, key: key }
                        }));
                    }
                } else {
                    setStatus(statusEls, 'error', resp && resp.data && resp.data.message, sourceEl);
                }
            })
            .catch(function () { setStatus(statusEls, 'error', null, sourceEl); });
    }

    // All status badges in the card (header AND footer) so the autosave
    // indicator stays visible when scrolling a tall card. Falls back to the
    // broad ancestor for non-card contexts (resource rename, module setting).
    function findStatusEls(sourceEl) {
        var card = sourceEl.closest('.lrob-etk-card');
        if (card) return card.querySelectorAll('.lrob-etk-card-status');
        var ancestor = sourceEl.closest('header, section, article, .lrob-etk-nl-form-edit');
        return ancestor ? ancestor.querySelectorAll('.lrob-etk-card-status') : [];
    }

    function setStatus(els, state, detail, sourceEl) {
        var emitter = sourceEl || (els && els[0]);
        if (emitter && emitter.dispatchEvent) {
            emitter.dispatchEvent(new CustomEvent('lrob-etk:save-status', {
                bubbles: true,
                detail: { state: state, message: detail || '' },
            }));
        }
        renderStatus(els, state, detail);
    }

    function renderStatus(els, state, detail) {
        if (!els || !els.length) return;
        Array.prototype.forEach.call(els, function (el) {
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
        });
    }

    // Card-internal widgets that save on their own (the audience / default-lists
    // picker) dispatch `lrob-etk:save-status` but don't touch the card badge.
    // Route those to the card's status badge(s). nl-field events are already
    // rendered directly by sendSave → skip them (no double work, no loop:
    // renderStatus never re-dispatches).
    document.addEventListener('lrob-etk:save-status', function (e) {
        var src = e.target;
        if (!src || !src.closest) return;
        if (src.classList && src.classList.contains('lrob-etk-nl-field')) return;
        renderStatus(findStatusEls(src), e.detail && e.detail.state, e.detail && e.detail.message);
    });
})();
