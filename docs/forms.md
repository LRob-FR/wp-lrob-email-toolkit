# Shared form infrastructure (`src/Forms/`)

Covers the backend form infrastructure shared between Contact Form and Newsletter:
field types, registry, structure storage, renderers, captcha field, honeypot, upload
policy, style presets, country data, and the frontend pieces (`assets/js/form-submit.js`,
`assets/css/contact-form.css`).

For the admin WYSIWYG editor JS (`admin/js/form-fields-editor.js`,
`admin/js/contact-form-editor.js`) see `docs/form-builder.md`.

---

## Field types and registry

### `FieldTypeInterface`

Every field type implements `slug()`, `label()`, `normalize(array $field)`, and
`render(array $attrs)`. Renderers are stateless and host-neutral; the caller wraps
them in a `FormContext` scope.

- `normalize()` — sanitises a raw structure entry and returns the canonical shape, or
  `null` to drop the entry silently (prevents a bad client save from wiping a form).
- `render()` — emits HTML for both frontend and editor modes. `FormContext::is_editor()`
  switches editor-specific markup (contenteditable labels, inline settings strip, etc.)
  without a separate render path.

### `FieldTypeRegistry`

Per-CPT bucket of `FieldTypeInterface` instances. Modules register their field types at
boot via `register(string $cpt_slug, FieldTypeInterface $type)`. `FormStructure` and
`FormEditorRenderer` dispatch through the registry, never hard-code type slugs.

Per-CPT isolation is intentional: Contact Form text/email/etc. types don't bleed into
Newsletter's allowed set (same slug in both CPTs is fine — each has its own bucket).

### Built-in field types (`src/Forms/Fields/`)

| Class | Slug | Notes |
|---|---|---|
| `TextField` | `text` | Accepts `maxLength`. |
| `EmailField` | `email` | Accepts `maxLength`. |
| `NumberField` | `number` | Accepts `min`, `max`, `step`. |
| `PhoneField` | `phone` | Plain `<input type="tel">` or composite country-picker widget. |
| `DateField` | `date` | Accepts `min`, `max`. |
| `TextareaField` | `textarea` | Accepts `rows`, `maxLength`. |
| `SelectField` | `select` | Single-choice. `defaults` capped to one entry by design. |
| `RadioField` | `radio` | Renders via `FieldRenderHelpers::render_option_group`. |
| `CheckboxField` | `checkbox` | Multiple mode = option group; single mode = boolean inline checkbox. |
| `SubmitField` | `submit` | `text` + `align` (left/center/right/stretch). No label/helper/required. |
| `FileUploadField` | `file_upload` | See Upload Policy section. |

`CaptchaField` lives in `src/Forms/CaptchaField.php` (not under `Fields/`) — it
references the Captcha module and is registered per-consumer (see below).

---

## `FormContext`

Global static render-scope state shared by embed renderer and field renderers. Must
always be paired: `start()` before rendering, `end()` in a `finally`.

```php
FormContext::start(
    int    $form_id,
    string $instance,      // unique per-embed (entropy or 'editor')
    string $name_prefix,   // top-level $_POST key (e.g. 'lrob_etk_cf')
    string $id_prefix,     // seeds DOM ids
    bool   $editor = false
);
```

Key methods used by field renderers:

- `FormContext::is_active()` — field renderers return `''` when inactive.
- `FormContext::is_editor()` — flips label/helper to contenteditable, submit to
  `type="button"`, etc.
- `FormContext::field_name($slug, $multiple)` → `name_prefix[instance][slug]` (or `[]`
  suffix for multiple).
- `FormContext::field_id($slug)` → `id_prefix-instance-slug`.

Two embeds of the same form on a single page don't collide because `$instance` is unique
per embed; two different form CPTs don't collide because `$name_prefix` differs.

---

## `FormStructure`

Owns the row/column/field tree. Stored as JSON in `post_content` (host-CPT-agnostic,
survives revisions and `wp_delete_post`).

**Structure shape (version 1):**

```json
{
  "version": 1,
  "rows": [
    { "id": "row_…", "columns": [
      { "id": "col_…", "fields": [
        { "id": "f_…", "type": "text", "slug": "name", "label": "…", "required": true, … }
      ]}
    ]}
  ]
}
```

Any `post_content` that doesn't decode to this shape (legacy Gutenberg blocks, plain
text) is treated as empty — by design.

### Key methods

- `load(int $form_id)` — decodes and normalizes, runs a one-time legacy migration for
  forms with a top-level `submit` key (pre-dates in-row submit field).
- `save(int $form_id, array $structure)` — normalizes then `wp_update_post`. Uses
  `wp_slash` to compensate for `wp_update_post`'s `wp_unslash`; `JSON_UNESCAPED_UNICODE`
  avoids the `\uXXXX` sequences that `wp_unslash` would otherwise corrupt.
- `normalize(array $structure, string $cpt_slug)` — sanitises top-to-bottom. Unknown
  field types (for the CPT) are dropped silently, not rejected. Runs
  `enforce_unique_nths_and_slugs()` as a post-pass.
- `fields_index(array $structure)` → flat `slug → {label, type}` map; used by the
  submissions inbox to render stored slugs with their current labels.
- `has_field_of_type(array $structure, string $type)` — used to warn when a form has no
  submit button.

### `enforce_unique_nths_and_slugs()`

Three-pass post-pass:
1. Discover the highest existing `nth`.
2. Backfill any 0/missing/duplicate `nth` with the next free index.
3. Suffix duplicate `slug` values with `_2`, `_3`, etc.

---

## `FieldRenderHelpers`

Stateless helpers shared by every `FieldTypeInterface` implementation.

- `gen_id(string $prefix)` — 8-char hex random id (`prefix_xxxxxxxx`).
- `normalize_base_keys(array $field, string $type)` — sanitises `id`, `type`, `slug`,
  `nth`, `label`, `helper`, `placeholder`, `required`, and the optional `maps_to` key
  (Newsletter subscriber profile mapping; Contact Form ignores it).
- `normalize_options($raw)` / `normalize_defaults($raw, $options, $multiple)` — option
  list cleanup for select/radio/checkbox.
- `render_input(string $input_type, array $attrs, array $extra)` — shared renderer for
  text/email/number/date/phone (plain). Resolves `autocomplete` via `guess_autocomplete`.
- `render_option_group(string $kind, array $attrs)` — radio/checkbox group using
  `<fieldset>` + `<legend>`. Editor mode flips the legend to a contenteditable span
  (same `label-for` click-steal avoidance as `label_html`).
- `wrap_field(…)` — outer field markup: label + control + helper + `[data-field-error]`
  placeholder. CSS class uses `lrob-etk-form-field--<type>`.
- `label_html(…)` — editor mode: `<div>` (not `<label>`) + contenteditable span +
  required toggle. Frontend: `<label for="…">` + optional required marker.
- `helper_html(string $helper)` — editor mode: always-rendered contenteditable `<p>`.
  Frontend: emitted only when `$helper !== ''`.
- `required_toggle_html(bool $required)` — editor-only: visual star + hover-revealed
  labelled checkbox pair.
- `recover_unicode_escapes(string $s)` — repairs legacy `uXXXX` sequences written before
  the `wp_slash` compensation landed.

CSS classes emitted here use `lrob-etk-form-*` (host-neutral, shared between Contact
Form and Newsletter). Module-specific admin chrome keeps its own prefix.

---

## `FormEditorRenderer`

Renders the WYSIWYG editor view of a form. Differences from the frontend:

- Wraps in `<div class="lrob-etk-form is-editor">` (not `<form>`) so the admin card
  form doesn't accidentally submit.
- Starts `FormContext` with `editor=true`.
- Wraps each row/column/field in a shell carrying `data-*` attributes the editor JS
  reads for drag/delete/serialize.
- Emits `+` insertion zones between every pair of rows/fields and at the row's right
  edge for new columns.
- Does **not** emit honeypot, nonce, or hidden submission fields.

`FormEditorRenderer::render(int $form_id, string $name_prefix, string $id_prefix)` is
the only public entry point. The DOM it emits is the contract `form-fields-editor.js`
depends on — if you change either side, change both. See `docs/form-builder.md` for the
full DOM contract.

---

## `CaptchaField`

Shared captcha field registered by each consumer module with its own context and meta key:

```php
new CaptchaField(
    context:  'contact_form',              // Captcha Routing context
    meta_key: '_lrob_etk_cf_challenge',    // per-form override post_meta key
)
```

- Frontend render: resolves the effective routing key (per-form meta, or context default)
  and delegates to `CaptchaService::render()`.
- Editor render: `editor_stub()` — an in-block picker `<select>` whose `data-key` hooks
  into the admin auto-save, plus a live preview pane.
- `build_editor_options(string $context, ?CaptchaService $service)` — static factory for
  the JS-localized `captchaOptions.entries` array (route, label, preview HTML, disabled
  flag). The editor JS uses this to rebuild captcha blocks from scratch when the admin
  inserts a captcha field without a page reload.

---

## `Honeypot`

Hidden honeypot field. Field name: `_lrob_etk_form_hp_website`. CSS-hidden + offscreen +
`tabindex="-1"` + `autocomplete="off"`. Both Contact Form and Newsletter submit pipelines
call `Honeypot::tripped(array $post)` to check it.

---

## `StylePresets`

Single source of truth for the `lrob-etk-form-preset--<slug>` root class modifiers. Both
Contact Form and Newsletter consume the same list so preset coverage is uniform.

Current presets: `default`, `minimal`, `soft`, `contrast`.

To add a preset: (1) add slug + label here, (2) add `.lrob-etk-form-preset--<slug>`
selectors to `assets/css/contact-form.css`. Both modules pick it up automatically.

---

## `CountryData`

ISO 3166-1 alpha-2 dataset for the phone-field country picker.

- `all_translated(string $sort_by)` → `[{iso, name, dial, flag}]`. Sort by `'name'`
  (locale-aware, default) or `'dial'` (numeric).
- `resolve_default(string $admin_choice)` — priority chain:
  1. Admin's explicit choice.
  2. WP locale (`fr_FR` → `FR`).
  3. WP timezone (`Europe/Paris` → `FR`) via `DateTimeZone::PER_COUNTRY`.
  4. Blank — visitor must choose.
- `flag_emoji(string $iso2)` — regional-indicator pair computed from the ISO-2 code at
  read time; no static glyph data to maintain.
- `dial(string $iso2)` — dial prefix string (no `+`).

---

## Upload Policy (`src/Forms/Upload/UploadPolicy`)

Two-tier extension whitelist for `FileUploadField`.

- **Tier 1** (`tier1_extensions()`) — server-executable formats (PHP, ASP, CGI, shell
  scripts, `.htaccess`, etc.). Always rejected; admin cannot override.
- **Tier 2** (`tier2_extensions()`) — XSS-via-inline-content or client executables
  (HTML, SVG, JS, EXE, MSI, PS1, etc.). Rejected unless the field's `allow_dangerous`
  checkbox is ticked.

**Delivery modes** (`DELIVERY_*` constants):
- `webserver` — save to disk, email links to admin.
- `attachment` — attach files to the notification email, no storage.
- `both` — save AND attach.

**Presets** (`PRESET_*` constants): `images`, `documents`, `pdf`, `vcard`, `videos`,
`audio`, `archives`, `custom`. `custom` parses a comma/semicolon/space-separated
extension list from the `accept_custom` field setting.

`resolve_extensions(string $preset, string $custom_csv, bool $allow_dangerous)` strips
tier-1 unconditionally and tier-2 unless `$allow_dangerous`. Returns lowercase extension
strings (no dot prefix).

`accept_attribute(array $exts)` → dotted extension list for the HTML5 `accept=`
attribute (`.pdf,.jpg`). Extensions preferred over MIME globs for cross-browser
reliability.

`mime_hint(string $ext)` → coarse MIME string for cross-checking against
`wp_check_filetype_and_ext()`; content sniffing is the source of truth.

---

## Frontend: `assets/js/form-submit.js`

Single IIFE, no dependencies. Drives every `.lrob-etk-form` (both Contact Form and
Newsletter). Discovers forms at DOMContentLoaded and watches for late-mounted forms via
`MutationObserver`.

**Submit flow:**

1. `validateClient(form)` — required + email-format checks on `.lrob-etk-form-field[data-field]` elements. Returns error array.
2. Invisible captcha guard (hCaptcha invisible / reCAPTCHA v2 invisible): if `[data-lrob-etk-invisible]` is present and the token isn't filled, calls `runInvisibleCaptcha()` and returns. The vendor fires `window.lrobEtkInvisibleResolve()` on success, which re-submits the form.
3. reCAPTCHA v3 guard: if `[data-lrob-etk-recaptcha-v3]` is present and token isn't filled, calls `runRecaptchaV3()` which calls `grecaptcha.execute()` then re-submits.
4. On the resumed pass, `fetch` POSTs `FormData` to `ajaxUrl` (from `window.lrobEtkForm`). The form's own hidden `action` input carries the WP AJAX action name, so the same JS serves both modules.
5. `joinPhonesInto(fd, form)` prefixes each country-picker tel value with `+<dial>` in the outgoing FormData (visitor keeps the national format on screen; server sees E.164).
6. Response handling: success → `is-sent` class (hides the form body); field errors → `is-invalid` + `[data-field-error]` per field; form-level message → `[data-form-status]` banner (rendered **below** the form, under the submit button; `revealStatus()` scrolls to it only when off-screen).

**Phone country picker** (`attachPicker(el)`):

- Built lazily on first open from `window.lrobEtkForm.countries`.
- Search-as-you-type filter on name, ISO code, dial prefix.
- `window.lrobEtkPhone.attach(el)` / `.joinForSubmit(fd, form)` exposed for the admin
  form-builder preview.

**File upload** (`attachFileUpload(el)`):

- Hides the native `<input type="file">` behind a visible label-button.
- `renderFileList()` pre-validates files client-side (count, per-file size, total size,
  extension whitelist) and surfaces errors on the field's existing `[data-field-error]`
  slot.

**Captcha globals** declared on `window` (referenced declaratively by widget
`data-callback` / `data-error-callback` attrs rendered by `AbstractHostedCaptcha`):

- `lrobEtkInvisibleResolve` — fires on successful invisible solve; re-submits.
- `lrobEtkInvisibleFailed` — fires on error; shows error banner.
- `lrobEtkInvisibleExpired` — fires on token expiry; clears the pending form reference.

---

## Frontend CSS: `assets/css/contact-form.css`

Scoped under `.lrob-etk-form`. Uses a `--lrob-etk-cf-*` token system (separate from the
admin `--etk-*` tokens — never cross the two).

**Token fallback chain** for each variable:
1. Plugin var (set inline by per-block / per-form / global settings).
2. Matching WP FSE theme preset variable (e.g. `--wp--preset--color--primary`).
3. Hardcoded sensible default.

This lets a block-theme's primary color and typography flow into the form for free while
still allowing per-form overrides.

**Key tokens:**

| Token | Purpose |
|---|---|
| `--lrob-etk-cf-accent` | Submit button fill, focus rings, option accent-color. |
| `--lrob-etk-cf-accent-fg` | Text/icon on the accent-filled submit. Fixed `#fff` default. |
| `--lrob-etk-cf-fg` | Form root text (labels, helpers). `inherit` — follows the page section. |
| `--lrob-etk-cf-input-fg` | Input chrome text. `--wp--preset--color--contrast` hook. |
| `--lrob-etk-cf-bg` | Input background. `--wp--preset--color--base` hook. |
| `--lrob-etk-cf-border` | Input borders. |
| `--lrob-etk-cf-muted` | Helper text, placeholders. |
| `--lrob-etk-cf-radius` | Border radius (inputs, status banner, presets). |
| `--lrob-etk-cf-shadow-focus` | Focus ring shadow. |
| `--lrob-etk-cf-trans` | Transition shorthand. |

## Form theming (presets + resolution)

Presets are **data**, not CSS classes. `src/Forms/StylePresets.php` is the single source: a `schema()` of styleable vars (`schemaKey → {var, type, label}`) and a set of presets (`slug → {label, vars}`). `default` carries no vars (inherits the FSE theme via the CSS fallback chain); `dark`/`minimal`/`rounded`/`sharp`/`ocean` are pure var swaps over the contract above.

`src/Forms/StyleResolver::inline_style($preset, $perForm, $global, $block)` merges the tiers (later wins) — **preset baseline < global default < per-form override < block override** — sanitises each value (colour/size allowlist, blocks `;`/`}`/`url(`) and returns the `--lrob-etk-cf-*:…` declaration emitted inline on the `<form>` by both `EmbedRenderer`s. No per-preset CSS, and **both modules render identically** (newsletter previously emitted only an inert class).

- **Storage**: `META_STYLE_PRESET` (slug) + `META_STYLE_VARS` (JSON `schemaKey → value`, per-form overrides) on each CPT. CF global defaults (accent/radius/font-size) live in `Settings`; newsletter has no global surface yet.
- **Live preview**: `StylePresets::js_data()` (`{presets:{slug:{cssVar:val}}, vars:[…]}`) is localized to `lrobEtkCfAdmin`; `contact-form-admin.js applyPreset()` clears the var list then sets the chosen preset's vars inline on the card preview.
- **Adding a preset**: add an entry to `StylePresets::presets()` — that's it (picker, resolver, preview, both modules pick it up). To add a styleable property, add it to `schema()` (with `group` + `type`) and consume its `var` in `contact-form.css`.

### The editor: global preset + per-axis dropdowns (+ Edit knobs)

The **override map** (`META_STYLE_VARS`, `schemaKey → value`) is the single source of truth. The frontend renders **from the map only** (`StyleResolver::inline_style('', $map, …)`, no preset baseline) — with a back-compat seed in both `EmbedRenderer`s: a pre-map form (preset slug, empty map) seeds the map from `vars_for($preset)`.

- **Global preset dropdown** (the existing preset combobox on each card): on change, `form-style-controls.js` bulk-replaces the map with that preset's vars. Presets are themselves composed from axis options (`StylePresets::presets()` → `compose([...])`).
- **`StylePresets::axes()`** defines the named sub-presets, each owning a set of schema `keys` + one-click `options` (slug → vars): **colour scheme** (Auto/White/Dark/Ocean/Forest/Sunset/Deep blue/Purple party/Pink), **roundness** (Sharp/Soft/Roundy/Round), **spacing** (Compact/Average/Comfortable/Spacious), **font size** (Auto/Small/Medium/Big/Extra-big), **label font**, **body font**, **label emphasis** (Regular/Bold/Italic/Bold italic).
- **`StyleControls::render($field_class, $meta_key, $current)`** renders, per axis: a `<select>` of its options (with a hidden `custom` sentinel) + an **Edit** button revealing that axis's individual knobs (`render_row` per key). Picking an option sets its vars and clears the axis's other keys (single-select); a manual knob edit flips the select to *Custom*. `axes()` matching is exact (map-restricted-to-axis-keys === option vars).
- **Persistence + preview**: hidden `[data-style-json]` field tagged with the host autosave class (`lrob-etk-cf-field`/`lrob-etk-nl-field`) → rides autosave → `StyleResolver::sanitize_map()`. `form-style-controls.js` paints the map onto the card preview and keeps the axis selects/knobs in sync; `lrobEtkStyle` (= `js_data()`: presets + axes in schemaKey space, `keyToVar`, `vars`) is localized on both form pages.
- **Contract additions**: `--lrob-etk-cf-form-bg/-border/-pad`, `--lrob-etk-cf-btn-bg/-btn-radius`, `--lrob-etk-cf-border-width`, `--lrob-etk-cf-label-font/-weight/-style` (defaults keep the old look via CSS `var()` fallback).
- *Deferred (C/D):* **user-saved presets** (save/delete named looks → appear in the global dropdown), the global **Defaults** surface getting this same editor, effects/animations, theme-palette inheritance.

**Notable sections:**

- **Captcha challenge shared layout** (`.lrob-etk-cf-challenge`) — self-contained so it
  looks identical in the live form, the editor preview, and the captcha-settings preview
  (which is NOT inside `.lrob-etk-form`). None of these rules lean on `.lrob-etk-form
  input` scope.
- **Captcha wrap for WP-native forms** (`.lrob-etk-captcha-wrap--narrow`) — scales down
  full-size hosted widgets (hCaptcha, reCAPTCHA, Turnstile) on narrow contexts like
  wp-login (~270px) via `transform: scale(0.88)`.
- **Phone country picker** (`.lrob-etk-form-phone`) — trigger button + tel input sharing
  a single rounded border; dropdown menu.
- **File upload** (`.lrob-etk-form-file`) — visible label-button over the hidden native
  input; selected-file list; `.is-invalid` per-item error state.
- **Honeypot** (`.lrob-etk-cf-hp`) — absolute-positioned 1×1px overflow:hidden cell.

---

## Embed Gutenberg block (`admin/js/contact-form-editor.js`)

Registers the `lrob-etk/contact-form` block for embedding a form in any page/post. Pure
`wp.element` / `wp.blocks`, no JSX, no build step.

**Block attributes:** `formId` (integer), `preset` (string), `overrides` (object with
optional keys `accent`, `radius`, `font_size`).

**Edit component (`EmbedEdit`):**

- Fetches published Contact Form CPT entries via `wp.apiFetch` on first render.
- Sidebar `InspectorControls`: form picker, style preset, per-override fields (accent
  color, corner roundness, font size).
- Canvas: when no form is selected or the saved `formId` no longer points to a published
  form (orphan), renders a `Placeholder` with the picker inline. Once a form is picked,
  renders a non-interactive named preview tile.
- `save` returns `null` — server-side rendering via `ContactFormBlock::render_callback`.

**Localized data** (`window.lrobEtkCfEditor`): `globalDefaults` (site-wide style
settings), `editFormBase` (admin URL prefix for "Edit this form →" link).
