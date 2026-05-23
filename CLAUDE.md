# CLAUDE.md

Guidance for Claude Code sessions working in this repository.

## Project

WordPress plugin **LRob - Email Toolkit** (slug `lrob-email-toolkit`). Modular all-in-one email plugin. Each module (SMTP, Logging, Contact Form, Captcha, Newsletter, future Integrations) is independently activatable. Requires PHP 8.1+ and WordPress 6.0+.

## Build / lint / release

`./release.sh` is the single build entry point. **Run it yourself whenever needed** — no need to ask. Steps:
- Lints every PHP file (`php -l`) — don't pre-lint with `php -l` separately, the script already does it.
- Lints every JS file (`node --check`) when node is on the path — skips with a warn otherwise.
- Scans CSS for unreferenced `.lrob-etk-*` selectors (peel-once + 3-hyphen-min heuristic suppresses dynamic-concat false positives).
- Regenerates `languages/lrob-email-toolkit.pot` via `wp i18n make-pot`.
- `msgmerge`s the fresh POT references into every `.po`, then `msgattrib --no-obsolete` strips orphans (reports the count when > 0).
- Compiles `.po` → `.mo` and `.json` (Gutenberg), printing per-language stats from `msgfmt --statistics` so you can see at a glance "1113 translated, 0 untranslated, 0 fuzzy" or where work is needed.
- Prints file-type + LoC stats (per-extension counts, PHP code-vs-comment-vs-blank ratio). Stats are display-only, never saved.
- Zips into `../releases/lrob-email-toolkit-<version>.zip`.

**Translation workflow.** Don't translate on every commit — too much churn. Run the translation pass at **milestone boundaries only** (before tagging a release). The release.sh stats line tells you what needs doing per language. To add a new locale, just drop `lrob-email-toolkit-<locale>.po` next to `fr_FR.po` and run release.sh — `msgmerge` populates the new file with all source strings as untranslated, you translate, re-run release.sh. To edit a `.po` file, use the Edit tool / sed directly (do **not** write Python parsing scripts — `msgmerge` + `msgattrib` already handle the heavy lifting). Fuzzy entries (auto-promoted by msgmerge when source strings shift slightly) are flagged but NOT compiled into the `.mo` until either you manually clear the fuzzy flag or run `msgattrib --clear-fuzzy` to accept them wholesale.

No PHPUnit, PHPCS, or PHPStan config yet — don't invent commands.

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

**Events** — public API from v0.0.1. `Support\Events::dispatch($name, $payload)` fires both a generic `do_action('lrob_etk_event', $name, $payload)` (Integrations listens here) and a typed `do_action('lrob_etk_' . str_replace('.', '_', $name), $payload)`. Names are dot-namespaced (`domain.action[.detail]`). **Renaming or removing** an event is a breaking change. Live: `email.{sending,sent,failed,imap_saved,imap_save_failed}`, `contact_form.{submitted,spam_blocked,delivered}`, newsletter events emitted today are `newsletter.{started,paused,resumed,aborted,completed,test_sent}` (lifecycle), `newsletter.recipient.{sent,failed}`, `newsletter.subscriber.{added,confirmed,refused,unsubscribed,trashed,promoted,resubscribed,reminder_sent}`, `newsletter.tracking.unsubscribed`. The fuller event vocabulary (including reserved names for tracking opens/clicks/image_loaded, bounces, import/export) lives in [newsletter.md](newsletter.md) § "Events" — that's the canonical list.

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

- **Newsletter module.** Newsletters, segmentation by role / meta / WooCommerce purchase data (HPOS-aware), throttled sending, open/click tracking, unsubscribe handling. Includes importer from the [Newsletter](https://wordpress.org/plugins/newsletter/) plugin. Biggest single chunk on the list. **Full design spec in [newsletter.md](newsletter.md)** — read that before touching Newsletter code.
- **Cross-feature captcha.** Captcha for comments / lost-password / registration. The Captcha module already declares these contexts; small lift. Ships naturally near Newsletter since `newsletter_subscribe` becomes a real consumer at that point.
- **Contact Form reply composer.** Deferred from the submissions-inbox work. Per-form "reply identity" setting, ad-hoc Reply-To override in composer, `replied_at` + reply count tracked on `cf_submissions`. Lives at the existing submission detail URL.
- **Captcha enrichment.** More hosted providers (Cloudflare Turnstile, Google reCAPTCHA) drop into `Providers/`. More in-house challenges (image-letter, simple logic, proof-of-work using local browser compute) drop into `Challenges/`. Both directories are auto-scanned — zero glue code.
- **Integrations module.** Outbound webhooks: Slack / Discord / Matrix / n8n presets + generic. Built on the `lrob_etk_event` action that already ships from v0.0.1.
- **Marketing automation module (name + scope TBD, last).** A broader marketing-tool sibling to Newsletter: event-triggered email sequences (welcome email after registration, post-purchase / post-WooCommerce-order follow-ups, abandoned-cart-style flows, anniversary / win-back), conditional logic, segmentation, A/B sends — the WordPress ecosystem doesn't have a clean self-hosted answer for this. Leans on the Newsletter module's send pipeline + tracking + unsubscribe once those exist, so it can only ship after Newsletter. The single-shot "send N hours after event X" surface is the obvious first scope; full multi-step sequences land later. Exact scope, feature boundary vs Newsletter, and module name need a design pass before any code. (Note: single welcome / follow-up emails could alternatively be wired under the Newsletter module's Onboarding view if the user wants the simple case faster — design call when we get there.)

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
- **Custom combobox** (`.lrob-etk-combo`, input + dropdown menu) — `<datalist>` is banned (inconsistent cross-browser). **Always use `Admin\Combobox::render_fixed_select()` for select-style fields (Identity / Category / List pickers) and `Admin\Combobox::render_free_text()` for free-text fields with suggestions (From name / Reply-to / subject template / success message). Never render a raw `<select>` or a raw `<input>` inside a settings card — every dropdown/text field in card UIs goes through the Combobox helpers so the look and keyboard behavior stay consistent. Pass the module's auto-save marker class (e.g. `lrob-etk-nl-field`, `lrob-etk-cf-field`) so the existing listener picks the field up.**
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
- **In-house email content editor (extend the form editor).** User pain: leaving the admin page to enter a full-screen Gutenberg surface for short content (the newsletter footer, eventually simple newsletter bodies) feels heavyweight when the rest of the plugin's editing is inline. Form editor (`admin/js/form-fields-editor.js`, ~1900 lines) is the proposed base — it already does drag-and-drop, undo/redo, contenteditable labels, attribute editors. To make it an *email* editor we'd need: new block types (paragraph / heading / image / button / separator / spacer), rich text inside text blocks (bold / italic / inline links via Selection API + range wrapping, no execCommand), token-insert dropdown (literal `{{name}}` insert OR wrap selection in `<a href="{{prefs_url}}">…</a>`), `wp.media` integration for images, paste sanitization to our vocabulary, and a PHP renderer that emits email-safe HTML (table wrappers, inline styles only). The form-editor's `{rows:[{columns:[{fields:[]}]}]}` shape would diverge enough that it's "borrow patterns from" rather than "reuse." **A/B path:** offer both editors side-by-side (settings toggle: "Legacy [Gutenberg] / New [In-house]") so the new one can be evaluated against the existing flow without ripping Gutenberg out; if it doesn't work for the body, the in-house editor still pays off for the footer. **Keep Gutenberg for system email templates** (confirmation/reminder/refuse_ack) — those work; don't migrate without need. Post-0.3.0 release; not a blocker.
- **Outbound-email blocklist + WP-system-email mutes.** Since this plugin already sits in front of every outgoing email, give the admin per-context muting / blocking. Two surfaces: (1) a global kill-switch ("Block all outgoing email") for staging/dev sites where you don't want anything leaking — short-circuits in the `wp_mail` filter chain, optionally logging the would-have-been email; (2) granular per-source toggles for noisy WP-core system emails (auto-update notifications, new-user welcomes, password-reset admin copies, comment moderation alerts, etc.) — implemented as filters on the matching hooks (`auto_core_update_send_email`, `wp_new_user_notification_email_admin`, `send_password_change_email`, `comment_notification_recipients`, …) so muting returns false / empty array without touching the actual flow. UI lives in the SMTP module (since SMTP is always-on and owns the outbound pipeline) or as a new "Outbound" module if scope grows. Per-source state stored as a JSON option `lrob_etk_email_mutes` keyed by stable source slugs. Pairs nicely with the Logging module: muted sends still log so the admin can verify what would have been sent.
- **Customize WordPress default emails.** Sibling of the mutes feature above — same hook surface, opposite direction. Instead of muting WP-core emails (auto-update notice, new-user welcome to admin, new-user welcome to user, password-reset, password-change confirmation, email-change confirmation, comment-moderation, etc.), let the admin **rewrite** subject + body + (optionally) from-name. Each source has an "use WP default / use custom" toggle and, when custom, a body editor that uses the same Gutenberg-with-allowed-blocks setup as the Newsletter onboarding templates (or the in-house editor if that ships first). Token vocabulary per source: `{{user_name}}`, `{{user_email}}`, `{{site_name}}`, `{{login_url}}`, `{{reset_url}}`, etc. — published per-hook so the editor's token-insert menu only shows what's actually substitutable for that source. Storage: a CPT `lrob_etk_etpl` (16 chars; mirrors the Newsletter onboarding-template CPT pattern but is plugin-wide, not Newsletter-scoped), with `purpose` meta = the WP-source slug (`wp_new_user_notification_user`, `password_reset_request`, etc.). Resolution at send time: filter the matching hook (`wp_new_user_notification_email`, `retrieve_password_message`, …), substitute tokens, return the rewritten array. Default subjects/bodies match WP's originals so opting into "custom" without editing is a no-op. Lives in the same admin surface as the mutes (likely a new top-level "Outbound" module bundling: mute toggles + customization templates + the blocklist kill-switch). Pairs with the in-house email editor backlog — both want the same edit-without-Gutenberg-fullscreen experience.
- **Newsletter list polish — ✅ shipped in v0.3.1.** Preview button (per-card modal of the rendered HTML), recipients drawer (paginated `newsletter_recipients` view with status-filter chips + email/name search + cross-link to Logging entries on failed rows), and sub-tabs (**In preparation** / **Sent** / **Trash**) all landed together with the trash system (`wp_trash_post` + Restore / Delete-permanently / Empty-trash). Newly-created newsletters sort to the top of In preparation. Trash safety nets: `trashed_post` hook flips scheduled→draft + SendCron joins wp_posts to exclude trashed rows. Duplicate-options dialog (clone-everything vs content-only) was *demoted to maybe* — the current "clone everything" default has been adequate; revisit only if someone reports it's wrong.
- **Archive sent newsletters.** Once a newsletter is in a terminal status (sent / aborted / failed), it stays in the active list and accumulates over time, drowning out current drafts. Add an "Archive" action on the card footer (next to Duplicate/Delete) that moves it to an "Archived" sub-tab on the Newsletters view. Archived newsletters stay queryable (stats are preserved) but don't show on the default tab. Underlying mechanism: a meta flag `_lrob_etk_nl_archived = '1'` or use WP's built-in `post_status = 'archived'` (custom status registered with the CPT). Default list excludes archived; sub-tab filter shows only archived. Auto-archive after N days post-send is a nice-to-have on top.
- **Newsletter default settings page + sender-override decision.** Deferred during 0.3.0: a "Newsletter defaults" surface for the values new newsletters inherit (default identity, default category, default tracking toggles). The user explicitly rejected per-newsletter from-name / from-email / reply-to override UI in v0.3.0 — *picking an identity is the single source of truth for sender info*; to change any of that, the admin edits the identity itself. The override meta keys (`_lrob_etk_nl_from_name_override`, `_lrob_etk_nl_reply_to_override`) and the SendLoop's header-emit code path are still in place for back-compat / future power-feature reactivation, but no UI exposes them. If the override UI is ever brought back: also add `_lrob_etk_nl_from_email_override` (currently missing from the meta vocab) and a managed "From-name suggestions" list option. Mirror the existing `Combobox::render_free_text` pattern.
- **Newsletter templates (separate CPT).** Distinct from the newsletter post itself. Two flavours: **premade locked templates** shipped with the plugin (curated examples — "monthly digest", "single-article", "event invite"), and **admin-created templates** (saved from an existing draft for reuse). "+ New newsletter" gains a picker step (Blank / Template / Duplicate existing). Templates carry the same meta shape as newsletters minus send-state. Premade ones live in code (PHP-defined seed data) and can't be edited/deleted from the UI; admin-created ones use a `lrob_etk_nl_tpl` CPT (15 chars). Pairs with the in-house editor backlog — if that lands, templates are the natural starting library.
- **Newsletter tracking — design re-locked (step 9).** Full spec in [newsletter.md](newsletter.md) § "Tracking"; this entry is the survival anchor in case that section is later reorganized. Three rewriter passes at send time:
  - **Image rewriter (primary open signal).** Walk every `<img src="…">` in the rendered HTML and route it through `/wp-json/lrob-etk/v1/nl/track/img/<token>?n=<newsletter_id>&r=<kind>:<id>&a=<asset_id>`. HMAC-signed; verified constant-time. The newsletter's own images carry the open signal — most emails have a logo or hero, so we route those loads through our domain rather than appending a dedicated tracking pixel. Less spy-y; free per-image engagement data as a byproduct.
  - **Open-pixel fallback only.** If the rendered body has **zero** `<img>` after the rewriter pass, append a 1×1 transparent GIF before `</body>` (same endpoint family, `purpose='open_pixel'`, reserved `asset_id=0`). The dedicated GIF has no advantage over media-URL rewriting (same image-blocking, proxy behaviour, caching — per-recipient token makes URLs unique anyway).
  - **Link rewriter (clicks).** Walk every `<a href>` (skip `mailto:`/`tel:`/`#anchor`/prefs-token/`data-lrob-etk-no-track`). Route through `/wp-json/lrob-etk/v1/nl/track/click/<token>?n=…&r=…&l=<link_id>`. HMAC-signed. On valid token: insert `click` event; if no prior `open` for this recipient, also insert an `open` (clicks imply opens — recovers signal from image-blocking clients); respond `302 Location: <original URL>` with `Cache-Control: no-store`. Invalid token → 400 (don't become an open redirect).
  - **Side tables.** `wp_lrob_etk_nl_newsletter_assets` (`id, newsletter_id, asset_id, url, purpose enum('open_pixel','content')`, UNIQUE `(newsletter_id, asset_id)`). `wp_lrob_etk_nl_newsletter_links` (`id, newsletter_id, link_id, url, label_snippet`, UNIQUE `(newsletter_id, link_id)`). Stores the URL once per newsletter, not per recipient — keeps the per-event row cheap.
  - **Test-send exclusion.** `X-Lrob-Etk-Newsletter-Test: 1` sends bypass all three passes; no tracking URLs at all so admins testing don't poison real stats.
  - **Per-recipient counters** already in schema: `opens`, `clicks`, `last_open_at`, `last_click_at` on `newsletter_recipients`. Bumped by the REST handlers.
  - **Per-subscriber lifetime stats (new columns).** Denormalized on `wp_lrob_etk_nl_subscribers` (and matching `lrob_etk_nl_*` user_meta keys for WP users): `last_engagement_at`, `last_sent_at`, `sends_since_engagement smallint`, `total_sent int`, `total_opened int`, `total_clicked int`. Materializer bumps `last_sent_at` + `total_sent` + `sends_since_engagement` per row; tracking endpoints reset `sends_since_engagement` to 0 + bump `total_*` + set `last_engagement_at`. Lets us identify cold subscribers without an aggregate query over the whole events table.
  - **Apple MPP caveat.** Apple Mail loads images server-side (~60% of consumer inboxes) — opens are inflated to ~100% for those recipients. To keep cold-detection honest, `sends_since_engagement` resets **on click only by default**. Setting `lrob_etk_nl_engagement_counts_opens` (default `false`) lets opens also count if admin trusts the audience mix.
  - **Cold sub-tab in Subscribers admin.** `WHERE sends_since_engagement >= N` (admin-configurable via `lrob_etk_nl_cold_threshold`, default 5). Bulk-unsubscribe action on selected rows. Optional `lrob_etk_nl_auto_cleanup_threshold` (default 0 = disabled) — daily cron auto-unsubscribes everyone past the line. Auto-cleanup with a "we noticed you're not reading" warning email (template `cleanup_warning`) is step-9 polish, not first ship.
  - **Per-list / per-category rollups** at view time, no new storage. `COUNT(*) WHERE list_id=? AND last_engagement_at >= NOW() - INTERVAL 90 DAY`. Transient-cached for 5 min because expensive on big lists.
  - **Retention.** `lrob_etk_nl_tracking_retention_days` (default 365). Daily chunked-DELETE cron. Companion-row counters (`opens_count`, `opens_unique`, `clicks_count`, `clicks_unique` on `wp_lrob_etk_nl_newsletters`) kept forever — events table just powers detail views, summary numbers are denormalized.
  - **IP / UA.** IPv4 → /24, IPv6 → /48 before storage in `tracking_events.ip_anon`. No cookies, no fingerprinting. User-agent not stored by default; per-newsletter opt-in via `_lrob_etk_nl_track_user_agent`.
- **SMTP failure circuit-breaker for newsletter sends — ✅ shipped (step 7c).** Full design + implementation notes in [newsletter.md](newsletter.md) § "Step 7c". TL;DR: `SendLoop` counts consecutive `wp_mail` failures inside a tick, aborts at 5, releases claimed-but-unsent rows back to `pending`, flips status to `paused` with `pause_reason = 'smtp_unhealthy'`. `Retry failed (N)` button + AJAX endpoint re-queues failed rows. Still TODO if useful: a "Retry connection now" button that runs a single test-send to the current admin's email before flipping to sending, surfacing the actual error inline if SMTP is still broken — current Resume just re-trips the breaker after a wasted batch, which is fine but noisier.
- **Per-identity (per-provider) hourly send cap.** Distinct from the per-recipient-domain throttle (which protects inboxes from spam-flagging by rate-limiting *to* known-strict ISPs like laposte.net at 30/h). This one is the *sender-side* limit imposed by the SMTP provider itself — "this Mailjet plan allows 2000/h", "LRob's mail server caps outgoing at 2000/h", "Gmail Workspace caps at 2000/day". Lives on the SMTP identity row: a new `hourly_send_cap` int column (0 = unlimited, default 2000 as a safe ceiling). The Newsletter's `SendLoop` consults the identity's cap before claiming the next batch and skips the tick (leaves rows `pending`, returns `throttled` in the progress JSON) when sends in the last 60min for that identity ≥ cap; the SendCron picks it back up when the rolling window clears. Bookkeeping: a small transient `lrob_etk_smtp_identity_send_count_<id>` tracking sliding-window count (or just `COUNT(*) FROM logs WHERE identity_id = ? AND created_at > NOW() - INTERVAL 1 HOUR` — slower but accurate without extra plumbing). UI: identity edit card gets a "Hourly cap" number input with a tooltip linking to the provider's quota docs. Newsletter card during a send shows a small "throttled by provider (X/h cap)" badge instead of a stalled progress bar when the cap is the reason. Note: also useful **outside** newsletter (e.g. burst password-reset emails after a forgot-password attack) but newsletter is where it matters most.
- **WP-Cron health diagnostic on the Newsletters page — ✅ shipped.** The send-pipeline depends on `SendCron` (1-min interval) firing; on default WP installs that means pseudo-cron, which silently breaks on many self-hosted setups. `SendCron::handle_tick()` now stamps `lrob_etk_nl_last_cron_tick` on every fire, and `NewslettersPage::render_cron_diagnostic()` surfaces a footer panel with the last observed tick, next scheduled fire, DISABLE_WP_CRON flag, and a colour-coded health verdict (ok / slow / stalled). Still TODO if useful: a "Run send tick now" manual-trigger button (bounded by the existing per-tick budget), and surfacing the same panel on the Dashboard.
- **Send-in-progress live progress bar.** The `SendLoop` tick endpoint already returns `sent / failed / remaining / total`; the card has a `.lrob-etk-nl-send-progress` block today that fills based on the ratio. Polish needed: smoother live updates while the AJAX loop is running (visible % climbing, ETA estimate based on tick rate, per-batch flash on the progress fill), plus a per-second "X recipients/sec" indicator. Also a separate progress display when the Cron path is doing the work (admin walks away mid-send, comes back to a card showing 47% — should re-poll on page load to keep the bar moving without requiring the admin to click anything). Pairs with the cron-health diagnostic above.
- **AJAX ↔ Cron handoff verification.** Scenario: admin clicks Send-now, AJAX loop sends 200 recipients, admin closes the tab. SendCron picks up the still-`sending` newsletter on its next tick (1 min later) and continues. Admin comes back 5 min later, opens the card — needs to: (a) reflect actual progress from the companion-row counters (already does via initial render), (b) re-attach the AJAX loop if the cron is still going so the bar continues updating live (currently does NOT — admin sees the static "X / Y sent" from page-load and would need to refresh). Verify the existing flow end-to-end with a real partial-send scenario and add the missing piece: when the card opens with status=`sending`, the JS should start a poll loop hitting the tick endpoint (or a new lighter `progress` endpoint that just reads counters) every ~3 seconds and update the bar until status flips to `sent`. Same approach as the existing in-flight AJAX loop, just driven by polling instead of self-tick.
- **Injection safety from stored email content** — *the touchy one.* `logs.body_html`, `subject`, `from_name`, headers, attachment filenames are attacker-controllable once mail-receive lands. Everywhere they're rendered or re-emitted:
  - **Admin UI**: escape on output. `body_html` viewing must use an isolated sandboxed iframe — never inject into the admin DOM. Subject/headers through `esc_html`, filenames through `esc_attr`. There's no "trusted" log row.
  - **Resend / forward / reply paths**: never reuse stored HTML as outbound body without re-sanitizing. PHPMailer doesn't sanitize for you. Header values must be CRLF-stripped (Resender already does this — keep it).
  - **CSV / export**: prefix any cell starting with `=`, `+`, `-`, `@`, tab, or CR with a single quote to neuter spreadsheet formula injection.
  - **Search / filter inputs**: already parameterized via `$wpdb->prepare`, keep it that way; never concatenate user input into SQL.
  - Treat any stored field as if it came directly from a hostile sender — because eventually it will.
