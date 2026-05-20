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

**Note on version skips:** the early milestones (SMTP+Logging, Contact Form milestone-1) were under-numbered as patch bumps — the project landed at 0.0.7 when it should have been around 0.3.x by the milestone rule. The Captcha multi-context routing + first hosted provider iteration is being released as 0.1.0 to start re-aligning. Don't retro-renumber past releases; from 0.1.0 onward, follow the milestone rule strictly.

## Pre-1.0 release prerequisites

Before the first 1.0 release ships, two things must be done:

1. **Make the GitHub repo public.** Until then the plugin only ships as a manually-distributed zip from `../releases/`.
2. **Build a GitHub-hosted auto-update mechanism**, mirroring the one in https://github.com/LRob-FR/wp-lrob-calendar. The reference plugin uses the standard "update from GitHub releases" pattern: a small PHP class hooks into `pre_set_site_transient_update_plugins` + `plugins_api`, fetches the latest release tag from the GitHub API, compares it to `LROB_ETK_VERSION`, and points WordPress at the release-zip asset. When implementing here:
   - Reuse that plugin's class shape so admins who run both plugins see consistent behaviour.
   - Hook + class names follow this plugin's `lrob_etk_` / `LRob\EmailToolkit\` prefixes — don't copy `lrob_calendar_` identifiers.
   - Inspect the calendar repo's implementation (fetch via WebFetch when starting that task) rather than guessing — the exact transient shape and GitHub API call format matter.
   - This is not "Integrations module" work; it lives under `src/Support/` or a small dedicated `src/AutoUpdate/` namespace.

Neither is in scope for any current module's work — they're a 1.0 release-gate, tracked here so we don't ship 1.0 without them.

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

1. ~~**SMTP + Logging**~~ — shipped in v0.0.1.
2. ~~**Contact Form**~~ — shipped in v0.0.7. WYSIWYG field editor (drag-drop, snap-to-col, inline settings strip, inline option editor for select/radio/checkbox with per-option ★ default markers), settings restructure (Essentials / Style / Advanced collapsed), recipient list widget, Reply-To dropdown of form's email fields, Subject + Success as free-mode comboboxes, per-form captcha picker with live preview (real hCaptcha widget in the editor when an identity is configured), Defaults modal, two built-in challenges (Math, Image-recognition with 20 SVG icons), submission logging with `captcha_slug` + `captcha_outcome` tracking, auto-generated slugs (`<type>_<sluggified-label>_<nth>`) with stable creation-order indices, required-by-default on new fields, Gutenberg embed block.
3. ~~**Captcha**~~ — shipped in v0.1.0. Service module (always on). Per-context routing (`contact_form`, `comments`, `newsletter_subscribe`, `lost_password`, `registration`) backed by `lrob_etk_captcha_context_map`. Multi-identity per provider via `wp_lrob_etk_captcha_identities` (AES-256-GCM encrypted credentials). Built-in challenges: Math, Image-recognition. Hosted provider: hCaptcha (Turnstile/reCAPTCHA designed to plug in via `Providers/`).
4. **IMAP save-to-sent** ← next chunk. Extends Logging — store outbound emails into the user's IMAP Sent folder via the same identity that sent them. Identity gets new IMAP credential fields (host/port/encryption/username/password — same AES-256-GCM model). Async dispatch via WP-Cron so admin pages don't block on IMAP RTT. Failure mode: log entry annotated with `imap_save_failed` event but the outbound email is already gone — never re-send to recover.
5. **Newsletter** (with importer from the Newsletter plugin). Big chunk.
6. **Cross-feature captcha & subscribe-to-comments** — captcha for comments/lost-password/registration plus a subscribe-to-comments visitor feature. Depends on the captcha infrastructure being battle-tested with Contact Form (✓) and Newsletter signup.
7. **Integrations** (webhooks: Slack/Discord/Matrix/n8n presets + generic).

Still-pending Contact Form work that doesn't gate the next milestone:
- Visual customization + animations (named presets, hover/focus glow, submit-success celebration) — see [[project-contact-form-visual-polish]].
- More homemade anti-bot challenges to round out the pool — see backlog "Homemade anti-bot question pool".
- **Multi-recipient base.** Today a form has a single recipient. Allow comma-separated emails on the per-form Recipients field + global Defaults. Validation path (`SubmitHandler::send_notification_email`) should iterate. Prereq for conditional routing below.
- **Conditional recipient routing** ([[project-contact-form-conditional-recipients]]). Admin picks a select/radio field on the form and maps each option value → recipient(s). Submission goes to the matching rule's recipient(s); fallback is the regular per-form recipient. Rules stored per-form as `_lrob_etk_cf_recipient_rules` post meta. Resolution lives in a new `Settings::effective_recipients(int $form_id, array $values): array`. Each rule supports comma-separated recipients once the multi-recipient base is in.

Don't start a later module until the previous is functionally stable.

## Deployment workflow — read this before claiming a fix is live

The user runs the plugin from the release zip, not from the working tree. **Every PHP change must be followed by `./release.sh`**, otherwise the user is still running stale code. Treat "edit done" as "not deployed" until the rebuild step has run.

CSS/JS pick up a `filemtime`-based cache-bust query string when `WP_DEBUG` is on (`Assets::asset_version()`). In production they use plugin version, so a CSS-only fix in a release still needs a version bump or a hard refresh.

## UI patterns established in v0.0.1 — match these in new modules

The admin UI deliberately does **not** use core WP defaults (`.wrap`, `WP_List_Table`, `notice notice-success`, `<select>`, `<datalist>`, etc.). Shared components live across the per-feature CSS files (`admin/css/admin-base.css`, `admin-components.css`, `admin-dashboard.css`, `admin-smtp.css`, `admin-logging.css`, `admin-contact-form.css`, `admin-captcha.css`) under `.lrob-etk-*`, plus the shared PHP renderers in `src/Admin/`. Reuse — don't reinvent — these when building any new module's admin screens:

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

Two flavours of "challenge" coexist in `src/Modules/Captcha/`:

- **Homemade challenges** (`Challenges/`) — self-contained PHP-renders-HTML + verifies-server-side. `MathChallenge`, `ImageChallenge`. No external API, no credentials.
- **Hosted providers** (`Providers/`) — hCaptcha (shipped), Turnstile / reCAPTCHA designed to plug in. Loaded via vendor JS widget, verified via HTTP call to the vendor with site key + secret. Each provider can have N **identities** (credential sets) stored in `wp_lrob_etk_captcha_identities`.

`ProviderInterface` extends `ChallengeInterface` with `credential_fields()`, `validate_credentials()`, `logo_html()` — the rendering contract is otherwise identical.

### Routing model

The Captcha module owns a per-context routing map (`lrob_etk_captcha_context_map`) keyed by consumer:

```
default               → homemade:math       (site-wide default)
contact_form          → inherit             (uses the default)
comments              → inherit
newsletter_subscribe  → inherit
lost_password         → inherit
registration          → inherit
```

Routing keys are strings: `homemade:<slug>`, `identity:<int>`, `none`, or `inherit`. The `Captcha\Routing` class is the parsing/lookup boundary; `CaptchaService::resolve($context)` returns `[ChallengeInterface, decrypted_credentials]` for the effective route. Callers pass `'context' => 'contact_form'` (or other) to render/verify; advanced callers override with `'force_route' => 'homemade:image_recognition'`.

### To add a homemade challenge

1. Drop a class implementing `ChallengeInterface` into `src/Modules/Captcha/Challenges/`.
2. That's it. `Module::register_challenges()` scans both `Challenges/` and `Providers/` at boot, instantiates everything that implements `ChallengeInterface`, registers it with `CaptchaService`. No `Module.php` edit.
3. The new challenge automatically shows up in:
   - The Captcha settings page routing dropdowns (under "Built-in challenges" optgroup).
   - The per-form Anti-spam → Challenge dropdown in the Contact Form admin.
   - The Contact Form WYSIWYG editor's in-block captcha picker (via `wp_localize_script`-published `EDITOR_DATA.captchaOptions`).
4. If the challenge needs an admin-visible preview, `render(['context' => 'preview'])` should produce sensible HTML.

### To add a hosted provider (Turnstile / reCAPTCHA / etc.)

Drop a class implementing `ProviderInterface` into `src/Modules/Captcha/Providers/`. The interface requires:

- `slug()`, `label()`, `description()`, `logo_html()` — identity card chrome
- `credential_fields()` — array of `{key, label, type:'text'|'password', required, description?}` for the admin form
- `validate_credentials($values)` — returns `{credentials: [...], errors: [field => msg]}`
- `render(array $context)` — receives the active identity's decrypted credentials via `$context['credentials']`. For `$context['context'] === 'preview'` (admin editor), prefer a placeholder div when no site_key is available, or the real widget when credentials are present.
- `verify(array $post, array $context)` — `wp_remote_post` to the vendor's verify URL with secret + token + visitor IP. Returns `[bool, ?error]`.
- Optional class constant `SCRIPT_URL` — surfaced to admin JS so the in-card preview can lazy-load the vendor script.
- Optional class constant `POST_RESPONSE_FIELD` — the `$_POST` key the vendor uses for the solved token (e.g. `'h-captcha-response'`). The Captcha admin test endpoint reads this constant.

No edits to `Module.php`, `CaptchaService`, or the settings page are needed — the auto-scan plus the routing dropdowns pick up the new provider automatically. Adding an identity then makes it pickable in any routing context.

### Verification + token names

`verify()` receives the raw `$_POST` array — pick out whichever fields the challenge's `render()` emitted. Use `FormContext::is_active()` / `FormContext::instance()` from ContactForm if the token name needs to be scoped per-form (prevents one form's solved token from being replayed on another). The `MathChallenge` does this — copy that scope-by-context pattern for any new challenge that issues a signed token.

### Service-module migration trap — read before bumping `db_version_int()`

The Captcha module is a **service module** (`is_service_module() === true`, `is_enabled()` returns `true` unconditionally), so `maybe_migrate()` runs on every boot. AbstractModule's default `db_version_int() === 1` recorded version=1 on every existing site **before** the module had any install logic. Bumping to 2 then made `maybe_migrate()` take the `migrate()` branch (not `install()`), which was a no-op — schema never got created on upgrade sites. See [[project-service-module-migrate-trap]]. The fix: always override `migrate()` to forward to `install()` (idempotent — `dbDelta` + a "seed if missing" guard), and if you're recovering from an already-shipped broken bump, bump the target version one more notch so stuck sites take the migrate path again.

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
- **Homemade anti-bot question pool.** Beyond the current `MathChallenge` and `ImageChallenge`, build a small library of self-contained questions (image-letter, simple logic, etc.) and let the webmaster pick a subset; the form picks one at random per submission. Each must be self-contained (no external API, no tracking).
- **Per-context SMTP identity routing.** SMTP module currently routes by domain / explicit selection. Add a "context" mapping so the user assigns identities to email categories — WooCommerce, admin notifications, contact forms, newsletter, comments, password resets, etc. — without bloating the admin UI. Probably one small table on the SMTP settings page with sensible defaults; the matching happens in `MailRouter` from message headers / hook context. Keep the UX simple: most users want defaults, power users want overrides.
- **Contact form visual customization.** Per-form (with global defaults) settings for colours, corner roundness, background illumination, hover/focus glow, button animations, submit-success celebration animation, etc. Ships with named templates ("sober", "fancy", others). The submitter's visual experience needs to feel polished; the webmaster's editor needs to make these accessible without overwhelming. See [[project-contact-form-visual-polish]] memory.
- **Responsive preview modes for the contact-form editor (Desktop / Tablet / Phone).** Today the form card preview is a single fixed-width container (`minmax(420px, 680px)` grid track), so the admin can't see how their layout behaves at narrower viewports. Add three preview-width toggles at the top of the Forms page that constrain the form-card preview to representative widths (e.g. 1280 / 768 / 380) and re-flow columns/fields exactly as the frontend would. Tricky bits: the form card normally shares the page grid with other cards, so the toggle would need to either swap the grid layout (single-column when a non-Desktop mode is active) or scope the resize to one card at a time. Worth doing once visual customization lands — it'll be the natural place to verify a chosen preset across breakpoints. Until then, the 680px upper bound is a pragmatic compromise that gives hCaptcha (303px min) breathing room without rebuilding the chrome.
- **Subscribe-to-comments.** Visitor-facing feature (low priority, after newsletter): when someone comments on a post, offer to receive email when they get a reply. Must include proper unsubscribe (per-comment-thread token, list-unsubscribe header, dedicated page). Don't reinvent the wheel — there's a "Subscribe to Comments" plugin that's the reference for behaviour, but we implement ours so it integrates with the toolkit's SMTP routing + captcha + logging.
- **Email export.** Bulk export of log entries (CSV at minimum, possibly mbox/EML for full message reconstruction). `LogRepository` already has filtered query helpers; export should reuse them, not re-implement filtering. Stream the response — don't buffer thousands of rows in memory.
- **Injection safety from stored email content** — *this is the touchy one.* `logs.body_html`, `subject`, `from_name`, header values, and attachment filenames are attacker-controllable when the site receives mail (future IMAP-save, future reply-to-log flows, contact form reflection, etc.). Everywhere this data is rendered or re-emitted:
  - **Admin UI rendering:** escape on output. `body_html` viewing must use an isolated iframe (sandbox attribute, no JS, no same-origin) — never inject into the admin DOM. Subject/headers must go through `esc_html`. Filenames through `esc_attr` / `esc_html`. There's no "trusted" log row.
  - **Resend / forward / reply paths:** never reuse stored HTML as a draft body for outbound mail without re-sanitizing. PHPMailer doesn't sanitize for you. Header values must be CRLF-stripped before being passed back to `wp_mail` to prevent header injection.
  - **CSV / export:** prefix any cell starting with `=`, `+`, `-`, `@`, tab, or CR with a single quote to neuter spreadsheet formula injection.
  - **Search / filter inputs:** already parameterized via `$wpdb->prepare`, keep it that way; never concatenate user input into SQL.
  - When unsure, treat any stored field as if it came directly from a hostile sender — because eventually it will.
