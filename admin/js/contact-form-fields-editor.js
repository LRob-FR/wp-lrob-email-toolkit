/* LRob Email Toolkit — Contact Form WYSIWYG editor
 *
 * The form's editor DOM mirrors the frontend output exactly (same
 * FieldRenderer, same CSS). This script overlays the editing layer:
 *
 *   • Hover affordances (CSS-driven visibility, JS-driven clicks).
 *   • Click-to-edit labels, helpers, and the submit-button text via
 *     contenteditable spans.
 *   • Click-an-input → swap placeholder ↔ value so the user types the
 *     placeholder directly while editing, then it snaps back on blur.
 *   • Gear popup anchored to the gear icon: slug, required, helper,
 *     and per-type options (rows, min/max, options list, etc.).
 *   • "+" insertion zones between every pair of rows/fields and at the
 *     end of each row for new columns. Clicking opens a type picker.
 *   • Drag-and-drop reorder for rows, columns, fields (same scope as
 *     before: rows free, cols within a row, fields within a column).
 *   • Auto-save: any mutation serializes the form DOM to JSON and POSTs
 *     to lrob_etk_cf_save_structure.
 *
 * The serializer is DOM-as-source-of-truth: the editor DOM IS the form
 * state. Server-side FormStructure::normalize() drops anything malformed,
 * so a partially-bad client mutation can never wipe the form.
 */
(function () {
    'use strict';

    var SAVE_DATA = window.lrobEtkCfAdmin || {};
    var EDITOR_DATA = window.lrobEtkCfEditor || {};
    var I18N = SAVE_DATA.i18n || {};
    var EDITOR_I18N = EDITOR_DATA.i18n || {};
    var FIELD_TYPES = EDITOR_DATA.fieldTypes || {};
    var SAVE_DEBOUNCE_MS = 500;

    function init() {
        var sections = document.querySelectorAll('.lrob-etk-cf-fields');
        Array.prototype.forEach.call(sections, bindSection);
    }

    function bindSection(section) {
        if (section.__etkBound) return;
        section.__etkBound = true;

        var formId = parseInt(section.getAttribute('data-form-id'), 10) || 0;
        if (!formId) return;

        var form = section.querySelector('.lrob-etk-cf-form.is-editor');
        if (!form) return;

        // Status indicator + undo/redo buttons live in the toolbar directly
        // above the form preview, so feedback sits next to the action.
        var status = section.querySelector('.lrob-etk-cf-editor-status');
        var undoBtn = section.querySelector('[data-editor-action="undo"]');
        var redoBtn = section.querySelector('[data-editor-action="redo"]');
        if (undoBtn) undoBtn.addEventListener('click', function () { undo(); });
        if (redoBtn) redoBtn.addEventListener('click', function () { redo(); });
        var addFieldBtn = section.querySelector('[data-editor-action="add-field"]');
        if (addFieldBtn) {
            addFieldBtn.addEventListener('click', function () {
                // Gutenberg-style top "+": pick a type, append a single-col
                // row at the end of the form. User drags it wherever after.
                showTypePicker(addFieldBtn, function (type) {
                    var body = form.querySelector('.lrob-etk-cf-body');
                    if (!body) return;
                    var newRow = buildRowWithField(type);
                    body.appendChild(newRow);
                    normalizeAllInserts();
                    // Highlight + scroll the new row into view so the user
                    // sees where the added field landed.
                    newRow.classList.add('is-just-inserted');
                    setTimeout(function () { newRow.classList.remove('is-just-inserted'); }, 800);
                    if (newRow.scrollIntoView) {
                        newRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                    commitAndSave();
                });
            });
        }
        // Keyboard shortcuts: Ctrl/Cmd+Z to undo, Ctrl/Cmd+Shift+Z (or Y) to
        // redo. Only fire when the keystroke is happening inside this card's
        // editor section, so two open editors don't fight over the same Z.
        section.addEventListener('keydown', function (e) {
            var mod = e.ctrlKey || e.metaKey;
            if (!mod) return;
            var key = e.key.toLowerCase();
            if (key === 'z' && !e.shiftKey) {
                e.preventDefault();
                undo();
            } else if ((key === 'z' && e.shiftKey) || key === 'y') {
                e.preventDefault();
                redo();
            }
        });
        var saveTimer = null;

        // --- Undo / redo history ------------------------------------------
        // A snapshot is the form's full innerHTML. Each discrete user action
        // (insert/delete/drag/toggle/blur of contenteditable) pushes one
        // entry, so undo steps back to the state BEFORE that action. Typing
        // inside a contenteditable doesn't snapshot per-keystroke — only on
        // blur — so undo skips back over a whole word, not letter-by-letter.
        var HISTORY_MAX = 50;
        var history = [];
        var historyIndex = -1;
        function commit() {
            var snapshot = form.innerHTML;
            if (historyIndex >= 0 && history[historyIndex] === snapshot) return;
            // Truncate any forward (redo) entries — we're branching.
            history = history.slice(0, historyIndex + 1);
            history.push(snapshot);
            if (history.length > HISTORY_MAX) {
                history.shift();
            } else {
                historyIndex++;
            }
            updateHistoryButtons();
        }
        function commitAndSave() {
            commit();
            queueSave();
        }
        function dismissOverlays() {
            // Any open gear popup / type picker references shells that may
            // be replaced wholesale by an undo/redo. Close them first.
            document.querySelectorAll('.lrob-etk-cf-gear-popup').forEach(function (p) { p.remove(); });
            form.querySelectorAll('.lrob-etk-cf-type-picker').forEach(function (p) { p.remove(); });
        }
        function undo() {
            if (historyIndex <= 0) return;
            dismissOverlays();
            historyIndex--;
            form.innerHTML = history[historyIndex];
            refreshInserts();
            updateHistoryButtons();
            queueSave();
        }
        function redo() {
            if (historyIndex >= history.length - 1) return;
            dismissOverlays();
            historyIndex++;
            form.innerHTML = history[historyIndex];
            refreshInserts();
            updateHistoryButtons();
            queueSave();
        }
        function updateHistoryButtons() {
            if (undoBtn) undoBtn.disabled = historyIndex <= 0;
            if (redoBtn) redoBtn.disabled = historyIndex >= history.length - 1;
        }

        // --- Save plumbing ------------------------------------------------
        function queueSave() {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(doSave, SAVE_DEBOUNCE_MS);
        }
        function doSave() {
            var structure = serialize(form);
            setStatus('saving');
            var fd = new FormData();
            fd.append('action', 'lrob_etk_cf_save_structure');
            fd.append('_nonce', SAVE_DATA.nonce || '');
            fd.append('form_id', String(formId));
            fd.append('structure', JSON.stringify(structure));
            fetch(SAVE_DATA.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                .then(function (resp) {
                    setStatus(resp && resp.success ? 'saved' : 'error', resp && resp.data && resp.data.message);
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
            } else {
                status.classList.add('is-error');
                status.textContent = detail ? ((I18N.error || 'Save failed') + ': ' + detail) : (I18N.error || 'Save failed');
            }
        }

        // --- Click dispatcher (overlays + insert zones) -------------------
        form.addEventListener('click', function (e) {
            // Insert "+" zones.
            var insert = e.target.closest('[data-insert]');
            if (insert) {
                e.preventDefault();
                handleInsert(insert);
                return;
            }
            // Hover overlay buttons.
            var action = e.target.closest('[data-action]');
            if (!action) return;
            e.preventDefault();
            var act = action.getAttribute('data-action');
            switch (act) {
                case 'delete-row':           deleteRow(action); break;
                case 'delete-col':           deleteColumn(action); break;
                case 'delete-field':         deleteField(action); break;
                case 'gear':                 openGearPopup(action); break;
                case 'toggle-required':      toggleRequired(action); break;
                case 'add-inline-option':    addInlineOption(action); break;
                case 'delete-inline-option': deleteInlineOption(action); break;
            }
        });

        /**
         * Inline option-list mutations. Each shell stores its options in
         * `data-attr-options`; the inline preview reads/writes those + the
         * serializer reads them on save. Open gear popup (when relevant)
         * has no options section anymore — inline IS the editor.
         */
        function addInlineOption(btn) {
            var shell = btn.closest('.lrob-etk-cf-edit-shell');
            if (!shell) return;
            var options = parseOptions(shell);
            options.push({ label: '', value: '' });
            shell.setAttribute('data-attr-options', JSON.stringify(options));
            applyOptionsToPreview(shell);
            // Focus the freshly-added label so the user can type immediately.
            var labels = shell.querySelectorAll('[data-option-edit]');
            var last = labels[labels.length - 1];
            if (last) {
                last.focus();
                // Place caret inside (rather than at the start).
                var range = document.createRange();
                range.selectNodeContents(last);
                range.collapse(false);
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            }
            commitAndSave();
        }
        function deleteInlineOption(btn) {
            var shell = btn.closest('.lrob-etk-cf-edit-shell');
            var row = btn.closest('[data-inline-option]');
            if (!shell || !row) return;
            row.remove();
            syncOptionsFromInline(shell);
            applyOptionsToPreview(shell);
            commitAndSave();
        }

        // --- Inline editables ---------------------------------------------
        // Labels, helpers, submit-button text: contenteditable spans/elements
        // tagged with data-edit. We strip the "(empty)" placeholder text on
        // focus so the user sees their own typing on a clean slate.
        form.addEventListener('focusin', function (e) {
            var editable = e.target.closest('[data-edit]');
            if (editable && (editable.classList.contains('lrob-etk-cf-helper-empty') || editable.querySelector('.lrob-etk-cf-label-empty'))) {
                // Clear placeholder text so the user types into a blank field.
                editable.textContent = '';
                editable.classList.remove('lrob-etk-cf-helper-empty');
            }
            // Inputs in editor mode: swap placeholder → value so the user
            // edits the placeholder directly by typing.
            var input = e.target.matches && e.target.matches('input[type="text"], input[type="email"], input[type="tel"], input[type="number"], input[type="date"], textarea')
                ? e.target : null;
            if (input && form.contains(input)) {
                var ph = input.getAttribute('placeholder') || '';
                if (ph !== '' && input.value === '') {
                    input.dataset.etkSwappedPlaceholder = '1';
                    input.value = ph;
                    input.removeAttribute('placeholder');
                }
            }
        });
        form.addEventListener('focusout', function (e) {
            var editable = e.target.closest('[data-edit]');
            if (editable) {
                handleEditableBlur(editable);
                commitAndSave();
            }
            var input = e.target.matches && e.target.matches('input[type="text"], input[type="email"], input[type="tel"], input[type="number"], input[type="date"], textarea')
                ? e.target : null;
            if (input && input.dataset.etkSwappedPlaceholder === '1') {
                // User finished typing the placeholder back into the input.
                input.setAttribute('placeholder', input.value || '');
                input.value = '';
                delete input.dataset.etkSwappedPlaceholder;
                commitAndSave();
            }
        });
        form.addEventListener('input', function (e) {
            // contenteditable typing → queue save; pure input typing (which
            // is the placeholder swap path) doesn't save until blur.
            if (e.target.closest('[data-edit]')) {
                queueSave();
            }
            // Inline option label edits: sync labels → data-attr-options,
            // but don't redraw the preview on every keystroke (would steal
            // the caret). The redraw happens on blur.
            if (e.target.closest && e.target.closest('[data-option-edit]')) {
                var shell = e.target.closest('.lrob-etk-cf-edit-shell');
                if (shell) {
                    syncOptionsFromInline(shell);
                    queueSave();
                }
            }
        });
        form.addEventListener('blur', function (e) {
            // Redraw the preview after an inline label edit completes so
            // values reflect the final label (the input listener can't
            // safely redraw mid-edit without ejecting the caret).
            if (e.target && e.target.matches && e.target.matches('[data-option-edit]')) {
                var shell = e.target.closest('.lrob-etk-cf-edit-shell');
                if (shell) applyOptionsToPreview(shell);
            }
        }, true);

        function handleEditableBlur(editable) {
            // If the user cleared the editable, put back the "(empty)" hint.
            var text = (editable.textContent || '').trim();
            var kind = editable.getAttribute('data-edit');
            if (text === '') {
                if (kind === 'helper') {
                    editable.textContent = EDITOR_I18N.helperPlaceholder || '(optional helper text)';
                    editable.classList.add('lrob-etk-cf-helper-empty');
                } else if (kind === 'label' && editable.classList.contains('lrob-etk-cf-label-text')) {
                    editable.innerHTML = '<span class="lrob-etk-cf-label-empty">' + (EDITOR_I18N.labelPlaceholder || '(field label)') + '</span>';
                }
            } else {
                editable.classList.remove('lrob-etk-cf-helper-empty');
            }
        }

        // --- Captcha in-block picker --------------------------------------
        // The select fires its own 'change' event (auto-save catches it via
        // bindCard's data-key path); we additionally swap the rendered
        // preview so the user sees what visitors would see for the chosen
        // challenge — a real picture, not a description.
        form.addEventListener('change', function (e) {
            if (e.target && e.target.matches && e.target.matches('[data-captcha-pick]')) {
                var block = e.target.closest('[data-captcha-block]');
                var preview = block && block.querySelector('[data-captcha-preview]');
                if (preview) preview.innerHTML = captchaPreviewHtml(e.target.value);
            }
        });

        // --- Drag-and-drop -------------------------------------------------
        var draggedItem = null;
        form.addEventListener('dragstart', function (e) {
            var item = e.target.closest('[data-draggable-type]');
            if (!item) return;
            if (e.target.closest('button, input, textarea, select, [contenteditable]')) {
                e.preventDefault();
                return;
            }
            // Substitute the field for its ROW only when the field IS the
            // entire body block — single-col row AND the only shell in that
            // col. If the col stacks several fields, substituting would drag
            // all of them; the user only meant to grab the one they clicked.
            if (item.getAttribute('data-draggable-type') === 'field') {
                var ownRow = item.closest('.lrob-etk-cf-row');
                var ownCol = item.closest('.lrob-etk-cf-col');
                if (ownRow && ownCol
                    && ownRow.querySelectorAll(':scope > .lrob-etk-cf-col').length === 1
                    && ownCol.querySelectorAll(':scope > .lrob-etk-cf-edit-shell').length === 1) {
                    item = ownRow;
                }
            }
            draggedItem = item;
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', item.getAttribute('data-draggable-type')); } catch (err) {}
            // Explicitly set the drag image BEFORE mutating the source — if
            // the browser implicitly captures during the opacity transition
            // it can decide the image is invalid and abort the drag (which
            // is what we've been chasing: dragstart fires, then dragend with
            // zero drag events).
            try {
                if (e.dataTransfer.setDragImage) {
                    var rect = item.getBoundingClientRect();
                    e.dataTransfer.setDragImage(item, e.clientX - rect.left, e.clientY - rect.top);
                }
            } catch (err) {}
            // Defer the class mutations to after the browser has committed
            // the drag image, so the transition can't interfere.
            requestAnimationFrame(function () {
                if (draggedItem === item) {
                    item.classList.add('is-dragging');
                    form.classList.add('is-drag-active');
                }
            });
        });
        form.addEventListener('dragend', function () {
            if (draggedItem) draggedItem.classList.remove('is-dragging');
            form.classList.remove('is-drag-active');
            clearDropIndicators();
            draggedItem = null;
        });
        form.addEventListener('dragover', function (e) {
            if (!draggedItem) return;
            var type = draggedItem.getAttribute('data-draggable-type');
            // Prefer an "insert" target whenever the cursor is over one —
            // those are the empty seams between rows/fields, including the
            // very-bottom slot of the form. Without this branch a drop in an
            // otherwise-empty area silently fails because the dragover
            // handler only saw block targets.
            var insertHover = e.target.closest('.lrob-etk-cf-insert');
            if (insertHover && isValidInsertTarget(insertHover, type, draggedItem)) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                clearDropIndicators();
                insertHover.classList.add('is-drop-on-insert');
                return;
            }
            // Field-drag also accepts row/col targets so the user can drop
            // the field as a new column (side-drop, between or at edge of
            // the row's cols) or as a new single-col row above/below.
            // Row-drag also accepts col targets so the user can place a new
            // column between two existing cols by dropping on the left/right
            // half of an existing one.
            var hover = pickDropHover(e, type);
            if (!hover || hover === draggedItem || !sameScope(draggedItem, hover, type)) {
                clearDropIndicators();
                return;
            }
            // Block same-element and "nest into self" targets. EXCEPT when
            // the dragged element's own row is the hover: that's the
            // "extract this field above/below my row" case, valid as long
            // as the drop direction is above/below (the snap-to-col logic
            // already prevents the middle band from picking a col target on
            // the source's own row).
            if (hover === draggedItem
                || (draggedItem.contains && draggedItem.contains(hover))) {
                clearDropIndicators();
                return;
            }
            if (hover.contains && hover.contains(draggedItem)) {
                if (!hover.classList.contains('lrob-etk-cf-row')) {
                    clearDropIndicators();
                    return;
                }
                // Hover is the dragged item's own row. pickDropHover already
                // forced this case for any cursor position inside the source
                // row, so cursor.y just decides above vs below — extract is
                // always meaningful.
            }
            // Cap check: dropping on a col target would create a new column
            // in the target's row, so refuse if the row's already at 4.
            if (hover.classList.contains('lrob-etk-cf-col') && type !== 'col') {
                var hostRow = hover.closest('.lrob-etk-cf-row');
                if (hostRow && hostRow.querySelectorAll(':scope > .lrob-etk-cf-col').length >= 4) {
                    clearDropIndicators();
                    return;
                }
            }
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            clearDropIndicators();
            var dir = computeDropDirection(draggedItem, hover, e);
            hover.classList.add(dir);
        });
        function pickDropHover(e, type) {
            var direct;
            if (type === 'field') {
                direct = e.target.closest('[data-draggable-type="field"], [data-draggable-type="col"], [data-draggable-type="row"]');
            } else if (type === 'row') {
                direct = e.target.closest('[data-draggable-type="col"], [data-draggable-type="row"]');
            } else {
                direct = e.target.closest('[data-draggable-type="' + type + '"]');
            }
            if (!direct) return null;
            // Any field-/row-drag landing anywhere INSIDE the dragged item's
            // own row (the row itself, any of its cols, any of its shells)
            // collapses to a source-row hover. computeDropDirection then
            // returns above/below, which the drop handler turns into a
            // clean extract — no degenerate "new col where the source col
            // just emptied" reshuffle that looked like a no-op to the user.
            // Col-drag is exempt: it relies on dropping onto other cols in
            // the same row to reorder.
            var sourceRow = draggedItem ? draggedItem.closest('.lrob-etk-cf-row') : null;
            if (sourceRow && (type === 'field' || type === 'row')) {
                var inSourceRow = direct === sourceRow
                    || ((direct.classList.contains('lrob-etk-cf-col')
                        || direct.classList.contains('lrob-etk-cf-edit-shell'))
                        && direct.closest('.lrob-etk-cf-row') === sourceRow);
                if (inSourceRow) {
                    return sourceRow;
                }
            }
            // Snap-to-col: when the user aims at a row's middle vertical
            // band (intending a between-cols drop), pick a column target
            // even if the cursor sits in the grid gap between cols. The
            // chosen col is the FIRST one whose midX is greater than the
            // cursor — that gives a single stable "drop before this col"
            // target across the whole gap, so the bar doesn't flicker
            // between cols as the cursor moves a few pixels left or right.
            if (direct.classList.contains('lrob-etk-cf-row') && (type === 'row' || type === 'field')) {
                var rowCols = direct.querySelectorAll(':scope > .lrob-etk-cf-col');
                if (rowCols.length >= 1) {
                    var rRect = direct.getBoundingClientRect();
                    var vMargin = Math.max(rRect.height * 0.25, 10);
                    if (e.clientY > rRect.top + vMargin && e.clientY < rRect.bottom - vMargin) {
                        var snapped = null;
                        for (var i = 0; i < rowCols.length; i++) {
                            var cRect = rowCols[i].getBoundingClientRect();
                            var cMid = cRect.left + cRect.width / 2;
                            if (e.clientX < cMid) { snapped = rowCols[i]; break; }
                        }
                        if (!snapped) snapped = rowCols[rowCols.length - 1];
                        return snapped;
                    }
                }
            }
            return direct;
        }
        form.addEventListener('drop', function (e) {
            if (!draggedItem) return;
            var type = draggedItem.getAttribute('data-draggable-type');
            var sourceCol = (type === 'field') ? draggedItem.closest('.lrob-etk-cf-col') : null;

            // Insert drop: place the dragged item right before the insert so
            // it lands exactly where the "+" indicated. Field on row-insert
            // wraps the field in a new single-col row.
            var insertHover = e.target.closest('.lrob-etk-cf-insert');
            if (insertHover && isValidInsertTarget(insertHover, type, draggedItem)) {
                e.preventDefault();
                var insertKind = insertHover.getAttribute('data-insert');
                if (type === 'field' && insertKind === 'row') {
                    // Drop a field on a body row-insert → wrap it in a fresh
                    // single-col row at that body position. The insert
                    // itself is wiped + re-emitted by normalizeAllInserts.
                    wrapFieldAsRow(draggedItem, 'after', insertHover);
                } else {
                    insertHover.parentNode.insertBefore(draggedItem, insertHover);
                }
                if (sourceCol && sourceCol !== draggedItem.parentNode) {
                    cleanupEmptyCol(sourceCol);
                }
                normalizeAllInserts();
                clearDropIndicators();
                commitAndSave();
                return;
            }

            // Block-target drops. Field-drag accepts field/col/row targets;
            // row-drag accepts col/row; col-drag stays restricted to col.
            var hover = pickDropHover(e, type);
            if (!hover || hover === draggedItem || !sameScope(draggedItem, hover, type)) {
                clearDropIndicators();
                return;
            }
            if (hover !== draggedItem && hover.contains && hover.contains(draggedItem)) {
                clearDropIndicators();
                return;
            }
            e.preventDefault();
            var dir = computeDropDirection(draggedItem, hover, e);

            if (hover.classList.contains('lrob-etk-cf-col') && type !== 'col') {
                // Field- or row-drag landed on a column: drop the dragged
                // payload as a NEW col before/after the target col. This is
                // how the user inserts between two existing columns.
                var hostRow = hover.closest('.lrob-etk-cf-row');
                var beforeRef = (dir === 'is-drop-before-h')
                    ? hover
                    : hover.nextElementSibling;
                if (type === 'row' && isSingleColRow(draggedItem)) {
                    var movedCol = draggedItem.querySelector(':scope > .lrob-etk-cf-col');
                    if (movedCol && insertColIntoRow(movedCol, hostRow, beforeRef)) {
                        draggedItem.remove();
                    }
                } else if (type === 'field') {
                    insertColIntoRow(buildColWithField(draggedItem), hostRow, beforeRef);
                }
            } else if (type === 'field' && hover.classList.contains('lrob-etk-cf-row')) {
                // Field dropped on a row → new single-col row above/below.
                wrapFieldAsRow(draggedItem, dir === 'is-drop-before' ? 'before' : 'after', hover);
            } else if (dir === 'is-drop-before-h' || dir === 'is-drop-before') {
                hover.parentNode.insertBefore(draggedItem, hover);
            } else {
                hover.parentNode.insertBefore(draggedItem, hover.nextSibling);
            }
            // If we just yanked the field out of a column and left it empty,
            // collapse the column (and the row, if the column was its last).
            if (sourceCol && sourceCol !== draggedItem.parentNode) {
                cleanupEmptyCol(sourceCol);
            }
            normalizeAllInserts();
            clearDropIndicators();
            commitAndSave();
        });
        function isValidInsertTarget(insert, type, dragged) {
            // Don't allow dropping on an insert that's a direct neighbour of
            // the dragged element — that would be a no-op move.
            if (dragged && (insert.previousElementSibling === dragged || insert.nextElementSibling === dragged)) {
                return false;
            }
            var kind = insert.getAttribute('data-insert');
            if (type === 'row'   && kind === 'row')   return true;
            if (type === 'field' && kind === 'field') return true;
            // Field can drop on a body row-insert: it gets wrapped in a new
            // single-col row at that body position (extracts the field out
            // of a column block onto body level).
            if (type === 'field' && kind === 'row')   return true;
            return false;
        }
        function wrapFieldAsRow(sourceField, position, refRow) {
            // Build a single-col row with the field as its only content.
            // The row inserts get rebuilt by normalizeAllInserts afterward.
            var newRow = buildRow();
            var newCol = newRow.querySelector('.lrob-etk-cf-col');
            // Drop the placeholder field-insert from the empty col template
            // and append the actual field + a trailing insert.
            var placeholder = newCol.querySelector('.lrob-etk-cf-insert--field');
            if (placeholder) placeholder.remove();
            newCol.appendChild(sourceField);
            newCol.appendChild(buildInsertZone('field'));
            if (position === 'before') {
                refRow.parentNode.insertBefore(newRow, refRow);
            } else {
                refRow.parentNode.insertBefore(newRow, refRow.nextSibling);
            }
            return newRow;
        }
        function buildColWithField(sourceField) {
            var newCol = buildColumn();
            var placeholder = newCol.querySelector('.lrob-etk-cf-insert--field');
            if (placeholder) placeholder.remove();
            newCol.appendChild(sourceField);
            newCol.appendChild(buildInsertZone('field'));
            return newCol;
        }
        function sameScope(dragged, hover, type) {
            if (type === 'row') return true;
            // Fields can be dragged into any column on any row — the source
            // column auto-cleans up if it's emptied by the move.
            if (type === 'field') return true;
            // Columns still only reorder within their own row.
            return dragged.parentNode === hover.parentNode;
        }
        function cleanupEmptyCol(col) {
            if (!col || col.querySelector('.lrob-etk-cf-edit-shell')) return;
            var row = col.closest('.lrob-etk-cf-row');
            if (!row) return;
            var rowCols = row.querySelectorAll(':scope > .lrob-etk-cf-col');
            if (rowCols.length === 1) {
                // Single-col row: the row's only column is empty → drop the
                // whole row. (normalizeBody will re-balance the body's row
                // inserts so the user keeps a "+" at every seam.)
                row.remove();
            } else {
                // Multi-col row: drop just the empty column. Restore the
                // trailing column "+" if we're now under the 4-col cap.
                col.remove();
                updateRowCols(row);
                if (row.querySelectorAll(':scope > .lrob-etk-cf-col').length < 4
                    && !row.querySelector(':scope > .lrob-etk-cf-insert--column')) {
                    row.appendChild(buildInsertZone('column'));
                }
            }
        }

        // --- Normalize insert zones ---------------------------------------
        // After drag-drop, side-drop, and column cleanup, the row/field
        // insert pattern can end up broken: missing trailing "+" at the very
        // bottom, duplicate "+" between rows, or no "+" between two newly
        // adjacent rows. Rebuild the canonical pattern from scratch — wipe
        // the row/field inserts in each container and reinsert exactly one
        // before every block + one trailing. The orphan/single-col-hide CSS
        // rules then handle the visual presentation. */
        function normalizeAllInserts() {
            var body = form.querySelector(':scope > .lrob-etk-cf-body');
            if (body) normalizeContainer(body, '.lrob-etk-cf-row', '.lrob-etk-cf-insert--row', 'row');
            form.querySelectorAll('.lrob-etk-cf-col').forEach(function (col) {
                normalizeContainer(col, '.lrob-etk-cf-edit-shell', '.lrob-etk-cf-insert--field', 'field');
            });
        }
        function normalizeContainer(container, blockSel, insertSel, insertKind) {
            container.querySelectorAll(':scope > ' + insertSel).forEach(function (el) { el.remove(); });
            var blocks = container.querySelectorAll(':scope > ' + blockSel);
            if (blocks.length === 0) {
                container.appendChild(buildInsertZone(insertKind));
                return;
            }
            container.insertBefore(buildInsertZone(insertKind), blocks[0]);
            blocks.forEach(function (block) {
                container.insertBefore(buildInsertZone(insertKind), block.nextSibling);
            });
        }
        function computeDropDirection(dragged, hover, e) {
            var rect = hover.getBoundingClientRect();
            var type = dragged.getAttribute('data-draggable-type');
            // Col target: row- or field-drag aiming at an existing column
            // (either directly or via snap-to-col on a row). Left half →
            // insert new col before; right half → insert after.
            if (hover.classList.contains('lrob-etk-cf-col')) {
                var midX = rect.left + rect.width / 2;
                return e.clientX < midX ? 'is-drop-before-h' : 'is-drop-after-h';
            }
            // Row target: vertical above/below for field-drag (creates a
            // new single-col row); same for row-drag (reorders body items).
            // The previous "edge band side-drop" is gone — snap-to-col on
            // multi/single-col rows now handles before-first / after-last
            // column drops via the col target instead.
            var horizontal = type === 'col';
            var midpoint = horizontal ? rect.left + rect.width / 2 : rect.top + rect.height / 2;
            var coord = horizontal ? e.clientX : e.clientY;
            var before = coord < midpoint;
            return before
                ? (horizontal ? 'is-drop-before-h' : 'is-drop-before')
                : (horizontal ? 'is-drop-after-h'  : 'is-drop-after');
        }
        function isSingleColRow(el) {
            return el
                && el.classList.contains('lrob-etk-cf-row')
                && el.querySelectorAll(':scope > .lrob-etk-cf-col').length === 1;
        }
        function insertColIntoRow(sourceCol, targetRow, beforeRef) {
            // Generic "place this col inside this row at this position".
            // beforeRef: a sibling element to insert before, or null for end.
            // Skip past the trailing column "+" so it stays at the end.
            if (targetRow.querySelectorAll(':scope > .lrob-etk-cf-col').length >= 4) return false;
            if (beforeRef && beforeRef.classList && beforeRef.classList.contains('lrob-etk-cf-insert--column')) {
                beforeRef = null;
            }
            // Fall back to placing before the trailing column "+" so the
            // trailing track stays at the row's right edge.
            if (!beforeRef) {
                beforeRef = targetRow.querySelector(':scope > .lrob-etk-cf-insert--column') || null;
            }
            targetRow.insertBefore(sourceCol, beforeRef);
            updateRowCols(targetRow);
            var colsNow = targetRow.querySelectorAll(':scope > .lrob-etk-cf-col').length;
            var trailingNow = targetRow.querySelector(':scope > .lrob-etk-cf-insert--column');
            if (colsNow >= 4 && trailingNow) {
                trailingNow.remove();
            } else if (colsNow < 4 && !trailingNow) {
                targetRow.appendChild(buildInsertZone('column'));
            }
            sourceCol.classList.add('is-just-inserted');
            setTimeout(function () { sourceCol.classList.remove('is-just-inserted'); }, 700);
            return true;
        }
        function clearDropIndicators() {
            form.querySelectorAll('.is-drop-before, .is-drop-after, .is-drop-before-h, .is-drop-after-h, .is-drop-on-insert')
                .forEach(function (el) {
                    el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-before-h', 'is-drop-after-h', 'is-drop-on-insert');
                });
        }

        // --- Insert zone state --------------------------------------------
        // Inserts are always visible (no cursor-distance fading, no movement)
        // — they're absolutely-sized pills that overlap the seam between
        // neighbors via negative margins, so they cost no vertical layout
        // space. The only stateful bit is `.is-orphan`, applied to the lone
        // insert in an empty container so it renders as a clearly larger
        // drop-zone instead of a thin pill.
        function refreshInserts() {
            form.querySelectorAll('.lrob-etk-cf-insert').forEach(function (el) {
                var parent = el.parentElement;
                var kind = el.getAttribute('data-insert');
                var isOrphan = false;
                if (kind === 'row' && parent) {
                    isOrphan = !parent.querySelector(':scope > .lrob-etk-cf-row');
                } else if (kind === 'field' && parent) {
                    isOrphan = !parent.querySelector(':scope > .lrob-etk-cf-edit-shell');
                }
                el.classList.toggle('is-orphan', isOrphan);
            });
        }
        var observer = new MutationObserver(refreshInserts);
        observer.observe(form, { childList: true, subtree: true });
        refreshInserts();

        // --- Mutators ------------------------------------------------------
        function deleteRow(btn) {
            var row = btn.closest('.lrob-etk-cf-row');
            if (!row) return;
            // Remove both the row and the trailing insert-row zone (the "+"
            // that sits just after this row) so we don't leave dangling
            // zones. The first zone (above the row) acts as the surviving
            // insertion point for that gap.
            var next = row.nextElementSibling;
            row.remove();
            if (next && next.matches && next.matches('.lrob-etk-cf-insert--row')) {
                next.remove();
            }
            commitAndSave();
        }
        function deleteColumn(btn) {
            var col = btn.closest('.lrob-etk-cf-col');
            if (!col) return;
            var row = col.closest('.lrob-etk-cf-row');
            if (!row) return;
            var cols = row.querySelectorAll('.lrob-etk-cf-col');
            if (cols.length <= 1) return; // keep at least one column
            col.remove();
            updateRowCols(row);
            // If insertColumn previously removed the "+" because we hit the
            // 4-column cap, drop back below the cap means we need it back.
            if (row.querySelectorAll('.lrob-etk-cf-col').length < 4
                && !row.querySelector(':scope > .lrob-etk-cf-insert--column')) {
                row.appendChild(buildInsertZone('column'));
            }
            commitAndSave();
        }
        function deleteField(btn) {
            var shell = btn.closest('.lrob-etk-cf-edit-shell');
            if (!shell) return;
            var col = shell.closest('.lrob-etk-cf-col');
            var row = shell.closest('.lrob-etk-cf-row');
            var next = shell.nextElementSibling;
            shell.remove();
            if (next && next.matches && next.matches('.lrob-etk-cf-insert--field')) {
                next.remove();
            }
            // Clean up empty containers left by the deletion. Single-col row
            // → remove the whole row (and its trailing body insert). Multi-
            // col row → remove just the now-empty column and update the row
            // grid (re-adding the trailing column "+" if we dropped below 4).
            if (col && row && !col.querySelector('.lrob-etk-cf-edit-shell')) {
                var rowCols = row.querySelectorAll(':scope > .lrob-etk-cf-col');
                if (rowCols.length === 1) {
                    var rowNext = row.nextElementSibling;
                    row.remove();
                    if (rowNext && rowNext.matches && rowNext.matches('.lrob-etk-cf-insert--row')) {
                        rowNext.remove();
                    }
                } else {
                    col.remove();
                    updateRowCols(row);
                    if (row.querySelectorAll(':scope > .lrob-etk-cf-col').length < 4
                        && !row.querySelector(':scope > .lrob-etk-cf-insert--column')) {
                        row.appendChild(buildInsertZone('column'));
                    }
                }
            }
            commitAndSave();
        }
        function toggleRequired(btn) {
            var shell = btn.closest('.lrob-etk-cf-edit-shell');
            if (!shell) return;
            var on = shell.getAttribute('data-attr-required') !== '1';
            shell.setAttribute('data-attr-required', on ? '1' : '0');
            btn.classList.toggle('is-on', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            commitAndSave();
        }

        function handleInsert(btn) {
            var kind = btn.getAttribute('data-insert');
            if (kind === 'column') return insertColumn(btn);
            // Both "row" (body-level) and "field" (inside a column) open the
                // type picker. Row picks build a single-col row containing one
                // field; field picks add a field to the surrounding column.
            return showTypePicker(btn, function (type) {
                if (kind === 'row') {
                    var row = buildRowWithField(type);
                    btn.parentNode.insertBefore(row, btn.nextSibling);
                    btn.parentNode.insertBefore(buildInsertZone('row'), row.nextSibling);
                } else {
                    var field = buildField(type);
                    btn.parentNode.insertBefore(field, btn.nextSibling);
                    btn.parentNode.insertBefore(buildInsertZone('field'), field.nextSibling);
                }
                commitAndSave();
            });
        }

        function insertColumn(btn) {
            var row = btn.closest('.lrob-etk-cf-row');
            if (!row) return;
            var existing = row.querySelectorAll(':scope > .lrob-etk-cf-col').length;
            if (existing >= 4) return;
            var col = buildColumn();
            row.insertBefore(col, btn);
            updateRowCols(row);
            // Brief highlight so the user gets visible confirmation that the
            // click did something (the new col is otherwise an empty dashed
            // drop zone that can blend in next to the existing content).
            col.classList.add('is-just-inserted');
            setTimeout(function () { col.classList.remove('is-just-inserted'); }, 700);
            // Remove the trailing column "+" if the row is now at the max.
            if (row.querySelectorAll(':scope > .lrob-etk-cf-col').length >= 4) {
                btn.remove();
            }
            commitAndSave();
        }
        function showTypePicker(btn, onPick) {
            // Close any open picker first.
            form.querySelectorAll('.lrob-etk-cf-type-picker').forEach(function (p) { p.remove(); });
            var tpl = section.querySelector('template[data-field-type-picker]');
            if (!tpl) return;
            var picker = tpl.content.firstElementChild.cloneNode(true);
            picker.addEventListener('click', function (e) {
                var tb = e.target.closest('[data-type]');
                if (!tb) return;
                e.preventDefault();
                var type = tb.getAttribute('data-type');
                picker.remove();
                onPick(type);
            });
            btn.parentNode.insertBefore(picker, btn.nextSibling);
            setTimeout(function () {
                document.addEventListener('click', function onDoc(ev) {
                    if (!picker.contains(ev.target) && ev.target !== btn) {
                        picker.remove();
                        document.removeEventListener('click', onDoc);
                    }
                });
            }, 0);
        }

        function updateRowCols(row) {
            var n = row.querySelectorAll('.lrob-etk-cf-col').length;
            row.setAttribute('data-cols', String(n));
        }

        // --- Gear popup ----------------------------------------------------
        function openGearPopup(btn) {
            var shell = btn.closest('.lrob-etk-cf-edit-shell');
            if (!shell) return;
            // Close any open popup first.
            document.querySelectorAll('.lrob-etk-cf-gear-popup').forEach(function (p) { p.remove(); });

            var tpl = section.querySelector('template[data-field-gear-popup]');
            if (!tpl) return;
            var popup = tpl.content.firstElementChild.cloneNode(true);
            var body = popup.querySelector('[data-gear-body]');
            var title = popup.querySelector('[data-gear-title]');

            var type = shell.getAttribute('data-field-type') || '';
            if (title) title.textContent = (FIELD_TYPES[type] || type) + ' — ' + (EDITOR_I18N.fieldOptions || 'Field options');

            // Build the body fields for this type. Simple text inputs + a
            // checkbox for required. Changes write straight back to the
            // shell's data attributes (read by the serializer).
            if (body) body.innerHTML = buildGearBody(shell, type);

            document.body.appendChild(popup);
            anchorPopup(popup, btn);

            function closePopup() {
                if (!popup.isConnected) return;
                popup.remove();
                // Group every gear edit since opening into a single undo step.
                commit();
            }
            popup.addEventListener('click', function (e) {
                if (e.target.closest('[data-close]')) {
                    closePopup();
                    return;
                }
                if (e.target.closest('[data-action="add-option"]')) {
                    e.preventDefault();
                    var list = popup.querySelector('[data-options-list]');
                    if (list) list.appendChild(buildOptionRow(''));
                    syncOptionsFromPopup(shell, popup);
                    applyOptionsToPreview(shell);
                    commitAndSave();
                }
                if (e.target.closest('[data-action="delete-option"]')) {
                    e.preventDefault();
                    var row = e.target.closest('[data-option]');
                    if (row) row.remove();
                    syncOptionsFromPopup(shell, popup);
                    applyOptionsToPreview(shell);
                    commitAndSave();
                }
            });
            popup.addEventListener('input', function (e) {
                if (e.target.matches('[data-gear-prop]')) {
                    var key = e.target.getAttribute('data-gear-prop');
                    var val = e.target.type === 'checkbox' ? (e.target.checked ? '1' : '0') : e.target.value;
                    shell.setAttribute('data-attr-' + key, val);
                    if (key === 'required') updateRequiredMarker(shell, e.target.checked);
                    if (key === 'slug') {
                        // Reflect slug onto the inner field wrapper for the
                        // serializer's secondary read.
                        var fieldWrap = shell.querySelector('.lrob-etk-cf-field');
                        if (fieldWrap) fieldWrap.setAttribute('data-field', val);
                        applyOptionsToPreview(shell); // re-derive radio/checkbox name="slug" / "slug[]"
                    }
                    if (key === 'multiple') {
                        applyOptionsToPreview(shell);
                    }
                    queueSave();
                }
                if (e.target.matches('[data-option-prop]')) {
                    syncOptionsFromPopup(shell, popup);
                    applyOptionsToPreview(shell);
                    queueSave();
                }
            });
            // Close on outside click.
            setTimeout(function () {
                document.addEventListener('click', function onDoc(ev) {
                    if (!popup.contains(ev.target) && ev.target !== btn && !btn.contains(ev.target)) {
                        closePopup();
                        document.removeEventListener('click', onDoc);
                    }
                });
            }, 0);
        }

        function anchorPopup(popup, anchor) {
            var rect = anchor.getBoundingClientRect();
            var pRect = popup.getBoundingClientRect();
            var top  = window.scrollY + rect.bottom + 6;
            var left = window.scrollX + rect.right - pRect.width;
            if (left < window.scrollX + 8) left = window.scrollX + 8;
            popup.style.position = 'absolute';
            popup.style.top  = top + 'px';
            popup.style.left = left + 'px';
        }

        function updateRequiredMarker(shell, required) {
            // Editor mode: the required marker is the .lrob-etk-cf-required-toggle
            // button rendered next to the label. Just flip its visual state.
            var toggle = shell.querySelector('.lrob-etk-cf-required-toggle');
            if (toggle) {
                toggle.classList.toggle('is-on', !!required);
                toggle.setAttribute('aria-pressed', required ? 'true' : 'false');
            }
        }

        function buildGearBody(shell, type) {
            if (type === 'submit')  return gearSubmitBody(shell);
            if (type === 'captcha') return '<p class="lrob-etk-cf-gear-note">Configure the challenge type in the form\'s Anti-spam settings above. The captcha will appear here on the frontend.</p>';

            var slug = shell.getAttribute('data-attr-slug') || '';

            var html = ''
                + '<div class="lrob-etk-field"><label>' + esc(EDITOR_I18N.slug || 'Slug') + '</label>'
                + '<input type="text" data-gear-prop="slug" value="' + escAttr(slug) + '"></div>';

            html += typeSpecificGearControls(shell, type);
            return html;
        }

        function gearSubmitBody(shell) {
            var align = shell.getAttribute('data-attr-align') || 'right';
            var aligns = [['left', EDITOR_I18N.alignLeft || 'Left'], ['center', EDITOR_I18N.alignCenter || 'Center'], ['right', EDITOR_I18N.alignRight || 'Right'], ['stretch', EDITOR_I18N.alignStretch || 'Full width']];
            var opts = aligns.map(function (a) { return '<option value="' + a[0] + '"' + (a[0] === align ? ' selected' : '') + '>' + esc(a[1]) + '</option>'; }).join('');
            return '<div class="lrob-etk-field"><label>' + esc(EDITOR_I18N.alignment || 'Alignment') + '</label>'
                + '<select data-gear-prop="align" class="lrob-etk-select">' + opts + '</select></div>';
        }

        function typeSpecificGearControls(shell, type) {
            switch (type) {
                case 'textarea':
                    return numField(shell, 'rows', EDITOR_I18N.rows || 'Rows', 5) + numField(shell, 'maxLength', EDITOR_I18N.maxLength || 'Max length', 0);
                case 'text':
                case 'email':
                    return numField(shell, 'maxLength', EDITOR_I18N.maxLength || 'Max length', 0);
                case 'number':
                    return textField(shell, 'min', EDITOR_I18N.min || 'Min') + textField(shell, 'max', EDITOR_I18N.max || 'Max') + textField(shell, 'step', EDITOR_I18N.step || 'Step');
                case 'phone':
                    return textField(shell, 'pattern', EDITOR_I18N.pattern || 'Regex pattern');
                case 'date':
                    return textField(shell, 'min', EDITOR_I18N.min || 'Min') + textField(shell, 'max', EDITOR_I18N.max || 'Max');
                case 'select':
                case 'radio':
                    // Options are edited inline on the field preview itself.
                    return '<p class="lrob-etk-cf-gear-note">' + esc(EDITOR_I18N.optionsInlineHint || 'Edit options directly on the field — click any label to rename, or use the inline + / × buttons.') + '</p>';
                case 'checkbox':
                    var multiple = shell.getAttribute('data-attr-multiple') !== '0';
                    return '<div class="lrob-etk-field"><label class="lrob-etk-cf-inline-check"><input type="checkbox" data-gear-prop="multiple"' + (multiple ? ' checked' : '') + '> ' + esc(EDITOR_I18N.multiple || 'Multiple choices') + '</label></div>'
                        + '<p class="lrob-etk-cf-gear-note">' + esc(EDITOR_I18N.optionsInlineHint || 'Edit options directly on the field — click any label to rename, or use the inline + / × buttons.') + '</p>';
            }
            return '';
        }

        function textField(shell, key, label) {
            var v = shell.getAttribute('data-attr-' + key) || '';
            return '<div class="lrob-etk-field"><label>' + esc(label) + '</label><input type="text" data-gear-prop="' + key + '" value="' + escAttr(v) + '"></div>';
        }
        function numField(shell, key, label, fallback) {
            var v = shell.getAttribute('data-attr-' + key);
            if (v === null || v === '') v = String(fallback);
            return '<div class="lrob-etk-field"><label>' + esc(label) + '</label><input type="number" data-gear-prop="' + key + '" value="' + escAttr(v) + '"></div>';
        }
        function optionsBlock(shell) {
            // Option rows expose ONLY a label input. The value is auto-
            // derived (sluggified label) — matching what FieldRenderer's
            // normalize_options already does server-side. Having both
            // confused the user and ~nobody needed independent value/label
            // pairs anyway.
            var options = parseOptions(shell);
            var rows = options.map(function (o) { return optionRowHtml(o.label || ''); }).join('');
            return '<div class="lrob-etk-field lrob-etk-cf-editor-options" data-options-root>'
                + '<label>' + esc(EDITOR_I18N.options || 'Options') + '</label>'
                + '<div data-options-list>' + rows + '</div>'
                + '<button type="button" class="button-link" data-action="add-option">+ ' + esc(EDITOR_I18N.addOption || 'Add option') + '</button>'
                + '</div>';
        }
        function optionRowHtml(label) {
            return '<div class="lrob-etk-cf-editor-option" data-option>'
                + '<input type="text" data-option-prop="label" placeholder="' + esc(EDITOR_I18N.optionLabel || 'Option') + '" value="' + escAttr(label) + '">'
                + '<button type="button" data-action="delete-option" class="lrob-etk-cf-icon-btn lrob-etk-cf-icon-btn--delete" aria-label="' + esc(EDITOR_I18N.removeOption || 'Remove option') + '">×</button>'
                + '</div>';
        }
        function buildOptionRow(label) {
            var div = document.createElement('div');
            div.innerHTML = optionRowHtml(label || '');
            return div.firstElementChild;
        }
        function parseOptions(shell) {
            try { return JSON.parse(shell.getAttribute('data-attr-options') || '[]'); }
            catch (e) { return []; }
        }
        function deriveOptionValue(label) {
            var v = String(label || '').toLowerCase()
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '')
                .substring(0, 40);
            return v || 'opt';
        }
        /**
         * Read all label inputs in the popup, derive values, write back to
         * the shell's data-attr-options. Called on every option-prop input
         * + on add/delete row so the preview can re-render in real time.
         */
        function syncOptionsFromPopup(shell, popup) {
            var rows = popup.querySelectorAll('[data-option]');
            var seen = {};
            var options = [];
            Array.prototype.forEach.call(rows, function (row) {
                var l = row.querySelector('[data-option-prop="label"]');
                var label = l ? String(l.value || '').trim() : '';
                if (label === '') return;
                var value = deriveOptionValue(label);
                // De-dupe slugified values: "Yes" + "yes" both → "yes".
                // Append _2, _3 etc. so each row keeps a distinct value.
                var base = value;
                var i = 2;
                while (seen[value]) { value = base + '_' + i++; }
                seen[value] = true;
                options.push({ label: label, value: value });
            });
            shell.setAttribute('data-attr-options', JSON.stringify(options));
        }
        /**
         * Rebuild the live preview control inside the field shell so
         * select/radio/checkbox(multi) fields show the user's current
         * options inline AND editable: each label is a contenteditable
         * span with a × delete button; an inline "+ Add option" sits at
         * the bottom. The gear popup options-list is now redundant — gear
         * keeps slug/required/etc but options live where they render.
         *
         * For select, contenteditable doesn't slot into <option> labels
         * cleanly, so we mirror the option list to a small inline editor
         * below the select control. The select stays in the DOM so the
         * preview reads visually correct.
         */
        function applyOptionsToPreview(shell) {
            var type = shell.getAttribute('data-field-type') || '';
            if (type !== 'select' && type !== 'radio' && type !== 'checkbox') return;
            var field = shell.querySelector('.lrob-etk-cf-field');
            if (!field) return;
            var slug = shell.getAttribute('data-attr-slug') || 'field';
            var options = parseOptions(shell);

            if (type === 'checkbox' && shell.getAttribute('data-attr-multiple') === '0') {
                // Single checkbox doesn't take a list of options. Wipe any
                // existing options block and show a one-line placeholder.
                var stale = field.querySelector(':scope > .lrob-etk-cf-options');
                if (stale) stale.outerHTML = '<div class="lrob-etk-cf-options"><p class="lrob-etk-cf-helper">' + esc(EDITOR_I18N.singleCheckboxHint || 'Single checkbox — no options needed.') + '</p></div>';
                return;
            }

            if (type === 'select') {
                renderSelectPreview(field, slug, options);
            } else {
                renderOptionGroupPreview(field, type, slug, options);
            }
        }

        function renderSelectPreview(field, slug, options) {
            // The <select> can't host contenteditable labels, so we keep
            // the native select (cosmetic) AND render an inline label
            // editor next to it. Edits update both.
            var existingSelect = field.querySelector(':scope > select');
            var optsHtml = '<option value="">— select —</option>';
            options.forEach(function (o) {
                optsHtml += '<option value="' + escAttr(o.value || '') + '">' + esc(o.label || '') + '</option>';
            });
            var selectHtml = '<select id="lrob-etk-cf-editor-' + escAttr(slug) + '">' + optsHtml + '</select>';
            if (existingSelect) {
                existingSelect.outerHTML = selectHtml;
            } else {
                field.insertAdjacentHTML('beforeend', selectHtml);
            }
            var existingInline = field.querySelector(':scope > .lrob-etk-cf-options');
            var inlineHtml = inlineOptionEditorHtml(options);
            if (existingInline) {
                existingInline.outerHTML = inlineHtml;
            } else {
                // Insert right after the select so editing sits next to the control.
                var sel = field.querySelector(':scope > select');
                if (sel && sel.insertAdjacentHTML) sel.insertAdjacentHTML('afterend', inlineHtml);
                else field.insertAdjacentHTML('beforeend', inlineHtml);
            }
        }

        function renderOptionGroupPreview(field, type, slug, options) {
            var existing = field.querySelector(':scope > .lrob-etk-cf-options, :scope > fieldset, :scope > select');
            var inputType = type === 'radio' ? 'radio' : 'checkbox';
            var name = type === 'checkbox' ? slug + '[]' : slug;
            var items = options.map(function (o) {
                return inlineOptionRowHtml(inputType, name, o.label || '', o.value || '');
            }).join('');
            var html = '<div class="lrob-etk-cf-options" data-options-inline>'
                + items
                + inlineAddButtonHtml()
                + '</div>';
            if (existing) {
                existing.outerHTML = html;
            } else {
                field.insertAdjacentHTML('beforeend', html);
            }
        }

        function inlineOptionEditorHtml(options) {
            var rows = options.map(function (o) {
                return '<div class="lrob-etk-cf-inline-option" data-inline-option>'
                    + '<span class="lrob-etk-cf-option-label" contenteditable="plaintext-only" data-option-edit spellcheck="false">' + esc(o.label || '') + '</span>'
                    + '<button type="button" class="lrob-etk-cf-option-remove" data-action="delete-inline-option" aria-label="' + esc(EDITOR_I18N.removeOption || 'Remove option') + '">×</button>'
                    + '</div>';
            }).join('');
            return '<div class="lrob-etk-cf-options" data-options-inline>'
                + rows
                + inlineAddButtonHtml()
                + '</div>';
        }
        function inlineOptionRowHtml(inputType, name, label, value) {
            // input + span + button as siblings (NOT wrapped in <label>):
            // wrapping in <label> made click events forward to the input
            // and steal focus from the contenteditable span — user couldn't
            // place their caret to edit. Same trap CLAUDE.md flags for the
            // field-label markup. The radio/checkbox is purely visual in
            // editor mode, so unlinked-from-its-label is fine here.
            return '<div class="lrob-etk-cf-option" data-inline-option>'
                + '<input type="' + inputType + '" name="' + escAttr(name) + '" value="' + escAttr(value) + '" tabindex="-1">'
                + '<span class="lrob-etk-cf-option-label" contenteditable="plaintext-only" data-option-edit spellcheck="false">' + esc(label) + '</span>'
                + '<button type="button" class="lrob-etk-cf-option-remove" data-action="delete-inline-option" aria-label="' + esc(EDITOR_I18N.removeOption || 'Remove option') + '">×</button>'
                + '</div>';
        }
        function inlineAddButtonHtml() {
            return '<button type="button" class="lrob-etk-cf-option-add" data-action="add-inline-option">+ '
                + esc(EDITOR_I18N.addOption || 'Add option')
                + '</button>';
        }

        /**
         * Read every contenteditable option label inside a shell, derive
         * value from label, write back to data-attr-options. Mirror of
         * syncOptionsFromPopup but for the new inline editor.
         */
        function syncOptionsFromInline(shell) {
            var optionsContainer = shell.querySelector('[data-options-inline]');
            if (!optionsContainer) return;
            var labelEls = optionsContainer.querySelectorAll('[data-option-edit]');
            var seen = {};
            var options = [];
            Array.prototype.forEach.call(labelEls, function (el) {
                var label = String(el.textContent || '').trim();
                if (label === '') return;
                var base = deriveOptionValue(label);
                var value = base;
                var i = 2;
                while (seen[value]) { value = base + '_' + i++; }
                seen[value] = true;
                options.push({ label: label, value: value });
            });
            shell.setAttribute('data-attr-options', JSON.stringify(options));
        }

        // --- DOM builders for newly-inserted elements ---------------------
        function genId(prefix) {
            return prefix + '_' + Math.random().toString(36).substr(2, 8);
        }
        function buildRow() {
            var rowId = genId('row');
            var row = document.createElement('div');
            row.className = 'lrob-etk-cf-row';
            row.setAttribute('data-cols', '1');
            row.setAttribute('data-row-id', rowId);
            row.setAttribute('data-draggable-type', 'row');
            row.setAttribute('draggable', 'true');
            row.innerHTML = rowOverlayHtml() + colHtml(genId('col'));
            // Append a column "+" so the user can grow the row.
            row.insertAdjacentHTML('beforeend', insertZoneHtml('column'));
            return row;
        }
        function buildRowWithField(type) {
            // Body-level "+ Field" → single-col row containing one field.
            // The user thinks of this as adding a field; the row+col wrapping
            // is the storage shape FormStructure expects.
            var row = buildRow();
            var col = row.querySelector('.lrob-etk-cf-col');
            if (col) {
                var field = buildField(type);
                col.appendChild(field);
                col.appendChild(buildInsertZone('field'));
            }
            return row;
        }
        function buildColumn() {
            // Build via innerHTML on a wrapper — outerHTML on a detached
            // element throws NoModificationAllowedError.
            var wrap = document.createElement('div');
            wrap.innerHTML = colHtml(genId('col'));
            return wrap.firstElementChild;
        }
        function colHtml(colId) {
            return '<div class="lrob-etk-cf-col" data-col-id="' + colId + '" data-draggable-type="col" draggable="true">'
                + colOverlayHtml()
                + insertZoneHtml('field')
                + '</div>';
        }
        function buildField(type) {
            var id = genId('f');
            var wrap = document.createElement('div');
            wrap.innerHTML = fieldShellHtml(id, type);
            return wrap.firstElementChild;
        }
        function fieldShellHtml(id, type) {
            var inner = freshFieldInnerHtml(type);
            var extraAttrs = '';
            if (type === 'select' || type === 'radio' || type === 'checkbox') {
                // Seed every new multi-choice field with two starter
                // options — empty fields confused the user and an empty
                // select / radio / checkbox-group is never useful.
                var seed = [
                    { label: 'Option 1', value: 'option_1' },
                    { label: 'Option 2', value: 'option_2' },
                ];
                extraAttrs += ' data-attr-options="' + escAttr(JSON.stringify(seed)) + '"';
                if (type === 'checkbox') {
                    // Checkbox defaults to MULTIPLE — that's the use case
                    // the seed options make sense for. Single-checkbox
                    // (consent-style) is reached by toggling in the gear.
                    extraAttrs += ' data-attr-multiple="1"';
                }
            }
            return '<div class="lrob-etk-cf-edit-shell" data-field-id="' + id + '" data-field-type="' + type + '" data-draggable-type="field" draggable="true"'
                + ' data-attr-slug="' + escAttr(defaultSlug(type)) + '" data-attr-required="0"'
                + extraAttrs
                + '>' + fieldOverlayHtml(type) + inner + '</div>';
        }
        function freshFieldInnerHtml(type) {
            // Minimal field stub. The user will fill in label / placeholder
            // / options inline; the serializer reads everything from this
            // markup on save.
            var typeLabel = FIELD_TYPES[type] || type;
            if (type === 'submit') {
                return '<div class="lrob-etk-cf-field lrob-etk-cf-field--submit is-align-right">'
                    + '<button type="button" class="lrob-etk-cf-submit"><span class="lrob-etk-cf-submit-label" contenteditable="plaintext-only" data-edit="submit-text">' + esc(typeLabel) + '</span></button>'
                    + '</div>';
            }
            if (type === 'captcha') {
                return captchaEditorStubHtml('');
            }
            var slug = defaultSlug(type);
            var labelText = typeLabel;
            var control = buildControlHtml(type, slug);
            // Mirror FieldRenderer's editor markup: <div> (not <label>) so
            // clicks on the contenteditable span aren't stolen by label-for.
            var requiredTitle = esc(EDITOR_I18N.toggleRequired || 'Toggle required');
            return '<div class="lrob-etk-cf-field lrob-etk-cf-field--' + type + '" data-field="' + escAttr(slug) + '">'
                + '<div class="lrob-etk-cf-label">'
                + '<span class="lrob-etk-cf-label-text" contenteditable="plaintext-only" data-edit="label" spellcheck="false">' + esc(labelText) + '</span>'
                + '<button type="button" class="lrob-etk-cf-required-toggle" data-action="toggle-required" aria-pressed="false" title="' + requiredTitle + '">*</button>'
                + '</div>'
                + control
                + '<p class="lrob-etk-cf-helper lrob-etk-cf-helper-empty" contenteditable="plaintext-only" data-edit="helper" spellcheck="false">' + esc(EDITOR_I18N.helperPlaceholder || '(optional helper text)') + '</p>'
                + '<p class="lrob-etk-cf-error" data-field-error hidden></p>'
                + '</div>';
        }
        function buildControlHtml(type, slug) {
            var id = 'lrob-etk-cf-editor-' + slug;
            // Seed two options for multi-choice fields. Same shape that
            // applyOptionsToPreview emits — newly-created fields are
            // immediately inline-editable, no reload required.
            var seed = [
                { label: 'Option 1', value: 'option_1' },
                { label: 'Option 2', value: 'option_2' },
            ];
            switch (type) {
                case 'textarea': return '<textarea id="' + id + '" rows="5"></textarea>';
                case 'select':
                    var selectOpts = '<option value="">— select —</option>'
                        + seed.map(function (o) {
                            return '<option value="' + escAttr(o.value) + '">' + esc(o.label) + '</option>';
                        }).join('');
                    return '<select id="' + id + '">' + selectOpts + '</select>'
                        + inlineOptionEditorHtml(seed);
                case 'radio':
                    return '<div class="lrob-etk-cf-options" data-options-inline>'
                        + seed.map(function (o) { return inlineOptionRowHtml('radio', slug, o.label, o.value); }).join('')
                        + inlineAddButtonHtml()
                        + '</div>';
                case 'checkbox':
                    return '<div class="lrob-etk-cf-options" data-options-inline>'
                        + seed.map(function (o) { return inlineOptionRowHtml('checkbox', slug + '[]', o.label, o.value); }).join('')
                        + inlineAddButtonHtml()
                        + '</div>';
                case 'date':     return '<input type="date" id="' + id + '">';
                case 'number':   return '<input type="number" id="' + id + '">';
                case 'phone':    return '<input type="tel" id="' + id + '">';
                case 'email':    return '<input type="email" id="' + id + '">';
                default:         return '<input type="text" id="' + id + '">';
            }
        }
        /**
         * Inline captcha picker the same shape FieldRenderer.captcha()
         * emits server-side, so newly-inserted captcha blocks behave
         * identically to a freshly-rendered one. The select's data-key
         * hooks into the per-form card's auto-save plumbing — same wire
         * the Advanced > Challenge combobox uses.
         */
        function captchaEditorStubHtml(currentSlug) {
            var key = EDITOR_DATA.captchaKey || '_lrob_etk_cf_challenge';
            var challenges = Array.isArray(EDITOR_DATA.challenges) ? EDITOR_DATA.challenges : [];
            var opts = '<option value=""' + (currentSlug === '' ? ' selected' : '') + '>'
                + esc(EDITOR_I18N.captchaDefault || 'Form default') + '</option>';
            opts += '<option value="none"' + (currentSlug === 'none' ? ' selected' : '') + '>'
                + esc(EDITOR_I18N.captchaNone || 'None') + '</option>';
            challenges.forEach(function (c) {
                opts += '<option value="' + escAttr(c.slug) + '"' + (currentSlug === c.slug ? ' selected' : '') + '>'
                    + esc(c.label) + '</option>';
            });
            return '<div class="lrob-etk-cf-field lrob-etk-cf-field--captcha is-editor-stub" data-captcha-block>'
                + '<div class="lrob-etk-cf-captcha-stub-head">'
                + '<span class="lrob-etk-cf-captcha-stub-icon dashicons dashicons-shield" aria-hidden="true"></span>'
                + '<label class="lrob-etk-cf-captcha-stub-label">' + esc(EDITOR_I18N.captchaPick || 'Anti-spam:') + '</label>'
                + '<select class="lrob-etk-cf-field lrob-etk-cf-captcha-pick" name="' + escAttr(key) + '" data-key="' + escAttr(key) + '" data-captcha-pick>' + opts + '</select>'
                + '</div>'
                + '<div class="lrob-etk-cf-captcha-stub-preview" data-captcha-preview>' + captchaPreviewHtml(currentSlug) + '</div>'
                + '</div>';
        }
        /**
         * Looks up the pre-rendered preview HTML for a given challenge
         * slug (sent over via wp_localize_script). Sentinel slugs ('' /
         * 'none') render as small placeholder paragraphs instead.
         */
        function captchaPreviewHtml(slug) {
            if (slug === 'none') {
                return '<p class="lrob-etk-cf-captcha-stub-empty">'
                    + esc(EDITOR_I18N.captchaOff || 'No anti-spam challenge.')
                    + '</p>';
            }
            if (slug === '') {
                return '<p class="lrob-etk-cf-captcha-stub-empty">'
                    + esc(EDITOR_I18N.captchaInherit || 'Uses the form\'s default challenge.')
                    + '</p>';
            }
            var list = Array.isArray(EDITOR_DATA.challenges) ? EDITOR_DATA.challenges : [];
            for (var i = 0; i < list.length; i++) {
                if (list[i].slug === slug && list[i].preview) return list[i].preview;
            }
            return '<p class="lrob-etk-cf-captcha-stub-empty">' + esc(slug) + '</p>';
        }
        function defaultSlug(type) {
            if (type === 'submit') return '';
            if (type === 'captcha') return '';
            return type;
        }
        function rowOverlayHtml() {
            return '<div class="lrob-etk-cf-overlay lrob-etk-cf-overlay--row" aria-hidden="true">'
                + '<span class="lrob-etk-cf-overlay-handle dashicons dashicons-move"></span>'
                + '<button type="button" class="lrob-etk-cf-overlay-btn" data-action="delete-row"><span class="dashicons dashicons-trash"></span></button>'
                + '</div>';
        }
        function colOverlayHtml() {
            return '<div class="lrob-etk-cf-overlay lrob-etk-cf-overlay--col" aria-hidden="true">'
                + '<span class="lrob-etk-cf-overlay-handle dashicons dashicons-move"></span>'
                + '<button type="button" class="lrob-etk-cf-overlay-btn" data-action="delete-col"><span class="dashicons dashicons-trash"></span></button>'
                + '</div>';
        }
        function fieldOverlayHtml(type) {
            // Captcha doesn't need slug/required/options — but Gear stays so
            // users can find their way back to anti-spam settings.
            return '<div class="lrob-etk-cf-overlay lrob-etk-cf-overlay--field" aria-hidden="true">'
                + '<span class="lrob-etk-cf-overlay-handle dashicons dashicons-move"></span>'
                + '<button type="button" class="lrob-etk-cf-overlay-btn" data-action="gear"><span class="dashicons dashicons-admin-generic"></span></button>'
                + '<button type="button" class="lrob-etk-cf-overlay-btn lrob-etk-cf-overlay-btn--delete" data-action="delete-field"><span class="dashicons dashicons-trash"></span></button>'
                + '</div>';
        }
        function insertZoneHtml(kind) {
            // Same labelling logic as FormEditorRenderer::insert_zone (PHP).
            var labelText = (kind === 'row' || kind === 'field') ? (EDITOR_I18N.fieldLabel || 'Field') : '';
            var label = labelText ? '<span class="lrob-etk-cf-insert-label">' + esc(labelText) + '</span>' : '';
            return '<button type="button" class="lrob-etk-cf-insert lrob-etk-cf-insert--' + kind + '" data-insert="' + kind + '" aria-label="Add">'
                + '<span class="lrob-etk-cf-insert-plus" aria-hidden="true">+</span>' + label + '</button>';
        }
        function buildInsertZone(kind) {
            var wrap = document.createElement('div');
            wrap.innerHTML = insertZoneHtml(kind);
            return wrap.firstElementChild;
        }

        // --- Serializer (DOM → JSON) --------------------------------------
        function serialize(form) {
            var rows = [];
            form.querySelectorAll(':scope > .lrob-etk-cf-body > .lrob-etk-cf-row').forEach(function (rowEl) {
                var cols = [];
                rowEl.querySelectorAll(':scope > .lrob-etk-cf-col').forEach(function (colEl) {
                    var fields = [];
                    colEl.querySelectorAll(':scope > .lrob-etk-cf-edit-shell').forEach(function (shellEl) {
                        fields.push(serializeField(shellEl));
                    });
                    cols.push({ id: colEl.getAttribute('data-col-id') || '', fields: fields });
                });
                rows.push({ id: rowEl.getAttribute('data-row-id') || '', columns: cols });
            });
            return { version: 1, rows: rows };
        }
        function serializeField(shell) {
            var type = shell.getAttribute('data-field-type') || 'text';
            var id   = shell.getAttribute('data-field-id') || '';
            var f = { id: id, type: type };

            if (type === 'submit') {
                var submitText = shell.querySelector('[data-edit="submit-text"]');
                f.text  = submitText ? (submitText.textContent || '').trim() : 'Send';
                f.align = shell.getAttribute('data-attr-align') || 'right';
                return f;
            }
            if (type === 'captcha') return f;

            // Inline-edit values.
            var labelEl = shell.querySelector('[data-edit="label"]');
            var helperEl = shell.querySelector('[data-edit="helper"]');
            var inputEl = shell.querySelector('input, textarea, select');

            f.slug        = shell.getAttribute('data-attr-slug') || '';
            f.label       = labelEl ? readEditableText(labelEl) : '';
            f.helper      = helperEl ? readEditableText(helperEl, true) : '';
            f.placeholder = inputEl && inputEl.hasAttribute('placeholder') ? inputEl.getAttribute('placeholder') : '';
            f.required    = shell.getAttribute('data-attr-required') === '1';

            // Type-specific extras stored as data-attr-* (set by the gear).
            ['maxLength', 'rows', 'min', 'max', 'step', 'pattern'].forEach(function (k) {
                var v = shell.getAttribute('data-attr-' + k);
                if (v !== null && v !== '') {
                    f[k] = (k === 'maxLength' || k === 'rows') ? (parseInt(v, 10) || 0) : v;
                }
            });
            if (type === 'select' || type === 'radio' || type === 'checkbox') {
                // Options live in data-attr-options as JSON when set via the
                // gear popup. The popup's inputs are read live if a popup is
                // open for this field.
                var optionsAttr = shell.getAttribute('data-attr-options');
                if (optionsAttr) {
                    try { f.options = JSON.parse(optionsAttr); } catch (e) { f.options = []; }
                }
                // If gear popup is open with live edits, prefer those.
                // Popup rows now expose only a label input; value is
                // derived from the label so storage and preview agree.
                var openPopup = document.querySelector('.lrob-etk-cf-gear-popup');
                if (openPopup) {
                    var rows = openPopup.querySelectorAll('[data-option]');
                    if (rows.length) {
                        var seen = {};
                        f.options = Array.prototype.map.call(rows, function (r) {
                            var l = r.querySelector('[data-option-prop="label"]');
                            var label = l ? String(l.value || '').trim() : '';
                            return label;
                        }).filter(function (lbl) { return lbl !== ''; })
                          .map(function (label) {
                              var base = deriveOptionValue(label);
                              var value = base;
                              var i = 2;
                              while (seen[value]) { value = base + '_' + i++; }
                              seen[value] = true;
                              return { label: label, value: value };
                          });
                        // Mirror back to data-attr-options for next save.
                        shell.setAttribute('data-attr-options', JSON.stringify(f.options));
                    }
                }
            }
            if (type === 'checkbox') {
                f.multiple = shell.getAttribute('data-attr-multiple') !== '0';
            }
            return f;
        }
        function readEditableText(el, ignoreEmptyPlaceholder) {
            if (ignoreEmptyPlaceholder && el.classList.contains('lrob-etk-cf-helper-empty')) return '';
            if (el.querySelector('.lrob-etk-cf-label-empty')) return '';
            return (el.textContent || '').trim();
        }
        function readHelperText(shell) {
            var h = shell.querySelector('[data-edit="helper"]');
            return h && !h.classList.contains('lrob-etk-cf-helper-empty') ? (h.textContent || '').trim() : '';
        }

        function esc(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        function escAttr(s) { return String(s).replace(/"/g, '&quot;'); }

        // --- Initial sync: copy field attrs from the existing PHP-rendered
        // DOM into data-attr-* on each shell, so the gear popup has the
        // current values and the serializer can read them.
        form.querySelectorAll('.lrob-etk-cf-edit-shell').forEach(function (shell) {
            // Skip if we've already populated (e.g. shells we built ourselves).
            if (shell.dataset.etkInit === '1') return;
            shell.dataset.etkInit = '1';
            var input = shell.querySelector('input, textarea, select');
            if (input) {
                ['maxLength', 'rows', 'min', 'max', 'step', 'pattern'].forEach(function (k) {
                    var attr = k === 'maxLength' ? 'maxlength' : k;
                    if (input.hasAttribute(attr)) shell.setAttribute('data-attr-' + k, input.getAttribute(attr));
                });
                if (input.hasAttribute('required')) shell.setAttribute('data-attr-required', '1');
            }
            // Slug comes from the inner field wrapper's data-field attr.
            var wrap = shell.querySelector('.lrob-etk-cf-field');
            if (wrap && wrap.hasAttribute('data-field')) {
                shell.setAttribute('data-attr-slug', wrap.getAttribute('data-field'));
            }
            // Submit align: read from the field's class (is-align-X).
            var submitWrap = shell.querySelector('.lrob-etk-cf-field--submit');
            if (submitWrap) {
                var m = submitWrap.className.match(/is-align-(left|center|right|stretch)/);
                if (m) shell.setAttribute('data-attr-align', m[1]);
            }
            // Required: editor mode renders a toggle button — `.is-on` means
            // required. The legacy `.lrob-etk-cf-required` span is still
            // accepted in case anything else writes one.
            if (shell.querySelector('.lrob-etk-cf-required-toggle.is-on') || shell.querySelector('.lrob-etk-cf-required')) {
                shell.setAttribute('data-attr-required', '1');
            }

            // Multi-choice fields: scrape options + multiple from the PHP-
            // rendered DOM so the inline editor (and serializer) has them
            // after a page reload. Without this the gear popup options
            // section was empty and inline edit had nothing to render.
            var type = shell.getAttribute('data-field-type') || '';
            if ((type === 'select' || type === 'radio' || type === 'checkbox') && !shell.hasAttribute('data-attr-options')) {
                var scraped = [];
                if (type === 'select') {
                    shell.querySelectorAll('select > option').forEach(function (opt) {
                        if (opt.value === '') return; // skip "— select —" placeholder
                        scraped.push({ label: (opt.textContent || '').trim(), value: opt.value });
                    });
                } else {
                    shell.querySelectorAll('.lrob-etk-cf-option').forEach(function (lbl) {
                        var inp = lbl.querySelector('input');
                        var span = lbl.querySelector('span') || lbl;
                        scraped.push({
                            label: (span.textContent || '').trim(),
                            value: inp ? inp.value : '',
                        });
                    });
                }
                if (scraped.length > 0) {
                    shell.setAttribute('data-attr-options', JSON.stringify(scraped));
                }
            }
            if (type === 'checkbox' && !shell.hasAttribute('data-attr-multiple')) {
                // Multi if a fieldset / option list was rendered, single
                // otherwise (a lone inline checkbox).
                shell.setAttribute('data-attr-multiple',
                    shell.querySelector('.lrob-etk-cf-field--checkbox-single, .lrob-etk-cf-checkbox-inline') ? '0' : '1'
                );
            }

            // Replace the PHP-rendered options markup with the editable
            // inline editor so users can rename / add / remove without
            // opening the gear popup.
            if (type === 'select' || type === 'radio' || type === 'checkbox') {
                applyOptionsToPreview(shell);
            }
        });

        // Seed the history with the initial state, AFTER the data-attr sync
        // so undo from any later mutation lands back on a complete snapshot.
        commit();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
