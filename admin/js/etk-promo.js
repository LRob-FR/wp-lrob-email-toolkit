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

        var bodyContent = document.getElementById('wpbody-content');

        Array.prototype.forEach.call(hosts, function (host) {
            // `in_admin_footer` injects the strip inside #wpfooter, which is
            // position:absolute in the WP admin layout (WP only reserves ~65px
            // of padding for its own small footer). Our taller strip therefore
            // overflows upward and paints over the bottom of the page content
            // (most visibly on card grids). Relocate the strip's .wrap into
            // #wpbody-content's normal flow so it reserves its own height and
            // the footer sits cleanly below it.
            var wrap = host.closest('.wrap') || host.parentElement;
            if (bodyContent && wrap && wrap.parentElement !== bodyContent) {
                bodyContent.appendChild(wrap);
            }

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

        // After the strip is out of #wpfooter, the footer holds only WP's
        // small default text. Shrink #wpbody-content's reserved bottom padding
        // to exactly that height so the relocated strip sits flush above the
        // footer instead of floating over a big empty gap.
        var wpfooter = document.getElementById('wpfooter');
        if (bodyContent && wpfooter) {
            bodyContent.style.paddingBottom = wpfooter.offsetHeight + 'px';
        }
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
