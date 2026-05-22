# CLAUDE.md

Guidance for Claude Code sessions working in this repository.

## Project

WordPress plugin **LRob - Email Toolkit** (slug `lrob-email-toolkit`). Modular all-in-one email plugin. Each module (SMTP, Logging, Contact Form, Captcha, Newsletter, future Integrations) is independently activatable. Requires PHP 8.1+ and WordPress 6.0+.

## Build / lint / release

`./release.sh` is the single build entry point. **Run it yourself whenever needed** — no need to ask. It lints every PHP file, regenerates `languages/lrob-email-toolkit.pot` via `wp i18n make-pot`, `msgmerge`s the fresh POT references into every `.po`, compiles `.po` → `.mo` and `.json`, then zips into `../releases/lrob-email-toolkit-<version>.zip`. No PHPUnit, PHPCS, or PHPStan config yet — don't invent commands.

## Versioning

Two cadences only:

- **+0.0.1 (patch)** — small adjustments, only on an explicit "let's release" cue. Multiple iterations can stack at the same version while you're testing; bump happens only when asked to ship.
- **+0.1.0 (minor)** — a full module shipped (Captcha → 0.1.0, Newsletter → next minor).

Single source of truth: `lrob-email-toolkit.php` has both the `Version:` header and `LROB_ETK_VERSION` constant — bump them together. Don't ship two releases with the same version.

`1.0.0` happens when the plugin is stable enough to declare it so — no specific feature gate. Pre-1.0 the schema can still change freely between minor bumps.

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

**Entry point**: `lrob-email-toolkit.php` defines constants, registers a hand-rolled PSR-4 autoloader (`LRob\EmailToolkit\Foo\Bar` → `src/Foo/Bar.php`), boots `Plugin::instance()->boot()` on `plugins_loaded`. **No Composer at runtime** by design — distrust of library bloat.

**Lifecycle**: `Activator::activate()` grants `manage_lrob_etk` to administrator + seeds `lrob_etk_modules`. `Deactivator::deactivate()` clears every `lrob_etk_*` cron event (data preserved). `uninstall.php` drops every `{prefix}lrob_etk_*` table + every `lrob_etk_*` option/cron + the capability (belt-and-suspenders: prefix scan handles modules that forgot their own uninstall).

**Modules**: hard-coded in `ModuleManager::module_classes()` — no filesystem scanning. Module class names use studly-case slug: `smtp` → `Modules\SMTP\Module`, `contact_form` → `Modules\ContactForm\Module`. `enable()` calls `install()` (must be idempotent — uses `dbDelta`); disabling preserves data. Service modules override `is_enabled()` to always return true (Captcha is one — see [migration trap](#captcha-module-adding-a-challenge--provider) below).

**Container** (`src/Container.php`): tiny `set()`/`get()`/`has()` service locator. Modules drop public services in there for cross-module access. Not full DI; constructor injection is the norm.

**Encryption** (`src/Support/Encryption.php`): AES-256-GCM, key derived from `AUTH_KEY` via HKDF-SHA256 (info-tag `lrob_etk_v1`). Output: base64(version(1) || iv(12) || tag(16) || ciphertext). Throws `RuntimeException` on missing/placeholder `AUTH_KEY`. Use for SMTP/IMAP passwords and any credential at rest. If `AUTH_KEY` changes, old ciphertexts are unrecoverable — callers must catch and prompt the user.

**Events** — public API from v0.0.1. `Support\Events::dispatch($name, $payload)` fires both a generic `do_action('lrob_etk_event', $name, $payload)` (Integrations listens here) and a typed `do_action('lrob_etk_' . str_replace('.', '_', $name), $payload)`. Names are dot-namespaced (`domain.action[.detail]`). **Renaming or removing** an event is a breaking change. Live: `email.{sending,sent,failed,imap_saved,imap_save_failed}`, `contact_form.{submitted,spam_blocked,delivered}`. Newsletter event names land with that module: `newsletter.{campaign.created,campaign.scheduled,campaign.started,campaign.completed,recipient.sent,recipient.failed,subscriber.added,subscriber.unsubscribed,tracking.opened,tracking.clicked}`.

**Admin UI**: server-rendered PHP, vanilla JS for AJAX (no React/JSX, no build pipeline). Shared menu `admin.php?page=lrob-etk` (top-level "Email Toolkit"). Each module adds its own submenu.

## Conventions to follow

- **Strict types**: every PHP file in `src/` starts with `declare(strict_types=1);`.
- **Final classes** unless explicitly meant for subclassing (AbstractModule is the exception).
- **Constructor property promotion** — PHP 8.1+ minimum.
- **No mock/stub/fallback code paths for things that can't happen.** Internal code trusts callers; validate only at WP REST/admin/form boundaries.
- **One-line doc comments only where the WHY is non-obvious.** Don't narrate WHAT — names already do that.
- **No backwards-compat shims** while version < 1.0.0 — schema can change freely between minor versions.

## Build order

**Shipped:**
1. ~~SMTP + Logging~~ — v0.0.1
2. ~~Contact Form~~ — v0.0.7
3. ~~Captcha~~ — v0.1.0
4. ~~Contact Form submissions inbox~~ — v0.2.0 (view of FormsPage at `?page=lrob-etk-cform&view=submissions`; filters, detail view, dashboard tiles, captcha counters, save-toggle, IP-storage toggle, retention cron, form-delete cascade modal). Reply composer was deferred — low priority.
5. ~~GitHub-release auto-update~~ — v0.2.0 (`src/AutoUpdate/Updater.php`; 1h cache, force-refresh on `update-core.php` or `$_GET['force-check']`).

**On deck — priority order, no version commitment.** Pick whatever's most useful at the time:

- **Newsletter module.** Campaigns, segmentation by role / meta / WooCommerce purchase data (HPOS-aware), throttled sending, open/click tracking, unsubscribe handling. Includes importer from the [Newsletter](https://wordpress.org/plugins/newsletter/) plugin. Biggest single chunk on the list. **Full design spec in [newsletter.md](newsletter.md)** — read that before touching Newsletter code.
- **Cross-feature captcha.** Captcha for comments / lost-password / registration. The Captcha module already declares these contexts; small lift. Ships naturally near Newsletter since `newsletter_subscribe` becomes a real consumer at that point.
- **Contact Form reply composer.** Deferred from the submissions-inbox work. Per-form "reply identity" setting, ad-hoc Reply-To override in composer, `replied_at` + reply count tracked on `cf_submissions`. Lives at the existing submission detail URL.
- **Captcha enrichment.** More hosted providers (Cloudflare Turnstile, Google reCAPTCHA) drop into `Providers/`. More in-house challenges (image-letter, simple logic, proof-of-work using local browser compute) drop into `Challenges/`. Both directories are auto-scanned — zero glue code.
- **Integrations module.** Outbound webhooks: Slack / Discord / Matrix / n8n presets + generic. Built on the `lrob_etk_event` action that already ships from v0.0.1.
- **Marketing automation module (name + scope TBD, last).** A broader marketing-tool sibling to Newsletter: event-triggered email sequences (post-purchase, post-registration, abandoned-cart-style flows), conditional logic, segmentation, A/B sends — the WordPress ecosystem doesn't have a clean self-hosted answer for this. Leans on the Newsletter module's send pipeline + tracking + unsubscribe once those exist, so it can only ship after Newsletter. Exact scope, feature boundary vs Newsletter, and module name need a design pass before any code.

**Maybe / deferred:**
- **IMAP "Save to Sent" archive.** Originally next-up after Captcha; demoted to optional. Useful for self-hosted IMAP setups but adds significant credential-handling + cron-dispatch + failure-mode complexity for a niche feature. If revisited: extends Logging, identities grow IMAP credential fields (same AES-256-GCM model), async dispatch via WP-Cron, failure annotates `imap_save_failed` event but the outbound email is already gone — never re-send to recover.

Pending Contact Form polish (multi-recipient, conditional routing, visual customization) lives in memories [[project-contact-form-conditional-recipients]], [[project-contact-form-visual-polish]] — doesn't gate anything.

## Deployment workflow — read before claiming a fix is live

The user runs the plugin from the release zip, not the working tree. **Every PHP change must be followed by `./release.sh`**. Treat "edit done" as "not deployed" until rebuild has run. CSS/JS pick up a `filemtime`-based cache-bust query when `WP_DEBUG` is on (`Assets::asset_version_for()`); in production they use plugin version, so a CSS-only fix in a release still needs a version bump or a hard refresh.

## UI patterns — match these in new modules

Admin UI deliberately does **not** use core WP defaults (`.wrap`, `WP_List_Table`, `<select>`, `<datalist>`). Shared components live in `admin/css/admin-{base,components,dashboard,smtp,logging,contact-form,captcha}.css` under `.lrob-etk-*` plus shared PHP renderers in `src/Admin/`. Reuse — don't reinvent:

- **Card grid** for entity lists: `grid-template-columns: repeat(auto-fit, minmax(380px, 540px))`. Form cards override to `minmax(420px, 750px)` so hCaptcha (303px min) fits.
- **Auto-save edit cards**: existing rows save on `blur`/`change` with the absolutely-positioned card-status badge that animates via `max-width` transition; new rows have an explicit "Create" button. Track per-input original value on focus, only save on blur if changed. Reference: `Modules/SMTP/Admin/SettingsPage.php` + `AjaxController`.
- **Inline module toggle** next to the page `<h1>` via `Admin\ModuleToggle::render_inline()`.
- **Anchored popovers** (`.lrob-etk-popover`) — JS positions via `getBoundingClientRect` relative to the trigger button. Used for SMTP test-send, connection-test details, log cleanup, dashboard test email.
- **Custom combobox** (`.lrob-etk-combo`, input + dropdown menu) — `<datalist>` is banned (inconsistent cross-browser).
- **Custom data table** (`.lrob-etk-logs-table`) — replaces `widefat striped`.
- **Tooltips** (`Admin\Tooltip::render()`) — `position: fixed` with JS-computed coords so they escape scroll containers / popovers. Tip text has explicit `text-transform: none`.
- **CSS gotcha**: WP's `.button { display: inline-block }` overrides `[hidden]`. `.lrob-etk [hidden] { display: none !important }` is the fix — keep it.

## Hidden admin pages

Don't `add_submenu_page(...)` + `remove_submenu_page(...)` — `get_admin_page_cap()` walks `$submenu` for direct URLs, so an empty entry returns `'do_not_allow'` and the page 403s. Render as a **view** of an existing registered page: dispatch on `$_GET['view']` at the top of the parent's `render()` and delegate. URL pattern: `?page=<parent-slug>&view=<view-name>`. See `FormsPage` → `SubmissionsPage` (view `submissions`) and `DashboardPage` → `DataPage` (view `data`).

## Per-module AJAX

Each module's admin lives under `Modules/<Name>/Admin/AjaxController.php`. One shared nonce per module (e.g. `lrob_etk_smtp_ajax`), one `action` per endpoint, JSON in/out. `manage_lrob_etk` + `check_ajax_referer` gate every handler. Don't introduce REST routes unless an external integration actually needs them.

## SQL + timezone

WordPress stores `DATETIME` columns in the **server session timezone** — unstable across hosts and DST. Avoid `UNIX_TIMESTAMP()`. For bucket/grouping queries:

```sql
FLOOR(TIMESTAMPDIFF(SECOND, '2000-01-01 00:00:00', created_at) / %d) * %d + 946684800 AS bucket_ts
```

`946684800` is the UTC epoch of `2000-01-01 00:00:00`. Used by `Modules/Logging/LogRepository::counts_by_bucket()`; copy this pattern for any new time-bucketed aggregation. Display-side: render browser-local via JS `Date`, not server-formatted strings.

## Regression preventers (do not re-break)

- **Resender** (`Modules/Logging/Resender::resend()`) creates a new log row for the retry and leaves the original untouched. Earlier code marked the original as `retried` and undercounted sends. Don't reintroduce a status flip on the original. `build_headers()` runs every stored header component through `strip_crlf()` — keep this; the planned IMAP-save / mail-receive features will introduce attacker-controlled data.
- **From / transport resolution**: SMTP identity rows store `from_email` / `from_name` that may be empty — meaning "fall back at send time". `Identity::effective_from_email()` returns `smtp_username` if `from_email` is empty; `effective_from_name()` returns the site title. Per-identity `transport` (`smtp` | `mail`) is honored by `MailRouter` and `TestSender`. New per-identity behavior follows the same `effective_*` accessor pattern.
- **Attachments in logs**: `logs.attachments` is JSON `[{"name", "path"}]`. `LogEntry::normalize_attachments()` upgrades legacy string-only entries — keep it as long as old rows can exist. Resend re-attaches files whose `path` still resolves and reports `attachments_dropped` for the rest.
- **Captcha fail-closed on misconfiguration**: `CaptchaService::verify()` returns `[false, message]` when `resolve()` returns `STATE_BROKEN` (deleted identity, inactive identity, AUTH_KEY rotated). Earlier behavior returned `[true, null]` and silently let bots through. Distinguish `STATE_NONE` (admin opted out → fail open) from `STATE_BROKEN` (misconfig → fail closed). `Captcha\Module::render_broken_routes_notice()` surfaces broken routes to admins via `admin_notices`.

## Captcha module: adding a challenge / provider

Two flavours coexist in `src/Modules/Captcha/`:

- **Homemade challenges** (`Challenges/`) — self-contained PHP-renders-HTML + verifies-server-side. `MathChallenge`, `ImageChallenge`. No external API, no credentials.
- **Hosted providers** (`Providers/`) — hCaptcha (shipped), Turnstile / reCAPTCHA designed to plug in. Loaded via vendor JS widget, verified via `wp_remote_post` to the vendor's verify URL with site key + secret. Each provider can have N **identities** stored in `wp_lrob_etk_captcha_identities` (AES-256-GCM encrypted credentials).

`ProviderInterface` extends `ChallengeInterface` with `credential_fields()`, `validate_credentials()`, `logo_html()`. Routing keys are strings: `homemade:<slug>`, `identity:<int>`, `none`, `inherit`. Per-context map (`lrob_etk_captcha_context_map`) keyed by `default` / `contact_form` / `comments` / `newsletter_subscribe` / `lost_password` / `registration`. `CaptchaService::resolve($context)` returns `[ChallengeInterface, credentials, state]` for the effective route; callers pass `'context' => 'contact_form'` (or other) to render/verify, advanced callers override with `'force_route'`.

### To add a homemade challenge

Drop a class implementing `ChallengeInterface` into `Challenges/`. `Module::register_challenges()` auto-scans both `Challenges/` and `Providers/` at boot — no `Module.php` edit. The challenge automatically appears in: Captcha settings routing dropdowns, Contact Form per-form picker, Contact Form WYSIWYG editor's in-block picker (via `wp_localize_script`-published `EDITOR_DATA.captchaOptions`). If admin preview matters, `render(['context' => 'preview'])` should produce sensible HTML.

### To add a hosted provider (Turnstile / reCAPTCHA / etc.)

Drop a class implementing `ProviderInterface` into `Providers/`. Required:

- `slug()`, `label()`, `description()`, `logo_html()` — identity card chrome
- `credential_fields()` — array of `{key, label, type:'text'|'password', required, description?}` for the admin form
- `validate_credentials($values)` — returns `{credentials: [...], errors: [field => msg]}`
- `render(array $context)` — receives the active identity's decrypted credentials via `$context['credentials']`. For `$context['context'] === 'preview'` (admin editor), prefer a placeholder div when no site_key, the real widget when credentials are present.
- `verify(array $post, array $context)` — `wp_remote_post` to the vendor's verify URL. Returns `[bool, ?error]`.
- Optional `SCRIPT_URL` constant — surfaced to admin JS so the in-card preview can lazy-load the vendor script.
- Optional `POST_RESPONSE_FIELD` constant — the `$_POST` key the vendor uses for the solved token. The admin test endpoint reads this.

No edits to `Module.php`, `CaptchaService`, or the settings page needed — auto-scan + the routing dropdowns pick up the new provider automatically.

`verify()` receives the raw `$_POST` array. Use `FormContext::is_active()` / `FormContext::instance()` from ContactForm if the token name needs per-form scoping (prevents replay across forms). The `MathChallenge` does this — copy that pattern for any new challenge that issues a signed token.

### Service-module migration trap — read before bumping `db_version_int()`

Captcha is a **service module** (`is_service_module() === true`, always-enabled), so `maybe_migrate()` runs every boot. AbstractModule's default `db_version_int() === 1` recorded version=1 on every existing site **before** the module had any install logic. Bumping to 2 made `maybe_migrate()` take the `migrate()` branch (not `install()`) — schema never got created on upgrade sites. See [[project-service-module-migrate-trap]]. Fix: always override `migrate()` to forward to `install()` (idempotent). If recovering from an already-shipped broken bump, bump the target version one more notch so stuck sites re-take the migrate path.

## Form-builder WYSIWYG editor (`admin/js/form-fields-editor.js`)

Shared between Contact Form and Newsletter (when it lands) via `src/Forms/` — see `newsletter.md` for the split. Form-builder DOM uses the `lrob-etk-form-*` CSS prefix (renamed from historical `lrob-etk-cf-*`); module-specific admin chrome keeps its own prefix (`lrob-etk-cf-*` for Contact Form's form cards / recipients / modals, `lrob-etk-nl-*` for Newsletter's admin chrome later). The captcha field type is per-module — Contact Form's captcha emits `lrob-etk-cf-captcha-*` styles; Newsletter's will emit its own.

Single-IIFE, ~1900 lines. Drives every Contact Form card's field editor (preview + overlays + drag-drop + inline settings strip + inline option editor + serializer + sticky-hover). Section map for navigation — line numbers drift, search by the `// --- Name ---` marker:

| Section | What lives here |
|---|---|
| Undo / redo history | Snapshot stack of `form.innerHTML`. One per discrete action. `HISTORY_MAX = 50`. Contenteditables only snapshot on blur. |
| Save plumbing | `serialize(form)` → `FormData` → ajax `lrob_etk_cf_save_structure`. Debounced. Status states: `is-saving` / `is-saved` / `is-error`. |
| Click dispatcher | Single delegate on `form`. Routes by `[data-insert]`, `[data-action]`, `[data-edit]`. |
| Inline editables | Labels / helpers / submit text are `contenteditable="plaintext-only"` with `data-edit="label\|helper\|submit-text"`. |
| Drag-and-drop | `draggedItem`, `dragType` (`field`\|`row`\|`col`). Targets via `pickDropHover()`. Snap-to-col: row middle band picks col whose `midX` > cursor. See [[project-drag-image-gotcha]]. |
| Insert zones | Rebuilt from scratch after every mutation — canonical "one insert between every pair plus one trailing". `.is-orphan` for the sole insert in an empty container. |
| Mutators | `addField`, `deleteField`, `addRow`, `addColumn`, `moveField`. Each commits one history snapshot. |
| Inline settings strip | Per-field knobs (`maxLength`, `rows`, `min/max/step`, `pattern`, `placeholder` combobox for select, `multiple` for checkbox, `align` for submit) in `.lrob-etk-cf-inline-settings`. `[data-inline-prop]` inputs auto-write to `data-attr-*`. Slugs are auto-derived, no chip. |
| Sticky hover state | JS-managed `.is-active` class on the shell containing the cursor (or its 10px buffer). Pairs with CSS so the field stays expanded across the "+ Field" pill gap. |
| Required toggle | `.lrob-etk-cf-required-control` = visual-only star + hover-revealed checkbox. Checkbox is the control. New fields default to required. |
| Slug derivation | `<type>_<sluggified-label>_<nth>`. `nth` is the creation-order index (stable across reorders/deletions). `recomputeSlug(shell)` re-derives on label blur. PHP `FormStructure::enforce_unique_nths_and_slugs()` is the safety net at save. |
| Inline option editor | `select`/`radio`/`checkbox` shells render `.lrob-etk-cf-options[data-options-inline]` with `<input> + contenteditable label + remove button` per option (+ `★` default toggle on select). `applyOptionsToPreview()` renders; `syncOptionsFromInline()` reads back into `data-attr-options`. `inlineOptionRowHtml` deliberately does NOT wrap input in `<label>` — wrapping would steal click focus from the contenteditable. `buildControlHtml` must emit this same shape. |
| DOM builders | `buildField(type, attrs)` / `buildRow(field)` / `buildColumn()`. Adding a new field type means this section + `inlineSettingsHtml` + the serializer + the PHP side. |
| Drag enable/disable | Mousedown anywhere except `.lrob-etk-cf-overlay-handle` flips `draggable="false"` on every `[data-draggable-type]` ancestor, restored on global mouseup — so text selection inside inputs can't launch an HTML5 drag of the shell. |
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

## Backlog — don't paint into a corner

Not in scope now, but design current code so these are easier later.

- **Email reading in a modal with prev/next navigation.** Future: full-screen-ish modal with `←`/`→` keys cycling. Keep `LogsPage` row→detail addressable by index.
- **Homemade anti-bot question pool.** Beyond Math + Image, build a small library (image-letter, simple logic, etc.); the form picks one at random per submission. Each must be self-contained (no external API).
- **Per-context SMTP identity routing.** Add a "context" mapping so the user assigns identities to email categories (WooCommerce, admin notifications, contact forms, etc.) — small table on the SMTP settings page, matching happens in `MailRouter` from headers/hook context. Most users want defaults, power users want overrides.
- **Contact form visual customization.** Per-form (with global defaults) colours, roundness, hover/focus glow, button animations, submit-success celebration. Named templates ("sober", "fancy"). See [[project-contact-form-visual-polish]].
- **Responsive preview modes (Desktop / Tablet / Phone) for the form-card preview.** Toggles at the top of the Forms page that constrain card width and reflow as the frontend would. Worth doing once visual customization lands.
- **Subscribe-to-comments.** Visitor-facing (low priority, after newsletter). Per-thread token, list-unsubscribe header, integrates with SMTP routing + captcha + logging.
- **Email export.** Bulk CSV (possibly mbox/EML). Reuse `LogRepository` filtered query helpers. Stream the response.
- **Injection safety from stored email content** — *the touchy one.* `logs.body_html`, `subject`, `from_name`, headers, attachment filenames are attacker-controllable once mail-receive lands. Everywhere they're rendered or re-emitted:
  - **Admin UI**: escape on output. `body_html` viewing must use an isolated sandboxed iframe — never inject into the admin DOM. Subject/headers through `esc_html`, filenames through `esc_attr`. There's no "trusted" log row.
  - **Resend / forward / reply paths**: never reuse stored HTML as outbound body without re-sanitizing. PHPMailer doesn't sanitize for you. Header values must be CRLF-stripped (Resender already does this — keep it).
  - **CSV / export**: prefix any cell starting with `=`, `+`, `-`, `@`, tab, or CR with a single quote to neuter spreadsheet formula injection.
  - **Search / filter inputs**: already parameterized via `$wpdb->prepare`, keep it that way; never concatenate user input into SQL.
  - Treat any stored field as if it came directly from a hostile sender — because eventually it will.
