# SMTP module

> Loaded on demand, **not** part of the always-in-context `CLAUDE.md`. Pointed to from `CLAUDE.md` → "Architecture". Keep in sync when this subsystem changes.

Routes `wp_mail()` through configured SMTP identities. Hooks standard WordPress PHPMailer filters; dispatches the plugin's `email.*` events. See also `CLAUDE.md` → Architecture (Container, Encryption, Events) and `docs/logging.md` for how `email.sending` / `email.sent` / `email.failed` are consumed.

---

## File map

| File | Responsibility |
|---|---|
| `Module.php` | Lifecycle: `register()` wires services into Container + hooks; `install()` / `migrate()` delegate to `Schema`. Admin pages always register (toggle/CTA UX). `MailRouter` only wired when module is enabled. |
| `Schema.php` | `wp_lrob_etk_identities` DDL via `dbDelta`. Owns v2→v3 migration: replaced `force_from` tinyint with `override_mode` varchar. |
| `Identity.php` | Immutable value object. Loaded from DB, applied to PHPMailer, displayed in admin. Mutation goes through `with()` clones. |
| `IdentityRepository.php` | CRUD against `wp_lrob_etk_identities`. Owns the encryption boundary: callers deal in plaintext; only this class calls `Encryption::encrypt/decrypt` at persist time. |
| `MailRouter.php` | Hooks `wp_mail` lifecycle; resolves identity per call; dispatches `email.*` events. |
| `RoutingRules.php` | Persists the source→identity-slug map in `lrob_etk_smtp_routing`. |
| `SourceResolver.php` | Determines which "source" the current `wp_mail()` belongs to. |
| `ConstantOverrides.php` | Applies `wp-config.php` constants over the default identity at runtime. |
| `AuthTester.php` | Tests SMTP connection (TLS handshake + AUTH) without sending a message. |
| `TestSender.php` | Sends a one-off test email through a specific identity. |
| `DnsLookup.php` | Cached A/AAAA and MX queries for the host picker in admin. |
| `Admin/PageController.php` | Registers the `lrob-etk-smtp` submenu; delegates render to `SettingsPage`. |
| `Admin/AjaxController.php` | Eight `wp_ajax_*` endpoints for identity CRUD, routing, DNS, and test operations. |
| `Admin/SettingsPage.php` | Renders identity cards + routing section + inline JS driver. |

---

## Identities (`Identity`, `IdentityRepository`, `Schema`)

### Schema — `wp_lrob_etk_identities`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `slug` | varchar(50) UNIQUE | auto-derived from label; URL-safe |
| `label` | varchar(190) | display name |
| `transport` | varchar(10) | `smtp` or `mail` |
| `from_email` | varchar(190) | empty = automatic (falls back to `smtp_username`) |
| `from_name` | varchar(190) | empty = automatic (falls back to site title, then `from_email`) |
| `smtp_host` | varchar(190) | |
| `smtp_port` | smallint unsigned | default 465 |
| `smtp_encryption` | varchar(10) | `''` / `ssl` / `tls` |
| `smtp_username` | varchar(190) | |
| `smtp_password_encrypted` | text | AES-256-GCM ciphertext (see Encryption below) |
| `smtp_auth` | tinyint(1) | |
| `override_mode` | varchar(20) | `never` / `when_default` / `always` |
| `reply_to_email` | varchar(190) NULL | |
| `is_default` | tinyint(1) | only one row should have `1`; enforced by `set_default()` transaction |
| `is_active` | tinyint(1) | inactive identities are skipped at send time |
| `save_attachments` | tinyint(1) | tells Logging module to keep attachment copies; consumed via `email.sending` event payload |
| `created_at` / `updated_at` | datetime | UTC |

**Schema migration (v2 → v3):** `dbDelta` added `override_mode`; the `force_from` column is then dropped and values mapped: `1 → 'always'`, `0 → 'never'`. `install()` is idempotent — the `information_schema` check makes repeated runs safe. `Module::db_version_int()` returns 4; `migrate()` forwards to `install()` so upgrading sites always converge.

### Password encryption

Passwords are stored as AES-256-GCM ciphertext derived from `AUTH_KEY` (see `CLAUDE.md` → Encryption). The repository encrypts on `save($identity, $plain_password)`:

- `null` → keep existing ciphertext (editing without retyping)
- `''` → clear the password
- non-empty string → encrypt and store

`Identity::decrypted_password()` materialises the plaintext on demand. It throws `RuntimeException` if `AUTH_KEY` is missing or has changed since encryption (old ciphertexts are then unrecoverable). The admin shows an error banner when `Encryption::is_available()` returns false.

### Automatic From fields

`effective_from_email()`: returns `from_email` if set, otherwise `smtp_username`.  
`effective_from_name()`: returns `from_name` if set, otherwise site title (`get_bloginfo('name')`), otherwise `effective_from_email()`.

The admin UI shows smart placeholders reflecting these fallbacks so the admin sees what "auto" resolves to without a separate mode toggle.

### `override_mode`

Controls how aggressively the identity's From address wins against a caller-set From header:

| Constant | Value | Behaviour |
|---|---|---|
| `OVERRIDE_NEVER` | `never` | never override; caller's value passes through |
| `OVERRIDE_WHEN_DEFAULT` | `when_default` | override only when caller did not explicitly set a `From:` header (WP's auto-generated `wordpress@hostname` counts as "no caller-set From") |
| `OVERRIDE_ALWAYS` | `always` | unconditionally override (legacy `force_from=true` behaviour) |

`Identity::from_row()` accepts both the old `force_from` tinyint and the current `override_mode` string so serialized backups / in-memory rows from pre-v3 still load.

### `save_attachments`

Flagged per identity. The value is forwarded in the `email.sending` event payload so the Logging module can decide whether to copy attachments to disk. New identities default to `true`; rows migrated from pre-feature versions default to `false` to avoid surprises on upgrade.

### Identity uniqueness

Slug has a UNIQUE key. Future intent (noted in project memory): one mailbox = one identity, unique on `(host, username)`. Not yet enforced at the DB layer.

---

## Routing (`RoutingRules`, `SourceResolver`)

### Sources

A "source" is an open-ended string. Built-ins:

| Constant | Value | How set |
|---|---|---|
| `SOURCE_DEFAULT` | `default` | fallback when nothing else matches |
| `SOURCE_NEWSLETTER` | `newsletter` | `SourceResolver::with('newsletter', fn)` used by Newsletter module |
| `SOURCE_CONTACT_FORM` | `contact_form` | `SourceResolver::with('contact_form', fn)` used by ContactForm module |
| `SOURCE_WOOCOMMERCE` | `woocommerce` | pushed by `wrap_woocommerce_callback` wrapper on `woocommerce_mail_callback` filter |

Resolution order:
1. Most recently pushed explicit source (via `push()` / `pop()` stack), if any.
2. Auto-detection: checks `doing_action('woocommerce_mail_callback')`.
3. `'default'`.

Then the value is run through `apply_filters('lrob_etk_smtp_source', $source)`.

Third-party code can introduce new sources by pushing onto the stack or filtering `lrob_etk_smtp_source`. Always pair `push()` with `pop()` in a `try/finally`; or use the `SourceResolver::with($source, $callback)` helper.

### Routing rules

Stored in `lrob_etk_smtp_routing` option as `['source_key' => 'identity_slug', ...]`.

`RoutingRules::resolve($source)` falls back through:
1. Slug mapped for the given source.
2. Slug mapped for `SOURCE_DEFAULT`.
3. The identity marked `is_default=1` (if active).

Returns `null` when no usable identity exists — `MailRouter` lets WordPress fall through to its native `mail()` transport.

The routing section only renders in the admin when there are ≥ 2 identities. Sources surfaced in the admin are those returned by `SettingsPage::known_sources()`, which also calls `apply_filters('lrob_etk_smtp_known_sources', $sources)` — third-party code can add rows.

---

## MailRouter

Hooks (all registered in `register()`):

| Hook | Priority | Purpose |
|---|---|---|
| `wp_mail` (filter) | 1 | `capture_caller_from()` — sniffs headers for an explicit `From:` line before per-call filters run |
| `phpmailer_init` (action) | 9 | `configure_mailer()` — sets SMTP credentials + `setFrom` if `should_override_sender()` |
| `wp_mail_from` (filter) | 9 | `override_from_email()` — same gate |
| `wp_mail_from_name` (filter) | 9 | `override_from_name()` — same gate |
| `wp_mail_succeeded` (action) | — | dispatches `email.sent`; resets per-call state |
| `wp_mail_failed` (action) | — | dispatches `email.failed`; resets per-call state |
| `woocommerce_mail_callback` (filter) | — | wraps the callback to push `SOURCE_WOOCOMMERCE` for the duration |

### setFrom gate pair (invariant)

**Both** `configure_mailer`'s `setFrom` call **and** the `wp_mail_from` / `wp_mail_from_name` filters check `should_override_sender()`. Without both checks, `configure_mailer` would stomp whatever `wp_mail_from` returned, regardless of `override_mode`. This must remain a matched pair.

### Identity resolution (per call)

Resolution is lazy and cached for the duration of one `wp_mail()` call (reset by `on_succeeded` / `on_failed`):

1. If `forced_identity_slug` is set (see `force_identity()`), find that identity and apply constant overrides.
2. Otherwise: `SourceResolver::resolve()` → `RoutingRules::resolve(source)` → `ConstantOverrides::apply()`.

### `force_identity(?string $slug)`

Bypasses routing for the very next `wp_mail()` resolution. Used by the admin "Test email" flow so the test exercises a specific identity that may not be the default. Pass `null` to clear.

### Events dispatched

`email.sending` — fired in `configure_mailer()` with `{identity_id, identity_slug, source, transport, save_attachments}`.  
`email.sent` — fired in `on_succeeded()` with `{identity_id, identity_slug, to, subject, source}`.  
`email.failed` — fired in `on_failed()` with `{identity_id, identity_slug, error_code, error_message, to, subject, source}`.

---

## ConstantOverrides

Applies `wp-config.php` constants to the **default identity only** at runtime. The DB row is untouched; a `with()` clone is returned for use at send time.

| Constant | Identity field |
|---|---|
| `LROB_ETK_SMTP_HOST` | `smtp_host` |
| `LROB_ETK_SMTP_PORT` | `smtp_port` |
| `LROB_ETK_SMTP_ENCRYPTION` | `smtp_encryption` |
| `LROB_ETK_SMTP_USER` | `smtp_username` |
| `LROB_ETK_SMTP_PASS` | `smtp_password_encrypted` (re-encrypted in memory only) |
| `LROB_ETK_SMTP_AUTH` | `smtp_auth` |
| `LROB_ETK_SMTP_FROM` | `from_email` |
| `LROB_ETK_SMTP_FROM_NAME` | `from_name` |

Only the default identity is overridable. Per-slug constants (e.g. `LROB_ETK_SMTP_HOST_NEWSLETTER`) are a straightforward future extension.

`overridden_fields()` returns the list of currently-overridden field names; the admin UI renders a lock tooltip next to those fields.

---

## AuthTester

Tests the SMTP connection without sending a message: performs the TLS handshake, EHLO, and AUTH exchange via PHPMailer's `smtpConnect()`, then sends QUIT and closes cleanly. Accepts either a saved `Identity` (password read from DB) or a transient one built from pending form values (so the admin can test before saving). Returns `{ok: bool, message: string, debug: ?string}`.

Timeout: 10 seconds. Debug output is captured and the last 6 non-empty lines returned on failure.

---

## TestSender

Sends a one-off test email through a specific identity. Registers its own `phpmailer_init` hook at priority 100 (after `MailRouter`'s 9) for the duration of the call, then removes it — so test sends work whether or not the module is enabled. A `wp_mail_failed` hook at priority 1 captures the `WP_Error` so the actual error message is surfaced (since `wp_mail()` only returns bool).

---

## DnsLookup

Used by the admin host picker:

- `resolves($host)` — checks A/AAAA records.
- `mx_records($domain)` — returns `[{host, priority}]` sorted by preference, deduped.

Each result cached as a WordPress transient for 1 hour. Per-user soft rate limit: 60 calls per hour (transient counter keyed by `user_id`).

---

## AJAX endpoints (AjaxController)

All endpoints require `manage_lrob_etk` capability + nonce `lrob_etk_smtp_ajax` (sent as `_nonce`). Destructive operations additionally require a per-action nonce sent as `_action_nonce`.

| Action constant | WP action string | Destructive nonce? |
|---|---|---|
| `ACTION_SAVE` | `lrob_etk_smtp_save_identity` | no |
| `ACTION_DELETE` | `lrob_etk_smtp_delete_identity` | yes |
| `ACTION_SET_DEFAULT` | `lrob_etk_smtp_set_default` | yes |
| `ACTION_TEST_AUTH` | `lrob_etk_smtp_test_auth` | no |
| `ACTION_TEST_SEND` | `lrob_etk_smtp_test_send` | no |
| `ACTION_SAVE_ROUTING` | `lrob_etk_smtp_save_routing` | yes |
| `ACTION_CHECK_HOSTS` | `lrob_etk_smtp_check_hosts` | no |

### `ajax_save` notes

- `id=0` → insert; `id>0` → update.
- Password handling: form field empty + `smtp_password_clear` absent → keep existing ciphertext. `smtp_password_clear=1` → store `''`. Non-empty password → encrypt and store.
- `from_email` / `from_name` empty is valid ("automatic" mode).
- Auto-promotes to default if `is_default` checked or if it's the only identity.

### `ajax_test_auth` notes

Builds a transient `Identity` from the form's current values so the admin can test before saving. Does not apply `ConstantOverrides` (the form values are the intended test subject). Returns `{message, debug}`.

### `ajax_test_send` notes

Recipient choices: `current` (logged-in user), `admin` (site admin email), `custom` (free-text field). Delegates to `TestSender::send()`.

---

## Admin page (`SettingsPage`)

Rendered by `PageController::render()`. Inline JS is printed at the bottom of the page under `window.lrobEtkSmtp`.

Card layout (stacked full-width rows, no 2-column split):
1. Header — one row: Active toggle (no text label, `title` = Enable/Disable) · transport segmented (SMTP / mail()) · identity-name title (grows) · ghost trash **Delete** icon-btn far right (reddens on hover).
2. **Authentication required** toggle (heads the section); below it Username | Password (2-col `.lrob-etk-from-grid`). Auth off hides the credential fields and reclaims the space.
3. Server section (no separator line from the auth block above — they read as one block). Row 1: SMTP host, full width — the resolve badge (`.lrob-etk-host-status`, coloured dot + label) sits **inside** the field on the right and auto-hides after ~4s. Row 2: narrow **Encryption** + **Port** (left). Port is a plain text field (numeric inputmode, spinner arrows removed). Encryption change **always** rewrites the port (587/465/25). **Host resolution** is automatic — on mailbox blur + card init, `ajax_check_hosts(domain)` builds the candidate list (presets `mail.`/`smtp.`/bare domain **+** MX targets, deduped so an MX host isn't listed twice), resolves each, returns `{host, priority, resolves}`. The host dropdown renders each with a coloured status dot (green/red via `opt.mark` → `.lrob-etk-combo-mark` + `state--on/fail`) and an `(MX<priority>)` suffix for MX entries; the in-field badge mirrors the selected host. Non-blocking; `DnsLookup` caches + rate-limits.
4. From section (no title): From email + **Force** selector on row 1, From name + Reply-to on row 2 (2-col grid). The "what fills empty fields" hint moved onto the From-email label; `override_mode` is labelled **Force**.
5. Save-attachments toggle, then footer.

**Footer** = three zones: default badge / **Set as default** (left) · save-status (centered, the `.lrob-etk-card-status` lives here now) · actions (right): **Test connection** (SMTP-only, hidden for mail() via `.lrob-etk-smtp-only-inline`) + **Test email**.

**Connection test:** the Test-connection button runs `ajax_test_auth` and shows the result in the anchored popover immediately (success or failure) — the button stays neutral (transient spinner only), no lingering green/red/blue state.

Key UX:
- Cards are always-editable (no modal); changes auto-save on blur/change.
- `data-state="new"` cards are created in-page via `<template>` clone; a first save triggers a page reload so the card gets a real `data-identity-id`.
- Changing `is_default` also triggers a page reload (so the star badges refresh across all cards).
- Transport segmented control (`smtp` / `mail()`) hides the SMTP-only credential fields when `mail` is selected.
- Encryption `<select>` auto-fills the port to 587 / 465 / 25 when the user hasn't manually edited it.
- From-email mismatch warnings: domain mismatch (danger) or local-part mismatch (warning) vs the mailbox login.
- Connection test button shows a `lrob-etk-popover` with PHPMailer debug output on failure; auto-opens on failure, manual click on success.
- Routing section only appears when ≥ 2 identities exist.
- `ConstantOverrides::overridden_fields()` drives the lock-tooltip dots on overridden fields.

JS global: `window.lrobEtkSmtp` — `{ajaxUrl, nonce, actions, actionNonces, identities, siteTitle, i18n}`.
