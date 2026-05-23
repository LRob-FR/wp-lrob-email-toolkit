/* LRob — Email Toolkit · Newsletter cards send pipeline
 *
 * Drives Send-now / Pause / Resume / Abort / Test-send on each
 * newsletter card in the Newsletters admin view. One delegated
 * listener for all cards on the page (no N inline scripts).
 *
 * State the global config carries (window.lrobEtkNlSend, set up via
 * wp_localize_script in HomePage::enqueue_assets):
 *
 *   ajaxUrl   string — admin-ajax.php endpoint
 *   nonce     string — nonce for the SendAjaxController NONCE_ACTION
 *   actions   { tick, test, pause, resume, abort }
 *   i18n      copy strings used in alerts / status text
 *
 * Each newsletter card has a per-card stopRequested flag tracked on
 * its root element so pause/abort can break the loop locally without
 * waiting for the next server response.
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
            // 'draft' badge is intentionally hidden — that's the
            // default state, no need to label every new card.
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
        // Lock/unlock the settings fieldset.
        var settingsFs = card.querySelector('.lrob-etk-nl-card-settings');
        if (settingsFs) settingsFs.disabled = (sending || paused || terminal);
        // Title input lives outside the fieldset; disable separately.
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
                'width:100%; min-height:60vh; border:1px solid #dcdcde; border-radius:4px; display:block;';
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

        // ---- Recipients modal: fetch count + sample, render into body.
        // Two response modes:
        //   - snapshot: frozen at send time (per-row status included)
        //   - preview:  dry-run of who'd be targeted right now
        if (trigger.hasAttribute('data-card-recipients')) {
            // Anchor trigger (not a button — anchors stay clickable
            // even inside a fieldset-disabled settings group, so the
            // list is reachable after the newsletter is sent / locked).
            e.preventDefault();
            var recModal = modalById('lrob-etk-nl-modal-recipients');
            if (!recModal) return;
            var recBody = recModal.querySelector('[data-recipients-body]');
            if (recBody) recBody.innerHTML = '<p>' + (I18N.recipientsLoading || 'Computing…') + '</p>';
            openModal(recModal);
            post(ACTIONS.recipientsPreview, { newsletter_id: newsletterId }).then(function (resp) {
                if (!resp || !resp.success || !recBody) {
                    if (recBody) recBody.innerHTML = '<p>' + ((resp && resp.data && resp.data.message) || I18N.previewFailed || 'Failed.') + '</p>';
                    return;
                }
                var d = resp.data;
                var isSnapshot = d.mode === 'snapshot';
                var html = '';

                // Header: total + the right secondary line per mode.
                html += '<p><strong>' + (d.total || 0) + '</strong> ' + (I18N.recipientsTotal || 'recipients');
                if (isSnapshot) {
                    html += ' <span style="color:#6b7280;font-weight:normal;">— ' +
                            (I18N.snapshotNote || 'frozen at send time') + '</span>';
                }
                html += '</p>';

                if (isSnapshot && d.by_status) {
                    var statusParts = [];
                    Object.keys(d.by_status).forEach(function (k) {
                        statusParts.push((d.by_status[k] || 0) + ' ' + k);
                    });
                    html += '<p style="color:#6b7280;font-size:0.9em;">' + statusParts.join(' · ') + '</p>';
                } else if (d.by_kind) {
                    html += '<p style="color:#6b7280;font-size:0.9em;">';
                    html += (d.by_kind.subscriber || 0) + ' ' + (I18N.recipientsSubscribers || 'subscribers') + ' · ';
                    html += (d.by_kind.user || 0) + ' ' + (I18N.recipientsUsers || 'WordPress users');
                    html += '</p>';
                }

                if (d.sample && d.sample.length) {
                    html += '<p style="margin-top:1rem;color:#6b7280;font-size:0.85em;">' +
                            (I18N.recipientsSample || 'Sample (first %d):').replace('%d', d.sample_limit || d.sample.length) +
                            '</p>';
                    html += '<ul style="list-style:none;padding:0;margin:0;max-height:40vh;overflow-y:auto;border:1px solid #e5e7eb;border-radius:4px;">';
                    d.sample.forEach(function (r) {
                        var statusBadge = '';
                        if (isSnapshot && r.status) {
                            var color = '#6b7280';
                            if (r.status === 'sent')    color = '#065f46';
                            if (r.status === 'failed')  color = '#b32d2e';
                            if (r.status === 'skipped') color = '#8a5a00';
                            if (r.status === 'pending') color = '#0a3978';
                            statusBadge = ' <span style="float:right;font-size:0.75em;text-transform:uppercase;letter-spacing:0.05em;color:' + color + ';font-weight:600;">' +
                                r.status + (r.failure_code ? ' (' + r.failure_code + ')' : '') + '</span>';
                        }
                        // Failed sends keep a Logging row; surface the link so
                        // the admin can jump straight to the error + body.
                        var logLink = '';
                        if (r.log_url) {
                            logLink = ' <a href="' + r.log_url + '" style="margin-left:0.5rem;font-size:0.8em;">' +
                                (I18N.viewInLogs || 'View in Logs →') + '</a>';
                        }
                        html += '<li style="padding:0.4rem 0.6rem;border-bottom:1px solid #f0f0f1;font-size:0.9em;">' +
                                '<span style="color:#6b7280;font-size:0.75em;text-transform:uppercase;letter-spacing:0.05em;margin-right:0.5rem;">' +
                                (r.kind || '') + '</span>' +
                                (r.email || '') +
                                (r.name ? ' <span style="color:#6b7280;">(' + r.name + ')</span>' : '') +
                                logLink +
                                statusBadge +
                                '</li>';
                    });
                    html += '</ul>';
                }
                recBody.innerHTML = html;
            });
            return;
        }

        // ---- Delete modal: populate title + confirm-link href.
        if (trigger.hasAttribute('data-card-delete')) {
            var delModal = modalById('lrob-etk-nl-modal-delete');
            if (!delModal) return;
            var titleEl = delModal.querySelector('[data-delete-title]');
            var confirmLink = delModal.querySelector('[data-delete-confirm]');
            if (titleEl) titleEl.textContent = trigger.getAttribute('data-newsletter-title') || '';
            // The card already has the delete href via window object?
            // Instead, build it here by reading nonce + post from a
            // hidden anchor — or just delegate to the existing
            // admin-post URL we constructed PHP-side. Simplest: ask
            // the trigger for it.
            if (confirmLink) confirmLink.setAttribute('href', trigger.getAttribute('data-delete-url') || '#');
            openModal(delModal);
            return;
        }

        if (trigger.hasAttribute('data-send-now')) {
            var isSchedule = (trigger.querySelector('[data-send-label]') || {}).textContent
                === (trigger.getAttribute('data-label-schedule') || '');
            etkConfirm({
                title: isSchedule
                    ? (trigger.getAttribute('data-label-schedule') || 'Schedule')
                    : (trigger.getAttribute('data-label-send') || 'Send now'),
                body: isSchedule
                    ? (trigger.getAttribute('data-confirm-schedule') || '')
                    : (trigger.getAttribute('data-confirm-send') || ''),
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
        // Card-level: toggle list picker visibility when the audience
        // "Specific list" radio toggles. (Pure UI state, no count refresh
        // here — that's debounced via the saved event below so we count
        // against the latest persisted meta, not the in-flight change.)
        if (e.target.matches('input[data-key="target_kind"]')) {
            var card = e.target.closest('[data-newsletter-id]');
            if (!card) return;
            var listPicker = card.querySelector('[data-target-list-picker]');
            if (listPicker) {
                var showList = e.target.checked && e.target.value === 'list';
                listPicker[showList ? 'removeAttribute' : 'setAttribute']('hidden', '');
            }
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
    var AUDIENCE_KEYS = ['target_kind', 'target_list_id'];
    document.addEventListener('lrob-etk-nl-saved', function (e) {
        if (!e.detail || !e.detail.newsletterId) return;
        var key = e.detail.key || '';
        var card = document.querySelector('[data-newsletter-id="' + e.detail.newsletterId + '"]');
        if (!card) return;
        // Audience / category change → refresh recipient count.
        if (AUDIENCE_KEYS.indexOf(key) !== -1 || key.indexOf('category') !== -1) {
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
        post(ACTIONS.recipientsPreview, { newsletter_id: id }).then(function (resp) {
            if (resp && resp.success && typeof resp.data.total !== 'undefined') {
                countEl.textContent = resp.data.total;
            } else {
                countEl.textContent = '—';
            }
        });
    }

    // Initial card sync on page load: list-picker visibility +
    // recipient count (one cheap AJAX per card).
    function syncCardInitial(card) {
        var listPicker = card.querySelector('[data-target-list-picker]');
        if (listPicker) {
            var kindRadio = card.querySelector('input[data-key="target_kind"]:checked');
            var showList = kindRadio && kindRadio.value === 'list';
            listPicker[showList ? 'removeAttribute' : 'setAttribute']('hidden', '');
        }
        refreshRecipientCount(card);
    }
    document.querySelectorAll('[data-newsletter-id]').forEach(syncCardInitial);
})();
