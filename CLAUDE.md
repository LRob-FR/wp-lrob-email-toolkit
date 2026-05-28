# CLAUDE.md

Guidance for Claude Code sessions working in this repository.

## 📖 READ THIS FIRST, EVERY SESSION

**Before touching any code or suggesting any feature work, READ [todo.md](./todo.md) — specifically its "🗺️ Roadmap to 1.0" section at the top.** The user has locked the milestone sequence to 1.0 (v0.4.x → v0.5.x → … → v1.0.0). **Do not propose work from a later milestone before the current one ships.** Do not invent new priorities — if it's not in the roadmap, ask before building it.

**Then check [completed.md](./completed.md)** for what's already shipped (so you don't reinvent or duplicate). Also load memory `project_1_0_release_prerequisites` — it's the short-form pointer to the roadmap with status flags on the original 1.0 gates.

**Keep `todo.md` and `completed.md` in sync as you work.** When a feature lands or a backlog item gains/loses scope, update both files in the same change — don't defer to "later". Stale entries (e.g. *working tree; pending release* on something already shipped, or a backlog bullet for something now in `completed.md`) are bugs in the docs. Also: when a milestone ships, update the roadmap section in `todo.md` to flag it shipped + move the line items to `completed.md`.

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

Admin UI deliberately does **not** use core WP defaults (`.wrap`, `WP_List_Table`, `<select>`, `<datalist>`). Shared components live in `admin/css/admin-{base,components,dashboard,smtp,logging,contact-form,captcha,newsletter}.css` under `.lrob-etk-*` plus shared PHP renderers in `src/Admin/`. **Default to functional names + global classes** — drop module sub-prefixes (`cf-`, `smtp-`, `logs-`) when the pattern could resurface elsewhere. Reuse — don't reinvent.

### Design tokens (admin-base.css)

Single source for every color, radius, shadow, spacing, transition, **and text size**. **Hardcoding chrome values is forbidden** — `font-size: 12px`, `gap: 6px`, `padding: 8px`, `color: #eee`, `border-radius: 4px` all belong to a token. If a needed value isn't tokenized yet, **add a token to admin-base.css first**, then reference it. Adapting one (dark mode, compact density, roundness preset) then ripples everywhere. The same rule applies to **reuse**: before forking a new class, grep for similar existing primitives — extend rather than duplicate (see memory `feedback_css_tokens_no_hardcode`).

| Token family | Values |
|---|---|
| Palette | `--etk-fg`, `--etk-muted`, `--etk-soft`, `--etk-line`, `--etk-line-strong`, `--etk-accent`, `--etk-accent-hover`, `--etk-accent-bg`, `--etk-success`, `--etk-success-bg`, `--etk-warning`, `--etk-warning-bg`, `--etk-danger`, `--etk-danger-bg` |
| Text-on-tint | `--etk-text-{success,danger,warning}` (dark counterparts to the `*-bg` tokens) |
| Surfaces | `--etk-card-bg` (warm off-white), `--etk-input-bg` (pure white) |
| Radii | `--etk-radius-{sm,md,lg,xl,pill}` (4 / 6 / 8 / 12 / 999px). Legacy alias `--etk-radius` = md. |
| Shadows | `--etk-shadow-{sm,md,lg,modal,menu}` |
| Spacing | `--etk-space-{1..6}` (4 / 8 / 12 / 16 / 24 / 32px). Snap to grid — don't add `--etk-space-1-5` for 6px, round to 4px or 8px. |
| Text sizes | `--etk-text-{xxs,xs,sm,md,lg}` (10 / 11 / 12 / 13 / 14px). Density-tunable — every chrome `font-size` references one of these. |
| Inputs | `--etk-input-height` (30px), `--etk-input-height-sm` (28px), `--etk-input-font-size` (13px, aliases `--etk-text-md`) |
| Icons | `--etk-icon-size` (16px), `--etk-icon-size-sm` (14px) |
| Motion | `--etk-transition` (0.15s ease), `--etk-transition-slow` (0.30s ease) |

**Pre-existing tech debt note:** historical hardcoded values (`font-size: 12px` in old `.lrob-etk-nl-status-*` pills, `padding: 8px 12px` on table heads, etc.) predate the token system — don't mass-refactor them mid-feature, risk of visual regression. Add tokens cleanly to NEW additions; the user will ask for an explicit cleanup pass when migrating the old ones.

### Shared PHP renderers (src/Admin/)

- **`PageHeader::render($args)`** — Single source for every plugin page header. Layout: `[Title] [ModuleToggle?] [+ New X primary]   →→→   [Tools group] | [Nav group]`. Wording mandate: **`New X`** (the `dashicons-plus-alt2` icon supplies the `+`). Pass `primary`/`tools`/`nav` button arrays. Cross-page links go in `nav` (muted treatment + vertical divider).
- **`ModuleToggle::render_inline($module)`** — toggle switch next to the H1. PageHeader auto-invokes when `module` is passed.
- **`Combobox::render_fixed_select()` / `render_free_text()`** — `<datalist>` is banned; raw `<select>` is banned. Auto-save marker class passed in (`lrob-etk-cf-field`, etc.).
- **`Tooltip::render($text)`** — `position: fixed` so they escape scroll/modal clipping.
- **`RetentionToggle::render([...])`** — checkbox + days input pair (0 stored = disabled).

### Shared JS helpers (admin/js/, enqueued plugin-wide via Admin\Assets)

- **`etk-modal.js`** — `window.lrobEtkModal.bindHeader(modalId, openerId)`. Opens any `.lrob-etk-modal` via header button; backdrop / × / Escape all close; body scroll locks. Used by CF Defaults, CF Storage, Logs Storage.
- **`etk-autosave.js`** — `window.lrobEtkAutosave.attach(card, opts)`. Per-key autosave with debounce, `lastSent` tracking, and `.lrob-etk-card-status` badge state machine. Consumer supplies `{ fieldSelector, save(field, value), readValue?, debounceMs?, i18n }`. Used by CF per-form cards + Defaults card, Logs Storage card.
- **`etk-controls.js`** — combobox driver. Loads in head (SMTP cards call synchronously mid-body).
- **`etk-list-filter.js`** — generic filter form ⇄ list region AJAX swap. Used by Email Logs + Submissions inbox.
- **`etk-detail-modal.js`** — generic detail modal with prev/next nav.
- **`etk-retention-toggle.js`** — RetentionToggle widget runtime.

### CSS primitives (admin-components.css)

Don't redefine these per module — extend with module-only variants where genuinely needed.

- **`.lrob-etk-card`** (+ `--container` modifier for inline-size container queries) — every settings/identity card. Consumes `--etk-card-bg`, line border, lg radius, md shadow, focus-within highlight, `.is-new` accent. Module flavors (`.lrob-etk-identity-card` for SMTP JS hook, `.lrob-etk-form-card` for CF, `.lrob-etk-captcha-card`, `.lrob-etk-nl-card`, `.lrob-etk-logs-storage-card`) sit alongside as semantic markers, not duplicate visuals.
- **`.lrob-etk-card-grid`** — `repeat(auto-fit, minmax(380px, 540px))`. CF form cards add `--wide` modifier (`minmax(420px, 750px)`) so hCaptcha (303px min) fits.
- **`.lrob-etk-card-form` + `-head` + `-status` + `-footer`** — card internals. `-status` is the absolutely-positioned animated save badge.
- **`.lrob-etk-data-table` + `-wrap`** — replaces WP `widefat striped`. Shared by Email Logs + Submissions inbox; column widths via `.col-*` modifiers.
- **`.lrob-etk-filter-bar` + `-field` (+ `--search`) + `-actions`** — top-of-list filter row.
- **`.lrob-etk-bulk-toolbar`, `.lrob-etk-pagination`** — list chrome below the filter bar / above results.
- **`.lrob-etk-icon-btn`** (+ `--ghost` / `--danger` / `--spam`) — square icon-only button. Replaces every per-module row-action / picker-trigger / conn-test variant. Sizes drive off tokens.
- **`.lrob-etk-btn--danger` / `--spam` / `--danger-solid` / `--warn-solid`** — modifiers on WP `.button`. Outline variants (`--danger`, `--spam`) for inline actions; solid-fill variants (`*-solid`) for destructive confirm buttons.
- **`.lrob-etk-combo` + `-input` + `-toggle` + `-menu`** — input+dropdown shell. The recipient-list row uses the same input-shell idiom; the `<datalist>` ban applies.
- **`.lrob-etk-menu` + `--fixed` + `-item`** — floating menu shared between combobox and JS-positioned pickers (recipient menu).
- **`.lrob-etk-modal` + `-dialog` (+ `--small` / `--wide`) + `-header` + `-body` + `-footer`** — modal chrome. Opened via `window.lrobEtkModal.bindHeader()`.
- **`.lrob-etk-popover` + `-header` + `-body` + `-footer`** — anchored popover (SMTP conn-test details, dashboard test email).
- **`.lrob-etk-test-result`** (+ `.is-pending` / `.is-success` / `.is-failure`) — banner for SMTP conn-test / Captcha verify-test / manual-cleanup result.
- **`.lrob-etk-detail-strip` + `-item` + `-label` + `-value`** — chip row at top of record detail views (submission, log entry).
- **`.lrob-etk-cleanup-row` + `-statuses` + `-actions`** — manual-cleanup row inside Storage modals.
- **`.lrob-etk-retention-toggle`** — checkbox + days widget rendered by `Admin\RetentionToggle`.
- **`.lrob-etk-status`** (+ `--on` / `--off` / `--fail` / `--pending`) — pill badges.
- **`.lrob-etk-section-title`** — page-level section title carries a `border-top`; inside `.lrob-etk-card` the border is suppressed (the parent section owns separation).
- **`.lrob-etk-tip` + `-text`** — tooltip rendered via `Admin\Tooltip::render()`; `position: fixed` with JS coords.
- **`.lrob-etk-card-footer-default`** slot carries `.lrob-etk-default-badge` (star-filled, "Default") or `.lrob-etk-set-default` (star-empty, click to make default).

### Mandates

- **Wording**: `+ New X` is forbidden — the dashicon already provides `+`. Use bare `New identity` / `New form` / etc.
- **Forms / inputs**: never render a raw `<select>` or `<input>` in a settings card — always go through `Admin\Combobox` helpers.
- **Modal opening**: never reimplement the open/close/scroll-lock dance — use `window.lrobEtkModal.bindHeader()`.
- **Per-key autosave**: never reimplement debounce + status badge + lastSent — use `window.lrobEtkAutosave.attach()`.
- **Colors / radii / shadows / transitions**: no hardcoded values — always reference tokens. If a needed value isn't tokenized yet, add the token in `admin-base.css`.
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

## Newsletter list kinds + system lists + visibility

`wp_lrob_etk_nl_lists.kind` is an enum-ish column with three values:

| kind | semantics | membership source |
|---|---|---|
| `subscribers` | Subscribers list — collects explicit members via `list_members` table | manual (subscribe form / contact form / admin add) |
| `users` | WP users list — rule-based; provider locked at creation, can't be swapped post-hoc | `rule_json` → `RuleProviderInterface::resolve_user_ids()` |
| `all_subscribers` | Pseudo-kind, system-only — resolves to every confirmed subscriber, no membership row needed | Materializer special-cases it |

`lists.is_system = 1` marks the four built-in lists seeded on install/migrate (`Module::seed_system_lists`): **All subscribers**, **All WP members**, **All WC customers**, **Active WC subscribers**. System lists refuse rename / rule edits / delete via the AjaxController guards + `ListRepository::is_system`. They **do** accept exclusions (admin can pin out specific WP users).

`lists.visibility` ('private' | 'public') decides whether a list surfaces on the subscriber preferences page. **Private** (default) = admin-managed, hidden from subscribers. **Public** = subscribers self-join/leave from the prefs page. System lists hardcoded private (computed sets aren't subscriber-toggleable). `PrefsHandler::sync_public_list_memberships` + `ProfileSection::save` both clip the membership set to `visibility=public + kind=subscribers + is_system=0` server-side — POST tampering can't reach private lists.

The Newsletter audience picker (`META_TARGET_SPEC`) supports `{kind: 'lists', list_ids: [1,2,3]}` for multi-list union. Materializer iterates list_ids, dedupes by (kind,id), and resolves each per its `list.kind`. Legacy `{kind: 'all'}` and friends keep working under the hood. The Materializer **no longer** filters recipients by `category_opt_outs` (categories merged into lists in v0.3.4 schema v12) — audience is purely list-membership-driven.

### Categories → Lists migration (v0.3.4, schema v12 + v13)

Categories were merged into Lists. v12 migrated every category to a public Subscribers-kind list + materialised list_members from `category_opt_outs`. v13 ran the destructive cleanup: dropped the `wp_lrob_etk_nl_categories` table, the `subscribers.category_opt_outs` column, and every `lrob_etk_nl_category_opt_outs` user_meta row. Both migrations chain on the same upgrade pass (v11 → v13 catches v12's data move + v13's drops in order). `CategoryRepository`, `CategoryPicker`, `seed_default_category()` all gone.

## Newsletter list rule providers — adding one

Lists (`wp_lrob_etk_nl_lists`) can be manual, rule-based, or both. Rule providers implement `Modules\Newsletter\Lists\RuleProviderInterface` and are surfaced via the `RuleRegistry`. Built-ins ship in `src/Modules/Newsletter/Lists/` (today: `WpUserRoleRule`).

To register a third-party provider, hook the `lrob_etk_nl_list_rule_providers` filter and append your instance:

```php
add_filter('lrob_etk_nl_list_rule_providers', function (array $providers) {
    $providers[] = new \MyPlugin\WooSubscribersRule();
    return $providers;
});
```

`config_fields()` returns generic field descriptors (`text` / `select` / `multiselect` / `checkbox`); the list-modal admin UI renders them automatically — no UI work to register a new provider. `sanitize_config()` is the trust boundary (the server never trusts the raw POST shape) and `resolve_user_ids()` is what the send-time Materializer calls to compute the auto-membership set. Manual memberships are unioned in by `Materializer::fetch_opted_in_users`.

## Shared admin JS helpers (audience picker)

`admin/js/etk-audience-picker.js` is the dropdown-of-grouped-checkboxes picker reused by the newsletter card's audience field AND by the form-card's default-list field. Parameterised purely via data attrs on the `[data-audience-picker]` shell:

| Attr | Purpose |
|---|---|
| `data-audience-action` | `wp_ajax` action name to POST to |
| `data-audience-key` | meta key the server should write under |
| `data-audience-id-param` | POST param name for the entity ID (e.g. `newsletter_id`, `form_id`) |
| `data-audience-id` | entity ID value |
| `data-audience-nonce` | nonce string for the configured action |
| `data-audience-ajax-url` | usually `admin-ajax.php` URL |
| `data-audience-empty-label` | summary text when no list is picked |
| `data-audience-saved-event` | (optional) `CustomEvent` name to dispatch on save success |
| `data-audience-saved-id-key` | (optional) detail key under which the entity ID is published — listeners read it (newsletter-cards.js looks for `newsletterId`) |

DOM contract: a `[data-audience-toggle]` button, a `[data-audience-menu]` (hidden by default), and one or more `<input type="checkbox" data-audience-list="<id>">` inside the menu. The shared module owns open/close, outside-click + Escape, summary updates, persistence. Save value shape is comma-separated IDs in the `value` POST param — server unpacks however the meta wants.

Server-side: each consumer's save handler validates its own eligibility rules. Form's `default_list_ids` enforces `kind=subscribers AND is_system=0` (admin-created subscribers lists only — rule-based / computed lists make no sense as form defaults). Newsletter's `target_list_ids` accepts everything (system / users-kind / subscribers-kind all valid send targets).

**Multi-instance opener**: every per-card "Manage lists →" trigger uses the class `.lrob-etk-nl-open-lists-modal` (not an `id`), with a delegated click handler in `ListsPage::render_modal`. Avoids the duplicate-ID trap when multiple cards live on the same page (`bindHeader` is ID-based and only binds the first match).

## Mandates carried by memory

These are enforced project-wide; the named memory file documents the why + how:

- **No `window.confirm/alert/prompt`** (`feedback_no_browser_popups`) — every destructive admin action goes through `window.lrobEtkConfirm.prompt()` (head-loaded `admin/js/etk-confirm.js`). Server-rendered forms use `[data-etk-confirm-form]` + `data-confirm-title/-message/-label`.
- **No explicit Save buttons** (`feedback_autosave_everywhere`) — every editable field autosaves on blur/change. The shared `lrob-etk:save-status` CustomEvent bubbles to the nearest `.lrob-etk-modal`, where `etk-modal.js` reflects state on a `[data-modal-status]` badge near the X button. Emit it from any custom save handler.
- **Never reload from inside an open modal** (`feedback_no_modal_close_on_save`) — return the server-rendered HTML snippet from the AJAX endpoint and insert it client-side; mutate badges/state in place. Reloads slam the modal shut and lose scroll position. A reload deferred to modal-close is acceptable when the surrounding table genuinely needs a re-render (column picker).

## Subscriber profile fields + form mapping

`Modules\Newsletter\SubscriberFields` is the single source of truth for the subscriber profile columns:

- `PROFILE_COLUMNS` — the canonical whitelist (`email`, `name`, `first_name`, `last_name`, `phone`, `address_line`, `address_line2`, `address_postcode`, `address_city`, `address_region`, `address_country`, `gender`, `language`).
- `GENDER_VALUES` — `female` / `male` / `other` / `prefer_not_to_say`.
- `sanitize($column, $value)` — per-column sanitiser (sanitize_email for email, ENUM check for gender, ISO-2 cap for country, etc.). Every write goes through this.
- `SubscriberRepository::set_profile_field($id, $column, $value)` — whitelisted single-column write. Used by the detail-modal autosave (per-key AJAX path), the form-submit `write_mapped_profile`, and the CSV import handler.

Form fields can declare a **`maps_to`** attribute (added via the editor's "Maps to" chip — Newsletter forms only; Contact Form gets an empty `EDITOR_DATA.mapsToTargets` and the chip stays hidden). At subscribe time, `SubmitHandler::extract_mapped_profile` walks the structure, runs values through `SubscriberFields::sanitize`, and `write_mapped_profile` fans them onto the subscriber row.

The Newsletter Forms picker also ships **field presets** (`FormsPage::field_presets()`) — *Full name*, *First + Last name*, *Phone*, *Postal address* (5 sub-fields). Picking a preset drops one or more pre-mapped fields in one shot.

Newsletter-specific field types live module-local under `src/Modules/Newsletter/Fields/` and are registered against `FormCPT::POST_TYPE` only (not Contact Form): `CategoryPicker` (back-compat shim, retired post-v0.3.4), `ListPicker`, `GenderField`. Shared types in `src/Forms/Fields/` (text/email/phone/select/etc) get registered into both CPTs from the respective modules. The `gender` field is a dedicated type rather than a `select+maps_to=gender` preset — options come from `SubscriberFields::GENDER_VALUES`, labels are translated at render time, and `maps_to` is locked so admin can't accidentally rewire it.

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
