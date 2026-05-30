/**
 * Admin light/dark theming. Head-loaded so the resolved theme is applied
 * before first paint (no flash). Preference is per-browser (localStorage):
 * 'auto' (follow system) | 'light' | 'dark'. The data-attribute on <html>
 * always carries the RESOLVED value (light|dark); the switch's active button
 * reflects the stored PREFERENCE (auto|light|dark) — they differ under 'auto'.
 */
(function () {
    'use strict';

    var KEY = 'lrobEtkTheme';
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function pref() {
        var v = null;
        try { v = window.localStorage.getItem(KEY); } catch (e) {}
        return (v === 'light' || v === 'dark') ? v : 'auto';
    }

    function resolve(p) {
        if (p === 'dark') return 'dark';
        if (p === 'light') return 'light';
        return (media && media.matches) ? 'dark' : 'light';
    }

    function apply() {
        document.documentElement.setAttribute('data-etk-theme', resolve(pref()));
    }

    // Pre-paint: run immediately (script is in <head>).
    apply();

    // Follow the OS only while preference is 'auto'.
    if (media) {
        var onChange = function () { if (pref() === 'auto') apply(); };
        if (media.addEventListener) media.addEventListener('change', onChange);
        else if (media.addListener) media.addListener(onChange);
    }

    var ORDER = ['auto', 'light', 'dark'];
    var GLYPH = { auto: '◐', light: '○', dark: '●' };

    function syncControls(p) {
        var btns = document.querySelectorAll('[data-etk-theme-cycle]');
        for (var i = 0; i < btns.length; i++) {
            var glyph = btns[i].querySelector('.lrob-etk-theme-switch-glyph');
            if (glyph) glyph.textContent = GLYPH[p] || GLYPH.auto;
            var title = btns[i].getAttribute('data-title-' + p);
            if (title) { btns[i].title = title; btns[i].setAttribute('aria-label', title); }
        }
    }

    function wire() {
        var btns = document.querySelectorAll('[data-etk-theme-cycle]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function () {
                var next = ORDER[(ORDER.indexOf(pref()) + 1) % ORDER.length];
                try { window.localStorage.setItem(KEY, next); } catch (err) {}
                apply();
                syncControls(next);
            });
        }
        syncControls(pref());
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
})();
