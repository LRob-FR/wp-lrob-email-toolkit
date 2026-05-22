/* LRob Email Toolkit — Newsletter: "Start a new subscribe form" picker.
 *
 * Mirror of contact-form-new-picker.js, scoped to the newsletter
 * subscribe-form CPT. Opens via the header button and the empty-state
 * button; selecting a card POSTs to the create-form AJAX endpoint and
 * reloads the page anchored on the new card.
 *
 * Config is injected via wp_localize_script as window.lrobEtkNlNewPicker
 * (ajaxUrl, action, nonce, pageUrl).
 */
(function () {
    var cfg = window.lrobEtkNlNewPicker || {};

    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    ready(function () {
        var picker = document.getElementById('lrob-etk-nl-new-picker');
        if (!picker) return;

        // Both triggers exist depending on render state — header button
        // always; empty-state button only when there are no forms yet.
        var openBtns = [
            document.getElementById('lrob-etk-nl-new-form-btn'),
            document.getElementById('lrob-etk-nl-new-form-btn-empty')
        ];

        function open() { picker.hidden = false; document.body.style.overflow = 'hidden'; }
        function close() { picker.hidden = true; document.body.style.overflow = ''; }

        function setCardsDisabled(disabled) {
            Array.prototype.forEach.call(
                picker.querySelectorAll('.lrob-etk-cf-picker-card'),
                function (c) { c.disabled = disabled; }
            );
        }

        openBtns.forEach(function (b) { if (b) b.addEventListener('click', open); });

        picker.addEventListener('click', function (e) {
            if (e.target === picker || e.target.closest('[data-close]')) {
                close();
                return;
            }
            var card = e.target.closest('.lrob-etk-cf-picker-card');
            if (!card) return;

            setCardsDisabled(true);
            var fd = new FormData();
            fd.append('action', cfg.action);
            fd.append('_nonce', cfg.nonce);
            fd.append('source', card.getAttribute('data-source') || 'blank');
            if (card.getAttribute('data-slug')) fd.append('slug', card.getAttribute('data-slug'));
            if (card.getAttribute('data-form-id')) fd.append('form_id', card.getAttribute('data-form-id'));

            fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                .then(function (resp) {
                    if (resp && resp.success && resp.data && resp.data.form_id) {
                        window.location.href = cfg.pageUrl + '#form-' + resp.data.form_id;
                        // Force reload so the new card actually renders.
                        window.location.reload();
                    } else {
                        setCardsDisabled(false);
                        alert((resp && resp.data && resp.data.message) || 'Could not create form.');
                    }
                })
                .catch(function () { setCardsDisabled(false); });
        });

        document.addEventListener('keydown', function (e) {
            if (!picker.hidden && e.key === 'Escape') close();
        });
    });
})();
