/* LRob Email Toolkit — admin promo strip.
 *
 * Paints the bottom-of-page LRob sponsor strip(s) (.lrob-etk-promo) from the
 * localized message pool, starts on a random message, auto-rotates every ~9s
 * with a short fade, and pauses while hovered so the reader can click through.
 * Brand-shared with the other LRob plugins. */
(function () {
    'use strict';

    var CFG = window.lrobEtkPromo || {};
    var POOL = (CFG.messages) || [];
    var URL = CFG.authorUrl || 'https://www.lrob.fr';

    function boot() {
        var hosts = document.querySelectorAll('[data-role="lrob-etk-promo"]');
        if (!hosts.length || !POOL.length) return;

        Array.prototype.forEach.call(hosts, function (host) {
            host.innerHTML = '<span class="lrob-etk-promo-icon"></span><span class="lrob-etk-promo-body"></span>';
            var iconEl = host.querySelector('.lrob-etk-promo-icon');
            var bodyEl = host.querySelector('.lrob-etk-promo-body');

            var i = Math.floor(Math.random() * POOL.length);
            paint(i);

            var paused = false;
            host.addEventListener('mouseenter', function () { paused = true; });
            host.addEventListener('mouseleave', function () { paused = false; });

            setInterval(function () {
                if (paused) return;
                bodyEl.classList.add('is-fading');
                setTimeout(function () {
                    i = (i + 1) % POOL.length;
                    paint(i);
                    bodyEl.classList.remove('is-fading');
                }, 350);
            }, 9000);

            function paint(idx) {
                var p = POOL[idx] || {};
                iconEl.textContent = p.icon || '✨';
                bodyEl.innerHTML = esc(p.text || '') + ' '
                    + '<a href="' + esc(URL) + '" target="_blank" rel="noopener nofollow">'
                    + esc(p.link || '') + '</a>';
            }
        });
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
