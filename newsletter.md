# Newsletter module — design spec

Working design doc for the Newsletter module (target: v0.3.0). Companion to CLAUDE.md, not a replacement. CLAUDE.md still has the cross-module rules (naming, lifecycle, UI patterns) — this file owns only the Newsletter-specific shape.

Status: **v0.3.0 in active implementation** — steps 0–6 shipped (Campaign CPT + composer + admin list now live; sending pipeline still pending). WC My Account integration deferred (non-essential). Next: step 7 (send pipeline). See "Implementation slicing" at the bottom for the precise where-we-are.

## Module identifiers

| Layer | Value |
|---|---|
| Slug | `newsletter` |
| Namespace | `LRob\EmailToolkit\Modules\Newsletter\` |
| Admin page (hub) | `?page=lrob-etk-nl` (single page, `&view=` dispatch) |
| Capability | `manage_lrob_etk` (shared) |
| Table prefix | `{wpdb->prefix}lrob_etk_nl_` |
| Option prefix | `lrob_etk_nl_` |
| User-meta prefix | `lrob_etk_nl_` |
| Hook prefix | `lrob_etk_nl_` (typed: `lrob_etk_newsletter_<event>`) |
| Event vocabulary | `newsletter.*` (dot-namespaced) |
| REST namespace | `lrob-etk/v1/nl/...` |
| CPT — subscribe forms | `lrob_etk_nl_form` (16 chars, fits WP's 20-char CPT limit) |
| CPT — campaigns | `lrob_etk_nl_campaign` (20 chars exactly, fits) |
| CPT — system email templates | `lrob_etk_nl_email_tpl` (21 chars — *over by 1*; use `lrob_etk_nl_etpl` (17 chars) instead) |
| CSS classes | `lrob-etk-nl-*` |
| JS global | `window.lrobEtkNl` |

## Module dependencies

- **SMTP module** is a hard dependency. Newsletter relies on identity routing for `From`, `Reply-To`, and transport. On Newsletter activation, if SMTP isn't enabled, the module surfaces an admin notice and refuses to send campaigns until SMTP is on. (Subscribe-form receipt, schema install, and admin UI work without SMTP — only the send path is gated.)
- **Captcha module** is a soft dependency: it's always-on as a service module per its `is_service_module()`, so Newsletter just calls `CaptchaService::resolve(['context' => Routing::CONTEXT_NEWSLETTER])` and trusts the result.
- **Logging module** is a soft dependency: if enabled, Newsletter cooperates with it (header tagging, dedicated section). If disabled, sends still work; nothing logs.

## Sender identity (default + per-campaign override)

- Module-wide default: option `lrob_etk_nl_default_smtp_identity_id` set on the Newsletter Settings page from the list of active SMTP identities.
- Per-campaign override: post meta `_lrob_etk_nl_smtp_identity_id`. When set, takes precedence. When null/missing, the campaign uses the module-wide default.
- The campaign editor exposes a "Sender" picker showing all active SMTP identities + an "(Use default)" entry. The default identity is displayed as the placeholder.
- If the resolved identity is missing or inactive at send time, the campaign fails with status=`failed` and fires `newsletter.campaign.failed` with a `reason: missing_smtp_identity` payload. No silent fallback to PHP `mail()`.
- The "Sender" filter on `wp_mail` (the SMTP module's `MailRouter`) reads `X-Lrob-Etk-Newsletter-SMTP-Identity: <id>` header injected by the send pipeline and routes accordingly. The header is stripped before the actual send.

## Refactor prerequisite (ships before Newsletter)

The Contact Form module's form-builder (`FormStructure`, `FormEditorRenderer`, `FieldRenderer`, `admin/js/contact-form-fields-editor.js`) gets extracted into a shared location used by both Contact Form and Newsletter. **Shipped — `src/Forms/` exists; the rest of this section describes the landed shape.**

- New namespace: `LRob\EmailToolkit\Forms\` under `src/Forms/` (FormStructure, FormContext, FormEditorRenderer, FieldRenderHelpers, FieldTypeInterface, FieldTypeRegistry, Fields/{Text,Email,Textarea,Number,Phone,Date,Select,Radio,Checkbox,Submit}Field).
- Editor JS moved to `admin/js/form-fields-editor.js`, enqueue handle `lrob-etk-form-fields-editor`. Form-builder DOM now uses the `lrob-etk-form-*` CSS prefix (renamed from `lrob-etk-cf-*`). Module-specific admin chrome and per-module field types (Contact Form's captcha; Newsletter's later) keep their own prefix (`lrob-etk-cf-*` / `lrob-etk-nl-*`).
- `FieldTypeRegistry` is per-CPT: each module's `register()` adds its allowed types for its CPT slug. Stock types (text/email/select/etc.) live in `src/Forms/Fields/`; module-specific types (Contact Form's captcha; Newsletter's captcha + list-picker + category-picker later) live in the module's `Fields/` subdirectory.
- Container exposes the registry as a singleton (`Plugin::boot()` instantiates it before `ModuleManager::boot_all()`).
- Contact Form refactored to consume the shared builder. Existing forms/submissions unchanged. No DB migration — only code re-shuffle.
- `FormContext` is host-neutral: `start($form_id, $instance, $name_prefix, $id_prefix, $editor)` accepts module-specific prefixes (Contact Form passes `lrob_etk_cf` / `lrob-etk-cf`; Newsletter will pass `lrob_etk_nl` / `lrob-etk-nl`).
- This was a discrete patch release before Newsletter work starts. Field types from Contact Form (text, email, textarea, checkbox, radio, select, number, phone, date, captcha, submit) preserved; Newsletter will get a smaller subset (email, name, list-picker, category-picker, captcha, submit) plus its own picker types.

## Data model

### Recipients — two kinds, never duplicated

**WP users** — first-class recipients. State lives in user_meta with `lrob_etk_nl_` prefix:
- `lrob_etk_nl_opted_in` — bool. Default true on user_register (admin-configurable per role).
- `lrob_etk_nl_category_opt_outs` — JSON array of category slugs the user has opted out of.
- `lrob_etk_nl_bounce_count` — int.
- `lrob_etk_nl_status` — enum mirror: `active|bounced` (WP users can't be `trashed` or `refused` — only deleted via WP).
- `lrob_etk_nl_prefs_token` — opaque token for the public prefs page.
- `lrob_etk_nl_confirmed_at` — datetime when subscription confirmed (if double-opt-in was required).

WP users dropping out of the system (account deletion) take all this state with them via WP's standard user-meta cleanup.

**Subscribers** — email-only, no WP account.

Table `wp_lrob_etk_nl_subscribers`:
```
id              bigint(20) unsigned NOT NULL AUTO_INCREMENT
email           varchar(190) NOT NULL              -- unique
name            varchar(190) NOT NULL DEFAULT ''
status          varchar(20)  NOT NULL DEFAULT 'pending'
                              -- pending | confirmed | unsubscribed | refused | bounced | trashed
previous_status varchar(20)  NOT NULL DEFAULT ''   -- for restore-from-trash
category_opt_outs longtext   NOT NULL DEFAULT ''   -- JSON array of category slugs
prefs_token     varchar(64)  NOT NULL              -- opaque, HMAC-derived
source          varchar(50)  NOT NULL DEFAULT ''   -- 'form:<form_id>' | 'import:csv' | 'import:newsletter-plugin' | 'admin'
bounce_count    smallint     NOT NULL DEFAULT 0
confirmed_at    datetime     DEFAULT NULL
created_at      datetime     NOT NULL
trashed_at      datetime     DEFAULT NULL
trashed_reason  varchar(190) NOT NULL DEFAULT ''
PRIMARY KEY (id)
UNIQUE KEY email (email)
KEY status (status)
KEY created_at (created_at)
```

**Status enum** (subscribers only):
- `pending` — created, awaiting confirmation. Picked up by reminder cron.
- `confirmed` — confirmed subscription.
- `unsubscribed` — was confirmed, then opted out (soft).
- `refused` — explicitly clicked "Refuse" in the confirmation email. Excluded from reminders.
- `bounced` — auto-flipped after N hard bounces (default N=3).
- `trashed` — admin-deleted OR user-requested "forget me". Row persists for audit; only "Empty trash" permanently removes it.

`trashed`, `refused`, and `bounced` recipients are excluded from all "Send to" targets including "All subscribers". Only re-surface in dedicated views.

**Auto-promotion on user_register**: hook `user_register`. If a subscriber row exists with the new user's email, copy its state into user_meta (status, confirmed_at, category_opt_outs, source) and delete the subscriber row. Fires `newsletter.subscriber.promoted` for traceability.

**Resubscribe / refused-resub / bounced-resub via subscribe form**: any of the three flips back to `pending` and re-issues a double-opt-in. (No "permanently rejected, can never resubscribe" status — recipients always get one path back if they explicitly act.)

### Lists

Table `wp_lrob_etk_nl_lists`:
```
id          bigint(20) unsigned NOT NULL AUTO_INCREMENT
name        varchar(190) NOT NULL
slug        varchar(190) NOT NULL              -- unique
description text NOT NULL DEFAULT ''
rule_json   longtext NOT NULL DEFAULT ''       -- JSON; empty = no rule (manual-only)
created_at  datetime NOT NULL
updated_at  datetime NOT NULL
PRIMARY KEY (id)
UNIQUE KEY slug (slug)
```

Junction table `wp_lrob_etk_nl_list_members`:
```
id             bigint(20) unsigned NOT NULL AUTO_INCREMENT
list_id        bigint(20) unsigned NOT NULL
recipient_kind enum('user','subscriber') NOT NULL
recipient_id   bigint(20) unsigned NOT NULL
added_at       datetime NOT NULL
PRIMARY KEY (id)
UNIQUE KEY list_recipient (list_id, recipient_kind, recipient_id)
KEY recipient_lookup (recipient_kind, recipient_id)
```

Effective members at send time = explicit junction rows ∪ rule matches, minus `unsubscribed|refused|bounced|trashed`, minus per-category opt-outs for the campaign's category.

Hook on `deleted_user` removes that user's entries from the junction table.

**Rule grammar (v0.3.0)**: JSON shape, all rule sub-clauses ANDed unless explicitly nested. Supported dimensions:
- `recipient_kind`: `user` | `subscriber` | `any` (default `any`).
- `user.role`: WP role slug (only meaningful when recipient_kind=user).
- `user.role_in`: array of roles.
- `user.registered_after` / `user.registered_before`: ISO date.
- `user.meta`: array of `{key, op: '='|'!='|'exists', value}`.
- `subscriber.source_in`: array of source strings.
- `subscriber.created_after` / `subscriber.created_before`: ISO date.
- `subscriber.confirmed_after` / `subscriber.confirmed_before`: ISO date.
- `list_in`: array of list IDs (be on at least one of these lists too — useful for "this list AND that one").
- `not_in_lists`: array of list IDs (exclude).

WooCommerce-purchase-based clauses (`wc.has_purchased_product`, `wc.total_spent_gte`, `wc.last_order_after`) are **post-MVP**.

### Categories

Table `wp_lrob_etk_nl_categories`:
```
id          bigint(20) unsigned NOT NULL AUTO_INCREMENT
name        varchar(190) NOT NULL
slug        varchar(190) NOT NULL              -- unique
description text NOT NULL DEFAULT ''
sort_order  smallint NOT NULL DEFAULT 0
created_at  datetime NOT NULL
PRIMARY KEY (id)
UNIQUE KEY slug (slug)
```

Seeded on install with a `general` category (name: "General"). Required on every campaign. Recipients store opt-outs by slug (not ID) so renames don't break references; deleting a category prompts the admin to migrate campaigns/opt-outs to another category first.

### Campaigns (CPT + companion table)

- CPT `lrob_etk_nl_campaign` (post_title = subject, post_content = block source).
- Post meta with `_lrob_etk_nl_` prefix:
  - `_lrob_etk_nl_preview_text` — string, ~150 chars max.
  - `_lrob_etk_nl_from_name_override` — optional string, falls back to SMTP identity.
  - `_lrob_etk_nl_reply_to_override` — optional string.
  - `_lrob_etk_nl_smtp_identity_id` — int, FK to SMTP identities (nullable = use SMTP module's default routing for 'newsletter' source).
  - `_lrob_etk_nl_category_id` — int, FK to categories. Required at send time.
  - `_lrob_etk_nl_target_spec` — JSON. Shape: `{kind: 'list'|'all_users'|'all_subscribers'|'all', list_id?: int}`.
  - `_lrob_etk_nl_scheduled_at` — datetime UTC, nullable.
  - `_lrob_etk_nl_track_opens` — bool (default true).
  - `_lrob_etk_nl_track_clicks` — bool (default true).
  - `_lrob_etk_nl_log_all_sends` — bool (default false). Override the per-campaign logging default.

Companion table `wp_lrob_etk_nl_campaigns` (hot runtime state — kept off postmeta):
```
post_id          bigint(20) unsigned NOT NULL
status           varchar(20) NOT NULL DEFAULT 'draft'
                   -- draft | scheduled | sending | paused | sent | failed | aborted
total_recipients int unsigned NOT NULL DEFAULT 0
sent_count       int unsigned NOT NULL DEFAULT 0
failed_count     int unsigned NOT NULL DEFAULT 0
skipped_count    int unsigned NOT NULL DEFAULT 0
opens_count      int unsigned NOT NULL DEFAULT 0
clicks_count     int unsigned NOT NULL DEFAULT 0
opens_unique     int unsigned NOT NULL DEFAULT 0
clicks_unique    int unsigned NOT NULL DEFAULT 0
started_at       datetime DEFAULT NULL
completed_at     datetime DEFAULT NULL
last_tick_at     datetime DEFAULT NULL
PRIMARY KEY (post_id)
KEY status (status)
```

Composing: full Gutenberg editor with curated allowed-blocks list (paragraph, heading, image, button, separator, columns, latest-posts, list, quote). Send-time pipeline runs `do_blocks()`, then an in-house CSS-to-inline-styles transformer (no vendor deps, ~200-400 LoC) for email-client compatibility. If Classic Editor plugin is detected as active, surface an admin notice on the Newsletter homepage.

### Campaign recipients (per-send recipient state)

Table `wp_lrob_etk_nl_campaign_recipients`:
```
id             bigint(20) unsigned NOT NULL AUTO_INCREMENT
campaign_id    bigint(20) unsigned NOT NULL
recipient_kind enum('user','subscriber') NOT NULL
recipient_id   bigint(20) unsigned NOT NULL
email_snapshot varchar(190) NOT NULL          -- denormalized for audit if recipient changes later
name_snapshot  varchar(190) NOT NULL DEFAULT ''
domain         varchar(100) NOT NULL          -- for per-domain throttle grouping
status         varchar(20) NOT NULL DEFAULT 'pending'
                 -- pending | sent | failed | skipped | bounced
sent_at        datetime DEFAULT NULL
failure_code   varchar(80) NOT NULL DEFAULT ''
opens          smallint unsigned NOT NULL DEFAULT 0
last_open_at   datetime DEFAULT NULL
clicks         smallint unsigned NOT NULL DEFAULT 0
last_click_at  datetime DEFAULT NULL
unsubscribed_via_email tinyint(1) NOT NULL DEFAULT 0   -- one-click List-Unsubscribe
PRIMARY KEY (id)
UNIQUE KEY campaign_recipient (campaign_id, recipient_kind, recipient_id)
KEY status (status)
KEY domain_pending (campaign_id, domain, status)
```

Materialized when sending starts (or at schedule fire). Status transitions are the only writes during a send. Crash-safe: next AJAX/Cron tick picks up `pending` rows.

### Tracking events

Table `wp_lrob_etk_nl_tracking_events`:
```
id             bigint(20) unsigned NOT NULL AUTO_INCREMENT
campaign_id    bigint(20) unsigned NOT NULL
recipient_kind enum('user','subscriber') NOT NULL
recipient_id   bigint(20) unsigned NOT NULL
kind           enum('open','click','unsubscribe') NOT NULL
url            varchar(500) NOT NULL DEFAULT ''
ip_anon        varchar(45) NOT NULL DEFAULT ''
user_agent     varchar(500) NOT NULL DEFAULT ''  -- empty unless campaign opts in
occurred_at    datetime NOT NULL
PRIMARY KEY (id)
KEY campaign_kind (campaign_id, kind)
KEY occurred_at (occurred_at)
KEY recipient (recipient_kind, recipient_id)
```

Retention setting `lrob_etk_nl_tracking_retention_days` (default 365). Daily cron prunes.

## Scale & performance

Design target: campaigns up to **10 million recipients** must work. Most sites won't push that — but the architecture must not collapse there. Concretely:

- **Recipient materialization is chunked**. Don't `INSERT INTO ... SELECT` 10M rows at once. Loop in chunks of 10k via offset/cursor on the source recipient ids. Each chunk inserted in a single statement. Campaign status stays `materializing` (a new intermediate status before `sending`) until done. Progress visible on the campaign view.
- **Campaign view metrics use the companion `wp_lrob_etk_nl_campaigns` table's pre-computed counters** — never `SELECT COUNT(*)` against the recipients table for header tiles. Per-domain queue health uses cached aggregates updated on each batch tick, not live queries.
- **Subscriber/list views are paginated** with cursor-based pagination (last-id-seen), not OFFSET — OFFSET breaks down past ~100k rows.
- **Lists rule evaluation for huge audiences** uses streaming. The rule compiler emits SQL with cursor pagination; results pipe into the recipient materializer chunk-by-chunk. No "load all recipient ids into PHP memory" anywhere.
- **Tracking events table** will dominate disk. Daily retention cron runs `DELETE ... LIMIT 5000` in a loop with `WHERE occurred_at < ?` until done — never one giant DELETE.
- **Export of 10M subscribers** as JSON would be a multi-gigabyte file. Export streams to disk in NDJSON-style chunks (one subscriber per line, then close-section markers) wrapped in the outer JSON envelope. Download served with chunked transfer-encoding. Same for import: streaming JSON parser, not `json_decode` of the whole file.
- **Index design**:
  - `wp_lrob_etk_nl_subscribers`: unique on `email`, indexes on `status`, `created_at`. Per-status counts via `COUNT(*) WHERE status=?` use the index.
  - `wp_lrob_etk_nl_campaign_recipients`: critical composite index `(campaign_id, status, domain)` for the send loop's "next batch grouped by domain" query.
  - `wp_lrob_etk_nl_tracking_events`: `(campaign_id, kind)` for stats, `(occurred_at)` for retention pruning.
- **Send-loop micro-optimization**: each batch claims its rows via `UPDATE ... SET status='sending', tick_id=? WHERE status='pending' AND campaign_id=? LIMIT N` then SELECTs the marked rows. Avoids races between AJAX and Cron paths. After processing, `UPDATE status='sent'|'failed'`.
- **Dynamic loading on heavy admin views**: the campaign view's "Recipient breakdown" tab loads via AJAX, not in the initial page render. Same for the tracking-events timeline on a per-recipient drilldown.
- **Domain throttle bookkeeping** uses a small in-memory window (last N seconds of sends per domain) backed by a transient. No "count rows in DB" per send.

When in doubt, default to chunked. Premature optimization is fine here because the scale target is locked.

## Subscribe forms

- New CPT `lrob_etk_nl_form` using the shared form-builder (post-refactor).
- Allowed field types: `email` (required), `name` (optional), `list_picker` (subscriber chooses which list(s)), `category_picker` (subscriber chooses default category opt-outs at signup), `captcha`, `submit`. Plus inherited honeypot + time-trap from the shared form-builder's anti-spam layer.
- Public Gutenberg block `lrob-etk/newsletter-subscribe` (picks a newsletter form by ID — restricted to newsletter-form posts).
- Shortcode `[lrob_etk_nl_subscribe id="…"]`.
- Contact-form block can't see newsletter forms; subscribe block can't see contact forms (registry filter).
- Existing `newsletter_signup` template in Contact Form's `TemplateRegistry.php` is retired on Newsletter v0.3.0 activation.
- Each form's edit page has a **toolbar dropdown** ("header menu") to pick which confirmation-email template applies to its signups. Default = the module's built-in confirmation template. Multiple templates supported — see "System email templates" below.
- Each form has a **default list** setting (in the form's settings panel). If the form has no `list_picker` field, signups via this form go into the default list. If no default is set, no list memberships are created — the subscriber lands as a confirmed recipient with no list.

**Submit flow**:
1. Frontend POST hits `lrob_etk_nl_subscribe` AJAX action.
2. Server validates email + honeypot + time-trap + rate-limit.
3. `CaptchaService::resolve(['context' => Routing::CONTEXT_NEWSLETTER])` (already wired in `src/Modules/Captcha/Routing.php`) — captcha is on by default for this context.
4. Look up by email:
   - No row + no WP user → create subscriber row (status=pending), assign prefs_token, send double-opt-in.
   - Existing subscriber (`pending` / `unsubscribed` / `refused` / `bounced` / `trashed`) → flip back to `pending`, resend double-opt-in, untrash if applicable. Fire `newsletter.subscriber.resubscribed`.
   - Existing subscriber (`confirmed`) → silently treat as success (don't reveal that they're already subscribed; anti-enumeration).
   - Matches a WP user's email → create no subscriber row; flip the user's `lrob_etk_nl_opted_in` to true and apply chosen lists/categories. If double-opt-in was required (admin setting), send the same confirmation email.
5. Apply chosen list memberships (if list_picker present and any selected) and category opt-outs.
6. Return success response. Frontend shows configured success message (inline / redirect / "check your inbox" page).

## System email templates (CPT)

Confirmation emails, reminder emails, and refuse-acknowledgments are **first-class templates** — composed with the same Gutenberg editor as campaigns, rendered through the same CSS-inliner pipeline.

- CPT: `lrob_etk_nl_etpl` (17 chars, fits the 20-char limit).
- Each template has a `purpose` post meta: `confirmation` | `reminder` | `refuse_ack` | `welcome` (welcome is post-MVP).
- Token substitution before CSS inlining: `{{confirm_url}}`, `{{refuse_url}}`, `{{prefs_url}}`, `{{first_name}}`, `{{name}}`, `{{email}}`, `{{site_name}}`. Save-time validation enforces that confirmation templates contain BOTH `{{confirm_url}}` and `{{refuse_url}}` (block the save with an admin notice otherwise).
- Module ships with one default template per purpose, auto-created on activation if missing. Default templates carry a `_lrob_etk_nl_template_is_default` meta = true so admins can identify them; they're editable, not locked.
- Subscribe forms pick which `confirmation` template applies (form-level toolbar dropdown). The reminder cron picks one `reminder` template (module-wide setting `lrob_etk_nl_reminder_template_id`).
- Templates are exposed in the Newsletter admin under a "Templates" section in the homepage hub.

Same Gutenberg allowed-blocks list as campaigns. Same sender-identity resolution (a template can override the default identity if the admin wants confirmation emails to come from a different mailbox).

## Double-opt-in flow

Confirmation email body comes from the form's chosen `confirmation`-purpose template (see above). Token substitution produces a unique email per subscriber. The email contains **three CTAs**:
- **Confirm** → status `pending → confirmed`. Fires `newsletter.subscriber.confirmed`. Lands on configurable success page.
- **No, I don't want this** (Refuse) → status `pending → refused`. Fires `newsletter.subscriber.refused`. Lands on brief acknowledgment page.
- No action → stays `pending`. Picked up by reminder cron.

Each link is a tokenized URL: `?lrob-etk-nl-confirm=<token>` / `?lrob-etk-nl-refuse=<token>`. Token = HMAC of `(subscriber_id, action)` using a key derived from `AUTH_KEY` via the existing `Support\Encryption` HKDF path.

### Reminder cron for `pending` subscribers

- Daily cron `lrob_etk_nl_pending_followup`.
- Settings: `lrob_etk_nl_reminder_max` (default 2), `lrob_etk_nl_reminder_interval_days` (default 7), `lrob_etk_nl_first_reminder_after_days` (default 3).
- 0 reminders = feature disabled.
- After max reminders, the subscriber stays `pending` indefinitely; admin must trash manually or rerun manually via bulk action.
- Fires `newsletter.subscriber.reminder_sent` per dispatch.

## Trash

- Subscribers with status=`trashed` live in the Trash view (sub-tab of Subscribers).
- Actions: **Restore** (returns to `previous_status`), **Permanently delete** (one row), **Empty trash** (all rows with confirmation modal).
- Trashing can be admin-initiated (bulk action on the list view) or user-initiated ("forget me entirely" button on the prefs page).
- Auto-purge setting `lrob_etk_nl_trash_auto_purge_days` — default 0 (never auto-purge). Set to e.g. 365 to purge after a year via daily cron.

## Categories & per-recipient preferences

Every campaign picks a category at send time. Recipients can opt out of any category individually. Effective filter at send time:

```
status IN ('confirmed') AND
NOT trashed/refused/bounced/unsubscribed AND
campaign's category slug NOT IN recipient's category_opt_outs AND
opted_in flag is true (WP users only)
```

Default category opt-outs at signup: none unless the subscribe form's `category_picker` field is present and the subscriber unchecked categories there.

## Preferences page (prefs surfaces)

Same UI rendered in four surfaces:
1. **Public token URL**: `/?lrob-etk-nl-prefs=<token>`. Used in every email footer.
2. **WP profile section** for logged-in WP users. Token not needed; uses the current user.
3. **WooCommerce My Account** endpoint (if WC active + admin setting on; default on when WC detected). Renders the same prefs UI under WC's account chrome.
4. **Gutenberg block** `lrob-etk/newsletter-preferences` — drop the prefs panel into any page (only renders for logged-in users; logged-out viewers see a "please log in or use the link from your email" hint).

Prefs UI controls:
- Per-category opt-in checkboxes.
- (Subscribers only) Master "Unsubscribe from everything" button → status=`unsubscribed`.
- (Subscribers only) "Forget me entirely" button → status=`trashed`, opens confirmation.
- (WP users only) Master opt-out toggle → flips `lrob_etk_nl_opted_in=false`. Note text: "To fully delete your data, delete your account from your profile page."

## Send pipeline (AJAX + Cron hybrid)

Two entry paths feed the **same** recipient state. State transitions are atomic via per-campaign DB lock.

**Materialization step** (one-time per campaign):
- Triggered by "Send now" click OR by the scheduled-time cron fire.
- Resolves the target_spec into the campaign-recipients table:
  - `list` → list members minus exclusions.
  - `all_users` → WP users with `lrob_etk_nl_opted_in=true` and not bounced.
  - `all_subscribers` → subscribers with status=`confirmed`.
  - `all` → union of the above.
- Applies category opt-out filter.
- Populates `email_snapshot`, `name_snapshot`, `domain`.
- Sets campaign status `draft|scheduled → sending`.
- Sets `total_recipients`.
- Fires `newsletter.campaign.started`.

**AJAX path (admin clicks Send now)**:
- Endpoint `lrob_etk_nl_send_tick` loops: claim N pending recipients (where N = batch size, default 50), send each, update status, return progress.
- Admin UI shows live progress bar (sent / failed / remaining).
- Throttle check before each send: count this campaign's sends to `recipient.domain` in the last hour. If at limit, skip this batch for that domain (recipient stays `pending`).
- Closing the tab is safe: pending recipients remain. The Cron path picks up where AJAX left off.

**Cron path (always-on safety net)**:
- WP-Cron event `lrob_etk_nl_send_tick` runs every minute.
- Finds campaigns in status=`sending` not touched in the last 2 minutes (`last_tick_at` stale). Processes one batch per campaign per tick.
- Same throttle logic as AJAX.

**Completion**:
- When a campaign has zero `pending` recipients left, status → `sent`, `completed_at` set, fires `newsletter.campaign.completed`.

**Test send (campaign editor)**:
- Popover button "Send test" on the campaign view (same anchored-popover pattern as SMTP test-send).
- Three targets:
  1. Single ad-hoc address (admin types it in).
  2. A "test list" — option `lrob_etk_nl_test_list_id` points at one regular list; when set, the popover shows "Send to test list (N members)". Maintained manually by the admin (no special flag on the list — it's a regular list used for this purpose).
  3. The admin's own email (one-click button).
- Test sends DO substitute personalization tokens (so admin sees the real email shape) but mark recipients with `X-Lrob-Etk-Newsletter-Test: 1` header and DO NOT write to `wp_lrob_etk_nl_campaign_recipients` or update counters. Tracking links work but tracking events from test sends are flagged so they're excluded from real stats.
- Test sends never modify subscribers' bounce_count or status.

**Per-domain throttle**:
- Option `lrob_etk_nl_domain_throttle` is a map: `{ "laposte.net": 30, "free.fr": 30, "orange.fr": 30, "sfr.fr": 30, "wanadoo.fr": 30, "gmail.com": 200, "outlook.com": 200, "yahoo.com": 200, "*": 100 }` (sends per hour).
- Conservative defaults for known-strict French ISPs. Wildcard `*` for everything else.
- Send rate display on the campaign view shows per-domain queue state during a send.

**Pause / Resume / Abort**:
- Pause: status `sending → paused`. Both AJAX and Cron skip the campaign. Pending rows stay.
- Resume: status `paused → sending`. Cron picks back up next tick; admin can hit "Send now" to resume via AJAX too.
- Abort: status `sending|paused → aborted`. Remaining `pending` rows flip to `skipped`. No undo. Fires `newsletter.campaign.aborted`.

**Transport**: `wp_mail()` per recipient. Honors SMTP module's identity routing (campaign's `_lrob_etk_nl_smtp_identity_id` post meta → SMTP module respects it via the `lrob_etk_email_sending` filter chain). Header `X-Lrob-Etk-Newsletter-Campaign-ID: <post_id>` added to every send.

## Logging integration

- Default: only **failures** are logged to `wp_lrob_etk_logs`. Success rows live in `wp_lrob_etk_nl_campaign_recipients` only. The Logging module reads `X-Lrob-Etk-Newsletter-Campaign-ID` and skips writing a log row when the send succeeded.
- Per-campaign override `_lrob_etk_nl_log_all_sends=true` logs every send for that campaign.
- Logs UI gains a "Newsletter" filter (off by default). Newsletter-tagged rows are hidden from the default view to keep it clean.
- Optional one-row-per-campaign summary log on completion (off by default; admin opt-in via `lrob_etk_nl_log_campaign_summary`).

## Unsubscribe (RFC 8058)

Every outbound email includes:
```
List-Unsubscribe: <mailto:unsub-<token>@<site_domain>>, <https://<site>/?lrob-etk-nl-unsub=<token>>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

Where `<token>` = HMAC of `(campaign_id, recipient_kind, recipient_id, 'unsub')`.

**One-click action (RFC 8058)**:
- Hits the HTTPS endpoint with `POST { "List-Unsubscribe": "One-Click" }`.
- Effect: opts the recipient out of the **campaign's category** (not the whole newsletter). For subscribers, also untrashes nothing (their status is unchanged); for WP users, sets the category opt-out.
- Lands on a confirmation page with link to the full prefs page for more control.
- Fires `newsletter.tracking.unsubscribed` (records on the tracking_events table with kind=`unsubscribe`).

**Mailto unsubscribe**: a `lrob_etk_nl_mailto_unsub` inbound-email mechanism is **post-MVP** (needs IMAP infra). v0.3.0 ships the HTTPS variant only; some mail clients fall back to the URL when mailto isn't supported.

## Tracking

- Per-campaign toggles `_lrob_etk_nl_track_opens` and `_lrob_etk_nl_track_clicks` (default on).
- **Open pixel**: `/wp-json/lrob-etk/v1/nl/track/open/<token>` returns a 1x1 transparent GIF. Token = HMAC of `(campaign_id, recipient_kind, recipient_id, 'open')`. Forgery- and enumeration-resistant.
- **Click rewriter**: send-time pass over rendered HTML, rewrites every `<a href>` (excluding `mailto:`, `#anchors`, prefs/unsub links) to `/wp-json/lrob-etk/v1/nl/track/click/<token>?to=<urlencoded original>`. Endpoint records the click + 302-redirects.
- **IP anonymization**: IPv4 → /24, IPv6 → /48 before storage in `tracking_events.ip_anon`.
- **User-agent**: not stored by default. Per-campaign opt-in via `_lrob_etk_nl_track_user_agent` post meta.
- Tracking-events retention: `lrob_etk_nl_tracking_retention_days` (default 365).

## Bounces

- Passive only in v0.3.0. When `wp_mail()` returns false OR PHPMailer surfaces a 5xx hard failure (via PHPMailer's `\PHPMailer\PHPMailer\Exception`), the recipient row's `bounce_count` increments.
- After N hard bounces (default `lrob_etk_nl_hard_bounce_threshold=3`), status flips to `bounced` (for both subscribers and WP users via user_meta).
- Soft 4xx bounces tracked separately (column on the campaign-recipients row: `failure_code` distinguishes them). Soft bounces never auto-unsubscribe.
- Active IMAP bounce parsing is post-MVP, tied to the deferred IMAP "save to sent" work.

## Import / export

One unified wizard at `?page=lrob-etk-nl&view=import`. Three input sources:
1. **Newsletter plugin** (wordpress.org/plugins/newsletter/) — auto-detected if `wp_newsletter*` tables exist.
2. **JSON file** — produced by the Export feature; full restore.
3. **CSV file** — manual upload with column mapping.

### CSV import (manual)

- Detect delimiter (`,`, `;`, `\t`) and header presence.
- Auto-map columns by header name. Multi-language hints:
  - email: `email`, `e-mail`, `courriel`, `adresse e-mail`, `mail`.
  - name: `name`, `nom`, `full name`. Also recognizes `firstname` + `lastname` to concatenate.
  - status: `status`, `statut`. Values map to status enum (case-insensitive).
  - list / category columns: any header matching an existing list or category name is treated as a boolean membership column (`1` / `yes` / `oui` / `true` = member).
- Admin sees a preview table with detected mappings and can override per-column.
- Validation step before commit: invalid emails, duplicate emails (across CSV + against existing rows), missing required columns.
- Optional per-row status override; otherwise the import-wide confirmation mode applies.

### Newsletter-plugin import

- Subscribers (email, name, status mapping → our enum). Idempotent: existing emails skipped.
- Lists + list memberships. Imported as manual lists (`rule_json=''`).
- Campaigns: best-effort template import only. Subject + body HTML become a draft block. Their template-language tags converted to `<!-- newsletter:template-tag -->` placeholder blocks for admin review. Send history NOT imported.

### Confirmation mode picker (applies to every import path)

- **Mark as already confirmed** (admin attests prior consent). Status=`confirmed` directly, no email sent.
- **Send confirmation email** — status=`pending`, double-opt-in dispatched.
- **Send confirmation email + reminder sequence** — same plus reminder cron enabled for these rows.

### Conflict resolution (full-restore JSON)

Per-section: skip-duplicates (default) / merge / replace. Cross-references (list memberships, campaign target IDs) remapped automatically.

### Export

- "Export everything" button on Newsletter Settings.
- Single `.json` file containing: categories, lists (with rule_json), list memberships, subscribers (full row state including trash/refused/bounced), campaigns (post fields + meta + companion row), settings, per-domain throttle map.
- **Not** exported: campaign-recipients (too big, per-send state), tracking events.
- Versioned format: `{ "schema": 1, "exported_at": ..., "module_version": "0.3.0", "data": { ... } }`.
- Tokens (prefs_token, etc.) are exported — restoring on a different site lets recipients re-use existing links. Admin can choose to regenerate all tokens on import.

## Captcha integration

- Existing `Routing::CONTEXT_NEWSLETTER = 'newsletter_subscribe'` (already declared in `src/Modules/Captcha/Routing.php`).
- Subscribe form calls `CaptchaService::resolve(['context' => Routing::CONTEXT_NEWSLETTER])` at render and verify time.
- Default routing: inherit from `Routing::KEY_DEFAULT` (which itself defaults to `homemade:math`).
- The Captcha settings page (already shipped) shows the Newsletter context — no work needed there.

## Events (vocabulary frozen at v0.3.0)

```
newsletter.subscriber.added           — pending subscriber created (via form, import, or admin)
newsletter.subscriber.confirmed       — double-opt-in succeeded
newsletter.subscriber.refused         — clicked Refuse in confirmation email
newsletter.subscriber.unsubscribed    — soft unsubscribe (was confirmed, opted out)
newsletter.subscriber.trashed         — moved to trash (admin or user action)
newsletter.subscriber.restored        — restored from trash
newsletter.subscriber.purged          — permanently deleted from trash
newsletter.subscriber.bounced         — auto-flipped to bounced status
newsletter.subscriber.promoted        — subscriber row absorbed into a new WP user account
newsletter.subscriber.resubscribed    — was unsubscribed/refused/bounced/trashed, signed up again
newsletter.subscriber.reminder_sent   — pending-followup reminder dispatched

newsletter.campaign.created           — draft saved for the first time
newsletter.campaign.scheduled         — scheduled-at set, status=scheduled
newsletter.campaign.started           — materialization complete, sending begins
newsletter.campaign.paused
newsletter.campaign.resumed
newsletter.campaign.completed         — all recipients reached terminal state
newsletter.campaign.aborted           — admin aborted mid-send
newsletter.campaign.failed            — fatal error (e.g. SMTP identity missing)

newsletter.recipient.sent
newsletter.recipient.failed
newsletter.recipient.skipped          — aborted campaigns convert pending → skipped

newsletter.tracking.opened
newsletter.tracking.clicked
newsletter.tracking.unsubscribed      — one-click List-Unsubscribe

newsletter.import.started
newsletter.import.completed
newsletter.import.failed
newsletter.export.generated
```

Renaming or removing any of these is a breaking change (per CLAUDE.md's event API stability rules).

## Admin UI

Single-page hub at `?page=lrob-etk-nl` with `&view=` dispatch:

- `&view=` (no view) — Dashboard. Tiles: total recipients (split: WP users / subscribers), recent campaign with summary, send-queue status, last-30-days opens/clicks, per-domain throttle health, top categories.
- `&view=campaigns` — Campaign list + new-campaign + per-campaign view.
- `&view=subscribers` — Subscribers list. Sub-tabs: All / Pending / Confirmed / Unsubscribed / Refused / Bounced / Trash.
- `&view=lists` — Lists list + new-list + edit (rule editor).
- `&view=categories` — Categories list + new-category.
- `&view=forms` — Newsletter forms list. Empty state invites first-form creation (Contact-Form-style).
- `&view=templates` — System email templates list (confirmation, reminder, refuse-ack). Edit-in-place.
- `&view=settings` — Default SMTP identity, default category, default list per role, reminder cron settings (max + interval + which template), per-domain throttle map, bounce threshold, tracking defaults, trash auto-purge days, test-list ID, WC My Account integration toggle, export button.
- `&view=import` — Unified import wizard.

UI patterns inherit from CLAUDE.md's UI conventions: card grids, auto-save edit cards, anchored popovers, custom comboboxes, custom data tables. No new patterns introduced; reuse `lrob-etk-` primitives.

## Out of scope for v0.3.0 (first Newsletter ship)

- **WooCommerce purchase-data dimensions** in list rules (`wc.has_purchased_product`, `wc.total_spent_gte`, `wc.last_order_after`). Lands in a follow-up minor.
- **Active IMAP bounce parsing**. Tied to the deferred IMAP "save to sent" work.
- **Mailto-based List-Unsubscribe** processing. HTTPS variant only.
- **A/B campaign testing**.
- **Custom email-builder UI** (we use Gutenberg).
- **Send-statistics importer** from the Newsletter plugin (only templates + subscribers + lists imported).
- **WP-user trash** (only category-level opt-out for WP users; full removal = delete the WP account).

## Implementation slicing (status: where-we-are anchor)

### ✅ Shipped

0. ✅ **Refactor → src/Forms/**. v0.2.2 (released on GitHub). Form-builder shared between modules.
1. ✅ **Schema + recipient model**. Schema v1 with 7 tables; user_register / deleted_user hooks; SMTP-dependency admin notice; hub shell at `?page=lrob-etk-nl` with `&view=` dispatch.
2. ✅ **System email templates** (renamed "Onboarding"). `lrob_etk_nl_etpl` CPT, three seeded purposes (confirmation / reminder / refuse_ack), token registry + substitution + validator; "+ New from default" action; token-reference metabox in editor. Schema v3 includes a repair migration for the v2 esc_url_raw token-stripping bug.
3. ✅ **Subscribe forms**. `lrob_etk_nl_form` CPT, FormsPage card-grid admin matching Contact Form's UX, `Blocks` + shortcode + `EmbedRenderer`, frontend `assets/js/form-submit.js` shared between modules, `SubmitHandler` with full pipeline (nonce → form → honeypot → time-trap → captcha → email → recipient resolution → confirmation dispatch), `ConfirmationTokens` HMAC, `ConfirmationDispatcher`, `ConfirmationHandler` for confirm/refuse URLs. Starter templates (blank / email_only / email_name). Shared `Combobox`, `StylePresets`, `CaptchaField`, `Honeypot` in `src/Forms/` + `src/Admin/`.
4a. ✅ **Categories + Lists**. `CategoryRepository`, `ListRepository` (manual lists only; rule_json stays empty). Admin CRUD pages for both. `CategoryPicker` + `ListPicker` field types registered for the newsletter CPT. SubmitHandler captures picker values, applies opt-outs / list memberships for both WP-user and subscriber recipients. Form-card "default list" picker; "+ Field" menu surfaces both new field types.
4b. ✅ **Prefs page + WP profile + one-click unsubscribe + reminder cron**. Schema v4 added `reminder_count` + `last_reminder_at` + `prefs_token` index + `pending_followup` composite index. `PrefsRenderer` (shared UI), `PrefsHandler` (public token URL: GET renders prefs, POST saves / unsubscribes / forget-me; one-click unsub POST endpoint per RFC 8058 with `OK` 200 response), `ProfileSection` (WP user-edit page integration with scoped CSS to tame `<legend>` sizing), `ReminderCron` (daily, scheduled on enable/unscheduled on disable). `ConfirmationDispatcher` now adds `List-Unsubscribe` + `List-Unsubscribe-Post` headers and substitutes `{{prefs_url}}`. `SettingsPage` real page with reminder-schedule controls; same controls mirrored on Onboarding view (Settings is the central hub; Onboarding is the canonical contextual home — both call the shared `SettingsPage::render_reminder_schedule_section()`).
4c. ✅ **Gutenberg prefs block + shortcode** (partial of original 4c — WC My Account postponed as non-essential). `lrob-etk/newsletter-preferences` block + `[lrob_etk_nl_preferences]` shortcode render the prefs UI on any public page. Three render paths: logged-in WP user → full prefs form (with lazy-minted `prefs_token` user_meta if missing); anonymous + valid token URL → handled by `PrefsHandler` directly (never reaches the block); anonymous without token → short message pointing at email links or login. POST flows through `PrefsHandler` via the user's prefs token URL with a `_lrob_etk_nl_return_to` hidden field so the user lands back on the host page with a `?lrob_etk_nl_saved=1` flash instead of the standalone prefs page. `PrefsRenderer::render_full_form` gained an optional `$return_to` parameter.
5. ✅ **Trash + refuse polish**. `SubscribersPage` admin view with status sub-tabs (all / pending / confirmed / unsubscribed / refused / bounced / trashed), search (email + name LIKE), and pagination. Inline row actions: Trash (any non-trashed) / Restore / Permanently delete (trashed only). Empty-trash bulk action on the trashed tab. `SubscriberRepository` gained: `list_with_filters`, `count_with_filters`, `counts_by_status`, `trash`, `restore`, `permanently_delete`, `empty_trash`, `purge_old_trash`. New `TrashCron` (`lrob_etk_nl_trash_purge`, daily, MAX_BATCHES * 500 deletions per tick) honours `lrob_etk_nl_trash_auto_purge_days` (0 = disabled = default). SettingsPage gained a "Trash retention" section mirrored on the Subscribers trash-tab info banner. `ConfirmationHandler::handle_refuse` now uses the seeded `refuse_ack` template (if its content is non-empty) as the acknowledgment page body via a new `render_template_page` helper (wp_kses_post for safety); falls back to the hardcoded short message otherwise.
6. ✅ **Campaign CPT + composer**. `CampaignCPT` registers `lrob_etk_nl_campaign` (19 chars, fits 20-char limit), Gutenberg-enabled, same constrained block subset as TemplateCPT. Plural-caps mapping pattern. `register_meta` for: `_lrob_etk_nl_preview_text`, `_from_name_override`, `_reply_to_override`, `_smtp_identity_id`, `_category_id`, `_target_spec` (JSON: `{kind, list_id?}`), `_scheduled_at` (UTC), `_track_opens`, `_track_clicks`, `_log_all_sends`. `CampaignMetaboxes` ships four boxes (Sender & preview / Audience / Schedule / Tracking) — vanilla PHP/JS, no JSX build. SMTP identity picker pulls from the SMTP module's `IdentityRepository` when available (gracefully empty when SMTP is disabled). `CampaignRepository` for the companion table: `ensure_row` (INSERT IGNORE on save), `update_status`, `delete_for_post` (cleans companion + campaign_recipients on post delete), `list_all` (JOIN with wp_posts for the admin view). `CampaignsPage` admin view ships at `?page=lrob-etk-nl&view=campaigns` (replaces the placeholder). Companion `status` flips between draft ↔ scheduled based on whether `_scheduled_at` is set, but only while the campaign is still pre-send — the send pipeline (step 7) drives it from there. Test-send popover deferred to step 7 (cleaner to bundle with the real send pipeline).

### 🚧 Next

7. **Send pipeline**: chunked materialization, recipients table, AJAX endpoint, Cron tick, per-domain throttle, status transitions, pause/resume/abort, sender-identity routing. Test-send popover lands here too (uses the same pipeline as real sends, in a "test" tick that doesn't materialise into `campaign_recipients`).

### Deferred (originally in 4c)

- **WC My Account integration** — admin-toggleable section under WooCommerce's My Account pages rendering the same `PrefsRenderer::render_inputs`. Non-essential; the Gutenberg block + shortcode already cover the "public prefs surface" use case for WC sites willing to drop the block onto their account page.

### Later

8. **Logging integration**: header tagging, Logging-module filter for newsletter, "Log all sends" override.
9. **Tracking**: open pixel, click rewriter, tracking events table, retention cron, test-send exclusion.
10. **Bounce handling**: failure detection, bounce_count tracking, auto-bounce threshold.
11. **Import wizard**: CSV importer with column mapping, JSON full-restore, Newsletter-plugin importer, confirmation-mode picker.
12. **Export** (streaming).
13. **Dashboard polish**: tiles, queue health, recent-campaign details.
14. **CSS inliner**: written along the way; finalized before v0.3.0 ship.
15. **Localization sweep**: `wp i18n make-pot`, French translations, ship.

### Polish landed during iteration (not in original slicing)

- Subscribe forms admin: card-grid matches Contact Form UX, "+ New from default" starter modal, always-visible footer delete link, per-form style preset (uses shared `StylePresets`).
- Editor JS save plumbing refactored: `lrobEtkFormEditor.save = {url, action, nonce}` so both modules drive `form-fields-editor.js` against their own AJAX endpoints. Action name no longer hardcoded.
- CSS class rename `lrob-etk-cf-*` → `lrob-etk-form-*` for form-builder DOM (already in 0.2.2 but extended to captcha-stub-* in step 3).
- Module enable/disable toggle gated to the Dashboard view only — subpages don't render it.
- Newsletter hub-wide JS enqueue moved from `FormsPage::enqueue_assets` to `HomePage::enqueue_assets` so per-card auto-save works on Categories / Lists / Settings views too.

## Open questions during iteration

None blocking. Resubscribe always re-issues double-opt-in. WP users skip double-opt-in on form submit (already email-verified at registration). Confirmation emails get full List-Unsubscribe + List-Unsubscribe-Post headers. Reminder cron defaults: max=2, first-after=3d, interval=7d (admin-configurable).

## Open questions

None at design time — all batch-1-through-8 decisions are locked. Implementation will surface its own details (concrete CSS-inliner scope, exact token list, exact reminder template defaults) which will be folded into this doc as they're resolved.
