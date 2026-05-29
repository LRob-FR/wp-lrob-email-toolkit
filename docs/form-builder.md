# Form-builder WYSIWYG editor (`admin/js/form-fields-editor.js`)

> Loaded on demand, **not** part of the always-in-context `CLAUDE.md`. Pointed to from `CLAUDE.md` → "Form-builder" and from a header comment at the top of `admin/js/form-fields-editor.js`. Keep it in sync when the editor's section map, serialized shape, or DOM contract changes — **if you change the DOM contract on one side (JS or `FormEditorRenderer.php`), change both.**
>
> Backend form infrastructure (field types, `FormContext`, `FormStructure`, `FormEditorRenderer`, captcha field, honeypot, upload policy, `form-submit.js`, `contact-form.css`) is documented in `docs/forms.md`.

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

## Serialized field shape

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

## DOM contract the editor JS depends on

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

## Where to make common changes

- **Add a new field type:** `buildField()` switch + `buildControlHtml()` switch (with inline option editor seed if multi-choice) + `serialize()`'s data-attr list + `typeSpecificInlineHtml()` switch + `FieldRenderer.php` (frontend) + `FormEditorRenderer.php` (editor preview).
- **Add a new per-field setting:** chip in `typeSpecificInlineHtml()` writing `data-inline-prop="X"` + read it in `serialize()` via `data-attr-X` + PHP side in `FieldRenderer` + schema in `FormStructure.php`.
- **Tweak the inline option editor:** start at `applyOptionsToPreview` / `renderSelectPreview` / `renderOptionGroupPreview` / `syncOptionsFromInline`. Mirror DOM shape changes in `buildControlHtml`.
- **Tweak drag-drop:** start at `pickDropHover()` / `computeDropDirection()` / `sameScope()`.
- **Add an undo-able action:** wrap with `commit()` at the end. One commit per user action.

---

## Localized data (`window.lrobEtkFormEditor`)

Populated by PHP at enqueue time. Key properties:

| Key | Type | Purpose |
|---|---|---|
| `save.action` | string | WP AJAX action for structure saves (per-CPT). Falls back to `lrob_etk_cf_save_structure`. |
| `save.nonce` | string | Nonce for that action. |
| `save.ajaxUrl` | string | `admin-ajax.php` URL. |
| `save.i18n` | object | Save-state strings: `saving`, `saved`, `error`. |
| `fieldTypes` | object | `slug → translated label`. Used when building new field stubs. |
| `fieldPresets` | array | Field preset descriptors `{slug, label, fields: [{type, label?, maps_to?, required?, options?}]}`. |
| `mapsToTargets` | array | `{value, label}` list for the "Maps to" chip. Empty on Contact Form (chip stays hidden). |
| `captchaKey` | string | Post-meta key the captcha picker autosaves to. |
| `captchaOptions` | object | `{entries: [{route, label, preview, optgroup?, disabled?}]}` — pre-rendered for inserted captcha blocks. |
| `placeholderPresets` | array | `{value, label}` suggestions for the placeholder combobox. |
| `countries` | array | `{iso, name, dial, flag}` — used by the country-picker combo and phone preview builder. |
| `uploadPresets` | array | `{value, label, exts}` — for the accept-preset combo. |
| `uploadDeliveryOptions` | array | `{value, label}` — for the delivery combo. |
| `uploadTier1Extensions` | array | Server-executable extensions (tier-1 blacklist). |
| `uploadTier2Extensions` | array | Client-side dangerous extensions (tier-2 blacklist). |
| `serverMaxUploadBytes` | string | PHP `upload_max_filesize` in bytes as a string; shown as hint in the max-MB chip. |
| `i18n` | object | Editor-UI strings: `alignment`, `alignLeft/Center/Right/Stretch`, `rows`, `maxLength`, `mapsTo`, `mapsToNone`, `countryPicker`, `defaultCountry`, `autoFromLocale`, `captchaPick`, `captchaOff`, `required`, `labelPlaceholder`, `helperPlaceholder`, `singleCheckboxHint`, `addOption`, `removeOption`, `setAsDefault`, `unsetDefault`, `chooseFile`, `chooseFilesMulti`, `uploadHint`, `uploadHintTpl`, `uploadHintNoLimit`, `serverMaxHint`, `fileDelivery`, `acceptPreset`, `acceptCustom`, `stripExif`, `allowDangerous`, `multipleFiles`, `maxCount`, `maxSizeMb`, `totalSizeMb`, `autoDetectCountry`. |

## Captcha preview mounting (`mountCaptchaPreview` / `ensureCaptchaScript`)

On load and after every captcha-pick dropdown change, `mountCaptchaPreview(previewEl)`
scans the preview slot for `.h-captcha`, `.cf-turnstile`, `.g-recaptcha` divs that
haven't been mounted yet. For each vendor it calls `ensureCaptchaScript(cls, callback)`,
which lazy-loads the vendor script in explicit-render mode (avoids "only one captcha per
container" warnings from auto-render mode), then calls `g.render(el)`.

Vendor script URLs and global names are in `CAPTCHA_VENDORS`:
- `h-captcha` → `hcaptcha` global, `js.hcaptcha.com/1/api.js?render=explicit`
- `cf-turnstile` → `turnstile` global, `challenges.cloudflare.com/…?render=explicit`
- `g-recaptcha` → `grecaptcha` global, `google.com/recaptcha/api.js?render=explicit`

The polling loop gives up after 100 iterations (~10 s on a blocked network).

## Field defaults seeded on insert

New `select`, `radio`, `checkbox` fields are seeded with two starter options (`Option 1`,
`Option 2`). New `checkbox` defaults to `multiple=1` (option group). The user can flip
to single via the inline settings strip.

New `phone` defaults to a plain `<input type="tel">` (no country picker). The country
picker is toggled on via the inline settings strip; `applyPhonePreview(shell)` swaps the
control between the plain input and the composite picker DOM.

## `nth` and slug stability

`nth` is assigned once when a field is inserted (`nextNth()` scans the current max).
It survives reordering and deletions of other fields. Slug is `<type>_<sluggified-label>_<nth>`.

Renaming a field's label rewrites its slug (anything pointing at the old slug needs
re-picking). `nth` never changes after assignment, so stable external references can key
off `nth` if needed.

`FormStructure::enforce_unique_nths_and_slugs()` is the server-side safety net that
backfills and deduplicates on every save — the JS slug derivation and the PHP safety net
must agree on the `<type>_<label>_<nth>` formula.

## Undo/redo snapshot model

History entries are snapshots of `form.innerHTML`. One entry per discrete user action
(insert/delete/drag/toggle/input blur). Typing inside a contenteditable only snapshots on
blur, not per-keystroke. `HISTORY_MAX = 50`. Undo/redo replay via `innerHTML`
replacement → `refreshInserts()` + `rebindShells()` (re-attaches combobox controllers
and other JS bindings).

Keyboard: `Ctrl/Cmd+Z` → undo; `Ctrl/Cmd+Shift+Z` or `Ctrl/Cmd+Y` → redo. Scoped to
the card's editor section so two open editors don't fight.

## Sticky hover state

JS-managed `.is-active` on the field shell under the cursor (or its 10 px buffer). The
buffer keeps the "+" insert pill at its expanded state while the user reaches for it
without a timed delay. Driven by `mouseover` / `mousemove` / `mouseleave` on the form.

## Drag-and-drop

- Mousedown anywhere except `.lrob-etk-form-overlay-handle` flips `draggable="false"` on
  all ancestor `[data-draggable-type]` elements so text selection inside inputs can't
  accidentally start an HTML5 drag. Restored on `mouseup`.
- `dragstart`: sets drag image explicitly BEFORE class mutations (defer rAF) to avoid
  the "drag image invalid, drag aborted" browser bug when a transition is in flight.
- A single-field, single-column row: dragging the field substitutes the whole row as the
  drag source (so the user moves the row, not just a bare field).
- `pickDropHover()` collapses any hover inside the dragged item's own row to the source
  row itself (forces above/below direction, no degenerate "new col where source col just
  emptied" reshuffles). Snap-to-col: when the cursor is in the middle vertical band of a
  row, picks the column target whose midX is just above the cursor.
- Drop on a `col` target (field or row drag): inserts the payload as a new column.
- Drop on a `row` target (field drag): wraps field in a new single-column row.
- `cleanupEmptyCol()`: after a field is dragged out, if its column is now empty — remove
  it. If it was the last column, remove the whole row.

