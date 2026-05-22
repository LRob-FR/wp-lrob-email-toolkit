# Newsletter module — design spec

Working design doc for the Newsletter module (target: v0.3.0). Companion to CLAUDE.md, not a replacement. CLAUDE.md still has the cross-module rules (naming, lifecycle, UI patterns) — this file owns only the Newsletter-specific shape.

Status: **v0.3.0 in active implementation** — steps 0–8 + step 7b core shipped (cron safety net, pause/resume/abort, scheduled-send cron handoff), plus the **Campaign → Newsletter vocabulary rename** (CPT slug `lrob_etk_newsletter`, tables `wp_lrob_etk_nl_newsletters` + `wp_lrob_etk_nl_newsletter_recipients`, schema migration v6 in place), the **newsletter cards refactor** (settings + send actions live on each card; Gutenberg is content-only), and **step 8 — Logging integration** (Logger reads `X-Lrob-Etk-Newsletter-ID` + `X-Lrob-Etk-Newsletter-Recipient-ID` headers, deletes successful newsletter rows by default, per-newsletter "Log every send" override flips back to full logging, LogsPage gained a tri-state Newsletter filter [hide / show all / newsletter only], recipients drawer surfaces "View in Logs →" per failed row). Deferred to step 7b polish: per-domain throttle, CSS inliner. Next: step 9 (Tracking — design re-locked around media-URL rewriting + per-subscriber lifetime stats + cold-subscriber detection; see Tracking section + CLAUDE.md backlog). See "Implementation slicing" at the bottom + the CLAUDE.md backlog section for the precise where-we-are.

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
| CPT — newsletters | `lrob_etk_newsletter` (19 chars, fits) |
| CPT — system email templates | `lrob_etk_nl_etpl` (16 chars; the longer `lrob_etk_nl_email_tpl` would overflow at 21) |
| CSS classes | `lrob-etk-nl-*` |
| JS global | `window.lrobEtkNl` |

## Module dependencies

- **SMTP module** is a hard dependency. Newsletter relies on identity routing for `From`, `Reply-To`, and transport. On Newsletter activation, if SMTP isn't enabled, the module surfaces an admin notice and refuses to send newsletters until SMTP is on. (Subscribe-form receipt, schema install, and admin UI work without SMTP — only the send path is gated.)
- **Captcha module** is a soft dependency: it's always-on as a service module per its `is_service_module()`, so Newsletter just calls `CaptchaService::resolve(['context' => Routing::CONTEXT_NEWSLETTER])` and trusts the result.
- **Logging module** is a soft dependency: if enabled, Newsletter cooperates with it (header tagging, dedicated section). If disabled, sends still work; nothing logs.

## Sender identity (default + per-newsletter override)

- Module-wide default: option `lrob_etk_nl_default_smtp_identity_id` set on the Newsletter Settings page from the list of active SMTP identities.
- Per-newsletter override: post meta `_lrob_etk_nl_smtp_identity_id`. When set, takes precedence. When null/missing, the newsletter uses the module-wide default.
- The newsletter card exposes a "Sender" picker showing all active SMTP identities + an "Automatic (<default name>)" entry. The default identity is shown inline so the admin sees which mailbox "automatic" resolves to.
- If the resolved identity is missing or inactive at send time, the newsletter fails with companion status=`failed` and fires `newsletter.failed` (planned — not yet emitted) with a `reason: missing_smtp_identity` payload. No silent fallback to PHP `mail()`.
- SMTP identity resolution happens **inside** `SendLoop` (the loop reads the identity id from companion meta and applies the routing directly). No `X-Lrob-Etk-Newsletter-SMTP-Identity` header is emitted on the wire — the SMTP module isn't consulted via filter for newsletter sends.

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

Effective members at send time = explicit junction rows ∪ rule matches, minus `unsubscribed|refused|bounced|trashed`, minus per-category opt-outs for the newsletter's category.

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

Seeded on install with a `general` category (name: "General"). Required on every newsletter. Recipients store opt-outs by slug (not ID) so renames don't break references; deleting a category prompts the admin to migrate newsletters/opt-outs to another category first.

### Newsletters (CPT + companion table)

- CPT `lrob_etk_newsletter` (post_title = subject, post_content = block source). Class: `NewsletterCPT`.
- Post meta with `_lrob_etk_nl_` prefix (constants on `NewsletterCPT`):
  - `_lrob_etk_nl_preview_text` — string, ~150 chars max.
  - `_lrob_etk_nl_from_name_override` — optional string, falls back to SMTP identity. **Currently no UI** (identity is single source of truth post-cards-refactor); meta + send-loop header-emit path stays for back-compat / future power-user reactivation.
  - `_lrob_etk_nl_reply_to_override` — optional string. Same note as `from_name_override`.
  - `_lrob_etk_nl_smtp_identity_id` — int, FK to SMTP identities (nullable = inherit module-wide default).
  - `_lrob_etk_nl_category_id` — int, FK to categories. Required at send time.
  - `_lrob_etk_nl_target_spec` — JSON. Shape: `{kind: 'list'|'all_users'|'all_subscribers'|'all', list_id?: int}`.
  - `_lrob_etk_nl_scheduled_at` — datetime UTC, nullable.
  - `_lrob_etk_nl_track_opens` — bool (default true).
  - `_lrob_etk_nl_track_clicks` — bool (default true).
  - `_lrob_etk_nl_log_all_sends` — bool (default false). Override the per-newsletter logging default.

Companion table `wp_lrob_etk_nl_newsletters` (hot runtime state — kept off postmeta; class `NewsletterRepository`):
```
post_id          bigint(20) unsigned NOT NULL
status           varchar(20) NOT NULL DEFAULT 'draft'
                   -- draft | scheduled | materializing | sending | paused | sent | failed | aborted
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

Composing: full Gutenberg editor (post.php) with curated allowed-blocks list (paragraph, heading, image, button, separator, columns, latest-posts, list, quote). Gutenberg is now **content-only**: every newsletter's settings + send actions live on the newsletter card (Newsletters admin view), not in metaboxes. Send-time pipeline runs `do_blocks()`, then `EmailLayout::apply()` (alignment-class inliner + skeleton wrap); future polish adds a CSS-to-inline-styles transformer for full email-client compatibility. If Classic Editor plugin is detected as active, surface an admin notice on the Newsletter homepage.

### Newsletter recipients (per-send recipient state)

Table `wp_lrob_etk_nl_newsletter_recipients`:
```
id             bigint(20) unsigned NOT NULL AUTO_INCREMENT
newsletter_id  bigint(20) unsigned NOT NULL
recipient_kind varchar(20) NOT NULL          -- 'user' | 'subscriber'
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
UNIQUE KEY newsletter_recipient (newsletter_id, recipient_kind, recipient_id)
KEY status (status)
KEY domain_pending (newsletter_id, domain, status)
```

Materialized when sending starts (or at schedule fire). Status transitions are the only writes during a send. Crash-safe: next AJAX/Cron tick picks up `pending` rows.

### Tracking events

Table `wp_lrob_etk_nl_tracking_events`:
```
id             bigint(20) unsigned NOT NULL AUTO_INCREMENT
newsletter_id  bigint(20) unsigned NOT NULL
recipient_kind varchar(20) NOT NULL          -- 'user' | 'subscriber'
recipient_id   bigint(20) unsigned NOT NULL
kind           varchar(20) NOT NULL          -- 'open' | 'click' | 'unsubscribe' | 'image_load'
url            varchar(500) NOT NULL DEFAULT ''
ip_anon        varchar(45) NOT NULL DEFAULT ''
user_agent     varchar(500) NOT NULL DEFAULT ''  -- empty unless newsletter opts in
occurred_at    datetime NOT NULL
PRIMARY KEY (id)
KEY newsletter_kind (newsletter_id, kind)
KEY occurred_at (occurred_at)
KEY recipient (recipient_kind, recipient_id)
```

Retention setting `lrob_etk_nl_tracking_retention_days` (default 365). Daily cron prunes.

Step 9 adds two sibling tables driving the link/image rewriter (see Tracking section): `wp_lrob_etk_nl_newsletter_links` (per-newsletter distinct `<a href>` URLs) and `wp_lrob_etk_nl_newsletter_assets` (per-newsletter distinct `<img src>` URLs + the fallback open-pixel slot).

## Scale & performance

Design target: newsletters up to **10 million recipients** must work. Most sites won't push that — but the architecture must not collapse there. Concretely:

- **Recipient materialization is chunked**. Don't `INSERT INTO ... SELECT` 10M rows at once. Loop in chunks of 10k via offset/cursor on the source recipient ids. Each chunk inserted in a single statement. Newsletter status stays `materializing` (intermediate status before `sending`) until done. Progress visible on the newsletter card.
- **Newsletter card metrics use the companion `wp_lrob_etk_nl_newsletters` table's pre-computed counters** — never `SELECT COUNT(*)` against the recipients table for header tiles. Per-domain queue health uses cached aggregates updated on each batch tick, not live queries.
- **Subscriber/list views are paginated** with cursor-based pagination (last-id-seen), not OFFSET — OFFSET breaks down past ~100k rows.
- **Lists rule evaluation for huge audiences** uses streaming. The rule compiler emits SQL with cursor pagination; results pipe into the recipient materializer chunk-by-chunk. No "load all recipient ids into PHP memory" anywhere.
- **Tracking events table** will dominate disk. Daily retention cron runs `DELETE ... LIMIT 5000` in a loop with `WHERE occurred_at < ?` until done — never one giant DELETE.
- **Export of 10M subscribers** as JSON would be a multi-gigabyte file. Export streams to disk in NDJSON-style chunks (one subscriber per line, then close-section markers) wrapped in the outer JSON envelope. Download served with chunked transfer-encoding. Same for import: streaming JSON parser, not `json_decode` of the whole file.
- **Index design**:
  - `wp_lrob_etk_nl_subscribers`: unique on `email`, indexes on `status`, `created_at`. Per-status counts via `COUNT(*) WHERE status=?` use the index.
  - `wp_lrob_etk_nl_newsletter_recipients`: critical composite index `(newsletter_id, domain, status)` (named `domain_pending`) for the send loop's "next batch grouped by domain" query.
  - `wp_lrob_etk_nl_tracking_events`: `(newsletter_id, kind)` (named `newsletter_kind`) for stats, `(occurred_at)` for retention pruning.
- **Send-loop micro-optimization**: each batch claims its rows via `UPDATE ... SET status='sending', tick_id=? WHERE status='pending' AND newsletter_id=? LIMIT N` then SELECTs the marked rows. Avoids races between AJAX and Cron paths. After processing, `UPDATE status='sent'|'failed'`.
- **Dynamic loading on heavy admin views**: the recipients-preview modal loads via AJAX, not in the initial page render. Same for the tracking-events timeline on a per-recipient drilldown.
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

Confirmation emails, reminder emails, and refuse-acknowledgments are **first-class templates** — composed with the same Gutenberg editor as newsletters, rendered through the same CSS-inliner pipeline.

- CPT: `lrob_etk_nl_etpl` (17 chars, fits the 20-char limit).
- Each template has a `purpose` post meta: `confirmation` | `reminder` | `refuse_ack` | `welcome` (welcome is post-MVP).
- Token substitution before CSS inlining: `{{confirm_url}}`, `{{refuse_url}}`, `{{prefs_url}}`, `{{first_name}}`, `{{name}}`, `{{email}}`, `{{site_name}}`. Save-time validation enforces that confirmation templates contain BOTH `{{confirm_url}}` and `{{refuse_url}}` (block the save with an admin notice otherwise).
- Module ships with one default template per purpose, auto-created on activation if missing. Default templates carry a `_lrob_etk_nl_template_is_default` meta = true so admins can identify them; they're editable, not locked.
- Subscribe forms pick which `confirmation` template applies (form-level toolbar dropdown). The reminder cron picks one `reminder` template (module-wide setting `lrob_etk_nl_reminder_template_id`).
- Templates are exposed in the Newsletter admin under a "Templates" section in the homepage hub.

Same Gutenberg allowed-blocks list as newsletters. Same sender-identity resolution (a template can override the default identity if the admin wants confirmation emails to come from a different mailbox).

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

Every newsletter picks a category at send time. Recipients can opt out of any category individually. Effective filter at send time:

```
status IN ('confirmed') AND
NOT trashed/refused/bounced/unsubscribed AND
newsletter's category slug NOT IN recipient's category_opt_outs AND
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

Two entry paths feed the **same** recipient state. State transitions are atomic via per-newsletter DB lock.

**Materialization step** (one-time per newsletter):
- Triggered by "Send now" click OR by the scheduled-time cron fire.
- Resolves the target_spec into the newsletter-recipients table (via `Materializer`):
  - `list` → list members minus exclusions.
  - `all_users` → WP users not opted-out (semantics are opt-out, not opt-in: missing user_meta counts as opted-in). Bounced excluded.
  - `all_subscribers` → subscribers with status=`confirmed`.
  - `all` → union of the above.
- Applies category opt-out filter.
- Populates `email_snapshot`, `name_snapshot`, `domain`.
- Sets newsletter companion status `draft|scheduled → sending`.
- Sets `total_recipients`.
- Fires `newsletter.started`.

**AJAX path (admin clicks Send now)**:
- Endpoint action `lrob_etk_nl_send_tick` (nonce action `lrob_etk_nl_newsletter_send`) loops: claim N pending recipients (where N = batch size, default 50), send each, update status, return progress.
- Newsletter card shows live progress bar (sent / failed / remaining) driven by `admin/js/newsletter-cards.js`.
- Throttle check before each send: count this newsletter's sends to `recipient.domain` in the last hour. If at limit, skip this batch for that domain (recipient stays `pending`). **Throttle is step-7b deferred work** — not yet enforced.
- Closing the tab is safe: pending recipients remain. The Cron path picks up where AJAX left off.

**Cron path (always-on safety net)**:
- WP-Cron event runs every minute (hook `lrob_etk_nl_send_cron_tick`, class `SendCron`).
- Two passes: (1) scheduled newsletters whose `_scheduled_at` has elapsed → materialize + first tick; (2) sending newsletters with stale `last_tick_at` (>2 min) → continue one batch.
- Same throttle logic as AJAX (once 7b lands).

**Completion**:
- When a newsletter has zero `pending` recipients left, companion status → `sent`, `completed_at` set, fires `newsletter.completed`.

**Test send (newsletter card)**:
- Modal triggered by the "Test" button on each newsletter card (replaces the deferred Gutenberg-popover approach).
- Three targets:
  1. Single ad-hoc address (admin types it in).
  2. A "test list" — option `lrob_etk_nl_test_list_id` points at one regular list; when set, the modal shows "Send to test list (N members)". Maintained manually by the admin (no special flag on the list).
  3. The admin's own email (one-click button).
- Test sends DO substitute personalization tokens (so admin sees the real email shape) but mark recipients with `X-Lrob-Etk-Newsletter-Test: 1` header and DO NOT write to `wp_lrob_etk_nl_newsletter_recipients` or update counters. Tracking links work but tracking events from test sends are flagged so they're excluded from real stats. Fires `newsletter.test_sent`.
- Test sends never modify subscribers' bounce_count or status.

**Per-domain throttle** (step-7b deferred):
- Option `lrob_etk_nl_domain_throttle` is a map: `{ "laposte.net": 30, "free.fr": 30, "orange.fr": 30, "sfr.fr": 30, "wanadoo.fr": 30, "gmail.com": 200, "outlook.com": 200, "yahoo.com": 200, "*": 100 }` (sends per hour).
- Conservative defaults for known-strict French ISPs. Wildcard `*` for everything else.
- Send rate display on the newsletter card shows per-domain queue state during a send.

**Pause / Resume / Abort** (shipped in step 7b):
- AJAX actions: `lrob_etk_nl_send_pause` / `lrob_etk_nl_send_resume` / `lrob_etk_nl_send_abort`. All confirmed via the shared `etkConfirm` modal.
- Pause: status `sending → paused`. Both AJAX and Cron skip the newsletter. Pending rows stay. Fires `newsletter.paused`.
- Resume: status `paused → sending`. Cron picks back up next tick; admin can hit "Send now" to resume via AJAX too. Fires `newsletter.resumed`.
- Abort: status `sending|paused → aborted`. Remaining `pending` rows flip to `skipped`. No undo. Fires `newsletter.aborted`.

**Transport**: `wp_mail()` per recipient invoked from `SendLoop`. SMTP identity is resolved inside `SendLoop` from `_lrob_etk_nl_smtp_identity_id` meta (no filter chain dance — the loop applies identity directly). Header `X-Lrob-Etk-Newsletter-ID: <post_id>` added to every send.

## Logging integration

Shipped in step 8. Logger writes a row at `phpmailer_init` (current flow) and decides at `wp_mail_succeeded` whether to keep or prune; failures always update to `failed` so the admin can investigate.

- **Headers parsed**: `X-Lrob-Etk-Newsletter-ID` (newsletter post id) and `X-Lrob-Etk-Newsletter-Recipient-ID` (the per-send `newsletter_recipients.id`) — both populated by `SendLoop`. Test sends carry `X-Lrob-Etk-Newsletter-Test: 1` and get `source = 'newsletter_test'`; regular sends get `source = 'newsletter'`.
- **Default rule on success**: if the row has a `newsletter_id` and the per-newsletter `_lrob_etk_nl_log_all_sends` meta is NOT `'1'`, the row is **deleted**. Newsletter success state lives in `newsletter_recipients` already; duplicating millions of rows in the global logs table is a waste.
- **Failures**: always `update_status('failed', error)` — never pruned. The error_message from `wp_mail_failed` is preserved.
- **Per-newsletter "Log every send" override**: card's Tracking section has a `Log every send` checkbox writing `_lrob_etk_nl_log_all_sends`. When `'1'`, success rows are kept like any other send (full audit trail for that one newsletter).
- **Logs UI filter**: `LogsPage` has a Newsletter dropdown with three modes — `exclude` (default, hides newsletter rows) / `include` (show all) / `only` (newsletter only). Repository's `build_where` honours `newsletter_mode` + the explicit `newsletter_id` filter (cross-link landing page sets both).
- **Cross-link from recipients drawer**: `LogRepository::log_ids_for_newsletter_recipients()` batches the lookup from row id → log id; `SendAjaxController::handle_recipients_preview` attaches `log_url` to each sample row; the JS renders "View in Logs →" for any failed row that has a match. Since the default rule prunes successes, in practice the link only appears on failures — which is exactly when you'd want it.
- **Cross-module soft dependency**: the Newsletter side late-binds the Logging classes via `class_exists` (in `SendAjaxController::attach_log_urls`). Disabling the Logging module doesn't break newsletter sends; the recipients drawer just stops showing the log links.
- **Reserved for later**: one-row-per-newsletter summary log on completion (`lrob_etk_nl_log_newsletter_summary` option, not yet implemented — would emit a single rollup row when the send completes, useful for sites that want a Logging-page-level audit without the per-recipient noise).

## Unsubscribe (RFC 8058)

Every outbound email includes:
```
List-Unsubscribe: <mailto:unsub-<token>@<site_domain>>, <https://<site>/?lrob-etk-nl-unsub=<token>>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

Where `<token>` = the recipient's `prefs_token` (the same opaque token used by the prefs page — one-click unsub is a POST against the prefs URL). Newsletter-scoped HMAC tokens are reserved for future per-send unsubscribe attribution but not currently used here.

**One-click action (RFC 8058)**:
- Hits the HTTPS endpoint with `POST { "List-Unsubscribe": "One-Click" }`.
- Effect: opts the recipient out of the **newsletter's category** (not the whole newsletter system). For subscribers, also untrashes nothing (their status is unchanged); for WP users, sets the category opt-out.
- Lands on a confirmation page with link to the full prefs page for more control.
- Fires `newsletter.tracking.unsubscribed` (records on the tracking_events table with kind=`unsubscribe`).

**Mailto unsubscribe**: a `lrob_etk_nl_mailto_unsub` inbound-email mechanism is **post-MVP** (needs IMAP infra). v0.3.0 ships the HTTPS variant only; some mail clients fall back to the URL when mailto isn't supported.

## Tracking

Per-newsletter toggles `_lrob_etk_nl_track_opens` and `_lrob_etk_nl_track_clicks` (default on). Three rewriter passes at send time + a fallback open pixel + per-recipient/per-subscriber lifetime counters powering the **cold-subscribers** surface.

### Image rewriter (primary open signal)

The newsletter's own images carry the open signal — most newsletters have a logo or hero, so we route those loads through our domain rather than appending a dedicated tracking pixel. The dedicated 1×1 GIF is kept as a fallback for image-less emails.

- Send-time pass over rendered HTML rewrites every `<img src="…">`. Skip `data:` URLs, prefs-token URLs, and `data-lrob-etk-no-track` opt-out attributes.
- Each rewritten URL: `/wp-json/lrob-etk/v1/nl/track/img/<token>?n=<newsletter_id>&r=<kind>:<id>&a=<asset_id>` where `token = base64url(hmac_sha256(key, "img|<newsletter_id>|<recipient_kind>|<recipient_id>|<asset_id>"))`.
- `asset_id` is a deterministic small int — the Nth distinct image URL in the newsletter, stable across recipients. Lets us aggregate per-image engagement without storing the URL on every event row.
- Side table `wp_lrob_etk_nl_newsletter_assets`: `id, newsletter_id, asset_id, url, purpose enum('open_pixel','content'), UNIQUE (newsletter_id, asset_id)`.
- Endpoint verifies HMAC constant-time; on match, inserts a `tracking_events` row (`kind='open'` if no prior open for this recipient, else `kind='image_load'`); responds `302 Location: <original URL>` with `Cache-Control: no-store, max-age=0`. Verification failure → still serve the 302 silently, but don't log (image clients prefetch).

### Open pixel (fallback only)

If the rendered body has **zero** `<img>` tags after the image-rewriter pass, append a 1×1 transparent GIF before `</body>`. Same endpoint family, with `purpose='open_pixel'` and `asset_id=0` reserved on `newsletter_assets`.

Why a fallback only: the dedicated pixel has no advantage over media-URL rewriting (same image-blocking, same proxy behaviour, same caching — because the per-recipient token makes every URL unique). Rewriting the real images is less spy-y and gives free per-image engagement data.

### Link rewriter (clicks)

- Send-time pass over rendered HTML rewrites every `<a href="…">`. Skip `mailto:` / `tel:` / `sms:` / `javascript:`, `#anchor`-only hrefs, prefs/unsub-token URLs, and `data-lrob-etk-no-track` opt-out.
- Each rewritten URL: `/wp-json/lrob-etk/v1/nl/track/click/<token>?n=<newsletter_id>&r=<kind>:<id>&l=<link_id>` where `token = base64url(hmac_sha256(key, "click|<newsletter_id>|<recipient_kind>|<recipient_id>|<link_id>"))`.
- `link_id` mirrors `asset_id`: Nth distinct URL, scoped per newsletter, stored in `wp_lrob_etk_nl_newsletter_links` (`id, newsletter_id, link_id, url, label_snippet, UNIQUE (newsletter_id, link_id)`). Powers the per-link clickthrough breakdown.
- Endpoint verifies HMAC. Invalid → 400 (don't become an open redirect). Inserts `tracking_events` `kind='click'`; if no prior `open` for this recipient, also inserts an `open` event (clicks imply opens — image-blocked clients give us this signal). Responds `302 Location: <original URL>` with `Cache-Control: no-store`.

### Per-recipient counters (live on `newsletter_recipients`)

Already in schema: `opens`, `clicks`, `last_open_at`, `last_click_at`. Bumped by the REST handlers. Surfaced on the per-newsletter recipients drawer ("Sent 2026-03-15 09:02 / Opened 3× / Clicked 1× on 2 links").

### Per-subscriber lifetime stats (new, for cold-detection)

Denormalized counters on `wp_lrob_etk_nl_subscribers` (and matching user_meta keys for WP users — `lrob_etk_nl_last_engagement_at`, `lrob_etk_nl_last_sent_at`, `lrob_etk_nl_sends_since_engagement`, `lrob_etk_nl_total_sent`, `lrob_etk_nl_total_opened`, `lrob_etk_nl_total_clicked`):

```
last_engagement_at      datetime NULL    -- max(open, click) across all newsletters
last_sent_at            datetime NULL    -- last materialization
sends_since_engagement  smallint(5)      -- counter, resets to 0 on engagement
total_sent              int(10)
total_opened            int(10)          -- unique opens, not raw
total_clicked           int(10)          -- unique clicks
```

`Materializer` bumps `last_sent_at` + `total_sent` + `sends_since_engagement` per recipient row when materializing. Tracking endpoints reset `sends_since_engagement` to 0 and bump the matching `total_*` + `last_engagement_at`.

**Apple Mail Privacy Protection caveat**: Apple loads all images server-side (~60% of consumer inboxes), so opens are inflated to ~100% for those recipients. To keep cold-detection honest, `sends_since_engagement` resets **on click only by default**. Admin setting `lrob_etk_nl_engagement_counts_opens` (default `false`) lets opens count too if the admin trusts their audience mix.

### Cold-subscribers surface

In `SubscribersPage` admin, a new sub-tab **"Cold"** filters by `sends_since_engagement >= N` (admin-configurable via `lrob_etk_nl_cold_threshold`, default 5).

- Bulk action on selected rows: unsubscribe.
- Optional auto-cleanup setting `lrob_etk_nl_auto_cleanup_threshold` (default 0 = disabled). When > 0, the daily cron auto-unsubscribes everyone crossing the line. Optionally sends a "we noticed you're not reading — opt back in?" email first using a new system template `cleanup_warning` (post-MVP — first ship is manual cleanup only).

### Per-list / per-category rollups

No new storage. List/Category admin views get aggregate counters at view time:
- List view: "1,250 members / 312 engaged in last 90 days (24.9%)" — `COUNT(*) WHERE list_id=? AND last_engagement_at >= NOW() - INTERVAL 90 DAY`. Cached for 5 min in a transient because this can get expensive on big lists.

### IP / UA / Retention

- **IP anonymization**: IPv4 → /24, IPv6 → /48 before storage in `tracking_events.ip_anon`. No cookies, no fingerprinting; GDPR-defensible without a consent prompt.
- **User-agent**: not stored by default. Per-newsletter opt-in via `_lrob_etk_nl_track_user_agent` post meta.
- Retention: `lrob_etk_nl_tracking_retention_days` (default 365). Daily chunked-DELETE cron prunes.
- Companion-row counters (`opens_count`, `clicks_count`, `opens_unique`, `clicks_unique` on `wp_lrob_etk_nl_newsletters`) are kept forever — events table just powers detail views, summary numbers are denormalized.

### Test-send exclusion

`X-Lrob-Etk-Newsletter-Test: 1` sends bypass the image rewriter, link rewriter, and open-pixel injection. Test emails carry no tracking links — admins clicking around their own test sends would otherwise poison real newsletter stats.

### Card stats display

The Recipients box on each newsletter card surfaces three lines once tracking lands:
- `2,341 sent`
- `1,127 opens (48%)` — unique opens, labelled "incl. proxies" tooltip since Apple MPP inflates this
- `94 clicks (4%, 19 unique on 7 links)` — total + unique-click rate + "Show breakdown →" link to per-link rollup

Live during a send: the existing tick endpoint return shape grows opens/clicks fields.

## Bounces

- Passive only in v0.3.0. When `wp_mail()` returns false OR PHPMailer surfaces a 5xx hard failure (via PHPMailer's `\PHPMailer\PHPMailer\Exception`), the recipient row's `bounce_count` increments.
- After N hard bounces (default `lrob_etk_nl_hard_bounce_threshold=3`), status flips to `bounced` (for both subscribers and WP users via user_meta).
- Soft 4xx bounces tracked separately (column on the newsletter-recipients row: `failure_code` distinguishes them). Soft bounces never auto-unsubscribe.
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
- Newsletters: best-effort template import only. Subject + body HTML become a draft block. Their template-language tags converted to `<!-- newsletter:template-tag -->` placeholder blocks for admin review. Send history NOT imported.

### Confirmation mode picker (applies to every import path)

- **Mark as already confirmed** (admin attests prior consent). Status=`confirmed` directly, no email sent.
- **Send confirmation email** — status=`pending`, double-opt-in dispatched.
- **Send confirmation email + reminder sequence** — same plus reminder cron enabled for these rows.

### Conflict resolution (full-restore JSON)

Per-section: skip-duplicates (default) / merge / replace. Cross-references (list memberships, newsletter target IDs) remapped automatically.

### Export

- "Export everything" button on Newsletter Settings.
- Single `.json` file containing: categories, lists (with rule_json), list memberships, subscribers (full row state including trash/refused/bounced), newsletters (post fields + meta + companion row), settings, per-domain throttle map.
- **Not** exported: newsletter-recipients (too big, per-send state), tracking events.
- Versioned format: `{ "schema": 1, "exported_at": ..., "module_version": "0.3.0", "data": { ... } }`.
- Tokens (prefs_token, etc.) are exported — restoring on a different site lets recipients re-use existing links. Admin can choose to regenerate all tokens on import.

## Captcha integration

- Existing `Routing::CONTEXT_NEWSLETTER = 'newsletter_subscribe'` (already declared in `src/Modules/Captcha/Routing.php`).
- Subscribe form calls `CaptchaService::resolve(['context' => Routing::CONTEXT_NEWSLETTER])` at render and verify time.
- Default routing: inherit from `Routing::KEY_DEFAULT` (which itself defaults to `homemade:math`).
- The Captcha settings page (already shipped) shows the Newsletter context — no work needed there.

## Events (vocabulary frozen at v0.3.0)

Event names use the `newsletter.<verb>` shape for newsletter-level lifecycle (no second component); subscriber-level events get `newsletter.subscriber.<verb>`; per-recipient send events get `newsletter.recipient.<verb>`; tracking events get `newsletter.tracking.<verb>`. **Shipped** events are emitted today; **planned** events are reserved names that will be emitted when their step lands.

**Shipped (verified against code as of 2026-05):**

```
newsletter.subscriber.added           — pending subscriber created (via form, import, or admin)
newsletter.subscriber.confirmed       — double-opt-in succeeded
newsletter.subscriber.refused         — clicked Refuse in confirmation email
newsletter.subscriber.unsubscribed    — soft unsubscribe (was confirmed, opted out)
newsletter.subscriber.trashed         — moved to trash (admin or user action)
newsletter.subscriber.promoted        — subscriber row absorbed into a new WP user account
newsletter.subscriber.resubscribed    — was unsubscribed/refused/bounced/trashed, signed up again
newsletter.subscriber.reminder_sent   — pending-followup reminder dispatched

newsletter.started                    — materialization complete, sending begins
newsletter.paused                     — admin paused mid-send
newsletter.resumed                    — admin resumed a paused send
newsletter.aborted                    — admin aborted mid-send
newsletter.completed                  — all recipients reached terminal state
newsletter.test_sent                  — admin used the test-send modal

newsletter.recipient.sent
newsletter.recipient.failed

newsletter.tracking.unsubscribed      — one-click List-Unsubscribe
```

**Planned (names reserved; emit when the matching step ships):**

```
newsletter.subscriber.restored        — restored from trash (step 5 polish)
newsletter.subscriber.purged          — permanently deleted from trash (step 5 polish)
newsletter.subscriber.bounced         — auto-flipped to bounced status (step 10)

newsletter.created                    — draft saved for the first time (optional)
newsletter.scheduled                  — _scheduled_at set, companion status=scheduled (optional)
newsletter.failed                     — fatal error (e.g. SMTP identity missing) before step 9

newsletter.recipient.skipped          — aborted newsletters convert pending → skipped (step 7b polish)

newsletter.tracking.opened            — step 9 (image rewriter + open-pixel fallback)
newsletter.tracking.clicked           — step 9 (link rewriter)
newsletter.tracking.image_loaded      — step 9 (non-first image load — per-asset breakdown)

newsletter.import.started             — step 11
newsletter.import.completed           — step 11
newsletter.import.failed              — step 11
newsletter.export.generated           — step 12
```

Renaming or removing a **shipped** event is a breaking change (per CLAUDE.md's event API stability rules). Reserved names can still be re-shaped — they're not on the wire yet.

## Admin UI

Single-page hub at `?page=lrob-etk-nl` with `&view=` dispatch. Constants on `HomePage`:

- `&view=` (no view) — Dashboard. Tiles: total recipients (split: WP users / subscribers), recent newsletter with summary, send-queue status, last-30-days opens/clicks, per-domain throttle health, top categories.
- `&view=newsletters` (`VIEW_NEWSLETTERS`) — Newsletter cards grid. Each card hosts subject, settings (category, audience, sender identity, schedule, tracking), action buttons (Content / Test / Preview / Send-or-Schedule), status badge, recipients/stats box, footer with Duplicate + Delete. Sub-tabs (in-prep / sent) + archive sub-tab still on the backlog.
- `&view=subscribers` (`VIEW_SUBSCRIBERS`) — Subscribers list. Sub-tabs: All / Pending / Confirmed / Unsubscribed / Refused / Bounced / Trash. Step 9 adds a **Cold** sub-tab.
- `&view=lists` (`VIEW_LISTS`) — Lists list + new-list + edit (rule editor).
- `&view=categories` (`VIEW_CATEGORIES`) — Categories list + new-category.
- `&view=forms` (`VIEW_FORMS`) — Newsletter forms list. Empty state invites first-form creation (Contact-Form-style).
- `&view=onboarding` (`VIEW_ONBOARDING`) — System email templates (confirmation, reminder, refuse-ack). Edit-in-place. Named "Onboarding" in the UI rather than "Templates".
- `&view=settings` (`VIEW_SETTINGS`) — Default SMTP identity, default category, default list per role, reminder cron settings (max + interval + which template), per-domain throttle map, bounce threshold, tracking defaults, trash auto-purge days, test-list ID, WC My Account integration toggle, export button.
- `&view=import` (`VIEW_IMPORT`) — Unified import wizard.

UI patterns inherit from CLAUDE.md's UI conventions: card grids, auto-save edit cards, anchored popovers, custom comboboxes, custom data tables. No new patterns introduced; reuse `lrob-etk-` primitives.

## Out of scope for v0.3.0 (first Newsletter ship)

- **WooCommerce purchase-data dimensions** in list rules (`wc.has_purchased_product`, `wc.total_spent_gte`, `wc.last_order_after`). Lands in a follow-up minor.
- **Active IMAP bounce parsing**. Tied to the deferred IMAP "save to sent" work.
- **Mailto-based List-Unsubscribe** processing. HTTPS variant only.
- **A/B newsletter testing**.
- **Custom email-builder UI** (we use Gutenberg). Note: in-house email content editor extending the form-builder is in the CLAUDE.md backlog for post-0.3.0; would A/B with Gutenberg, never replace it for system templates.
- **Send-statistics importer** from the Newsletter plugin (only templates + subscribers + lists imported).
- **WP-user trash** (only category-level opt-out for WP users; full removal = delete the WP account).
- **Cold-subscriber auto-cleanup warning email** (`cleanup_warning` template) — first ship of step 9 is manual-cleanup only; auto-cleanup with warning email is a step-9 polish.

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
6. ✅ **Newsletter CPT + composer** (originally "Campaign CPT"). `NewsletterCPT` registers `lrob_etk_newsletter` (19 chars, fits the 20-char limit), Gutenberg-enabled, same constrained block subset as `TemplateCPT`. Plural-caps mapping pattern. `register_meta` for: `_lrob_etk_nl_preview_text`, `_from_name_override`, `_reply_to_override`, `_smtp_identity_id`, `_category_id`, `_target_spec` (JSON: `{kind, list_id?}`), `_scheduled_at` (UTC), `_track_opens`, `_track_clicks`, `_log_all_sends`. **Originally shipped as Gutenberg metaboxes (`CampaignMetaboxes`, four boxes: Sender & preview / Audience / Schedule / Tracking) — superseded by the cards refactor below; metaboxes removed and replaced by `NewsletterLifecycle` (companion-row plumbing only).** SMTP identity picker pulls from the SMTP module's `IdentityRepository` when available (gracefully empty when SMTP is disabled). `NewsletterRepository` for the companion table: `ensure_row` (INSERT IGNORE on save), `update_status`, `delete_for_post` (cleans companion + newsletter_recipients on post delete), `list_all` (JOIN with wp_posts for the admin view, sorted newest-first by `post_date_gmt DESC`), `recipients_snapshot` (frozen recipient breakdown for sent newsletters). `NewslettersPage` admin view ships at `?page=lrob-etk-nl&view=newsletters` (replaces the placeholder). Companion `status` flips between draft ↔ scheduled based on whether `_scheduled_at` is set, but only while the newsletter is still pre-send — the send pipeline (step 7) drives it from there.
7. ✅ **Minimum viable send pipeline.** `src/Modules/Newsletter/Send/` package: `NewsletterRenderer` (do_blocks + per-recipient token substitute: `{{email}}`, `{{name}}`, `{{first_name}}`, `{{prefs_url}}`, `{{unsub_url}}`, `{{site_name}}`, `{{site_url}}`); `Materializer` (resolves target_spec → `newsletter_recipients` rows in chunks of 50, applies category opt-out filter, populates email/name snapshots + domain, INSERT IGNORE for idempotency, flips status to `sending` + fires `newsletter.started`; opt-out semantics for WP users — missing meta counts as opted-in); `SendLoop` (claim N pending → wp_mail each → flip row status → bump newsletter counters; completion detection flips `sent` + dispatches `newsletter.completed`; List-Unsubscribe + List-Unsubscribe-Post headers + `X-Lrob-Etk-Newsletter-ID` on every send); `SendAjaxController` with endpoints `lrob_etk_nl_send_tick`, `lrob_etk_nl_test_send`, `lrob_etk_nl_preview`, `lrob_etk_nl_recipients_preview` (nonce action `lrob_etk_nl_newsletter_send`). Test sends don't write `newsletter_recipients` or touch counters; flagged with `X-Lrob-Etk-Newsletter-Test: 1`.

### ✅ Step 7b core (cron safety net + pause/resume/abort + scheduled-send cron handoff)

- **WP-Cron safety net** (`SendCron`, every-minute tick) — picks up `sending` newsletters whose `last_tick_at` is stale (>2 min) and processes one batch per newsletter per tick. Also: two-pass scan picks up scheduled newsletters whose `_scheduled_at` has elapsed → materialize + first tick (closes the "schedule and forget" loop).
- **Pause / resume / abort** — AJAX actions `lrob_etk_nl_send_{pause,resume,abort}`, flip companion status; both AJAX and Cron honour `paused` / `aborted`. Confirmed via shared `etkConfirm` modal.

### ✅ Newsletter cards refactor + Campaign → Newsletter rename

Major UX + vocabulary pass between step 7 and step 8:

- **Cards refactor**: every newsletter's settings + send actions move out of Gutenberg metaboxes into a single card on the Newsletters admin view. `NewsletterMetaboxes` deleted; `NewsletterLifecycle` keeps the companion-row plumbing only. Each card hosts: Subject input (writes to `post_title`), status badge, settings fieldset (Category, Audience + Recipients, Sender identity, Schedule, Tracking), Content/Test/Preview/Send buttons, recipients + stats box, footer with Duplicate + Delete. Send button state-aware (Send-now ⇄ Schedule). All confirmations via `.lrob-etk-modal` (no `window.confirm`). Default schedule = tomorrow 10:00 local. Recipients box shows frozen snapshot for sent newsletters; falls back to dry-run preview for drafts. Show-list is an `<a>` (not a `<button>`) so the locked-fieldset stays clickable.
- **Combobox enforcement**: every settings field on a newsletter card goes through `Admin\Combobox::render_fixed_select` (Identity / Category / List pickers) or `render_free_text` (free-text-with-suggestions). No raw `<select>` / `<input>`. New CLAUDE.md rule under "UI patterns" calls this out for all future modules.
- **Vocabulary rename**: Campaign → Newsletter at the persistence layer + class names + event names. Tables `wp_lrob_etk_nl_campaigns` → `wp_lrob_etk_nl_newsletters`, `wp_lrob_etk_nl_campaign_recipients` → `wp_lrob_etk_nl_newsletter_recipients`, every `campaign_id` column → `newsletter_id`, indexes renamed. CPT slug `lrob_etk_nl_campaign` → `lrob_etk_newsletter`. Class renames: `Campaign*` → `Newsletter*`. Event names: `newsletter.campaign.<verb>` → `newsletter.<verb>` (and `recipient.<verb>` / `tracking.<verb>` for sub-namespaces). Schema migration v6 in `Module::migrate_campaign_to_newsletter()` runs information-schema-guarded `RENAME TABLE` + `ALTER … RENAME COLUMN` before `install()` so dbDelta sees the new shape only.

### 🚧 Deferred to step 7b polish

- **Per-domain throttle** — `lrob_etk_nl_domain_throttle` option (map of domain → hourly cap); SendLoop's claim query filters out rows whose recipient.domain hit the cap in the last hour.
- **CSS inliner** — converts `<style>` rules to inline `style=""` attrs for email-client compatibility.

### 🚧 Next

### Deferred (originally in 4c)

- **WC My Account integration** — admin-toggleable section under WooCommerce's My Account pages rendering the same `PrefsRenderer::render_inputs`. Non-essential; the Gutenberg block + shortcode already cover the "public prefs surface" use case for WC sites willing to drop the block onto their account page.

### Later

8. ✅ **Logging integration.** SendLoop emits `X-Lrob-Etk-Newsletter-ID` + `X-Lrob-Etk-Newsletter-Recipient-ID` per send (the recipient header carries `newsletter_recipients.id`, the per-send row id). `Logger` parses both at `phpmailer_init`, populates `logs.newsletter_id` + `logs.recipient_id`, and tags `source = 'newsletter'` (or `'newsletter_test'` when `X-Lrob-Etk-Newsletter-Test: 1` is present). Default rule: on `wp_mail_succeeded`, if the row has a `newsletter_id` and the per-newsletter `_lrob_etk_nl_log_all_sends` meta is NOT `'1'`, delete the row instead of flipping to `sent` — newsletter sends already live in `newsletter_recipients`, no need to duplicate. Failures always update normally (so the admin can investigate). `LogRepository::build_where` gained a `newsletter_mode` filter — `'exclude'` (default, hides newsletter rows) / `'include'` (show all) / `'only'` (newsletter only) — surfaced as a dropdown on `LogsPage`. New `LogRepository::log_ids_for_newsletter_recipients()` batches the join from `newsletter_recipients.id` → log row id; `SendAjaxController::handle_recipients_preview` calls it and attaches `log_url` to each sample row; `newsletter-cards.js` renders "View in Logs →" links in the recipients drawer for any row with a matching log entry. Logging stays a soft dependency — `SendAjaxController::attach_log_urls` late-binds via `class_exists` so newsletter sends still work when Logging is disabled. The card's Tracking section already had the "Log every send" checkbox (per-newsletter override), now functional.
9. **Tracking** (re-locked design — see Tracking section): image-rewriter (primary open signal via media URLs) + open-pixel fallback for image-less emails + link rewriter for clicks. Sibling tables `nl_newsletter_assets` + `nl_newsletter_links`. Per-recipient counters on `newsletter_recipients` (already in schema). **Per-subscriber lifetime stats** on `subscribers` + matching user_meta: `last_engagement_at`, `last_sent_at`, `sends_since_engagement`, `total_sent/opened/clicked`. **Cold-subscribers sub-tab** in Subscribers admin (`sends_since_engagement >= N`) + optional auto-cleanup setting. Engagement resets on **click** by default (Apple MPP inflates opens); admin can opt in to opens-also via `lrob_etk_nl_engagement_counts_opens`. Per-list / per-category rollup counters via transient-cached aggregate queries. Retention cron prunes old events.
10. **Bounce handling**: failure detection, bounce_count tracking, auto-bounce threshold.
11. **Import wizard**: CSV importer with column mapping, JSON full-restore, Newsletter-plugin importer, confirmation-mode picker.
12. **Export** (streaming).
13. **Dashboard polish**: tiles, queue health, recent-newsletter details.
14. **CSS inliner**: bundled with step 7b (the send pipeline polish slice).
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
