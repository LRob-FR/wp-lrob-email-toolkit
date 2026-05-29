/* Docs: docs/admin-ui.md */
(function (window, document) {
    'use strict';

    // --------------------------- Auto-init (select mode) ---------------------------

    function initSelectCombos() {
        var combos = document.querySelectorAll('.lrob-etk-combo[data-options]');
        Array.prototype.forEach.call(combos, setupSelectCombo);
    }

    function setupSelectCombo(combo) {
        if (combo.__etkBound) return;
        var options;
        try {
            options = JSON.parse(combo.getAttribute('data-options'));
        } catch (e) {
            return;
        }
        if (!Array.isArray(options)) return;

        var hidden = combo.querySelector('.lrob-etk-combo-value');
        if (!hidden) return;

        // Inherit sentinel → leave visible input empty so placeholder renders as a hint.
        var inheritValue = combo.getAttribute('data-inherit-value');
        if (inheritValue === null) inheritValue = '';

        attachCombobox(combo, {
            mode: 'select',
            // Re-read data-options on every open so live DOM changes (e.g. captcha toggle) reflect.
            populate: function () {
                try {
                    var live = JSON.parse(combo.getAttribute('data-options') || 'null');
                    return Array.isArray(live) ? live : options;
                } catch (e) { return options; }
            },
            getValue: function () { return hidden.value; },
            setValue: function (value, label) {
                hidden.value = value;
                var input = combo.querySelector('.lrob-etk-combo-input');
                if (input) {
                    input.value = (String(value) === String(inheritValue)) ? '' : label;
                }
                hidden.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        // Initial display sync — same inherit rule as setValue.
        var initial = options.find(function (o) { return String(o.value) === String(hidden.value); });
        var inputEl = combo.querySelector('.lrob-etk-combo-input');
        if (inputEl) inputEl.value = (initial && String(initial.value) !== String(inheritValue)) ? initial.label : '';
    }

    // --------------------------- Public manual init (both modes) ---------------------------

    // Attach combobox behavior. See docs/admin-ui.md for cfg shape.
    function attachCombobox(combo, cfg) {
        if (combo.__etkBound) return;
        combo.__etkBound = true;

        cfg = cfg || {};
        var mode = cfg.mode || 'free';

        var input  = combo.querySelector('.lrob-etk-combo-input');
        var toggle = combo.querySelector('.lrob-etk-combo-toggle');
        var menu   = combo.querySelector('.lrob-etk-combo-menu');
        if (!input || !toggle || !menu) return;

        var getValue = cfg.getValue || function () { return input.value; };
        var setValue = cfg.setValue || function (value /* , label */) {
            input.value = value;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        };

        function open() {
            if (!menu.hidden) return;
            var options = cfg.populate ? (cfg.populate(getValue()) || []) : [];
            if (options.length === 0) {
                return;
            }
            renderMenu(menu, options, getValue());
            menu.hidden = false;
            combo.classList.add('is-open');
        }
        function close() {
            if (menu.hidden) return;
            menu.hidden = true;
            combo.classList.remove('is-open');
        }
        function toggleOpen() { menu.hidden ? open() : close(); }

        function commit(value, label) {
            setValue(value, label || value);
            close();
            if (cfg.onSelect) cfg.onSelect(value);
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            toggleOpen();
            if (!menu.hidden) input.focus();
        });

        // Readonly input in select mode: click opens menu.
        if (mode === 'select') {
            input.addEventListener('click', function (e) {
                e.preventDefault();
                toggleOpen();
            });
        }

        input.addEventListener('keydown', function (e) {
            handleKey(e, combo, menu, getValue, function (val, lbl) { commit(val, lbl); }, open, close, cfg.populate);
        });

        menu.addEventListener('click', function (e) {
            var item = e.target.closest('[role="option"]');
            if (!item) return;
            var value = item.getAttribute('data-value') || '';
            var label = item.textContent || value;
            commit(value, label);
            input.focus();
        });
    }

    function handleKey(e, combo, menu, getValue, commit, open, close, populate) {
        if (menu.hidden && (e.key === 'ArrowDown' || (e.key === 'Enter' && combo.querySelector('.lrob-etk-combo-input').readOnly) || (e.key === ' ' && combo.querySelector('.lrob-etk-combo-input').readOnly))) {
            e.preventDefault();
            open();
            return;
        }
        if (menu.hidden) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            close();
            return;
        }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var options = populate ? (populate(getValue()) || []) : [];
            if (options.length === 0) return;
            var current = options.findIndex(function (o) { return String(o.value) === String(getValue()); });
            if (current === -1) current = 0;
            var step = e.key === 'ArrowDown' ? 1 : -1;
            var next = (current + step + options.length) % options.length;
            commit(options[next].value, options[next].label);
            open(); // commit closes; re-open so user can keep arrowing
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            close();
        }
    }

    function renderMenu(menu, options, currentValue) {
        var html = options.map(function (opt) {
            var selected = (String(opt.value) === String(currentValue));
            return '<li role="option"'
                + ' data-value="' + escapeAttr(String(opt.value)) + '"'
                + (selected ? ' class="is-selected" aria-selected="true"' : '')
                + '>' + escapeHtml(String(opt.label)) + '</li>';
        }).join('');
        menu.innerHTML = html;
    }

    function escapeHtml(s) {
        return s.replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function escapeAttr(s) { return s.replace(/"/g, '&quot;'); }

    document.addEventListener('click', function (e) {
        var open = document.querySelectorAll('.lrob-etk-combo.is-open');
        Array.prototype.forEach.call(open, function (combo) {
            if (!combo.contains(e.target)) {
                combo.classList.remove('is-open');
                var m = combo.querySelector('.lrob-etk-combo-menu');
                if (m) m.hidden = true;
            }
        });
    });

    window.lrobEtkControls = window.lrobEtkControls || {};
    window.lrobEtkControls.initCombos = initSelectCombos;
    window.lrobEtkControls.attachCombobox = attachCombobox;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSelectCombos);
    } else {
        initSelectCombos();
    }
})(window, document);
