# Admin UI reference — design system

> Loaded on demand, **not** part of the always-in-context `CLAUDE.md`. Pointed to from `CLAUDE.md` → "UI patterns". Covers the admin design tokens, shared PHP renderers, shared JS helpers, and the CSS primitives catalog. When you change any of these, update this file in the same change.

The mandates (wording, no raw `<select>`/`<datalist>`, no hardcoded chrome values, modal-open helper, autosave helper, `[hidden]` rule) stay in `CLAUDE.md` — they're enforced rules, not reference.

## Design tokens (admin-base.css)

Single source for every color, radius, shadow, spacing, transition, **and text size**. **Hardcoding chrome values is forbidden** — `font-size: 12px`, `gap: 6px`, `padding: 8px`, `color: #eee`, `border-radius: 4px` all belong to a token. If a needed value isn't tokenized yet, **add a token to admin-base.css first**, then reference it. Adapting one (dark mode, compact density, roundness preset) then ripples everywhere. The same rule applies to **reuse**: before forking a new class, grep for similar existing primitives — extend rather than duplicate (see memory `feedback_css_tokens_no_hardcode`). **Tints** (glows, hover/selected backgrounds, focus rings) are NOT new tokens — derive them at the use site with `color-mix(in srgb, var(--etk-accent) N%, transparent)` (or `--etk-danger`, or a surface var) so they track the palette / any future theme automatically; the only literal left is the `%`. The frontend `assets/css/contact-form.css` is a **separate** token system (`--lrob-etk-cf-*`, user-themeable per form) — never cross the two.

| Token family | Values |
|---|---|
| Palette | `--etk-fg`, `--etk-muted`, `--etk-soft`, `--etk-line`, `--etk-line-strong`, `--etk-accent`, `--etk-accent-hover`, `--etk-accent-bg`, `--etk-success`, `--etk-success-bg`, `--etk-warning`, `--etk-warning-bg`, `--etk-danger`, `--etk-danger-bg` |
| Text-on-tint | `--etk-text-{success,danger,warning,accent}` (dark text on the matching `*-bg` tints). `--etk-on-accent` = light text/icon sitting ON an accent or semantic fill (primary buttons, solid badges). |
| Surfaces | `--etk-card-bg` (warm off-white), `--etk-input-bg` (pure white) |
| Veils / scrim | `--etk-veil-{1,2,3}` (translucent black — layered over a surface to recede / gray it out: sent/trashed rows, disabled cards, hover tints), `--etk-backdrop` (modal scrim) |
| Radii | `--etk-radius-{sm,md,lg,xl,pill}` (4 / 6 / 8 / 12 / 999px). Legacy alias `--etk-radius` = md. |
| Shadows | `--etk-shadow-{sm,md,lg,modal,menu}` |
| Spacing | `--etk-space-{1..9}` (4 / 8 / 12 / 16 / 24 / 32 / 40 / 48 / 60px). `1..6` = the core grid; `7..9` = large empty-state / hero block padding. Snap to grid — don't add a half-step token for 6/10/14px; round **down** (6→4, 10→8, 14→12, 18/20→16, 28→24). Sub-4px micro-nudges (1/2/3px), negative pull-ins, and icon-clearance offsets (e.g. `padding-right:22/26px`) stay literal by design. Density-tunable. |
| Text sizes | `--etk-text-{xxs,xs,lg,xl,2xl}` = 9 / 11 / 15 / 20 / 28px. **The base body size is 13px (the WP-admin default, set on `.lrob-etk`) and is NEVER declared — normal-flow text inherits it, so DON'T write `font-size: 13px` / `var(--etk-input-font-size)` on plain text.** Only deviations get a token: `xs`=secondary/muted (captions, slugs, helper, table heads, badges, pills); `xxs`=micro glyphs (sort arrows, badge numerals); `lg`=titles; `xl`=section/CTA headings + big counts; `2xl`=hero stat numbers. Display sizes are tokenized too (no bare px in admin chrome). `em` is banned (compounding). `sm`(12)/`md`(13) are retired — defined, 0 refs; don't reintroduce. Density-tunable. |
| Inputs | `--etk-input-height` (30px), `--etk-input-height-sm` (28px), `--etk-input-font-size` (13px). Form controls (input/select/textarea/button) + headings normalized down do NOT inherit `font-size` — they carry an explicit size (this var for controls; a deliberate value on headings). Keep those; the "don't declare 13" rule is for *normal-flow text*. |
| Icons | `--etk-icon-size` (16px), `--etk-icon-size-sm` (14px), `--etk-icon-size-lg` (18px — accent/warning inline icons), `--etk-icon-size-xl` (40px — big decorative empty-state/onboarding icons). `.dashicons` set `font-size` = `width` = `height` to the same icon token. |
| Motion | `--etk-transition` (0.15s ease), `--etk-transition-slow` (0.30s ease) |

**Cleanup status:** **colour** + **font-size** + **spacing/radius** passes DONE — every hardcoded colour → token / `color-mix`; all 9 admin sheets on the text-size scale above (base 13 inherited, secondary→`xs`, micro→`xxs`, titles→`lg`/`xl`, hero→`2xl`, icons→the `--etk-icon-size-*` family); `em` eliminated; display sizes centralized; **574 spacing literals → `--etk-space-*`** (on-grid 1:1, off-grid rounded down per the Spacing row; scale extended to `7..9` for empty-state padding) + the leftover literal `border-radius` → `--etk-radius-md` (`50%` circles + the intentional micro/negative/icon-clearance literals stay). Zero off-grid spacing literals remain. **Remaining in v0.5.x step 0:** the **structural dedup** pass (merge duplicate classes; promote `-cf-`/`-nl-`/`-smtp-`/`-logs-` sub-prefixes → globals; drop dead selectors). Incremental + visual QA (regression risk). Frontend `assets/css/contact-form.css` keeps its own `--lrob-etk-cf-*` system (never cross the two).

## CSS file map

Files are enqueued in this cascade order (each depends on the previous):

| File | Owns |
|---|---|
| `admin-base.css` | CSS variables (`--etk-*`), normalization, page chrome (h1/h2), WP form-table tweaks, generic footer. Scoped under `.lrob-etk`. |
| `admin-components.css` | Shared primitives reused across module pages: CTA card, status badges, empty state, tooltips, toggle switch, modal, segmented control, select, combobox + menu, anchored popover, form errors, flash messages, page header, card primitives, filter bar, data table, bulk toolbar, pagination, detail strip, cleanup row, section tabs, empty-state card, banner. |
| `admin-dashboard.css` | Stat cards, SVG activity chart, module card test button, failure banner, modules grid. Loaded plugin-wide but only used on the dashboard. |
| `admin-smtp.css` | SMTP page: routing grid, inline connection-test button, encryption + port row, From section warnings, identity card grid, container queries. |
| `admin-logging.css` | Log-entry detail page only (metadata grid, iframe body, error banner, attachment status). All generic patterns (filter-bar, data-table, etc.) live in components. |
| `admin-contact-form.css` | Contact Form admin pages: form-card list, per-form delivery + anti-spam editor, defaults section, WYSIWYG field editor (hover overlays, insertion zones, contenteditable cues, captcha stub, inline settings strip, drag-drop indicators, type picker), new-form picker modal, dashboard widget. |
| `admin-captcha.css` | Captcha page: built-in challenge list, provider identity cards, routing grid, diagnostics. |
| `admin-newsletter.css` | Newsletter hub (`?page=lrob-etk-nl`). Sub-areas routed via `&view=…`. |

**Cascade note:** if a rule depends on a primitive, ensure the primitive's file is listed as a dependency in `Assets.php`. The handle constants there mirror this order.

## Shared PHP renderers (src/Admin/)

### PageHeader

`PageHeader::render($args)` — Single source for every plugin page header. Layout: `[Title] [ModuleToggle?] [+ New X primary]   →→→   [Tools group] | [Nav group]`.

- Tools sit close to the primary action; nav links (cross-page) go to the far right with a vertical divider. Tools act on the current page, nav leaves it.
- When the module is disabled, primary/tools/nav buttons are hidden unless `gate: true` is passed explicitly. Title + toggle remain visible.
- Wording mandate: **`New X`** (the `dashicons-plus-alt2` icon supplies the `+`). Never `+ New X`.

Button definition shape:

```php
[
    'label' => 'New identity',     // required
    'icon'  => 'dashicons-plus',   // optional dashicons-* class
    'id'    => 'add-btn',          // optional DOM id (button mode)
    'href'  => $url,               // optional link target (anchor mode)
    'attrs' => ['data-x' => 'y'],  // extra HTML attributes
]
```

Pass `id` OR `href` (or neither for a bare button). Never both.

### ModuleToggle

`ModuleToggle::render_inline($module)` — compact switch next to the page H1. Form auto-submits on toggle change (hooks load/unload on the server anyway, so AJAX would gain nothing). `PageHeader` invokes this automatically when `module` is passed.

Three shapes exist:
- `render_inline()` — switch + state word; sits inline with H1.
- `render_bar()` — wider toggle pill (legacy; used by Logging).
- `render_cta()` — full-card call-to-action for the disabled-and-empty state.

All POST to `admin-post.php` with `action = $module->toggle_action()` and a nonce.

### Combobox

`Combobox::render_fixed_select()` / `render_free_text()` — `<datalist>` is banned; raw `<select>` is banned.

- **`render_fixed_select($meta_key, $current, $options, $inherit_value, $auto_save_marker)`** — readonly input + dropdown. The hidden `.lrob-etk-combo-value` input carries the canonical value and fires `change` when updated. Pass `$inherit_value` (e.g. `''` or `'default'`) for the muted placeholder sentinel — the visible input stays empty when that value is selected. `etk-controls.js` reads `data-options` on every open so live changes (e.g. toggling a captcha identity active/inactive) reflect without a reload.
- **`render_free_text($meta_key, $current, $suggestions, $placeholder, $auto_save_marker)`** — editable input + dropdown of suggestions. The visible input IS the form value; no hidden mirror.

The `$auto_save_marker` class (e.g. `lrob-etk-cf-field`, `lrob-etk-nl-field`) lets each module's auto-save listener pick up the field. Pass `''` when the containing form POSTs normally.

### Tooltip

`Tooltip::render($text, $icon = 'info-outline')` — info-bubble that reveals a short explanation on hover (JS keeps it open after a tap/click). Uses `position: fixed` with JS-computed coordinates so it escapes scroll/modal clipping. The tooltip script is inlined by `Assets::print_tooltip_script()` and runs plugin-wide.

`Tooltip::html($text, $icon)` returns the HTML string for use in `sprintf` contexts.

### RetentionToggle

`RetentionToggle::render($args)` — checkbox + days input pair backed by a hidden field.

- `0` stored = disabled. `>0` = auto-delete after that many days.
- The visible number input always shows a usable value (defaults to `default_days` fallback when checkbox is off) so admins can enable without typing a number first.
- Runtime in `etk-retention-toggle.js` (plugin-wide via event delegation). The hidden field fires `change` after every flip so the module's existing auto-save plumbing picks it up without per-module wiring.

Args: `key` (string), `value` (int), `auto_save_marker` (string), `default_days` (int, default 365), `max_days` (int, default 3650), `label` (string).

### PerPagePicker

`PerPagePicker::render($slug, $current)` / `PerPagePicker::parse($slug, $default)` — inline per-page `<select>` for list/table views. Resolution order: POST > GET > session cookie > default. The cookie has no `expires` so it drops when the browser closes (no stale preference). Allowed values: 10 / 25 / 50 / 100 / 200. Client-side in `etk-perpage.js`.

### Assets

`Assets::enqueue_admin($hook_suffix)` — enqueues all shared CSS and JS on every plugin page. Load positions:

| Handle | File | Position |
|---|---|---|
| `lrob-etk-controls` | `etk-controls.js` | **head** — SMTP card's inline IIFE calls `lrobEtkControls.attachCombobox` mid-body synchronously |
| `lrob-etk-modal` | `etk-modal.js` | **head** — pages inline `bindHeader()` calls mid-body |
| `lrob-etk-confirm` | `etk-confirm.js` | **head** — mid-body inline scripts may use it |
| `lrob-etk-autosave` | `etk-autosave.js` | footer |
| `lrob-etk-list-filter` | `etk-list-filter.js` | footer |
| `lrob-etk-sortable` | `etk-sortable.js` | footer (depends on list-filter) |
| `lrob-etk-perpage` | `etk-perpage.js` | footer (depends on list-filter) |
| `lrob-etk-detail-modal` | `etk-detail-modal.js` | footer |
| `lrob-etk-retention-toggle` | `etk-retention-toggle.js` | footer |
| `lrob-etk-promo` | `etk-promo.js` | footer |

`Assets::asset_version_for($relative_path)` is the public cache-busting helper for per-module enqueuers: returns `LROB_ETK_VERSION . '.' . filemtime(...)` so any CSS/JS edit produces a different `?ver=` without a version bump.

## Shared JS helpers (admin/js/, enqueued plugin-wide via Admin\Assets)

### etk-modal.js — `window.lrobEtkModal`

Drives every header-button-triggered `.lrob-etk-modal` across the plugin.

**DOM contract:**
- Modal element: `.lrob-etk-modal` with `id=<modalId>`, normally `hidden` until opened.
- Opener: button/anchor with `id=<openerId>`.
- Any element with `data-modal-close` (× button, backdrop) closes the dialog.
- Escape closes while open. Body scroll is locked while open.

**API:**
```js
var handle = window.lrobEtkModal.bindHeader(modalId, openerId);
// handle.open() / handle.close() for programmatic control

window.lrobEtkModal.reportSave(sourceEl, state, message);
// state: 'saving' | 'saved' | 'error'
```

**Save feedback inside modals:** every `.lrob-etk-modal-header` gets a status badge injected on first interaction (next to the × button). Any code can dispatch `new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state, message } })` — the event bubbles to the nearest modal and the badge reflects the state. States: `saving`, `saved`, `error`. `etk-autosave.js` dispatches this automatically; ad-hoc save handlers can call `lrobEtkModal.reportSave(el, state)`.

### etk-autosave.js — `window.lrobEtkAutosave`

Per-key autosave for any settings card (`.lrob-etk-card-status` badge included).

```js
window.lrobEtkAutosave.attach(cardElement, {
    fieldSelector: '.lrob-etk-cf-field',  // CSS class to find fields
    save: function(field, value) {         // required; returns Promise<{success, data?}>
        return fetch(ajaxUrl, {...}).then(r => r.json());
    },
    readValue: function(field) { return field.value; },  // optional
    debounceMs: 600,    // optional, default 600 for text inputs
    i18n: { saving: '…', saved: 'Saved', error: 'Save failed' },
});
```

Field binding rules: text/textarea → `input` + `blur` with debounce; `select`/checkbox → `change` immediately; hidden inputs (combobox carriers, retention toggle) → `change` immediately. `lastSent` tracking prevents no-op saves on blur. Bubbles `lrob-etk:save-status` to the nearest enclosing modal automatically.

### etk-controls.js — `window.lrobEtkControls`

Combobox component. Two modes share the same `.lrob-etk-combo*` CSS and outside-click handler.

**Select mode (declarative):** auto-initialized on `DOMContentLoaded`. Markup: `<div class="lrob-etk-combo" data-options="[…JSON…]">` with a readonly `.lrob-etk-combo-input`, `.lrob-etk-combo-toggle`, `<ul class="lrob-etk-combo-menu">`, and a hidden `.lrob-etk-combo-value` input. The hidden input carries the form value; fires `change` on update. `data-inherit-value` attribute sets the sentinel whose display label is suppressed (shown as muted placeholder instead). `data-options` is re-read on every open so live DOM updates reflect.

**Free mode (imperative):** caller invokes `lrobEtkControls.attachCombobox(comboEl, config)` where `config` has `populate(currentValue) → [{value, label}]` and optional `onSelect(value)`. No hidden input; the visible `.lrob-etk-combo-input` IS the form value.

```js
window.lrobEtkControls.attachCombobox(el, {
    mode: 'select' | 'free',
    populate: function(currentValue) { return [{value, label}]; },
    getValue: function() { return ...; },    // optional
    setValue: function(value, label) { ... }, // optional
    onSelect: function(value) { ... },        // optional side-effects
});
window.lrobEtkControls.initCombos(); // re-run auto-init after dynamic DOM changes
```

**Must load in `<head>`** — SMTP identity cards call `attachCombobox` synchronously mid-body.

### etk-list-filter.js — `window.lrobEtkListFilter`

Generic filter form ⇄ list region AJAX swap. Used by Email Logs + Submissions inbox.

```js
var filterApi = window.lrobEtkListFilter.attach({
    formSelector:   '[data-etk-list-form]',
    regionSelector: '[data-etk-list-region]',
    ajaxUrl:        ajaxUrl,
    nonce:          nonce,
    action:         'lrob_etk_cf_list',
    onAfterReload:  function(newRegion) { ... },  // optional
    typingDelay:    300,  // optional
});
filterApi.reload(page);   // trigger a reload programmatically
filterApi.currentRegion(); // get the live region element
```

Wires form inputs to a debounced AJAX reload, keeps browser URL in sync via `history.replaceState`, intercepts pagination links, handles back/forward via `popstate`. Dispatches `CustomEvent('etk:list-region-swapped', { detail: { region } })` after every swap so sortable headers and other region-local scripts can reinitialize.

Nonce sent as both `_ajax_nonce` (standard WP) and `_nonce` (Newsletter guard) so one helper covers both endpoints.

### etk-sortable.js — `window.lrobEtkSortable`

Sortable column headers for list tables. Pairs with `etk-list-filter.js`.

```js
window.lrobEtkSortable.attach({
    cookieKey:      'lrob_etk_sort_subscribers',  // unique per table
    formSelector:   '[data-etk-list-form]',
    regionSelector: '[data-etk-list-region]',
    filterApi:      filterApi,
});
```

Clicks on `<th data-sort-key="...">` cycle: asc → desc → clear. Writes hidden `orderby`/`order` inputs into the filter form. Persists chosen sort in a cookie (1-year; survives reloads). On boot, hydrates from URL params or cookie before first filter interaction. Repaints active-column glyphs after every `etk:list-region-swapped` event.

CSS glyphs (▲ / ▼) driven by `.is-sort-asc` / `.is-sort-desc` on the `<th>` — no transition on the hover state to avoid a visible flicker on first paint.

### etk-perpage.js — `window.lrobEtkPerPage`

Per-page picker glue for `Admin\PerPagePicker`.

```js
window.lrobEtkPerPage.attach({
    slug:         'subscribers',
    formSelector: '[data-etk-list-form]',
    filterApi:    filterApi,
});
```

On `<select data-per-page="<slug>">` change: writes a session cookie (`lrob_etk_per_page_<slug>=<n>`), mirrors the value into a hidden `per_page` input in the filter form, then calls `filterApi.reload(1)` (or falls back to full form submit).

### etk-detail-modal.js — `window.lrobEtkDetailModal`

Generic detail overlay for admin list pages (Contact Form submissions, Email Logs).

```js
var modal = window.lrobEtkDetailModal.create({
    fetcher:       function(id) { return Promise.resolve({title, html, ...}); },
    actionsHtml:   '<button data-cf-detail-action="delete">Delete</button>',
    afterFetch:    function(modal, resp) { /* refresh action buttons */ },
    onAction:      function(actionKey, id, modal) { /* handle action */ },
    getVisibleIds: function() { return [1, 4, 7]; },
    i18n:          { prev, next, close, loading, error },
});
triggerEl.addEventListener('click', function() { modal.open(id); });
// modal.close(), modal.refresh(), modal.currentId(), modal.element()
```

Features: fixed-top layout (prev/next stay under cursor between different-height records); no-flicker body swap (current content stays visible, soft dim in flight; "Loading…" only on first open); ← / → keyboard nav; Escape + backdrop click closes. `currentId` supports both numeric and composite-string ids (e.g. `"subscriber:42"`). The modal element carries `.lrob-etk` so the `.button .dashicons` fix in `admin-base.css` applies even though it's appended to `<body>` outside the normal page wrap.

### etk-retention-toggle.js

Drives the `Admin\RetentionToggle` widget via event delegation on `document`. No explicit init call needed. Handles:
- `[data-retention-enable]` change: enables/disables the days input, writes canonical `0` or days value to the hidden field, dispatches `change` on the hidden field.
- `[data-retention-days]` change: clamps value, writes to hidden field, dispatches `change`.

`dispatch()` has an IE11 fallback via `createEvent`; should never fire in modern WP admin but is kept defensively.

### etk-confirm.js — `window.lrobEtkConfirm`

Styled `window.confirm()` replacement. Lazily appends a `.lrob-etk-modal` to `<body>` on first call; reuses the element on subsequent calls.

```js
window.lrobEtkConfirm.prompt({
    title:        'Delete identity',
    message:      'This cannot be undone.',
    confirmLabel: 'Delete',
    danger:       true,   // uses .lrob-etk-btn--danger-solid
}).then(function(ok) { if (ok) { /* do it */ } });
```

Escape, backdrop click, ×, and Cancel all resolve `false`. Primary button resolves `true`. Cancel button is focused on open so an accidental Enter doesn't confirm.

**Server-rendered forms** can use `[data-etk-confirm-form]` with `data-confirm-title`, `data-confirm-message`, `data-confirm-label` attributes — the helper intercepts `submit` and shows the styled prompt before allowing the form to proceed.

**Must load in `<head>`** — mid-body inline scripts may use it before DOMContentLoaded.

### etk-promo.js

Paints the `.lrob-etk-promo` strip from the `window.lrobEtkPromo.messages` pool, starts on a random message, auto-rotates every ~9 s with a 350 ms fade, pauses while hovered. Relocates the strip's `.wrap` out of `#wpfooter` (which is `position: absolute`) into `#wpbody-content`'s normal flow so it reserves its own height and doesn't paint over page content. After relocation it shrinks `#wpbody-content`'s `paddingBottom` to `#wpfooter.offsetHeight` so the two sit flush.

The strip host (`<aside class="lrob-etk-promo">`) is printed via `in_admin_footer` by `Admin\PromoStrip::render()` — this hook places it inside `#wpcontent` where the `--etk-*` token scope applies (unlike `admin_footer` which is outside `#wpcontent`).

## CSS primitives (admin-components.css)

Don't redefine these per module — extend with module-only variants where genuinely needed.

- **`.lrob-etk-card`** (+ `--container` modifier for inline-size container queries) — every settings/identity card. Consumes `--etk-card-bg`, line border, lg radius, md shadow, focus-within highlight, `.is-new` accent. Module flavors (`.lrob-etk-identity-card` for SMTP JS hook, `.lrob-etk-form-card` for CF, `.lrob-etk-captcha-card`, `.lrob-etk-nl-card`, `.lrob-etk-logs-storage-card`) sit alongside as semantic markers, not duplicate visuals.
- **`.lrob-etk-card-grid`** — `repeat(auto-fit, minmax(380px, 540px))`. CF form cards add `--wide` modifier (`minmax(420px, 750px)`) so hCaptcha (303px min) fits.
- **`.lrob-etk-card-form` + `-head` + `-status` + `-footer`** — card internals. `-status` is the animated save badge that collapses to zero width at rest (max-width + opacity transition) so the title input is the only flex-shrinkable sibling — the badge expands smoothly into the space the title yields.
- **`.lrob-etk-data-table` + `-wrap`** — replaces WP `widefat striped`. Shared by Email Logs + Submissions inbox; column widths via `.col-*` modifiers.
- **`.lrob-etk-filter-bar` + `-field` (+ `--search`) + `-actions`** — top-of-list filter row.
- **`.lrob-etk-bulk-toolbar`, `.lrob-etk-pagination`** — list chrome below the filter bar / above results.
- **`.lrob-etk-icon-btn`** (+ `--ghost` / `--danger` / `--spam`) — square icon-only button. Replaces every per-module row-action / picker-trigger / conn-test variant. Sizes drive off tokens.
- **`.lrob-etk-btn--danger` / `--spam` / `--danger-solid` / `--warn-solid`** — modifiers on WP `.button`. Outline variants (`--danger`, `--spam`) for inline actions; solid-fill variants (`*-solid`) for destructive confirm buttons (standalone pages AND the JS confirm dialog — same primitive for both).
- **`.lrob-etk-combo` + `-input` + `-toggle` + `-menu`** — input+dropdown shell. The recipient-list row uses the same input-shell idiom; the `<datalist>` ban applies.
- **`.lrob-etk-menu` + `--fixed` + `-item`** — floating menu shared between combobox and JS-positioned pickers (recipient menu). `.lrob-etk-combo-menu` uses `position: absolute` anchored inside the combo shell; use `.lrob-etk-menu--fixed` (`position: fixed`) when the menu's position is JS-computed relative to an arbitrary anchor.
- **`.lrob-etk-modal` + `-dialog` (+ `--small` / `--wide`) + `-header` + `-body` + `-footer`** — modal chrome. Opened via `window.lrobEtkModal.bindHeader()`. `[data-anchored="1"]` variant: transparent backdrop + JS-positioned dialog (used for test-email near button).
- **`.lrob-etk-popover` + `-header` + `-body` + `-footer`** — anchored popover (SMTP conn-test details, dashboard test email).
- **`.lrob-etk-test-result`** (+ `.is-pending` / `.is-success` / `.is-failure`) — banner for SMTP conn-test / Captcha verify-test / manual-cleanup result.
- **`.lrob-etk-detail-strip` + `-item` + `-label` + `-value`** — chip row at top of record detail views (submission, log entry).
- **`.lrob-etk-cleanup-row` + `-statuses` + `-actions`** — manual-cleanup row inside Storage modals.
- **`.lrob-etk-retention-toggle`** — checkbox + days widget rendered by `Admin\RetentionToggle`.
- **`.lrob-etk-status`** (+ `--on` / `--off` / `--fail` / `--pending`) — pill badges.
- **`.lrob-etk-is-dimmed`** — reusable greying veil (opacity, hover-restores to full). Marks a card/row inactive or terminal: inactive captcha + SMTP identities, sent newsletters. Toggle it live in JS alongside the state change.
- **`.lrob-etk-section-title`** — page-level section title carries a `border-top`; inside `.lrob-etk-card` the border is suppressed (the parent section owns separation).
- **`.lrob-etk-tip` + `-text`** — tooltip rendered via `Admin\Tooltip::render()`; `position: fixed` with JS coords (z-index 200 000, above modals at 100 000).
- **`.lrob-etk-card-footer-default`** slot carries `.lrob-etk-default-badge` (star-filled, "Default") or `.lrob-etk-set-default` (star-empty, click to make default).
- **`.lrob-etk-switch-track`** (+ `--sm` / `--lg`) — single track + knob primitive consumed by every switch wrapper (page-toggle, inline-switch, section-switch, retention-toggle). Default md (32×18 track, 14×14 knob). The label wrapper owns flex layout + label styling; the track owns dimensions.
- **`.lrob-etk-section-tabs` + `-tab` (+ `.is-active`) + `-tab-count` + `-tabs-end`** — WP-nav-tab style strip used for both page-level sub-section navigation (Newsletter: Dashboard / Newsletters / Subscribers…) and in-page filter tabs (All / Sent / Trash). The active tab "sits on" the baseline border. `.lrob-etk-section-tabs-end` is a trailing slot for actions (e.g. "Empty trash").
- **`.lrob-etk-empty-state`** — icon + title + body + optional CTA for zero-row states.
- **`.lrob-etk-banner-warning` / `-error`** — left-border-accent banners with icon.
- **`.lrob-etk-confirm-prompt`** — the JS confirm-dialog overlay (rendered by `etk-confirm.js`).
- **`.lrob-etk-inline-confirm`** — inline destructive-confirm row (for modals, swaps row → confirm inline).
- **`.lrob-etk-bulk-action`** — generic flex+gap cluster inside `.lrob-etk-bulk-toolbar` (bulk-action dropdown + Apply, or count + per-page select + icon buttons).

## Notable quirks documented in CSS

- **`[hidden]` override** (`admin-base.css`): WP's `.button { display: inline-block }` has equal specificity to the browser UA `[hidden] { display: none }` and loads later, defeating plain `hidden` on `.button` elements. Rule: `.lrob-etk [hidden] { display: none !important }`.
- **`.button .dashicons` line-height** (`admin-base.css`): WP 7.0 set `line-height: 1.9` on dashicons inside buttons (pushes icon above text baseline). Overridden with `line-height: 1` for all three button classes inside `.lrob-etk`.
- **Sortable column hover** (`admin-components.css`): no CSS transition on `th[data-sort-key]:hover` background — a transition fires on first paint and produces a visible flicker until the browser has cached the from-state.
- **SMTP identity container query** (`admin-smtp.css`): `.lrob-etk-identity-card .lrob-etk-modal-columns` collapses to 1 column at `@container (max-width: 460px)` — specific to this card's inner 2-col mailbox|server layout, no other card has the same structure.
- **hCaptcha min-width** (`admin-captcha.css`): hCaptcha widget is 303×78 px and the iframe doesn't shrink. `.lrob-etk-form-field--challenge` uses `overflow-x: auto` to allow horizontal scroll within the field rather than letting the iframe leak onto adjacent columns. Applied to both editor preview and frontend form.
- **Contact Form editor gap vs. inserts** (`admin-contact-form.css`): the frontend uses flex `gap` between rows/fields; the editor switches to margin-based rhythm so zero-height insertion zones cost nothing in layout when collapsed.
- **Promo strip relocation** (`admin-components.css` + `etk-promo.js`): the `<aside>` is printed by `in_admin_footer` inside `#wpfooter` (which is `position: absolute`), causing it to overflow upward over page content. `etk-promo.js` relocates the `.wrap` into `#wpbody-content` normal flow and adjusts `paddingBottom`.
