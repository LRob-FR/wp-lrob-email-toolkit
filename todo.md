# Todo — backlog, priority-ordered

What's not done yet. Three tiers + a "maybe / deferred" pile. Within each tier, no strict order — pick what's most useful when you're picking.

For **what's shipped**, see [completed.md](./completed.md).
For **how to build / conventions**, see [CLAUDE.md](./CLAUDE.md).

---

## 🗺️ Roadmap to 1.0

Sequence locked with the user. Each milestone is a meaty chunk; items inside a milestone can ship in any order. Bounce / suppression / universal-tracking / outbound-customize and a few others stay in 1.x — see *Essential / Nice to have* below.

### v0.4.x — Captcha enrichment + CSS cleanup + LRob branding
- **Captcha — Cloudflare Turnstile + Google reCAPTCHA providers.** Drop into `Providers/`, auto-discovered. Mirror the hCaptcha shape (credential_fields, validate_credentials, logo_html, render, verify).
- **Captcha — invisible mode + theme / size customization.** hCaptcha + Turnstile support invisible (no widget; verified at submit). Per-identity admin fields for `display_mode` (visible / invisible), `theme` (light / dark / auto), `size` (normal / compact). Probably extends `ProviderInterface` with an optional `display_fields()` next to `credential_fields()`.
- **CSS cleanup — eliminate hardcoded chrome values plugin-wide.** The token system (`--etk-text-*`, `--etk-space-*`, etc.) is in place but historical hardcoded values still litter `admin-newsletter.css` (status pills, gaps, paddings), `admin-components.css`, and per-module sheets. Prerequisite for the theme system + density setting in v0.5.x. Risk: visual regressions — do it as a careful sweep with eyeball checks. See memory `feedback_css_tokens_no_hardcode`.
- **LRob branding.** Subliminal "made by LRob.fr" placements in empty states, footers, and module About panels — inspired by [wp-lrob-qrcode-maker](https://github.com/LRob-FR/wp-lrob-qrcode-maker) (consult before implementing to mirror the style). Plugin readme / Author URI / lrob.fr links polished. Strategic: this is how the 15 days of dev work get amortized.
- **Quick cleanups:** drop the "optionally archive a copy to your IMAP Sent folder" text from the Logging dashboard (feature deferred, misleading promise). Make the cron-diagnostic panel at the bottom of Newsletters expand-on-demand instead of always-visible.

### v0.5.x — Theme system + density + accessibility
- **Theme system: Light / Dark / Auto / LRob / Contrast.** Settings radio. Auto follows `prefers-color-scheme`. LRob = branded palette (user provides colors). Contrast = colorblind-safe palette + reinforced WCAG ratios. Each theme is a CSS-variable swap on top of the v0.4.x-cleaned tokens.
- **Density setting: Compact / Comfortable / Spacious.** Single setting flips the `--etk-text-*` + `--etk-space-*` scale. Accessibility win.
- **Accessibility audit pass.** Focus rings, keyboard nav, ARIA labels on icon buttons, screen-reader-friendly status pills, color-only-information audit (paired with Contrast theme).

### v0.6.x — Contact form visual personalization
- **Contact form personalization (real this time).** Per-form: background-on-hover, color presets + custom color picker, animations (button click: ripple/scale/bounce; send success: fade/checkmark/confetti). Replaces the currently-useless "preset" picker with something actually configurable. See memory `project_contact_form_visual_polish` (needs to be fleshed out into specs first).

### v0.7.x — Send-rate throttling + per-domain limits (deliverability foundations)
- **Per-identity (sender-side) cap.** Already-deferred backlog item. New `send_cap_rate` int + `send_cap_window` enum (`per_minute` / `per_hour`) on SMTP identity. SendLoop checks before claiming next batch; skips tick when cap reached. Use cases: "this Mailjet plan allows 2000/h max", "OVH SMTP throttles at 100/min".
- **Per-destination-domain throttle — 3 levels, with /min OR /h units.** Every rate carries both a `count` and a `window` (`per_minute` / `per_hour`) — some ISPs publish their limits in minutes, others in hours, and getting the unit wrong breaks the whole protection.
  1. **Global default** rate per recipient-domain (e.g. 60/h to any one domain by default).
  2. **Hardcoded overrides** for known-strict ISPs as a default map in code: e.g. laposte.net (typically 30/h), free.fr (10/min in some sources, 60/h in others — verify before hardcoding), orange.fr, gmail.com (200/h is conservative), etc. Each entry = `{domain, count, window}`. Admin can disable individual entries but not edit them (they reflect ISP reality).
  3. **User-configurable overrides** in admin: admin adds `{domain, count, window}` rows. Persists in option. UI in dedicated "Deliverability" sub-page (or under SMTP/Newsletter settings).
  - **Implementation note:** SendLoop tracks send-count-per-domain in a rolling window (transient keyed by `{domain, bucket}` where bucket = `floor(now / window_seconds)`); next batch skips recipients whose domain has hit cap *for the current bucket*; remaining recipients spill to next tick (or next bucket boundary). Per-minute windows mean the rolling-bucket math needs to be precise — a sloppy 60-second window that drifts will under-deliver. Consider testing with a synthetic 5/min limit to validate before shipping.
- **Bounce handling.** NDR parsing. Two ingestion paths: **(1) IMAP polling** — admin configures bounce-mailbox creds, cron polls every 5min; **(2) Webhook** — providers like Mailgun/Postmark/SendGrid push events to `/lrob-etk/v1/bounces`. Classify hard (mailbox-not-found, domain-not-found) vs soft (mailbox-full, deferred). Hard → auto-unsubscribe + add to suppression list. Soft → increment `bounce_count`, unsubscribe after N consecutive.
- **Suppression list.** Global "do not email" scoped to the site. Sources: hard bounces (auto), complaints / FBL, manual additions. Pre-send filter drops suppressed emails regardless of list membership. New table `wp_lrob_etk_nl_suppression(email, reason, added_at, source)`.

### v0.8.x — GDPR Toolkit
- Data export (JSON of everything for an email)
- Delete request (hard-delete + anonymisation marker preserving aggregate stats)
- Consent log (IP anonymised, timestamp, source, the consent label text **at signup time**)
- Per-category retention controls
- Privacy-policy integration helpers
- Strategic: marketing argument "cloud souverain / EU-friendly".

### v0.9.x — Beta hardening + bug bash
- User-cycle testing, bug fixes, polish.

### v1.0.0 — stable

---

**Deferred to 1.x+** (still essential but post-1.0): universal email tracking (Logs "Opened" column), one-shot composer, drip/automation workflows, in-house email builder, WooCommerce integration, subscriber custom fields + tags, customize WP default emails + outbound blocklist, statistics overhaul, "view online" link.

---

## ⚡ Priority — sooner is better

These are foundational or carry strategic weight. Do them before chasing more features.

- **UI uniformization + theme system.** The admin surfaces have drifted across modules — title formats, debug-panel placement, link colors, card spacing vary. Two layers: **(a)** consolidate every page through the same chrome (`<header class="lrob-etk-page-header">`, same module-toggle position, same diagnostic-panel pattern, same empty-state typography); **(b)** every color/border/radius/shadow becomes a CSS custom property, so theme = CSS-variable swap. Three first-class themes: **Light** (default), **Dark** (high-contrast WP-style), **LRob** (mostly-dark, branded palette matching lrob.fr). Settings radio: Auto / Light / Dark / LRob, **Auto** follows `prefers-color-scheme`. Future themes plug in via a filter. Pairs with the dark-mode email preview backlog (same tokens).
- **Statistics overhaul — Newsletter view + Email Toolkit dashboard.** Essential, currently missing. Three surfaces: **(a) Newsletter → Statistics view**: sortable list by date/open-rate/click-rate, trend charts (sends-per-month, avg rates over time), per-list + per-category rollups (transient-cached 5min), per-newsletter drill-down (per-asset opens, per-link clicks, per-domain breakdown, recipient event timeline), per-subscriber detail. **(b) Global Email Toolkit dashboard tiles**: last-N-days global sends/opens/clicks, latest newsletter's headline numbers, cold-count, active-sends, contact-form submissions, SMTP failures. Tiles link to module detail views. **(c) Per-card stats expansion**: open-vs-sent gauge, CTR, unsubscribe rate, time-to-first-open, top-3 clicked links inline. Recipients drawer per-row gets opens/clicks columns. **No charting library** — vanilla SVG or HTML/CSS bars.
---

## Essential — next milestones

The features that would let this plugin replace Mailchimp / Brevo / SendInBlue for serious users.

- **Universal email tracking + "Opened" column in Logs.** Extend the existing tracking pipeline (HMAC tokens + image rewriter + REST endpoint) to *every* outbound email, not just newsletters. Use case: "did the customer get the invoice?" — admin opens the log row and sees *Opened 3 days ago, 2 times*. **Token shape**: `(log_id, asset_id)` HMAC-signed — `log_id` is the recipient identifier (each log row is 1:1 with a recipient). **Hook**: `wp_mail` filter (or `phpmailer_init`) wrapping after the Logger row is created but before PHPMailer sends. **Schema**: add `first_opened_at datetime`, `opens int`, `clicks int`, `last_click_at datetime` to the logs table. **Rewriter scope**: HTML bodies only (skip plain text); skip messages carrying `X-Lrob-Etk-Newsletter-ID` (newsletter has its own tracking); skip messages carrying explicit `X-Lrob-Etk-No-Track` (caller opt-out). **Defaults**: global toggle **off** on fresh installs (don't surprise upgrade-installs); per-source filter list with sensible auto-mutes (password resets / 2FA / GDPR-sensitive sources off by default — same source-slug vocab as the "Customize WordPress default emails" / mutes backlog). **REST endpoint**: new family `/lrob-etk/v1/log/track/{img,click}/<token>` separate from the `/nl/track/` family (different recipient model). **Logs UI**: new *Opened* column (timestamp / em-dash), filter (all / opened / not-opened), detail view shows `first_opened_at` + `opens` + `clicks` + per-click event timeline. **Caveat**: evidence of delivery, not proof of read — Apple MPP inflates opens, images-blocked clients don't fire the pixel, plain-text gets nothing.
- **One-shot email composer with templates + history + attachments.** Admin-side "Compose email" surface — replaces the "compose in Gmail + attach the PDF" workflow for sending formal manual emails (quotes, invoices, customer correspondence). **Compose surface**: recipient picker (To/Cc/Bcc) autocompleting from subscribers + WP users + recent contacts; subject; body (leans on the in-house email editor when it ships, Gutenberg as A/B); header/footer template picker ("letterhead", "signature"); attachment upload (multi-file). **Templates**: admin saves reusable templates with token placeholders (`{{customer_name}}`, `{{quote_amount}}`, `{{invoice_date}}`, …) — "Quote template", "Invoice template", "Reminder template". New CPT `lrob_etk_cmp_tpl` or similar. **Server-side attachments**: every attachment persisted to `wp-content/uploads/lrob-etk-cmp/<YYYY>/<MM>/<send-id>/` so the admin can re-download from history later, not just lose them in the sent email. Reuses the outbound-attachments backlog item for size-cap + fallback-to-link behaviour. **History**: dedicated "Composed emails" admin view (or grouped sub-tab in Logs) showing every manual compose, recipient, subject, status, opens (via universal tracking above), with "Duplicate to compose again" action. **Module scope**: probably a new module `Modules/Composer/` — independent admin surface + own state. Reuses: in-house editor (body), outbound-attachments (files), universal tracking (delivery proof), templates pattern (system email templates). Pairs naturally with Contact Form reply composer (different entry point, same engine).

- **Drip / automation workflows (Marketing automation module).** Visual journey builder: Subscribe → wait 1 day → email A → if opened, email B; if not, email C. Per-subscriber state in `wp_lrob_etk_nl_automation_state`. Cron pulls due steps. Triggers off existing events (`newsletter.subscriber.confirmed`, `email.sent`, `contact_form.submitted`, plus WC events when that integration lands). Biggest single competitive gap.
- **Drag-and-drop email builder (in-house editor).** Extend `admin/js/form-fields-editor.js` into an email content editor. New block types: paragraph / heading / image / button / separator / spacer / columns. Rich text inside text blocks (bold/italic/links via Selection API, no execCommand). Token-insert dropdown. `wp.media` for images. PHP renderer emits email-safe HTML (table wrappers, inline styles). **A/B path**: ship side-by-side with Gutenberg via a settings toggle; if it doesn't work for the body, the in-house editor still pays off for the newsletter footer. Keep Gutenberg for system email templates.
- **Subscriber custom fields + tags.** Admin defines custom subscriber fields (city, company, signup_source, last_purchase_date, …). Tags = arbitrary strings (`vip`, `purchased-2024-q1`). List rule grammar extends to filter on both. Automation can add/remove tags. Unlocks real segmentation; the missing killer feature.
- **Bounce handling.** Hard/soft bounce parsing. Two paths: **(1) IMAP polling** — admin configures the bounce-mailbox creds, cron polls every 5min, parses NDR notifications, classifies hard (mailbox-not-found, domain-not-found) vs soft (mailbox-full, deferred). **(2) SMTP webhook** — providers like Mailgun/Postmark/SendGrid push events to `/lrob-etk/v1/bounces`. Hard → auto-unsubscribe + add to suppression list. Soft → increment `bounce_count`, unsubscribe after N consecutive. IMAP path also revives the deferred *IMAP save-to-sent* feature.
- **Suppression list.** Global "do not email" scoped to the whole site. Sources: hard bounces (auto), complaints (FBL), manual additions, optional unsubscribe escalation. Pre-send filter drops suppressed emails regardless of list membership. Admin sub-tab in Subscribers admin. New table `wp_lrob_etk_nl_suppression (email, reason, added_at, source)`.
- **GDPR toolkit.** EU compliance suite. **(1)** Data export: JSON of everything we hold about an email (subscriber row + memberships + recipient rows + tracking events + submissions). **(2)** Delete request: hard-delete + anonymisation marker so aggregate stats survive. **(3)** Consent log: every subscribe action logs IP (anonymised), timestamp, source, checkbox state, the consent label *text at signup time* (so wording changes don't invalidate prior consents). **(4)** Retention controls per data category. **(5)** Privacy-policy integration.
- **WooCommerce integration.** Most WP users have a store. **(1)** Abandoned-cart emails. **(2)** Post-purchase follow-ups (review request). **(3)** Order-receipt template customization (pairs with the "Customize WordPress default emails" item). **(4)** Customer segmentation in list rules (`customer_total_spent > 100`, `last_order_date < 6 months`, `bought_product = X`). HPOS-aware. **(5)** Repeat-customer / VIP / win-back auto-tags via automation.
- **"View online" link for newsletters.** Public web page rendering each sent newsletter (typical *View this email in your browser →* link at the top of the body). URL `/?lrob-etk-nl-view=<token>` with HMAC-signed `(newsletter_id, recipient_kind, recipient_id)` so the page can personalize. **Deleted-newsletter behaviour**: trashed = still renders (recoverable); permanently-deleted = `410 Gone` with friendly message. Permanent-delete confirmation modal warns *"View-online links in already-sent emails will stop working."*
- **Customize WordPress default emails.** Sibling of the mutes feature. Admin rewrites subject + body + (optionally) from-name for WP-core emails (auto-update notice, new-user welcomes, password-reset, password-change, email-change, comment-moderation). Per-source "use WP default / use custom" toggle. Token vocabulary per source (`{{user_name}}`, `{{site_name}}`, `{{login_url}}`, etc.). Storage: CPT `lrob_etk_etpl` with `purpose` meta = the WP-source slug. Lives in the same admin surface as the mutes (probably a new top-level "Outbound" module).
- **Outbound-email blocklist + WP-system-email mutes.** Sibling of customize-WP-emails. **(1)** Global kill-switch ("Block all outgoing") for staging/dev sites — short-circuits `wp_mail` chain, optionally logs the would-have-been-sent. **(2)** Per-source toggles for noisy WP-core emails. UI in the same surface as customize-WP-emails.

---

## Nice to have

Real features, well worth doing, but the plugin isn't materially worse without them yet.

- **Migrate Submissions inbox detail modal to the shared `etk-detail-modal.js` helper.** Logs already uses it; Submissions still carries its own copy of the modal code (~200 LOC of duplication). Same UX visible to user, just collapse the implementations into one.
- **Admin UI overhaul — done.** Phase 1, 2A/2B, 3, 4 (switch + recipient + preset), 5 (Newsletter) all landed. Residual cleanup: still ~30 hex one-offs in `admin-newsletter.css` (mostly per-feature accent tints — kept as one-offs because they don't map to existing tokens), and the placeholder Newsletter views (Import) still need real screens.
- **Reconsider Delivered / Received status labels.** Current wording is ambiguous to non-technical admins. Candidates: *Saved / Notified / Send failed / Spam*, or *Stored / Emailed*. Affects only the display layer — DB constants stay as-is for back-compat.
- **Cross-logs view — unify Email Logs + Contact Form submissions in one place.** Two design candidates: **(a) single timeline table** with a `source` column (outbound log / form submission) + filter chips to scope to one or both; row actions adapt per source. **(b) two stacked sections** on the Email Logs page: outbound logs on top, form submissions below, each with their own toolbar. Same filter UX (search + date + status) shared by both. Bigger move: probably requires extracting the filter+bulk+modal JS bits from `contact-form-submissions-inbox.js` into a generic helper used by both surfaces.

### Newsletter polish
- **Subscriber self-edit (profile + email-change confirm flow).** Pairs with the public/private visibility flag shipped in v0.3.4 (Categories → Lists merge). Subscribers edit their own profile (name/phone/address/gender) from the prefs page; email change requires a click-to-confirm token sent to the new address. Parity with the Newsletter plugin (import compat). See memory `project_lists_private_public`.
- **Subscribers — bulk "Add to list…" action.** The filter-by-list half shipped in v0.3.5 (URL `&list_id=`, dropdown in filter bar, cross-link from Lists-modal count badge). Remaining piece: a "Add to list…" bulk action so admins can attach the currently-selected subscribers to a list in one go, without opening each detail modal. Same bulk-toolbar mechanics as the existing Trash / Restore / Delete actions.
- **WooCommerce (non-Subscriptions) order-based list rule provider.** Provider matching customers by order history: "ordered product X within/after N months", "bought product X but not Y", "lifetime spend > N". Builds on the existing AJAX product picker + WC order/HPOS queries.
- **Advanced rule composition (AND / OR / NOT).** Single-provider rules cover simple cases; segmentation queries like "active WC subscription on product X **and not** on product Y" need a composite layer. Likely a `RuleComposite` over the existing single-provider model, with a tree UI.
- **Campaigns system — design conversation.** Open question: are list-rules the right place for advanced WC/Subscriptions targeting, or should a separate Campaigns module own composable segments + drip + event-triggered sends? Pair this with the marketing automation backlog before committing.
- **"All recipients" pseudo-list — opt-in mechanic.** Today picking *All subscribers* + *All WP members* in the audience picker gives the equivalent. Consider whether a dedicated `all_recipients` pseudo-kind that unions both sides as one list is worth a 6th system list — depends on user feedback after multi-list usage.
- **Form editor — composite `address` field type.** Sibling of the `gender` field type shipped in v0.3.4. Functionally covered by the *Postal address* preset (drops 5 pre-mapped text fields). This task is the convenience layer: one composite field shell with a single label that auto-decomposes into sub-inputs at render time + decomposes back to per-column values at submit.
- **WP-user membership UI in lists.** Largely subsumed by the two-kinds model — users lists are rule-only by design. Open piece: allow Manual additions of WP users to a Subscribers list (today the membership table accepts both kinds but the UI only adds subscribers).
- **A/B testing.** Subject-line A/B first: two variants, send to N% of list, wait W hours, pick winner by open rate, send to remaining %. Content A/B is v2.
- **Welcome series + post-subscribe automation.** Focused first slice of the broader automation module. Per-list admin defines a sequence ("send template X after N hours, then Y after M days"). Triggers on `newsletter.subscriber.confirmed`.
- **Auto-resend to non-openers.** Per-newsletter toggle: re-send to recipients whose `opens = 0` after N days with a new subject. Counts as a separate newsletter for stats.
- **Archive sent newsletters.** "Archive" action on terminal newsletters moves them to an Archived sub-tab so the active list doesn't accumulate. Auto-archive after N days post-send as a nice-to-have on top.
- **Newsletter templates (separate CPT).** Premade locked templates ("monthly digest", "single-article", "event invite") + admin-created saved templates. "+ New newsletter" gets a picker (Blank / Template / Duplicate existing). Premade live in code (seed data), admin-created use `lrob_etk_nl_tpl` CPT.
- **Newsletter default settings page.** "Newsletter defaults" surface for inherited values (default identity, default category, default tracking toggles).
- **Newsletter list polish follow-ups.** Per-newsletter detail report (per-asset / per-link breakdown), bulk-unsubscribe action on the Cold tab, optional auto-cleanup cron (auto-unsubscribe past threshold).
- **Send-in-progress live progress bar polish.** Smoother live updates, ETA estimate, per-batch flash. AJAX ↔ Cron handoff: when admin opens a card showing `sending`, JS starts a poll loop until terminal — no manual refresh needed.

### Email content & delivery
- **RSS-to-newsletter.** Per-list/per-category admin configures feed URL + cadence + template. Cron builds a digest from new entries. **Custom intervals**: daily / weekly / monthly / yearly recap (yearly = "2025 in posts"). Two modes per feed: auto-send or draft-for-review (default: draft).
- **Time-zone-aware send scheduling.** "Send at 9am in each recipient's local time" instead of fixed UTC. Recipient TZ captured at subscribe time from browser. Materializer partitions by TZ. Optional "working hours only" cap.
- **Mobile + dark-mode email preview.** Preview modal grows a toolbar: viewport (Desktop/Tablet/Phone) + color-scheme (Light/Dark) toggles applied to the iframe root.
- **Calendar invite (.ics) attachment.** Per-newsletter "Include calendar invite" checkbox. Admin fills event details, .ics ships with every send. ~30 lines of PHP, no library.
- **Spam-score check before send.** Local heuristics (CAPS%, exclamation density, spam-trigger words, image-to-text ratio). Score 0–100; warn on schedule confirmation when ≥ threshold. Optional mail-tester.com integration.
- **AI subject-line + preview-text suggestions.** Opt-in to admin's own Anthropic/OpenAI/OpenRouter key (AES-encrypted). "✨ Suggest" button on the card. Pure additive.
- **Per-identity (per-provider) hourly send cap.** Sender-side limit imposed by the SMTP provider ("this Mailjet plan allows 2000/h"). New `hourly_send_cap` int column on SMTP identity. SendLoop consults before claiming next batch; skips tick when cap reached. Distinct from the per-recipient-domain throttle (which protects inboxes from spam-flagging).
- **Per-domain throttle for newsletter sends.** Deferred from step 7b polish. Protects inboxes from spam-flagging by rate-limiting *to* known-strict ISPs (laposte.net 30/h, etc.).

### Form features
- **Form logic — Google-Forms-style conditionals + branching.** Conditional field visibility (show/hide field N based on field M's value), branching/skip-logic on multi-page forms ("if answered No, jump to end"), richer validation (regex / min-max / length / custom error), matrix/grid fields (radio-grid, checkbox-grid), pre-fill from URL params (`?lrob_etk_cf_prefill[email]=...`), CSV export + summary view of submissions. Reused for Newsletter sign-up + Drawing registration forms. Out of scope: quiz mode with auto-grading.
- **Multi-page contact forms.** Form-builder gains page breaks. Frontend renders one page at a time with Next/Previous, validates per page, submits at end. State in hidden inputs. No server-side draft persistence v1.
- **File-upload field — phase 1 SHIPPED in v0.3.4. Remaining phase-2 follow-ups**:
  - **Storage maintenance UI** (Contact Form sub-tab "Storage"): total disk usage, per-form breakdown, top-N largest files, age filter (>30d/90d/1y), bulk delete. Backing methods are already in place (`FileRepository::disk_usage_by_form` / `delete_older_than` / `count_total`), just needs the admin view.
  - **Daily orphan-files cron**: scan `lrob-etk-cf/<form-id>/` directories without a live submission, log a warning so admin can review + clean from the Storage tab. Files aren't auto-deleted by this pass.
  - **Activation notice** on the ContactForm hub: dismissible *Files come from untrusted visitors — always inspect before opening. No malware scan built in.*
- **Outbound attachments.** Separate concept from inbound uploads. Attach files to outgoing emails (auto-response, newsletter, drawing winner, contact form reply). **Two source paths**: forward uploaded files from the same submission, or static admin-uploaded attachments. **Critical**: total mail size cap (default ~10MB). Over the cap → auto-fall-back to "save to server + link in email" with signed time-limited URLs.
- **Contact Form reply composer.** Deferred from submissions-inbox work. Per-form "reply identity" + ad-hoc Reply-To override in composer. `replied_at` + reply count tracked on `cf_submissions`.
- **Contact form visual customization.** Per-form colours, roundness, hover/focus glow, button animations, submit-success celebration. Named templates ("sober", "fancy"). See memory `project_contact_form_visual_polish`.
- **Multi-recipient contact forms.** See memory `project_contact_form_conditional_recipients` — route to different recipient based on a dropdown field.
- **Responsive preview modes for the form-card preview.** Desktop / Tablet / Phone toggles constraining card width.

### New modules
- **Draw / raffle module.** Visitors register via a form; admin (or scheduled trigger) picks N winners at random; winners get an automated email. Participants can opt into a shared Newsletter list. Per-draw config: entry window, max winners, one-per-email / one-per-WP-user. Cryptographic randomness seeded once + stored for auditability.
- **Integrations module.** Outbound webhooks: Slack / Discord / Matrix / n8n + generic. Built on the `lrob_etk_event` action that already ships from v0.0.1 — devs can hook events today via WordPress actions, no module needed.
- **Cross-feature captcha enrichment.** More providers (Cloudflare Turnstile, Google reCAPTCHA) — drop into `Providers/`, auto-discovered. More in-house challenges (image-letter, simple logic, proof-of-work using local browser compute) — drop into `Challenges/`, also auto-discovered.

### Cross-cutting polish
- **Per-context SMTP identity routing.** Admin assigns identities to email categories (WooCommerce, admin notifications, contact forms, etc.) on the SMTP settings page. `MailRouter` matches from headers / hook context.
- **Subscribe-to-comments.** Visitor-facing. Per-thread token, list-unsubscribe header. Integrates with SMTP routing + captcha + logging.
- **Email export.** Bulk CSV (possibly mbox/EML). Reuse `LogRepository` filtered query helpers. Stream the response.
- **Email reading in a modal with prev/next navigation.** Full-screen-ish modal with ←/→ keys cycling. Keep `LogsPage` row→detail addressable by index.
- **Captcha admin live preview.** Settings page needs live previews of each challenge + per-challenge config blocks when more providers land. See memory `project_captcha_admin_preview_pending`.

---

## Maybe / deferred

Useful in theory; not committed to. Revisit on demand.

- **SMS marketing.** Way later, post-1.0. Provider abstraction (Twilio / Vonage / OVHcloud / etc.), per-recipient phone (the international-phone-field is a prerequisite), separate opt-in flag, cost-aware throttle (SMS costs real money), SMS-template CPT alongside newsletters. Regulatory minefield (10DLC US, GDPR EU, ARCEP FR, opt-in proof storage). Won't ship until email is feature-complete.
- **IMAP "Save to Sent" archive.** Originally next-up after Captcha, demoted to optional. Useful for self-hosted IMAP setups but heavy: credential handling + cron dispatch + failure-mode complexity for a niche feature. If revisited: extends Logging, identities grow IMAP credential fields (same AES-256-GCM model), async dispatch via WP-Cron. Likely lands as a side-effect of bounce-handling-via-IMAP above.
- **SMTP identity uniqueness on host+username.** Future schema change; one mailbox = one identity, but per-identity From override still allowed. See memory `project_smtp_identity_uniqueness`.
- **Custom field editor as Gutenberg replacement.** Replace Gutenberg with the in-page drag-and-drop field editor across the plugin. See memory `project_contact_form_field_editor`.
- **Homemade anti-bot question pool.** Beyond Math + Image, build a small library (image-letter, simple logic, etc.). Form picks one at random per submission. Each self-contained (no external API).
- **Newsletter front-end UI components.** Re-evaluate if subscribers want richer self-serve UIs (browse archives, preference center beyond current).
- **AbuseIPDB IP reputation check** at form-submit time. See memory `project_contact_form_backlog`.

---

## Regression preventers (do not re-break)

These aren't features — they're things that were broken once and fixed; the code now depends on the fix.

- **Resender** (`Modules/Logging/Resender::resend()`) creates a new log row for retries, leaves the original untouched. Earlier code marked the original as `retried` and undercounted sends. Don't reintroduce a status flip. `build_headers()` runs every stored header component through `strip_crlf()` — keep this.
- **From / transport resolution**: SMTP identity rows store `from_email` / `from_name` that may be empty (= fall back at send time). `Identity::effective_from_email()` returns `smtp_username` if empty; `effective_from_name()` returns site title. Per-identity `transport` (`smtp` | `mail`) honored by `MailRouter` and `TestSender`. New per-identity behavior follows the same `effective_*` accessor pattern.
- **Attachments in logs**: `logs.attachments` is JSON `[{"name", "path"}]`. `LogEntry::normalize_attachments()` upgrades legacy string-only entries — keep as long as old rows can exist. Resend re-attaches files whose `path` still resolves; reports `attachments_dropped` for the rest.
- **Captcha fail-closed on misconfiguration**: `CaptchaService::verify()` returns `[false, message]` on `STATE_BROKEN` (deleted identity, inactive identity, AUTH_KEY rotated). Distinguish `STATE_NONE` (admin opted out → fail open) from `STATE_BROKEN` (misconfig → fail closed). `Captcha\Module::render_broken_routes_notice()` surfaces broken routes to admins.
- **Service-module migrate trap** (Captcha is the type case): always-enabled modules record `db_version=1` on every existing site *before* the module had install logic. Bumping target to 2 makes `maybe_migrate()` take the `migrate()` branch — schema never gets created. Fix: always override `migrate()` to forward to `install()` (idempotent). If recovering from an already-shipped broken bump, bump the target one more notch so stuck sites re-take the migrate path. See memory `project_service_module_migrate_trap`.
- **Injection safety from stored email content** — the touchy one. Once mail-receive lands, `logs.body_html` / `subject` / `from_name` / headers / filenames are attacker-controllable. Admin UI must escape on output; `body_html` must render in a sandboxed iframe (never inject into admin DOM); resend/forward/reply paths re-sanitise (don't reuse stored HTML raw); CSV export must prefix `= + - @ \t \r` cells with `'` to neuter spreadsheet formula injection.
