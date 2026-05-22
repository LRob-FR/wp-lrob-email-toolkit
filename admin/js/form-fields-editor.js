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
 *   • Inline settings strip at the top of each field shell (slug + per-
 *     type knobs like maxLength, rows, min/max, multiple, alignment,
 *     placeholder presets, etc.). Hidden at rest, fades in on shell hover
 *     — the editor canvas reads as the live frontend preview.
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
        // Mount hCaptcha widgets PHP rendered into the initial captcha
        // previews (one per form card when hCaptcha is the active route).
        document.querySelectorAll('[data-captcha-preview]').forEach(mountCaptchaPreview);
    }

    function bindSection(section) {
        if (section.__etkBound) return;
        section.__etkBound = true;

        var formId = parseInt(section.getAttribute('data-form-id'), 10) || 0;
        if (!formId) return;

        var form = section.querySelector('.lrob-etk-form.is-editor');
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
                    var body = form.querySelector('.lrob-etk-form-body');
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
            // Type picker references shells that may be replaced wholesale
            // by an undo/redo. Close it first.
            form.querySelectorAll('.lrob-etk-form-type-picker').forEach(function (p) { p.remove(); });
        }
        function undo() {
            if (historyIndex <= 0) return;
            dismissOverlays();
            historyIndex--;
            form.innerHTML = history[historyIndex];
            refreshInserts();
            rebindShells();
            updateHistoryButtons();
            queueSave();
        }
        function redo() {
            if (historyIndex >= history.length - 1) return;
            dismissOverlays();
            historyIndex++;
            form.innerHTML = history[historyIndex];
            refreshInserts();
            rebindShells();
            updateHistoryButtons();
            queueSave();
        }
        function rebindShells() {
            // After innerHTML replacement, combobox controllers and any
            // other JS bindings need re-attaching to the new DOM nodes.
            form.querySelectorAll('.lrob-etk-form-edit-shell').forEach(function (shell) {
                ensureInlineSettings(shell);
            });
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
                case 'add-inline-option':    addInlineOption(action); break;
                case 'delete-inline-option': deleteInlineOption(action); break;
                case 'toggle-default-option': toggleDefaultOption(action); break;
            }
        });

        /**
         * Inline option-list mutations. Each shell stores its options in
         * `data-attr-options`; the inline preview reads/writes those + the
         * serializer reads them on save. Open gear popup (when relevant)
         * has no options section anymore — inline IS the editor.
         */
        function addInlineOption(btn) {
            var shell = btn.closest('.lrob-etk-form-edit-shell');
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
            var shell = btn.closest('.lrob-etk-form-edit-shell');
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
            if (editable && (editable.classList.contains('lrob-etk-form-helper-empty') || editable.querySelector('.lrob-etk-form-label-empty'))) {
                // Clear placeholder text so the user types into a blank field.
                editable.textContent = '';
                editable.classList.remove('lrob-etk-form-helper-empty');
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
                var shell = e.target.closest('.lrob-etk-form-edit-shell');
                if (shell) {
                    syncOptionsFromInline(shell);
                    queueSave();
                }
            }
        });
        form.addEventListener('blur', function (e) {
            if (!e.target || !e.target.matches) return;
            // Redraw the preview after an inline label edit completes so
            // values reflect the final label (the input listener can't
            // safely redraw mid-edit without ejecting the caret).
            if (e.target.matches('[data-option-edit]')) {
                var shell = e.target.closest('.lrob-etk-form-edit-shell');
                if (shell) applyOptionsToPreview(shell);
            }
            // Field-label blur: re-derive the slug from the new label so
            // it's always `<type>_<sluggified-label>_<nth>` — the nth tail
            // is the creation index and stays put across reorders, which
            // keeps the slug stable when *other* fields are removed.
            if (e.target.matches('[data-edit="label"]')) {
                var lShell = e.target.closest('.lrob-etk-form-edit-shell');
                if (lShell) recomputeSlug(lShell);
            }
        }, true);

        function handleEditableBlur(editable) {
            // If the user cleared the editable, put back the "(empty)" hint.
            var text = (editable.textContent || '').trim();
            var kind = editable.getAttribute('data-edit');
            if (text === '') {
                if (kind === 'helper') {
                    editable.textContent = EDITOR_I18N.helperPlaceholder || '(optional helper text)';
                    editable.classList.add('lrob-etk-form-helper-empty');
                } else if (kind === 'label' && editable.classList.contains('lrob-etk-form-label-text')) {
                    editable.innerHTML = '<span class="lrob-etk-form-label-empty">' + (EDITOR_I18N.labelPlaceholder || '(field label)') + '</span>';
                }
            } else {
                editable.classList.remove('lrob-etk-form-helper-empty');
            }
        }

        // --- Sticky hover state on field shells ---------------------------
        // Keeps a `.is-active` class on the shell whose bounding rect (or
        // a 10px buffer around it) contains the cursor. The buffer is the
        // whole trick: the "+ Field" insert pill in the gap below the
        // shell is inside the buffer, so the field never collapses while
        // the user is reaching for the pill — pill stays at its expanded
        // position long enough to actually click. Geometric, not timed —
        // the buffer is wide enough that human reaction time isn't a
        // factor.
        var STICKY_BUFFER = 10; // px around the shell's rect
        var stickyShell = null;
        function findStickyShell(node) {
            if (!node || !node.closest) return null;
            var shell = node.closest('.lrob-etk-form-edit-shell');
            if (shell) return shell;
            var pill = node.closest('.lrob-etk-form-insert--field');
            if (pill) {
                var prev = pill.previousElementSibling;
                if (prev && prev.matches && prev.matches('.lrob-etk-form-edit-shell')) return prev;
            }
            return null;
        }
        function setStickyShell(shell) {
            if (shell === stickyShell) return;
            if (stickyShell) stickyShell.classList.remove('is-active');
            stickyShell = shell;
            if (shell) shell.classList.add('is-active');
        }
        form.addEventListener('mouseover', function (e) {
            var shell = findStickyShell(e.target);
            if (shell) setStickyShell(shell);
        });
        form.addEventListener('mousemove', function (e) {
            if (!stickyShell) return;
            // Re-read the rect every move — the shell's height changes as
            // its reveals open/close, and we need the *current* rect to
            // know whether the cursor has actually escaped.
            var r = stickyShell.getBoundingClientRect();
            var x = e.clientX, y = e.clientY;
            if (x < r.left - STICKY_BUFFER ||
                x > r.right + STICKY_BUFFER ||
                y < r.top - STICKY_BUFFER ||
                y > r.bottom + STICKY_BUFFER) {
                // Outside the buffer — hand the active state to whatever
                // shell (if any) is under the cursor now.
                setStickyShell(findStickyShell(document.elementFromPoint(x, y)));
            }
        });
        form.addEventListener('mouseleave', function () {
            setStickyShell(null);
        });

        // --- Editor-side change handlers ---------------------------------
        // 1) Captcha picker (in-block) — swap the preview HTML so the user
        //    sees what visitors will see for the chosen challenge.
        // 2) Required checkbox — mirrors data-attr-required + the star's
        //    is-on indicator so the post-blur cosmetic state stays in sync.
        // 3) Select preview <select> — promotes the freshly-picked option
        //    to the field's default (placeholder option clears it).
        form.addEventListener('change', function (e) {
            var target = e.target;
            if (!target || !target.matches) return;

            if (target.matches('[data-captcha-pick]')) {
                var block = target.closest('[data-captcha-block]');
                var preview = block && block.querySelector('[data-captcha-preview]');
                if (preview) {
                    preview.innerHTML = captchaPreviewHtml(target.value);
                    mountCaptchaPreview(preview);
                }
                return;
            }

            if (target.matches('[data-required-toggle]')) {
                var rShell = target.closest('.lrob-etk-form-edit-shell');
                if (!rShell) return;
                var on = !!target.checked;
                rShell.setAttribute('data-attr-required', on ? '1' : '0');
                var star = rShell.querySelector('.lrob-etk-form-required-star');
                if (star) star.classList.toggle('is-on', on);
                commitAndSave();
                return;
            }

            // Select preview: any <select> living inside a .lrob-etk-cf-
            // field--select belongs to a dropdown field. The captcha
            // picker is filtered out above (data-captcha-pick).
            if (target.matches('.lrob-etk-form-field--select > select')) {
                var sShell = target.closest('.lrob-etk-form-edit-shell');
                if (!sShell) return;
                var v = target.value;
                sShell.setAttribute('data-attr-defaults', v === '' ? '[]' : JSON.stringify([v]));
                applyOptionsToPreview(sShell);
                commitAndSave();
                return;
            }
        });

        // --- Drag-and-drop -------------------------------------------------
        // Drag may ONLY start from the move arrow in a row / col / field
        // overlay. Mousedown anywhere else proactively flips `draggable`
        // to false on every draggable ancestor for the duration of the
        // press — so clicking into an input, dragging to select text, or
        // mousedown-and-drag on a contenteditable label can't accidentally
        // launch an HTML5 drag of the whole shell/row/col. Restored on
        // mouseup (also globally, in case the mouse leaves the form).
        var dragDisabled = [];
        function restoreDraggable() {
            dragDisabled.forEach(function (el) { el.setAttribute('draggable', 'true'); });
            dragDisabled = [];
        }
        form.addEventListener('mousedown', function (e) {
            // Defensive restore — if a previous mousedown didn't see its
            // mouseup (cursor left the window etc.) we still want a clean
            // slate.
            restoreDraggable();
            if (e.target.closest && e.target.closest('.lrob-etk-form-overlay-handle')) return;
            var item = e.target.closest && e.target.closest('[data-draggable-type]');
            while (item) {
                item.setAttribute('draggable', 'false');
                dragDisabled.push(item);
                var parent = item.parentElement;
                item = parent ? parent.closest('[data-draggable-type]') : null;
            }
        });
        document.addEventListener('mouseup', restoreDraggable);

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
                var ownRow = item.closest('.lrob-etk-form-row');
                var ownCol = item.closest('.lrob-etk-form-col');
                if (ownRow && ownCol
                    && ownRow.querySelectorAll(':scope > .lrob-etk-form-col').length === 1
                    && ownCol.querySelectorAll(':scope > .lrob-etk-form-edit-shell').length === 1) {
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
            var insertHover = e.target.closest('.lrob-etk-form-insert');
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
                if (!hover.classList.contains('lrob-etk-form-row')) {
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
            if (hover.classList.contains('lrob-etk-form-col') && type !== 'col') {
                var hostRow = hover.closest('.lrob-etk-form-row');
                if (hostRow && hostRow.querySelectorAll(':scope > .lrob-etk-form-col').length >= 4) {
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
            var sourceRow = draggedItem ? draggedItem.closest('.lrob-etk-form-row') : null;
            if (sourceRow && (type === 'field' || type === 'row')) {
                var inSourceRow = direct === sourceRow
                    || ((direct.classList.contains('lrob-etk-form-col')
                        || direct.classList.contains('lrob-etk-form-edit-shell'))
                        && direct.closest('.lrob-etk-form-row') === sourceRow);
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
            if (direct.classList.contains('lrob-etk-form-row') && (type === 'row' || type === 'field')) {
                var rowCols = direct.querySelectorAll(':scope > .lrob-etk-form-col');
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
            var sourceCol = (type === 'field') ? draggedItem.closest('.lrob-etk-form-col') : null;

            // Insert drop: place the dragged item right before the insert so
            // it lands exactly where the "+" indicated. Field on row-insert
            // wraps the field in a new single-col row.
            var insertHover = e.target.closest('.lrob-etk-form-insert');
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

            if (hover.classList.contains('lrob-etk-form-col') && type !== 'col') {
                // Field- or row-drag landed on a column: drop the dragged
                // payload as a NEW col before/after the target col. This is
                // how the user inserts between two existing columns.
                var hostRow = hover.closest('.lrob-etk-form-row');
                var beforeRef = (dir === 'is-drop-before-h')
                    ? hover
                    : hover.nextElementSibling;
                if (type === 'row' && isSingleColRow(draggedItem)) {
                    var movedCol = draggedItem.querySelector(':scope > .lrob-etk-form-col');
                    if (movedCol && insertColIntoRow(movedCol, hostRow, beforeRef)) {
                        draggedItem.remove();
                    }
                } else if (type === 'field') {
                    insertColIntoRow(buildColWithField(draggedItem), hostRow, beforeRef);
                }
            } else if (type === 'field' && hover.classList.contains('lrob-etk-form-row')) {
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
            var newCol = newRow.querySelector('.lrob-etk-form-col');
            // Drop the placeholder field-insert from the empty col template
            // and append the actual field + a trailing insert.
            var placeholder = newCol.querySelector('.lrob-etk-form-insert--field');
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
            var placeholder = newCol.querySelector('.lrob-etk-form-insert--field');
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
            if (!col || col.querySelector('.lrob-etk-form-edit-shell')) return;
            var row = col.closest('.lrob-etk-form-row');
            if (!row) return;
            var rowCols = row.querySelectorAll(':scope > .lrob-etk-form-col');
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
                if (row.querySelectorAll(':scope > .lrob-etk-form-col').length < 4
                    && !row.querySelector(':scope > .lrob-etk-form-insert--column')) {
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
            var body = form.querySelector(':scope > .lrob-etk-form-body');
            if (body) normalizeContainer(body, '.lrob-etk-form-row', '.lrob-etk-form-insert--row', 'row');
            form.querySelectorAll('.lrob-etk-form-col').forEach(function (col) {
                normalizeContainer(col, '.lrob-etk-form-edit-shell', '.lrob-etk-form-insert--field', 'field');
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
            if (hover.classList.contains('lrob-etk-form-col')) {
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
                && el.classList.contains('lrob-etk-form-row')
                && el.querySelectorAll(':scope > .lrob-etk-form-col').length === 1;
        }
        function insertColIntoRow(sourceCol, targetRow, beforeRef) {
            // Generic "place this col inside this row at this position".
            // beforeRef: a sibling element to insert before, or null for end.
            // Skip past the trailing column "+" so it stays at the end.
            if (targetRow.querySelectorAll(':scope > .lrob-etk-form-col').length >= 4) return false;
            if (beforeRef && beforeRef.classList && beforeRef.classList.contains('lrob-etk-form-insert--column')) {
                beforeRef = null;
            }
            // Fall back to placing before the trailing column "+" so the
            // trailing track stays at the row's right edge.
            if (!beforeRef) {
                beforeRef = targetRow.querySelector(':scope > .lrob-etk-form-insert--column') || null;
            }
            targetRow.insertBefore(sourceCol, beforeRef);
            updateRowCols(targetRow);
            var colsNow = targetRow.querySelectorAll(':scope > .lrob-etk-form-col').length;
            var trailingNow = targetRow.querySelector(':scope > .lrob-etk-form-insert--column');
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
            form.querySelectorAll('.lrob-etk-form-insert').forEach(function (el) {
                var parent = el.parentElement;
                var kind = el.getAttribute('data-insert');
                var isOrphan = false;
                if (kind === 'row' && parent) {
                    isOrphan = !parent.querySelector(':scope > .lrob-etk-form-row');
                } else if (kind === 'field' && parent) {
                    isOrphan = !parent.querySelector(':scope > .lrob-etk-form-edit-shell');
                }
                el.classList.toggle('is-orphan', isOrphan);
            });
        }
        var observer = new MutationObserver(refreshInserts);
        observer.observe(form, { childList: true, subtree: true });
        refreshInserts();

        // --- Mutators ------------------------------------------------------
        function deleteRow(btn) {
            var row = btn.closest('.lrob-etk-form-row');
            if (!row) return;
            // Remove both the row and the trailing insert-row zone (the "+"
            // that sits just after this row) so we don't leave dangling
            // zones. The first zone (above the row) acts as the surviving
            // insertion point for that gap.
            var next = row.nextElementSibling;
            row.remove();
            if (next && next.matches && next.matches('.lrob-etk-form-insert--row')) {
                next.remove();
            }
            commitAndSave();
        }
        function deleteColumn(btn) {
            var col = btn.closest('.lrob-etk-form-col');
            if (!col) return;
            var row = col.closest('.lrob-etk-form-row');
            if (!row) return;
            var cols = row.querySelectorAll('.lrob-etk-form-col');
            if (cols.length <= 1) return; // keep at least one column
            col.remove();
            updateRowCols(row);
            // If insertColumn previously removed the "+" because we hit the
            // 4-column cap, drop back below the cap means we need it back.
            if (row.querySelectorAll('.lrob-etk-form-col').length < 4
                && !row.querySelector(':scope > .lrob-etk-form-insert--column')) {
                row.appendChild(buildInsertZone('column'));
            }
            commitAndSave();
        }
        function deleteField(btn) {
            var shell = btn.closest('.lrob-etk-form-edit-shell');
            if (!shell) return;
            var col = shell.closest('.lrob-etk-form-col');
            var row = shell.closest('.lrob-etk-form-row');
            var next = shell.nextElementSibling;
            shell.remove();
            if (next && next.matches && next.matches('.lrob-etk-form-insert--field')) {
                next.remove();
            }
            // Clean up empty containers left by the deletion. Single-col row
            // → remove the whole row (and its trailing body insert). Multi-
            // col row → remove just the now-empty column and update the row
            // grid (re-adding the trailing column "+" if we dropped below 4).
            if (col && row && !col.querySelector('.lrob-etk-form-edit-shell')) {
                var rowCols = row.querySelectorAll(':scope > .lrob-etk-form-col');
                if (rowCols.length === 1) {
                    var rowNext = row.nextElementSibling;
                    row.remove();
                    if (rowNext && rowNext.matches && rowNext.matches('.lrob-etk-form-insert--row')) {
                        rowNext.remove();
                    }
                } else {
                    col.remove();
                    updateRowCols(row);
                    if (row.querySelectorAll(':scope > .lrob-etk-form-col').length < 4
                        && !row.querySelector(':scope > .lrob-etk-form-insert--column')) {
                        row.appendChild(buildInsertZone('column'));
                    }
                }
            }
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
            var row = btn.closest('.lrob-etk-form-row');
            if (!row) return;
            var existing = row.querySelectorAll(':scope > .lrob-etk-form-col').length;
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
            if (row.querySelectorAll(':scope > .lrob-etk-form-col').length >= 4) {
                btn.remove();
            }
            commitAndSave();
        }
        function showTypePicker(btn, onPick) {
            // Close any open picker first.
            form.querySelectorAll('.lrob-etk-form-type-picker').forEach(function (p) { p.remove(); });
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
            var n = row.querySelectorAll('.lrob-etk-form-col').length;
            row.setAttribute('data-cols', String(n));
        }

        // --- Inline settings strip ---------------------------------------
        // The strip sits between the field's drag/delete overlay and the
        // label, hidden at rest, revealed on shell hover (same pattern as
        // the helper-empty + option-remove reveal). It holds every per-
        // field setting (slug + type-specific knobs), replacing the gear
        // popup. Inputs write back to data-attr-* via the form-level
        // 'input' listener below.

        function inlineSettingsHtml(shell) {
            var type = shell.getAttribute('data-field-type') || '';
            var inner;
            if (type === 'submit') {
                inner = alignSegmentedHtml(shell, { defaultAlign: 'right', includeStretch: true });
            } else if (type === 'captcha') {
                // Captcha has no "full width" — hCaptcha is a fixed-width
                // iframe. Left / center / right only.
                inner = alignSegmentedHtml(shell, { defaultAlign: 'center', includeStretch: false });
            } else {
                inner = typeSpecificInlineHtml(shell, type);
            }
            if (inner === '') return '';
            return '<div class="lrob-etk-form-inline-settings" data-inline-settings>' + inner + '</div>';
        }

        function inlineChipHtml(key, label, control) {
            return '<label class="lrob-etk-form-inline-chip" data-inline-chip="' + key + '">'
                + '<span class="lrob-etk-form-inline-chip-label">' + esc(label) + '</span>'
                + control
                + '</label>';
        }
        // --- Slug derivation ---------------------------------------------
        // Slugs are auto-derived from `<type>_<sluggified-label>_<nth>` and
        // never manually editable. `nth` is the field's creation index,
        // assigned once when the field is first added and stable across
        // reorders / deletions so external references to the slug (Reply-To,
        // {token}s in subject/success templates, etc.) don't shift when
        // *other* fields are removed. The label still influences the slug,
        // though — renaming a field intentionally rewrites its slug, and
        // anything pointing to the old slug needs re-picking.
        function nextNth() {
            var max = 0;
            form.querySelectorAll('.lrob-etk-form-edit-shell').forEach(function (s) {
                var n = parseInt(s.getAttribute('data-attr-nth') || '0', 10);
                if (!isNaN(n) && n > max) max = n;
            });
            return max + 1;
        }
        function deriveSlug(type, labelText, nth) {
            if (type === 'submit' || type === 'captcha') return '';
            var fromLabel = String(labelText || '').toLowerCase()
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '')
                .substring(0, 40);
            var base = fromLabel ? type + '_' + fromLabel : type;
            return base + '_' + (parseInt(nth, 10) || 1);
        }
        function recomputeSlug(shell) {
            var type = shell.getAttribute('data-field-type') || '';
            if (type === 'submit' || type === 'captcha') return;
            var labelEl = shell.querySelector('[data-edit="label"]');
            var labelText = labelEl ? readEditableText(labelEl) : '';
            var nth = parseInt(shell.getAttribute('data-attr-nth') || '0', 10) || 1;
            var slug = deriveSlug(type, labelText, nth);
            if (slug === '' || slug === shell.getAttribute('data-attr-slug')) return;
            shell.setAttribute('data-attr-slug', slug);
            var fieldWrap = shell.querySelector('.lrob-etk-form-field');
            if (fieldWrap) fieldWrap.setAttribute('data-field', slug);
            // Radio / checkbox name="slug" / "slug[]" re-derives off the
            // current slug, so re-render the inline options too.
            applyOptionsToPreview(shell);
            queueSave();
        }
        function numChipHtml(shell, key, label, fallback) {
            var v = shell.getAttribute('data-attr-' + key);
            if (v === null || v === '') v = fallback != null ? String(fallback) : '';
            return inlineChipHtml(key, label,
                '<input type="number" data-inline-prop="' + key + '" value="' + escAttr(v) + '">'
            );
        }
        function textChipHtml(shell, key, label) {
            var v = shell.getAttribute('data-attr-' + key) || '';
            return inlineChipHtml(key, label,
                '<input type="text" data-inline-prop="' + key + '" value="' + escAttr(v) + '">'
            );
        }
        function checkChipHtml(shell, key, label, defaultOn) {
            var v = shell.getAttribute('data-attr-' + key);
            var on = v === null ? !!defaultOn : v === '1';
            return '<label class="lrob-etk-form-inline-check">'
                + '<input type="checkbox" data-inline-prop="' + key + '"' + (on ? ' checked' : '') + '>'
                + '<span>' + esc(label) + '</span>'
                + '</label>';
        }
        function alignSegmentedHtml(shell, options) {
            var defaultAlign = (options && options.defaultAlign) || 'right';
            var includeStretch = !options || options.includeStretch !== false;
            var align = shell.getAttribute('data-attr-align') || defaultAlign;
            var aligns = [
                ['left',    EDITOR_I18N.alignLeft    || 'Left'],
                ['center',  EDITOR_I18N.alignCenter  || 'Center'],
                ['right',   EDITOR_I18N.alignRight   || 'Right'],
            ];
            if (includeStretch) {
                aligns.push(['stretch', EDITOR_I18N.alignStretch || 'Full width']);
            }
            var btns = aligns.map(function (a) {
                return '<button type="button" class="lrob-etk-form-inline-seg' + (a[0] === align ? ' is-on' : '') + '"'
                    + ' data-inline-seg="align" data-value="' + a[0] + '">'
                    + esc(a[1])
                    + '</button>';
            }).join('');
            return inlineChipHtml('align', EDITOR_I18N.alignment || 'Alignment',
                '<span class="lrob-etk-form-inline-segmented">' + btns + '</span>'
            );
        }
        function placeholderComboHtml(shell) {
            var current = shell.getAttribute('data-attr-placeholder') || '';
            var label = EDITOR_I18N.placeholderText || EDITOR_I18N.placeholder || 'Placeholder';
            return '<label class="lrob-etk-form-inline-chip" data-inline-chip="placeholder">'
                + '<span class="lrob-etk-form-inline-chip-label">' + esc(label) + '</span>'
                + '<span class="lrob-etk-combo lrob-etk-form-inline-combo" data-inline-combo>'
                + '<input type="text" class="lrob-etk-combo-input" data-inline-prop="placeholder" value="' + escAttr(current) + '" placeholder="— select —" autocomplete="off">'
                + '<button type="button" class="lrob-etk-combo-toggle" tabindex="-1" aria-label="' + esc(EDITOR_I18N.placeholder || 'Placeholder') + '">'
                + '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>'
                + '</button>'
                + '<ul class="lrob-etk-combo-menu" role="listbox" hidden></ul>'
                + '</span>'
                + '</label>';
        }
        function typeSpecificInlineHtml(shell, type) {
            switch (type) {
                case 'textarea':
                    return numChipHtml(shell, 'rows',      EDITOR_I18N.rows      || 'Rows', 5)
                         + numChipHtml(shell, 'maxLength', EDITOR_I18N.maxLength || 'Max length', 0);
                case 'text':
                case 'email':
                    return numChipHtml(shell, 'maxLength', EDITOR_I18N.maxLength || 'Max length', 0);
                case 'number':
                    return textChipHtml(shell, 'min',  EDITOR_I18N.min  || 'Min')
                         + textChipHtml(shell, 'max',  EDITOR_I18N.max  || 'Max')
                         + textChipHtml(shell, 'step', EDITOR_I18N.step || 'Step');
                case 'phone':
                    return textChipHtml(shell, 'pattern', EDITOR_I18N.pattern || 'Regex pattern');
                case 'date':
                    return textChipHtml(shell, 'min', EDITOR_I18N.min || 'Min')
                         + textChipHtml(shell, 'max', EDITOR_I18N.max || 'Max');
                case 'select':
                    // No `multiple` chip — a native <select> is single-choice
                    // by design here; use the checkbox field type for multi.
                    return placeholderComboHtml(shell);
                case 'checkbox':
                    return checkChipHtml(shell, 'multiple', EDITOR_I18N.multiple || 'Multiple choices', true);
            }
            return '';
        }
        function ensureInlineSettings(shell) {
            if (!shell) return;
            var field = shell.querySelector(':scope > .lrob-etk-form-field');
            if (!field) return;
            if (!field.querySelector(':scope > .lrob-etk-form-inline-settings')) {
                var html = inlineSettingsHtml(shell);
                if (html) {
                    // Strip is an in-flow child of the field, appended
                    // after the helper / error. It uses the same max-
                    // height + opacity collapse pattern as the empty-
                    // helper reveal so it takes zero space at rest. The
                    // field's :hover naturally includes it because it
                    // lives inside the shell.
                    field.insertAdjacentHTML('beforeend', html);
                }
            }
            // (Re-)attach the combobox controller after every DOM-rebuild
            // path (initial sync, new field, undo/redo). The controller
            // guards itself against double-binding via combo.__etkBound.
            bindPlaceholderCombo(shell);
        }
        function bindPlaceholderCombo(shell) {
            var combo = shell.querySelector('.lrob-etk-form-inline-settings [data-inline-combo]');
            if (!combo || !window.lrobEtkControls || !window.lrobEtkControls.attachCombobox) return;
            var presets = EDITOR_DATA.placeholderPresets || [];
            window.lrobEtkControls.attachCombobox(combo, {
                mode: 'free',
                populate: function () { return presets.slice(); },
                setValue: function (value) {
                    var input = combo.querySelector('.lrob-etk-combo-input');
                    if (!input) return;
                    input.value = value;
                    // Dispatch 'input' so the form-level listener picks it up
                    // exactly like a keyboard edit.
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                },
            });
        }
        function toggleDefaultOption(btn) {
            var shell = btn.closest('.lrob-etk-form-edit-shell');
            if (!shell || shell.getAttribute('data-field-type') !== 'select') return;
            var row = btn.closest('[data-inline-option]');
            if (!row) return;
            var value = row.getAttribute('data-option-value') || '';
            var defaults = parseDefaults(shell);
            var multiple = shell.getAttribute('data-attr-multiple') === '1';
            var idx = defaults.indexOf(value);
            if (multiple) {
                if (idx >= 0) defaults.splice(idx, 1);
                else defaults.push(value);
            } else {
                defaults = idx >= 0 ? [] : [value];
            }
            shell.setAttribute('data-attr-defaults', JSON.stringify(defaults));
            applyOptionsToPreview(shell);
            commitAndSave();
        }
        function parseDefaults(shell) {
            try { return JSON.parse(shell.getAttribute('data-attr-defaults') || '[]'); }
            catch (e) { return []; }
        }

        // Inline-settings input handler: any [data-inline-prop] edit writes
        // back to the shell's data-attr-* and triggers a save. Also keeps
        // the input's value/checked ATTRIBUTE in sync with its property so
        // undo/redo snapshots (taken via innerHTML) preserve typed values.
        form.addEventListener('input', function (e) {
            var target = e.target.closest && e.target.closest('[data-inline-prop]');
            if (!target) return;
            var shell = target.closest('.lrob-etk-form-edit-shell');
            if (!shell) return;
            var key = target.getAttribute('data-inline-prop');
            var val;
            if (target.type === 'checkbox') {
                val = target.checked ? '1' : '0';
                if (target.checked) target.setAttribute('checked', '');
                else target.removeAttribute('checked');
            } else {
                val = target.value;
                target.setAttribute('value', val);
            }
            shell.setAttribute('data-attr-' + key, val);
            if (key === 'slug') {
                var fieldWrap = shell.querySelector('.lrob-etk-form-field');
                if (fieldWrap) fieldWrap.setAttribute('data-field', val);
                applyOptionsToPreview(shell); // re-derive radio/checkbox name="slug" / "slug[]"
            }
            if (key === 'multiple' || key === 'placeholder') {
                applyOptionsToPreview(shell);
            }
            queueSave();
        });
        // Segmented controls (submit alignment): click flips the active button.
        form.addEventListener('click', function (e) {
            var seg = e.target.closest && e.target.closest('[data-inline-seg]');
            if (!seg) return;
            e.preventDefault();
            var shell = seg.closest('.lrob-etk-form-edit-shell');
            if (!shell) return;
            var key = seg.getAttribute('data-inline-seg');
            var val = seg.getAttribute('data-value') || '';
            shell.setAttribute('data-attr-' + key, val);
            var siblings = seg.parentElement.querySelectorAll('[data-inline-seg="' + key + '"]');
            Array.prototype.forEach.call(siblings, function (s) {
                s.classList.toggle('is-on', s === seg);
            });
            if (key === 'align') {
                // Submit + captcha both store the chosen alignment as
                // `is-align-*` on the field wrapper. For captcha, the
                // wrapper is the editor stub (.lrob-etk-form-field--captcha).
                var alignTarget = shell.querySelector('.lrob-etk-form-field--submit')
                    || shell.querySelector('.lrob-etk-form-field--captcha')
                    || shell.querySelector('.lrob-etk-form-field--challenge');
                if (alignTarget) {
                    alignTarget.className = alignTarget.className.replace(/\s*is-align-(left|center|right|stretch)/g, '').trim();
                    alignTarget.classList.add('is-align-' + val);
                }
            }
            commitAndSave();
        });

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
            var field = shell.querySelector('.lrob-etk-form-field');
            if (!field) return;
            var slug = shell.getAttribute('data-attr-slug') || 'field';
            var options = parseOptions(shell);

            if (type === 'checkbox' && shell.getAttribute('data-attr-multiple') === '0') {
                // Single checkbox doesn't take a list of options. Wipe any
                // existing options block and show a one-line placeholder.
                var stale = field.querySelector(':scope > .lrob-etk-form-options');
                if (stale) stale.outerHTML = '<div class="lrob-etk-form-options"><p class="lrob-etk-form-helper">' + esc(EDITOR_I18N.singleCheckboxHint || 'Single checkbox — no options needed.') + '</p></div>';
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
            var shell = field.closest('.lrob-etk-form-edit-shell');
            var defaults = shell ? parseDefaults(shell) : [];
            var placeholder = shell ? (shell.getAttribute('data-attr-placeholder') || '') : '';

            // Pick the single default option that still exists in the
            // option list (the editor enforces single-pick). If none, the
            // select renders a leading placeholder row so the user sees
            // a clear "nothing selected" state — same shape FieldRenderer
            // emits on the frontend.
            var validValues = options.map(function (o) { return String(o.value || ''); });
            var selectedValue = '';
            for (var i = 0; i < defaults.length; i++) {
                if (validValues.indexOf(defaults[i]) !== -1) { selectedValue = defaults[i]; break; }
            }

            var existingSelect = field.querySelector(':scope > select');
            var placeholderText = placeholder !== '' ? placeholder : '— select —';
            // Always emit the placeholder option — picking it from the
            // dropdown is the user's way of clearing the default. The
            // matching `change` handler below resets data-attr-defaults
            // when the value is empty.
            var optsHtml = '<option value=""' + (selectedValue === '' ? ' selected' : '') + '>' + esc(placeholderText) + '</option>';
            options.forEach(function (o) {
                var v = String(o.value || '');
                optsHtml += '<option value="' + escAttr(v) + '"' + (v === selectedValue ? ' selected' : '') + '>' + esc(o.label || '') + '</option>';
            });
            var selectHtml = '<select id="lrob-etk-cf-editor-' + escAttr(slug) + '">' + optsHtml + '</select>';
            if (existingSelect) {
                existingSelect.outerHTML = selectHtml;
            } else {
                field.insertAdjacentHTML('beforeend', selectHtml);
            }
            var existingInline = field.querySelector(':scope > .lrob-etk-form-options');
            var inlineHtml = inlineOptionEditorHtml(options, defaults, true);
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
            var existing = field.querySelector(':scope > .lrob-etk-form-options, :scope > fieldset, :scope > select');
            var inputType = type === 'radio' ? 'radio' : 'checkbox';
            var name = type === 'checkbox' ? slug + '[]' : slug;
            var items = options.map(function (o) {
                return inlineOptionRowHtml(inputType, name, o.label || '', o.value || '');
            }).join('');
            var html = '<div class="lrob-etk-form-options" data-options-inline>'
                + items
                + inlineAddButtonHtml()
                + '</div>';
            if (existing) {
                existing.outerHTML = html;
            } else {
                field.insertAdjacentHTML('beforeend', html);
            }
        }

        // `withDefault`: when true (select only), each row carries a ★
        // "use as default" toggle next to the × remove button. The toggle
        // is hidden at rest by the same CSS that hides the ×.
        function inlineOptionEditorHtml(options, defaults, withDefault) {
            defaults = defaults || [];
            var rows = options.map(function (o) {
                var value = String(o.value || '');
                var isDef = withDefault && defaults.indexOf(value) !== -1;
                var defBtn = withDefault
                    ? '<button type="button" class="lrob-etk-form-option-default' + (isDef ? ' is-on' : '') + '"'
                        + ' data-action="toggle-default-option"'
                        + ' aria-pressed="' + (isDef ? 'true' : 'false') + '"'
                        + ' title="' + esc(isDef ? (EDITOR_I18N.unsetDefault || 'Remove as default') : (EDITOR_I18N.setAsDefault || 'Use as default')) + '">★</button>'
                    : '';
                return '<div class="lrob-etk-form-inline-option" data-inline-option data-option-value="' + escAttr(value) + '">'
                    + '<span class="lrob-etk-form-option-label" contenteditable="plaintext-only" data-option-edit spellcheck="false">' + esc(o.label || '') + '</span>'
                    + defBtn
                    + '<button type="button" class="lrob-etk-form-option-remove" data-action="delete-inline-option" aria-label="' + esc(EDITOR_I18N.removeOption || 'Remove option') + '">×</button>'
                    + '</div>';
            }).join('');
            return '<div class="lrob-etk-form-options" data-options-inline>'
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
            return '<div class="lrob-etk-form-option" data-inline-option>'
                + '<input type="' + inputType + '" name="' + escAttr(name) + '" value="' + escAttr(value) + '" tabindex="-1">'
                + '<span class="lrob-etk-form-option-label" contenteditable="plaintext-only" data-option-edit spellcheck="false">' + esc(label) + '</span>'
                + '<button type="button" class="lrob-etk-form-option-remove" data-action="delete-inline-option" aria-label="' + esc(EDITOR_I18N.removeOption || 'Remove option') + '">×</button>'
                + '</div>';
        }
        function inlineAddButtonHtml() {
            return '<button type="button" class="lrob-etk-form-option-add" data-action="add-inline-option">+ '
                + esc(EDITOR_I18N.addOption || 'Add option')
                + '</button>';
        }

        /**
         * Read every contenteditable option label inside a shell, derive
         * value from label, write back to data-attr-options. Also remap
         * stored defaults (select fields) when labels rename — each row
         * tracks its OLD value via data-option-value, so we translate
         * old→new across the rename and rewrite both attributes together.
         */
        function syncOptionsFromInline(shell) {
            var optionsContainer = shell.querySelector('[data-options-inline]');
            if (!optionsContainer) return;
            var rows = optionsContainer.querySelectorAll('[data-inline-option]');
            var oldDefaults = parseDefaults(shell);
            var valueMap = {}; // old → new
            var seen = {};
            var options = [];
            Array.prototype.forEach.call(rows, function (row) {
                var labelEl = row.querySelector('[data-option-edit]');
                var label = labelEl ? String(labelEl.textContent || '').trim() : '';
                if (label === '') return;
                var base = deriveOptionValue(label);
                var value = base;
                var i = 2;
                while (seen[value]) { value = base + '_' + i++; }
                seen[value] = true;
                var oldValue = row.getAttribute('data-option-value');
                if (oldValue && oldValue !== value) {
                    valueMap[oldValue] = value;
                }
                row.setAttribute('data-option-value', value);
                options.push({ label: label, value: value });
            });
            shell.setAttribute('data-attr-options', JSON.stringify(options));
            if (oldDefaults.length > 0 && shell.getAttribute('data-field-type') === 'select') {
                var validValues = options.map(function (o) { return o.value; });
                var newDefaults = oldDefaults
                    .map(function (v) { return valueMap[v] || v; })
                    .filter(function (v) { return validValues.indexOf(v) !== -1; });
                shell.setAttribute('data-attr-defaults', JSON.stringify(newDefaults));
            }
        }

        // --- DOM builders for newly-inserted elements ---------------------
        function genId(prefix) {
            return prefix + '_' + Math.random().toString(36).substr(2, 8);
        }
        function buildRow() {
            var rowId = genId('row');
            var row = document.createElement('div');
            row.className = 'lrob-etk-form-row';
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
            var col = row.querySelector('.lrob-etk-form-col');
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
            return '<div class="lrob-etk-form-col" data-col-id="' + colId + '" data-draggable-type="col" draggable="true">'
                + colOverlayHtml()
                + insertZoneHtml('field')
                + '</div>';
        }
        function buildField(type) {
            var id = genId('f');
            var wrap = document.createElement('div');
            wrap.innerHTML = fieldShellHtml(id, type);
            var shell = wrap.firstElementChild;
            // Inject the inline settings strip + dataset.etkInit so the
            // initial-sync pass on next load won't try to re-scrape what we
            // already know.
            ensureInlineSettings(shell);
            if (shell) shell.dataset.etkInit = '1';
            return shell;
        }
        function fieldShellHtml(id, type) {
            var nth = nextNth();
            var initialLabel = (type === 'submit' || type === 'captcha') ? '' : (FIELD_TYPES[type] || type);
            var initialSlug = deriveSlug(type, initialLabel, nth);
            var inner = freshFieldInnerHtml(type, initialSlug);
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
                if (type === 'select') {
                    extraAttrs += ' data-attr-placeholder="" data-attr-defaults="[]"';
                }
                if (type === 'checkbox') {
                    // Checkbox defaults to MULTIPLE — that's the use case
                    // the seed options make sense for. Single-checkbox
                    // (consent-style) flips via the inline settings strip.
                    extraAttrs += ' data-attr-multiple="1"';
                }
            }
            return '<div class="lrob-etk-form-edit-shell" data-field-id="' + id + '" data-field-type="' + type + '" data-draggable-type="field" draggable="true"'
                + ' data-attr-nth="' + nth + '"'
                + ' data-attr-slug="' + escAttr(initialSlug) + '"'
                + ' data-attr-required="1"'
                + extraAttrs
                + '>' + fieldOverlayHtml(type) + inner + '</div>';
        }
        function freshFieldInnerHtml(type, slug) {
            // Minimal field stub. The user will fill in label / placeholder
            // / options inline; the serializer reads everything from this
            // markup on save.
            var typeLabel = FIELD_TYPES[type] || type;
            if (type === 'submit') {
                return '<div class="lrob-etk-form-field lrob-etk-form-field--submit is-align-right">'
                    + '<button type="button" class="lrob-etk-form-submit"><span class="lrob-etk-form-submit-label" contenteditable="plaintext-only" data-edit="submit-text">' + esc(typeLabel) + '</span></button>'
                    + '</div>';
            }
            if (type === 'captcha') {
                return captchaEditorStubHtml('', 'center');
            }
            slug = slug || defaultSlug(type);
            var labelText = typeLabel;
            var control = buildControlHtml(type, slug);
            // Mirror FieldRenderer's editor markup: <div> (not <label>) so
            // clicks on the contenteditable span aren't stolen by label-for.
            // New fields are required-by-default — the user can uncheck it
            // via the inline `[checkbox] Required` that the star morphs
            // into on hover.
            var requiredLabel = esc(EDITOR_I18N.required || 'Required');
            return '<div class="lrob-etk-form-field lrob-etk-form-field--' + type + '" data-field="' + escAttr(slug) + '">'
                + '<div class="lrob-etk-form-label">'
                + '<span class="lrob-etk-form-label-text" contenteditable="plaintext-only" data-edit="label" spellcheck="false">' + esc(labelText) + '</span>'
                + '<span class="lrob-etk-form-required-control">'
                + '<span class="lrob-etk-form-required-star is-on" aria-hidden="true">*</span>'
                + '<label class="lrob-etk-form-required-check">'
                + '<input type="checkbox" data-required-toggle checked>'
                + '<span>' + requiredLabel + '</span>'
                + '</label>'
                + '</span>'
                + '</div>'
                + control
                + '<p class="lrob-etk-form-helper lrob-etk-form-helper-empty" contenteditable="plaintext-only" data-edit="helper" spellcheck="false">' + esc(EDITOR_I18N.helperPlaceholder || '(optional helper text)') + '</p>'
                + '<p class="lrob-etk-form-error" data-field-error hidden></p>'
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
                    // New select fields have no placeholder row and no
                    // pre-selected defaults — both knobs live in the inline
                    // settings strip and inline editor for the user to set.
                    var selectOpts = seed.map(function (o) {
                        return '<option value="' + escAttr(o.value) + '">' + esc(o.label) + '</option>';
                    }).join('');
                    return '<select id="' + id + '">' + selectOpts + '</select>'
                        + inlineOptionEditorHtml(seed, [], true);
                case 'radio':
                    return '<div class="lrob-etk-form-options" data-options-inline>'
                        + seed.map(function (o) { return inlineOptionRowHtml('radio', slug, o.label, o.value); }).join('')
                        + inlineAddButtonHtml()
                        + '</div>';
                case 'checkbox':
                    return '<div class="lrob-etk-form-options" data-options-inline>'
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
        function captchaEditorStubHtml(currentRoute, align) {
            var key = EDITOR_DATA.captchaKey || '_lrob_etk_cf_challenge';
            var entries = captchaEntries();
            var safeAlign = (align === 'left' || align === 'center' || align === 'right') ? align : 'center';
            // Build option/optgroup HTML, preserving the entries' insertion
            // order so the picker matches what the server emits. Entries
            // with an `optgroup` open a fresh <optgroup> on encountering a
            // new group name.
            var opts = '';
            var currentGroup = '';
            for (var i = 0; i < entries.length; i++) {
                var e = entries[i];
                var group = e.optgroup || '';
                if (group !== currentGroup) {
                    if (currentGroup !== '') opts += '</optgroup>';
                    if (group !== '') opts += '<optgroup label="' + escAttr(group) + '">';
                    currentGroup = group;
                }
                var selected = (currentRoute === e.route) ? ' selected' : '';
                var disabled = e.disabled ? ' disabled' : '';
                opts += '<option value="' + escAttr(e.route) + '"' + selected + disabled + '>'
                      + esc(e.label) + '</option>';
            }
            if (currentGroup !== '') opts += '</optgroup>';

            return '<div class="lrob-etk-form-field lrob-etk-form-field--captcha is-editor-stub is-align-' + safeAlign + '" data-captcha-block>'
                + '<div class="lrob-etk-cf-captcha-stub-head">'
                + '<span class="lrob-etk-cf-captcha-stub-icon dashicons dashicons-shield" aria-hidden="true"></span>'
                + '<label class="lrob-etk-cf-captcha-stub-label">' + esc(EDITOR_I18N.captchaPick || 'Anti-spam:') + '</label>'
                + '<select class="lrob-etk-form-field lrob-etk-cf-captcha-pick" name="' + escAttr(key) + '" data-key="' + escAttr(key) + '" data-captcha-pick>' + opts + '</select>'
                + '</div>'
                + '<div class="lrob-etk-cf-captcha-stub-preview" data-captcha-preview>' + captchaPreviewHtml(currentRoute) + '</div>'
                + '</div>';
        }
        /**
         * Look up the pre-rendered preview HTML for a routing key sent over
         * via wp_localize_script. Default ('') and none have their own
         * entries in the list — they always exist.
         */
        function captchaPreviewHtml(route) {
            var entries = captchaEntries();
            for (var i = 0; i < entries.length; i++) {
                if (entries[i].route === route && entries[i].preview) return entries[i].preview;
            }
            return '<p class="lrob-etk-cf-captcha-stub-empty">'
                + esc(EDITOR_I18N.captchaOff || 'No anti-spam challenge.')
                + '</p>';
        }
        function captchaEntries() {
            var opts = EDITOR_DATA.captchaOptions || {};
            return Array.isArray(opts.entries) ? opts.entries : [];
        }
        function defaultSlug(type) {
            if (type === 'submit') return '';
            if (type === 'captcha') return '';
            return type;
        }
        function rowOverlayHtml() {
            return '<div class="lrob-etk-form-overlay lrob-etk-form-overlay--row" aria-hidden="true">'
                + '<span class="lrob-etk-form-overlay-handle dashicons dashicons-move"></span>'
                + '<button type="button" class="lrob-etk-form-overlay-btn" data-action="delete-row"><span class="dashicons dashicons-trash"></span></button>'
                + '</div>';
        }
        function colOverlayHtml() {
            return '<div class="lrob-etk-form-overlay lrob-etk-form-overlay--col" aria-hidden="true">'
                + '<span class="lrob-etk-form-overlay-handle dashicons dashicons-move"></span>'
                + '<button type="button" class="lrob-etk-form-overlay-btn" data-action="delete-col"><span class="dashicons dashicons-trash"></span></button>'
                + '</div>';
        }
        function fieldOverlayHtml(type) {
            // Per-field settings live inline next to the label now (see
            // .lrob-etk-form-inline-settings); the overlay carries just drag +
            // delete so it stays out of the way.
            return '<div class="lrob-etk-form-overlay lrob-etk-form-overlay--field" aria-hidden="true">'
                + '<span class="lrob-etk-form-overlay-handle dashicons dashicons-move"></span>'
                + '<button type="button" class="lrob-etk-form-overlay-btn lrob-etk-form-overlay-btn--delete" data-action="delete-field"><span class="dashicons dashicons-trash"></span></button>'
                + '</div>';
        }
        function insertZoneHtml(kind) {
            // Same labelling logic as FormEditorRenderer::insert_zone (PHP).
            var labelText = (kind === 'row' || kind === 'field') ? (EDITOR_I18N.fieldLabel || 'Field') : '';
            var label = labelText ? '<span class="lrob-etk-form-insert-label">' + esc(labelText) + '</span>' : '';
            return '<button type="button" class="lrob-etk-form-insert lrob-etk-form-insert--' + kind + '" data-insert="' + kind + '" aria-label="Add">'
                + '<span class="lrob-etk-form-insert-plus" aria-hidden="true">+</span>' + label + '</button>';
        }
        function buildInsertZone(kind) {
            var wrap = document.createElement('div');
            wrap.innerHTML = insertZoneHtml(kind);
            return wrap.firstElementChild;
        }

        // --- Serializer (DOM → JSON) --------------------------------------
        function serialize(form) {
            var rows = [];
            form.querySelectorAll(':scope > .lrob-etk-form-body > .lrob-etk-form-row').forEach(function (rowEl) {
                var cols = [];
                rowEl.querySelectorAll(':scope > .lrob-etk-form-col').forEach(function (colEl) {
                    var fields = [];
                    colEl.querySelectorAll(':scope > .lrob-etk-form-edit-shell').forEach(function (shellEl) {
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
            if (type === 'captcha') {
                f.align = shell.getAttribute('data-attr-align') || 'center';
                return f;
            }

            // Inline-edit values.
            var labelEl = shell.querySelector('[data-edit="label"]');
            var helperEl = shell.querySelector('[data-edit="helper"]');
            var inputEl = shell.querySelector('input, textarea, select');

            f.slug     = shell.getAttribute('data-attr-slug') || '';
            f.nth      = parseInt(shell.getAttribute('data-attr-nth') || '0', 10) || 0;
            f.label    = labelEl ? readEditableText(labelEl) : '';
            f.helper   = helperEl ? readEditableText(helperEl, true) : '';
            f.required = shell.getAttribute('data-attr-required') === '1';
            // Select stores placeholder on the shell (set via the inline
            // settings strip); other types fall back to the input's
            // placeholder attribute if one is present.
            if (type === 'select') {
                f.placeholder = shell.getAttribute('data-attr-placeholder') || '';
            } else {
                f.placeholder = inputEl && inputEl.hasAttribute('placeholder') ? inputEl.getAttribute('placeholder') : '';
            }

            // Type-specific scalars stored as data-attr-* by the inline
            // settings strip.
            ['maxLength', 'rows', 'min', 'max', 'step', 'pattern'].forEach(function (k) {
                var v = shell.getAttribute('data-attr-' + k);
                if (v !== null && v !== '') {
                    f[k] = (k === 'maxLength' || k === 'rows') ? (parseInt(v, 10) || 0) : v;
                }
            });
            if (type === 'select' || type === 'radio' || type === 'checkbox') {
                var optionsAttr = shell.getAttribute('data-attr-options');
                if (optionsAttr) {
                    try { f.options = JSON.parse(optionsAttr); } catch (e) { f.options = []; }
                }
            }
            if (type === 'select') {
                f.defaults = parseDefaults(shell);
            }
            if (type === 'checkbox') {
                // Default is multiple-checkboxes; only `0` flips to single.
                f.multiple = shell.getAttribute('data-attr-multiple') !== '0';
            }
            return f;
        }
        function readEditableText(el, ignoreEmptyPlaceholder) {
            if (ignoreEmptyPlaceholder && el.classList.contains('lrob-etk-form-helper-empty')) return '';
            if (el.querySelector('.lrob-etk-form-label-empty')) return '';
            return (el.textContent || '').trim();
        }
        function readHelperText(shell) {
            var h = shell.querySelector('[data-edit="helper"]');
            return h && !h.classList.contains('lrob-etk-form-helper-empty') ? (h.textContent || '').trim() : '';
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
        form.querySelectorAll('.lrob-etk-form-edit-shell').forEach(function (shell) {
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
            var wrap = shell.querySelector('.lrob-etk-form-field');
            if (wrap && wrap.hasAttribute('data-field')) {
                shell.setAttribute('data-attr-slug', wrap.getAttribute('data-field'));
            }
            // Submit / captcha align: read from the field's class
            // (is-align-X). Captcha has no stretch variant. For captcha,
            // the carrier in the editor is .lrob-etk-form-field--captcha
            // (the editor stub); .lrob-etk-form-field--challenge only
            // appears later inside the preview slot once JS injects the
            // live widget.
            var alignWrap = shell.querySelector('.lrob-etk-form-field--submit')
                || shell.querySelector('.lrob-etk-form-field--captcha')
                || shell.querySelector('.lrob-etk-form-field--challenge');
            if (alignWrap) {
                var m = alignWrap.className.match(/is-align-(left|center|right|stretch)/);
                if (m) shell.setAttribute('data-attr-align', m[1]);
            }
            // Required: editor mode renders a star + checkbox marker. The
            // checkbox carries the canonical state; the legacy spans/
            // toggle-button are still accepted in case an older PHP render
            // is on the page.
            if (shell.querySelector('[data-required-toggle]:checked')
                || shell.querySelector('.lrob-etk-form-required-star.is-on')
                || shell.querySelector('.lrob-etk-form-required-toggle.is-on')
                || shell.querySelector('.lrob-etk-form-required')) {
                shell.setAttribute('data-attr-required', '1');
            }

            // Multi-choice fields: scrape options + multiple from the PHP-
            // rendered DOM so the inline editor (and serializer) has them
            // after a page reload. Without this the inline editor would be
            // empty.
            var type = shell.getAttribute('data-field-type') || '';
            if ((type === 'select' || type === 'radio' || type === 'checkbox') && !shell.hasAttribute('data-attr-options')) {
                var scraped = [];
                var scrapedDefaults = [];
                if (type === 'select') {
                    shell.querySelectorAll('select > option').forEach(function (opt) {
                        if (opt.value === '') return; // skip the placeholder row
                        scraped.push({ label: (opt.textContent || '').trim(), value: opt.value });
                        if (opt.selected) scrapedDefaults.push(opt.value);
                    });
                } else {
                    shell.querySelectorAll('.lrob-etk-form-option').forEach(function (lbl) {
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
                if (type === 'select' && scrapedDefaults.length > 0 && !shell.hasAttribute('data-attr-defaults')) {
                    shell.setAttribute('data-attr-defaults', JSON.stringify(scrapedDefaults));
                }
            }
            if (type === 'select' && !shell.hasAttribute('data-attr-placeholder')) {
                var sel2 = shell.querySelector('select');
                var first = sel2 ? sel2.querySelector('option[value=""]') : null;
                // The frontend renderer falls back to "— select —" when the
                // placeholder is empty, so don't lock that fallback into
                // the data attribute — leave it empty and let the renderer
                // re-fall-back next time.
                var firstText = first ? (first.textContent || '').trim() : '';
                shell.setAttribute('data-attr-placeholder', firstText === '— select —' ? '' : firstText);
            }
            if (type === 'checkbox' && !shell.hasAttribute('data-attr-multiple')) {
                // Multi if a fieldset / option list was rendered, single
                // otherwise (a lone inline checkbox).
                shell.setAttribute('data-attr-multiple',
                    shell.querySelector('.lrob-etk-form-field--checkbox-single, .lrob-etk-form-checkbox-inline') ? '0' : '1'
                );
            }

            // Replace the PHP-rendered options markup with the editable
            // inline editor so users can rename / add / remove right on
            // the preview.
            if (type === 'select' || type === 'radio' || type === 'checkbox') {
                applyOptionsToPreview(shell);
            }

            // Drop the inline settings strip in. Idempotent — also called
            // by buildField for freshly-inserted shells.
            ensureInlineSettings(shell);
        });

        // Assign a stable creation-order `nth` to legacy shells that lack
        // one (forms saved before this update). Done as a second pass so
        // existing `nth`s on the page are respected first, then any
        // unnumbered shells slot into the next available indices in DOM
        // order — they'll keep that index from now on, even after the
        // user reorders or deletes other fields. Slugs left as-is on
        // first load so external references (Reply-To, {token}s) keep
        // matching until the user explicitly renames a field's label.
        var maxNthInit = 0;
        form.querySelectorAll('.lrob-etk-form-edit-shell').forEach(function (s) {
            var n = parseInt(s.getAttribute('data-attr-nth') || '0', 10);
            if (!isNaN(n) && n > maxNthInit) maxNthInit = n;
        });
        form.querySelectorAll('.lrob-etk-form-edit-shell').forEach(function (s) {
            if (s.hasAttribute('data-attr-nth')) return;
            maxNthInit++;
            s.setAttribute('data-attr-nth', String(maxNthInit));
        });

        // Seed the history with the initial state, AFTER the data-attr sync
        // so undo from any later mutation lands back on a complete snapshot.
        commit();
    }

    /**
     * Mount any unmounted hCaptcha widgets that live inside a preview
     * slot. Called on initial load (for whatever PHP rendered) and after
     * a captcha-pick dropdown change swaps fresh HTML into the slot.
     *
     * hCaptcha can host many widgets per page natively — each gets a
     * unique iframe + internal widget id — so we don't worry about
     * cleanup when a preview gets replaced. The old widget's DOM goes
     * away with the innerHTML swap; any internal references hCaptcha
     * holds become garbage on the next solve/page nav.
     */
    function mountCaptchaPreview(previewEl) {
        if (!previewEl) return;
        var widgets = previewEl.querySelectorAll('.h-captcha:not([data-etk-mounted])');
        if (widgets.length === 0) return;
        ensureHCaptchaScript(function () {
            widgets.forEach(function (el) {
                if (el.hasAttribute('data-etk-mounted')) return;
                if (!window.hcaptcha || typeof window.hcaptcha.render !== 'function') return;
                try {
                    window.hcaptcha.render(el);
                    el.setAttribute('data-etk-mounted', '1');
                } catch (e) {
                    // Already rendered (auto-render kicked in first) — fine.
                    el.setAttribute('data-etk-mounted', '1');
                }
            });
        });
    }

    /**
     * Lazy-load the hCaptcha vendor script the first time the editor
     * needs it. Uses default mode (no ?render=explicit) so the first
     * batch of widgets present at load auto-renders; subsequent
     * dynamically-injected widgets are rendered manually via
     * hcaptcha.render(). Polls for the global since hCaptcha's script
     * doesn't expose a documented onload promise.
     */
    var hCaptchaLoading = false;
    var hCaptchaCallbacks = [];
    function ensureHCaptchaScript(callback) {
        if (window.hcaptcha && typeof window.hcaptcha.render === 'function') {
            callback();
            return;
        }
        hCaptchaCallbacks.push(callback);
        if (hCaptchaLoading) return;
        hCaptchaLoading = true;

        if (!document.querySelector('script[src*="js.hcaptcha.com"]')) {
            var s = document.createElement('script');
            s.src = 'https://js.hcaptcha.com/1/api.js';
            s.async = true;
            s.defer = true;
            document.head.appendChild(s);
        }
        var attempts = 0;
        var poll = setInterval(function () {
            if (window.hcaptcha && typeof window.hcaptcha.render === 'function') {
                clearInterval(poll);
                var fns = hCaptchaCallbacks.splice(0);
                fns.forEach(function (fn) { try { fn(); } catch (e) {} });
            } else if (++attempts > 100) {
                // 10s ceiling; give up rather than poll forever on a
                // blocked network.
                clearInterval(poll);
                hCaptchaCallbacks.length = 0;
            }
        }, 100);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
