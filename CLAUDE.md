# CLAUDE.md

Guidance for Claude Code sessions working in this repository.

**Project status & history → [completed.md](./completed.md). Backlog → [todo.md](./todo.md).** Read those when planning new work or claiming something's shipped.

**Keep these two files in sync as you work.** Whenever a feature lands or a backlog item gains/loses scope, update `todo.md` and `completed.md` in the same change — don't defer to "later". Stale entries (e.g. *working tree; pending release* on something already shipped, or a backlog bullet for something now in `completed.md`) are bugs in the docs.

## Project

WordPress plugin **LRob - Email Toolkit** (slug `lrob-email-toolkit`). Modular all-in-one email plugin. Each module (SMTP, Logging, Contact Form, Captcha, Newsletter, future Integrations) is independently activatable. Requires PHP 8.1+ and WordPress 6.0+.

## Build / lint / release

`./release.sh` is the single build entry point. **Run it yourself whenever needed.** Output is captured in one go — `./release.sh 2>&1 | tail -40` shows everything that matters. Steps:
- Lints every PHP file (`php -l`) and every JS file (`node --check`).
- Scans CSS for unreferenced `.lrob-etk-*` selectors (peel-once + 3-hyphen-min heuristic).
- Regenerates `languages/lrob-email-toolkit.pot` via `wp i18n make-pot`.
- `msgmerge`s POT into every `.po`, `msgattrib --no-obsolete` strips orphans.
- Compiles `.po` → `.mo` + `.json`; `msgfmt --statistics` per language.
- Prints file-type + LoC stats.
- Zips into `../releases/lrob-email-toolkit-<version>.zip`.

No PHPUnit, PHPCS, or PHPStan config yet — don't invent commands.

**Translation workflow.** Don't translate per commit — too much churn. Run the translation pass at **milestone boundaries only**. To add a new locale, drop `lrob-email-toolkit-<locale>.po` next to `fr_FR.po` and run release.sh. To edit a `.po`, use Edit/sed directly (do not write Python parsing scripts). Fuzzy entries are flagged but NOT compiled into `.mo` until manually cleared or `msgattrib --clear-fuzzy`.

## Versioning

Two cadences:
- **+0.0.1 (patch)** — small adjustments. Multiple iterations stack at the same version while testing; bump only on the user's explicit ship cue. **Ask the user for the version number — don't decide it yourself.**
- **+0.1.0 (minor)** — a full module shipped.

Single source of truth: `lrob-email-toolkit.php` has both the `Version:` header and `LROB_ETK_VERSION` constant — bump them together. `1.0.0` happens when stable enough to declare so. Pre-1.0 the schema can change freely between minor bumps.

## Naming convention — **MANDATORY**

Prefixes must be plugin-specific. Several LRob plugins coexist; "lrob_" alone collides. This plugin uses `etk` (= "email toolkit") everywhere a runtime identifier appears.

| Layer | Prefix | Examples |
|---|---|---|
| PHP namespace | `LRob\EmailToolkit\` | `LRob\EmailToolkit\Modules\SMTP\Module` |
| Hooks (actions/filters) | `lrob_etk_` | `lrob_etk_event`, `lrob_etk_email_sent` |
| Constants | `LROB_ETK_` | `LROB_ETK_VERSION`, `LROB_ETK_PATH` |
| DB tables | `{wpdb->prefix}lrob_etk_` | `wp_lrob_etk_logs`, `wp_lrob_etk_identities` |
| Options | `lrob_etk_` | `lrob_etk_modules`, `lrob_etk_<slug>_db_version` |
| REST namespace | `lrob-etk/v1` | `/wp-json/lrob-etk/v1/logs` |
| Capability | `manage_lrob_etk` | granted to `administrator` on activate |
| Text domain | `lrob-email-toolkit` | unchanged (human-readable slug) |
| CSS classes / JS globals | `lrob-etk-` / `lrobEtk` | `lrob-etk-form`, `window.lrobEtk` |

Anything Claude adds — new option, table, hook, CSS class — **must** follow these prefixes. No exceptions.

## Architecture

**Entry point** (`lrob-email-toolkit.php`): defines constants, registers a hand-rolled PSR-4 autoloader (`LRob\EmailToolkit\Foo\Bar` → `src/Foo/Bar.php`), boots `Plugin::instance()->boot()` on `plugins_loaded`. **No Composer at runtime** by design — distrust of library bloat.

**Lifecycle**: `Activator::activate()` grants `manage_lrob_etk` to administrator + seeds `lrob_etk_modules`. `Deactivator::deactivate()` clears every `lrob_etk_*` cron event (data preserved). `uninstall.php` drops every `{prefix}lrob_etk_*` table + every `lrob_etk_*` option/cron + the capability (belt-and-suspenders: prefix scan handles modules that forgot their own uninstall).

**Modules**: hard-coded in `ModuleManager::module_classes()` — no filesystem scanning. Module class names use studly-case slug: `smtp` → `Modules\SMTP\Module`, `contact_form` → `Modules\ContactForm\Module`. `enable()` calls `install()` (must be idempotent — uses `dbDelta`); disabling preserves data. Service modules override `is_enabled()` to always return true (Captcha is one — see [migration trap](#service-module-migrate-trap) below).

**Container** (`src/Container.php`): tiny `set()`/`get()`/`has()` service locator. Modules drop public services in there for cross-module access. Not full DI; constructor injection is the norm.

**Encryption** (`src/Support/Encryption.php`): AES-256-GCM, key derived from `AUTH_KEY` via HKDF-SHA256 (info-tag `lrob_etk_v1`). Output: base64(version(1) || iv(12) || tag(16) || ciphertext). Throws `RuntimeException` on missing/placeholder `AUTH_KEY`. Use for SMTP/IMAP passwords and any credential at rest. If `AUTH_KEY` changes, old ciphertexts are unrecoverable — callers must catch and prompt the user.

**Events** — public API from v0.0.1. `Support\Events::dispatch($name, $payload)` fires both a generic `do_action('lrob_etk_event', $name, $payload)` and a typed `do_action('lrob_etk_' . str_replace('.', '_', $name), $payload)`. Names are dot-namespaced (`domain.action[.detail]`). **Renaming or removing an event is a breaking change.** Live event list:
- `email.{sending,sent,failed,imap_saved,imap_save_failed}`
- `contact_form.{submitted,spam_blocked,delivered}`
- `newsletter.{started,paused,resumed,aborted,completed,test_sent}` (lifecycle)
- `newsletter.recipient.{sent,failed}`
- `newsletter.subscriber.{added,confirmed,refused,unsubscribed,trashed,promoted,resubscribed,reminder_sent}`
- `newsletter.tracking.{opened,clicked,unsubscribed}`

**Admin UI**: server-rendered PHP, vanilla JS for AJAX (no React/JSX, no build pipeline). Shared menu `admin.php?page=lrob-etk` (top-level "Email Toolkit"). Each module adds its own submenu.

## Conventions to follow

- **Strict types**: every PHP file in `src/` starts with `declare(strict_types=1);`.
- **Final classes** unless explicitly meant for subclassing (AbstractModule is the exception).
- **Constructor property promotion** — PHP 8.1+ minimum.
- **No mock/stub/fallback code paths for things that can't happen.** Internal code trusts callers; validate only at WP REST/admin/form boundaries.
- **One-line doc comments only where the WHY is non-obvious.** Don't narrate WHAT — names already do that. Don't restate shared conventions ("matches the X pattern", "single source of convention") — the convention either lives here or is implicit from any other call site.
- **No backwards-compat shims** while version < 1.0.0 — schema can change freely between minor versions.
- **Don't pre-bump versions or auto-commit small changes.** Both wait for the user's cue.

## Deployment workflow — read before claiming a fix is live

The user runs the plugin from the release zip, not the working tree. **Every PHP change must be followed by `./release.sh`**. Treat "edit done" as "not deployed" until rebuild has run. CSS/JS pick up a `filemtime`-based cache-bust query when `WP_DEBUG` is on (`Assets::asset_version_for()`); in production they use plugin version, so a CSS-only fix in a release still needs a version bump or a hard refresh.

## UI patterns — match these in new modules

Admin UI deliberately does **not** use core WP defaults (`.wrap`, `WP_List_Table`, `<select>`, `<datalist>`). Shared components live in `admin/css/admin-{base,components,dashboard,smtp,logging,contact-form,captcha,newsletter}.css` under `.lrob-etk-*` plus shared PHP renderers in `src/Admin/`. Reuse — don't reinvent:

- **Card grid** for entity lists: `grid-template-columns: repeat(auto-fit, minmax(380px, 540px))`. Form cards override to `minmax(420px, 750px)` so hCaptcha (303px min) fits.
- **Auto-save edit cards**: existing rows save on `blur`/`change` with the absolutely-positioned card-status badge that animates via `max-width` transition; new rows have an explicit "Create" button. Track per-input original value on focus, only save on blur if changed. Reference: `Modules/SMTP/Admin/SettingsPage.php` + `AjaxController`.
- **Inline module toggle** next to the page `<h1>` via `Admin\ModuleToggle::render_inline()`.
- **Anchored popovers** (`.lrob-etk-popover`) — JS positions via `getBoundingClientRect` relative to the trigger button.
- **Custom combobox** (`.lrob-etk-combo`, input + dropdown menu) — `<datalist>` is banned (inconsistent cross-browser). **Always use `Admin\Combobox::render_fixed_select()` for select-style fields and `Admin\Combobox::render_free_text()` for free-text fields with suggestions. Never render a raw `<select>` or a raw `<input>` inside a settings card.** Pass the module's auto-save marker class (e.g. `lrob-etk-nl-field`, `lrob-etk-cf-field`) so the existing listener picks the field up.
- **Custom data table** (`.lrob-etk-logs-table`) — replaces `widefat striped`.
- **Tooltips** (`Admin\Tooltip::render()`) — `position: fixed` with JS-computed coords so they escape scroll containers / popovers. Tip text has explicit `text-transform: none`.
- **Default-marker** on identity-style cards: `.lrob-etk-card-footer-default` slot in the card footer carries either `.lrob-etk-default-badge` (star-filled, "Default") or `.lrob-etk-set-default` (star-empty, click to make default). Used by SMTP identities + Captcha challenges/identities. Styles in `admin-components.css`.
- **CSS gotcha**: WP's `.button { display: inline-block }` overrides `[hidden]`. `.lrob-etk [hidden] { display: none !important }` is the fix — keep it.

## Hidden admin pages

Don't `add_submenu_page(...)` + `remove_submenu_page(...)` — `get_admin_page_cap()` walks `$submenu` for direct URLs, so an empty entry returns `'do_not_allow'` and the page 403s. Render as a **view** of an existing registered page: dispatch on `$_GET['view']` at the top of the parent's `render()` and delegate. URL pattern: `?page=<parent-slug>&view=<view-name>`. See `FormsPage` → `SubmissionsPage` (view `submissions`) and `DashboardPage` → `DataPage` (view `data`).

## Per-module AJAX

Each module's admin lives under `Modules/<Name>/Admin/AjaxController.php`. One shared nonce per module (e.g. `lrob_etk_smtp_ajax`), one `action` per endpoint, JSON in/out. `manage_lrob_etk` + `check_ajax_referer` gate every handler. Don't introduce REST routes unless an external integration actually needs them (the Newsletter tracking endpoints are REST because they're hit from email clients).

## SQL + timezone

WordPress stores `DATETIME` columns in the **server session timezone** — unstable across hosts and DST. Avoid `UNIX_TIMESTAMP()`. For bucket/grouping queries:

```sql
FLOOR(TIMESTAMPDIFF(SECOND, '2000-01-01 00:00:00', created_at) / %d) * %d + 946684800 AS bucket_ts
```

`946684800` is the UTC epoch of `2000-01-01 00:00:00`. Used by `Modules/Logging/LogRepository::counts_by_bucket()`; copy this pattern for any new time-bucketed aggregation. Display-side: render browser-local via JS `Date`, not server-formatted strings.

## Captcha module: adding a challenge / provider

Two flavours coexist in `src/Modules/Captcha/`:

- **Homemade challenges** (`Challenges/`) — self-contained PHP-renders-HTML + verifies-server-side. `MathChallenge`, `ImageChallenge`. No external API, no credentials.
- **Hosted providers** (`Providers/`) — hCaptcha (shipped); Turnstile / reCAPTCHA designed to plug in. Loaded via vendor JS widget, verified via `wp_remote_post` to the vendor's verify URL. Each provider can have N **identities** stored in `wp_lrob_etk_captcha_identities` (AES-256-GCM encrypted credentials).

`ProviderInterface` extends `ChallengeInterface` with `credential_fields()`, `validate_credentials()`, `logo_html()`. Routing keys: `homemade:<slug>`, `identity:<int>`, `none`, `inherit`. Per-context map (`lrob_etk_captcha_context_map`) keyed by `default` / `contact_form` / `comments` / `newsletter_subscribe` / `lost_password` / `registration`. `CaptchaService::resolve($context)` returns `[ChallengeInterface, credentials, state]`.

### To add a homemade challenge

Drop a class implementing `ChallengeInterface` into `Challenges/`. `Module::register_challenges()` auto-scans both `Challenges/` and `Providers/` at boot — no Module.php edit. The challenge automatically appears in every captcha picker.

### To add a hosted provider

Drop a class implementing `ProviderInterface` into `Providers/`. Required: `slug()`, `label()`, `description()`, `logo_html()`, `credential_fields()`, `validate_credentials()`, `render(array $context)`, `verify(array $post, array $context)`. Optional `SCRIPT_URL` and `POST_RESPONSE_FIELD` constants surface to admin JS for in-card preview. No edits to `Module.php`, `CaptchaService`, or the settings page — auto-scan picks it up.

`verify()` receives the raw `$_POST` array. Use `FormContext::is_active()` / `FormContext::instance()` from ContactForm if the token name needs per-form scoping (prevents replay across forms). `MathChallenge` does this — copy that pattern for any challenge issuing a signed token.

### Service-module migrate trap

Captcha is a **service module** (`is_service_module() === true`, always-enabled), so `maybe_migrate()` runs every boot. AbstractModule's default `db_version_int() === 1` recorded version=1 on every existing site *before* the module had install logic. Bumping to 2 makes `maybe_migrate()` take the `migrate()` branch (not `install()`) — schema never gets created on upgrade sites. **Fix**: always override `migrate()` to forward to `install()` (idempotent). If recovering from an already-shipped broken bump, bump the target one more notch so stuck sites re-take the migrate path. See memory `project_service_module_migrate_trap`.

## Form-builder WYSIWYG editor (`admin/js/form-fields-editor.js`)

Shared between Contact Form and Newsletter via `src/Forms/`. Form-builder DOM uses the `lrob-etk-form-*` CSS prefix; module-specific admin chrome keeps its own prefix (`lrob-etk-cf-*` for Contact Form's form cards / recipients / modals, `lrob-etk-nl-*` for Newsletter's). The captcha field type is per-module — Contact Form's captcha emits `lrob-etk-cf-captcha-*` styles; Newsletter's emits its own.

Single-IIFE, ~1900 lines. Section map for navigation — line numbers drift, search by the `// --- Name ---` marker:

| Section | What lives here |
|---|---|
| Undo / redo history | Snapshot stack of `form.innerHTML`. One per discrete action. `HISTORY_MAX = 50`. Contenteditables only snapshot on blur. |
| Save plumbing | `serialize(form)` → `FormData` → ajax `lrob_etk_cf_save_structure`. Debounced. Status states: `is-saving` / `is-saved` / `is-error`. |
| Click dispatcher | Single delegate on `form`. Routes by `[data-insert]`, `[data-action]`, `[data-edit]`. |
| Inline editables | Labels / helpers / submit text are `contenteditable="plaintext-only"` with `data-edit="label\|helper\|submit-text"`. |
| Drag-and-drop | `draggedItem`, `dragType` (`field`\|`row`\|`col`). Targets via `pickDropHover()`. Snap-to-col: row middle band picks col whose `midX` > cursor. See memory `project_drag_image_gotcha`. |
| Insert zones | Rebuilt from scratch after every mutation — canonical "one insert between every pair plus one trailing". `.is-orphan` for the sole insert in an empty container. |
| Mutators | `addField`, `deleteField`, `addRow`, `addColumn`, `moveField`. Each commits one history snapshot. |
| Inline settings strip | Per-field knobs (`maxLength`, `rows`, `min/max/step`, `pattern`, `placeholder` combobox for select, `multiple` for checkbox, `align` for submit) in `.lrob-etk-cf-inline-settings`. `[data-inline-prop]` inputs auto-write to `data-attr-*`. |
| Sticky hover state | JS-managed `.is-active` class on the shell containing the cursor (or its 10px buffer). |
| Required toggle | `.lrob-etk-cf-required-control` = visual-only star + hover-revealed checkbox. New fields default to required. |
| Slug derivation | `<type>_<sluggified-label>_<nth>`. `nth` is the stable creation-order index. PHP `FormStructure::enforce_unique_nths_and_slugs()` is the safety net at save. |
| Inline option editor | `select`/`radio`/`checkbox` shells render `.lrob-etk-cf-options[data-options-inline]` with `<input> + contenteditable label + remove button` per option. `inlineOptionRowHtml` deliberately does NOT wrap input in `<label>` — wrapping would steal click focus from the contenteditable. |
| DOM builders | `buildField(type, attrs)` / `buildRow(field)` / `buildColumn()`. Adding a new field type means this section + `inlineSettingsHtml` + the serializer + the PHP side. |
| Drag enable/disable | Mousedown anywhere except `.lrob-etk-cf-overlay-handle` flips `draggable="false"` on every `[data-draggable-type]` ancestor — so text selection inside inputs can't launch an HTML5 drag. |
| Serializer | `serialize(form)` produces `{ version: 1, rows: [{ id, columns: [{ id, fields: [...] }] }] }`. |
| Initial sync | First load copies attrs from PHP-rendered DOM into `data-attr-*` and backfills `data-attr-nth`. Guards via `dataset.etkInit`. |

### Serialized field shape

```json
{ "id": "f7a3", "type": "text|email|number|phone|date|textarea|select|radio|checkbox|submit|captcha",
  "slug": "text_your_name_3", "nth": 3,
  "label": "Your name", "helper": "", "placeholder": "",
  "required": true, "maxLength": 200,
  "rows": 5, "min": "0", "max": "99", "step": "1", "pattern": "...",
  "options": [{"label": "...", "value": "..."}], "defaults": ["..."], "multiple": true,
  "text": "Send", "align": "right" }
```

Type-specific keys appear only on relevant types. `submit` and `captcha` carry `align` (no stretch for captcha). `slug` is auto-derived and never user-editable; `nth` is the stable creation index.

### DOM contract the editor JS depends on

| Element | Required attributes |
|---|---|
| `.lrob-etk-cf-form.is-editor` | `data-form-id` on the wrapping `.lrob-etk-cf-fields` |
| `.lrob-etk-cf-row` | `data-row-id`, `data-cols` (1–4) |
| `.lrob-etk-cf-col` | `data-col-id` |
| `.lrob-etk-cf-edit-shell` | `data-field-id`, `data-field-type`, `data-attr-slug`, `data-attr-nth`, `data-attr-required`, optional `data-attr-*` for type-specific keys |
| `.lrob-etk-cf-overlay--{row\|col\|field}` | Drag handle + delete |
| `.lrob-etk-cf-insert--{row\|field\|column}` | `data-insert` action; `.is-orphan` when sole in empty container |
| `[contenteditable="plaintext-only"]` | `data-edit` value: `label\|helper\|submit-text` |
| `[data-inline-prop]` / `[data-required-toggle]` / `[data-action="toggle-default-option"]` | Inline-settings inputs / Required checkbox / per-option ★ toggle |

`FormEditorRenderer.php` emits this DOM. **If you change the DOM contract on one side, change both.**

### Where to make common changes

- **Add a new field type:** `buildField()` switch + `buildControlHtml()` switch (with inline option editor seed if multi-choice) + `serialize()`'s data-attr list + `typeSpecificInlineHtml()` switch + `FieldRenderer.php` (frontend) + `FormEditorRenderer.php` (editor preview).
- **Add a new per-field setting:** chip in `typeSpecificInlineHtml()` writing `data-inline-prop="X"` + read it in `serialize()` via `data-attr-X` + PHP side in `FieldRenderer` + schema in `FormStructure.php`.
- **Tweak the inline option editor:** start at `applyOptionsToPreview` / `renderSelectPreview` / `renderOptionGroupPreview` / `syncOptionsFromInline`. Mirror DOM shape changes in `buildControlHtml`.
- **Tweak drag-drop:** start at `pickDropHover()` / `computeDropDirection()` / `sameScope()`.
- **Add an undo-able action:** wrap with `commit()` at the end. One commit per user action.
