# Core / foundation

> Loaded on demand, **not** part of the always-in-context `CLAUDE.md`. Pointed to from `CLAUDE.md`'s Architecture section. Covers entry point, lifecycle, module system, service container, support utilities, and the auto-updater. Keep in sync when this subsystem changes.

The enforcement rules (naming prefixes, no Composer, strict types, final classes, etc.) live in [`../CLAUDE.md`](../CLAUDE.md) — they are not repeated here.

---

## Entry point — `lrob-email-toolkit.php`

The file contains:

1. **WordPress plugin header** (`/** Plugin Name: … */`) — required by WP; never condense or remove.
2. **Constants** — `LROB_ETK_VERSION`, `LROB_ETK_FILE`, `LROB_ETK_PATH`, `LROB_ETK_URL`, `LROB_ETK_BASENAME`, `LROB_ETK_PLUGIN_URL`, `LROB_ETK_GITHUB_URL`, `LROB_ETK_GITHUB_ISSUES_URL`. Single source of truth for version — bump `Version:` header and the constant together.
3. **PSR-4 autoloader** — hand-rolled, no Composer. Maps `LRob\EmailToolkit\Foo\Bar` → `src/Foo/Bar.php`.
4. **Lifecycle hook registrations** — `register_activation_hook` → `Activator::activate`, `register_deactivation_hook` → `Deactivator::deactivate`.
5. **Boot** — `add_action('plugins_loaded', Plugin::instance()->boot())`.

---

## Lifecycle — Activator / Deactivator / uninstall.php

### `src/Activator.php`

Runs on plugin activation **and** on every `admin_init` (the `ensure_capability` self-heal).

| Method | Purpose |
|---|---|
| `activate()` | `grant_capability` + `seed_module_state` + `seed_uninstall_mode` + records `lrob_etk_db_version` + flushes the auto-update transient cache |
| `ensure_capability()` | Hooked on `admin_init`; idempotent cap grant. Recovers sites upgraded by file-copy where the activation hook never re-fired. |
| `grant_capability()` | Adds `manage_lrob_etk` to the `administrator` role (no-op if already present). |
| `seed_module_state()` | Writes `lrob_etk_modules` only if it doesn't exist. Default state: captcha=true, all others=false. |
| `seed_uninstall_mode()` | Writes `lrob_etk_uninstall_mode = 'keep'` only if the option doesn't exist. Safe: the default `'keep'` means a misclick on WP's Delete button cannot lose data. |

Defined constants:

```
Activator::CAPABILITY            = 'manage_lrob_etk'
Activator::OPTION_MODULES        = 'lrob_etk_modules'
Activator::OPTION_DB_VERSION     = 'lrob_etk_db_version'
Activator::OPTION_UNINSTALL_MODE = 'lrob_etk_uninstall_mode'
```

### `src/Deactivator.php`

Clears every `lrob_etk_*` cron event via `wp_clear_scheduled_hook`. Nothing else — no data removal. Data is preserved so the user can reactivate without losing logs/campaigns/etc.

### `uninstall.php`

Runs only when the user explicitly deletes the plugin from the admin. Gated by `WP_UNINSTALL_PLUGIN`. Behaviour is controlled by the `lrob_etk_uninstall_mode` option:

| Mode | Tables | Options / caps / cron |
|---|---|---|
| `keep` (default) | Preserved | Preserved |
| `archive` | Preserved | Dropped (except `lrob_etk_uninstall_mode` itself, so a reinstall remembers the choice) |
| `wipe` | Dropped (prefix scan: `{wpdb->prefix}lrob_etk_%`) | Dropped including the mode option |

The prefix-scan for tables validates each name against the prefix a second time before issuing DDL — `wpdb->prepare` cannot quote identifiers, and the source is `SHOW TABLES` output.

Options removal also sweeps transients: `_transient_lrob_etk_*` and `_transient_timeout_lrob_etk_*` live in the options table under those underscore-prefixed names.

---

## Plugin singleton — `src/Plugin.php`

Single instance, created on `plugins_loaded`. `boot()` is guarded by a `$booted` flag (safe against double-call).

Boot sequence:

1. Hook `init` → `load_textdomain`.
2. Seed the shared `FieldTypeRegistry` in the container (before modules register, so they can populate it immediately).
3. Create `ModuleManager`, add it to the container, call `discover()`.
4. If `is_admin()`: hook `admin_init` → `Activator::ensure_capability`; register the top-level admin menu.
5. Register the `AutoUpdate\Updater` unconditionally — `wp_update_plugins()` can be triggered by WP-cron from a frontend request (when `DISABLE_WP_CRON` is set), and the update entry must be injected regardless of context.
6. Call `ModuleManager::boot_all()`.

---

## Service container — `src/Container.php`

Tiny key→object map: `set(string $id, object)`, `get(string $id): ?object`, `has(string $id): bool`. Keys are class-strings by convention. Not full DI — constructor injection is the norm; the container is for cross-module shared services that can't be constructor-injected.

---

## Module system — `src/Modules/`

### `ModuleInterface`

Defines the contract every module fulfils:

- `slug()` — stable identifier used in option keys, hook names, table prefixes.
- `name()` / `description()` / `version()` — human-readable metadata (translated).
- `is_service_module()` — true for always-on modules (Captcha). Dashboard shows "Always on" badge instead of a toggle.
- `data_summary()` — translated count string for the Data admin page ("3 identities", "412 log entries"). Empty when the module stores nothing.
- `requires()` — slugs of modules this one depends on. `ModuleManager` skips boot if unsatisfied.
- `register()` — wire up actions/filters/REST routes. Called for every module regardless of enabled state; each module gates internally on `is_enabled()`.
- `install()` — idempotent setup (dbDelta tables, seed options, schedule cron). Must be safe to call repeatedly.
- `uninstall()` — drop tables, remove data. Only invoked from `uninstall.php`; disabling a module does **not** call this.
- `enable()` / `disable()` / `is_enabled()` — read/write the `lrob_etk_modules` option entry.
- `toggle_action()` — the `admin_post_` action name for the enable/disable form.
- `admin_page_url()` — redirect target after toggle; null if no admin page.

### `AbstractModule`

Default implementations of everything except `slug()`, `name()`, `description()`, `version()`, and `register()`.

**Schema migration scaffolding:**

| Method | Role |
|---|---|
| `db_version_int()` | The module's current schema version integer. Default: 1. Bump on each ALTER/dbDelta change. |
| `maybe_migrate()` | Called by `ModuleManager::boot_all()` for every enabled module. Reads the stored version, compares to `db_version_int()`. If stored == 0: calls `install()` (fresh or pre-migration-scaffolding install). If stored < target: calls `migrate()`. Writes the new version after. Short-circuits cheaply when versions match. |
| `migrate(int $from, int $to)` | Override with a switch on `$from_version`. **Service modules must override this to forward to `install()`.** See the service-module trap below. |

**Service-module migrate trap:** Service modules (`is_service_module() === true`) run `maybe_migrate()` every boot. The default `db_version_int()` returns 1, and `AbstractModule::enable()` records version 1. Any existing site that had the module before it had install logic already has version=1 in the DB. Bumping to 2 makes `maybe_migrate()` take the `migrate()` branch — but the default `migrate()` is a no-op, so the schema never gets created. Fix: **always** override `migrate()` on service modules to forward to `install()` (which is idempotent). If a broken bump was already shipped, bump the target one more notch to force all stuck sites through the migrate path. See `CLAUDE.md` for the full trap description.

### `ModuleManager`

Hard-coded module list in `module_classes()` — no filesystem scanning. Adding a module requires an explicit edit there.

`discover()` — instantiates each class (idempotent). `boot_all()` — for each module: checks `dependencies_satisfied()`, runs `maybe_migrate()` if enabled, calls `register()`. Dependencies are checked at boot time; an unsatisfied dependency silently skips the module.

---

## Support utilities — `src/Support/`

### `Encryption.php`

AES-256-GCM encryption for credentials at rest (SMTP passwords, captcha provider keys, etc.).

**Key derivation:** `AUTH_KEY` (from `wp-config.php`) → HKDF-SHA256, 32 bytes, info tag `lrob_etk_v1`. Throws `RuntimeException` if `AUTH_KEY` is missing or still the placeholder string.

**Wire format** (base64-encoded): `version(1 byte) || iv(12 bytes) || tag(16 bytes) || ciphertext`.

`VERSION = \x01` — bump and add a new decrypt branch if the cipher or layout ever changes.

**AUTH_KEY rotation:** if the key changes (site migration, `wp-config.php` regenerated), all previously encrypted values become unrecoverable. Callers must catch `RuntimeException` and prompt the user to re-enter the secret.

`is_available()` — returns false (rather than throwing) when `AUTH_KEY` isn't usable; useful for admin UI guards before attempting to store a credential.

### `Events.php`

Dispatches plugin domain events through two WP actions simultaneously:

- **Generic:** `do_action('lrob_etk_event', $name, $payload)` — a single listener can subscribe once and receive every event.
- **Typed:** `do_action('lrob_etk_' . str_replace('.', '_', $name), $payload)` — subscribe to one specific event by name.

Event names are dot-namespaced: `domain.action[.detail]`. The full vocabulary is in `CLAUDE.md`. **Renaming or removing an event is a breaking change** from v0.0.1 onward.

### `TrackingToken.php`

HMAC-SHA256 token signer for Newsletter open/click tracking URLs.

**Key derivation:** same `AUTH_KEY` input as `Encryption`, but a separate HKDF info tag (`lrob_etk_tracking_v1`) — a leaked tracking secret cannot decrypt credentials.

**Token format:** first 32 URL-safe base64 characters of `HMAC-SHA256(payload)`, where payload is `purpose|newsletter_id|recipient_kind|recipient_id|item_id` (pipe-delimited). ~190 bits of effective security — adequate for "make stats look weird" threat model; not credential-grade.

**AUTH_KEY rotation:** all in-flight tracking URLs stop validating after rotation. Tracking events from emails sent before the rotation become un-attributable. Acceptable: rotation is rare, and the data loss is bounded.

`verify()` does a quick regex gate (length + charset) before the HMAC computation, then a constant-time `hash_equals` compare.

Purposes: `TrackingToken::PURPOSE_IMAGE = 'img'`, `PURPOSE_CLICK = 'click'`. Changing the payload format (field order, delimiter) is a **breaking change** for every tracking URL in flight.

---

## Auto-updater — `src/AutoUpdate/Updater.php`

Self-hosted updater that surfaces GitHub releases as standard WordPress plugin updates. No external library.

**Two filters:**

| Filter | Role |
|---|---|
| `pre_set_site_transient_update_plugins` | Hits the GitHub API, compares versions, injects the update entry into WP's transient when a newer release is published. |
| `plugins_api` | Fills the "View version details" modal on Plugins / Updates screens with release info (changelog from GitHub release body, rendered via a minimal Markdown→HTML converter). |

**Caching:**
- Success: 1-hour transient (`lrob_etk_gh_release`).
- Failure: also 1-hour (to avoid hammering a flaky API).
- Bypassed entirely when the admin is on `update-core.php` or sends `?force-check=1` — the explicit "I want fresh data" signal.

**Release detection:** `find_asset_url()` looks for a release asset named `lrob-email-toolkit-<version>.zip`. If no matching zip is attached, the update entry is skipped — the GitHub-generated source tarball has a commit-hash folder name and would install side-by-side rather than replacing.

**`flush_cache()`** — static, called from `Activator::activate()` so the first admin page load after (re)installation hits the API fresh rather than replaying stale data.

**`markdown_to_html()`** — minimal renderer for the changelog modal. Covers headings, bullets, bold, inline code, links. Escapes everything first, then selectively re-introduces markup — safe against XSS.

`register()` is called unconditionally (not gated on `is_admin()`) because `wp_update_plugins()` can be triggered by WP-cron from a frontend request.

---

## Related docs

- [`../CLAUDE.md`](../CLAUDE.md) — architecture overview, naming conventions, module lifecycle, encryption notes, event vocabulary.
- [`docs/admin-ui.md`](admin-ui.md) — admin design system, shared PHP renderers, JS helpers.
- [`docs/captcha.md`](captcha.md) — Captcha module internals (provider/challenge architecture, service-module migrate trap in context).
- [`docs/newsletter-internals.md`](newsletter-internals.md) — Newsletter module internals (list kinds, tracking, subscriber fields).
- [`docs/form-builder.md`](form-builder.md) — shared form-builder WYSIWYG editor.
