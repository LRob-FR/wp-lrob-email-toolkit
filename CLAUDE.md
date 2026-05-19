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
2. **Contact Form** ← in active development (v0.0.2). Field editor (custom WYSIWYG with drag-and-drop, snap-to-col, side-drop into columns, type picker, etc.) is done. Still pending: per-field settings UI polish, contact-form settings display redesign, visual customization + animations, more homemade anti-bot challenges (currently only Math), submission logging with captcha-used tracking.
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
