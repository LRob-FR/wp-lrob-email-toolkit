# Contact Form module

> Loaded on demand, **not** part of the always-in-context `CLAUDE.md`. Pointed to from `CLAUDE.md`'s "Code reference docs" index. Keep in sync when this subsystem changes.

## Overview

The Contact Form module (`slug: contact_form`, `db_version_int: 5`) provides drag-and-drop contact forms backed by a custom post type (CPT). Each form is embedded into pages via a `lrob-etk/contact-form` Gutenberg block. Submissions hit a single AJAX endpoint, are persisted to a dedicated database table, and trigger a notification email routed through the SMTP module.

**Admin page:** `admin.php?page=lrob-etk-cform`. The Submissions inbox is a view of the same page (`?view=submissions`) — see `docs/form-builder.md` for the shared field editor, and `docs/captcha.md` for captcha integration.

---

## CPT — `src/Modules/ContactForm/CPT.php`

**Post type slug:** `lrob_etk_cform` (not `lrob_etk_contact_form` — WP enforces a 20-char cap on `post_type`; the longer slug silently failed to register).

**Storage:** `post_content` holds the form structure JSON (see `FormStructure`). All sidebar settings are individual `post_meta` keys so the REST API exposes them to the Gutenberg editor sidebar.

**Visibility:** `public: false`, `publicly_queryable: false`, `show_in_rest: true` (REST base `lrob-etk-contact-forms`). No public URL; forms are surfaced only via the embed block.

**Capability mapping:** plural primitives only (`edit_posts`, `publish_posts`, etc.) — all mapped to `manage_lrob_etk`. Do **not** add singular caps (`edit_post`, `read_post`, `delete_post`) here: WP's meta cap routing would make every `current_user_can('manage_lrob_etk')` call recurse into a post-level check without a post ID, returning `do_not_allow` and locking every admin out of the toolkit menu.

### Post meta constants (all registered with `show_in_rest: true`)

| Constant | Key | Type | Sentinel |
|---|---|---|---|
| `META_RECIPIENT` | `_lrob_etk_cf_recipient` | string | `''` = inherit global |
| `META_RECIPIENT_IDENTITY` | `_lrob_etk_cf_identity_id` | int | `0` = SMTP routing |
| `META_REPLY_TO_FIELD` | `_lrob_etk_cf_reply_to_field` | string | `''` = inherit global |
| `META_SUBJECT_TEMPLATE` | `_lrob_etk_cf_subject_template` | string | `''` = inherit global |
| `META_SUCCESS_MESSAGE` | `_lrob_etk_cf_success_message` | string | `''` = inherit global |
| `META_RATE_LIMIT_MAX` | `_lrob_etk_cf_rate_max` | int | `0` = inherit global |
| `META_RATE_LIMIT_WINDOW` | `_lrob_etk_cf_rate_window` | int | `0` = inherit global |
| `META_HONEYPOT_ENABLED` | `_lrob_etk_cf_honeypot` | string | `'default'` = inherit |
| `META_CHALLENGE_KIND` | `_lrob_etk_cf_challenge` | string | `''` = inherit captcha context |
| `META_CONFIRMATION_TIER` | `_lrob_etk_cf_confirmation_tier` | string | `'none'` |
| `META_STYLE_PRESET` | `_lrob_etk_cf_style_preset` | string | `''` = inherit global |
| `META_STYLE_VARS` | `_lrob_etk_cf_style_vars` | string | `''` |
| `META_SAVE_SUBMISSIONS` | `_lrob_etk_cf_save_submissions` | string | `''`/`'default'` = inherit global |

**Sentinel pattern:** `Settings::effective_*($form_id)` resolves per-form meta → global option → hardcoded fallback. Pass sentinels straight to the auto-save endpoint; never coerce them client-side.

### Field name namespacing

Frontend POST data: `lrob_etk_cf[<instance>][<slug>]`. Files: `$_FILES['lrob_etk_cf']['name'][<instance>][<slug>]`. The `instance` is a random 10-char hex per page render that scopes fields when multiple forms share a page. `FIELD_ID_PREFIX = 'lrob-etk-cf'`.

---

## Database schema — `src/Modules/ContactForm/Schema.php`

Three tables; versioning owned by `AbstractModule::maybe_migrate` via `lrob_etk_contact_form_db_version`. `Schema` itself only declares the current shape; `dbDelta` handles additive upgrades.

### `{prefix}lrob_etk_contact_submissions`

Primary submission archive. Key columns:

| Column | Notes |
|---|---|
| `form_id` | FK (soft) to the CPT post ID |
| `status` | `received` / `delivered` / `failed` / `spam_blocked` |
| `ip_hash` | SHA-256(AUTH_KEY\|ip) — always stored |
| `ip_address` | raw IP — only when `store_raw_ip` setting is on |
| `fields_json` | JSON of slug → submitted value; verbatim, escape at render |
| `log_id` | FK to outbound email log (Logging module) |
| `notes` | diagnostic string: `honeypot_tripped`, `time_trap`, `captcha_failed`, `prior:<status>` |
| `captcha_slug` | which captcha ran (empty = none) |
| `captcha_outcome` | `passed` / `failed` / `skipped` |

The `notes` field doubles as a prior-status carrier for spam toggling: `flag_as_spam()` writes `prior:<old_status>`; `restore_from_spam()` reads it back.

### `{prefix}lrob_etk_contact_rate`

Sliding-window rate limiter rows: `(ip_hash, form_id, hit_at)`. Pruned daily by `RateLimiter::gc()` (retains 7 days). Transients were rejected here because object-cache hosts can evict them at any moment.

### `{prefix}lrob_etk_contact_files`

One row per uploaded file attached to a submission. The submission's `fields_json` stores numeric file IDs; the real metadata (original name, stored path, MIME, size) lives here. `FileStorage` owns disk writes; `FileRepository` owns table reads/writes/deletes.

---

## Settings — `src/Modules/ContactForm/Settings.php`

Option: `lrob_etk_contact_form_settings` (flat array). `Settings::all()` merges stored values over `defaults()`. `Settings::save($values)` sanitizes every key before writing.

**Retention windows** (0 = disabled, kept forever; positive int = days before auto-deletion). Defaults:
- `delivered`: 0 (keep forever)
- `received`: 0 (keep forever)
- `failed`: 0 (keep forever)
- `spam_blocked`: 90 days

**Spam-save flags:**
- `save_spam_bot` (default `false`): skip honeypot/time-trap rows from the inbox — they are almost always bots.
- `save_spam_captcha` (default `true`): persist captcha-fail rows — can be a legitimate user with a typo.

**`effective_*($form_id)` resolution chain:** per-form meta → global option → hardcoded fallback. Every consumer calls these; never read raw meta directly.

---

## Submission pipeline — `src/Modules/ContactForm/SubmitHandler.php`

AJAX actions: `lrob_etk_cf_submit` (both `wp_ajax_` and `wp_ajax_nopriv_`). Nonce action: `lrob_etk_cf_submit` (also accepted via `X-WP-Nonce` header for page-builder environments that strip hidden inputs).

**Pipeline order (cheap first):**

1. Nonce verification
2. Form exists + published check
3. Honeypot (`Honeypot::tripped()`) — silent success response to prevent bot adaptation
4. Time-trap (`_lrob_etk_cf_started` < 2 seconds ago)
5. Rate limiter (`RateLimiter::over_limit()`)
6. Captcha verification (`CaptchaService::verify()`)
7. Field validation + file upload validation
8. Insert submission row (or skip if `save_submissions` is off)
9. `RateLimiter::record()`
10. File storage (move from tmp, EXIF strip if requested)
11. `Events::dispatch('contact_form.submitted', …)`
12. Send notification email via `wp_mail` with `SourceResolver::push(SOURCE_CONTACT_FORM)`
13. Update submission status to `delivered` or `failed`
14. `Events::dispatch('contact_form.delivered', …)`

**File delivery modes** (per `file_upload` field's `delivery` attr):
- `webserver` — stored on disk, admin link in email body
- `attachment` — emailed as attachment, not stored
- `both` — stored AND attached to email

When `save_submissions` is off, all file fields are clamped to `attachment` mode (no submission row to anchor stored files to).

**Notification email:** HTML-only (`text/html; charset=UTF-8`). Reply-To header is set from `Settings::effective_reply_to_field()` (default: the `email` field slug). Sentinel `__none__` = no Reply-To header. Reply-To name is guessed from `name`/`fullname`/`firstname` field values. Subject template supports `{field:slug}` and `{title}` tokens. CR/LF stripped from header values for injection safety.

**SMTP identity override:** If `META_RECIPIENT_IDENTITY` is set, `MailRouter::force_identity()` is called for this one send (and reset in a `finally` block).

---

## Anti-spam stack

### Honeypot — `src/Forms/Honeypot.php`

A hidden field rendered outside the visible form structure, before the closing `</form>`. Any non-empty value triggers a silent success — the bot "succeeds" without knowing it was blocked. See `Settings::effective_honeypot($form_id)` for the on/off override chain.

### Time trap

`_lrob_etk_cf_started` hidden field carries a server-side timestamp (seconds). Submissions arriving in under 2 seconds (`MIN_FORM_TIME_SECONDS`) are treated as bots. Same silent success response as honeypot.

### Rate limiter — `src/Modules/ContactForm/RateLimiter.php`

Per-IP (hashed) per-form sliding window, backed by the `contact_rate` table. IP hash: `SHA-256(AUTH_KEY . '|' . $ip)` — per-site salt prevents cross-site precomputation. Rate errors return HTTP 429 (not a silent success — bots hitting the limit should know to slow down; legitimate users need a user-visible message).

**Trusted proxy headers:** CF-Connecting-IP → X-Real-IP → X-Forwarded-For (first entry) → REMOTE_ADDR. Falls back silently to empty string if none resolves as a valid IP.

### Captcha integration

See `docs/captcha.md` for full Captcha module details. From the Contact Form side:

- `META_CHALLENGE_KIND` on the form post stores a **routing key**: `''` (inherit Captcha module's `contact_form` context), `'none'`, `'homemade:<slug>'`, or `'identity:<int>'`.
- `CaptchaService::resolve(['context' => 'contact_form', 'form_id' => …, 'force_route' => …])` resolves the active challenge.
- `captcha_slug` + `captcha_outcome` are recorded on every submission row for dashboard stats.

**Migration note (v1→v3):** `Module::migrate_captcha_routing_keys()` is idempotent and converts old bare slugs (`math`, `image_recognition`) to `homemade:<slug>`. The old per-module `challenge` option in `lrob_etk_contact_form_settings` is folded into the Captcha module's `contact_form` context map on upgrade.

---

## File uploads — `FileStorage`, `FileRepository`, `FileDownloadController`

### Storage path

`uploads/lrob-etk-cf/<form-id>/<YYYY>/<MM>/<DD>/<hex8>_<sanitized>.<ext>`

The `<hex8>` random prefix forecloses URL guessing and prevents same-name collisions. A `.htaccess` (Apache) and `index.html` are written to the root on first use.

### Access

Files are **not** directly HTTP-accessible. Admin downloads go through:

```
GET /wp-json/lrob-etk/v1/cf/file/<file_id>[?inline=1][?w=200&h=200]
```

Permission: `manage_lrob_etk`. The nonce must be a `wp_rest` nonce (injected at render time). `Content-Disposition` is `inline` for images and PDFs, `attachment` for everything else. Images support on-demand resize (`?w=`/`?h=`) via GD for inbox thumbnails.

### Security layers

- Extension whitelist via `UploadPolicy` (presets: `documents`, `images`, `all`; custom ext list; dangerous flag)
- Tier-1 (executables) always blocked regardless of preset
- `wp_check_filetype_and_ext()` content sniff (magic bytes vs claimed extension)
- EXIF strip on images when `strip_exif` is on (Imagick first, GD fallback; best-effort)
- `chmod(0640)` after move
- `realpath` check that destination is inside the upload root

---

## Frontend rendering — `EmbedRenderer`, `Blocks`, `Frontend`

`Blocks` registers the `lrob-etk/contact-form` Gutenberg embed block. `EmbedRenderer::render()` is its `render_callback`. At render time:

1. Looks up the form CPT post (returns placeholder or nothing if not found/published)
2. Calls `Frontend::enqueue_assets()` (idempotent)
3. Generates a 10-char hex `instance` per render
4. Calls `FormContext::start($form_id, $instance, …)` to scope field name/id prefixes
5. Walks `FormStructure::load($form_id)` and dispatches each field to its registered `FieldTypeRegistry` renderer
6. Appends honeypot + hidden fields (`_wpnonce`, `_lrob_etk_cf_form_id`, `_lrob_etk_cf_instance`, `_lrob_etk_cf_started`, `action`)
7. Calls `FormContext::end()`

Style: `lrob-etk-form-preset--<slug>` class on `<form>`. Style vars (`--lrob-etk-cf-radius`, `--lrob-etk-cf-accent`, `--lrob-etk-cf-font-size`) set inline from per-block overrides → global defaults. The frontend `assets/css/contact-form.css` uses a separate `--lrob-etk-cf-*` token system — never cross with admin tokens.

**Assets:** `lrob-etk-form-frontend` (CSS) and `lrob-etk-form-submit` (JS, with `window.lrobEtkForm` localization) are *registered* on `wp_enqueue_scripts` but only *enqueued* by `EmbedRenderer` when a form actually appears on the page.

---

## Templates — `TemplateRegistry`

Built-in form starter templates: `simple_contact`, `quote_request`, `support_ticket`, `feedback`, `event_rsvp`. Each returns a `FormStructure`-shaped array (structure only — no per-form settings). The "New form" picker shows them plus any existing forms as clone sources. The registry is consumed by `AjaxController::handle_create_form`.

---

## Module lifecycle — `Module.php`

**`install()`** calls `Schema::install()` (idempotent via `dbDelta`) and `migrate_captcha_routing_keys()`.

**`migrate($from, $to)`** step-gates schema installs:
- v1→v2: `captcha_slug` + `captcha_outcome` columns on submissions
- v2→v3: captcha routing key migration (bare slugs → `homemade:*`)
- v3→v4: `ip_address` column on submissions
- v4→v5: new `lrob_etk_contact_files` table

**`uninstall()`:** unregisters crons, trashes all CPT posts, drops tables. Tables are also swept by `uninstall.php`'s prefix scan.

**`register()`:** runtime (CPT, blocks, frontend, rate limiter, submit handler, file download, retention cron) only when `is_enabled()`. Admin chrome (forms page, AJAX controller, email actions, submissions AJAX) always registered so the user can re-enable from the admin page.

---

## Admin AJAX endpoints — `AjaxController.php`

Nonce action: `lrob_etk_cf_admin`. All require `manage_lrob_etk`.

| Action | Handler | Purpose |
|---|---|---|
| `lrob_etk_cf_save_meta` | `handle_save_meta` | Per-form per-key autosave (post_meta or title). Whitelist-only — unknown keys rejected. |
| `lrob_etk_cf_save_structure` | `handle_save_structure` | Replace full form structure JSON (`FormStructure::save`). |
| `lrob_etk_cf_create_form` | `handle_create_form` | Create new CPT post from blank / template / existing form clone. |
| `lrob_etk_cf_save_default` | `handle_save_default` | Per-key autosave for the global Defaults card (writes to `lrob_etk_contact_form_settings`). |

**Meta type coercion in `save_meta`:**

| Type string | Behavior |
|---|---|
| `int` | `max(0, (int))` |
| `string` | `sanitize_text_field` |
| `slug` | `sanitize_key` |
| `textarea` | `sanitize_textarea_field` |
| `recipient_list` | split on comma, `sanitize_email` + `is_email`, rejoin |
| `tristate` | one of `'default'`/`'on'`/`'off'`; falls back to `'default'` |
| `challenge` | shape-validates routing key (`''`, `'none'`, `'homemade:*'`, `'identity:*'`); unknown → `''` |
| `style_preset` | `sanitize_html_class` |

---

## Submissions inbox AJAX — `SubmissionsAjax.php`

Nonce action: `lrob_etk_cf_submissions_ajax`. All require `manage_lrob_etk`.

| Action | Purpose |
|---|---|
| `lrob_etk_cf_submissions_filter` | Re-render the swap-able list region (summary + table + pagination) for current filters. Called on every filter change. |
| `lrob_etk_cf_submissions_bulk` | Spam / unspam / delete a list of submission IDs. Delete cascades to `FileRepository`. |
| `lrob_etk_cf_submissions_detail` | Render the detail body HTML for a single submission (for the in-page modal). Returns `{id, status, title, html}`. |
| `lrob_etk_cf_submissions_purge` | Manual one-shot cleanup: delete submissions older than N days, optionally filtered by status list. |

---

## Email action handlers — `EmailActions.php`

`admin_post.php` handlers for the Spam / Delete buttons embedded in notification emails.

- **Spam/Delete** require a POST (not GET) and a per-submission nonce. Bare GETs are bounced to the confirmation page (`?view=submissions&action=spam-confirm|delete-confirm&id=N`).
- **Unspam** accepts GET (non-destructive) but still requires nonce + cap.
- Confirmation page is rendered by `SubmissionsPage::render_confirm()` — it shows a submission summary and a form that POSTs to the actual action URL.

Action constants: `lrob_etk_cf_email_spam`, `lrob_etk_cf_email_unspam`, `lrob_etk_cf_email_delete`.

---

## Admin JS

### `admin/js/contact-form-admin.js`

Drives per-form cards. Key behaviors:
- Delegates autosave to `window.lrobEtkAutosave.attach()` (shared helper) with a `readValue` hook that converts rate-limit window minutes → seconds before posting.
- Wires free-text comboboxes (Subject template, Success message) via `window.lrobEtkControls.attachCombobox()`.
- Wires recipient-list UI: stacked email rows serialize into a hidden comma-separated mirror input that drives the autosave. Chevron menu pops known admin emails from `lrobEtkCfAdmin.knownEmails`.
- Live-updates the WYSIWYG editor preview's `lrob-etk-form-preset--*` class when the style preset combobox changes.
- Tracks `_lrob_etk_cf_save_submissions` tri-state and flips `data-save-effective-off` on the card so CSS can warn under `file_upload` fields when persistence is off.

Config global: `window.lrobEtkCfAdmin` (`ajaxUrl`, `nonce`, `action`, `actionDefault`, `knownEmails`, `i18n`).

### `admin/js/contact-form-new-picker.js`

Drives the "New form" picker modal. Listens for clicks on `.lrob-etk-cf-picker-card` elements (each carries `data-source`, `data-slug`, and/or `data-form-id`). POSTs to `lrob_etk_cf_create_form`, then navigates to `pageUrl#form-<id>` and reloads. ESC closes.

Config global: `window.lrobEtkCfNewPicker` (`ajaxUrl`, `action`, `nonce`, `pageUrl`).

### `admin/js/contact-form-submissions-inbox.js`

Drives the Submissions inbox page. Two surfaces:

1. **Live filter** — delegates to `window.lrobEtkListFilter.attach()` (shared helper) for debounced AJAX list-region swaps and `history.replaceState` URL sync.

2. **Bulk + row actions** — document-level event delegation (the list region is replaced on every AJAX swap, so per-element listeners would die). Spam/Delete show a lazily-built `lrob-etk-confirm-modal` before firing. Unspam fires immediately (non-destructive).

3. **Detail modal** — a lazily-built `lrob-etk-detail-modal` populated via `lrob_etk_cf_submissions_detail` AJAX. Prev/next navigate the currently-visible row IDs. Arrow keys navigate while open. Spam/Delete/Restore buttons in the header reflect the submission's current status. `DATA.autoOpen` causes the modal to open immediately on page load (used by direct-link navigation from email buttons or the dashboard).

Config global: `window.lrobEtkCfInbox` (`ajaxUrl`, `nonce`, `actionFilter`, `actionBulk`, `actionDetail`, `autoOpen`, `i18n`).

---

## Retention cron — `SubmissionsRetentionCron.php`

Hook: `lrob_etk_cf_submissions_purge` (daily, offset +1 hour at schedule time). Purges submissions per-status using the per-status retention windows from `Settings`. 0 days = disabled (kept forever). Also calls `FileRepository::delete_by_submission()` for each row before deletion so disk files don't outlive their database rows. Fires `lrob_etk_cf_submissions_purged` action after any deletion.

---

## Events dispatched

From `SubmitHandler` via `Support\Events::dispatch()`:

| Event | When |
|---|---|
| `contact_form.spam_blocked` | Honeypot, time-trap, rate-limit, or captcha failure. Payload: `{form_id, reason}` |
| `contact_form.submitted` | After passing all anti-spam checks and inserting the submission row. Payload: `{form_id, submission_id, fields}` |
| `contact_form.delivered` | After successful `wp_mail` send. Payload: `{form_id, submission_id}` |

---

## Cross-references

- **Form structure + field types:** `docs/form-builder.md`
- **Captcha routing, providers, challenges:** `docs/captcha.md`
- **Email logging + IMAP save:** `docs/logging.md` (submissions cross-link via `log_id`)
- **Admin UI tokens, shared JS helpers, CSS primitives:** `docs/admin-ui.md`
- **Naming prefixes, module lifecycle, AJAX conventions:** `CLAUDE.md`
