# Newsletter internals

> Loaded on demand, **not** part of the always-in-context `CLAUDE.md`. Pointed to from `CLAUDE.md` → "Newsletter internals". Covers list kinds / system lists / visibility, the rule-provider extension point, the shared audience-picker JS contract, subscriber profile fields + form mapping, the send pipeline, tracking, subscriber/list repositories, prefs/email-change flow, and the schema-version migration chain. (The full product spec lives in repo-root `newsletter.md`; this file is the implementation reference.) Keep it in sync when these contracts change.

## List kinds + system lists + visibility

`wp_lrob_etk_nl_lists.kind` is an enum-ish column with three values:

| kind | semantics | membership source |
|---|---|---|
| `subscribers` | Subscribers list — collects explicit members via `list_members` table | manual (subscribe form / contact form / admin add) |
| `users` | WP users list — rule-based; provider locked at creation, can't be swapped post-hoc | `rule_json` → `RuleProviderInterface::resolve_user_ids()` |
| `all_subscribers` | Pseudo-kind, system-only — resolves to every confirmed subscriber, no membership row needed | Materializer special-cases it |

`lists.is_system = 1` marks the four built-in lists seeded on install/migrate (`Module::seed_system_lists`): **All subscribers**, **All WP members**, **All WC customers**, **Active WC subscribers**. System lists refuse rename / rule edits / delete via the AjaxController guards + `ListRepository::is_system`. They **do** accept exclusions (admin can pin out specific WP users).

`lists.visibility` ('private' | 'public') decides whether a list surfaces on the subscriber preferences page. **Private** (default) = admin-managed, hidden from subscribers. **Public** = subscribers self-join/leave from the prefs page. System lists hardcoded private (computed sets aren't subscriber-toggleable). `PrefsHandler::sync_public_list_memberships` + `ProfileSection::save` both clip the membership set to `visibility=public + kind=subscribers + is_system=0` server-side — POST tampering can't reach private lists.

The Newsletter audience picker (`META_TARGET_SPEC`) supports `{kind: 'lists', list_ids: [1,2,3]}` for multi-list union. Materializer iterates list_ids, dedupes by (kind,id), and resolves each per its `list.kind`. Legacy `{kind: 'all'}` and friends keep working under the hood. The Materializer **no longer** filters recipients by `category_opt_outs` — audience is purely list-membership-driven. (The v0.3.4 Categories→Lists merge is shipped history — see `done.md`.)

## List rule providers — adding one

Lists (`wp_lrob_etk_nl_lists`) can be manual, rule-based, or both. Rule providers implement `Modules\Newsletter\Lists\RuleProviderInterface` and are surfaced via the `RuleRegistry`. Built-ins ship in `src/Modules/Newsletter/Lists/` (today: `WpUserRoleRule`).

To register a third-party provider, hook the `lrob_etk_nl_list_rule_providers` filter and append your instance:

```php
add_filter('lrob_etk_nl_list_rule_providers', function (array $providers) {
    $providers[] = new \MyPlugin\WooSubscribersRule();
    return $providers;
});
```

`config_fields()` returns generic field descriptors (`text` / `select` / `multiselect` / `checkbox`); the list-modal admin UI renders them automatically — no UI work to register a new provider. `sanitize_config()` is the trust boundary (the server never trusts the raw POST shape) and `resolve_user_ids()` is what the send-time Materializer calls to compute the auto-membership set. Manual memberships are unioned in by `Materializer::fetch_opted_in_users`.

## Shared admin JS — audience picker (`admin/js/etk-audience-picker.js`)

The dropdown-of-grouped-checkboxes picker reused by the newsletter card's audience field AND by the form-card's default-list field. Parameterised purely via data attrs on the `[data-audience-picker]` shell:

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

## Subscriber profile fields + form mapping

`Modules\Newsletter\SubscriberFields` is the single source of truth for the subscriber profile columns:

- `PROFILE_COLUMNS` — the canonical whitelist (`email`, `name`, `first_name`, `last_name`, `phone`, `address_line`, `address_line2`, `address_postcode`, `address_city`, `address_region`, `address_country`, `gender`, `language`).
- `GENDER_VALUES` — `female` / `male` / `other` / `prefer_not_to_say`.
- `sanitize($column, $value)` — per-column sanitiser (sanitize_email for email, ENUM check for gender, ISO-2 cap for country, etc.). Every write goes through this.
- `SubscriberRepository::set_profile_field($id, $column, $value)` — whitelisted single-column write. Used by the detail-modal autosave (per-key AJAX path), the form-submit `write_mapped_profile`, and the CSV import handler.

Form fields can declare a **`maps_to`** attribute (added via the editor's "Maps to" chip — Newsletter forms only; Contact Form gets an empty `EDITOR_DATA.mapsToTargets` and the chip stays hidden). At subscribe time, `SubmitHandler::extract_mapped_profile` walks the structure, runs values through `SubscriberFields::sanitize`, and `write_mapped_profile` fans them onto the subscriber row.

The Newsletter Forms picker also ships **field presets** (`FormsPage::field_presets()`) — *Full name*, *First + Last name*, *Phone*, *Postal address* (5 sub-fields). Picking a preset drops one or more pre-mapped fields in one shot.

Newsletter-specific field types live module-local under `src/Modules/Newsletter/Fields/` and are registered against `FormCPT::POST_TYPE` only (not Contact Form): `ListPicker`, `GenderField` (`CategoryPicker` was a back-compat shim, retired post-v0.3.4). Shared types in `src/Forms/Fields/` (text/email/phone/select/etc) get registered into both CPTs from the respective modules. The `gender` field is a dedicated type rather than a `select+maps_to=gender` preset — options come from `SubscriberFields::GENDER_VALUES`, labels are translated at render time, and `maps_to` is locked so admin can't accidentally rewire it.

## Send pipeline

### Overview

The send pipeline has three layers that work together:

1. **Materializer** (`Send/Materializer.php`) — one-time per newsletter: resolves `META_TARGET_SPEC` into rows in `newsletter_recipients`. Filters opt-outs and dedupes by email (WP user wins over subscriber when both share an address). Bumps lifetime send stats on the subscriber/user rows. On completion flips newsletter status to `sending` and fires `newsletter.started`.
2. **SendLoop** (`Send/SendLoop.php`) — one tick at a time: claims up to N `pending` recipient rows (flips them to `sending`), renders + `wp_mail`s each, marks rows `sent`/`failed`, bumps counters. Returns a progress dict the AJAX caller uses to decide whether to keep looping.
3. **SendCron** (`Send/SendCron.php`) — safety-net 1-minute cron: picks up stale `sending` newsletters (`last_tick_at` > 2 min ago) and drives one tick through the loop. Handles scheduled newsletters whose `META_SCHEDULED_AT` has passed. Also starts the first tick for newly scheduled newsletters.

### Newsletter statuses

`draft` → `scheduled` (optional) → `materializing` → `sending` → `sent` | `failed` | `aborted` | `paused`

`pause_reason` on the companion row: `NULL` = admin-initiated pause; `smtp_unhealthy` = SMTP circuit-breaker tripped.

### SMTP circuit-breaker

Five consecutive `wp_mail` failures inside a single tick trips the breaker (`SendLoop::CONSECUTIVE_FAILURE_THRESHOLD = 5`). Any still-claimed recipient rows are released back to `pending`. The newsletter flips to `paused` with `pause_reason='smtp_unhealthy'`. A single isolated failure never trips it — the counter resets to 0 on any successful send. Admin resumes from the card; SendCron will not pick it back up while paused.

### AJAX ↔ Cron handoff

The AJAX loop (`newsletter-cards.js`) drives sends while the admin tab is open — it calls `lrob_etk_nl_send_tick` every ~250ms, looping until `remaining === 0` or `status !== 'sending'`. If the tab closes, `SendCron` catches stale newsletters (those with `last_tick_at > 120s ago`) on its next 1-minute tick and resumes. Because `claim_batch` atomically flips rows to `sending` before processing them, concurrent AJAX + Cron ticks can't double-send: the second claimer finds nothing to claim.

### `META_TARGET_SPEC` JSON shape

```json
{"kind": "lists", "list_ids": [1, 2, 3]}
```

Legacy values `all`, `all_users`, `all_subscribers`, `list` (single) still resolve in the Materializer. The canonical form going forward is `lists` with a `list_ids` array. An absent or empty meta defaults to `{kind: 'lists', list_ids: []}` — no recipients.

### Per-newsletter overrides

Three post_meta keys control audience overrides:
- `META_IGNORE_OPTOUTS` — widens recipient pool to include `unsubscribed` subscribers / OPTED_IN=0 users; does not override `bounced`/`refused`/`trashed`.
- `META_FORCE_INCLUDE_IDS` — JSON `[{kind, id},…]`; forces specific recipients in even if outside the audience.
- `META_FORCE_EXCLUDE_IDS` — JSON `[{kind, id},…]`; drops specific recipients; wins against everything including force-includes.

### Test sends

`lrob_etk_nl_test_send` AJAX action renders the newsletter body for one or more test recipients and sends without writing to `newsletter_recipients` and without bumping counters. The outbound headers include `X-Lrob-Etk-Newsletter-Test: 1`. Test sends bypass the tracking pipeline entirely.

## Tracking

### Token format

Tracking URLs embed a compact HMAC token. `Support\TrackingToken::sign(purpose, newsletter_id, kind, rid, resource_id)` produces the token; `::verify()` validates it. The token is URL-safe base64 of a truncated HMAC-SHA256 over `"<purpose>:<newsletter_id>:<kind>:<rid>:<resource_id>"`, keyed from `AUTH_KEY` via HKDF with info-tag `lrob_etk_tracking_v1`.

- `purpose` = `'img'` (image loads) or `'click'` (link clicks) — prevents an img token from being replayed as a click.
- On invalid img token: serve the transparent GIF anyway (don't reveal validity; don't break the email layout).
- On invalid click token: 400 (don't act as an open redirect).

### REST endpoints

`GET /wp-json/lrob-etk/v1/nl/track/img/<token>?n=&r=&a=` — verifies token, records an `open` event, redirects 302 to the original asset URL (or serves a 1×1 transparent GIF for `purpose=open_pixel`).

`GET /wp-json/lrob-etk/v1/nl/track/click/<token>?n=&r=&l=` — verifies token, records a `click` event (plus an implicit `open` if none exists for this recipient), redirects 302 to the original href.

Both handlers: `permission_callback = '__return_true'` (public — hits from email clients).

### Rewrite pipeline (`Tracking/Pipeline.php`)

Called once per (recipient, newsletter) at send time, after body rendering. Three passes:
1. **Image rewriter** — `<img src>` → tracking img URL. Registers each distinct URL in `newsletter_assets`; subsequent renders of the same body find the same `asset_id` (UNIQUE on `newsletter_id + url_hash`).
2. **Open-pixel fallback** — if the body had zero tracked `<img>` after the pass, appends a 1×1 transparent GIF so at least some open signal is possible.
3. **Link rewriter** — `<a href>` → click tracking URL, except: `mailto:`, `tel:`, `#` anchors, `data-lrob-etk-no-track` attrs, and URLs containing `lrob-etk-nl-prefs=` or `lrob-etk-nl-unsub=` (prefs/unsubscribe URLs must not be wrapped — would break one-click and leak the prefs token into the event log).

Per-newsletter toggles `META_TRACK_OPENS` / `META_TRACK_CLICKS` gate each pass independently. Default is enabled when the meta is absent (newsletters created before the toggle existed stay tracked).

### Counter semantics

- `newsletter_recipients.opens` / `clicks` bumped on every event.
- `newsletters.opens_count` / `clicks_count` bumped on every event; `opens_unique` / `clicks_unique` bumped only when the per-recipient counter was 0 before the bump (small SELECT→UPDATE race window — acceptable for display).
- Subscriber lifetime stats: `total_opened++` / `total_clicked++`, `last_engagement_at` set. `sends_since_engagement` resets to 0 on click always; on open only when `lrob_etk_nl_engagement_counts_opens = true` (default false — Apple MPP server-side image loads otherwise poison cold-detection).

### IP anonymisation

IPv4 → /24 (`x.x.x.0`), IPv6 → /48 (first 6 bytes, rest zeroed). No proxy-header trust by default (`REMOTE_ADDR` only). UA stored only when `_lrob_etk_nl_track_user_agent` post meta is set on the newsletter.

### Retention

Daily cron (`Tracking/RetentionCron.php`) prunes `tracking_events` rows older than `lrob_etk_nl_tracking_retention_days` (default 365). Uses chunked `DELETE … LIMIT 5000` (max 20 batches per tick). Aggregate counters are never pruned — only per-event detail ages out.

## Subscriber repository

`SubscriberRepository` is the CRUD layer for `wp_lrob_etk_nl_subscribers`. Key behaviours:

- **Status machine**: `pending` → `confirmed` (via confirmation link), `unsubscribed` / `refused` / `bounced` / `trashed`. `previous_status` stores the pre-trash status so Restore works.
- **Trash is two-step**: `trash($id)` flips to `trashed`; `permanently_delete($id)` refuses to act on non-trashed rows.
- **Resubscribe**: `reset_to_pending($id)` flips any non-pending status back to `pending`, regenerates the `prefs_token` (so the old confirmation URL can't be replayed).
- **Email change**: `set_pending_email_change($id, $new_email)` stores the new address + single-use token; `confirm_pending_email_change($token, $ttl)` applies it (24h default TTL, re-checks email-taken race). `cancel_pending_email_change($id)` clears the pending state.
- **Cold detection**: `sends_since_engagement` increments on each materialise; resets to 0 on engagement. `list_cold($threshold)` returns subscribers confirmed + above-threshold.
- **Reminder tracking**: `list_pending_for_reminder()` scans the `pending_followup` composite index; `record_reminder_sent()` bumps `reminder_count + last_reminder_at`.

## List repository (`ListRepository.php`)

Key behaviours beyond plain CRUD:

- `resolve_rule_user_ids($list_id)` — evaluates a users-kind list's `rule_json` via `RuleRegistry::resolve`. Exclusions (from `list_exclusions`) are applied here before returning IDs to the Materializer.
- `memberships_for_recipient($kind, $id)` — returns the list IDs the recipient belongs to (used by prefs page to pre-check their current memberships).
- `add_member` / `remove_member` / `detach_recipient` — membership writes. `detach_recipient` removes a recipient from every list (used on unsubscribe / forget-me paths).
- `list_public_for_subscribers()` — returns `visibility=public + kind=subscribers + is_system=0` lists only; used by both prefs page and ProfileSection to enforce the server-side guard.
- `opted_out_user_ids()` — single query: WP users with `OPTED_IN='0'` user_meta. Used by `Materializer::preview_recipients` to tag the preview rows.

## Prefs page + one-click unsubscribe (`PrefsHandler.php`)

`PrefsHandler` hooks on `init` (non-admin) and intercepts three query params:

| Query param | Meaning |
|---|---|
| `?lrob-etk-nl-prefs=<token>` | Prefs form (GET = render; POST = save) |
| `?lrob-etk-nl-unsub=<token>` | RFC 8058 one-click: POST = immediate unsub; GET = redirect to prefs |
| `?lrob-etk-nl-confirm-email=<token>` | Email-change confirmation link |

The `prefs_token` is an opaque 24-byte random hex string stored on the subscriber row (`subscribers.prefs_token`) or WP user meta (`lrob_etk_nl_prefs_token`). It is embedded verbatim in outbound `{{prefs_url}}` and `{{unsub_url}}` template substitutions; no HMAC needed (the token itself is the secret). `PrefsHandler::resolve()` tries subscribers first (indexed lookup), falls back to `get_users(meta_query)`.

On prefs POST: profile edits go through `SubscriberRepository::set_profile_field` (whitelisted + sanitised). Public-list membership changes go through `sync_public_list_memberships` which clips the chosen set to `public + subscribers + non-system` on the server. WP users can toggle `OPTED_IN` and public-list memberships; subscribers can also unsubscribe (status flip + `detach_recipient`) or forget-me (trash + `detach_recipient`).

### Email-change flow

1. Subscriber requests change from prefs page → `SubscriberRepository::set_pending_email_change` stores `(pending_email, pending_email_token, pending_email_requested_at)`.
2. `EmailChangeDispatcher::send` sends two emails: a **confirm** to the new address (containing the `?lrob-etk-nl-confirm-email=<token>` URL) and a **notice** to the old address (best-effort, non-fatal).
3. Recipient clicks the confirm link → `SubscriberRepository::confirm_pending_email_change` validates TTL (24h), re-checks email-taken race, flips `email`, clears the pending columns.
4. `EmailChangeDispatcher` does not use TemplateCPT — wording is baked in code to prevent misconfiguration from blocking confirmations. A `lrob_etk_nl_email_change_message` filter can override if needed.

## Confirmation tokens (`ConfirmationTokens.php`)

Double-opt-in / refuse URLs use stateless HMAC tokens (not stored in DB). Format: `<subscriber_id>.<base64url(hmac)>`.

- Two token families per subscriber: `confirm` vs `refuse` — tokens aren't interchangeable between actions.
- Secret: HKDF-SHA256 of `AUTH_KEY` with info-tag `lrob_etk_nl_confirm_v1`. Falls back to `NONCE_SALT`; throws `RuntimeException` if neither is configured.
- 30-byte HMAC truncated to 22 base64url chars. Compared with `hash_equals`.
- Rotating `AUTH_KEY` invalidates outstanding confirmation links — acceptable because the subscriber can re-sign-up to get a fresh one.

## Schema versions

Schema versioning is owned by `AbstractModule::maybe_migrate` via the `lrob_etk_newsletter_db_version` option. `Schema::install()` is idempotent (dbDelta handles additive changes). `Module::migrate()` runs destructive-or-rename steps before calling `install()`.

| Version | What changed |
|---|---|
| 1 | Initial install (7 tables + seeded "General" category) |
| 2 | System email-template seeds |
| 3 | Repair pass: deletes default templates whose content had broken URL tokens (`esc_url_raw` stripped `{{ }}`); pending-flag triggers re-seeding |
| 4 | subscribers gains `reminder_count`, `last_reminder_at`, `pending_followup` index, `prefs_token` index |
| 5 | subscribers gains `language` column |
| 6 | Campaign → Newsletter vocabulary rename: `lrob_etk_nl_campaigns` → `lrob_etk_nl_newsletters`, `campaign_recipients` → `newsletter_recipients`, `campaign_id` columns, CPT post_type slug; ALTER/UPDATE hand-coded before `install()` because dbDelta can't rename |
| 7 | newsletters companion gains `pause_reason` (SMTP circuit-breaker) |
| 8 | Two tracking side-tables (`newsletter_assets`, `newsletter_links`) + lifetime engagement columns on subscribers + UserMeta keys |
| 9 | Extended subscriber profile fields (`first_name`, `last_name`, `gender`, `phone`, postal address columns) |
| 10 | Two list kinds: `lists.kind` column + `list_exclusions` table; existing rule-json lists backfilled to `kind='users'` |
| 11 | `lists.is_system` flag + seed of four built-in system lists |
| 12 | Categories merged into lists: `lists.visibility`, each category → public subscribers list, subscribers migrated to `list_members`, newsletters' `META_TARGET_SPEC` rewritten |
| 13 | Destructive cleanup of v12 safety net: drops `nl_categories` table, `subscribers.category_opt_outs` column, `lrob_etk_nl_category_opt_outs` user_meta |
| 14 | Email-change flow: `subscribers` gains `pending_email`, `pending_email_token`, `pending_email_requested_at` |

**Template-seed defer pattern**: `install()` sets a `lrob_etk_newsletter_pending_template_seed` option flag instead of calling `wp_insert_post` directly — the CPT isn't registered at `plugins_loaded` time. `Module::maybe_seed_templates()` picks the flag up at `init` priority 20 (after TemplateCPT registers at priority 6).

**Campaign→Newsletter rename trap (v6)**: `migrate()` calls `migrate_campaign_to_newsletter()` *before* `install()` because dbDelta would otherwise try to CREATE the new tables alongside still-present old ones. Every ALTER/RENAME is guarded with `information_schema` checks so re-running is safe.

## WP-user-side state (`UserMeta.php`)

WP users are first-class recipients. Their newsletter state lives in `lrob_etk_nl_*` user_meta — no subscriber row is created for them. Key constants:

| Meta key | Meaning |
|---|---|
| `lrob_etk_nl_opted_in` | `'1'` = opted in; `'0'` = opted out; absent = eligible (opt-out model) |
| `lrob_etk_nl_status` | `'active'` or `'bounced'` (no `pending`/`trashed`/`refused` for users) |
| `lrob_etk_nl_prefs_token` | Opaque 24-byte hex; same semantics as subscribers.prefs_token |
| `lrob_etk_nl_total_sent` / `total_opened` / `total_clicked` | Lifetime engagement counters mirroring subscriber columns |
| `lrob_etk_nl_sends_since_engagement` | Cold-detection counter; resets on engagement |
| `lrob_etk_nl_last_sent_at` / `last_engagement_at` | Timestamp mirrors |

The `KIND_USER = 'user'` and `KIND_SUBSCRIBER = 'subscriber'` constants tag rows in `list_members`, `newsletter_recipients`, and `tracking_events` — same string in all three tables.

## Cron inventory

| Hook | Schedule | Purpose |
|---|---|---|
| `lrob_etk_nl_cron_send_tick` | Every 1 min (`lrob_etk_nl_minute`) | Safety-net send: resume stale newsletters, promote scheduled ones |
| `lrob_etk_nl_reminder` | Daily | Send pending-subscriber reminders (up to configured max) |
| `lrob_etk_nl_trash_purge` | Daily | Auto-purge trashed subscribers older than `lrob_etk_nl_trash_auto_purge_days` days |
| `lrob_etk_nl_tracking_retention` | Daily | Prune `tracking_events` older than `lrob_etk_nl_tracking_retention_days` (default 365) |

All four crons are scheduled on `install()` and unscheduled on both `disable()` and `uninstall()`. `SendCron::register()` self-heals if the 1-minute event is missing from the queue (catches the install-order bug where `maybe_migrate()` ran before `cron_schedules` fired).

## Admin JS overview

| File | Global config | What it does |
|---|---|---|
| `newsletter-admin.js` | `window.lrobEtkNlAdmin` | Autosave: `blur`/`change` on `.lrob-etk-nl-field` inputs → AJAX. Dispatches `lrob-etk-nl-saved` CustomEvent on success. Four dispatch paths: newsletter-card meta, form-card meta, resource rename (list/category), module setting. |
| `newsletter-cards.js` | `window.lrobEtkNlSend` | Send pipeline: drives `Send now` / `Pause` / `Resume` / `Abort` / `Test send` on each card. `loopTick` calls `lrob_etk_nl_send_tick` every 250ms until `remaining === 0`. Per-card `__stopRequested` flag breaks the loop on pause/abort without waiting for the next server response. |
| `newsletter-new-picker.js` | `window.lrobEtkNlNewPicker` | "Start a new subscribe form" modal picker — POSTs to `create-form` endpoint, reloads anchored on the new card. |
| `newsletter-block-editor.js` | `window.lrobEtkNlBlock` | Gutenberg block: `lrob-etk/newsletter-subscribe` — drops a subscribe-form picker into any page/post; server-rendered on the front end. |
| `newsletter-prefs-block-editor.js` | — | Gutenberg block: `lrob-etk/newsletter-preferences` — static editor placeholder; server-rendered on the front end. |
| `etk-audience-picker.js` | data-attrs only | Shared multi-list picker; see "audience picker" section above. |
