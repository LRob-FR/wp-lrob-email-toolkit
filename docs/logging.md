# Logging module

> Loaded on demand, **not** part of the always-in-context `CLAUDE.md`. Pointed to from `CLAUDE.md` → "Code reference docs". Covers the Logging module's schema, data model, repository, capture lifecycle, resend mechanics, attachment store, retention, and admin UI. Keep in sync when this subsystem changes.

## Overview

The Logging module (`src/Modules/Logging/`) captures every outgoing email by hooking deep into PHPMailer's pipeline. Admin page is always registered (browsable even when the module is disabled, as long as rows exist). The `wp_mail`/`phpmailer_init`/`wp_mail_failed` hooks only fire when the module is enabled.

Slug: `logging`. Module version: `0.0.1`. Schema version: `2`.

## Schema (`Schema.php`)

Table: `{prefix}lrob_etk_logs`. Managed by `Schema::install()` / `Schema::drop()`. `Schema::install()` is idempotent — it short-circuits via the `lrob_etk_logging_db_version` option (current value `'2'`).

### Columns

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint unsigned` PK | auto-increment |
| `created_at` | `datetime` NOT NULL | insert time; UTC string (see timezone note below) |
| `sent_at` | `datetime` NULL | set by `update_status()` on `STATUS_SENT` |
| `status` | `varchar(20)` | `sending` / `sent` / `failed` / `retried` |
| `source` | `varchar(50)` | `unknown` / `contact_form` / `newsletter` / `newsletter_test` / SMTP identity name |
| `identity_id` | `bigint unsigned` NULL | FK → SMTP identity (not enforced) |
| `newsletter_id` | `bigint unsigned` NULL | FK → newsletter CPT post ID |
| `recipient_id` | `bigint unsigned` NULL | FK → `lrob_etk_nl_recipients.id` |
| `from_email` | `varchar(190)` | |
| `from_name` | `varchar(190)` NULL | |
| `to_emails` | `text` | JSON array of address strings |
| `cc_emails` | `text` NULL | JSON array; NULL when empty |
| `bcc_emails` | `text` NULL | JSON array; NULL when empty |
| `reply_to` | `varchar(190)` NULL | first reply-to address only |
| `subject` | `varchar(500)` | |
| `body_html` | `longtext` NULL | |
| `body_text` | `longtext` NULL | |
| `headers` | `text` NULL | JSON array of `{name, value}` objects; NULL when empty |
| `attachments` | `text` NULL | JSON array of `{name, path}` objects; NULL when empty |
| `message_id` | `varchar(190)` NULL | PHPMailer `MessageID` |
| `error_message` | `text` NULL | populated on failure |
| `retry_count` | `int` | currently always 0 — tracked for future use |

### Indexes

`status_created (status, created_at)`, `source (source)`, `created_at (created_at)`, `newsletter_id (newsletter_id)`, `identity_id (identity_id)`.

### Schema versions

- **v1** — initial install with placeholder columns `newsletter_id` (then named `campaign_id`), `recipient_id`, `message_id` for future modules.
- **v2** — renamed `campaign_id` → `newsletter_id` to align with the Newsletter module's vocabulary. `dbDelta` cannot rename columns, so `install()` runs an `ALTER TABLE … CHANGE` first, then drops the old index name, then lets `dbDelta` recreate cleanly. The rename is data-safe (the column was reserved, never written).

### Timezone note

`created_at` is stored as a UTC datetime string (`Y-m-d H:i:s`). **Do not use `UNIX_TIMESTAMP()`** — it interprets DATETIME in the session timezone, which is unstable across hosts and DST. All time-bucket queries use the `TIMESTAMPDIFF(SECOND, '2000-01-01 00:00:00', created_at)` + epoch-anchor pattern instead. See CLAUDE.md → "SQL + timezone" and `LogRepository::counts_by_bucket()`.

---

## `LogEntry` — the data model (`LogEntry.php`)

Immutable value object. Constructed from a DB row, from a live PHPMailer instance, or directly.

### Status constants and display helpers

| Constant | Value | `status_class()` |
|---|---|---|
| `STATUS_SENDING` | `'sending'` | `lrob-etk-status--pending` |
| `STATUS_SENT` | `'sent'` | `lrob-etk-status--on` |
| `STATUS_FAILED` | `'failed'` | `lrob-etk-status--fail` |
| `STATUS_RETRIED` | `'retried'` | `lrob-etk-status--off` |

`status_label()` returns the translated string. `status_class()` returns the `.lrob-etk-status` modifier class (see `admin-components.css`).

### Construction paths

**`from_phpmailer(PHPMailer $mailer)`** — called from `Logger::log_outgoing()` during `phpmailer_init`. Sets `status = STATUS_SENDING`, `id = null`, `source = 'unknown'`, `identity_id = null`. Logger applies overrides via `with()` immediately after.

PHPMailer attachment row layout (indices): `[0]` content/path, `[1]` name, `[2]` encoded_name, `[3]` encoding, `[4]` type, `[5]` isString flag, `[6]` disposition, `[7]` cid. Only file-path attachments (`isString === false`) get a non-null `path`.

**`from_row(array $row)`** — called by `LogRepository` on every DB read. JSON columns are decoded via `decode_array()`. Attachments pass through `normalize_attachments()`.

**`normalize_attachments()`** — legacy upgrade shim: old rows stored plain filename strings in the JSON array; new rows store `{name, path}` objects. Both formats are accepted transparently on read. This runs on every `from_row()` call.

**`with(array $changes)`** — immutable update (returns new instance). Merges over `to_array()`.

### Array column encoding

- `to_emails`: always a JSON array (even for a single recipient).
- `cc_emails`, `bcc_emails`, `headers`, `attachments`: stored as JSON array; NULL in the DB when empty; decoded to `[]` by `from_row()`.
- `headers`: each element is `{name: string, value: string}`.
- `attachments`: each element is `{name: string, path: string|null}`. `path` is null for inline-string attachments (rare in `wp_mail` flows).

---

## `LogRepository` — persistence (`LogRepository.php`)

CRUD layer. Handles JSON encode/decode for array-valued columns so all callers see PHP arrays.

### Key methods

**`insert(LogEntry $entry): int`** — returns the new `id`. Timestamps (`created_at`, `sent_at`) are formatted to `Y-m-d H:i:s` by the repository, not by the caller.

**`update_status(int $id, string $status, ?string $error)`** — updates only `status` (+ `sent_at` = `current_time('mysql', true)` when transitioning to `STATUS_SENT`, + `error_message` when non-null). No full-row rewrite.

**`find(int $id): ?LogEntry`** — single row by PK.

**`count(array $filters): int`** / **`paginate(array $filters, int $page, int $per_page): array`** — share `build_where()`. `per_page` is clamped to 1–200.

**`delete(int $id)`** / **`bulk_delete(array $ids): int`** — purge managed attachment files before removing rows (calls `purge_attachments_for_ids()`). Returns rows deleted for bulk.

**`delete_older_than(\DateTimeImmutable $cutoff): int`** — used by both `RetentionCron` and the manual-cleanup AJAX. Fetches attachment JSON for affected rows first, then deletes files, then `DELETE … WHERE created_at < %s`.

### Filter shape (`build_where`)

Accepted keys: `status`, `source`, `search`, `date_from`, `date_to`, `newsletter_id`, `newsletter_mode`, `orderby`, `order`.

Newsletter visibility default is **exclude** (`newsletter_id IS NULL`) so the main logs view stays clean. The filter bar exposes a tri-state: `exclude` (default) / `include` (all rows) / `only` (newsletter rows only). An explicit `newsletter_id` overrides the mode.

`orderby` is whitelisted in `sanitize_orderby()` to `['id', 'created_at', 'status', 'from_email', 'to_emails', 'subject', 'source']`. Unknown values fall back to `id`. The value is interpolated into `ORDER BY` (SQL injection prevention — do not remove the whitelist).

### Aggregation queries

**`counts_by_day(\DateTimeImmutable $from, \DateTimeImmutable $to)`** — groups by `DATE(created_at)` and status. Returns a map keyed `Y-m-d` with zero-filled buckets for days with no activity.

**`counts_by_bucket(\DateTimeImmutable $from, \DateTimeImmutable $to, int $bucket_seconds)`** — general-purpose time-bucket aggregation for the dashboard activity chart. Uses:

```sql
FLOOR(TIMESTAMPDIFF(SECOND, '2000-01-01 00:00:00', created_at) / %d) * %d + 946684800 AS bucket_ts
```

`946684800` is the UTC epoch of `2000-01-01 00:00:00` — the fixed anchor. This avoids `UNIX_TIMESTAMP()` instability. Returns an array of `{ts, sent, failed}` entries, chronological, zero-filled. `bucket_seconds` is floored to 60.

**`log_ids_for_newsletter_recipients(int $newsletter_id, array $recipient_ids)`** — cross-link from the Newsletter recipients drawer to the Logs page. Returns a map of `recipient_id → log_id` (newest log per recipient, via `ORDER BY id DESC`). In practice only failed rows are present because `Logger` deletes success rows for newsletter sends by default.

**`distinct_sources()`** — powers the Source filter dropdown in the logs page.

### Attachment purge chain

When any delete path is triggered: `purge_attachments_for_ids(ids)` → reads `attachments` JSON for those rows → for each `{path}` entry, calls `AttachmentStore::delete(path)`. `AttachmentStore::delete()` is a no-op unless `is_managed()` returns true (guards against deleting unrelated WP temp files).

---

## `Logger` — capture lifecycle (`Logger.php`)

Hooks: `lrob_etk_email_sending` (priority default), `phpmailer_init` (priority **999**), `wp_mail_succeeded`, `wp_mail_failed`.

The high priority on `phpmailer_init` (999) ensures it runs after the SMTP module's `MailRouter` (priority 9), so the PHPMailer object is fully configured before snapshot.

### SMTP context injection

The SMTP module's `MailRouter` fires the `lrob_etk_email_sending` event before `phpmailer_init`. Logger captures the payload (`source`, `identity_id`, `save_attachments`) in `$pending_sending_event` and applies it in `log_outgoing()`. If the SMTP module is disabled, source defaults to `'unknown'` and identity stays null.

### Newsletter integration

The Newsletter module's `SendLoop` injects three custom headers on every send:

| Header constant | Purpose |
|---|---|
| `Logger::HEADER_NEWSLETTER_ID` (`X-Lrob-Etk-Newsletter-ID`) | newsletter CPT post ID |
| `Logger::HEADER_NEWSLETTER_RECIPIENT_ID` (`X-Lrob-Etk-Newsletter-Recipient-ID`) | `newsletter_recipients.id` |
| `Logger::HEADER_NEWSLETTER_TEST` (`X-Lrob-Etk-Newsletter-Test`) | marks a test send |

`extract_newsletter_headers()` promotes these to `newsletter_id`, `recipient_id` columns and sets `source = 'newsletter'` / `'newsletter_test'`.

### Success-suppression rule

Default for newsletter sends: delete the log row on success (`should_suppress_success_log()`). Failures always update normally. Rationale: send results already live in `newsletter_recipients`; duplicating millions of success rows in the global logs table is wasteful. Per-newsletter opt-in: set `_lrob_etk_nl_log_all_sends = '1'` post meta to keep all rows including successes. Test sends (`is_test = true`) follow the same rule.

### Error isolation

`log_outgoing()` wraps everything in `try/catch(\Throwable)`. A logging failure must never break `wp_mail`. Errors are written to `error_log` with the `[lrob-etk]` prefix.

### Attachment persistence

When `$pending_sending_event['save_attachments']` is set (controlled by SMTP identity setting), `persist_attachments()` copies each file-path attachment into `AttachmentStore` and rewrites the entry's paths to the copies before insert. Inline attachments (no path) and copy failures fall back to the original.

---

## `Resender` — retry mechanics (`Resender.php`)

Reconstructs `wp_mail()` arguments from a stored `LogEntry` and re-sends. The original row is **never mutated** — it represents a distinct historical send event. The retry creates its own new log row via the normal `Logger` pipeline.

### Attachment handling

Each attachment is re-attached only if `$a['path']` is non-null, `is_file()`, and `is_readable()`. Missing or inaccessible files are silently dropped; the response reports `attachments_dropped = true` and the counts `attachments_total` / `attachments_sent`. Inline-string attachments (path = null) are never re-attachable.

### `build_headers()`

Reconstructs the header array for `wp_mail()`. Fixed headers emitted: `Content-Type: text/html; charset=UTF-8` (when HTML body), `From:`, `Reply-To:`, `Cc:` and `Bcc:` (one header per address). Then re-emits all custom headers from the stored `headers` array, skipping any whose name matches the already-emitted set (`content-type`, `from`, `reply-to`, `cc`, `bcc`) to avoid duplication.

### `strip_crlf()`

Strips `\r` / `\n` from every value embedded into a header. PHPMailer normalises on the way out, but future mail-receive features could introduce attacker-controlled data into log columns — this closes the header-injection vector before the data ships.

---

## `AttachmentStore` — durable file storage (`AttachmentStore.php`)

Stores copies of outbound email attachments so they can be re-attached on resend. Opt-in per SMTP identity ("Save attachments locally" toggle). `wp_mail` attachments are typically transient temp files gone after the request.

**Root:** `{uploads_basedir}/lrob-etk-logs/` (constant `ROOT_DIR = 'lrob-etk-logs'`).

**Path shape:** `<root>/<YYYY>/<MM>/<hex8>_<sanitized_name>`. The 4-byte random prefix prevents collisions and avoids predictable URLs.

**Security:** On first write, `ensure_htaccess()` writes a `.htaccess` at the store root: `Options -Indexes`, `Require all denied` (Apache 2.4+), `Order allow,deny / Deny from all` (Apache 2.2), PHP engine disabled for `mod_php` 5/7/8. An empty `index.html` is also written. Files are chmod'd `0640`.

**`is_managed(string $abs_path): bool`** — returns true when the path starts with `root_dir() . '/'` or `root_dir() . DIRECTORY_SEPARATOR`. The repository uses this as a gate before deleting any file, preventing accidental deletion of files outside the store (e.g. transient `wp_mail` paths).

**`delete(string $abs_path)`** — no-op unless `is_managed()`. Then `@unlink`.

**`persist(string $source_abs, string $original_name): ?string`** — copies the source file into the dated subdirectory. Returns the absolute destination path, or `null` on any failure (uploads error, mkdir failure, copy failure). Caller falls back to the original source path on null.

---

## `RetentionCron` — automated cleanup (`RetentionCron.php`)

Daily WP-cron event that deletes log entries older than the configured retention period.

| Item | Value |
|---|---|
| Hook | `lrob_etk_logging_purge` (prefixed so `Deactivator`'s prefix-scan catches it on deactivate) |
| Option | `lrob_etk_logging_retention_days` |
| Default | `365` days |
| Disable | Set option to `0` — `run()` returns early |

`schedule()` uses `wp_schedule_event` with `'daily'` recurrence, deferred by 1 hour from now. It is a no-op if already scheduled. `unschedule()` calls `wp_clear_scheduled_hook`.

On completion, fires `do_action('lrob_etk_logging_purged', $deleted, $days)` when rows were actually deleted.

`Module::install()` calls `schedule()` (idempotent). `Module::uninstall()` calls `unschedule()` then `Schema::drop()`.

---

## `Module` — lifecycle (`Module.php`)

### `db_version_int() = 2`

Returns `2` (not the AbstractModule default of `1`). The bump was required because the initial install recorded `db_version = 1` via AbstractModule before `Schema::install()` set up the v1→v2 rename. Without this bump, `maybe_migrate()` would short-circuit and the `campaign_id → newsletter_id` ALTER would never fire on already-installed sites.

### `migrate()` forwards to `install()`

Both `Schema::install()` and `wp_schedule_event` are idempotent, so forwarding from `migrate()` to `install()` is always safe. This follows the service-module migrate trap pattern documented in CLAUDE.md → "Service-module migrate trap" (Logging is not a service module, but the same pattern applies for robustness).

### Container registration

`LogRepository` is always registered in the container (`container->set(LogRepository::class, ...)`). `Logger` is only registered when the module is enabled (no capture without logging turned on).

---

## Admin controllers

### `PageController` (`Admin/PageController.php`)

Registers the `lrob-etk-logs` submenu page. Two `admin-post.php` actions:

- `lrob_etk_logging_save_settings` — saves `retention_days` option, flashes success.
- `lrob_etk_logging_purge_manual` — "older than N days" or "all". Both paths use `delete_older_than()`.

Flash messages are stored as user-scoped transients (60 s TTL): `lrob_etk_logging_flash_{notice|errors}_{user_id}`. `pop_flash()` reads and deletes in one call.

### `AjaxController` (`Admin/AjaxController.php`)

All endpoints share nonce `lrob_etk_logging_ajax`, gated by `manage_lrob_etk` + `check_ajax_referer`.

| Action constant | Handler | Purpose |
|---|---|---|
| `ACTION_DELETE` | `ajax_delete` | Delete single row (purges attachments) |
| `ACTION_BULK_DELETE` | `ajax_bulk_delete` | Delete selected rows |
| `ACTION_PURGE` | `ajax_purge` | One-shot cleanup: `older_than` or `all` |
| `ACTION_RESEND` | `ajax_resend` | Trigger `Resender::resend()`, report attachment counts |
| `ACTION_SAVE_SETTING` | `ajax_save_setting` | Per-key autosave for the Storage modal card |
| `ACTION_LIST_FILTER` | `ajax_list_filter` | Re-render the swappable list region |
| `ACTION_DETAIL` | `ajax_detail` | Return `{id, status, title, html}` for the detail modal |

`ajax_save_setting` routes on `key` through a whitelist switch — currently only `retention_days` (0–3650 days). Unknown keys return 400.

`ajax_list_filter` delegates to `LogsPage::render_list_region_for_filters()` and wraps the output as `{html: ...}`. Mirrors the Contact Form submissions inbox endpoint.

`ajax_resend` reports attachment drop details in the success message when the original entry had attachments.

### `LogsPage` (`Admin/LogsPage.php`)

Custom table page — no `WP_List_Table`. Components: filter bar, bulk toolbar, table, pagination, Storage modal.

**Constructor** accepts a nullable `?ModuleInterface $module`. The AJAX list-filter endpoint instantiates `LogsPage` with `null` (needs only `render_list_region_for_filters()`); `render()` requires a non-null module.

**Newsletter row visibility**: the log count for the "disabled and empty" gate uses `newsletter_mode = 'include'` so sites with only newsletter-failure logs don't falsely show the disabled-state message.

**`parse_filters(?array $source)`** — shared by initial page load (reads `$_GET`) and the AJAX filter endpoint (receives `$_POST`). `date_from`/`date_to` are expanded to full timestamps (`00:00:00` / `23:59:59`). `orderby` is passed through (sanitized later by `LogRepository::sanitize_orderby()`).

**`submission_link_map()`** — batched lookup of log_id → submission_id via `ContactFormSubmissions::submission_ids_for_log_ids()`. Returns `[]` if the Contact Form module is not installed. Used to render the "View submission" icon in table rows.

**Storage modal** (inline HTML in `render_storage_modal()`): single card with `RetentionToggle` for automatic retention + a destructive one-shot manual-cleanup section. Per-page picker is rendered inline above the table by `Admin\PerPagePicker` (session-cookie persisted) — not surfaced as an admin-level setting in the modal.

**Inline JS** (`print_inline_js()`): sets `window.lrobEtkLogs` with `ajaxUrl`, `nonce`, `autoOpenId`, `actions`, `i18n`. Wires document-level delegation for: select-all checkbox, bulk apply, per-row delete/resend, detail-modal open. Uses `window.lrobEtkListFilter`, `window.lrobEtkDetailModal`, `window.lrobEtkModal`, `window.lrobEtkAutosave` (all footer-enqueued shared helpers) — deferred via `whenReady()`.

Direct links (`?detail=N` — from the dashboard, email "View" links, newsletter cross-links) land on the list page and auto-open the detail modal for that entry via `autoOpenId`.

### `LogDetailRenderer` (`Admin/LogDetailRenderer.php`)

Renders the body markup for the in-page detail modal. Consumed by `AjaxController::ajax_detail()`.

Output: error banner (if `error_message` set) → metadata grid (`created_at`, `sent_at`, status pill, source, identity, from, to/cc/bcc, reply-to, attachments) → collapsible custom headers table → HTML body in a sandboxed `<iframe srcdoc>` + plain-text body in `<pre>`.

**Attachment display** (`render_attachments_html()`): each attachment shows its filename + a status chip: `(no path — inline content)` / `(file missing — won't re-send)` / `(still on disk)`. The "still on disk" check uses `is_file($path)` at render time.

HTML body is rendered in `<iframe sandbox="">` (empty sandbox = no scripts, no same-origin, no forms, no popups) to isolate arbitrary email HTML from the admin page.
