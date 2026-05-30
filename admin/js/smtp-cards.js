// SMTP identity-card editor driver. Docs: docs/smtp.md
(function () {
    var S = window.lrobEtkSmtp;
    if (!S) return;

    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function field(card, cls) { return card.querySelector('.lrob-etk-field-' + cls); }
    function slugify(s) {
        return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').substring(0, 50);
    }

    var actionNonceMap = (function () {
        var map = {};
        var nonces = S.actionNonces || {};
        Object.keys(nonces).forEach(function (key) {
            if (S.actions[key]) map[S.actions[key]] = nonces[key];
        });
        return map;
    })();

    function ajax(action, params) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('_nonce', S.nonce);
        if (actionNonceMap[action]) fd.append('_action_nonce', actionNonceMap[action]);
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (v === undefined || v === null) return;
            if (typeof v === 'boolean') v = v ? '1' : '';
            if (Array.isArray(v)) { v.forEach(function (item) { fd.append(k + '[]', item); }); return; }
            if (typeof v === 'object') {
                Object.keys(v).forEach(function (sub) { fd.append(k + '[' + sub + ']', v[sub]); });
                return;
            }
            fd.append(k, v);
        });
        return fetch(S.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); });
    }

    function flash(msg, type) {
        var holder = document.getElementById('lrob-etk-flash');
        if (!holder) return;
        var div = document.createElement('div');
        div.className = 'notice notice-' + (type === 'error' ? 'error' : 'success') + ' is-dismissible';
        var p = document.createElement('p'); p.textContent = msg; div.appendChild(p);
        holder.appendChild(div);
        setTimeout(function () { if (div.parentNode) div.parentNode.removeChild(div); }, 5000);
    }

    function setStatus(card, state, message) {
        var el = card.querySelector('.lrob-etk-card-status');
        if (!el) return;
        el.className = 'lrob-etk-card-status is-' + state;
        el.textContent = message || '';
        if (state === 'saved') {
            clearTimeout(card._statusTimer);
            card._statusTimer = setTimeout(function () {
                el.className = 'lrob-etk-card-status';
                el.textContent = '';
            }, 1500);
        }
    }

    function applyCardState(card) {
        var transport = field(card, 'transport').value;
        var isMail = transport === 'mail';
        var smtpOnly = card.querySelector('.lrob-etk-smtp-only');
        if (smtpOnly) smtpOnly.style.display = isMail ? 'none' : '';
        // Test-connection button lives in the footer (outside .smtp-only) — toggle it too.
        $$('.lrob-etk-smtp-only-inline', card).forEach(function (el) {
            el.style.display = isMail ? 'none' : '';
        });

        // Auth visibility
        var authOn = field(card, 'smtp-auth').checked;
        $$('.lrob-etk-auth-fields > .lrob-etk-field', card).forEach(function (div) {
            div.style.display = authOn ? '' : 'none';
        });

        // Active toggle title (Enable/Disable depending on current state) + dim card.
        var activeSwitch = card.querySelector('.lrob-etk-active-switch');
        var activeChk = field(card, 'is-active');
        if (activeSwitch && activeChk) {
            var t = activeChk.checked ? activeSwitch.getAttribute('data-on') : activeSwitch.getAttribute('data-off');
            if (t) activeSwitch.setAttribute('title', t);
        }
        if (activeChk && card.getAttribute('data-state') !== 'new') {
            card.classList.toggle('lrob-etk-is-dimmed', !activeChk.checked);
        }

        // None warning (encryption is a Combobox now — read its hidden value input).
        var encInput = card.querySelector('[name="smtp_encryption"]');
        var noneWarn = card.querySelector('.lrob-etk-none-warning');
        if (noneWarn) noneWarn.hidden = !(encInput && encInput.value === '');

        rebuildHostPresets(card);
        updateFromEmailDefaultLabel(card);
        syncFromWarning(card);
    }

    function updateFromEmailPlaceholder(card) {
        var input = card.querySelector('.lrob-etk-field-from-email');
        if (!input) return;
        var isMail = field(card, 'transport').value === 'mail';
        var user = field(card, 'username').value.trim();
        if (isMail) {
            input.placeholder = S.i18n.defaultWpSender;
        } else if (user) {
            input.placeholder = S.i18n.defaultPrefix + user;
        } else {
            input.placeholder = S.i18n.defaultMailboxLogin;
        }
    }
    function rebuildHostPresets() { /* presets are built on demand by combobox */ }
    function updateFromEmailDefaultLabel(card) { updateFromEmailPlaceholder(card); }

    // Paint the resolve state: left dot stays persistent; the right word shows
    // on change/reload then fades after 3s. `resolves` null = unknown (hide both).
    function paintHostResolve(card, resolves) {
        var dot = card.querySelector('.lrob-etk-host-dot');
        var word = card.querySelector('.lrob-etk-host-status');
        clearTimeout(card._hostWordTimer);
        if (resolves === null || resolves === undefined) {
            if (dot) { dot.hidden = true; dot.removeAttribute('title'); }
            if (word) { word.hidden = true; }
            return;
        }
        var state = resolves ? 'lrob-etk-state--on' : 'lrob-etk-state--fail';
        var label = resolves ? S.i18n.resolves : S.i18n.noResolve;
        if (dot) { dot.hidden = false; dot.className = 'lrob-etk-host-dot ' + state; dot.setAttribute('title', label); }
        if (word) {
            word.hidden = false;
            word.className = 'lrob-etk-host-status ' + state;
            word.textContent = label;
            card._hostWordTimer = setTimeout(function () { word.hidden = true; }, 3000);
        }
    }

    // Show resolve state for the current host: prefer the cached candidate list,
    // else resolve the typed host directly (debounced) — so an arbitrary/invalid
    // host still reports red instead of silently disappearing.
    function showHostResolve(card) {
        var current = field(card, 'host').value.trim().toLowerCase();
        if (!current || current.indexOf('.') === -1) { paintHostResolve(card, null); return; }
        var match = null;
        (card._hostList || []).forEach(function (h) { if (h.host === current) match = h; });
        if (match) { paintHostResolve(card, match.resolves); return; }
        // Not in the candidate list — resolve it directly, debounced.
        clearTimeout(card._hostResolveTimer);
        card._hostResolveTimer = setTimeout(function () {
            ajax(S.actions.checkHost, { host: current }).then(function (resp) {
                // Ignore stale responses if the host changed meanwhile.
                if (field(card, 'host').value.trim().toLowerCase() !== current) return;
                if (resp.success) paintHostResolve(card, !!resp.data.resolves);
                else paintHostResolve(card, null);
            }).catch(function () { paintHostResolve(card, null); });
        }, 500);
    }

    function setupCombobox(card, name) {
        var combo = card.querySelector('.lrob-etk-combo[data-name="' + name + '"]');
        if (!combo) return;
        if (!window.lrobEtkControls || !window.lrobEtkControls.attachCombobox) return;
        window.lrobEtkControls.attachCombobox(combo, {
            mode: 'free',
            populate: function () { return buildComboOptions(card, name); },
            onSelect: function () {
                if (name === 'host') showHostResolve(card);
                if (name === 'from-email') syncFromWarning(card);
                queueSave(card, 0);
            }
        });
    }

    function buildComboOptions(card, name) {
        var items = [];
        var user = field(card, 'username').value.trim();
        if (name === 'host') {
            // Resolved candidate list (built by checkHosts): host + resolve mark +
            // optional MX priority. Falls back to plain presets before any check.
            if (card._hostList && card._hostList.length) {
                card._hostList.forEach(function (h) {
                    var pri = (h.priority !== null && h.priority !== undefined) ? ' (MX' + h.priority + ')' : '';
                    items.push({ value: h.host, label: h.host + pri, mark: h.resolves ? 'ok' : 'fail' });
                });
            } else {
                var at = user.lastIndexOf('@');
                var domain = at !== -1 ? user.substring(at + 1).toLowerCase() : '';
                if (domain) {
                    items.push({ value: 'mail.' + domain, label: 'mail.' + domain });
                    items.push({ value: 'smtp.' + domain, label: 'smtp.' + domain });
                    items.push({ value: domain, label: domain });
                }
            }
        } else if (name === 'from-email') {
            // Single "Default — <mailbox login>" option with empty value: picking
            // it clears the field so it inherits the live default (shown as the
            // placeholder), exactly like the CF Success-message combo. mail()
            // transport has no login → generic wording.
            var prefix = S.i18n.defaultPrefix;
            var isMail = field(card, 'transport').value === 'mail';
            var emailDefault = isMail
                ? S.i18n.wpSenderShort
                : (user || S.i18n.mailboxLoginShort);
            items.push({ value: '', label: prefix + emailDefault });
        } else if (name === 'from-name') {
            items.push({ value: '', label: S.i18n.defaultPrefix + S.siteTitle });
        }
        return items;
    }

    // Outside-click closure is owned by lrobEtkControls — no local handler needed.

    function syncFromWarning(card) {
        var warn = card.querySelector('.lrob-etk-from-warning-el');
        if (!warn) return;
        var u = field(card, 'username').value.trim();
        var f = field(card, 'from-email').value.trim();
        // No warning when From email is empty (auto mode).
        if (!u || !f || u === f) { warn.hidden = true; return; }
        var uAt = u.lastIndexOf('@'); var fAt = f.lastIndexOf('@');
        if (uAt === -1 || fAt === -1) { warn.hidden = true; return; }
        var uDom = u.substring(uAt + 1); var fDom = f.substring(fAt + 1);
        if (uDom !== fDom) {
            warn.className = 'lrob-etk-from-warning lrob-etk-from-warning-el is-danger';
            warn.textContent = S.i18n.domainMismatch;
        } else {
            warn.className = 'lrob-etk-from-warning lrob-etk-from-warning-el is-warning';
            warn.textContent = S.i18n.userMismatch;
        }
        warn.hidden = false;
    }

    // Resolve the host candidates for the card's domain (presets + MX, deduped
    // server-side). Non-blocking; refreshes card._hostList for the dropdown.
    // domainSource: the username (mailbox) or the current host's domain.
    function checkHosts(card) {
        var src = field(card, 'username').value.trim();
        if (src.indexOf('@') === -1) {
            // No mailbox yet — derive the domain from the host field instead.
            src = field(card, 'host').value.trim();
        }
        var domain = src.indexOf('@') !== -1 ? src.substring(src.lastIndexOf('@') + 1) : src;
        domain = domain.toLowerCase();
        if (!domain || domain.indexOf('.') === -1) return;
        if (card._lastCheckedDomain === domain) return;
        card._lastCheckedDomain = domain;

        ajax(S.actions.checkHosts, { domain: domain }).then(function (resp) {
            if (!resp.success || !resp.data.hosts) return;
            card._hostList = resp.data.hosts;   // populates dropdown marks; badge shows on host change
            showHostResolve(card);
        });
    }

    function wireCardListeners(card) {
        if (card.getAttribute('data-wired') === '1') return;
        card.setAttribute('data-wired', '1');

        var labelInput = field(card, 'label');
        if (labelInput) {
            labelInput.addEventListener('input', function () {
                var initial = field(card, 'slug').getAttribute('data-initial') || '';
                if (initial && field(card, 'slug').value !== '') return;
                var s = slugify(labelInput.value);
                field(card, 'slug').value = s;
            });
        }

        field(card, 'username').addEventListener('input', function () {
            applyCardState(card);
        });
        field(card, 'username').addEventListener('blur', function () { checkHosts(card); });
        field(card, 'smtp-auth').addEventListener('change', function () { applyCardState(card); });
        field(card, 'is-active').addEventListener('change', function () { applyCardState(card); });

        // Combobox: input + dropdown menu for each of these three fields.
        setupCombobox(card, 'host');
        setupCombobox(card, 'from-email');
        setupCombobox(card, 'from-name');

        // Host typing updates the inline resolve mark from the cached list.
        field(card, 'host').addEventListener('input', function () { showHostResolve(card); });
        field(card, 'from-email').addEventListener('input', function () { syncFromWarning(card); });

        // Encryption is a Combobox: its hidden value input fires bubbling `change`.
        var portDefaults = { 'tls': 587, 'ssl': 465, '': 25 };
        var encInput = card.querySelector('[name="smtp_encryption"]');
        if (encInput) {
            encInput.addEventListener('change', function () {
                applyCardState(card);
                var def = portDefaults[encInput.value];
                if (def !== undefined) {
                    field(card, 'port').value = def;   // encryption always drives the port
                }
                // Save is handled by the generic .lrob-etk-combo-value binding below.
            });
        }

        // Transport segmented
        $$('.lrob-etk-transport-segmented', card).forEach(function (group) {
            group.addEventListener('click', function (e) {
                var btn = e.target.closest('button[data-mode]');
                if (!btn) return;
                var hidden = group.querySelector('input[type="hidden"]');
                var mode = btn.getAttribute('data-mode');
                hidden.value = mode;
                $$('button[data-mode]', group).forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                });
                applyCardState(card);
                queueSave(card);
            });
        });

        // Auto-save bindings
        $$('input[type="text"], input[type="email"], input[type="password"], input[type="number"]', card).forEach(function (input) {
            input.addEventListener('input', function () { queueSave(card, 1000); });
            input.addEventListener('blur', function () { flushSave(card); });
        });
        $$('input[type="radio"], input[type="checkbox"]', card).forEach(function (input) {
            input.addEventListener('change', function () { queueSave(card, 0); });
        });
        $$('.lrob-etk-combo-value', card).forEach(function (input) {
            input.addEventListener('change', function () { queueSave(card, 0); });
        });
    }

    function queueSave(card, delay) {
        if (card.getAttribute('data-state') === 'new') return;
        if (card._saveInFlight) {
            card._dirtyDuringFlight = true;
            setStatus(card, 'dirty', S.i18n.dirty);
            return;
        }
        if (card._saveTimer) clearTimeout(card._saveTimer);
        setStatus(card, 'dirty', S.i18n.dirty);
        card._saveTimer = setTimeout(function () {
            card._saveTimer = null;
            saveCard(card);
        }, delay || 0);
    }

    function flushSave(card) {
        if (card._saveTimer) {
            clearTimeout(card._saveTimer);
            card._saveTimer = null;
            return saveCard(card);
        }
        return card._saveInFlight || Promise.resolve();
    }

    function clearFieldErrors(card) {
        $$('.is-invalid', card).forEach(function (n) { n.classList.remove('is-invalid'); });
    }
    function markFieldErrors(card, fields) {
        var first = null;
        Object.keys(fields).forEach(function (name) {
            // label lives on the title input; the rest match by [name].
            var el = name === 'label'
                ? card.querySelector('.lrob-etk-field-label')
                : card.querySelector('[name="' + name + '"]');
            if (!el) return;
            el.classList.add('is-invalid');
            if (!first) first = el;
        });
        if (first && first.focus) first.focus();
    }

    function saveCard(card) {
        if (card._saveInFlight) {
            card._dirtyDuringFlight = true;
            return card._saveInFlight;
        }
        var fd = new FormData(card.querySelector('.lrob-etk-card-form'));
        fd.append('action', S.actions.save);
        fd.append('_nonce', S.nonce);

        var data = S.identities[parseInt(card.getAttribute('data-identity-id'), 10)];
        var wasDefault = data ? !!data.is_default : false;
        var isDefaultNow = field(card, 'is-default').value === '1';
        var defaultToggled = isDefaultNow !== wasDefault;

        setStatus(card, 'saving', S.i18n.saving);
        var errBox = card.querySelector('.lrob-etk-card-error'); errBox.hidden = true;
        clearFieldErrors(card);

        card._saveInFlight = fetch(S.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success) {
                    if (data) data.is_default = isDefaultNow;
                    if (defaultToggled) {
                        setStatus(card, 'saved', S.i18n.saved);
                        setTimeout(function () { window.location.reload(); }, 400);
                        return;
                    }
                    setStatus(card, 'saved', S.i18n.saved);
                    if (card.getAttribute('data-state') === 'new' && resp.data && resp.data.id) {
                        card.setAttribute('data-identity-id', resp.data.id);
                        card.setAttribute('data-state', 'existing');
                        card.classList.remove('is-new');
                        field(card, 'id').value = resp.data.id;
                        setTimeout(function () { window.location.reload(); }, 600);
                    }
                } else {
                    setStatus(card, 'failed', S.i18n.saveFailed);
                    errBox.hidden = false;
                    errBox.textContent = (resp.data && resp.data.message) || S.i18n.unknownError;
                    if (resp.data && resp.data.fields) markFieldErrors(card, resp.data.fields);
                }
            })
            .catch(function () {
                setStatus(card, 'failed', S.i18n.saveFailed);
                errBox.hidden = false;
                errBox.textContent = S.i18n.unknownError;
            })
            .then(function () {
                card._saveInFlight = null;
                if (card._dirtyDuringFlight) {
                    card._dirtyDuringFlight = false;
                    queueSave(card, 0);
                }
            });
        return card._saveInFlight;
    }

    // ---------------- Card actions ----------------
    document.addEventListener('click', function (e) {
        var card = e.target.closest && e.target.closest('.lrob-etk-identity-card');
        if (!card) return;
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.getAttribute('data-action');

        if (action === 'create') {
            saveCard(card);
        } else if (action === 'discard') {
            card.parentNode.removeChild(card);
        } else if (action === 'delete') {
            var label = btn.getAttribute('data-label') || '';
            var ask = window.lrobEtkConfirm
                ? window.lrobEtkConfirm.prompt({
                    title: S.i18n.deleteTitle || 'Delete identity?',
                    message: S.i18n.deleteConfirm.replace('%s', label),
                    confirmLabel: S.i18n.deleteLabel || 'Delete',
                    danger: true
                })
                : Promise.resolve(true);
            ask.then(function (ok) {
                if (!ok) return;
                ajax(S.actions.delete, { id: btn.getAttribute('data-id') }).then(function (resp) {
                    if (resp.success) { flash(resp.data.message, 'success'); setTimeout(function () { window.location.reload(); }, 300); }
                    else flash((resp.data && resp.data.message) || S.i18n.unknownError, 'error');
                });
            });
        } else if (action === 'test') {
            openTestModal(parseInt(btn.getAttribute('data-id'), 10), btn);
        } else if (action === 'test-auth') {
            handleConnTestClick(card, btn);
        }
    });

    // Set-default uses a separate button outside the form-actions cluster.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.lrob-etk-set-default');
        if (!btn) return;
        ajax(S.actions.setDefault, { id: btn.getAttribute('data-id') }).then(function (resp) {
            if (resp.success) { flash(resp.data.message, 'success'); setTimeout(function () { window.location.reload(); }, 300); }
            else flash((resp.data && resp.data.message) || S.i18n.unknownError, 'error');
        });
    });

    // Connection test — runs and shows the result in the popover immediately.
    // The button itself stays neutral (only a transient spinner) — no lingering
    // green/red/blue state; the popover is the single source of result feedback.
    function handleConnTestClick(card, btn) {
        runTestAuth(card, btn);
    }

    function runTestAuth(card, btn) {
        btn.classList.add('is-testing');
        var icon = btn.querySelector('.dashicons');
        var iconWas = icon ? icon.className : '';
        if (icon) icon.className = 'dashicons dashicons-update';
        btn.disabled = true;

        var fd = new FormData(card.querySelector('.lrob-etk-card-form'));
        fd.append('action', S.actions.testAuth);
        fd.append('_nonce', S.nonce);

        return fetch(S.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success) {
                    showConnResult(card, btn, { ok: true, message: resp.data.message, debug: resp.data.debug || null });
                } else {
                    showConnResult(card, btn, {
                        ok: false,
                        message: (resp.data && resp.data.message) || S.i18n.unknownError,
                        debug: (resp.data && resp.data.debug) || null
                    });
                }
            })
            .catch(function () {
                showConnResult(card, btn, { ok: false, message: S.i18n.unknownError, debug: null });
            })
            .then(function () {
                btn.classList.remove('is-testing');
                btn.disabled = false;
                if (icon) icon.className = iconWas;   // restore the play icon — no persistent state
            });
    }

    function showConnResult(card, btn, result) {
        btn._lastResult = result;
        showConnPopover(card, btn);
    }

    function showConnPopover(card, anchorBtn) {
        var popover = document.getElementById('lrob-etk-conn-popover');
        if (!popover) return;
        var result = anchorBtn._lastResult || { ok: false, message: '' };
        popover.classList.toggle('is-success', !!result.ok);
        popover.classList.toggle('is-failure', !result.ok);
        var msg = popover.querySelector('.lrob-etk-popover-message');
        if (msg) msg.textContent = (result.ok ? '✓ ' : '✗ ') + result.message;
        var debug = popover.querySelector('.lrob-etk-popover-debug');
        if (debug) {
            if (result.debug) { debug.textContent = result.debug; debug.hidden = false; }
            else debug.hidden = true;
        }
        var rerun = popover.querySelector('.lrob-etk-popover-rerun');
        if (rerun) {
            rerun.onclick = function () {
                popover.hidden = true;
                runTestAuth(card, anchorBtn);
            };
        }
        anchorPopover(popover, anchorBtn);
    }

    function anchorPopover(popover, anchorEl) {
        popover.hidden = false;
        // Measure after show
        var pRect = popover.getBoundingClientRect();
        var aRect = anchorEl.getBoundingClientRect();
        var width = pRect.width || 320;
        var height = pRect.height || 100;
        var margin = 8;

        var top = aRect.bottom + margin;
        if (top + height > window.innerHeight - margin) {
            top = aRect.top - height - margin;
            if (top < margin) top = margin;
        }
        var left = aRect.left;
        if (left + width > window.innerWidth - margin) {
            left = window.innerWidth - width - margin;
        }
        if (left < margin) left = margin;

        popover.style.position = 'fixed';
        popover.style.top = top + 'px';
        popover.style.left = left + 'px';
    }

    function closeAllPopovers() {
        $$('.lrob-etk-popover').forEach(function (p) { p.hidden = true; });
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest && (e.target.closest('.lrob-etk-popover') || e.target.closest('[data-action="test-auth"]') || e.target.closest('[data-action="test"]'))) {
            return;  // clicks inside or that opened a popover don't close
        }
        closeAllPopovers();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllPopovers();
    });

    // Add identity
    var addBtn = document.getElementById('lrob-etk-add-identity');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var template = document.getElementById('lrob-etk-card-template');
            if (!template) return;
            var fragment = template.content.cloneNode(true);
            var newCard = fragment.querySelector('.lrob-etk-identity-card');
            var container = document.querySelector('.lrob-etk-card-grid');
            if (!container) {
                container = document.createElement('div');
                container.className = 'lrob-etk-card-grid';
                var emptyEl = document.querySelector('.lrob-etk-empty');
                if (emptyEl) emptyEl.parentNode.replaceChild(container, emptyEl);
                else document.querySelector('.lrob-etk-add-row').insertAdjacentElement('beforebegin', container);
            }
            container.appendChild(newCard);
            wireCardListeners(newCard);
            applyCardState(newCard);
            field(newCard, 'label').focus();
            newCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }

    // ---------------- Test email anchored popover ----------------
    function openTestModal(id, anchorBtn) {
        var modal = document.getElementById('lrob-etk-test-modal');
        if (!modal) return;
        document.getElementById('lrob-etk-test-id').value = id || 0;
        var pick = document.getElementById('lrob-etk-test-identity-pick');
        if (pick && id) pick.value = id;
        document.getElementById('lrob-etk-test-result').hidden = true;
        var dialog = modal.querySelector('.lrob-etk-modal-dialog');
        if (anchorBtn && dialog) {
            modal.setAttribute('data-anchored', '1');
            modal.hidden = false;
            anchorPopover(dialog, anchorBtn);
        } else {
            modal.removeAttribute('data-anchored');
            modal.hidden = false;
        }
        document.body.classList.add('lrob-etk-modal-open');
    }
    function closeTestModal() {
        var modal = document.getElementById('lrob-etk-test-modal');
        if (modal) modal.hidden = true;
        document.body.classList.remove('lrob-etk-modal-open');
    }
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('[data-lrob-etk-close]')) {
            e.preventDefault(); closeTestModal();
        }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeTestModal(); });

    var recipientChoice = document.getElementById('lrob-etk-test-recipient-choice');
    if (recipientChoice) {
        recipientChoice.addEventListener('change', function () {
            document.getElementById('lrob-etk-test-custom-wrap').hidden = recipientChoice.value !== 'custom';
        });
    }
    var sendBtn = document.getElementById('lrob-etk-send-test');
    if (sendBtn) {
        sendBtn.addEventListener('click', function () {
            sendBtn.disabled = true; sendBtn.textContent = S.i18n.sending;
            var result = document.getElementById('lrob-etk-test-result');
            result.hidden = false; result.className = 'lrob-etk-test-result lrob-etk-state--off';
            result.textContent = S.i18n.sending;
            var pickEl = document.getElementById('lrob-etk-test-identity-pick');
            var id = pickEl ? pickEl.value : document.getElementById('lrob-etk-test-id').value;
            ajax(S.actions.testSend, {
                id: id,
                recipient_choice: document.getElementById('lrob-etk-test-recipient-choice').value,
                recipient_custom: document.getElementById('lrob-etk-test-recipient-custom').value
            }).then(function (resp) {
                if (resp.success) {
                    result.className = 'lrob-etk-test-result lrob-etk-state--on';
                    result.textContent = '✓ ' + resp.data.message;
                } else {
                    result.className = 'lrob-etk-test-result lrob-etk-state--fail';
                    result.textContent = '✗ ' + ((resp.data && resp.data.message) || S.i18n.unknownError);
                }
            }).finally(function () {
                sendBtn.disabled = false; sendBtn.textContent = S.i18n.sendBtn;
            });
        });
    }

    // Routing form
    var routingForm = document.getElementById('lrob-etk-routing-form');
    if (routingForm) {
        routingForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var routing = {};
            $$('select', routingForm).forEach(function (sel) {
                var m = sel.getAttribute('name').match(/^routing\[(.+)\]$/);
                if (m) routing[m[1]] = sel.value;
            });
            ajax(S.actions.saveRouting, { routing: routing }).then(function (resp) {
                if (resp.success) flash(resp.data.message, 'success');
                else flash((resp.data && resp.data.message) || S.i18n.unknownError, 'error');
            });
        });
    }

    // Initial wiring
    $$('.lrob-etk-identity-card').forEach(function (card) {
        wireCardListeners(card);
        applyCardState(card);
        checkHosts(card);   // pre-resolve so the host dropdown shows marks on first open
    });
})();
