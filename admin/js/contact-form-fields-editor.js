/* LRob Email Toolkit — Contact Form fields editor
 *
 * Inline rows/columns/fields editor that lives on each form card. The PHP
 * side renders the initial state; this script owns all client-side mutations
 * (add/remove/move/edit) and serializes the DOM back to JSON on every
 * change. The server is the source of truth — it normalizes and persists.
 *
 * The status indicator and AJAX nonce piggyback on contact-form-admin.js's
 * lrobEtkCfAdmin global, which is enqueued first.
 */
(function () {
    'use strict';

    var DATA = window.lrobEtkCfAdmin || {};
    var I18N = DATA.i18n || {};
    var DEBOUNCE_MS = 500;

    var FIELD_TYPES = {
        text:     'Text',
        email:    'Email',
        textarea: 'Long text',
        number:   'Number',
        phone:    'Phone',
        date:     'Date',
        select:   'Dropdown',
        radio:    'Radio',
        checkbox: 'Checkbox'
    };

    function init() {
        var sections = document.querySelectorAll('.lrob-etk-cf-fields');
        Array.prototype.forEach.call(sections, bindSection);
    }

    function bindSection(section) {
        if (section.__etkBound) return;
        section.__etkBound = true;

        var formId = parseInt(section.getAttribute('data-form-id'), 10) || 0;
        if (!formId) return;

        var card = section.closest('.lrob-etk-cf-form-card');
        var status = card ? card.querySelector('.lrob-etk-card-status') : null;
        var saveTimer = null;

        function queueSave() {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(doSave, DEBOUNCE_MS);
        }

        function doSave() {
            var structure = serialize(section);
            setStatus('saving');
            var fd = new FormData();
            fd.append('action', 'lrob_etk_cf_save_structure');
            fd.append('_nonce', DATA.nonce || '');
            fd.append('form_id', String(formId));
            fd.append('structure', JSON.stringify(structure));
            fetch(DATA.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                .then(function (resp) {
                    if (resp && resp.success) {
                        setStatus('saved');
                    } else {
                        setStatus('error', (resp && resp.data && resp.data.message) || '');
                    }
                })
                .catch(function () { setStatus('error'); });
        }

        function setStatus(state, detail) {
            if (!status) return;
            status.classList.remove('is-saving', 'is-saved', 'is-error');
            if (state === 'saving') {
                status.classList.add('is-saving');
                status.textContent = I18N.saving || 'Saving…';
            } else if (state === 'saved') {
                status.classList.add('is-saved');
                status.textContent = I18N.saved || 'Saved';
                clearTimeout(status.__hideTimer);
                status.__hideTimer = setTimeout(function () {
                    status.classList.remove('is-saved');
                    status.textContent = '';
                }, 1400);
            } else if (state === 'error') {
                status.classList.add('is-error');
                status.textContent = detail ? ((I18N.error || 'Save failed') + ': ' + detail) : (I18N.error || 'Save failed');
            }
        }

        // ── Action dispatcher ───────────────────────────────────────────
        section.addEventListener('click', function (e) {
            var target = e.target.closest('[data-action]');
            if (!target) return;
            // Allow click-through if user clicked an input inside the summary.
            if (e.target.tagName === 'INPUT' && e.target.type !== 'button') return;

            var action = target.getAttribute('data-action');
            switch (action) {
                case 'add-row':       e.preventDefault(); addRow(); break;
                case 'delete-row':    e.preventDefault(); deleteRow(target); break;
                case 'add-column':    e.preventDefault(); addColumn(target); break;
                case 'delete-col':    e.preventDefault(); deleteColumn(target); break;
                case 'add-field':     e.preventDefault(); showTypePicker(target); break;
                case 'delete-field':  e.preventDefault(); deleteField(target); break;
                case 'toggle-edit':   toggleEdit(target, e); break;
                case 'add-option':    e.preventDefault(); addOption(target); break;
                case 'delete-option': e.preventDefault(); deleteOption(target); break;
            }
        });

        // ── Drag-and-drop reordering ────────────────────────────────────
        // Native HTML5 D&D. Rows / columns / fields are each draggable; drops
        // are constrained to like-type siblings (rows in the form, columns in
        // their row, fields in their column). The handle's `cursor: grab`
        // hints at draggability; inputs and buttons inside don't trigger drag
        // because browsers exclude them from element drag sources.
        var draggedItem = null;

        section.addEventListener('dragstart', function (e) {
            var item = e.target.closest('[data-draggable-type]');
            if (!item) return;
            // Bail if the drag originated on an interactive child (inputs
            // already opt out, but buttons don't always).
            if (e.target.closest('button, .lrob-etk-cf-icon-btn, input, textarea, select')) {
                e.preventDefault();
                return;
            }
            draggedItem = item;
            item.classList.add('is-dragging');
            // Required to actually allow drop on Firefox.
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', item.getAttribute('data-draggable-type')); } catch (err) {}
        });

        section.addEventListener('dragend', function () {
            if (draggedItem) draggedItem.classList.remove('is-dragging');
            clearDropIndicators();
            draggedItem = null;
        });

        section.addEventListener('dragover', function (e) {
            if (!draggedItem) return;
            var type = draggedItem.getAttribute('data-draggable-type');
            var hover = e.target.closest('[data-draggable-type="' + type + '"]');
            if (!hover || hover === draggedItem) {
                clearDropIndicators();
                return;
            }
            if (!sameScope(draggedItem, hover, type)) {
                clearDropIndicators();
                return;
            }
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            var rect = hover.getBoundingClientRect();
            var horizontal = type === 'col';
            var midpoint = horizontal ? rect.left + rect.width / 2 : rect.top + rect.height / 2;
            var coord    = horizontal ? e.clientX : e.clientY;
            var before   = coord < midpoint;
            clearDropIndicators();
            hover.classList.add(before
                ? (horizontal ? 'is-drop-before-h' : 'is-drop-before')
                : (horizontal ? 'is-drop-after-h'  : 'is-drop-after'));
        });

        section.addEventListener('drop', function (e) {
            if (!draggedItem) return;
            var type = draggedItem.getAttribute('data-draggable-type');
            var hover = e.target.closest('[data-draggable-type="' + type + '"]');
            if (!hover || hover === draggedItem || !sameScope(draggedItem, hover, type)) {
                clearDropIndicators();
                return;
            }
            e.preventDefault();
            var rect = hover.getBoundingClientRect();
            var horizontal = type === 'col';
            var midpoint = horizontal ? rect.left + rect.width / 2 : rect.top + rect.height / 2;
            var coord    = horizontal ? e.clientX : e.clientY;
            var before   = coord < midpoint;
            if (before) hover.parentNode.insertBefore(draggedItem, hover);
            else        hover.parentNode.insertBefore(draggedItem, hover.nextSibling);
            clearDropIndicators();
            queueSave();
        });

        function sameScope(dragged, hover, type) {
            // Rows can swap freely within the section. Columns and fields are
            // constrained to their own row / column for now.
            if (type === 'row') return true;
            return dragged.parentNode === hover.parentNode;
        }
        function clearDropIndicators() {
            section.querySelectorAll('.is-drop-before, .is-drop-after, .is-drop-before-h, .is-drop-after-h')
                .forEach(function (el) {
                    el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-before-h', 'is-drop-after-h');
                });
        }

        // Input changes inside the section → save.
        section.addEventListener('input', function (e) {
            if (e.target.matches('[data-prop], [data-option-prop], [data-submit-prop]')) {
                updateLabelDisplay(e.target);
                queueSave();
            }
        });
        section.addEventListener('change', function (e) {
            if (e.target.matches('[data-prop], [data-submit-prop]')) {
                updateLabelDisplay(e.target);
                queueSave();
            }
        });

        // ── Mutators ────────────────────────────────────────────────────
        function rowsRoot() { return section.querySelector('[data-rows-root]'); }

        function addRow() {
            var rowsEl = rowsRoot();
            rowsEl.appendChild(buildRow([buildColumn([])]));
            queueSave();
        }
        function deleteRow(btn) {
            var row = btn.closest('.lrob-etk-cf-editor-row');
            if (!row) return;
            row.remove();
            queueSave();
        }

        function addColumn(btn) {
            var row = btn.closest('.lrob-etk-cf-editor-row');
            var cols = row.querySelector('.lrob-etk-cf-editor-cols');
            var existing = cols.querySelectorAll('.lrob-etk-cf-editor-col').length;
            if (existing >= 4) return;
            cols.insertBefore(buildColumn([]), btn);
            cols.setAttribute('data-cols', String(existing + 1));
            updateRowLabel(row);
            if (existing + 1 >= 4) btn.remove();
            queueSave();
        }
        function deleteColumn(btn) {
            var col = btn.closest('.lrob-etk-cf-editor-col');
            var row = btn.closest('.lrob-etk-cf-editor-row');
            if (!col || !row) return;
            var cols = row.querySelector('.lrob-etk-cf-editor-cols');
            // Keep at least one column.
            if (cols.querySelectorAll('.lrob-etk-cf-editor-col').length <= 1) return;
            col.remove();
            var remaining = cols.querySelectorAll('.lrob-etk-cf-editor-col').length;
            cols.setAttribute('data-cols', String(remaining));
            updateRowLabel(row);
            // If add-col button is gone (had 4 cols), restore it.
            if (!cols.querySelector('.lrob-etk-cf-add-col')) {
                cols.appendChild(buildAddColButton());
            }
            queueSave();
        }
        function showTypePicker(btn) {
            var col = btn.closest('.lrob-etk-cf-editor-col');
            if (!col) return;
            // Close any open picker first.
            section.querySelectorAll('.lrob-etk-cf-type-picker').forEach(function (p) { p.remove(); });

            var tpl = section.querySelector('template[data-field-type-picker]');
            if (!tpl) return;
            var picker = tpl.content.firstElementChild.cloneNode(true);

            picker.addEventListener('click', function (e) {
                var typeBtn = e.target.closest('[data-type]');
                if (!typeBtn) return;
                e.preventDefault();
                var type = typeBtn.getAttribute('data-type');
                // Insert the new field just before the trailing "Add field" button.
                var addBtn = col.querySelector(':scope > .lrob-etk-cf-add-field');
                col.insertBefore(buildField(type), addBtn);
                picker.remove();
                queueSave();
            });

            // Position picker below the +Field button.
            btn.parentNode.insertBefore(picker, btn.nextSibling);

            // Close on outside click.
            setTimeout(function () {
                document.addEventListener('click', function onDocClick(ev) {
                    if (!picker.contains(ev.target) && ev.target !== btn) {
                        picker.remove();
                        document.removeEventListener('click', onDocClick);
                    }
                });
            }, 0);
        }
        function deleteField(btn) {
            var field = btn.closest('.lrob-etk-cf-editor-field');
            if (!field) return;
            field.remove();
            queueSave();
        }

        function toggleEdit(target, e) {
            // Don't toggle if user clicked one of the inline action buttons.
            if (e.target.closest('[data-action]') !== target) return;
            var field = target.closest('.lrob-etk-cf-editor-field');
            if (!field) return;
            var body = field.querySelector('.lrob-etk-cf-editor-field-body');
            if (!body) return;
            body.hidden = !body.hidden;
            field.classList.toggle('is-editing', !body.hidden);
        }

        function addOption(btn) {
            var root = btn.closest('[data-options-root]');
            var list = root ? root.querySelector('[data-options-list]') : null;
            if (!list) return;
            var row = document.createElement('div');
            row.className = 'lrob-etk-cf-editor-option';
            row.setAttribute('data-option', '');
            row.innerHTML =
                '<input type="text" data-option-prop="label" placeholder="Label">' +
                '<input type="text" data-option-prop="value" placeholder="value">' +
                '<button type="button" class="lrob-etk-cf-icon-btn lrob-etk-cf-icon-btn--delete" data-action="delete-option" aria-label="Remove option">' +
                '<span class="dashicons dashicons-no-alt"></span></button>';
            list.appendChild(row);
            queueSave();
        }
        function deleteOption(btn) {
            var row = btn.closest('[data-option]');
            if (!row) return;
            row.remove();
            queueSave();
        }

        function updateLabelDisplay(input) {
            if (input.getAttribute('data-prop') !== 'label') return;
            var field = input.closest('.lrob-etk-cf-editor-field');
            if (!field) return;
            var summary = field.querySelector('.lrob-etk-cf-editor-field-label');
            if (summary) summary.textContent = input.value || '(no label)';
        }

        function updateRowLabel(row) {
            var label = row.querySelector('.lrob-etk-cf-editor-row-label');
            if (!label) return;
            var n = row.querySelectorAll('.lrob-etk-cf-editor-col').length;
            label.textContent = 'Row · ' + n + ' ' + (n === 1 ? 'column' : 'columns');
        }

        // ── DOM builders for newly-added nodes ─────────────────────────
        function genId(prefix) {
            return prefix + '_' + Math.random().toString(36).substr(2, 8);
        }
        function el(html) {
            var wrap = document.createElement('div');
            wrap.innerHTML = html.trim();
            return wrap.firstElementChild;
        }
        function buildAddColButton() {
            return el('<button type="button" class="lrob-etk-cf-add-col" data-action="add-column" aria-label="Add column"><span class="dashicons dashicons-plus-alt2"></span></button>');
        }

        function buildRow(columns) {
            var rowId = genId('row');
            var row = el(
                '<div class="lrob-etk-cf-editor-row" data-row-id="' + rowId + '" data-draggable-type="row" draggable="true">' +
                '  <div class="lrob-etk-cf-editor-row-head">' +
                '    <span class="lrob-etk-cf-drag-handle dashicons dashicons-move" aria-hidden="true"></span>' +
                '    <span class="lrob-etk-cf-editor-row-label">Row · 1 column</span>' +
                '    <button type="button" class="lrob-etk-cf-icon-btn lrob-etk-cf-icon-btn--delete" data-action="delete-row"><span class="dashicons dashicons-trash"></span></button>' +
                '  </div>' +
                '  <div class="lrob-etk-cf-editor-cols" data-cols="' + columns.length + '"></div>' +
                '</div>'
            );
            var colsContainer = row.querySelector('.lrob-etk-cf-editor-cols');
            columns.forEach(function (c) { colsContainer.appendChild(c); });
            colsContainer.appendChild(buildAddColButton());
            updateRowLabel(row);
            return row;
        }
        function buildColumn(fields) {
            var colId = genId('col');
            var col = el(
                '<div class="lrob-etk-cf-editor-col" data-col-id="' + colId + '" data-draggable-type="col" draggable="true">' +
                '  <div class="lrob-etk-cf-editor-col-head">' +
                '    <span class="lrob-etk-cf-drag-handle dashicons dashicons-move" aria-hidden="true"></span>' +
                '    <button type="button" class="lrob-etk-cf-icon-btn lrob-etk-cf-icon-btn--delete" data-action="delete-col"><span class="dashicons dashicons-trash"></span></button>' +
                '  </div>' +
                '  <button type="button" class="lrob-etk-cf-add-field" data-action="add-field">' +
                '    <span class="dashicons dashicons-plus-alt2"></span>Add field</button>' +
                '</div>'
            );
            // Fields are direct children of the column (no fields-root wrapper).
            // Insert each field before the trailing "Add field" button.
            var addBtn = col.querySelector('.lrob-etk-cf-add-field');
            fields.forEach(function (f) { col.insertBefore(f, addBtn); });
            return col;
        }
        function buildField(type) {
            var id = genId('f');
            var typeLabel = FIELD_TYPES[type] || type;
            var defaultLabel = typeLabel;
            var bodyHtml = buildFieldBodyHtml(type);
            var field = el(
                '<div class="lrob-etk-cf-editor-field is-editing" data-field-id="' + id + '" data-field-type="' + type + '" data-draggable-type="field" draggable="true">' +
                '  <div class="lrob-etk-cf-editor-field-summary" data-action="toggle-edit">' +
                '    <span class="lrob-etk-cf-drag-handle dashicons dashicons-move" aria-hidden="true"></span>' +
                '    <span class="lrob-etk-cf-editor-field-type">' + escapeHtml(typeLabel) + '</span>' +
                '    <span class="lrob-etk-cf-editor-field-label">' + escapeHtml(defaultLabel) + '</span>' +
                '    <span class="lrob-etk-cf-editor-field-actions">' +
                '      <button type="button" class="lrob-etk-cf-icon-btn lrob-etk-cf-icon-btn--delete" data-action="delete-field"><span class="dashicons dashicons-trash"></span></button>' +
                '    </span>' +
                '  </div>' +
                '  <div class="lrob-etk-cf-editor-field-body">' + bodyHtml + '</div>' +
                '</div>'
            );
            // Pre-fill label input with the type name as a starting placeholder.
            var labelInput = field.querySelector('[data-prop="label"]');
            if (labelInput) labelInput.value = defaultLabel;
            var slugInput = field.querySelector('[data-prop="slug"]');
            if (slugInput) slugInput.value = slugify(defaultLabel) || type;
            return field;
        }

        function buildFieldBodyHtml(type) {
            var html =
                '<div class="lrob-etk-cf-editor-field-grid">' +
                '<div class="lrob-etk-field"><label>Label</label><input type="text" data-prop="label" value=""></div>' +
                '<div class="lrob-etk-field"><label>Field slug</label><input type="text" data-prop="slug" value=""></div>' +
                '<div class="lrob-etk-field lrob-etk-cf-editor-field-grid-full"><label>Helper text</label><input type="text" data-prop="helper" value=""></div>';
            if (['text','email','textarea','number','phone','date'].indexOf(type) !== -1) {
                html += '<div class="lrob-etk-field lrob-etk-cf-editor-field-grid-full"><label>Placeholder</label><input type="text" data-prop="placeholder" value=""></div>';
            }
            html += '<div class="lrob-etk-field lrob-etk-cf-editor-field-grid-full"><label class="lrob-etk-cf-inline-check"><input type="checkbox" data-prop="required"> Required</label></div>';
            html += buildTypeSpecificHtml(type);
            html += '</div>';
            return html;
        }

        function buildTypeSpecificHtml(type) {
            switch (type) {
                case 'textarea':
                    return '<div class="lrob-etk-field"><label>Rows</label><input type="number" min="2" max="20" data-prop="rows" value="5"></div>' +
                           '<div class="lrob-etk-field"><label>Max length</label><input type="number" min="0" data-prop="maxLength" value="0"></div>';
                case 'text':
                case 'email':
                    return '<div class="lrob-etk-field"><label>Max length</label><input type="number" min="0" data-prop="maxLength" value="0"></div>';
                case 'number':
                    return '<div class="lrob-etk-field"><label>Min</label><input type="text" data-prop="min" value=""></div>' +
                           '<div class="lrob-etk-field"><label>Max</label><input type="text" data-prop="max" value=""></div>' +
                           '<div class="lrob-etk-field"><label>Step</label><input type="text" data-prop="step" value=""></div>';
                case 'phone':
                    return '<div class="lrob-etk-field lrob-etk-cf-editor-field-grid-full"><label>Regex pattern (optional)</label><input type="text" data-prop="pattern" value=""></div>';
                case 'date':
                    return '<div class="lrob-etk-field"><label>Earliest (YYYY-MM-DD)</label><input type="text" data-prop="min" value=""></div>' +
                           '<div class="lrob-etk-field"><label>Latest (YYYY-MM-DD)</label><input type="text" data-prop="max" value=""></div>';
                case 'select':
                case 'radio':
                    return optionsEditorHtml();
                case 'checkbox':
                    return '<div class="lrob-etk-field lrob-etk-cf-editor-field-grid-full"><label class="lrob-etk-cf-inline-check"><input type="checkbox" data-prop="multiple" checked> Multiple choices</label></div>' +
                           optionsEditorHtml();
                default:
                    return '';
            }
        }
        function optionsEditorHtml() {
            return '<div class="lrob-etk-field lrob-etk-cf-editor-field-grid-full lrob-etk-cf-editor-options" data-options-root>' +
                   '<label>Options</label>' +
                   '<div class="lrob-etk-cf-editor-options-list" data-options-list></div>' +
                   '<button type="button" class="button-link" data-action="add-option">+ Add option</button>' +
                   '</div>';
        }

        function slugify(s) {
            return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').substr(0, 40);
        }
        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        // ── Serializer: DOM → structure JSON ───────────────────────────
        function serialize(section) {
            var rows = [];
            var rowEls = section.querySelectorAll('.lrob-etk-cf-editor-row');
            Array.prototype.forEach.call(rowEls, function (rowEl) {
                var cols = [];
                var colEls = rowEl.querySelectorAll('.lrob-etk-cf-editor-col');
                Array.prototype.forEach.call(colEls, function (colEl) {
                    var fields = [];
                    var fieldEls = colEl.querySelectorAll('.lrob-etk-cf-editor-field');
                    Array.prototype.forEach.call(fieldEls, function (fEl) {
                        fields.push(serializeField(fEl));
                    });
                    cols.push({
                        id:     colEl.getAttribute('data-col-id') || '',
                        fields: fields
                    });
                });
                rows.push({
                    id:      rowEl.getAttribute('data-row-id') || '',
                    columns: cols
                });
            });

            var submitText = section.querySelector('[data-submit-prop="text"]');
            var submitAlign = section.querySelector('[data-submit-prop="align"]');
            return {
                version: 1,
                submit: {
                    text:  submitText ? submitText.value : 'Send',
                    align: submitAlign ? submitAlign.value : 'right'
                },
                rows: rows
            };
        }

        function serializeField(fEl) {
            var field = {
                id:   fEl.getAttribute('data-field-id') || '',
                type: fEl.getAttribute('data-field-type') || 'text'
            };
            var props = fEl.querySelectorAll('[data-prop]');
            Array.prototype.forEach.call(props, function (p) {
                var key = p.getAttribute('data-prop');
                if (p.type === 'checkbox') {
                    field[key] = p.checked;
                } else if (p.type === 'number') {
                    var n = parseInt(p.value, 10);
                    field[key] = isNaN(n) ? 0 : n;
                } else {
                    field[key] = p.value;
                }
            });
            // Options for select/radio/checkbox.
            var optionsList = fEl.querySelector('[data-options-list]');
            if (optionsList) {
                var options = [];
                optionsList.querySelectorAll('[data-option]').forEach(function (optEl) {
                    var labelEl = optEl.querySelector('[data-option-prop="label"]');
                    var valueEl = optEl.querySelector('[data-option-prop="value"]');
                    var label = labelEl ? labelEl.value : '';
                    var value = valueEl ? valueEl.value : '';
                    if (label === '' && value === '') return;
                    options.push({ label: label || value, value: value || slugify(label) });
                });
                field.options = options;
            }
            return field;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
