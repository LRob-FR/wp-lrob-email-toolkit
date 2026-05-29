/* Docs: docs/captcha.md → "Admin JS" */
(function () {
    'use strict';

    if (!window.lrobEtkCaptcha) return;
    var CFG = window.lrobEtkCaptcha;
    var saveTimers = new WeakMap();
    var loadedProviderScripts = {}; // providerSlug → true once script tag injected
    var loadedV3Scripts = {}; // reCAPTCHA v3 site key → true once api.js?render injected

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
                if (res && res.success) applyDefaultEverywhere((res.data && res.data.route) || route);
            });
        });
    }

    // --- New captcha button ---
    function wireAddButton() {
        var btn = document.getElementById('lrob-etk-captcha-add');
        if (!btn) return;
        var providers = CFG.providers || [];

        if (providers.length <= 1) {
            btn.addEventListener('click', function () {
                spawnNewCard(providers[0] ? providers[0].slug : btn.dataset.provider);
            });
            return;
        }

        var modalApi = (window.lrobEtkModal && window.lrobEtkModal.bindHeader)
            ? window.lrobEtkModal.bindHeader('lrob-etk-captcha-provider-modal', 'lrob-etk-captcha-add')
            : null;
        var modal = document.getElementById('lrob-etk-captcha-provider-modal');
        if (!modal) return;
        modal.addEventListener('click', function (e) {
            var card = e.target.closest('[data-provider-pick-card]');
            if (!card) return;
            var slug = card.getAttribute('data-provider-slug');
            if (modalApi && modalApi.close) modalApi.close();
            else { modal.hidden = true; document.body.style.overflow = ''; }
            spawnNewCard(slug);
        });
    }

    function spawnNewCard(providerSlug) {
        var tpl = document.getElementById('lrob-etk-captcha-card-template');
        var container = document.getElementById('lrob-etk-captcha-identities');
        if (!tpl || !container) return;
        var slug = providerSlug || tpl.content.firstElementChild.dataset.provider;
        var clone = tpl.content.firstElementChild.cloneNode(true);
        container.appendChild(clone);
        wireIdentityCard(clone);
        // Brand the card + load the credential fields for the chosen provider.
        applyProviderToCard(clone, slug);
        // The master template carries the first provider's size options; swap
        // in the chosen provider's list (only invisible-capable providers offer
        // "Invisible") BEFORE binding the combos — the combo captures its
        // option list once at init.
        applyProviderSizeOptions(clone, slug);
        // Bind the cloned card's appearance comboboxes (theme / size) — done
        // last so they pick up the correct, just-set option list.
        if (window.lrobEtkControls && window.lrobEtkControls.initCombos) {
            window.lrobEtkControls.initCombos();
        }
        var emptyMsg = document.querySelector('.lrob-etk-captcha-providers-empty');
        if (emptyMsg) emptyMsg.style.display = 'none';
        // Bring the fresh card into view (the grid can be long) then focus.
        clone.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var firstInput = clone.querySelector('.lrob-etk-field-label');
        if (firstInput) firstInput.focus({ preventScroll: true });
    }

    function applyProviderSizeOptions(card, providerSlug) {
        var meta = (CFG.providers || []).filter(function (p) { return p.slug === providerSlug; })[0];
        if (!meta || !meta.sizeOptions) return;
        var sizeHidden = card.querySelector('.lrob-etk-combo-value[name="size"]');
        if (!sizeHidden) return;
        var combo = sizeHidden.closest('.lrob-etk-combo');
        if (combo) combo.setAttribute('data-options', JSON.stringify(meta.sizeOptions));
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
                card.classList.toggle('lrob-etk-is-dimmed', !e.target.checked);
                if (card.dataset.state === 'existing') scheduleSave(card);
                return;
            }
            // Appearance comboboxes (theme / size) + the reCAPTCHA version
            // combo post their value via a hidden .lrob-etk-combo-value input
            // that fires `change` on pick.
            if (e.target.classList && e.target.classList.contains('lrob-etk-combo-value')) {
                if (e.target.name === 'credentials[version]') {
                    applyVersionVisibility(card);
                }
                if (card.dataset.state === 'existing') scheduleSave(card);
            }
        });

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

        // Existing reCAPTCHA cards: reflect the saved version's field visibility
        // on load (new cards get it via applyProviderToCard at spawn time).
        applyVersionVisibility(card);
    }

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
        // Re-brand the header chip (logo + label) to match the chosen provider.
        var meta = (CFG.providers || []).filter(function (p) { return p.slug === providerSlug; })[0];
        if (meta) {
            var logo = card.querySelector('[data-provider-logo]');
            if (logo) logo.innerHTML = meta.logo || '';
            var label = card.querySelector('[data-provider-label]');
            if (label) label.textContent = meta.label;
        }
        applyVersionVisibility(card);
    }

    // reCAPTCHA only: the score field applies to v3, the theme/size (widget
    // appearance) applies to v2. Show each only where it's relevant. Cards
    // without a version combo (hCaptcha / Turnstile) are left untouched.
    function applyVersionVisibility(card) {
        var versionEl = card.querySelector('.lrob-etk-combo-value[name="credentials[version]"]');
        if (!versionEl) return;
        var isV3 = versionEl.value === 'v3';
        var scoreInput = card.querySelector('[name="credentials[score_threshold]"]');
        var scoreField = scoreInput ? scoreInput.closest('.lrob-etk-field') : null;
        if (scoreField) scoreField.hidden = !isV3;
        var appearance = card.querySelector('.lrob-etk-captcha-card-appearance');
        if (appearance) appearance.hidden = isV3;
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
            var idInput = form.querySelector('input[name="id"]');
            if (idInput) idInput.value = String(res.data.id);
            var del = form.querySelector('[data-action="delete"]');
            if (del) { del.removeAttribute('hidden'); del.dataset.id = String(res.data.id); }
            var create = form.querySelector('[data-action="create"]');
            if (create) create.setAttribute('hidden', '');
            var discard = form.querySelector('[data-action="discard"]');
            if (discard) discard.setAttribute('hidden', '');
        }
        // Derived slug chip + data-site-key so the preview renders against the
        // freshly-saved credentials.
        if (res.data.slug !== undefined) updateSlugChip(card, res.data.slug);
        if (res.data.site_key !== undefined) {
            card.dataset.siteKey = res.data.site_key || '';
        }
        // Stamp the (possibly just-created) card's route onto its default slot,
        // then reflect the current global default everywhere — this handles the
        // "deactivate the current default → server sweeps it to Math" case live.
        var defaultSlot = card.querySelector('.lrob-etk-card-footer-default');
        if (defaultSlot && res.data.route_key) defaultSlot.setAttribute('data-route', res.data.route_key);
        refreshRoutingMenus();
        if (res.data.default_route) applyDefaultEverywhere(res.data.default_route);
        renderPreview(card);
    }

    function applyDefaultEverywhere(route) {
        if (!route) return;
        var slots = document.querySelectorAll('.lrob-etk-card-footer-default[data-route]');
        Array.prototype.forEach.call(slots, function (slot) {
            var cardRoute = slot.getAttribute('data-route');
            if (!cardRoute) { slot.innerHTML = ''; return; }
            var card = slot.closest('.lrob-etk-captcha-card');
            var activeEl = card && card.querySelector('input[name="is_active"]');
            var isActive = !activeEl || activeEl.checked; // built-in cards have no toggle
            if (cardRoute === route) {
                slot.innerHTML = '<span class="lrob-etk-default-badge">'
                    + '<span class="dashicons dashicons-star-filled" aria-hidden="true"></span> '
                    + escText(CFG.i18n.defaultLabel || 'Default') + '</span>';
            } else if (isActive) {
                slot.innerHTML = setDefaultBtnHtml(cardRoute);
            } else {
                slot.innerHTML = '';
            }
        });
        syncDefaultDropdown(route);
    }

    function setDefaultBtnHtml(route) {
        return '<button type="button" class="lrob-etk-set-default" data-set-default-route="' + escAttr(route) + '">'
            + '<span class="dashicons dashicons-star-empty" aria-hidden="true"></span> '
            + escText(CFG.i18n.setDefaultLabel || 'Set as default') + '</button>';
    }

    // Display-only sync of the "Default challenge" dropdown — the server has
    // already persisted the new default; this just mirrors it visually.
    function syncDefaultDropdown(route) {
        var container = document.querySelector('[data-routing-key="default"]');
        var combo = container && container.querySelector('.lrob-etk-combo');
        if (!combo) return;
        var hidden = combo.querySelector('.lrob-etk-combo-value');
        var input = combo.querySelector('.lrob-etk-combo-input');
        if (hidden) hidden.value = route;
        var label = null;
        try {
            var opts = JSON.parse(combo.getAttribute('data-options') || '[]');
            for (var i = 0; i < opts.length; i++) {
                if (String(opts[i].value) === String(route)) { label = opts[i].label; break; }
            }
        } catch (e) {}
        // Not in the page-load option list — e.g. an identity that was inactive
        // when the page rendered and was just activated + set as default. Derive
        // its display name from its card so the title shows the proper name
        // ("Provider: Label") instead of the raw route code (identity:N).
        if (label === null) label = labelFromCard(route) || route;
        if (input) input.value = label;
    }

    function labelFromCard(route) {
        var slots = document.querySelectorAll('.lrob-etk-card-footer-default[data-route]');
        for (var i = 0; i < slots.length; i++) {
            if (slots[i].getAttribute('data-route') !== route) continue;
            var card = slots[i].closest('.lrob-etk-captcha-card');
            if (!card) return '';
            var provEl = card.querySelector('[data-provider-label]');
            var labelEl = card.querySelector('input[name="label"]');
            var prov = provEl ? provEl.textContent.trim() : '';
            var name = (labelEl && labelEl.value.trim()) || prov;
            return prov ? (prov + ': ' + name) : name;
        }
        return '';
    }

    function activeIdentityOptions() {
        var out = [];
        document.querySelectorAll('.lrob-etk-captcha-card[data-state="existing"]').forEach(function (card) {
            var activeEl = card.querySelector('input[name="is_active"]');
            if (activeEl && !activeEl.checked) return;
            var id = card.getAttribute('data-identity-id');
            if (!id || id === '0') return;
            var provEl = card.querySelector('[data-provider-label]');
            var labelEl = card.querySelector('input[name="label"]');
            var prov = provEl ? provEl.textContent.trim() : '';
            var name = (labelEl && labelEl.value.trim()) || prov;
            out.push({ value: 'identity:' + id, label: prov ? (prov + ': ' + name) : name });
        });
        return out;
    }

    // Rebuild identity entries in every routing dropdown from live cards — no reload.
    function refreshRoutingMenus() {
        var idOpts = activeIdentityOptions();
        var activeLabels = {};
        idOpts.forEach(function (o) { activeLabels[o.value] = o.label; });

        document.querySelectorAll('[data-routing-key] .lrob-etk-combo').forEach(function (combo) {
            var opts;
            try { opts = JSON.parse(combo.getAttribute('data-options') || '[]'); } catch (e) { return; }
            var kept = opts.filter(function (o) { return String(o.value).indexOf('identity:') !== 0; });
            combo.setAttribute('data-options', JSON.stringify(kept.concat(idOpts)));

            var hidden = combo.querySelector('.lrob-etk-combo-value');
            if (!hidden) return;
            var selected = String(hidden.value);
            if (selected.indexOf('identity:') !== 0) return;
            var keyEl = combo.closest('[data-routing-key]');
            var routingKey = keyEl ? keyEl.getAttribute('data-routing-key') : '';
            if (!activeLabels[selected]) {
                // Selected identity no longer active — server swept it. The
                // default combo is re-synced by applyDefaultEverywhere; others
                // fall back to inherit.
                if (routingKey !== 'default') setComboValue(combo, 'inherit');
            } else {
                // Still active — keep the visible label fresh (label may have
                // been edited).
                var input = combo.querySelector('.lrob-etk-combo-input');
                if (input) input.value = activeLabels[selected];
            }
        });
    }

    function setComboValue(combo, value) {
        var hidden = combo.querySelector('.lrob-etk-combo-value');
        var input = combo.querySelector('.lrob-etk-combo-input');
        if (hidden) hidden.value = value;
        var label = value;
        try {
            var opts = JSON.parse(combo.getAttribute('data-options') || '[]');
            for (var i = 0; i < opts.length; i++) {
                if (String(opts[i].value) === String(value)) { label = opts[i].label; break; }
            }
        } catch (e) {}
        var inheritVal = combo.getAttribute('data-inherit-value');
        if (input) input.value = (inheritVal !== null && String(value) === String(inheritVal)) ? '' : label;
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
            if (!res.success) return;
            card.parentNode && card.parentNode.removeChild(card);
            maybeShowEmpty();
            refreshRoutingMenus();
            if (res.data && res.data.default_route) applyDefaultEverywhere(res.data.default_route);
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
        // Inactive identity: no live preview/test — clear any mounted widget so
        // a disabled captcha doesn't keep rendering in the background.
        var activeEl = card.querySelector('input[name="is_active"]');
        if (activeEl && !activeEl.checked) {
            widgetSlot.innerHTML = '';
            previewContainer.hidden = true;
            return;
        }
        var siteKey = card.dataset.siteKey || '';
        var providerSlug = card.dataset.provider || '';

        // Unsaved or unconfigured: show the placeholder, hide the test slot.
        if (card.dataset.state !== 'existing' || siteKey === '' || providerSlug === '') {
            widgetSlot.innerHTML = '<p class="description">' + escText(CFG.i18n.previewUnsaved) + '</p>';
            previewContainer.hidden = (card.dataset.state !== 'existing');
            return;
        }

        previewContainer.hidden = false;

        // reCAPTCHA v3 has no widget — score-based. Offer a "Test score"
        // button that runs a real execute()+siteverify and shows the score.
        var versionEl = card.querySelector('.lrob-etk-combo-value[name="credentials[version]"]');
        if (versionEl && versionEl.value === 'v3') {
            widgetSlot.innerHTML = '<div class="lrob-etk-captcha-v3-test-box">'
                + '<p class="description">' + escText(CFG.i18n.recaptchaV3Note || CFG.i18n.invisibleNote || '') + '</p>'
                + '<button type="button" class="button lrob-etk-captcha-v3-test" data-v3-test>' + escText(CFG.i18n.testScore || 'Test score') + '</button>'
                + '</div>';
            var v3Btn = widgetSlot.querySelector('[data-v3-test]');
            if (v3Btn) v3Btn.addEventListener('click', function () { runV3ScoreTest(card, siteKey, v3Btn); });
            return;
        }

        // Invisible mode renders no visible box — there's nothing to show or
        // click-to-test here; explain it instead of an empty slot.
        var sizeEl = card.querySelector('.lrob-etk-combo-value[name="size"]');
        if (sizeEl && sizeEl.value === 'invisible') {
            widgetSlot.innerHTML = '<p class="description">' + escText(CFG.i18n.invisibleNote || '') + '</p>';
            return;
        }

        var scriptUrl = CFG.providerScripts && CFG.providerScripts[providerSlug];
        ensureProviderScript(providerSlug, scriptUrl);

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

    function runV3ScoreTest(card, siteKey, btn) {
        if (!siteKey) return;
        var resultEl = card.querySelector('[data-test-result]');
        if (resultEl) {
            resultEl.hidden = false;
            resultEl.className = 'lrob-etk-captcha-card-test-result is-testing';
            resultEl.textContent = CFG.i18n.testing;
        }
        if (btn) btn.disabled = true;

        ensureV3Script(siteKey, function () {
            var g = window.grecaptcha;
            if (!g || typeof g.ready !== 'function' || typeof g.execute !== 'function') {
                showV3Fail(resultEl, btn, CFG.i18n.captchaUnavailable || CFG.i18n.testFailed);
                return;
            }
            g.ready(function () {
                g.execute(siteKey, { action: 'admin_test' }).then(function (token) {
                    var data = new FormData();
                    data.append('action', CFG.actions.testScore);
                    data.append('_nonce', CFG.nonce);
                    data.append('id', card.dataset.identityId);
                    data.append('token', token);
                    request(data).then(function (res) {
                        if (btn) btn.disabled = false;
                        if (res.success && res.data) {
                            var d = res.data;
                            var verdict = d.ok ? CFG.i18n.testWorks : CFG.i18n.testFailed;
                            var msg = verdict + ' · score ' + Number(d.score).toFixed(2)
                                + ' (≥ ' + Number(d.threshold).toFixed(2) + ')';
                            if (resultEl) {
                                resultEl.hidden = false;
                                resultEl.className = 'lrob-etk-captcha-card-test-result ' + (d.ok ? 'is-ok' : 'is-fail');
                                resultEl.textContent = msg;
                            }
                        } else {
                            showV3Fail(resultEl, null, (res.data && res.data.message) || CFG.i18n.testFailed);
                        }
                    }).catch(function () { showV3Fail(resultEl, btn, CFG.i18n.testFailed); });
                }).catch(function () { showV3Fail(resultEl, btn, CFG.i18n.testFailed); });
            });
        });
    }

    function showV3Fail(resultEl, btn, msg) {
        if (btn) btn.disabled = false;
        if (resultEl) {
            resultEl.hidden = false;
            resultEl.className = 'lrob-etk-captcha-card-test-result is-fail';
            resultEl.textContent = msg;
        }
    }

    function ensureV3Script(siteKey, cb) {
        if (window.grecaptcha && typeof window.grecaptcha.execute === 'function' && loadedV3Scripts[siteKey]) {
            cb();
            return;
        }
        if (loadedV3Scripts[siteKey]) {
            // Tag injected but not ready yet — poll briefly.
            var tries = 0;
            var iv = setInterval(function () {
                if (window.grecaptcha && typeof window.grecaptcha.execute === 'function') { clearInterval(iv); cb(); }
                else if (++tries > 50) { clearInterval(iv); cb(); }
            }, 100);
            return;
        }
        loadedV3Scripts[siteKey] = true;
        var base = (CFG.providerScripts && CFG.providerScripts.recaptcha) || 'https://www.google.com/recaptcha/api.js';
        var s = document.createElement('script');
        s.async = true;
        s.defer = true;
        s.src = base + '?render=' + encodeURIComponent(siteKey);
        s.onload = function () { cb(); };
        s.onerror = function () { cb(); };
        document.head.appendChild(s);
    }

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
