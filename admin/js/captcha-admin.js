(function () {
    'use strict';

    if (!window.lrobEtkCaptcha) return;
    var CFG = window.lrobEtkCaptcha;
    var saveTimers = new WeakMap();
    var loadedProviderScripts = {}; // providerSlug → true once script tag injected

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        wireAddButton();
        wireIdentityCards(document);
        wireProtectionSection();
        wireSetDefault();
        renderAllPreviews();
    }

    function wireSetDefault() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.lrob-etk-set-default');
            if (!btn) return;
            var route = btn.getAttribute('data-set-default-route');
            if (!route) return;
            var data = new FormData();
            data.append('action', CFG.actions.setDefault);
            data.append('_nonce', CFG.nonce);
            data.append('route', route);
            request(data).then(function (res) {
                if (res && res.success) window.location.reload();
            });
        });
    }

    // --- Add captcha button ---
    // Spawns a new card directly (no popover), provider chosen via the
    // dropdown inside the card itself.
    function wireAddButton() {
        var btn = document.getElementById('lrob-etk-captcha-add');
        if (!btn) return;
        btn.addEventListener('click', function () {
            spawnNewCard();
        });
    }

    function spawnNewCard() {
        var tpl = document.getElementById('lrob-etk-captcha-card-template');
        var container = document.getElementById('lrob-etk-captcha-identities');
        if (!tpl || !container) return;
        var clone = tpl.content.firstElementChild.cloneNode(true);
        container.appendChild(clone);
        wireIdentityCard(clone);
        // Bind the cloned card's appearance comboboxes (theme / size).
        // initCombos is idempotent (skips already-bound combos).
        if (window.lrobEtkControls && window.lrobEtkControls.initCombos) {
            window.lrobEtkControls.initCombos();
        }
        // Sync fields container against the dropdown's default value.
        applyProviderToCard(clone, clone.dataset.provider);
        var firstInput = clone.querySelector('.lrob-etk-field-label');
        if (firstInput) firstInput.focus();
        var emptyMsg = document.querySelector('.lrob-etk-captcha-providers-empty');
        if (emptyMsg) emptyMsg.style.display = 'none';
    }

    // --- Identity cards ---
    function wireIdentityCards(scope) {
        scope.querySelectorAll('.lrob-etk-captcha-card').forEach(wireIdentityCard);
    }

    function wireIdentityCard(card) {
        if (card.dataset.wired === '1') return;
        card.dataset.wired = '1';

        var form = card.querySelector('form');
        if (!form) return;

        // Focus captures the field's current value so blur can check
        // whether the user actually changed anything. Tabbing through
        // a card without editing must NOT trigger a save.
        form.addEventListener('focus', function (e) {
            if (!isAutoSaveSource(e.target)) return;
            e.target.dataset.originalValue = e.target.value;
            // Clear any previous validation error the moment the user
            // starts touching the field again — gives an "I'm fixing it"
            // visual response.
            e.target.classList.remove('is-invalid');
        }, true);

        form.addEventListener('blur', function (e) {
            if (card.dataset.state !== 'existing') return;
            if (!isAutoSaveSource(e.target)) return;
            var orig = e.target.dataset.originalValue;
            if (orig !== undefined && e.target.value === orig) {
                // Unchanged — bail without nagging the server.
                delete e.target.dataset.originalValue;
                return;
            }
            delete e.target.dataset.originalValue;
            scheduleSave(card);
        }, true);

        form.addEventListener('change', function (e) {
            if (e.target.name === 'is_active') {
                updateActiveLabel(e.target);
                if (card.dataset.state === 'existing') scheduleSave(card);
                return;
            }
            // Appearance comboboxes (theme / size) post their value via a
            // hidden .lrob-etk-combo-value input that fires `change` on pick.
            if (e.target.classList && e.target.classList.contains('lrob-etk-combo-value')) {
                if (card.dataset.state === 'existing') scheduleSave(card);
            }
        });

        var providerPick = form.querySelector('[data-provider-pick]');
        if (providerPick) {
            providerPick.addEventListener('change', function () {
                applyProviderToCard(card, providerPick.value);
            });
        }

        var createBtn = form.querySelector('[data-action="create"]');
        if (createBtn) {
            createBtn.addEventListener('click', function () {
                if (validateRequired(form)) saveCard(card, true);
            });
        }

        var discardBtn = form.querySelector('[data-action="discard"]');
        if (discardBtn) {
            discardBtn.addEventListener('click', function () {
                card.parentNode && card.parentNode.removeChild(card);
                maybeShowEmpty();
            });
        }

        var deleteBtn = form.querySelector('[data-action="delete"]');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function () {
                var ask = window.lrobEtkConfirm
                    ? window.lrobEtkConfirm.prompt({
                        title: CFG.i18n.confirmDeleteTitle || 'Delete identity?',
                        message: CFG.i18n.confirmDelete,
                        confirmLabel: CFG.i18n.confirmDeleteLabel || 'Delete',
                        danger: true
                    })
                    : Promise.resolve(true);
                ask.then(function (ok) { if (ok) deleteCard(card); });
            });
        }
    }

    /**
     * Swap the credential fields in a card to match the chosen provider.
     * Used when (a) a new card is spawned and we need to populate the
     * fields for the dropdown's default provider, and (b) the user
     * switches providers via the dropdown on an unsaved card.
     */
    function applyProviderToCard(card, providerSlug) {
        if (!providerSlug) return;
        var fieldsTpl = document.querySelector('.lrob-etk-captcha-fields-template[data-provider="' + cssEscape(providerSlug) + '"]');
        var container = card.querySelector('[data-fields-container]');
        if (!fieldsTpl || !container) return;
        container.innerHTML = '';
        var nodes = fieldsTpl.content.cloneNode(true);
        container.appendChild(nodes);
        card.dataset.provider = providerSlug;
        var hidden = card.querySelector('[data-provider-slug]');
        if (hidden) hidden.value = providerSlug;
    }

    function isAutoSaveSource(el) {
        if (!el || !el.name) return false;
        if (el.name === 'label') return true;
        if (el.name && el.name.indexOf('credentials[') === 0) return true;
        return false;
    }

    function updateActiveLabel(input) {
        var lbl = input.parentNode && input.parentNode.querySelector('.lrob-etk-inline-switch-label');
        if (!lbl) return;
        lbl.textContent = input.checked ? lbl.dataset.on : lbl.dataset.off;
    }

    function scheduleSave(card) {
        var prev = saveTimers.get(card);
        if (prev) clearTimeout(prev);
        saveTimers.set(card, setTimeout(function () { saveCard(card, false); }, 250));
    }

    function validateRequired(form) {
        var ok = true;
        var label = form.querySelector('.lrob-etk-field-label');
        if (label && label.value.trim() === '') {
            setFieldError(label.closest('.lrob-etk-field') || label, CFG.i18n.labelRequired);
            ok = false;
        }
        return ok;
    }

    function setFieldError(scope, message) {
        if (!scope) return;
        var err = scope.querySelector('[data-field-error]');
        if (!err) return;
        if (message) {
            err.textContent = message;
            err.removeAttribute('hidden');
        } else {
            err.textContent = '';
            err.setAttribute('hidden', '');
        }
    }

    function clearCardErrors(card) {
        card.querySelectorAll('[data-field-error]').forEach(function (n) {
            n.textContent = '';
            n.setAttribute('hidden', '');
        });
        card.querySelectorAll('.is-invalid').forEach(function (n) {
            n.classList.remove('is-invalid');
        });
    }

    function setStatus(card, state, message) {
        var status = card.querySelector('.lrob-etk-card-status');
        if (!status) return;
        status.classList.remove('is-saving', 'is-saved', 'is-failed');
        if (state) status.classList.add(state);
        status.textContent = message || '';
    }

    function saveCard(card, isCreate) {
        var form = card.querySelector('form');
        if (!form) return;
        var data = new FormData(form);
        data.append('action', CFG.actions.saveIdentity);
        data.append('_nonce', CFG.nonce);
        if (!data.has('is_active')) data.append('is_active', '0');

        clearCardErrors(card);
        setStatus(card, 'is-saving', CFG.i18n.saving);

        request(data).then(function (res) {
            if (res.success) {
                handleSaveSuccess(card, res, isCreate);
                setStatus(card, 'is-saved', CFG.i18n.saved);
                setTimeout(function () { setStatus(card, '', ''); }, 1800);
            } else {
                handleSaveError(card, res);
            }
        }).catch(function () {
            setStatus(card, 'is-failed', CFG.i18n.failed);
        });
    }

    function handleSaveSuccess(card, res, isCreate) {
        var form = card.querySelector('form');
        var wasNew = isCreate || card.dataset.state === 'new';
        if (wasNew) {
            card.dataset.state = 'existing';
            card.classList.remove('is-new');
            card.dataset.identityId = String(res.data.id);
            form.querySelector('input[name="id"]').value = String(res.data.id);
            var del = form.querySelector('[data-action="delete"]');
            if (del) { del.removeAttribute('hidden'); del.dataset.id = String(res.data.id); }
            var create = form.querySelector('[data-action="create"]');
            if (create) create.setAttribute('hidden', '');
            var discard = form.querySelector('[data-action="discard"]');
            if (discard) discard.setAttribute('hidden', '');
            var pickField = form.querySelector('[data-provider-pick-field]');
            if (pickField) pickField.parentNode.removeChild(pickField);
            window.location.reload();
            return;
        } else {
            window.location.reload();
            return;
        }
        // Update the derived slug chip + the data-site-key attribute so
        // the preview renders against the freshly-saved credentials.
        if (res.data.slug !== undefined) updateSlugChip(card, res.data.slug);
        if (res.data.site_key !== undefined) {
            card.dataset.siteKey = res.data.site_key || '';
            renderPreview(card);
        }
    }

    function updateSlugChip(card, slug) {
        var chip = card.querySelector('[data-card-slug]');
        if (!chip) return;
        var code = chip.querySelector('code');
        if (code) code.textContent = slug || '';
        if (slug) chip.removeAttribute('hidden'); else chip.setAttribute('hidden', '');
    }

    function handleSaveError(card, res) {
        var fields = res.data && res.data.fields;
        // No per-field map — generic failure, fall back to the card status.
        if (!fields) {
            setStatus(card, 'is-failed', (res.data && res.data.message) || CFG.i18n.failed);
            return;
        }
        // Surface the most useful field error in the card status (so the
        // admin doesn't have to look anywhere specific), and visually
        // mark every offending input. Label errors get extra attention
        // because the label input lives in the header where no inline
        // [data-field-error] slot exists.
        var firstMsg = '';
        Object.keys(fields).forEach(function (key) {
            var sel = key === 'label'
                ? '.lrob-etk-field-label'
                : 'input[data-credential-key="' + cssEscape(key) + '"]';
            var input = card.querySelector(sel);
            if (!input) return;
            input.classList.add('is-invalid');
            if (!firstMsg) firstMsg = fields[key];
            // Credential fields still show their own inline error; label
            // sits in the header so the card-status carries the message.
            if (key !== 'label') {
                setFieldError(input.closest('.lrob-etk-field') || input, fields[key]);
            }
        });
        setStatus(card, 'is-failed', firstMsg || (res.data && res.data.message) || CFG.i18n.failed);
    }

    function deleteCard(card) {
        var id = parseInt(card.dataset.identityId, 10);
        var data = new FormData();
        data.append('action', CFG.actions.deleteIdentity);
        data.append('_nonce', CFG.nonce);
        data.append('id', String(id));

        request(data).then(function (res) {
            if (res.success) window.location.reload();
        });
    }

    function maybeShowEmpty() {
        var container = document.getElementById('lrob-etk-captcha-identities');
        var emptyMsg = document.querySelector('.lrob-etk-captcha-providers-empty');
        if (!container || !emptyMsg) return;
        if (container.querySelectorAll('.lrob-etk-captcha-card').length === 0) {
            emptyMsg.style.display = '';
        }
    }

    // --- Widget preview + auto-test ---
    function renderAllPreviews() {
        document.querySelectorAll('.lrob-etk-captcha-card').forEach(renderPreview);
    }

    function renderPreview(card) {
        var widgetSlot = card.querySelector('[data-preview-widget]');
        var previewContainer = card.querySelector('[data-preview-container]');
        if (!widgetSlot || !previewContainer) return;
        var siteKey = card.dataset.siteKey || '';
        var providerSlug = card.dataset.provider || '';

        // Unsaved or unconfigured: show the placeholder, hide the test slot.
        if (card.dataset.state !== 'existing' || siteKey === '' || providerSlug === '') {
            widgetSlot.innerHTML = '<p class="description">' + escText(CFG.i18n.previewUnsaved) + '</p>';
            previewContainer.hidden = (card.dataset.state !== 'existing');
            return;
        }

        previewContainer.hidden = false;
        var scriptUrl = CFG.providerScripts && CFG.providerScripts[providerSlug];
        ensureProviderScript(providerSlug, scriptUrl);

        // hCaptcha auto-renders any .h-captcha div on the page once its
        // script loads, but we want a per-card callback for auto-testing.
        // Register a per-card global callback name + emit the widget div.
        // Provider-agnostic: read the widget container class + JS global
        // from the localized metadata so every hosted provider (hCaptcha,
        // Turnstile, …) renders its preview the same way.
        var id = card.dataset.identityId;
        var widgets = CFG.providerWidgets || {};
        var w = widgets[providerSlug];
        if (w && w.class) {
            var cbName = 'lrobEtkCaptchaTest_' + id;
            window[cbName] = function (token) { autoTestCard(card, token); };
            widgetSlot.innerHTML = '<div class="' + escAttr(w.class) + '" data-sitekey="' + escAttr(siteKey)
                + '" data-callback="' + escAttr(cbName) + '"' + previewAppearanceAttrs(card) + '></div>';
            // If the vendor script already loaded once, render the new widget.
            var g = w.global ? window[w.global] : null;
            if (g && typeof g.render === 'function') {
                try { g.render(widgetSlot.querySelector('.' + w.class)); }
                catch (e) { /* already rendered */ }
            }
        }
    }

    // Reflect the card's saved theme/size on the preview widget. "auto" is
    // resolved here from the admin's OS colour scheme (the widget has no
    // native auto), matching the frontend behaviour.
    function previewAppearanceAttrs(card) {
        var themeEl = card.querySelector('.lrob-etk-combo-value[name="theme"]');
        var sizeEl = card.querySelector('.lrob-etk-combo-value[name="size"]');
        var theme = themeEl && themeEl.value ? themeEl.value : 'auto';
        var size = sizeEl && sizeEl.value === 'compact' ? 'compact' : 'normal';
        if (theme === 'auto') {
            theme = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }
        return ' data-size="' + escAttr(size) + '" data-theme="' + escAttr(theme) + '"';
    }

    function ensureProviderScript(slug, url) {
        if (!url || loadedProviderScripts[slug]) return;
        loadedProviderScripts[slug] = true;
        var s = document.createElement('script');
        s.async = true;
        s.defer = true;
        s.src = url;
        document.head.appendChild(s);
    }

    function autoTestCard(card, token) {
        var resultEl = card.querySelector('[data-test-result]');
        if (!resultEl) return;
        resultEl.hidden = false;
        resultEl.className = 'lrob-etk-captcha-card-test-result is-testing';
        resultEl.textContent = CFG.i18n.testing;

        var data = new FormData();
        data.append('action', CFG.actions.testIdentity);
        data.append('_nonce', CFG.nonce);
        data.append('id', card.dataset.identityId);
        data.append('token', token);

        request(data).then(function (res) {
            if (res.success) {
                resultEl.className = 'lrob-etk-captcha-card-test-result is-ok';
                resultEl.textContent = (res.data && res.data.message) || CFG.i18n.testWorks;
            } else {
                resultEl.className = 'lrob-etk-captcha-card-test-result is-fail';
                resultEl.textContent = ((res.data && res.data.message) || CFG.i18n.testFailed);
            }
        }).catch(function () {
            resultEl.className = 'lrob-etk-captcha-card-test-result is-fail';
            resultEl.textContent = CFG.i18n.testFailed;
        });
    }

    // One delegated change listener on the protection section: any combo
    // value change (default / per-context override / appearance) autosaves
    // the whole section in one POST.
    function wireProtectionSection() {
        var section = document.querySelector('.lrob-etk-captcha-protection');
        if (!section) return;
        section.addEventListener('change', function (e) {
            if (e.target.classList && e.target.classList.contains('lrob-etk-combo-value')) {
                saveProtection();
            }
        });
    }

    function saveProtection() {
        var data = new FormData();
        data.append('action', CFG.actions.saveRouting);
        data.append('_nonce', CFG.nonce);
        document.querySelectorAll('.lrob-etk-captcha-protection [data-routing-key]').forEach(function (row) {
            var hidden = row.querySelector('.lrob-etk-combo-value');
            data.append('routing[' + row.dataset.routingKey + ']', hidden ? hidden.value : 'none');
        });
        request(data).catch(function () {});
    }

    // --- Utilities ---
    // Reverse map of action string → per-action nonce for destructive
    // operations (delete identity, save routing, set default). When
    // request() sees one of these as the FormData's `action`, it appends
    // `_action_nonce` automatically — callers don't have to know.
    var actionNonceMap = (function () {
        var map = {};
        var nonces = CFG.actionNonces || {};
        Object.keys(nonces).forEach(function (key) {
            if (CFG.actions[key]) map[CFG.actions[key]] = nonces[key];
        });
        return map;
    })();

    function request(formData) {
        var action = formData.get('action');
        if (action && actionNonceMap[action]) {
            formData.append('_action_nonce', actionNonceMap[action]);
        }
        return fetch(CFG.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        }).then(function (r) { return r.json(); });
    }

    function escAttr(s) { return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function escText(s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function cssEscape(s) {
        if (window.CSS && CSS.escape) return CSS.escape(s);
        return String(s).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
    }
})();
