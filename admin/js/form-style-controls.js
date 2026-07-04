/* Docs: docs/forms.md → "Form theming".
 * Drives the per-axis style editor: the global preset dropdown bulk-sets the
 * override map; each axis dropdown picks a named sub-preset; the Edit button
 * reveals that axis's individual knobs. The override map (schemaKey → value) is
 * the single source of truth, persisted via the hidden [data-style-json] field
 * (a save-hook field, so dispatching 'change' saves) and painted live onto the
 * card preview. Shared by Contact Form + Newsletter form cards. */
(function () {
    'use strict';

    var DATA = window.lrobEtkStyle || { presets: {}, axes: {}, vars: [], keyToVar: {} };

    function init() {
        var panels = document.querySelectorAll('[data-style-customize]');
        Array.prototype.forEach.call(panels, bindPanel);
    }

    function bindPanel(root) {
        if (root.__lrobEtkStyleBound) return;
        root.__lrobEtkStyleBound = true;

        var scope = root.closest('.lrob-etk-form-card') || root.closest('.lrob-etk-card') || document;
        var previewForm = scope.querySelector('.lrob-etk-form.is-editor');
        var jsonField = root.querySelector('[data-style-json]');
        var presetSelect = root.querySelector('[data-style-preset]');
        var axesPanel = root.querySelector('[data-style-axes]');
        var toggle = root.querySelector('[data-style-toggle]');
        var map = parseObj(jsonField ? jsonField.value : '');

        // Which full preset the whole map equals (else 'custom').
        function matchPreset() {
            var cur = normObj(map);
            var slugs = Object.keys(DATA.presets || {});
            for (var i = 0; i < slugs.length; i++) {
                if (sameObj(normObj(DATA.presets[slugs[i]]), cur)) return slugs[i];
            }
            return 'custom';
        }
        function refreshPreset() { if (presetSelect) presetSelect.value = matchPreset(); }

        function paint() {
            if (!previewForm) return;
            (DATA.vars || []).forEach(function (v) { previewForm.style.removeProperty(v); });
            Object.keys(map).forEach(function (k) {
                var cssVar = DATA.keyToVar[k];
                if (cssVar) previewForm.style.setProperty(cssVar, map[k]);
            });
        }
        function persist() {
            if (!jsonField) return;
            jsonField.value = Object.keys(map).length ? JSON.stringify(map) : '';
            jsonField.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // --- Axis selects (named sub-presets) ---
        function axisActive(axisKey) {
            var def = DATA.axes[axisKey];
            if (!def) return 'custom';
            var sub = {};
            def.keys.forEach(function (k) { if (map[k] != null && map[k] !== '') sub[k] = map[k]; });
            var opts = def.options || {};
            var slugs = Object.keys(opts);
            for (var i = 0; i < slugs.length; i++) {
                if (sameObj(normObj(opts[slugs[i]]), sub)) return slugs[i];
            }
            return 'custom';
        }
        function syncAxis(axisEl) {
            var axisKey = axisEl.getAttribute('data-style-axis');
            var select = axisEl.querySelector('[data-style-axis-select]');
            if (select) select.value = axisActive(axisKey);
            // Reflect map values into this axis's knobs.
            axisEl.querySelectorAll('[data-style-key]').forEach(function (row) {
                reflectRow(row, map[row.getAttribute('data-style-key')]);
            });
        }
        function syncAll() {
            root.querySelectorAll('[data-style-axis]').forEach(syncAxis);
            refreshPreset();
        }

        function applyAxis(axisEl, slug) {
            var axisKey = axisEl.getAttribute('data-style-axis');
            var def = DATA.axes[axisKey];
            if (!def || slug === 'custom') return;
            var vars = def.options[slug] || {};
            def.keys.forEach(function (k) { delete map[k]; });
            Object.keys(vars).forEach(function (k) { map[k] = vars[k]; });
            persist(); paint(); syncAxis(axisEl); refreshPreset();
        }

        root.addEventListener('change', function (e) {
            var preset = e.target.closest('[data-style-preset]');
            if (preset) {
                if (preset.value !== 'custom') {
                    map = cloneObj(DATA.presets[preset.value] || {});
                    persist(); paint(); syncAll();
                }
                return;
            }
            var sel = e.target.closest('[data-style-axis-select]');
            if (sel) { applyAxis(sel.closest('[data-style-axis]'), sel.value); return; }
            onKnob(e);
        });
        root.addEventListener('input', onKnob);

        function onKnob(e) {
            var input = e.target.closest('[data-style-input]');
            if (!input) return;
            var row = input.closest('[data-style-key]');
            if (!row) return;
            var key = row.getAttribute('data-style-key');
            var value = (input.value || '').trim();
            setRowState(row, value !== '');
            if (value === '') { delete map[key]; } else { map[key] = value; }
            persist(); paint();
            var axisEl = row.closest('[data-style-axis]');
            if (axisEl) {
                var select = axisEl.querySelector('[data-style-axis-select]');
                if (select) select.value = axisActive(axisEl.getAttribute('data-style-axis'));
            }
            refreshPreset();
        }

        root.addEventListener('click', function (e) {
            var tog = e.target.closest('[data-style-toggle]');
            if (tog && axesPanel) {
                var showing = axesPanel.hasAttribute('hidden');
                if (showing) axesPanel.removeAttribute('hidden'); else axesPanel.setAttribute('hidden', '');
                tog.setAttribute('aria-expanded', showing ? 'true' : 'false');
                return;
            }
            var edit = e.target.closest('[data-style-edit]');
            if (edit) {
                var box = edit.closest('[data-style-axis]').querySelector('[data-style-axis-edit]');
                if (box) {
                    var opening = box.hasAttribute('hidden');
                    if (opening) box.removeAttribute('hidden'); else box.setAttribute('hidden', '');
                    edit.setAttribute('aria-expanded', opening ? 'true' : 'false');
                }
                return;
            }
            var clear = e.target.closest('[data-style-clear]');
            if (clear) {
                var row = clear.closest('[data-style-key]');
                if (row) {
                    delete map[row.getAttribute('data-style-key')];
                    reflectRow(row, null);
                    persist(); paint();
                    var axisEl = row.closest('[data-style-axis]');
                    if (axisEl) { var s = axisEl.querySelector('[data-style-axis-select]'); if (s) s.value = axisActive(axisEl.getAttribute('data-style-axis')); }
                    refreshPreset();
                }
            }
        });

        function reflectRow(row, value) {
            var input = row.querySelector('[data-style-input]');
            var type = row.getAttribute('data-style-type');
            if (input) {
                if (value == null || value === '') {
                    input.value = type === 'color' ? '#888888' : '';
                } else {
                    input.value = value;
                }
            }
            setRowState(row, value != null && value !== '');
        }
        function setRowState(row, isSet) {
            row.classList.toggle('is-set', !!isSet);
            var clr = row.querySelector('[data-style-clear]');
            if (clr) { if (isSet) clr.removeAttribute('hidden'); else clr.setAttribute('hidden', ''); }
        }

        paint();
        syncAll();
    }

    function parseObj(s) { var o = safeJson(s); return (o && typeof o === 'object') ? o : {}; }
    function safeJson(s) { try { return JSON.parse(s); } catch (e) { return {}; } }
    function cloneObj(o) { var r = {}; Object.keys(o).forEach(function (k) { r[k] = o[k]; }); return r; }
    function normObj(o) { var r = {}; Object.keys(o || {}).forEach(function (k) { if (o[k] != null && o[k] !== '') r[k] = String(o[k]); }); return r; }
    function sameObj(a, b) {
        var ka = Object.keys(a), kb = Object.keys(b);
        if (ka.length !== kb.length) return false;
        for (var i = 0; i < ka.length; i++) { if (String(b[ka[i]]) !== String(a[ka[i]])) return false; }
        return true;
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
    if ('MutationObserver' in window) {
        new MutationObserver(init).observe(document.documentElement, { childList: true, subtree: true });
    }
})();
