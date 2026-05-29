/* LRob — Email Toolkit · Newsletter cards send pipeline
 * Docs: docs/newsletter-internals.md → "Admin JS overview"
 */
(function () {
    var CFG = window.lrobEtkNlSend || {};
    if (!CFG.ajaxUrl || !CFG.nonce || !CFG.actions) return;
    var ACTIONS = CFG.actions;
    var I18N = CFG.i18n || {};

    function post(action, fields) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('_nonce', CFG.nonce);
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json().catch(function () { return { success: false }; }); });
    }

    function fmt(s, args) {
        return s
            .replace('%1$d', args[0])
            .replace('%2$d', args[1])
            .replace('%d', args[0]);
    }

    function setStatusBadge(card, status) {
        var st = card.querySelector('[data-send-status]');
        if (st && status) {
            st.className = 'lrob-etk-nl-status lrob-etk-nl-status-' + status;
            st.textContent = status.charAt(0).toUpperCase() + status.slice(1);
            if (status === 'draft') {
                st.setAttribute('hidden', '');
            } else {
                st.removeAttribute('hidden');
            }
        }
        // Toggle action-button visibility / disabled state to match.
        var sending = status === 'sending';
        var paused = status === 'paused';
        var terminal = (status === 'sent' || status === 'failed' || status === 'aborted');
        var pauseBtn = card.querySelector('[data-send-pause]');
        var resumeBtn = card.querySelector('[data-send-resume]');
        var abortBtn = card.querySelector('[data-send-abort]');
        var sendNowBtn = card.querySelector('[data-send-now]');
        if (pauseBtn) pauseBtn[sending ? 'removeAttribute' : 'setAttribute']('hidden', '');
        if (resumeBtn) resumeBtn[paused ? 'removeAttribute' : 'setAttribute']('hidden', '');
        if (abortBtn) abortBtn[(sending || paused) ? 'removeAttribute' : 'setAttribute']('hidden', '');
        if (sendNowBtn) sendNowBtn.disabled = (terminal || sending || paused);
        var settingsFs = card.querySelector('.lrob-etk-nl-card-settings');
        if (settingsFs) settingsFs.disabled = (sending || paused || terminal);
        var titleInput = card.querySelector('.lrob-etk-title-input');
        if (titleInput) titleInput.disabled = (sending || paused || terminal);
    }

    function applyProgress(card, p) {
        if (!p) return;
        var sent = card.querySelector('[data-progress-sent]');
        var total = card.querySelector('[data-progress-total]');
        var failed = card.querySelector('[data-progress-failed]');
        var fill = card.querySelector('[data-progress-fill]');
        var box = card.querySelector('[data-send-progress]');
        if (sent) sent.textContent = p.sent || 0;
        if (total) total.textContent = p.total || 0;
        if (failed) failed.textContent = p.failed || 0;
        if (fill) {
            var pct = p.total > 0 ? Math.min(100, Math.round(((p.sent + p.failed) * 100) / p.total)) : 0;
            fill.style.width = pct + '%';
        }
        if (box) box.removeAttribute('hidden');
        if (p.status) setStatusBadge(card, p.status);
    }

    function loopTick(card) {
        if (card.__stopRequested) return;
        var newsletterId = card.getAttribute('data-newsletter-id');
        post(ACTIONS.tick, { newsletter_id: newsletterId }).then(function (resp) {
            if (!resp || !resp.success) {
                window.alert((resp && resp.data && resp.data.message) || I18N.tickFailed || 'Send failed.');
                return;
            }
            applyProgress(card, resp.data);
            if (!card.__stopRequested
                && (resp.data.remaining || 0) > 0
                && resp.data.status === 'sending'
            ) {
                setTimeout(function () { loopTick(card); }, 250);
            }
        });
    }

    // ---- Modal helpers ---------------------------------------------
    function openModal(modal) {
        if (!modal) return;
        modal.removeAttribute('hidden');
        document.body.classList.add('lrob-etk-modal-open');
    }
    function closeModal(modal) {
        if (!modal) return;
        modal.setAttribute('hidden', '');
        document.body.classList.remove('lrob-etk-modal-open');
    }
    function modalById(id) { return document.getElementById(id); }

    /**
     * Sensible default for an empty schedule input: tomorrow at 10:00
     * local. Saves the admin from spinning the minutes dial when they
     * just want "next morning". Format matches the datetime-local
     * input shape (YYYY-MM-DDTHH:MM).
     */
    function defaultScheduleValue() {
        var d = new Date();
        d.setDate(d.getDate() + 1);
        d.setHours(10, 0, 0, 0);
        function pad(n) { return n < 10 ? '0' + n : '' + n; }
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    /**
     * Promise-based confirmation modal — replacement for window.confirm.
     * Uses the shared `.lrob-etk-modal` primitive so styling stays
     * consistent with Delete / Preview / Recipients dialogs.
     *
     * @param {object} opts
     *   - title:        modal header text
     *   - body:         the question shown in the body
     *   - confirmLabel: text on the primary action button
     *   - danger:       (optional) true → red confirm button
     */
    function etkConfirm(opts) {
        var modal = modalById('lrob-etk-nl-modal-confirm');
        if (!modal) return Promise.resolve(false);
        var titleEl = modal.querySelector('[data-confirm-title]');
        var bodyEl  = modal.querySelector('[data-confirm-body]');
        var okBtn   = modal.querySelector('[data-confirm-ok]');
        if (titleEl) titleEl.textContent = opts.title || '';
        if (bodyEl)  bodyEl.textContent  = opts.body  || '';
        if (okBtn) {
            okBtn.textContent = opts.confirmLabel || 'OK';
            okBtn.classList.remove('lrob-etk-nl-modal-confirm-danger');
            if (opts.danger) okBtn.classList.add('lrob-etk-nl-modal-confirm-danger');
        }
        openModal(modal);
        return new Promise(function (resolve) {
            var resolved = false;
            function done(result) {
                if (resolved) return;
                resolved = true;
                if (okBtn) okBtn.removeEventListener('click', onOk);
                modal.removeEventListener('click', onCancel);
                document.removeEventListener('keydown', onEsc);
                closeModal(modal);
                resolve(result);
            }
            function onOk() { done(true); }
            function onCancel(e) {
                if (e.target === okBtn || (okBtn && okBtn.contains(e.target))) return;
                if (e.target.matches('[data-modal-close]') || e.target.closest('[data-modal-close]')) {
                    done(false);
                }
            }
            function onEsc(e) { if (e.key === 'Escape') done(false); }
            if (okBtn) okBtn.addEventListener('click', onOk);
            modal.addEventListener('click', onCancel);
            document.addEventListener('keydown', onEsc);
        });
    }

    // Generic modal-close clicks (backdrop, close button, footer cancel).
    document.addEventListener('click', function (e) {
        if (!e.target.matches('[data-modal-close]') && !e.target.closest('[data-modal-close]')) return;
        var modal = e.target.closest('.lrob-etk-modal');
        if (modal) closeModal(modal);
    });
    // Esc to close the topmost open modal.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var openOnes = document.querySelectorAll('.lrob-etk-modal:not([hidden])');
        if (openOnes.length) closeModal(openOnes[openOnes.length - 1]);
    });

    // Track which newsletter the test-send modal is bound to.
    var testModalNewsletterId = 0;

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-send-now], [data-send-pause], [data-send-resume], [data-send-abort], [data-send-retry-failed], [data-send-unschedule], [data-test-send], [data-card-preview], [data-card-recipients], [data-card-delete], [data-card-test]');
        if (!trigger) return;
        // [data-test-send] (Send-test button INSIDE the modal) uses
        // testModalNewsletterId, not a card ancestor.
        if (trigger.hasAttribute('data-test-send')) {
            var newsletterIdForTest = testModalNewsletterId;
            if (!newsletterIdForTest) return;
            var testModal = modalById('lrob-etk-nl-modal-test');
            if (!testModal) return;
            var targetRadio = testModal.querySelector('input[data-test-target]:checked');
            var target = targetRadio ? targetRadio.value : 'self';
            var emailInput = testModal.querySelector('input[data-test-email]');
            var email = emailInput ? emailInput.value : '';
            var resultEl = testModal.querySelector('[data-test-result]');
            trigger.disabled = true;
            if (resultEl) resultEl.textContent = '';
            post(ACTIONS.test, { newsletter_id: newsletterIdForTest, target: target, email: email }).then(function (resp) {
                trigger.disabled = false;
                if (resp && resp.success) {
                    if (resultEl) resultEl.textContent = fmt(I18N.testDone || '%1$d sent, %2$d failed.', [resp.data.sent, resp.data.failed]);
                } else {
                    if (resultEl) resultEl.textContent = (resp && resp.data && resp.data.message) || I18N.testFailed || 'Test send failed.';
                }
            });
            return;
        }
        var card = trigger.closest('[data-newsletter-id]');
        if (!card) return;
        var newsletterId = card.getAttribute('data-newsletter-id');

        // ---- Test modal trigger (button on card): open the shared
        // modal + remember which newsletter it's bound to.
        if (trigger.hasAttribute('data-card-test')) {
            var tModal = modalById('lrob-etk-nl-modal-test');
            if (!tModal) return;
            testModalNewsletterId = parseInt(newsletterId, 10) || 0;
            var existingResult = tModal.querySelector('[data-test-result]');
            if (existingResult) existingResult.textContent = '';
            openModal(tModal);
            return;
        }

        // ---- Preview modal: fetch rendered HTML, inject into iframe.
        // Replace the iframe element on every open — some browsers
        // don't reliably re-render srcdoc when it's reset to the same
        // (or any) value on a second open. A fresh element guarantees
        // a fresh load each time.
        if (trigger.hasAttribute('data-card-preview')) {
            var previewModal = modalById('lrob-etk-nl-modal-preview');
            if (!previewModal) return;
            var oldIframe = previewModal.querySelector('[data-preview-iframe]');
            var iframe = document.createElement('iframe');
            iframe.setAttribute('data-preview-iframe', '');
            iframe.setAttribute('sandbox', '');
            iframe.style.cssText = oldIframe ? oldIframe.style.cssText :
                'width:100%; min-height:60vh; border:1px solid var(--etk-line); border-radius:var(--etk-radius-sm); display:block;';
            if (oldIframe && oldIframe.parentNode) {
                oldIframe.parentNode.replaceChild(iframe, oldIframe);
            }
            openModal(previewModal);
            post(ACTIONS.preview, { newsletter_id: newsletterId }).then(function (resp) {
                if (resp && resp.success) {
                    iframe.srcdoc = resp.data.html || '';
                } else {
                    iframe.srcdoc = '<p style="padding:1rem;font-family:sans-serif;">' +
                        ((resp && resp.data && resp.data.message) || I18N.previewFailed || 'Preview failed.') + '</p>';
                }
            });
            return;
        }

        // ---- Recipients modal: opens with filter / search / pagination
        //      driven by the helpers in the "Recipients drawer" block
        //      further down. We just kick off the initial load here.
        if (trigger.hasAttribute('data-card-recipients')) {
            // Anchor trigger (not a button — anchors stay clickable
            // even inside a fieldset-disabled settings group, so the
            // list is reachable after the newsletter is sent / locked).
            e.preventDefault();
            openRecipientsModal(newsletterId);
            return;
        }

        // ---- Delete modal: populate title + confirm-link href +
        //      toggle the "trash" / "permanent" variant copy.
        if (trigger.hasAttribute('data-card-delete')) {
            var delModal = modalById('lrob-etk-nl-modal-delete');
            if (!delModal) return;
            var titleEl = delModal.querySelector('[data-delete-title]');
            var confirmLink = delModal.querySelector('[data-delete-confirm]');
            if (titleEl) titleEl.textContent = trigger.getAttribute('data-newsletter-title') || '';
            if (confirmLink) confirmLink.setAttribute('href', trigger.getAttribute('data-delete-url') || '#');
            var mode = trigger.getAttribute('data-delete-mode') === 'permanent' ? 'permanent' : 'trash';
            var variants = delModal.querySelectorAll('[data-delete-variant]');
            for (var vi = 0; vi < variants.length; vi++) {
                variants[vi].hidden = variants[vi].getAttribute('data-delete-variant') !== mode;
            }
            openModal(delModal);
            return;
        }

        if (trigger.hasAttribute('data-send-now')) {
            var isSchedule = (trigger.querySelector('[data-send-label]') || {}).textContent
                === (trigger.getAttribute('data-label-schedule') || '');
            // Bypass warning — if the card has the ignore-optouts box
            // ticked we surface it loud in the confirm modal. Caps the
            // body text with a red banner the admin must acknowledge
            // before sending; doesn't gate the action otherwise.
            var bypassCb = card.querySelector('[data-key="_lrob_etk_nl_ignore_optouts"]');
            var bypassOn = bypassCb && bypassCb.checked;
            var bodyText = isSchedule
                ? (trigger.getAttribute('data-confirm-schedule') || '')
                : (trigger.getAttribute('data-confirm-send') || '');
            if (bypassOn) {
                bodyText = (I18N.recipientsBypassWarn ||
                    '⚠ Opt-outs bypassed — this newsletter will reach recipients who explicitly opted out. Only proceed for legitimate operational / legal communications.')
                    + '\n\n' + bodyText;
            }
            etkConfirm({
                title: isSchedule
                    ? (trigger.getAttribute('data-label-schedule') || 'Schedule')
                    : (trigger.getAttribute('data-label-send') || 'Send now'),
                body: bodyText,
                danger: bypassOn,
                confirmLabel: isSchedule
                    ? (trigger.getAttribute('data-label-schedule') || 'Schedule')
                    : (trigger.getAttribute('data-label-send') || 'Send now')
            }).then(function (ok) {
                if (!ok) return;
                if (isSchedule) {
                    // Scheduled path: server-side commit. Only here does
                    // the companion flip draft → scheduled (the auto-save
                    // of the datetime field used to do this silently
                    // which made the button click look like a no-op).
                    // SendCron picks it up at scheduled_at and runs
                    // materializer + tick.
                    trigger.disabled = true;
                    post(ACTIONS.commitSchedule, { newsletter_id: newsletterId }).then(function (resp) {
                        trigger.disabled = false;
                        if (resp && resp.success) {
                            setStatusBadge(card, resp.data.status || 'scheduled');
                            // Reload to refresh the status-msg + button
                            // states — easier than re-rendering inline.
                            window.location.reload();
                        } else {
                            window.alert((resp && resp.data && resp.data.message) || I18N.tickFailed);
                        }
                    });
                    return;
                }
                // Immediate-send path.
                card.__stopRequested = false;
                trigger.disabled = true;
                loopTick(card);
            });
            return;
        }

        if (trigger.hasAttribute('data-send-pause')) {
            trigger.disabled = true;
            card.__stopRequested = true;
            post(ACTIONS.pause, { newsletter_id: newsletterId }).then(function (resp) {
                trigger.disabled = false;
                if (resp && resp.success) setStatusBadge(card, resp.data.status || 'paused');
                else window.alert((resp && resp.data && resp.data.message) || I18N.tickFailed);
            });
            return;
        }

        if (trigger.hasAttribute('data-send-resume')) {
            trigger.disabled = true;
            card.__stopRequested = false;
            post(ACTIONS.resume, { newsletter_id: newsletterId }).then(function (resp) {
                trigger.disabled = false;
                if (resp && resp.success) {
                    setStatusBadge(card, resp.data.status || 'sending');
                    loopTick(card);
                } else {
                    window.alert((resp && resp.data && resp.data.message) || I18N.tickFailed);
                }
            });
            return;
        }

        if (trigger.hasAttribute('data-send-abort')) {
            etkConfirm({
                title: I18N.abortTitle || 'Abort send',
                body: trigger.getAttribute('data-confirm') || '',
                confirmLabel: I18N.abortConfirm || 'Abort',
                danger: true
            }).then(function (ok) {
                if (!ok) return;
                trigger.disabled = true;
                card.__stopRequested = true;
                post(ACTIONS.abort, { newsletter_id: newsletterId }).then(function (resp) {
                    trigger.disabled = false;
                    if (resp && resp.success) setStatusBadge(card, resp.data.status || 'aborted');
                    else window.alert((resp && resp.data && resp.data.message) || I18N.tickFailed);
                });
            });
            return;
        }

        if (trigger.hasAttribute('data-send-unschedule')) {
            etkConfirm({
                title: I18N.unscheduleTitle || 'Unschedule send',
                body: I18N.unscheduleConfirm || '',
                confirmLabel: I18N.unscheduleAction || 'Unschedule'
            }).then(function (ok) {
                if (!ok) return;
                trigger.disabled = true;
                post(ACTIONS.uncommitSchedule, { newsletter_id: newsletterId }).then(function (resp) {
                    trigger.disabled = false;
                    if (resp && resp.success) {
                        // Reload so the card re-renders with the new
                        // status badge + button states.
                        window.location.reload();
                    } else {
                        window.alert((resp && resp.data && resp.data.message) || I18N.tickFailed);
                    }
                });
            });
            return;
        }

        if (trigger.hasAttribute('data-send-retry-failed')) {
            var failedCount = parseInt(trigger.getAttribute('data-failed-count') || '0', 10) || 0;
            etkConfirm({
                title: I18N.retryFailedTitle || 'Retry failed recipients',
                body: fmt(I18N.retryFailedConfirm || 'Re-queue %d failed recipient(s)?', [failedCount]),
                confirmLabel: I18N.retryFailedAction || 'Retry'
            }).then(function (ok) {
                if (!ok) return;
                trigger.disabled = true;
                post(ACTIONS.retryFailed, { newsletter_id: newsletterId }).then(function (resp) {
                    trigger.disabled = false;
                    if (resp && resp.success) {
                        // Reload the page so the card re-renders with
                        // updated counters + status. The send pipeline
                        // will pick up the re-queued rows on next tick.
                        window.location.reload();
                    } else {
                        window.alert((resp && resp.data && resp.data.message) || I18N.tickFailed);
                    }
                });
            });
            return;
        }

        if (trigger.hasAttribute('data-test-send')) {
            var target = (card.querySelector('input[data-test-target]:checked') || {}).value || 'self';
            var email = (card.querySelector('input[data-test-email]') || {}).value || '';
            var resultEl = card.querySelector('[data-test-result]');
            trigger.disabled = true;
            if (resultEl) resultEl.textContent = '';
            post(ACTIONS.test, { newsletter_id: newsletterId, target: target, email: email }).then(function (resp) {
                trigger.disabled = false;
                if (resp && resp.success) {
                    if (resultEl) resultEl.textContent = fmt(I18N.testDone || '%1$d sent, %2$d failed.', [resp.data.sent, resp.data.failed]);
                } else {
                    if (resultEl) resultEl.textContent = (resp && resp.data && resp.data.message) || I18N.testFailed || 'Test send failed.';
                }
            });
            return;
        }
    });

    // Toggle the ad-hoc email input visibility per card on target
    // radio change. Same per-card delegation as the action buttons.
    document.addEventListener('change', function (e) {
        // Schedule toggle: ticking reveals the datetime input + seeds
        // a sensible default (tomorrow at 10am local) so the admin
        // isn't stuck at "now" and doesn't have to roll the minutes
        // dial. Unticking hides it AND clears the saved value (so
        // the user doesn't have to manually empty the field).
        if (e.target.matches('[data-schedule-toggle]')) {
            var block = e.target.closest('[data-schedule-block]');
            if (!block) return;
            var inputWrap = block.querySelector('[data-schedule-input]');
            var dateInput = block.querySelector('input[type="datetime-local"]');
            var card = e.target.closest('[data-newsletter-id]');
            if (e.target.checked) {
                if (inputWrap) inputWrap.removeAttribute('hidden');
                if (dateInput) {
                    if (!dateInput.value) {
                        dateInput.value = defaultScheduleValue();
                        // Dispatch change so newsletter-admin.js saves
                        // the seeded value — the status badge flips to
                        // scheduled + the send button relabels.
                        dateInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    dateInput.focus();
                }
            } else {
                if (inputWrap) inputWrap.setAttribute('hidden', '');
                if (dateInput && dateInput.value !== '') {
                    dateInput.value = '';
                    dateInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
                // Untick → no schedule → revert button label immediately
                // (the saved event below will also fire once the empty
                // value persists, but this snaps the UI without waiting).
                if (card) updateSendButtonForSchedule(card, '');
            }
            return;
        }
        // Test-modal radio: toggle ad-hoc email input visibility.
        if (e.target.matches('input[data-test-target]')) {
            var modal = e.target.closest('.lrob-etk-modal') || document;
            var adhoc = modal.querySelector('[data-test-adhoc-input]');
            if (adhoc) {
                var show = (e.target.value === 'adhoc');
                adhoc[show ? 'removeAttribute' : 'setAttribute']('hidden', '');
            }
            return;
        }
    });

    /**
     * Swap the Send-now / Schedule button label + icon + confirm text
     * based on whether a schedule is set. `scheduledValue` is the
     * datetime-local string from the card's schedule input.
     */
    function updateSendButtonForSchedule(card, scheduledValue) {
        if (!card) return;
        var btn = card.querySelector('[data-send-now]');
        if (!btn) return;
        var hasSchedule = scheduledValue && scheduledValue.length > 0;
        var labelEl = btn.querySelector('[data-send-label]');
        var iconEl = btn.querySelector('[data-send-icon]');
        if (labelEl) {
            labelEl.textContent = hasSchedule
                ? (btn.getAttribute('data-label-schedule') || 'Schedule')
                : (btn.getAttribute('data-label-send') || 'Send now');
        }
        if (iconEl) {
            iconEl.classList.remove('dashicons-calendar-alt', 'dashicons-email-alt');
            iconEl.classList.add(hasSchedule ? 'dashicons-calendar-alt' : 'dashicons-email-alt');
        }
        var confirmAttr = hasSchedule
            ? btn.getAttribute('data-confirm-schedule')
            : btn.getAttribute('data-confirm-send');
        if (confirmAttr) btn.setAttribute('data-confirm', confirmAttr);
    }

    /**
     * Update the relative-time status message under the action row
     * when a schedule changes. Computes human-readable time-from-now
     * in JS (good-enough — server-side formatting on page-load handles
     * the canonical version, JS handles in-flight changes).
     */
    function updateStatusMessageForSchedule(card, scheduledValue) {
        if (!card) return;
        var msg = card.querySelector('[data-status-msg]');
        if (!msg) return;
        if (!scheduledValue || scheduledValue.length === 0) {
            // Cleared: hide the schedule message unless the card is in
            // a terminal state (in which case the page reload will
            // re-render the "Sent X ago" message — leave whatever the
            // server rendered alone).
            if (msg.classList.contains('is-info') || msg.classList.contains('is-warn')) {
                msg.setAttribute('hidden', '');
                msg.textContent = '';
            }
            return;
        }
        var when = new Date(scheduledValue);
        if (isNaN(when.getTime())) return;
        var now = new Date();
        var deltaMin = Math.round((when.getTime() - now.getTime()) / 60000);
        var absolute = when.toLocaleString();
        var status = (card.getAttribute('data-status') || 'draft');

        // Draft + date set: the commit hasn't happened yet. Mirror the
        // server-rendered "Schedule set for X — click Schedule to commit."
        // so changing the date in-flight stays consistent with the page-
        // load render.
        if (status === 'draft') {
            var draftTpl = I18N.scheduleSetTemplate || 'Schedule set for %s — click Schedule to commit.';
            msg.className = 'lrob-etk-nl-card-status-msg is-info';
            msg.textContent = draftTpl.replace('%s', absolute);
            msg.removeAttribute('hidden');
            return;
        }

        // Scheduled (committed): live relative-time render. Past dates
        // get the "overdue — cron tick" message; future dates show the
        // countdown.
        if (deltaMin <= 0) {
            var overdueTpl = I18N.scheduledOverdueTemplate || 'Scheduled for %s (overdue — will run on the next cron tick).';
            msg.className = 'lrob-etk-nl-card-status-msg is-warn';
            msg.textContent = overdueTpl.replace('%s', absolute);
            msg.removeAttribute('hidden');
            return;
        }
        var relative;
        if (deltaMin < 60) {
            relative = deltaMin + ' ' + (deltaMin === 1 ? (I18N.minuteSingular || 'minute') : (I18N.minutes || 'minutes'));
        } else if (deltaMin < 60 * 24) {
            var h = Math.round(deltaMin / 60);
            relative = h + ' ' + (h === 1 ? (I18N.hourSingular || 'hour') : (I18N.hours || 'hours'));
        } else {
            var d = Math.round(deltaMin / (60 * 24));
            relative = d + ' ' + (d === 1 ? (I18N.daySingular || 'day') : (I18N.days || 'days'));
        }
        msg.className = 'lrob-etk-nl-card-status-msg is-info';
        var template = I18N.scheduledTemplate || 'Scheduled to send in %1$s — %2$s';
        msg.textContent = template.replace('%1$s', relative).replace('%2$s', absolute);
        msg.removeAttribute('hidden');
    }

    /**
     * Refresh the recipients count + send-button state whenever a
     * setting that affects either gets persisted.
     */
    var AUDIENCE_KEYS = ['target_kind', 'target_list_id', 'target_list_ids', 'target_audience'];
    document.addEventListener('lrob-etk-nl-saved', function (e) {
        if (!e.detail || !e.detail.newsletterId) return;
        var key = e.detail.key || '';
        var card = document.querySelector('[data-newsletter-id="' + e.detail.newsletterId + '"]');
        if (!card) return;
        // Audience change → refresh recipient count.
        if (AUDIENCE_KEYS.indexOf(key) !== -1) {
            refreshRecipientCount(card);
        }
        // Schedule change → swap button label + status message.
        if (key === '_lrob_etk_nl_scheduled_at') {
            var dateInput = card.querySelector('input[type="datetime-local"][data-key="_lrob_etk_nl_scheduled_at"]');
            var val = dateInput ? dateInput.value : '';
            updateSendButtonForSchedule(card, val);
            updateStatusMessageForSchedule(card, val);
        }
    });

    /**
     * Lazy-load the recipient count for a card. Updates the inline
     * count next to the "Show list" button. Errors render an em-dash.
     */
    function refreshRecipientCount(card) {
        if (!card) return;
        var countEl = card.querySelector('[data-recipients-count]');
        if (!countEl) return;
        var id = card.getAttribute('data-newsletter-id');
        if (!id) return;
        var optoutEl = card.querySelector('[data-recipients-optout]');
        var bypassEl = card.querySelector('[data-recipients-bypass]');
        post(ACTIONS.recipientsPreview, { newsletter_id: id }).then(function (resp) {
            if (!resp || !resp.success) {
                countEl.textContent = '—';
                if (optoutEl) optoutEl.hidden = true;
                if (bypassEl) bypassEl.hidden = true;
                return;
            }
            var d = resp.data;
            countEl.textContent = d.total || 0;
            if (optoutEl) {
                if (d.opted_out > 0 && !d.ignore_optouts) {
                    optoutEl.textContent = '· −' + d.opted_out + ' ' + (I18N.recipientsOptedOut || 'opted out');
                    optoutEl.hidden = false;
                } else {
                    optoutEl.hidden = true;
                }
            }
            if (bypassEl) bypassEl.hidden = !d.ignore_optouts;
        });
    }

    // Initial card sync on page load: refresh recipient count
    // (one cheap AJAX per card).
    function syncCardInitial(card) {
        refreshRecipientCount(card);
    }
    document.querySelectorAll('[data-newsletter-id]').forEach(syncCardInitial);

    /* ---------------------------------------------------------------------
     * Auto-refresh: clock-tick + server-poll.
     *
     * Two independent loops, both paused when the tab is hidden:
     *
     *   1. clock-tick (10s) — pure client-side. Every <span data-relative-to>
     *      on the page gets its text recomputed from now → ts. No server hit.
     *
     *   2. poll (20s, only when "interesting" cards exist) — hits the
     *      ACTION_CARD_STATES endpoint to refresh card progress + cron
     *      health. Updates the progress bar in place during sending;
     *      reloads the whole page when a status transitions (so the
     *      server re-renders all the per-status UI).
     *
     * Multi-tab dedup: localStorage lock + storage event. Even with N
     * tabs of the same admin view open, the server gets ~1 request per
     * 15s shared across them — other tabs read the cached response via
     * the `storage` event listener.
     * ------------------------------------------------------------------- */
    // Clock-tick rate adapts to whatever future deadline is closest:
    // counting down to a send 30 seconds away wants second-level
    // granularity; counting down to a send 3 days away is fine at
    // hourly resolution. Past-tense displays ("X ago") follow whatever
    // rate the future spans set — no point burning CPU at 1Hz on a
    // "Sent 2 hours ago" line.
    var CLOCK_FAST_MS    = 1000;     // any future deadline within 1h
    var CLOCK_MED_MS     = 60000;    // any future deadline 1–2h away
    var CLOCK_SLOW_MS    = 3600000;  // farther than 2h, or no future deadlines
    // Single fixed poll interval. Runs only when there's something to
    // watch (sending / paused / scheduled). Stops the moment everything
    // is terminal — after a reload the page re-renders with no
    // "interesting" cards and the loop just doesn't start.
    var POLL_INTERVAL_MS = 10000;
    var POLL_MIN_GAP_MS = 8000;     // multi-tab dedup floor
    var LS_LOCK = 'lrob-etk-nl-poll-lock';
    var LS_DATA = 'lrob-etk-nl-poll-data';
    var ACTIVE_STATUSES = ['sending', 'paused', 'scheduled'];
    var clockTimer = null;
    var pollTimer = null;

    function formatRelative(diffSec) {
        if (diffSec < 60) {
            return diffSec + ' ' + (diffSec === 1 ? (I18N.secondSingular || 'second') : (I18N.seconds || 'seconds'));
        }
        if (diffSec < 3600) {
            var m = Math.round(diffSec / 60);
            return m + ' ' + (m === 1 ? (I18N.minuteSingular || 'minute') : (I18N.minutes || 'minutes'));
        }
        if (diffSec < 86400) {
            var h = Math.round(diffSec / 3600);
            return h + ' ' + (h === 1 ? (I18N.hourSingular || 'hour') : (I18N.hours || 'hours'));
        }
        var d = Math.round(diffSec / 86400);
        return d + ' ' + (d === 1 ? (I18N.daySingular || 'day') : (I18N.days || 'days'));
    }

    /**
     * Recompute the text of every [data-relative-to] span and decide the
     * next tick interval. Returns the delay-in-ms for the next call.
     * Also detects zero-crossings (a future timestamp that just turned
     * past) and triggers a server poll so the server can re-render the
     * card with the post-deadline state cleanly.
     */
    function tickRelatives() {
        var now = Math.floor(Date.now() / 1000);
        var minFutureDiff = Infinity;
        var anyJustCrossed = false;
        var spans = document.querySelectorAll('[data-relative-to]');
        for (var i = 0; i < spans.length; i++) {
            var ts = parseInt(spans[i].getAttribute('data-relative-to') || '0', 10);
            if (ts <= 0) continue;
            var diff = ts - now;
            spans[i].textContent = formatRelative(Math.abs(diff));
            if (diff > 0 && diff < minFutureDiff) {
                minFutureDiff = diff;
            }
            // Zero-crossing detection: previous tick saw the span in the
            // future; this tick sees it past. Trigger a fresh server
            // poll so the card UI catches up to whatever state SendCron
            // moved it into.
            var lastDiff = parseFloat(spans[i].getAttribute('data-last-diff') || 'NaN');
            if (!isNaN(lastDiff) && lastDiff > 0 && diff <= 0) {
                anyJustCrossed = true;
            }
            spans[i].setAttribute('data-last-diff', String(diff));
        }
        if (anyJustCrossed) {
            // Bypass the multi-tab lock so the poll actually hits the
            // server right now — the deadline matters.
            try { localStorage.removeItem(LS_LOCK); } catch (e) {}
            pollIfDue();
        }
        if (minFutureDiff < 3600) return CLOCK_FAST_MS;
        if (minFutureDiff < 7200) return CLOCK_MED_MS;
        return CLOCK_SLOW_MS;
    }

    function interestingCardIds() {
        var ids = [];
        var cards = document.querySelectorAll('[data-newsletter-id]');
        for (var i = 0; i < cards.length; i++) {
            var status = cards[i].getAttribute('data-status') || '';
            if (ACTIVE_STATUSES.indexOf(status) !== -1) {
                ids.push(cards[i].getAttribute('data-newsletter-id'));
            }
        }
        return ids;
    }

    function postCardStates(ids) {
        var fd = new FormData();
        fd.append('action', ACTIONS.cardStates);
        fd.append('_nonce', CFG.nonce);
        ids.forEach(function (id) { fd.append('ids[]', id); });
        return fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json().catch(function () { return { success: false }; }); });
    }

    function applyState(payload) {
        if (!payload) return;
        var newsletters = payload.newsletters || {};
        var transitioned = false;
        Object.keys(newsletters).forEach(function (id) {
            var s = newsletters[id];
            var card = document.querySelector('[data-newsletter-id="' + id + '"]');
            if (!card) return;
            var oldStatus = card.getAttribute('data-status') || '';
            if (oldStatus !== s.status) {
                // Server state transitioned (scheduled → sending, sending → sent,
                // breaker tripped, etc). Reload so the server re-renders the
                // status badge, status_msg, action-button row, and any banner.
                transitioned = true;
                return;
            }
            // Same status — update progress bar + counters in place.
            var fill = card.querySelector('[data-progress-fill]');
            if (fill && s.total > 0) {
                var pct = Math.min(100, Math.round(((s.sent + s.failed) * 100) / s.total));
                fill.style.width = pct + '%';
            }
            var sentEl = card.querySelector('[data-progress-sent]');
            if (sentEl) sentEl.textContent = s.sent;
            var totalEl = card.querySelector('[data-progress-total]');
            if (totalEl) totalEl.textContent = s.total;
            var failedEl = card.querySelector('[data-progress-failed]');
            if (failedEl) failedEl.textContent = s.failed;
        });
        if (transitioned) {
            window.location.reload();
        }
    }

    function pollIfDue(force) {
        var ids = interestingCardIds();
        if (ids.length === 0 && !force) {
            return;
        }
        var now = Date.now();
        var lastPoll = parseInt(localStorage.getItem(LS_LOCK) || '0', 10);
        if (now - lastPoll < POLL_MIN_GAP_MS) {
            // Another tab polled recently. Use the cached response if
            // fresh enough. Don't make a server hit.
            var cached = localStorage.getItem(LS_DATA);
            if (cached) {
                try {
                    var parsed = JSON.parse(cached);
                    if (parsed.timestamp && (now - parsed.timestamp) < POLL_MIN_GAP_MS * 3) {
                        applyState(parsed);
                    }
                } catch (e) {}
            }
            return;
        }
        try { localStorage.setItem(LS_LOCK, String(now)); } catch (e) {}
        postCardStates(ids).then(function (resp) {
            if (!resp || !resp.success || !resp.data) return;
            var payload = {
                timestamp: Date.now(),
                newsletters: resp.data.newsletters || {},
                cron: resp.data.cron || null
            };
            try { localStorage.setItem(LS_DATA, JSON.stringify(payload)); } catch (e) {}
            applyState(payload);
        });
    }

    /**
     * Self-rescheduling poll loop. Stops the moment there are no
     * "interesting" (sending / paused / scheduled) cards on the page —
     * the page reload that fires on terminal transitions naturally
     * lands on a state where the loop just doesn't re-arm.
     */
    function pollLoop() {
        if (document.hidden) { pollTimer = null; return; }
        if (interestingCardIds().length === 0) { pollTimer = null; return; }
        pollIfDue();
        pollTimer = setTimeout(pollLoop, POLL_INTERVAL_MS);
    }

    function startPolling() {
        if (pollTimer || document.hidden) return;
        if (interestingCardIds().length === 0) return;
        pollLoop();
    }

    function stopPolling() {
        if (pollTimer) clearTimeout(pollTimer);
        pollTimer = null;
    }

    /**
     * Self-rescheduling clock loop. Each tick decides the next interval
     * based on how close the most-imminent future timestamp is, so a
     * 30-day-away schedule doesn't burn 1Hz updates and a 30-second-away
     * one doesn't get a stale "in 1 minute" display.
     */
    function clockTickLoop() {
        var nextDelay = tickRelatives();
        if (document.hidden) {
            clockTimer = null;
            return;
        }
        clockTimer = setTimeout(clockTickLoop, nextDelay);
    }

    function startClockTick() {
        if (clockTimer || document.hidden) return;
        clockTickLoop();
    }

    function stopClockTick() {
        if (clockTimer) clearTimeout(clockTimer);
        clockTimer = null;
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopPolling();
            stopClockTick();
        } else {
            tickRelatives();
            startClockTick();
            startPolling();
        }
    });

    // Sister tabs that polled get pushed to us via the `storage` event.
    // We update from their cached response without making our own hit.
    window.addEventListener('storage', function (e) {
        if (e.key !== LS_DATA || !e.newValue) return;
        try { applyState(JSON.parse(e.newValue)); } catch (err) {}
    });

    // Empty-trash button on the tab nav: confirm-then-navigate. The
    // confirm body + URL come from data-attrs PHP-rendered on the
    // button (count-aware copy, signed nonce URL).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-empty-trash]');
        if (!btn) return;
        e.preventDefault();
        var url = btn.getAttribute('data-empty-trash-url') || '';
        var body = btn.getAttribute('data-empty-trash-confirm') || '';
        if (!url) return;
        etkConfirm({
            title: btn.textContent.trim(),
            body: body,
            confirmLabel: btn.textContent.trim(),
            danger: true,
        }).then(function (ok) {
            if (ok) window.location.href = url;
        });
    });

    // -----------------------------------------------------------------
    // Recipients drawer
    // -----------------------------------------------------------------
    // Per-newsletter recipient list with status filter chips, email/
    // name substring search, and offset/limit pagination. State is
    // kept module-local (single drawer open at a time); each load
    // re-fetches with the current state + re-renders the body.

    var recipientsState = {
        newsletterId: 0,
        filter: '',      // '' | 'pending' | 'sent' | 'failed' | 'skipped'
        search: '',
        offset: 0,
        limit: 50,
        searchDebounce: null,
        loadSeq: 0,      // increments per request; lets us drop stale responses
    };

    var FILTER_KEYS = ['', 'pending', 'sent', 'failed', 'skipped'];

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function filterLabel(key) {
        if (key === '')        return I18N.recipientsFilterAll      || 'All';
        if (key === 'pending') return I18N.recipientsFilterPending  || 'Pending';
        if (key === 'sent')    return I18N.recipientsFilterSent     || 'Sent';
        if (key === 'failed')  return I18N.recipientsFilterFailed   || 'Failed';
        if (key === 'skipped') return I18N.recipientsFilterSkipped  || 'Skipped';
        return key;
    }

    function statusLabel(s) {
        if (s === 'pending') return I18N.recipientsFilterPending || 'pending';
        if (s === 'sent')    return I18N.recipientsFilterSent    || 'sent';
        if (s === 'failed')  return I18N.recipientsFilterFailed  || 'failed';
        if (s === 'skipped') return I18N.recipientsFilterSkipped || 'skipped';
        return s;
    }

    function openRecipientsModal(newsletterId) {
        var modal = modalById('lrob-etk-nl-modal-recipients');
        if (!modal) return;
        recipientsState.newsletterId = newsletterId;
        recipientsState.filter = '';
        recipientsState.search = '';
        recipientsState.offset = 0;
        var searchInput = modal.querySelector('[data-recipients-search]');
        if (searchInput) searchInput.value = '';
        var controls = modal.querySelector('[data-recipients-controls]');
        if (controls) controls.hidden = true;
        var body = modal.querySelector('[data-recipients-body]');
        if (body) body.innerHTML = '<p class="lrob-etk-nl-recipients-loading">' +
            escHtml(I18N.recipientsLoading || 'Computing…') + '</p>';
        openModal(modal);
        loadRecipients();
    }

    function loadRecipients() {
        var modal = modalById('lrob-etk-nl-modal-recipients');
        if (!modal) return;
        var body = modal.querySelector('[data-recipients-body]');
        if (!body) return;
        body.classList.add('is-loading');
        var seq = ++recipientsState.loadSeq;
        post(ACTIONS.recipientsPreview, {
            newsletter_id: recipientsState.newsletterId,
            offset: recipientsState.offset,
            status_filter: recipientsState.filter,
            search: recipientsState.search
        }).then(function (resp) {
            // Drop stale responses (debounced search emits faster than
            // the AJAX cycle on slow connections).
            if (seq !== recipientsState.loadSeq) return;
            body.classList.remove('is-loading');
            if (!resp || !resp.success) {
                body.innerHTML = '<p>' + escHtml(
                    (resp && resp.data && resp.data.message) || I18N.previewFailed || 'Failed.'
                ) + '</p>';
                return;
            }
            renderRecipients(modal, resp.data);
        });
    }

    function renderRecipients(modal, d) {
        var controls = modal.querySelector('[data-recipients-controls]');
        var body     = modal.querySelector('[data-recipients-body]');
        var isSnapshot = d.mode === 'snapshot';
        if (controls) controls.hidden = !isSnapshot;
        if (isSnapshot) {
            renderSnapshot(modal, d);
        } else {
            renderPreview(body, d);
        }
    }

    function renderSnapshot(modal, d) {
        // Filter chips — re-rendered on every load so the counts stay
        // in sync with the server's current by_status map.
        var filtersHost = modal.querySelector('[data-recipients-filters]');
        if (filtersHost) {
            var total = d.total || 0;
            var fhtml = '';
            FILTER_KEYS.forEach(function (key) {
                var count = key === '' ? total : ((d.by_status && d.by_status[key]) || 0);
                var active = (recipientsState.filter === key);
                fhtml += '<button type="button" role="tab" ' +
                        'class="lrob-etk-nl-recipients-filter' + (active ? ' is-active' : '') + '" ' +
                        'data-recipients-filter="' + escHtml(key) + '" ' +
                        'aria-selected="' + (active ? 'true' : 'false') + '">' +
                    '<span class="lrob-etk-nl-recipients-filter-label">' + escHtml(filterLabel(key)) + '</span> ' +
                    '<span class="lrob-etk-nl-recipients-filter-count">' + count + '</span>' +
                    '</button>';
            });
            filtersHost.innerHTML = fhtml;
        }

        // Body — counts header + table + pagination.
        var body = modal.querySelector('[data-recipients-body]');
        if (!body) return;
        var filteredTotal = d.filtered_total != null ? d.filtered_total : (d.total || 0);
        var offset = d.offset || 0;
        var limit  = d.limit  || 50;
        var rows   = d.sample || [];
        var rangeFrom = rows.length === 0 ? 0 : (offset + 1);
        var rangeTo   = offset + rows.length;

        var html = '';
        html += '<div class="lrob-etk-nl-recipients-summary">' +
            '<strong>' + (d.total || 0) + '</strong> ' + escHtml(I18N.recipientsTotal || 'recipients') +
            ' <span class="lrob-etk-nl-recipients-summary-note">— ' +
            escHtml(I18N.snapshotNote || 'frozen at send time') +
            '</span></div>';

        if (filteredTotal === 0) {
            html += '<p class="lrob-etk-nl-recipients-empty">' +
                escHtml(I18N.recipientsNoneMatch || 'No recipients match this filter.') +
                '</p>';
            body.innerHTML = html;
            return;
        }

        html += '<table class="lrob-etk-nl-recipients-table"><thead><tr>' +
            '<th>' + escHtml(I18N.recipientsColKind   || 'Kind')   + '</th>' +
            '<th>' + escHtml(I18N.recipientsColEmail  || 'Email')  + '</th>' +
            '<th>' + escHtml(I18N.recipientsColStatus || 'Status') + '</th>' +
            '<th>' + escHtml(I18N.recipientsColSentAt || 'Sent at') + '</th>' +
            '<th aria-label="' + escHtml(I18N.recipientsColLogs || 'Logs') + '"></th>' +
            '</tr></thead><tbody>';

        rows.forEach(function (r) {
            var status = r.status || '';
            var failureCode = r.failure_code || '';
            var sentAt = formatSentAt(r.sent_at);
            var logLink = '';
            if (r.log_url) {
                logLink = '<a href="' + escHtml(r.log_url) + '" class="lrob-etk-nl-recipients-log-link">' +
                    escHtml(I18N.viewInLogs || 'View in Logs →') + '</a>';
            }
            html += '<tr>' +
                '<td><span class="lrob-etk-nl-recipients-kind">' + escHtml(r.kind || '') + '</span></td>' +
                '<td class="lrob-etk-nl-recipients-email">' +
                    '<span class="lrob-etk-nl-recipients-email-value">' + escHtml(r.email || '') + '</span>' +
                    (r.name ? '<span class="lrob-etk-nl-recipients-name"> ' + escHtml(r.name) + '</span>' : '') +
                '</td>' +
                '<td><span class="lrob-etk-nl-status lrob-etk-nl-status-' + escHtml(status) + '"' +
                    (failureCode ? ' title="' + escHtml(failureCode) + '"' : '') + '>' +
                    escHtml(statusLabel(status)) +
                    (failureCode ? ' <span class="lrob-etk-nl-recipients-failcode">(' + escHtml(failureCode) + ')</span>' : '') +
                '</span></td>' +
                '<td class="lrob-etk-nl-recipients-sent-at">' + escHtml(sentAt) + '</td>' +
                '<td class="lrob-etk-nl-recipients-logs">' + logLink + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';

        // Pagination footer — only render the controls when there's
        // more than one page worth of data; otherwise just show the
        // range readout.
        var hasMore  = (offset + rows.length) < filteredTotal;
        var hasPrev  = offset > 0;
        var rangeLabel = (I18N.recipientsRange || 'Showing %1$s–%2$s of %3$s')
            .replace('%1$s', rangeFrom).replace('%2$s', rangeTo).replace('%3$s', filteredTotal);
        html += '<footer class="lrob-etk-nl-recipients-pagination">' +
            '<span class="lrob-etk-nl-recipients-range">' + escHtml(rangeLabel) + '</span>' +
            (hasPrev || hasMore
                ? '<span class="lrob-etk-nl-recipients-pager">' +
                  '<button type="button" class="button button-small" data-recipients-page="prev"' +
                    (hasPrev ? '' : ' disabled') + '>‹ ' + escHtml(I18N.previous || 'Previous') + '</button>' +
                  '<button type="button" class="button button-small" data-recipients-page="next"' +
                    (hasMore ? '' : ' disabled') + '>' + escHtml(I18N.next || 'Next') + ' ›</button>' +
                  '</span>'
                : '') +
            '</footer>';

        body.innerHTML = html;
    }

    function renderPreview(body, d) {
        // Pre-send dry-run path. Each sample row carries:
        //   was_opted_out (bool) — user's stated preference
        //   delivery     ('sent'|'skipped') — the outcome
        //   force        ('none'|'include'|'exclude') — admin override
        // Each user appears once. Tabs filter by was_opted_out.
        if (!body) return;
        var html = '';
        var optedOut = d.opted_out || 0;
        var ignore = !!d.ignore_optouts;
        var sample = d.sample || [];

        // Line 1: total recipients (always).
        html += '<div class="lrob-etk-nl-recipients-summary">' +
            '<strong>' + (d.total || 0) + '</strong> ' + escHtml(I18N.recipientsTotal || 'recipients');
        html += '</div>';

        if (d.by_kind) {
            html += '<p class="lrob-etk-nl-recipients-bykind">' +
                (d.by_kind.subscriber || 0) + ' ' + escHtml(I18N.recipientsSubscribers || 'subscribers') + ' · ' +
                (d.by_kind.user || 0) + ' ' + escHtml(I18N.recipientsUsers || 'WordPress users') +
                '</p>';
        }

        // Line 2: opted-out count + inline Bypass checkbox. Only
        // rendered when there ARE opt-outs (otherwise irrelevant).
        if (optedOut > 0) {
            html += '<div class="lrob-etk-nl-recipients-optout-row">' +
                '<span class="lrob-etk-nl-recipients-optout-count">−' + optedOut + ' ' +
                    escHtml(I18N.recipientsOptedOut || 'opted out') +
                '</span>' +
                '<label class="lrob-etk-nl-recipients-bypass-inline">' +
                    '<input type="checkbox" data-recipients-ignore-optouts' + (ignore ? ' checked' : '') + '>' +
                    '<span>' + escHtml(I18N.recipientsBypassShort || 'Bypass') + '</span>' +
                '</label>' +
                '</div>';
            // Line 3: warning, only when bypass is actually checked.
            if (ignore) {
                html += '<p class="lrob-etk-nl-recipients-bypass-warn">' +
                    escHtml(I18N.recipientsBypassWarn ||
                        '⚠ Opt-outs bypassed — this newsletter will reach recipients who explicitly opted out. Only proceed for legitimate operational / legal communications.') +
                    '</p>';
            }
        }

        // Tabs — only when there ARE opt-outs in the matched audience.
        // No opt-outs means filtering is pointless.
        if (optedOut > 0) {
            var optedIn = (d.total || 0) - optedOut + (ignore ? 0 : optedOut);
            // optedIn is the audience minus opt-outs. We compute from
            // the sample tagged data — the tab counts are derived
            // client-side so they're always consistent with the rows.
            var counts = {all: sample.length, opted_in: 0, opted_out: 0};
            sample.forEach(function (r) {
                if (r.was_opted_out) counts.opted_out++;
                else counts.opted_in++;
            });
            html += '<div class="lrob-etk-nl-recipients-tabs" role="tablist">' +
                '<button type="button" role="tab" class="lrob-etk-nl-recipients-tab is-active" data-recipients-preview-tab="all">' +
                    escHtml(I18N.recipientsTabAll || 'All') + ' <span>(' + counts.all + ')</span>' +
                '</button>' +
                '<button type="button" role="tab" class="lrob-etk-nl-recipients-tab" data-recipients-preview-tab="opted_in">' +
                    escHtml(I18N.recipientsTabOptedIn || 'Opted-in') + ' <span>(' + counts.opted_in + ')</span>' +
                '</button>' +
                '<button type="button" role="tab" class="lrob-etk-nl-recipients-tab" data-recipients-preview-tab="opted_out">' +
                    escHtml(I18N.recipientsTabOptedOut || 'Opted-out') + ' <span>(' + counts.opted_out + ')</span>' +
                '</button>' +
                '</div>';
        }

        if (sample.length) {
            var sampleNote = (I18N.recipientsSample || 'Sample (first %d):')
                .replace('%d', d.sample_limit || sample.length);
            html += '<p class="lrob-etk-nl-recipients-sample-note">' + escHtml(sampleNote) + '</p>';
            html += '<ul class="lrob-etk-nl-recipients-preview-list" data-recipients-preview-list>';
            sample.forEach(function (r) {
                html += renderPreviewRow(r);
            });
            html += '</ul>';
        }
        body.innerHTML = html;
    }

    function renderPreviewRow(r) {
        var deliveryBadge = r.delivery === 'sent'
            ? '<span class="lrob-etk-nl-recipients-badge is-sent">' + escHtml(I18N.recipientsWillSend || 'will send') + '</span>'
            : '<span class="lrob-etk-nl-recipients-badge is-skipped">' + escHtml(I18N.recipientsSkipped || 'skipped') + '</span>';
        var optoutBadge = r.was_opted_out
            ? '<span class="lrob-etk-nl-recipients-badge is-opted-out">' + escHtml(I18N.recipientsOptedOut || 'opted out') + '</span>'
            : '';
        var forceBadge = '';
        if (r.force === 'include') {
            forceBadge = '<span class="lrob-etk-nl-recipients-badge is-forced-in">' + escHtml(I18N.recipientsForceIncluded || 'force include') + '</span>';
        } else if (r.force === 'exclude') {
            forceBadge = '<span class="lrob-etk-nl-recipients-badge is-forced-out">' + escHtml(I18N.recipientsForceExcluded || 'force exclude') + '</span>';
        }

        // Per-row action button — context-dependent.
        var toggleHtml = '';
        if (r.force === 'include') {
            toggleHtml = '<button type="button" class="lrob-etk-nl-recipients-row-toggle is-on" ' +
                'data-force-toggle="include" data-mode="remove" data-kind="' + escHtml(r.kind || '') + '" data-id="' + (r.id || 0) + '">' +
                escHtml(I18N.recipientsUndoForce || 'Undo') +
                '</button>';
        } else if (r.force === 'exclude') {
            toggleHtml = '<button type="button" class="lrob-etk-nl-recipients-row-toggle is-on" ' +
                'data-force-toggle="exclude" data-mode="remove" data-kind="' + escHtml(r.kind || '') + '" data-id="' + (r.id || 0) + '">' +
                escHtml(I18N.recipientsUndoForce || 'Undo') +
                '</button>';
        } else if (r.delivery === 'skipped') {
            // Opted-out without override → offer "Send anyway".
            toggleHtml = '<button type="button" class="lrob-etk-nl-recipients-row-toggle" ' +
                'data-force-toggle="include" data-kind="' + escHtml(r.kind || '') + '" data-id="' + (r.id || 0) + '">' +
                escHtml(I18N.recipientsForceInclude || 'Send anyway') +
                '</button>';
        } else {
            // Default-sent → offer "Exclude".
            toggleHtml = '<button type="button" class="lrob-etk-nl-recipients-row-toggle is-quiet" ' +
                'data-force-toggle="exclude" data-kind="' + escHtml(r.kind || '') + '" data-id="' + (r.id || 0) + '">' +
                escHtml(I18N.recipientsForceExclude || 'Exclude') +
                '</button>';
        }

        return '<li class="lrob-etk-nl-recipients-preview-row delivery-' + (r.delivery || 'sent') + '" ' +
            'data-row-optedout="' + (r.was_opted_out ? '1' : '0') + '">' +
            '<span class="lrob-etk-nl-recipients-kind">' + escHtml(r.kind || '') + '</span>' +
            '<span class="lrob-etk-nl-recipients-email-value"> ' + escHtml(r.email || '') + '</span>' +
            (r.name ? '<span class="lrob-etk-nl-recipients-name"> ' + escHtml(r.name) + '</span>' : '') +
            optoutBadge +
            forceBadge +
            deliveryBadge +
            toggleHtml +
            '</li>';
    }

    function formatSentAt(raw) {
        if (!raw || raw === '0000-00-00 00:00:00') return '';
        // Server returns UTC mysql datetime; render in browser-local time.
        var ts = Date.parse(raw.replace(' ', 'T') + 'Z');
        if (isNaN(ts)) return raw;
        var d = new Date(ts);
        try {
            return d.toLocaleString();
        } catch (e) {
            return raw;
        }
    }

    // Wire the persistent controls once. Filter clicks and pagination
    // ride on event delegation since their DOM is re-rendered per load.
    (function wireRecipientsModal() {
        var modal = document.getElementById('lrob-etk-nl-modal-recipients');
        if (!modal) return;
        modal.addEventListener('click', function (e) {
            var filterBtn = e.target.closest('[data-recipients-filter]');
            if (filterBtn) {
                var key = filterBtn.getAttribute('data-recipients-filter') || '';
                if (recipientsState.filter !== key) {
                    recipientsState.filter = key;
                    recipientsState.offset = 0;
                    loadRecipients();
                }
                return;
            }
            var pageBtn = e.target.closest('[data-recipients-page]');
            if (pageBtn && !pageBtn.disabled) {
                var dir = pageBtn.getAttribute('data-recipients-page');
                if (dir === 'next') recipientsState.offset += recipientsState.limit;
                else if (dir === 'prev') recipientsState.offset = Math.max(0, recipientsState.offset - recipientsState.limit);
                loadRecipients();
            }
            // Per-row force-include / force-exclude toggle in the
            // pre-send preview. Click → AJAX persist → reload preview
            // so the row's badge + the global count reflect the new
            // state. Mode defaults to 'add' (button = "Send anyway" /
            // "Exclude"); 'remove' for the undo state.
            var forceBtn = e.target.closest('[data-force-toggle]');
            if (forceBtn) {
                e.preventDefault();
                var list = forceBtn.getAttribute('data-force-toggle');
                var kind = forceBtn.getAttribute('data-kind');
                var id   = forceBtn.getAttribute('data-id');
                var mode = forceBtn.getAttribute('data-mode') || 'add';
                forceBtn.disabled = true;
                post(ACTIONS.forceOverridesSave, {
                    newsletter_id: recipientsState.newsletterId,
                    list: list,
                    kind: kind,
                    id: id,
                    mode: mode
                }).then(function (resp) {
                    forceBtn.disabled = false;
                    if (resp && resp.success) {
                        loadRecipients();
                        // Card-level count refresh too — picker reads
                        // the same data so it'll pick up the new total.
                        var card = document.querySelector('[data-newsletter-id="' + recipientsState.newsletterId + '"]');
                        if (card) refreshRecipientCount(card);
                    }
                });
                return;
            }
            // Tab switch within the preview — pure client-side
            // filtering on the was_opted_out flag carried by every
            // sample row. Tabs only render when opt-outs exist.
            var tabBtn = e.target.closest('[data-recipients-preview-tab]');
            if (tabBtn) {
                var tab = tabBtn.getAttribute('data-recipients-preview-tab');
                modal.querySelectorAll('[data-recipients-preview-tab]').forEach(function (b) {
                    b.classList.toggle('is-active', b === tabBtn);
                });
                var rows = modal.querySelectorAll('[data-recipients-preview-list] [data-row-optedout]');
                rows.forEach(function (row) {
                    var isOptedOut = row.getAttribute('data-row-optedout') === '1';
                    var show = (tab === 'all')
                        || (tab === 'opted_in' && !isOptedOut)
                        || (tab === 'opted_out' && isOptedOut);
                    row.hidden = !show;
                });
                return;
            }
        });
        // Bypass checkbox in the preview header (rendered conditionally
        // when opt-outs exist). Routes through the standard
        // newsletter-meta save endpoint. Listening on 'change' rather
        // than 'click' to fire after the checked state flips.
        modal.addEventListener('change', function (e) {
            var bypass = e.target.closest('[data-recipients-ignore-optouts]');
            if (!bypass) return;
            var value = bypass.checked ? '1' : '';
            var fd = new FormData();
            fd.append('action', 'lrob_etk_nl_newsletter_save_meta');
            fd.append('_nonce', (window.lrobEtkNlAdmin && window.lrobEtkNlAdmin.nonce) || '');
            fd.append('newsletter_id', recipientsState.newsletterId);
            fd.append('key', '_lrob_etk_nl_ignore_optouts');
            fd.append('value', value);
            fetch((window.lrobEtkNlAdmin && window.lrobEtkNlAdmin.ajaxUrl) || '', { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                .then(function () {
                    loadRecipients();
                    var card = document.querySelector('[data-newsletter-id="' + recipientsState.newsletterId + '"]');
                    if (card) refreshRecipientCount(card);
                });
        });
        var searchInput = modal.querySelector('[data-recipients-search]');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                if (recipientsState.searchDebounce) clearTimeout(recipientsState.searchDebounce);
                var value = this.value;
                recipientsState.searchDebounce = setTimeout(function () {
                    recipientsState.search = value;
                    recipientsState.offset = 0;
                    loadRecipients();
                }, 300);
            });
        }
    })();

    startClockTick();
    startPolling();
})();
