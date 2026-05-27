/* LRob Email Toolkit — Contact Form admin page
 *
 * Auto-save for every input inside a .lrob-etk-form-card. Each field has a
 * data-key attribute that tells the server which setting to update. Saves
 * debounce on text inputs (after typing pauses + on blur), fire immediately
 * on selects, and surface a small per-card status indicator.
 *
 * Vanilla JS, no deps. Matches the SMTP card pattern in spirit but is its
 * own implementation — the server endpoint is simpler (single meta save).
 */
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

        // Track the live save_submissions tri-state combo so the CSS rule
        // on data-save-effective-off can flip the warning under any
        // file_upload field in the same card without a page reload. The
        // hidden combo-value carries 'default' | 'on' | 'off'.
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

        // The global Defaults card uses data-defaults-card="1" and writes
        // to the contact-form settings option via a different AJAX action.
        // Per-form cards have data-form-id and write to post_meta.
        var isDefaults = card.getAttribute('data-defaults-card') === '1';
        var formId = parseInt(card.getAttribute('data-form-id'), 10) || 0;
        if (!isDefaults && !formId) return;

        // Hand the per-key autosave plumbing to the shared helper. We
        // supply the field selector, a value reader (minutes → seconds
        // conversion for rate-limit window), and a save function that
        // knows which AJAX action + extra form_id to send.
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

        // Wire free-mode comboboxes (Subject template + Success message)
        // — same shape as the SMTP host combobox: typeable input + a
        // dropdown of suggestions (currently just the inherit default).
        var freeCombos = card.querySelectorAll('.lrob-etk-cf-free-combo');
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

        // Wire up any recipient-list widgets in this card.
        var lists = card.querySelectorAll('[data-recipient-input]');
        Array.prototype.forEach.call(lists, bindRecipientList);

        // Live-update the WYSIWYG preview when the style preset changes —
        // so the admin sees the form's actual look without reloading. The
        // preset value is the hidden mirror behind the combobox; auto-save
        // is already wired separately.
        var presetHidden = card.querySelector('input[data-key="_lrob_etk_cf_style_preset"]');
        var previewForm = card.querySelector('.lrob-etk-cf-form.is-editor');
        if (presetHidden && previewForm) {
            applyPreset(previewForm, presetHidden.value);
            presetHidden.addEventListener('change', function () {
                applyPreset(previewForm, presetHidden.value);
            });
        }
    }

    /**
     * Swap the .lrob-etk-cf-preset--X class on the preview form so the
     * editor reflects the active style. Empty value = use whatever the
     * defaults section is rendering (no class — frontend CSS picks the
     * default look). The slug is also stored on a data-attr so future
     * style knobs can read it.
     */
    function applyPreset(previewForm, slug) {
        // Strip any previous preset class.
        var toRemove = [];
        for (var i = 0; i < previewForm.classList.length; i++) {
            if (previewForm.classList[i].indexOf('lrob-etk-cf-preset--') === 0) {
                toRemove.push(previewForm.classList[i]);
            }
        }
        toRemove.forEach(function (c) { previewForm.classList.remove(c); });
        if (slug) {
            previewForm.classList.add('lrob-etk-cf-preset--' + slug);
        }
        previewForm.setAttribute('data-preset', slug || '');
    }

    /**
     * Stack of single-email rows that serializes back into the hidden
     * comma-separated mirror input. Chevron menu offers the known emails
     * shipped via lrobEtkCfAdmin.knownEmails. Empty rows are skipped on
     * serialize so an in-progress edit doesn't push trailing commas.
     */
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
                rows.querySelectorAll('.lrob-etk-recipient-input'),
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
                '<div class="lrob-etk-recipient-shell">' +
                    '<input type="email" class="lrob-etk-recipient-input" placeholder="' + escapeAttr(rowPlaceholder) + '" autocomplete="off">' +
                    '<button type="button" class="lrob-etk-recipient-pick" aria-label="' + escapeAttr(I18N.pickKnown || 'Pick a known email') + '" title="' + escapeAttr(I18N.pickKnown || 'Pick a known email') + '"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>' +
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
                var input = row.querySelector('.lrob-etk-recipient-input');
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
                    var input = row.querySelector('.lrob-etk-recipient-input');
                    if (input) {
                        input.value = item.value;
                        serialize();
                    }
                    closePickMenu();
                });
                menu.appendChild(btn);
            });
            document.body.appendChild(menu);
            positionMenu(menu, button);
            menu.__owner = button;
            container.__pickMenu = menu;
            document.addEventListener('mousedown', onDocMouseDown, true);
            window.addEventListener('scroll', closePickMenu, true);
            window.addEventListener('resize', closePickMenu);
        }

        function positionMenu(menu, anchor) {
            var rect = anchor.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = (rect.bottom + 4) + 'px';
            menu.style.left = Math.max(8, rect.right - menu.offsetWidth) + 'px';
            // Now that the menu is in the DOM we know its width; re-clamp.
            var width = menu.getBoundingClientRect().width;
            menu.style.left = Math.max(8, rect.right - width) + 'px';
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
            if (e.target && e.target.classList && e.target.classList.contains('lrob-etk-recipient-input')) {
                clearTimeout(rows.__typingTimer);
                rows.__typingTimer = setTimeout(serialize, TYPING_DEBOUNCE_MS);
            }
        });
        rows.addEventListener('blur', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('lrob-etk-recipient-input')) {
                clearTimeout(rows.__typingTimer);
                serialize();
            }
        }, true);
        rows.addEventListener('click', function (e) {
            var pick = e.target.closest('.lrob-etk-recipient-pick');
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
                var input = row.querySelector('.lrob-etk-recipient-input');
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
