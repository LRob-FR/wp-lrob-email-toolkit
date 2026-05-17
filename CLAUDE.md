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

1. **SMTP + Logging** (current target — v0.0.1).
2. **Contact Form**.
3. **IMAP save-to-sent** (extends Logging).
4. **Newsletter** (with importer from the Newsletter plugin).
5. **Integrations** (webhooks: Slack/Discord/Matrix/n8n presets + generic).

Don't start a later module until the previous is functionally stable. The skeletons for all four foundation modules already exist so the framework boots cleanly with everything disabled.
