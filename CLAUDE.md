# CLAUDE.md

Guidance for Claude Code sessions working in this repository.

## Project

WordPress plugin **LRob - Email Toolkit** (slug `lrob-email-toolkit`). Modular all-in-one email plugin. Each module (SMTP, Logging, Contact Form, Newsletter, future Integrations) is independently activatable. Requires PHP 8.1+ and WordPress 6.0+.

## Build / lint / release

`./release.sh` is the single build entry point. **Run it yourself whenever needed** — no need to ask the user. It:

1. `php -l` lints every PHP file in the repo.
2. Regenerates `languages/lrob-email-toolkit.pot` via `wp i18n make-pot`.
3. `msgmerge`s the fresh POT references into every `languages/*.po`.
4. Compiles every `.po` → `.mo` with `msgfmt`.
5. Generates `languages/*.json` from each `.po` via `wp i18n make-json --no-purge` (needed once Gutenberg blocks ship — harmless before).
6. Zips the plugin into `../releases/lrob-email-toolkit-<version>.zip`, excluding `.sh`, `.po`, `.pot`, `.git*`, `.claude/`, `CLAUDE.md`, `README.md`, `vendor/`, `node_modules/`, `composer.*`, `phpcs.*`, `phpstan.*`.

No PHPUnit, PHPCS, or PHPStan config yet — don't invent commands.

## Versioning

Single source of truth: `lrob-email-toolkit.php` has both the `Version:` header and `LROB_ETK_VERSION` constant — bump them together.

The plugin is in **iterative pre-1.0 development**. Every shipped iteration is a patch bump; minor bumps are reserved for completed module milestones:

- **Patch (0.0.X → 0.0.X+1)** — every dev iteration during a module's build: new features, refactors, fixes, schema changes. This is the normal cadence.
- **Minor (0.X.0 → 0.X+1.0)** — only when a module reaches a feature-complete milestone (one of the phases in [Build order](#build-order)). E.g. 0.1.0 = SMTP + Logging stable, 0.2.0 = Contact Form fully shipped (incl. captcha + submissions inbox), etc.
- **Major (0.x → 1.0)** — first public release. From there: standard SemVer.

Bump at the end of each piece of work, before running `./release.sh`. Don't ship two releases with the same version — the zip filename uses it. Don't bump aggressively just because something "feels big" — the milestone rule is conservative on purpose so the version conveys real maturity.

## Naming convention — **MANDATORY**

User has a strong rule: prefixes must be plugin-specific, not vendor-wide. Several LRob plugins coexist; "lrob_" alone collides. This plugin uses `etk` (= "email toolkit") everywhere a runtime identifier appears.

| Layer | Prefix | Examples |
|---|---|---|
| PHP namespace | `LRob\EmailToolkit\` | `LRob\EmailToolkit\Modules\SMTP\Module` |
| Hooks (actions/filters) | `lrob_etk_` | `lrob_etk_event`, `lrob_etk_email_sent` |
| Constants | `LROB_ETK_` | `LROB_ETK_VERSION`, `LROB_ETK_PATH`, `LROB_ETK_URL`, `LROB_ETK_FILE`, `LROB_ETK_BASENAME` |
| DB tables | `{wpdb->prefix}lrob_etk_` | `wp_lrob_etk_logs`, `wp_lrob_etk_identities` |
| Options (in `wp_options`) | `lrob_etk_` | `lrob_etk_modules`, `lrob_etk_smtp_settings`, `lrob_etk_<slug>_db_version` |
| REST namespace | `lrob-etk/v1` | `/wp-json/lrob-etk/v1/logs` |
| Capability | `manage_lrob_etk` | granted to `administrator` on activate |
| Text domain | `lrob-email-toolkit` | unchanged (human-readable, also the plugin slug) |
| CSS classes / JS globals | `lrob-etk-` / `lrobEtk` | `lrob-etk-form`, `window.lrobEtk` |

Anything Claude adds — new option key, new table, new hook, new CSS class — **must** follow these prefixes. No exceptions.

## Architecture

### Entry point and autoloader

`lrob-email-toolkit.php` defines constants, registers a PSR-4 autoloader (`LRob\EmailToolkit\Foo\Bar` → `src/Foo/Bar.php`), and boots `LRob\EmailToolkit\Plugin::instance()->boot()` on `plugins_loaded`.

No Composer at runtime by design — user explicitly distrusts library bloat. The autoloader is hand-rolled PSR-4. If a Composer dev dependency is added later (phpcs, phpstan), it must NOT be required at runtime and must NOT ship in the release zip.

### Plugin lifecycle

- `src/Activator.php::activate()` runs on activation. Grants `manage_lrob_etk` to administrator, seeds `lrob_etk_modules` (all modules disabled by default).
- `src/Deactivator.php::deactivate()` runs on deactivation. Clears every cron event whose hook name starts with `lrob_etk_`. Does **not** drop data.
- `uninstall.php` runs on plugin deletion. Drops every `{prefix}lrob_etk_*` table, every `lrob_etk_*` option, every `lrob_etk_*` cron event, and removes the capability from every role. Belt-and-suspenders: prefix scan handles modules that forgot to declare their own uninstall logic.

### Module framework

`src/Modules/ModuleInterface.php` + `AbstractModule.php` + `ModuleManager.php`.

- Modules are hard-coded in `ModuleManager::module_classes()` — no filesystem scanning, no plugin-extends-plugin. Adding a module is an explicit code change to that list.
- `discover()` instantiates every module class. `boot_enabled()` calls `register()` only on the ones whose slug is `true` in the `lrob_etk_modules` option AND whose `requires()` deps are also enabled.
- `enable($slug)` / `disable($slug)` toggle the option. `enable()` also calls `install()`. `install()` must be idempotent (uses `dbDelta`).
- Disabling a module does NOT drop its data. Data only drops in `uninstall.php`.

### Naming and namespace mapping

Module class names use the studly-cased slug:
- slug `smtp` → namespace `LRob\EmailToolkit\Modules\SMTP\Module`
- slug `logging` → `LRob\EmailToolkit\Modules\Logging\Module`
- slug `contact_form` → `LRob\EmailToolkit\Modules\ContactForm\Module`
- slug `newsletter` → `LRob\EmailToolkit\Modules\Newsletter\Module`

### Container

`src/Container.php` is a tiny service locator — `set()`/`get()`/`has()`. Modules drop their public services in there so other modules can read them without globals. Not a full DI container; constructor injection is the norm.

### Encryption

`src/Support/Encryption.php` — AES-256-GCM with a key derived from `AUTH_KEY` via HKDF-SHA256, info-tag `lrob_etk_v1`. Output format: base64(version(1) || iv(12) || tag(16) || ciphertext). Throws `RuntimeException` if `AUTH_KEY` is missing or the placeholder. Use this for SMTP/IMAP passwords and any credential at rest. If `AUTH_KEY` ever changes, old ciphertexts are unrecoverable — callers must catch the exception and prompt the user.

### Events — public API from v0.0.1

Every module emits events via `\LRob\EmailToolkit\Support\Events::dispatch($name, $payload)`, which fires both:

- **Generic** action: `do_action('lrob_etk_event', $name, $payload)` — one listener catches every event. The future Integrations module uses this.
- **Typed** action: `do_action('lrob_etk_' . str_replace('.', '_', $name), $payload)` — devs hook a specific event.

Event names are dot-namespaced (`domain.action[.detail]`). Adding events is fine; **renaming or removing** an event is a breaking change. The vocabulary:

```
email.sending              email.sent              email.failed
email.imap_saved           email.imap_save_failed
contact_form.submitted     contact_form.spam_blocked    contact_form.delivered
newsletter.campaign.created     newsletter.campaign.scheduled
newsletter.campaign.started     newsletter.campaign.completed
newsletter.recipient.sent       newsletter.recipient.failed
newsletter.subscriber.added     newsletter.subscriber.unsubscribed
newsletter.tracking.opened      newsletter.tracking.clicked
```

### Admin UI

Server-rendered PHP, `WP_List_Table` where lists are needed, vanilla JS for AJAX (no React/JSX, no build pipeline). The shared admin menu is `admin.php?page=lrob-etk` (top-level "Email Toolkit"). Module-specific submenus are added by each module's own admin code.

## Conventions to follow

- **Strict types**: every PHP file in `src/` starts with `declare(strict_types=1);`. Continue this.
- **Final classes**: most classes are `final` unless explicitly meant for subclassing (AbstractModule is the exception).
- **Constructor property promotion**: PHP 8.1+ is the minimum, use it freely.
- **No mock/stub/fallback code paths for things that can't happen.** Internal code trusts its callers; only validate at WP REST/admin/form boundaries.
- **One-line doc comments only where the WHY is non-obvious.** Don't narrate WHAT — class/method names already do that.
- **No backwards-compat shims** while the version is < 1.0.0 — schema can change freely between minor versions.

## Build order

1. ~~**SMTP + Logging**~~ — done in v0.0.1.
2. **Contact Form** ← in active development (v0.0.7). Done so far: WYSIWYG field editor (drag-drop, snap-to-col, **inline settings strip** instead of the retired gear popup, inline option editor for select/radio/checkbox with per-option ★ default markers), settings restructure (Essentials / Style / Advanced collapsed), recipient list widget, Reply-To dropdown of form's email fields, Subject + Success as free-mode comboboxes, per-form captcha kind picker with live preview, Defaults modal, two captcha challenges (Math, Image-recognition with 20 SVG icons), submission logging with `captcha_slug` + `captcha_outcome` tracking, **auto-generated slugs** (`<type>_<sluggified-label>_<nth>`) with stable creation-order indices that survive reordering/deletions, **required-by-default** on new fields with the `*` morphing into a labelled "Required" checkbox on hover, **option labels** (not raw values) in submission emails, **Gutenberg embed block** survives click-away + recovers gracefully when its source form is deleted. **Still pending:**
   - Visual customization + animations (named presets, hover/focus glow, submit-success celebration) — bigger chunk, see [[project-contact-form-visual-polish]].
   - More homemade anti-bot challenges to round out the pool (image-letter, simple logic, etc.) — see backlog "Homemade anti-bot question pool".
   - **Multi-recipient base.** Today a form has a single recipient. Allow comma-separated emails on the per-form Recipients field + global Defaults. Validation path (`SubmitHandler::send_notification_email`) should iterate. Prereq for conditional routing below.
   - **Conditional recipient routing** ([[project-contact-form-conditional-recipients]]). Admin picks a select/radio field on the form and maps each option value → recipient(s). Submission goes to the matching rule's recipient(s); fallback is the regular per-form recipient. Rules stored per-form as `_lrob_etk_cf_recipient_rules` post meta — JSON list of `{field_slug, value, recipient}`. UI: per-form card, a small "Routing" panel under Delivery, dropdown to pick the routing field then one row per value→recipient pair. Resolution lives in a new `Settings::effective_recipients(int $form_id, array $values): array` that inspects submitted values, returns the matching rule's recipient list, falls back to the static recipient(s) otherwise. Each rule supports comma-separated recipients once the multi-recipient base is in.
3. **IMAP save-to-sent** (extends Logging).
4. **Newsletter** (with importer from the Newsletter plugin). Big chunk.
5. **Cross-feature captcha & subscribe-to-comments** (see backlog) — depends on the shared captcha infrastructure being settled, so comes after Newsletter.
6. **Integrations** (webhooks: Slack/Discord/Matrix/n8n presets + generic).

Don't start a later module until the previous is functionally stable. The skeletons for all four foundation modules already exist so the framework boots cleanly with everything disabled.

## Deployment workflow — read this before claiming a fix is live

The user runs the plugin from the release zip, not from the working tree. **Every PHP change must be followed by `./release.sh`**, otherwise the user is still running stale code. Treat "edit done" as "not deployed" until the rebuild step has run.

CSS/JS pick up a `filemtime`-based cache-bust query string when `WP_DEBUG` is on (`Assets::asset_version()`). In production they use plugin version, so a CSS-only fix in a release still needs a version bump or a hard refresh.

## UI patterns established in v0.0.1 — match these in new modules

The admin UI deliberately does **not** use core WP defaults (`.wrap`, `WP_List_Table`, `notice notice-success`, `<select>`, `<datalist>`, etc.). All shared components live in `admin/css/admin.css` under `.lrob-etk-*` and `src/Admin/`. Reuse — don't reinvent — these when building Contact Form admin screens:

- **Card grid** for entity lists: `display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 540px))` so a single card doesn't stretch full width.
- **Auto-save edit cards**: existing rows save on `blur`/`change` with a small status indicator; new rows have an explicit "Create" button. Reference: `Modules/SMTP/Admin/SettingsPage.php` + its `AjaxController`.
- **Inline module toggle** next to the page `<h1>` via `Admin\ModuleToggle::render_inline()`. `render_bar()` is deprecated, don't use it.
- **Anchored popovers** (`.lrob-etk-popover`) — JS positions them via `getBoundingClientRect` relative to the trigger button. Used for SMTP test-send, connection-test details, log cleanup, log settings, dashboard test email. Use this instead of a centered modal whenever the action belongs to a specific button.
- **Custom combobox** (`.lrob-etk-combo`, input + dropdown menu) — used for SMTP host / From email / From name. `<datalist>` is banned (inconsistent cross-browser).
- **Custom data table** (`.lrob-etk-logs-table`) — replaces `widefat striped`. Used in the logs page and the dashboard recent-activity widget.
- **Tooltips** (`Admin\Tooltip::render()`) — `position: fixed` with JS-set coordinates so they don't get clipped by scroll containers or popovers. Tip text has explicit `text-transform: none` because parent labels use uppercase.
- **CSS gotcha**: WP's `.button { display: inline-block }` overrides the HTML `[hidden]` attribute. `.lrob-etk [hidden] { display: none !important }` is the fix — keep it.

## Per-module AJAX

Each module's admin lives under `Modules/<Name>/Admin/AjaxController.php`. One shared nonce per module (e.g. `lrob_etk_smtp_ajax`, `lrob_etk_logging_ajax`), one `action` value per endpoint dispatched via a `type` field, JSON in/out. `manage_lrob_etk` gates every handler. Follow this shape for Contact Form — don't introduce REST routes unless an external integration actually needs them.

## SQL + timezone

WordPress stores `DATETIME` columns in the **server session timezone**, which is unstable across hosts and DST. Avoid `UNIX_TIMESTAMP()`. For bucket/grouping queries:

```sql
FLOOR(TIMESTAMPDIFF(SECOND, '2000-01-01 00:00:00', created_at) / %d) * %d + 946684800 AS bucket_ts
```

`946684800` is the UTC epoch of `2000-01-01 00:00:00`. Used by `Modules/Logging/LogRepository::counts_by_bucket()`; copy this pattern for any new time-bucketed aggregation. Display-side: render in browser-local via JS `Date` methods, not server-formatted strings.

## Resender — do not regress

`Modules/Logging/Resender::resend()` creates a new log row for the retry and leaves the original untouched. Earlier code marked the original as `retried`, which made stats undercount sends. Don't reintroduce a status flip on the original.

## From / transport resolution

SMTP identity rows store `from_email` and `from_name` that may be empty — meaning "fall back at send time". `Identity::effective_from_email()` returns `smtp_username` if `from_email` is empty; `effective_from_name()` returns the site title. Per-identity `transport` (`smtp` | `mail`) is honored by `MailRouter` and `TestSender` — `mail` skips PHPMailer SMTP wiring entirely. New per-identity behavior should follow the same `effective_*` accessor pattern rather than baking fallbacks into call sites.

## Attachments in logs

`logs.attachments` is JSON: `[{"name": "...", "path": "..."}]`. `LogEntry::normalize_attachments()` upgrades legacy string-only entries to that shape — keep it as long as old rows can exist. Resend re-attaches files whose `path` still resolves on disk and reports `attachments_dropped` for the rest. Archiving the actual file bytes is explicitly out of scope for now (deferred low-priority).

## Captcha module: adding a challenge / provider

Two flavours of "challenge" exist in `src/Modules/Captcha/`:

- **Homemade challenges** — self-contained PHP-renders-HTML + verifies-server-side. `MathChallenge`, `ImageChallenge`. No external API.
- **Hosted providers** (planned) — hCaptcha, Cloudflare Turnstile, Google reCAPTCHA. Loaded via vendor JS widget, verified via HTTP call to the vendor with site key + secret.

Both implement the same `ChallengeInterface` (`slug()`, `label()`, `description()`, `render(array $context)`, `verify(array $post, array $context)`).

### To add a homemade challenge

1. Drop a class implementing `ChallengeInterface` into `src/Modules/Captcha/Challenges/`.
2. That's it. `Module::register_challenges()` scans the directory at boot, instantiates everything that implements the interface, registers it with `CaptchaService`. No `Module.php` edit, no glue code.
3. The new challenge automatically shows up in:
   - The Captcha settings page picker.
   - The per-form Anti-spam → Challenge dropdown in the Contact Form admin.
   - The Contact Form WYSIWYG editor's in-block captcha picker (via `wp_localize_script`-published `EDITOR_DATA.challenges`).
4. If the challenge needs an admin-visible preview, `render(['context' => 'preview'])` should produce sensible HTML. `SettingsPage` already embeds this for each registered challenge.

### To add a hosted provider (hCaptcha / Turnstile / reCAPTCHA)

Bigger lift — three things every external provider needs that the homemade ones don't:

1. **Per-provider config** (site key, secret key, optional score threshold, language pref). Lives in a provider-scoped option key — pattern: `lrob_etk_captcha_<slug>_settings`. The Captcha settings page renders a card per registered provider, each card containing the config inputs read from that option.
2. **Async JS widget**. `render()` returns the placeholder `<div>` with `data-sitekey` etc.; a small enqueued shim loads the vendor's script and binds. Either enqueue conditionally per request (only when the active challenge is this provider) or include in the toolkit's frontend bundle behind a feature flag.
3. **Server-side verification call** in `verify()` — `wp_remote_post` to the vendor's verify URL with the secret + submitted token + visitor IP. Return `[false, $error]` on any non-success.

Two future-shape decisions (memories [[project-captcha-architecture-next]] and [[project-captcha-admin-preview-pending]]) that should land **together with the first external provider**, not later:

- **Per-context default challenges**: every consumer (`contact_form`, `comments`, `newsletter_subscribe`, `lost_password`, `registration`) picks its own default independently. The current `CaptchaService::render($context)` signature already takes the context — replace the single `active_challenge` option with a `lrob_etk_captcha_context_map` array keyed by context.
- **Provider identities** (multi-credential, mirrors SMTP identities): `wp_lrob_etk_captcha_identities` table with `provider`, `label`, `credentials` (encrypted JSON via `src/Support/Encryption.php`). Each context entry points at an identity, not just a provider. UI reuses the `.lrob-etk-card-*` primitives from `admin-components.css`.

Bolting these on after the fact means rewriting the routing layer twice — do them once.

### Verification + token names

`verify()` receives the raw `$_POST` array — pick out whichever fields the challenge's `render()` emitted. Use `FormContext::is_active()` / `FormContext::instance()` from ContactForm if the token name needs to be scoped per-form (prevents one form's solved token from being replayed on another). The `MathChallenge` does this — copy that scope-by-context pattern for any new challenge that issues a signed token.

## Contact Form WYSIWYG editor (`admin/js/contact-form-fields-editor.js`)

Single-IIFE, ~1900 lines. Drives every Contact Form card's field editor (preview + overlays + drag-drop + inline settings strip + inline option editor + serializer + sticky-hover state). Section map for navigation — line numbers drift, search by the `// --- Name ---` marker:

| Section | What lives here |
|---|---|
| Undo / redo history | Snapshot stack of `form.innerHTML`. One snapshot per discrete action (insert/delete/drag/blur). `HISTORY_MAX = 50`. Typing in contenteditables only snapshots on blur, so undo skips whole words. |
| Save plumbing | `serialize(form)` → `FormData` → ajax `lrob_etk_cf_save_structure`. Debounced via `saveTimer`. Status indicator states: `is-saving` / `is-saved` / `is-error`. |
| Click dispatcher | Single delegate listener on `form`. Routes by `[data-insert]`, `[data-action]`, `[data-edit]`. |
| Inline editables | Labels / helpers / submit text are `contenteditable="plaintext-only"` with `data-edit="label\|helper\|submit-text"`. Placeholder swap on focus/blur. |
| Drag-and-drop | `draggedItem`, `dragType` (`field`\|`row`\|`col`). Hover targets picked via `pickDropHover()`. "Snap-to-col" rule: dropping in a row's middle vertical band picks the col whose `midX` > cursor. Source-row collapse: drops landing inside the dragged item's own row resolve to extract above/below. See [[project-drag-image-gotcha]]. |
| Normalize insert zones | After every mutation, rebuild the `+ Field` / `+ Row` / `+ Column` insert pattern from scratch in each container — keeps the canonical "one insert between every pair plus one trailing" invariant. |
| Insert zone state | `.is-orphan` class for the sole insert in an empty container (renders as a dashed drop-zone instead of a thin pill). `.is-drop-on-insert` during active drag. |
| Mutators | `addField`, `deleteField`, `addRow`, `addColumn`, `moveField`, etc. Each commits exactly one history snapshot. |
| Inline settings strip | Per-field type-specific knobs (`maxLength`, `rows`, `min/max/step`, `pattern`, `placeholder` combobox for select, `multiple` for checkbox, `align` for submit) inside `.lrob-etk-cf-inline-settings`. Lives at the bottom of `.lrob-etk-cf-field` with the same `max-height` collapse pattern as the empty-helper reveal. `[data-inline-prop]` inputs auto-write to `data-attr-*` on the shell. No slug chip — slugs are auto-derived. |
| Sticky hover state | JS-managed `.is-active` class on the shell whose bounding rect (or 10px buffer around it) contains the cursor. Pairs with CSS `:hover`/`:has(+ pill:hover)`/`.is-active` so the field stays expanded across the "+ Field" pill in the gap below — pill stays clickable instead of moving up as the field collapses mid-reach. |
| Required toggle | `.lrob-etk-cf-required-control` holds two siblings: a visual-only `.lrob-etk-cf-required-star` (`*`, red when on) and a hover-revealed `.lrob-etk-cf-required-check` (`<label>` wrapping a real checkbox + "Required" text). Star isn't clickable; the checkbox is the control. `[data-required-toggle]` change mirrors back to `data-attr-required` + the star's `is-on` class. New fields default to required. |
| Slug derivation | `<type>_<sluggified-label>_<nth>`. `nth` is a creation-order index stored on `data-attr-nth`, assigned via `nextNth()` once and stable across reorders/deletions — keeps slugs attached to their original field. `recomputeSlug(shell)` re-derives on label blur and writes `data-attr-slug` + `.lrob-etk-cf-field`'s `data-field`. PHP `FormStructure::enforce_unique_nths_and_slugs()` is the safety net at save time. |
| Inline option editor | `select`/`radio`/`checkbox` shells render an inline editor (`.lrob-etk-cf-options[data-options-inline]`) with one `<input> + contenteditable label + remove button` per option (plus a `★` default toggle on select), plus an `+ Add option` button. `applyOptionsToPreview()` is the canonical render; `syncOptionsFromInline()` reads edits back into `data-attr-options` (remapping `data-attr-defaults` on label renames). `inlineOptionRowHtml` deliberately does NOT wrap the input in `<label>` — wrapping would steal click focus from the contenteditable. `buildControlHtml` must emit this same shape for new fields so they're editable immediately without a reload. |
| DOM builders | `buildField(type, attrs)` / `buildRow(field)` / `buildColumn()`. Adding a new field type means extending this section + `inlineSettingsHtml` (if it has per-field knobs) + the serializer. For multi-choice types, `buildControlHtml` must seed with `inlineOptionEditorHtml` / `inlineOptionRowHtml` / `inlineAddButtonHtml`, not a static preview. |
| Drag enable/disable | Mousedown anywhere except a `.lrob-etk-cf-overlay-handle` flips `draggable="false"` on every `[data-draggable-type]` ancestor, restored on global mouseup — so text selection inside inputs / contenteditables / chips can't accidentally launch an HTML5 drag of the shell. |
| Serializer | `serialize(form)` produces `{ version: 1, rows: [{ id, columns: [{ id, fields: [...] }] }] }`. Field shape: see "Serialized field shape" below. |
| Initial sync | On first load, copies attrs from the PHP-rendered DOM into `data-attr-*` and backfills `data-attr-nth` in DOM order for legacy shells. Guards via `dataset.etkInit`. |

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

Type-specific keys appear only on relevant types. `submit` carries `text` + `align`. `captcha` carries only `id` + `type` (challenge comes from Captcha module, not per-field). `slug` is auto-derived from `<type>_<sluggified-label>_<nth>` and never user-editable; `nth` is the field's stable creation index.

### DOM contract the editor JS depends on

| Element | Required attributes |
|---|---|
| `.lrob-etk-cf-form.is-editor` | `data-form-id` on the wrapping `.lrob-etk-cf-fields` |
| `.lrob-etk-cf-row` | `data-row-id`, `data-cols` (1–4) |
| `.lrob-etk-cf-col` | `data-col-id` |
| `.lrob-etk-cf-edit-shell` | `data-field-id`, `data-field-type`, `data-attr-slug`, `data-attr-nth`, `data-attr-required`, optional `data-attr-*` for type-specific keys |
| `.lrob-etk-cf-overlay--{row\|col\|field}` | Drag handle + delete (no gear — settings live in the inline strip now) |
| `.lrob-etk-cf-insert--{row\|field\|column}` | `data-insert` action; `.is-orphan` when sole in empty container |
| `[contenteditable="plaintext-only"]` | `data-edit` value: `label\|helper\|submit-text` |
| `[data-inline-prop]` / `[data-required-toggle]` / `[data-action="toggle-default-option"]` | Inline-settings inputs / Required checkbox / per-option ★ default toggle. Change events route to `data-attr-*` and the relevant preview rebuild. |

`FormEditorRenderer.php` is the PHP that emits this DOM. **If you change the DOM contract on one side, change both.**

### Where to make common changes

- **Add a new field type:** `buildField()` switch + `buildControlHtml()` switch (with inline option editor seed if it's multi-choice) + `serialize()`'s data-attr list + `typeSpecificInlineHtml()` switch (for the inline settings strip) + `FieldRenderer.php` (frontend) + `FormEditorRenderer.php` (editor preview).
- **Add a new per-field setting:** add a chip to `typeSpecificInlineHtml()` writing `data-inline-prop="X"` + read it in `serialize()` via `data-attr-X` + PHP side in `FieldRenderer` + the form structure schema in `FormStructure.php`.
- **Tweak the inline option editor (multi-choice):** start at `applyOptionsToPreview` / `renderSelectPreview` / `renderOptionGroupPreview` / `syncOptionsFromInline`. Mirror any DOM shape change in `buildControlHtml` so newly-created fields don't need a reload to match.
- **Tweak a drag-drop behaviour:** start at `pickDropHover()` / `computeDropDirection()` / `sameScope()`. Add `console.log` in those three to trace.
- **Fix a save-status edge case:** `Save plumbing` section, `setStatus()` function.
- **Add an undo-able action:** wrap it with `commit()` at the end. Single commit per user action.

## Backlog — keep these in mind when designing related code

Not in scope now, but architectural decisions in the current module may make these easier or harder later. Don't build them yet, but don't paint into a corner either.

- **Email reading in a modal with prev/next navigation.** Today the logs detail view is a row expansion. Future: open a full-screen-ish modal with `←` / `→` keys cycling through the current filtered/paginated list. When refactoring `LogsPage` rendering, keep the row→detail mapping addressable by index, not just by row click handler.
- **Shared captcha settings module.** Captcha providers (hCaptcha, Cloudflare Turnstile, Google reCAPTCHA, plus the plugin's own homemade challenges — currently just `MathChallenge`, more to come) must be configured once and reused. Consumers planned: Contact Form, Newsletter subscribe, WP comments, lost-password form, registration form. When building Contact Form, put captcha provider config under a shared location (likely `src/Support/Captcha/` with its own settings sub-page under the toolkit menu) — not inside `Modules/ContactForm/`. Each module just *consumes* a captcha service.
- **Homemade anti-bot question pool.** Beyond the current single `MathChallenge`, build a small library of self-contained questions (image-letter, simple logic, etc.) and let the webmaster pick a subset; the form picks one at random per submission. Each must be self-contained (no external API, no tracking).
- **Per-context SMTP identity routing.** SMTP module currently routes by domain / explicit selection. Add a "context" mapping so the user assigns identities to email categories — WooCommerce, admin notifications, contact forms, newsletter, comments, password resets, etc. — without bloating the admin UI. Probably one small table on the SMTP settings page with sensible defaults; the matching happens in `MailRouter` from message headers / hook context. Keep the UX simple: most users want defaults, power users want overrides.
- **Contact form submission logging with captcha-used tracking.** Every contact-form submission gets a row in a submissions table (separate from `logs`), recording which captcha provider/challenge was active at the time and whether it was solved cleanly. This lets the user spot a captcha letting spam through and switch providers.
- **Contact form visual customization.** Per-form (with global defaults) settings for colours, corner roundness, background illumination, hover/focus glow, button animations, submit-success celebration animation, etc. Ships with named templates ("sober", "fancy", others). The submitter's visual experience needs to feel polished; the webmaster's editor needs to make these accessible without overwhelming. See [[project-contact-form-visual-polish]] memory.
- **Subscribe-to-comments.** Visitor-facing feature (low priority, after newsletter): when someone comments on a post, offer to receive email when they get a reply. Must include proper unsubscribe (per-comment-thread token, list-unsubscribe header, dedicated page). Don't reinvent the wheel — there's a "Subscribe to Comments" plugin that's the reference for behaviour, but we implement ours so it integrates with the toolkit's SMTP routing + captcha + logging.
- **Email export.** Bulk export of log entries (CSV at minimum, possibly mbox/EML for full message reconstruction). `LogRepository` already has filtered query helpers; export should reuse them, not re-implement filtering. Stream the response — don't buffer thousands of rows in memory.
- **Injection safety from stored email content** — *this is the touchy one.* `logs.body_html`, `subject`, `from_name`, header values, and attachment filenames are attacker-controllable when the site receives mail (future IMAP-save, future reply-to-log flows, contact form reflection, etc.). Everywhere this data is rendered or re-emitted:
  - **Admin UI rendering:** escape on output. `body_html` viewing must use an isolated iframe (sandbox attribute, no JS, no same-origin) — never inject into the admin DOM. Subject/headers must go through `esc_html`. Filenames through `esc_attr` / `esc_html`. There's no "trusted" log row.
  - **Resend / forward / reply paths:** never reuse stored HTML as a draft body for outbound mail without re-sanitizing. PHPMailer doesn't sanitize for you. Header values must be CRLF-stripped before being passed back to `wp_mail` to prevent header injection.
  - **CSV / export:** prefix any cell starting with `=`, `+`, `-`, `@`, tab, or CR with a single quote to neuter spreadsheet formula injection.
  - **Search / filter inputs:** already parameterized via `$wpdb->prepare`, keep it that way; never concatenate user input into SQL.
  - When unsure, treat any stored field as if it came directly from a hostile sender — because eventually it will.
