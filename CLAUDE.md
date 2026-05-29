# CLAUDE.md

Guidance for Claude Code sessions working in this repository. **This file is the general technical guideline layer** (conventions, mandates, gotchas, naming, architecture) — always loaded into context. For the public "what the plugin does" description, see `README.md`. For deep per-subsystem code reference, see `docs/*.md` (loaded on demand — index below).

## 📖 READ THIS FIRST, EVERY SESSION

**Before touching any code or suggesting any feature work, READ [docs/todo.md](./docs/todo.md) — specifically its "🗺️ Roadmap to 1.0" section at the top.** The user has locked the milestone sequence to 1.0 (v0.4.x → v0.5.x → … → v1.0.0). **Do not propose work from a later milestone before the current one ships.** Do not invent new priorities — if it's not in the roadmap, ask before building it.

**Then check [docs/done.md](./docs/done.md)** for what's already shipped (so you don't reinvent or duplicate). Also load memory `project_1_0_release_prerequisites` — it's the short-form pointer to the roadmap with status flags on the original 1.0 gates.

**Keep `docs/todo.md` and `docs/done.md` in sync as you work.** When a feature lands or a backlog item gains/loses scope, update both files in the same change — don't defer to "later". Stale entries (a backlog bullet for something now in `docs/done.md`, or a ✅-done line left in the todo) are bugs in the docs. When a milestone ships, flag it shipped in the roadmap section of `docs/todo.md` + move the line items to `docs/done.md`.

### Doc-sync timing — non-negotiable

- **`docs/todo.md` + `docs/done.md` — update them right after every human validation that a change is functional.** The user confirms it works → in that moment, move the shipped line out of `docs/todo.md` into `docs/done.md`. Don't batch it to "later"; a validated change that's still sitting in the todo is a stale doc.
- **`README.md`, `docs/todo.md`, `docs/done.md` must be current before every context compression AND before every build (`./release.sh`).** Treat an imminent compaction or a build as a checkpoint: reconcile these three first, so the next context window starts from accurate state and the shipped zip never reflects stale status. (`CLAUDE.md` + the relevant `docs/*.md` follow the same rule whenever conventions/architecture changed.)

## Code reference docs (`docs/`, load on demand)

Three-tier doc split — **`README.md`** = public (what the plugin does, for users); **`CLAUDE.md`** = general technical guidelines (always in context); **`docs/*.md`** = deep per-subsystem code reference, read only when you touch that subsystem.

**When you work on a subsystem, read its doc first.** When you change one, update its doc in the same change. **Prefer the doc over a big comment header in the code** — keep code comments minimal (a one-line pointer to the doc + short inline WHY comments only; see "Conventions to follow"). When a subsystem grows enough to warrant it, **create a new `docs/<area>.md`**, add it to this index, and drop a one-line pointer comment at the top of its main source file. Don't re-fold doc detail back into `CLAUDE.md` — it's deliberately split out to keep the always-loaded hub lean (memory `feedback_docs_in_claude_md`).

- **[docs/core.md](./docs/core.md)** — entry point + autoloader, lifecycle (Activator / Deactivator / `uninstall.php`), Container, ModuleManager / AbstractModule / ModuleInterface, Support (Encryption, Events, TrackingToken), AutoUpdate.
- **[docs/admin-ui.md](./docs/admin-ui.md)** — admin design tokens, shared PHP renderers (`src/Admin/`), shared JS helpers (`admin/js/etk-*.js`), CSS primitives catalog (`admin-components.css`).
- **[docs/forms.md](./docs/forms.md)** — shared form infrastructure (`src/Forms/`): field types + registry, renderers, structure/validation, captcha field, honeypot, upload policy; frontend `assets/js/form-submit.js` + `assets/css/contact-form.css`.
- **[docs/form-builder.md](./docs/form-builder.md)** — the WYSIWYG form editor (`admin/js/form-fields-editor.js`): section map, serialized field shape, DOM contract, where-to-change.
- **[docs/contact-form.md](./docs/contact-form.md)** — Contact Form module: CPT, recipients, subject/success templates, submissions inbox, the anti-spam stack.
- **[docs/smtp.md](./docs/smtp.md)** — SMTP module: identities, routing rules, From resolution, transports, test sender, source resolver.
- **[docs/logging.md](./docs/logging.md)** — Logging module: log repository, retention, resend, attachment store.
- **[docs/captcha.md](./docs/captcha.md)** — adding a homemade challenge or a hosted provider.
- **[docs/newsletter-internals.md](./docs/newsletter-internals.md)** — list kinds / system lists / visibility, rule providers, audience-picker JS contract, subscriber profile fields + form mapping, send pipeline, tracking.

## Project

WordPress plugin **LRob - Email Toolkit** (slug `lrob-email-toolkit`). Modular all-in-one email plugin. Each module (SMTP, Logging, Contact Form, Captcha, Newsletter, future Integrations) is independently activatable. Requires PHP 8.2+ and WordPress 6.8+.

## Build / lint / release

`./release.sh` is the single build entry point. **Run it yourself whenever needed**; `./release.sh 2>&1 | tail -40` shows everything that matters. It lints every PHP (`php -l`) + JS (`node --check`) file, scans CSS for dead `.lrob-etk-*` selectors, regenerates the POT + `msgmerge`s it into every `.po`, compiles `.mo`/`.json` (per-language `msgfmt --statistics` line), and zips into `../releases/lrob-email-toolkit-<version>.zip`. The zip excludes dev-only files (`docs/` — incl. todo/done —, `CLAUDE.md`, `README.md`, `*.sh`, `.git`, etc.).

No PHPUnit, PHPCS, or PHPStan config yet — don't invent commands.

**Translation workflow.** Don't translate per commit — too much churn. Run the translation pass at **milestone boundaries only**. To add a new locale, drop `lrob-email-toolkit-<locale>.po` next to `fr_FR.po` and run release.sh. To edit a `.po`, use Edit/sed directly (do not write Python parsing scripts). Fuzzy entries are flagged but NOT compiled into `.mo` until manually cleared or `msgattrib --clear-fuzzy`.

### 🚫 RELEASE GATE — translations are non-negotiable before tagging

**Before any `gh release create` / `gh release upload`, the `./release.sh` output's `msgfmt` line MUST read `N translated, 0 fuzzy, 0 untranslated`.** A release shipped with partial French is broken — users hit English fragments mid-flow. If the line isn't clean, STOP and don't tag. **The full step-by-step recovery (gap listing → hand-authored `fixes.po` → `msgcat` + `msgattrib --clear-fuzzy` → rebuild), plus the `gh release upload <tag> … --clobber` fix for an already-shipped broken release, live in memory `feedback_translations_before_every_release`.**

## Commit / push process — do this EVERY time, without being asked

When the user asks to commit (and/or push), run this whole sequence — don't make them list the steps:

1. **Sync the docs to what changed** (in the same commit, never deferred):
   - `docs/done.md` — add what shipped this batch (move it out of todo).
   - `docs/todo.md` — delete done lines, adjust the roadmap/backlog.
   - `README.md` — update the Status blurb / module table / requirements if user-facing behaviour or stack changed.
   - `CLAUDE.md` + the relevant `docs/*.md` — update conventions/tokens/architecture notes if any changed.
2. **`./release.sh`** and read the output — it MUST be green: `php -l` + `node --check` clean, dead-css scan clean, and the **translation gate** `msgfmt` line = `N translated, 0 fuzzy, 0 untranslated`. If the gate isn't green, fix `fr_FR.po` (recovery procedure above) before committing — a release with partial French is broken.
3. **Commit to `main`** — this repo's convention is WIP commits straight to `main` (don't branch). Message = the net diff vs the previous commit (see memory `feedback_commit_message_net_diff`), ending with the `Co-Authored-By:` trailer.
4. **`git push`** when the user also asked to push.

(Note: `.mo`/`.json` are build artifacts regenerated by `release.sh`; `.po`/`.pot` are committed sources — step 2 keeps them in sync.)

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

**Entry point** (`lrob-email-toolkit.php`): defines constants, registers a hand-rolled PSR-4 autoloader (`LRob\EmailToolkit\Foo\Bar` → `src/Foo/Bar.php`), boots `Plugin::instance()->boot()` on `plugins_loaded`. **No Composer at runtime** by design — distrust of library bloat. Foundation details (lifecycle, Container, Encryption, Events) in [docs/core.md](./docs/core.md).

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
- **Constructor property promotion** — PHP 8.2+ minimum.
- **No mock/stub/fallback code paths for things that can't happen.** Internal code trusts callers; validate only at WP REST/admin/form boundaries.
- **Comments: minimal.** One-line WHY comments only where non-obvious; never narrate WHAT (names already do that), and never restate shared conventions. **No big narrative header blocks in code** — that knowledge belongs in the file's `docs/*.md`; leave at most a one-line `// Docs: docs/<area>.md` pointer at the top. **Never strip load-bearing comments**: the WP plugin header in `lrob-email-toolkit.php`, `/* translators: */` notes, lint/tool directives, and the `// --- Section ---` navigation markers in `form-fields-editor.js`.
- **No backwards-compat shims** while version < 1.0.0 — schema can change freely between minor versions.
- **Don't pre-bump versions or auto-commit small changes.** Both wait for the user's cue.

## Deployment workflow — read before claiming a fix is live

The user runs the plugin from the release zip, not the working tree. **Every PHP change must be followed by `./release.sh`**. Treat "edit done" as "not deployed" until rebuild has run. CSS/JS pick up a `filemtime`-based cache-bust query when `WP_DEBUG` is on (`Assets::asset_version_for()`); in production they use plugin version, so a CSS-only fix in a release still needs a version bump or a hard refresh.

## UI patterns — match these in new modules

Admin UI deliberately does **not** use core WP defaults (`.wrap`, `WP_List_Table`, `<select>`, `<datalist>`). Shared components live in `admin/css/admin-{base,components,dashboard,smtp,logging,contact-form,captcha,newsletter}.css` under `.lrob-etk-*` plus shared PHP renderers in `src/Admin/`. **Default to functional names + global classes** — drop module sub-prefixes (`cf-`, `smtp-`, `logs-`) when the pattern could resurface elsewhere. Reuse — don't reinvent.

**The full admin-UI reference — design tokens, shared PHP renderers, shared JS helpers, the CSS primitives catalog — lives in [docs/admin-ui.md](./docs/admin-ui.md).** The non-negotiable rules are below.

### Mandates

- **Wording**: `+ New X` is forbidden — the dashicon already provides `+`. Use bare `New identity` / `New form` / etc.
- **Forms / inputs**: never render a raw `<select>` or `<input>` in a settings card — always go through `Admin\Combobox` helpers.
- **Modal opening**: never reimplement the open/close/scroll-lock dance — use `window.lrobEtkModal.bindHeader()`.
- **Per-key autosave**: never reimplement debounce + status badge + lastSent — use `window.lrobEtkAutosave.attach()`.
- **Colors / radii / shadows / transitions / spacing / text sizes**: no hardcoded values — always reference tokens. If a needed value isn't tokenized yet, add the token in `admin-base.css` (token catalog in docs/admin-ui.md).
- **`<datalist>`**: banned (cross-browser inconsistency). Use `Admin\Combobox::render_fixed_select()` for known options.
- **`.lrob-etk [hidden] { display: none !important }`** — keep this. WP's `.button { display: inline-block }` has equal specificity and loads later, defeating plain `hidden`.

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

## Captcha module

Two flavours in `src/Modules/Captcha/`: **homemade challenges** (`Challenges/` — self-contained PHP render + server verify, no creds) and **hosted providers** (`Providers/` — hCaptcha / Turnstile / reCAPTCHA, vendor-verified, AES-encrypted identities in `wp_lrob_etk_captcha_identities`). Both are auto-scanned at boot by `Module::register_challenges()` — dropping a class into either folder is all it takes; no `Module.php` edit. **Full how-to (interfaces, routing keys, `AbstractHostedCaptcha`, per-form token scoping) → [docs/captcha.md](./docs/captcha.md).**

### Service-module migrate trap

Captcha is a **service module** (`is_service_module() === true`, always-enabled), so `maybe_migrate()` runs every boot. AbstractModule's default `db_version_int() === 1` recorded version=1 on every existing site *before* the module had install logic. Bumping to 2 makes `maybe_migrate()` take the `migrate()` branch (not `install()`) — schema never gets created on upgrade sites. **Fix**: always override `migrate()` to forward to `install()` (idempotent). If recovering from an already-shipped broken bump, bump the target one more notch so stuck sites re-take the migrate path. See memory `project_service_module_migrate_trap`.

## Newsletter internals

The Newsletter module's data-model contracts — **list kinds** (`subscribers` / `users` / `all_subscribers` pseudo-kind), **system lists** + **visibility** (private/public), the **rule-provider** extension point (`lrob_etk_nl_list_rule_providers` filter), the shared **audience-picker** JS contract, and **subscriber profile fields + form mapping** (`SubscriberFields`) — are documented in **[docs/newsletter-internals.md](./docs/newsletter-internals.md)**. The full product spec is repo-root `newsletter.md`.

## Form-builder WYSIWYG editor

`admin/js/form-fields-editor.js` (shared by Contact Form + Newsletter via `src/Forms/`) is a ~1900-line single-IIFE editor with a strict DOM contract mirrored by `FormEditorRenderer.php`. **Section map, serialized field shape, DOM contract, and where-to-make-common-changes → [docs/form-builder.md](./docs/form-builder.md). If you change the DOM contract on one side, change both.**

## Mandates carried by memory

These are enforced project-wide; the named memory file documents the why + how:

- **No `window.confirm/alert/prompt`** (`feedback_no_browser_popups`) — every destructive admin action goes through `window.lrobEtkConfirm.prompt()` (head-loaded `admin/js/etk-confirm.js`). Server-rendered forms use `[data-etk-confirm-form]` + `data-confirm-title/-message/-label`.
- **No explicit Save buttons** (`feedback_autosave_everywhere`) — every editable field autosaves on blur/change. The shared `lrob-etk:save-status` CustomEvent bubbles to the nearest `.lrob-etk-modal`, where `etk-modal.js` reflects state on a `[data-modal-status]` badge near the X button. Emit it from any custom save handler.
- **Never reload from inside an open modal** (`feedback_no_modal_close_on_save`) — return the server-rendered HTML snippet from the AJAX endpoint and insert it client-side; mutate badges/state in place. Reloads slam the modal shut and lose scroll position. A reload deferred to modal-close is acceptable when the surrounding table genuinely needs a re-render (column picker).
