/* Docs: docs/contact-form.md */
(function () {
    'use strict';

    var DATA = window.lrobEtkCfAdmin || {};
    var I18N = DATA.i18n || {};
    var TYPING_DEBOUNCE_MS = 600;

    function init() {
        var cards = document.querySelectorAll('.lrob-etk-form-card');
        Array.prototype.forEach.call(cards, bindCard);
    }

    function bindCard(card) {
        if (card.__lrobEtkCfBound) return;
        card.__lrobEtkCfBound = true;

        var saveCombo = card.querySelector('input.lrob-etk-combo-value[data-key="_lrob_etk_cf_save_submissions"]');
        if (saveCombo) {
            var resync = function () {
                var v = saveCombo.value || '';
                var globalOff = card.getAttribute('data-save-global-off') === '1';
                var effectiveOff = (v === 'off') || (v === '' || v === 'default') && globalOff;
                card.setAttribute('data-save-effective-off', effectiveOff ? '1' : '0');
            };
            saveCombo.addEventListener('change', resync);
            // Initial state already set server-side; resync once anyway in
            // case the page was rendered before this script bound.
            resync();
        }

        var isDefaults = card.getAttribute('data-defaults-card') === '1';
        var formId = parseInt(card.getAttribute('data-form-id'), 10) || 0;
        if (!isDefaults && !formId) return;

        // readValue hook converts rate-limit window minutes → seconds before posting.
        if (window.lrobEtkAutosave) {
            window.lrobEtkAutosave.attach(card, {
                fieldSelector: '.lrob-etk-cf-field',
                debounceMs: TYPING_DEBOUNCE_MS,
                readValue: function (field) {
                    if (field.dataset.unit === 'minutes') {
                        var n = parseInt(field.value, 10);
                        if (!n || n <= 0) return 0;
                        return n * 60;
                    }
                    return field.value;
                },
                save: function (field, value) {
                    var fd = new FormData();
                    fd.append('action', isDefaults ? DATA.actionDefault : DATA.action);
                    fd.append('_nonce', DATA.nonce);
                    if (!isDefaults) {
                        fd.append('form_id', String(formId));
                    }
                    fd.append('key', field.dataset.key);
                    fd.append('value', String(value));
                    return fetch(DATA.ajaxUrl, {
                        method: 'POST', credentials: 'same-origin', body: fd
                    }).then(function (r) {
                        return r.json().catch(function () { return { success: false }; });
                    });
                },
                i18n: { saving: I18N.saving, saved: I18N.saved, error: I18N.error }
            });
        }

        var freeCombos = card.querySelectorAll('.lrob-etk-combo--free');
        Array.prototype.forEach.call(freeCombos, function (combo) {
            if (combo.__etkBound) return;
            var options;
            try { options = JSON.parse(combo.getAttribute('data-options') || '[]'); }
            catch (e) { options = []; }
            if (window.lrobEtkControls && window.lrobEtkControls.attachCombobox) {
                window.lrobEtkControls.attachCombobox(combo, {
                    mode: 'free',
                    populate: function () { return options; }
                });
            }
        });

        var lists = card.querySelectorAll('[data-recipient-input]');
        Array.prototype.forEach.call(lists, bindRecipientList);

        var presetHidden = card.querySelector('input[data-key="_lrob_etk_cf_style_preset"]');
        var previewForm = card.querySelector('.lrob-etk-form.is-editor');
        if (presetHidden && previewForm) {
            applyPreset(previewForm, presetHidden.value);
            presetHidden.addEventListener('change', function () {
                applyPreset(previewForm, presetHidden.value);
            });
        }
    }

    function applyPreset(previewForm, slug) {
        // Presets are data-driven: clear any previously-applied preset vars,
        // then set the chosen preset's vars inline (mirrors StyleResolver).
        var data = (window.lrobEtkCfAdmin && window.lrobEtkCfAdmin.stylePresets) || { presets: {}, vars: [] };
        (data.vars || []).forEach(function (cssVar) { previewForm.style.removeProperty(cssVar); });
        var vars = (data.presets && data.presets[slug]) || {};
        Object.keys(vars).forEach(function (cssVar) { previewForm.style.setProperty(cssVar, vars[cssVar]); });
        previewForm.setAttribute('data-preset', slug || '');
    }

    function bindRecipientList(container) {
        if (container.__lrobEtkCfBound) return;
        container.__lrobEtkCfBound = true;

        var hidden = container.querySelector('input[type="hidden"][data-key]');
        var rows = container.querySelector('[data-recipient-rows]');
        var addBtn = container.querySelector('[data-recipient-add]');
        if (!hidden || !rows) return;

        var knownEmails = Array.isArray(DATA.knownEmails) ? DATA.knownEmails : [];
        var rowPlaceholder = (I18N.recipientPh || 'email@example.com');

        function serialize() {
            var values = Array.prototype.map.call(
                rows.querySelectorAll('.lrob-etk-combo-input'),
                function (input) { return (input.value || '').trim(); }
            ).filter(function (v) { return v !== ''; });
            var joined = values.join(', ');
            if (hidden.value === joined) return;
            hidden.value = joined;
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function updateRemoveButtons() {
            var rowEls = rows.querySelectorAll('.lrob-etk-recipient-row');
            var only = rowEls.length === 1;
            Array.prototype.forEach.call(rowEls, function (row) {
                var btn = row.querySelector('.lrob-etk-recipient-remove');
                if (btn) btn.hidden = only;
            });
        }

        function addRow(value) {
            var row = document.createElement('div');
            row.className = 'lrob-etk-recipient-row';
            row.innerHTML =
                '<div class="lrob-etk-combo">' +
                    '<input type="email" class="lrob-etk-combo-input" placeholder="' + escapeAttr(rowPlaceholder) + '" autocomplete="off">' +
                    '<button type="button" class="lrob-etk-combo-toggle" aria-label="' + escapeAttr(I18N.pickKnown || 'Pick a known email') + '" title="' + escapeAttr(I18N.pickKnown || 'Pick a known email') + '"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>' +
                '</div>' +
                '<button type="button" class="lrob-etk-recipient-remove" aria-label="' + escapeAttr(I18N.removeRow || 'Remove') + '" title="' + escapeAttr(I18N.removeRow || 'Remove') + '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>';
            if (value) row.querySelector('input').value = value;
            rows.appendChild(row);
            updateRemoveButtons();
            return row;
        }

        function removeRow(row) {
            var rowEls = rows.querySelectorAll('.lrob-etk-recipient-row');
            if (rowEls.length <= 1) {
                // Keep at least one row visible — just clear it.
                var input = row.querySelector('.lrob-etk-combo-input');
                if (input) input.value = '';
            } else {
                row.parentNode.removeChild(row);
            }
            updateRemoveButtons();
            serialize();
        }

        function openPickMenu(button) {
            closePickMenu();
            if (knownEmails.length === 0) return;
            var menu = document.createElement('div');
            menu.className = 'lrob-etk-menu lrob-etk-menu--fixed';
            knownEmails.forEach(function (item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'lrob-etk-menu-item';
                btn.textContent = item.label;
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var row = button.closest('.lrob-etk-recipient-row');
                    if (!row) return;
                    var input = row.querySelector('.lrob-etk-combo-input');
                    if (input) {
                        input.value = item.value;
                        serialize();
                    }
                    closePickMenu();
                });
                menu.appendChild(btn);
            });
            // Must be inside .lrob-etk so --etk-* tokens resolve (body = out of scope).
            (container.closest('.lrob-etk') || document.body).appendChild(menu);
            positionMenu(menu, button);
            menu.__owner = button;
            container.__pickMenu = menu;
            document.addEventListener('mousedown', onDocMouseDown, true);
            window.addEventListener('scroll', closePickMenu, true);
            window.addEventListener('resize', closePickMenu);
        }

        function positionMenu(menu, anchor) {
            var combo = anchor.closest('.lrob-etk-combo') || anchor;
            var rect = combo.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = (rect.bottom + 4) + 'px';
            menu.style.left = rect.left + 'px';
            menu.style.minWidth = '0';
            menu.style.maxWidth = 'none';
            menu.style.width = rect.width + 'px';
        }

        function onDocMouseDown(e) {
            if (container.__pickMenu && !container.__pickMenu.contains(e.target)) {
                closePickMenu();
            }
        }

        function closePickMenu() {
            if (container.__pickMenu && container.__pickMenu.parentNode) {
                container.__pickMenu.parentNode.removeChild(container.__pickMenu);
            }
            container.__pickMenu = null;
            document.removeEventListener('mousedown', onDocMouseDown, true);
            window.removeEventListener('scroll', closePickMenu, true);
            window.removeEventListener('resize', closePickMenu);
        }

        function escapeAttr(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        // Initial setup for already-rendered rows
        updateRemoveButtons();

        // Delegate row events to the rows container so dynamically-added rows
        // pick the same handlers automatically.
        rows.addEventListener('input', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('lrob-etk-combo-input')) {
                clearTimeout(rows.__typingTimer);
                rows.__typingTimer = setTimeout(serialize, TYPING_DEBOUNCE_MS);
            }
        });
        rows.addEventListener('blur', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('lrob-etk-combo-input')) {
                clearTimeout(rows.__typingTimer);
                serialize();
            }
        }, true);
        rows.addEventListener('click', function (e) {
            var pick = e.target.closest('.lrob-etk-combo-toggle');
            var remove = e.target.closest('.lrob-etk-recipient-remove');
            if (pick) {
                openPickMenu(pick);
                return;
            }
            if (remove) {
                var row = remove.closest('.lrob-etk-recipient-row');
                if (row) removeRow(row);
            }
        });

        if (addBtn) {
            addBtn.addEventListener('click', function () {
                var row = addRow('');
                var input = row.querySelector('.lrob-etk-combo-input');
                if (input) input.focus();
            });
        }
    }

    /**
     * Smooth-scroll to the new form card after the create-form picker
     * navigates here with `#form-<id>`. The browser's native hash jump is
     * instant and easy to miss — sliding the card into view makes it clear
     * where the new entry landed (now at the bottom since we order ASC).
     */
    function smoothScrollToHash() {
        var hash = window.location.hash;
        if (!hash || hash.indexOf('#form-') !== 0) return;
        var el = document.getElementById(hash.substring(1));
        if (!el) return;
        // Defer a frame so layout has settled.
        setTimeout(function () {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 50);
    }

    /**
     * Storage modal: when "Save submissions" flips to Off, every other
     * setting in the modal (IP storage, spam recording, retention)
     * becomes inert. Dim + disable them so admins immediately see they
     * can't act on those fields. Runs on whatever page renders the
     * Storage modal (FormsPage AND the Submissions inbox).
     */
    function bindStorageConditional() {
        var card = document.querySelector('[data-storage-card]');
        if (!card) return;
        var saveCombo = card.querySelector('input.lrob-etk-combo-value[data-key="save_submissions"]');
        function apply() {
            var on = !saveCombo || saveCombo.value !== '0';
            if (on) card.removeAttribute('data-save-off');
            else card.setAttribute('data-save-off', '');
            var inputs = card.querySelectorAll('[data-storage-conditional] input, [data-storage-conditional] select, [data-storage-conditional] button');
            Array.prototype.forEach.call(inputs, function (i) { i.disabled = !on; });
        }
        apply();
        if (saveCombo) saveCombo.addEventListener('change', apply);
    }

    function bootstrap() {
        init();
        if (window.lrobEtkModal) {
            window.lrobEtkModal.bindHeader('lrob-etk-defaults-modal',    'lrob-etk-defaults-btn');
            window.lrobEtkModal.bindHeader('lrob-etk-cf-storage-modal',  'lrob-etk-cf-storage-btn');
        }
        bindStorageConditional();
        smoothScrollToHash();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
