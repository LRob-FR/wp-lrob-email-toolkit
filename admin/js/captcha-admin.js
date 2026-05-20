(function () {
    'use strict';

    if (!window.lrobEtkCaptcha) return;
    var CFG = window.lrobEtkCaptcha;
    var ROUTE_OPTIONS = readRouteOptions();
    var saveTimers = new WeakMap();
    var loadedProviderScripts = {}; // providerSlug → true once script tag injected

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        wireAddButton();
        wireIdentityCards(document);
        wireRoutingSelects();
        renderAllPreviews();
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
                if (!window.confirm(CFG.i18n.confirmDelete)) return;
                deleteCard(card);
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
            addIdentityToRouteOptions({
                providerSlug: card.dataset.provider,
                route: res.data.route_key,
                label: form.querySelector('input[name="label"]').value || '',
                isActive: form.querySelector('input[name="is_active"]').checked,
            });
            rebuildRoutingSelects();
        } else {
            updateIdentityInRouteOptions(parseInt(card.dataset.identityId, 10), {
                label: form.querySelector('input[name="label"]').value || '',
                isActive: form.querySelector('input[name="is_active"]').checked,
            });
            rebuildRoutingSelects();
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
            if (res.success) {
                removeIdentityFromRouteOptions(id);
                card.parentNode && card.parentNode.removeChild(card);
                rebuildRoutingSelects();
                maybeShowEmpty();
            }
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
        var id = card.dataset.identityId;
        if (providerSlug === 'hcaptcha') {
            var cbName = 'lrobEtkCaptchaTest_' + id;
            window[cbName] = function (token) { autoTestCard(card, token); };
            widgetSlot.innerHTML = '<div class="h-captcha" data-sitekey="' + escAttr(siteKey)
                + '" data-callback="' + escAttr(cbName) + '"></div>';
            // If hCaptcha already loaded once, manually render the new widget.
            if (window.hcaptcha && typeof window.hcaptcha.render === 'function') {
                try { window.hcaptcha.render(widgetSlot.querySelector('.h-captcha')); }
                catch (e) { /* already rendered */ }
            }
        }
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

    // --- Routing selects ---
    function wireRoutingSelects() {
        document.querySelectorAll('[data-routing-key]').forEach(function (select) {
            select.addEventListener('change', saveRouting);
        });
    }

    function saveRouting() {
        var data = new FormData();
        data.append('action', CFG.actions.saveRouting);
        data.append('_nonce', CFG.nonce);
        document.querySelectorAll('[data-routing-key]').forEach(function (select) {
            data.append('routing[' + select.dataset.routingKey + ']', select.value);
        });
        request(data).catch(function () { /* silent — change re-fires next interaction */ });
    }

    // --- Routing options model + select rebuild ---
    function readRouteOptions() {
        var wrap = document.querySelector('.lrob-etk-captcha-page');
        if (!wrap || !wrap.dataset.routeOptions) return null;
        try {
            return JSON.parse(wrap.dataset.routeOptions);
        } catch (e) {
            return null;
        }
    }

    function addIdentityToRouteOptions(args) {
        if (!ROUTE_OPTIONS) return;
        var group = ROUTE_OPTIONS.providers.find(function (g) { return g.slug === args.providerSlug; });
        if (!group) return;
        group.identities.push({ route: args.route, label: args.label, is_active: args.isActive });
    }

    function updateIdentityInRouteOptions(id, args) {
        if (!ROUTE_OPTIONS) return;
        var token = 'identity:' + id;
        ROUTE_OPTIONS.providers.forEach(function (group) {
            group.identities.forEach(function (ident) {
                if (ident.route === token) {
                    if (args.label !== undefined) ident.label = args.label;
                    if (args.isActive !== undefined) ident.is_active = args.isActive;
                }
            });
        });
    }

    function removeIdentityFromRouteOptions(id) {
        if (!ROUTE_OPTIONS) return;
        var token = 'identity:' + id;
        ROUTE_OPTIONS.providers.forEach(function (group) {
            group.identities = group.identities.filter(function (i) { return i.route !== token; });
        });
    }

    function rebuildRoutingSelects() {
        if (!ROUTE_OPTIONS) return;
        document.querySelectorAll('[data-routing-key]').forEach(function (select) {
            var current = select.value;
            var key = select.dataset.routingKey;
            var includeInherit = key !== 'default';
            select.innerHTML = buildRouteOptionsHtml(includeInherit);
            select.value = current;
            if (select.value !== current) {
                select.value = includeInherit ? ROUTE_OPTIONS.inherit : ROUTE_OPTIONS.none;
                saveRouting();
            }
        });
    }

    function buildRouteOptionsHtml(includeInherit) {
        var parts = [];
        if (includeInherit) {
            parts.push('<option value="' + escAttr(ROUTE_OPTIONS.inherit) + '">' + escText(ROUTE_OPTIONS.inheritLabel) + '</option>');
        }
        parts.push('<option value="' + escAttr(ROUTE_OPTIONS.none) + '">' + escText(ROUTE_OPTIONS.noneLabel) + '</option>');
        if (ROUTE_OPTIONS.homemade.length) {
            parts.push('<optgroup label="' + escAttr(ROUTE_OPTIONS.homemadeLabel) + '">');
            ROUTE_OPTIONS.homemade.forEach(function (h) {
                parts.push('<option value="' + escAttr(h.route) + '">' + escText(h.label) + '</option>');
            });
            parts.push('</optgroup>');
        }
        ROUTE_OPTIONS.providers.forEach(function (group) {
            parts.push('<optgroup label="' + escAttr(group.label) + '">');
            if (group.identities.length === 0) {
                parts.push('<option value="" disabled>' + escText(ROUTE_OPTIONS.configureFirst.replace('%s', group.label)) + '</option>');
            } else {
                group.identities.forEach(function (id) {
                    var label = id.label + (id.is_active ? '' : ' ' + ROUTE_OPTIONS.inactiveSuffix);
                    parts.push('<option value="' + escAttr(id.route) + '"' + (id.is_active ? '' : ' disabled') + '>' + escText(label) + '</option>');
                });
            }
            parts.push('</optgroup>');
        });
        return parts.join('');
    }

    // --- Utilities ---
    function request(formData) {
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
