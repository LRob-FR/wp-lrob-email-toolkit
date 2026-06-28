# Captcha module — architecture & extension guide

> Loaded on demand, **not** part of the always-in-context `CLAUDE.md`. Pointed to from `CLAUDE.md` → "Captcha module". The **service-module migrate trap** (a cross-cutting gotcha that uses Captcha as its type case) stays in `CLAUDE.md`. Keep this file in sync when the captcha extension surface changes.

Two flavours coexist in `src/Modules/Captcha/`:

- **Homemade challenges** (`Challenges/`) — self-contained PHP-renders-HTML + verifies-server-side. `MathChallenge`, `ImageChallenge`. No external API, no credentials.
- **Hosted providers** (`Providers/`) — hCaptcha, Cloudflare Turnstile, Google reCAPTCHA (all shipped). Loaded via vendor JS widget, verified via `wp_remote_post` to the vendor's verify URL. Each provider can have N **identities** stored in `wp_lrob_etk_captcha_identities` (AES-256-GCM encrypted credentials).

`ProviderInterface` extends `ChallengeInterface` with `credential_fields()`, `validate_credentials()`, `logo_html()`, `sort_order()`, `supports_invisible()`. Routing keys: `homemade:<slug>`, `identity:<int>`, `none`, `inherit`. Per-context map (`lrob_etk_captcha_context_map`) keyed by `default` / `contact_form` / `comments` / `newsletter_subscribe` / `lost_password` / `registration` / `login`. `CaptchaService::resolve($context)` returns `[ChallengeInterface, credentials, state]`.

## To add a homemade challenge

Drop a class implementing `ChallengeInterface` into `Challenges/`. `Module::register_challenges()` auto-scans both `Challenges/` and `Providers/` at boot — no Module.php edit. The challenge automatically appears in every captcha picker.

## To add a hosted provider

Drop a class implementing `ProviderInterface` into `Providers/`. Required: `slug()`, `label()`, `description()`, `logo_html()`, `credential_fields()`, `validate_credentials()`, `render(array $context)`, `verify(array $post, array $context)`. Optional `SCRIPT_URL` and `POST_RESPONSE_FIELD` constants surface to admin JS for in-card preview. New hosted providers usually extend `Providers/AbstractHostedCaptcha` (shared credential fields, validation, theme/size/auto widget render, siteverify); concrete providers then carry mostly constants + branding. No edits to `Module.php`, `CaptchaService`, or the settings page — auto-scan picks it up.

`verify()` receives the raw `$_POST` array. Use `FormContext::is_active()` / `FormContext::instance()` from ContactForm if the token name needs per-form scoping (prevents replay across forms). `MathChallenge` does this — copy that pattern for any challenge issuing a signed token.

## Module bootstrap

`Module` is always enabled (`is_service_module() = true`); `maybe_migrate()` runs every boot. `migrate()` forwards unconditionally into `install()` (idempotent) — required because sites running before the identities table existed had `db_version=1` recorded by the default no-op path, so later schema bumps would land in `migrate()` rather than `install()`. See CLAUDE.md "service-module migrate trap".

`register_challenges()` auto-scans `Challenges/` and `Providers/` via glob + ReflectionClass, skipping abstract classes and the interface files themselves. Registration order is alphabetical by filename.

## Schema

Two tables:

- `wp_lrob_etk_captcha_identities` — one row per configured hosted-provider account. Columns: `id`, `provider_slug`, `label`, `credentials_encrypted` (AES-256-GCM JSON blob), `is_active`, `theme`, `size`, `created_at`, `updated_at`.
- `wp_lrob_etk_captcha_stats` — pre-aggregated verify counters keyed by `(day_date, route_key, outcome)`. One UPSERT per `verify()` call; total rows stay small (≈ routes × 2 outcomes × active days). Powers the dashboard "spam blocked" tile and per-route counters on the settings page.

Current schema version: **6**. Version history:
- v1: default no-op (service module, recorded by AbstractModule before any schema existed).
- v2: broken first-release bump — identities table was never created on upgrade sites (migrate() path skipped install()).
- v3: recovery bump — migrate() now forwards to install(), so stuck v2 sites re-run the create.
- v4: added stats table (`day_date, route_key, outcome, n`).
- v5: added `theme` + `size` columns to identities.
- v6: flipped WP-native contexts that were on `inherit` to `none` (opt-in model migration).

## Routing

`Routing` is the single source for routing key constants, context lists, and the persisted context map (`lrob_etk_captcha_context_map` WP option).

**Key format:**
- `none` — disable captcha for this caller
- `inherit` — valid only in per-context entries; defers to the site default
- `homemade:<slug>` — built-in challenge by slug
- `identity:<int>` — hosted provider identity by row id

**Context groups:** plugin contexts (`contact_form`, `newsletter_subscribe`) default to `inherit` on fresh install — they pick up the site default immediately. WP-native contexts (`comments`, `login`, `lost_password`, `registration`) default to `none` — the admin opts in explicitly so adding WpHooks never surprises anyone.

`Routing::effective_route($context)` resolves inherit → default → `none`. `CaptchaService::resolve_route($context)` also accepts a `force_route` key in the context array to bypass the map entirely (used by the diagnostics page).

## CaptchaService states

`resolve()` returns a `[?ChallengeInterface, array $credentials, string $state]` triple:

- `STATE_OK` — challenge non-null, ready to run.
- `STATE_NONE` — admin intentionally turned this context off. `verify()` returns `[true, null]`.
- `STATE_BROKEN` — route is configured but can't resolve: identity row missing/inactive, provider class gone, or credentials undecryptable (AUTH_KEY rotated). `verify()` returns `[false, message]` — fail closed so bots can't bypass while the admin UI looks fine. A persistent admin notice is rendered by `Module::render_broken_routes_notice()`.

## Identity credentials

Credentials are stored as AES-256-GCM-encrypted JSON in `credentials_encrypted`. `Identity::decrypted_credentials()` decrypts and JSON-decodes; throws `RuntimeException` if AUTH_KEY changed or the ciphertext is tampered. Callers should catch and surface a re-enter prompt.

`IdentityRepository::save($identity, $plain_credentials)`: pass `null` to keep existing ciphertext, `[]` to clear, or a `[key => value]` array to re-encrypt + store.

On the admin save path (`AjaxController::ajax_save_identity`): empty credential fields on edit mean "keep existing" (mirrors SMTP identity semantics). The controller merges the POST values over the decrypted existing set before calling `validate_credentials()`, so the provider always sees a complete picture.

## Appearance (widget theme/size)

Stored per identity, not globally. `Appearance` constants: `THEME_AUTO` / `THEME_LIGHT` / `THEME_DARK`; `SIZE_NORMAL` / `SIZE_COMPACT` / `SIZE_INVISIBLE`. `auto` theme is resolved client-side from `prefers-color-scheme` before the vendor script renders the widget. `invisible` size is only offered in the admin UI when `$provider->supports_invisible()` is true; if a mis-set `invisible` reaches a provider that doesn't support it, `AbstractHostedCaptcha::render()` falls back to `normal`.

## AbstractHostedCaptcha

Shared base for hCaptcha / Turnstile / reCAPTCHA. Concrete providers **must** define these constants (read via late static binding; also surfaced to the admin JS via reflection):

| Constant | Purpose |
|---|---|
| `SCRIPT_URL` | Vendor widget JS URL |
| `VERIFY_URL` | Vendor siteverify endpoint |
| `POST_RESPONSE_FIELD` | Form field the widget writes its token into |
| `WIDGET_CLASS` | Container CSS class the vendor script renders into |
| `WIDGET_GLOBAL` | JS global exposing `render()` (e.g. `"hcaptcha"`) |

…and implement `slug()`, `label()`, `description()`, `logo_html()`, `vendor_label()`. `sort_order()` defaults to 100; override to control picker position.

`render()` injects `data-callback`/`data-error-callback`/`data-expired-callback` and `data-lrob-etk-invisible` markers when invisible mode is active — `form-submit.js` reads these to trigger + await the widget on submit. A **visible** widget instead gets `data-lrob-etk-hosted="1"` + `data-lrob-etk-response` so the frontend can detect a token-less submit and block it.

### Blocked-script handling (content/cookie blockers)

When a blocker stops the vendor script, the widget never renders and no token is posted — a token-less submit would only earn a `400` (and can trip server WAFs into a `403`). Two guards, both in `form-submit.js`:

- **Instant warning** — the vendor `<script>` carries an inline `onerror` (`AbstractHostedCaptcha::SCRIPT_ERROR_HANDLER`) that sets `window.__lrobEtkCaptchaBlocked` + calls `window.lrobEtkCaptchaBlocked()`. Race-free (attached at parse, fires before our footer JS); the flag is also re-checked on init for errors that beat the script. This is event-driven, **not** a timeout, so a slow connection still loads normally. Invisible/v3 short-circuit their retry loop on the same flag.
- **Submit guard** — for `[data-lrob-etk-hosted]`, a missing token blocks the POST in place with either "couldn't load" (no iframe / blocked) or "please complete" (rendered but unsolved).

Server-side, `ChallengeInterface::verify()` returns an optional 3rd element — a reason code. Hosted providers return `REASON_TOKEN_MISSING` for an empty token so `ContactForm\SubmitHandler` rejects (`400`) **without** persisting a spam row (blocked-script submits reaching the server are bots bypassing our JS — noise). A token-present-but-rejected verification still saves as before.

## Google reCAPTCHA specifics

One provider class covers two versions chosen per identity:

- **v3** (default): score-based, no visible widget. Script loaded as `api.js?render=<site_key>`; `form-submit.js` calls `grecaptcha.execute(site_key, {action})` on submit and drops the token in a hidden field. Server checks `success AND score >= threshold`. Threshold stored inside the encrypted credentials blob — no extra schema column.
- **v2**: classic checkbox / invisible — reuses `AbstractHostedCaptcha::render()`/`verify()`.

v2 and v3 require **separate** key pairs from Google's console; switching version means re-entering credentials. `Recaptcha::test_score()` is an admin-only helper that runs a real siteverify and returns the raw score so the threshold can be calibrated from the settings page.

## Homemade challenge tokens

Both `MathChallenge` and `ImageChallenge` use stateless HMAC-SHA256-signed tokens stored in a hidden form field; the expected answer is never sent to the client.

**Token format (Math):** `base64url("$a:$b:$op") . "." . hex(HMAC-SHA256)`

**Token format (Image):** `base64url("$position:$nonce") . "." . hex(HMAC-SHA256)`

Signing key derived from `AUTH_KEY` → `NONCE_SALT` → stable siteurl fallback. The `FormContext::instance()` call scopes the field `id` (but not the token name) to the active form instance so multiple forms on a page get distinct DOM ids.

`ImageChallenge` pools 20 SVG concepts; adding a 21st means appending to `concept_pool()` — no other changes. SVGs use `currentColor`, `aria-hidden="true"`, no text labels (OCR resistance). Not accessible to screen readers — the description flags this.

## WP-native context hooks (WpHooks)

`WpHooks` registers render + verify hooks for built-in WP forms when the context route is non-`none`:

| Context | Render hook | Verify hook |
|---|---|---|
| comments | `comment_form_after_fields` + `comment_form_logged_in_after` | `pre_comment_on_post` |
| login | `login_form` + `woocommerce_login_form` | `authenticate` (filter) + `woocommerce_process_login_errors` |
| lost_password | `lostpassword_form` | `lostpassword_post` |
| registration | `register_form` | `registration_errors` |

The `login` context requires special handling: `effective_route()` would normally fall through to the site default for a missing map entry, which would silently enable login captcha on every existing install when the login context was first added. `WpHooks::login_context_active()` reads the raw map and treats absent/`none` as off.

Comments skips users with `moderate_comments` capability (admins, editors). Registration skips when `users_can_register` is off. Login captcha does NOT fire on XML-RPC / application passwords / REST auth — only on `$_POST['log']`/`$_POST['pwd']` (wp-login.php) and the WC `woocommerce_process_login_errors` filter.

Frontend form CSS (`assets/css/contact-form.css`) is enqueued by `WpHooks` for any context that's active — without it homemade challenge tiles render raw and hosted widgets overflow.

## Admin settings page structure

Three visible sections + a collapsible diagnostics panel:

1. **Built-in challenges** — read-only list with live preview + per-challenge stats counter + "Set as default" star.
2. **Hosted providers** — card grid (one card per saved identity) + "New captcha" button that opens a provider-chooser modal (or spawns directly when only one provider is registered). Cards auto-save on blur; Create/Discard buttons on new cards.
3. **Captcha protection** — site-wide default dropdown + per-context override rows, split into "This plugin's forms" (inherit default) and "WordPress sections" (off by default). All auto-save on combo change.
4. **Diagnostics** — collapsible; shows stored-identity credential status (blob length, decrypt result, keys present) and routing resolution table. Helps distinguish "credentials never persisted" / "AUTH_KEY rotated" / "wrong routing key".

Security: `AjaxController` uses one shared nonce (`lrob_etk_captcha_ajax`) for all endpoints + a second per-action nonce (`_action_nonce`) for destructive endpoints (delete identity, save routing, set default) — prevents nonce reuse across action types.

## Admin JS (`captcha-admin.js`)

Single IIFE. `window.lrobEtkCaptcha` is the config object printed inline by `SettingsPage::print_inline_js()`. Key sections:

- **Identity cards** — autosave on `blur` (label + credential fields) and `change` (`is_active`, combobox picks). Focus records `dataset.originalValue`; blur bails without saving if value is unchanged. Create/Discard/Delete buttons on new/existing cards respectively.
- **Provider switch** — `applyProviderToCard()` swaps credential fields from a `<template>` when a new card is spawned for a given provider. `applyVersionVisibility()` shows/hides the score-threshold field and appearance section for reCAPTCHA v3 vs v2.
- **Widget preview** — `renderPreview()` mounts the vendor widget inside `[data-preview-widget]`. Invisible + v3 routes show an explanatory note instead. `ensureProviderScript()` injects the vendor script tag once per provider slug. Per-card callback name (`lrobEtkCaptchaTest_<id>`) forwards the solved token to `autoTestCard()` which calls the `testIdentity` AJAX action.
- **reCAPTCHA v3 score test** — `runV3ScoreTest()` loads `api.js?render=<key>` (deduplicated per key in `loadedV3Scripts`), calls `grecaptcha.execute()`, forwards the token to the `testScore` AJAX action, and displays `score X.XX (≥ threshold)`.
- **Routing menus** — `refreshRoutingMenus()` rebuilds the identity portion of every routing dropdown live from the active-card set after create/delete/activate/deactivate — no reload. `applyDefaultEverywhere()` updates all "Default" / "Set as default" badges and syncs the default-challenge dropdown.
- **Action nonces** — `actionNonceMap` is built at init from `CFG.actionNonces`; `request()` auto-appends `_action_nonce` for any action in the map, so callers don't know about the two-nonce scheme.
