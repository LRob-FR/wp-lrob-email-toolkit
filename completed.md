# Shipped — version history

Concise, fact-checked record of what's actually in the codebase. Each version lists user-facing features + load-bearing technical decisions. Implementation details live in the code; this file is "what does the plugin do today and when did each capability arrive".

For the **backlog** (what's not done yet), see [todo.md](./todo.md).
For working rules (conventions, naming, build, UI patterns), see [CLAUDE.md](./CLAUDE.md).

---

## v0.3.x — Newsletter module (alpha → being hardened)

### v0.3.4
- **International phone field — opt-in country-code picker.** New per-field attributes on the `phone` field type: `country_picker` (off by default), `country_default` (admin-pinned ISO-2, or blank for smart resolve), `country_auto_detect` (browser-side override). When enabled, the plain `<input type="tel">` becomes a composite widget: trigger button [flag + +dial ▼] glued to the tel input via a single rounded border, with a dropdown menu listing all 245 countries (search-as-you-type, alphabetised, flag emoji + name + dial code). Auto-detect resolves on the visitor's side from `navigator.language`; server-side fallback chain is admin choice → WP `get_locale()` → WP `wp_timezone_string()` via `DateTimeZone::listIdentifiers(PER_COUNTRY)` (no manual TZ mapping). On submit, JS rewrites the outgoing FormData value to E.164 (`+<dial><digits>`) so the server stores one canonical string per row. Plain mode (`country_picker` off) preserves the historical behavior. Dataset lives in `src/Forms/CountryData.php` as one table (`[iso => [dial, __(name)]]`), names wrapped in `__()` at the literal site for `wp i18n make-pot`.
- **Theme-aware form text colors.** `--lrob-etk-cf-fg` (labels, helpers, prose) is now `inherit` instead of `var(--wp--preset--color--contrast, #0f172a)` — the FSE contrast preset is a single global value, not context-aware, and forced black text on dark sections. New `--lrob-etk-cf-input-fg` carries the dark-text-on-light-input-bg value (defaults to `var(--wp--preset--color--contrast, #0f172a)`); used by every input/textarea/select and the phone-picker widget. Labels follow the page section's text color; input chrome stays legible against its own bg regardless.

### v0.3.3
- **SMTP override_mode tier system.** Replaces the `force_from` boolean with a 3-tier `override_mode` enum: `never` / `when_default` / `always`. The `when_default` mode sniffs caller-set `From:` headers via the `wp_mail` filter and only steps in when none is set — respects WooCommerce / contact-form / etc. sender choices. New identities default to `when_default`. Schema v3 migration drops `force_from` + populates `override_mode` (existing rows mapped `force_from=1 → always`, `=0 → never`). The setFrom call in `phpmailer_init` is now gated on the same mode logic as the `wp_mail_from` filter (was unconditionally overriding before).
- **Auto-save state machine** (SMTP cards). Ported from the QR-maker plugin: state machine (idle / dirty / saving / saved / failed) with visible badge, `dirtyDuringFlight` bool re-fires save after the in-flight one settles, `flushSave()` returns the in-flight Promise for await-on-close, setTimeout-id-nulled-on-fire so flush-on-blur doesn't re-save after a successful save.
- **Per-action nonces** on destructive endpoints (defense in depth on top of the per-module nonce + `manage_lrob_etk` capability gate). SMTP: `delete` / `set_default` / `save_routing`. Captcha: `delete_identity` / `set_default` / `save_routing`. Helper `guard_action($action)` in each module's AjaxController; JS side auto-injects `_action_nonce` via an action-string → nonce reverse map.
- **Cross-feature captcha** wiring: comments / lost-password / registration handled via `Modules/Captcha/WpHooks.php`. Each context only registers WP hooks when its route resolves to something other than `none` — zero overhead on contexts the admin hasn't opted in to. Admin bypass for comments.
- **Captcha admin UI rebuilt**: section renamed *Routing* → **Captcha assignments** (FR: *Affectation des captchas*). All routing dropdowns switched to `Admin\Combobox::render_fixed_select`. Per-context dropdowns show *Inherit default (X)* with X resolved live + a *None — no captcha here* option for opt-out. Default-row dropdown has no None / no Inherit (default can never be off). Flat *[Type] Name* option labels (*Built-in: Math challenge*, *hCaptcha: Production*) so the collapsed select trigger carries the type.
- **Make-default buttons** on every built-in challenge `<li>` and every identity card footer. Click flips the global default and reloads.
- **Module install seed** now puts form contexts (contact_form, newsletter_subscribe) on `inherit` (captcha on by default) and WP contexts (comments, lost_password, registration) on `none` (admin opts in explicitly).
- **Shared default-badge + set-default CSS** promoted from admin-smtp.css to admin-components.css.
- **Newsletter card stats** got a *clicks (X%)* line next to opens.
- **Display fix**: `NewsletterRepository::list_all()` now selects `opens_count / opens_unique / clicks_count / clicks_unique` so the card actually shows what the tracking endpoint records.

### v0.3.2 — Newsletter tracking pipeline (step 9)
- **Per-recipient image + link rewriters** route every outbound newsletter body through HMAC-signed REST endpoints at `/wp-json/lrob-etk/v1/nl/track/{img,click}/<token>`. Open-pixel (1×1 GIF) appended only when the body had zero `<img>`. Test sends bypass rewriting entirely.
- **HMAC token signer** (`Support\TrackingToken`): HKDF-derived secret from `AUTH_KEY` (separate info-tag from `Encryption`), 32-char URL-safe tokens, constant-time verify.
- **Per-newsletter asset + link side tables** (Schema v8): `wp_lrob_etk_nl_newsletter_assets` + `wp_lrob_etk_nl_newsletter_links`. URL stored once per newsletter; tracking URLs carry small per-newsletter ids.
- **Counters**: per-recipient (`newsletter_recipients.opens/clicks/last_*_at`), per-newsletter aggregate (`opens_count/unique`, `clicks_count/unique`), per-subscriber lifetime (`total_opened/clicked/sent`, `last_engagement_at`, `sends_since_engagement`). WP-user equivalents in user_meta.
- **Cold-subscribers sub-tab** in Subscribers admin filters by `sends_since_engagement >= threshold` (default 5).
- **Settings panel** exposes cold_threshold + `engagement_counts_opens` (default false — Apple MPP poisons opens) + `tracking_retention_days` (default 365).
- **Daily retention cron** prunes `tracking_events` in chunked DELETE; aggregate counters kept forever.
- **IP anonymisation**: IPv4 → /24, IPv6 → /48. UA only stored when per-newsletter opt-in.

### v0.3.1 — Newsletter list polish
- **Sub-tabs** (In preparation / Sent / Trash) on the Newsletters list with live counts. Newly-created newsletters sort to top.
- **Trash system**: `wp_trash_post` based. Restore + Delete-permanently + Empty-trash actions. Sending/paused newsletters can't be trashed (admin must abort first). `trashed_post` hook flips scheduled→draft + SendCron joins `wp_posts` to exclude trashed rows.
- **Recipients drawer**: paginated `newsletter_recipients` view with status-filter chips (All / Pending / Sent / Failed / Skipped), email/name substring search, cross-link to Logging entries on failed rows. Wide modal variant + XSS-hardened rendering.

### v0.3.0 — Newsletter module (alpha)
- **Schema + recipient model** (subscribers + WP-user dual track via user_meta).
- **Lists** (manual + rule-based hybrid) + **Categories** with per-recipient opt-outs.
- **Subscribe forms** built on the shared `src/Forms/` form-builder (extracted from Contact Form). Double-opt-in + RFC 8058 one-click unsubscribe.
- **Preferences page** + WP-profile section + Gutenberg prefs block + reminder cron for `pending` subscribers.
- **Trash + refuse-acknowledgment** flows.
- **Newsletter CPT + composer** (Gutenberg with restricted block subset) + companion-table runtime state.
- **Send pipeline**: Materializer (chunked recipient insert) + claim-based SendLoop + AJAX progress + test-send modal.
- **WP-Cron safety-net** (`SendCron`, 1-min interval) picks up stale `sending` newsletters when AJAX dies. Scheduled-send promotion handled by the same cron.
- **SMTP circuit-breaker**: 5 consecutive `wp_mail` failures → pause with `pause_reason='smtp_unhealthy'`, release claimed-but-unsent rows. *Retry failed (N)* bulk action.
- **Logging integration**: Logger reads `X-Lrob-Etk-Newsletter-ID` + recipient-id headers, deletes successful newsletter rows by default (recipient state lives in `newsletter_recipients`, no duplication), per-newsletter *Log every send* override.
- **Newsletter cards refactor**: settings + send actions on each card; Gutenberg is content-only. Send button is state-aware (*Send now ⇄ Schedule ⇄ Unschedule[red]*). Explicit commit-schedule (ticking a date doesn't auto-promote).
- **Cron health diagnostic** panel at the bottom of the Newsletters view.
- **Live clock-tick + adaptive server-poll** in cards: relative-time spans, multi-tab dedup via localStorage, Page Visibility-aware.
- French translation: ~100% coverage.

---

## v0.2.x — Contact Form polish + auto-update

### v0.2.1
- WordPress 7.0 `.button .dashicons line-height: 1.9` override.

### v0.2.0
- **Contact Form submissions inbox**: view of FormsPage at `?page=lrob-etk-cform&view=submissions`. Filters, detail view, dashboard tiles, captcha-outcome counters, per-form save-toggle, IP-storage toggle, retention cron, form-delete cascade modal. Reply composer was deferred.
- **GitHub-release auto-update** (`src/AutoUpdate/Updater.php`): 1h cache, force-refresh on `update-core.php` or `?force-check=1`.

---

## v0.1.x — Captcha module

- **Captcha module** as a service module (always-on). Per-context routing (`default` / `contact_form` / `comments` / `newsletter_subscribe` / `lost_password` / `registration`).
- **Built-in challenges**: Math (signed token, per-form scope) + Image (picture recognition).
- **Hosted-provider framework**: hCaptcha shipped. Cloudflare Turnstile / Google reCAPTCHA designed to plug in via `ProviderInterface`. Each provider has N identities with AES-256-GCM encrypted credentials.

---

## v0.0.x — Foundation modules

- **SMTP module**: multiple identities, per-source routing rules, `wp-config.php` constant overrides, AES-256-GCM password encryption, native `mail()` fallback transport.
- **Email Logging module**: every outgoing email stored (headers, body, attachments, status, errors). Browse / search / filter / resend. Configurable retention. Activity charts on the dashboard.
- **Contact Form module**: from-scratch WYSIWYG editor (drag/drop, columns, inline settings, autosave, undo/redo). Honeypot + time-trap + rate-limit + captcha. Starter templates. Per-form recipients + Reply-To picking + subject/success-message templates.
- **Public events API** (stable since v0.0.1): `lrob_etk_event` (generic) + typed `lrob_etk_<event>` actions. Names live in CLAUDE.md.
