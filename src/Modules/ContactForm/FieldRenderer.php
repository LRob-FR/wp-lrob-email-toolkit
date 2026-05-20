<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Plugin;

/**
 * Renders each field block on the frontend. Called from the registered
 * block render_callbacks via Blocks::dispatch_field_render(). Outputs
 * nothing when FormContext is inactive (i.e. when a field block was
 * somehow rendered outside an embed-block-driven form).
 *
 * Every method takes the block's $attrs and returns final HTML — no echo.
 * Markup conventions:
 *   - Root: &lt;div class="lrob-etk-cf-field lrob-etk-cf-field--{type}" data-field="{slug}"&gt;
 *   - Label: &lt;label for=…&gt; for single-input fields, &lt;span class="lrob-etk-cf-label"&gt; for option groups
 *   - Helper: &lt;p class="lrob-etk-cf-helper"&gt;
 *   - Error placeholder: &lt;p class="lrob-etk-cf-error" data-field-error&gt; (filled by JS)
 */
final class FieldRenderer
{
    public static function text(array $attrs): string
    {
        return self::render_input('text', $attrs);
    }

    public static function email(array $attrs): string
    {
        return self::render_input('email', $attrs);
    }

    public static function number(array $attrs): string
    {
        return self::render_input('number', $attrs, [
            'min'  => $attrs['min'] ?? null,
            'max'  => $attrs['max'] ?? null,
            'step' => $attrs['step'] ?? null,
        ]);
    }

    public static function phone(array $attrs): string
    {
        return self::render_input('tel', $attrs, [
            'inputmode' => 'tel',
            'pattern'   => isset($attrs['pattern']) && is_string($attrs['pattern']) && $attrs['pattern'] !== '' ? $attrs['pattern'] : null,
        ]);
    }

    public static function date(array $attrs): string
    {
        return self::render_input('date', $attrs, [
            'min' => $attrs['min'] ?? null,
            'max' => $attrs['max'] ?? null,
        ]);
    }

    public static function textarea(array $attrs): string
    {
        if (!FormContext::is_active()) {
            return '';
        }
        $slug = self::normalize_slug($attrs);
        if ($slug === '') {
            return '';
        }
        $label = isset($attrs['label']) ? (string) $attrs['label'] : '';
        $helper = isset($attrs['helper']) ? (string) $attrs['helper'] : '';
        $placeholder = isset($attrs['placeholder']) ? (string) $attrs['placeholder'] : '';
        $required = !empty($attrs['required']);
        $rows = isset($attrs['rows']) ? max(2, (int) $attrs['rows']) : 5;
        $max = isset($attrs['maxLength']) ? (int) $attrs['maxLength'] : 0;

        $id = FormContext::field_id($slug);
        $name = FormContext::field_name($slug);

        $attrs_html = sprintf(
            ' id="%s" name="%s" rows="%d"%s%s%s',
            esc_attr($id),
            esc_attr($name),
            $rows,
            $placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '',
            $max > 0 ? ' maxlength="' . $max . '"' : '',
            $required ? ' required aria-required="true"' : ''
        );

        return self::wrap_field(
            'textarea',
            $slug,
            $label,
            $helper,
            $required,
            '<textarea' . $attrs_html . '></textarea>',
            $id
        );
    }

    public static function select(array $attrs): string
    {
        if (!FormContext::is_active()) {
            return '';
        }
        $slug = self::normalize_slug($attrs);
        if ($slug === '') {
            return '';
        }
        $label = isset($attrs['label']) ? (string) $attrs['label'] : '';
        $helper = isset($attrs['helper']) ? (string) $attrs['helper'] : '';
        $placeholder = isset($attrs['placeholder']) ? (string) $attrs['placeholder'] : '';
        $required = !empty($attrs['required']);
        $options = self::normalize_options($attrs['options'] ?? []);
        $defaults = isset($attrs['defaults']) && is_array($attrs['defaults'])
            ? array_map('strval', $attrs['defaults'])
            : [];

        $id = FormContext::field_id($slug);
        $name = FormContext::field_name($slug);

        // Resolve which option (if any) starts selected. The placeholder
        // option is ALWAYS emitted so the user can pick "nothing" to clear
        // any default — selecting it from the dropdown amounts to "no
        // default chosen".
        $selected_value = '';
        foreach ($defaults as $v) {
            $exists = false;
            foreach ($options as $opt) {
                if ((string) $opt['value'] === $v) { $exists = true; break; }
            }
            if ($exists) { $selected_value = $v; break; }
        }

        $opts_html = sprintf(
            '<option value=""%s>%s</option>',
            $selected_value === '' ? ' selected' : '',
            esc_html($placeholder !== '' ? $placeholder : '— select —')
        );
        foreach ($options as $opt) {
            $opts_html .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($opt['value']),
                ((string) $opt['value']) === $selected_value ? ' selected' : '',
                esc_html($opt['label'])
            );
        }

        $control = sprintf(
            '<select id="%s" name="%s"%s>%s</select>',
            esc_attr($id),
            esc_attr($name),
            $required ? ' required aria-required="true"' : '',
            $opts_html
        );

        return self::wrap_field('select', $slug, $label, $helper, $required, $control, $id);
    }

    public static function radio(array $attrs): string
    {
        return self::render_option_group('radio', $attrs);
    }

    public static function checkbox(array $attrs): string
    {
        $multiple = !isset($attrs['multiple']) || !empty($attrs['multiple']);
        if (!$multiple) {
            // Single checkbox: treat as a single boolean field with the label inline.
            if (!FormContext::is_active()) {
                return '';
            }
            $slug = self::normalize_slug($attrs);
            if ($slug === '') {
                return '';
            }
            $label = isset($attrs['label']) ? (string) $attrs['label'] : '';
            $helper = isset($attrs['helper']) ? (string) $attrs['helper'] : '';
            $required = !empty($attrs['required']);
            $id = FormContext::field_id($slug);
            $name = FormContext::field_name($slug);

            $control = sprintf(
                '<label class="lrob-etk-cf-checkbox-inline" for="%s"><input type="checkbox" id="%s" name="%s" value="1"%s><span>%s%s</span></label>',
                esc_attr($id),
                esc_attr($id),
                esc_attr($name),
                $required ? ' required aria-required="true"' : '',
                esc_html($label),
                $required ? ' <span class="lrob-etk-cf-required" aria-hidden="true">*</span>' : ''
            );

            $helper_html = $helper !== '' ? '<p class="lrob-etk-cf-helper">' . esc_html($helper) . '</p>' : '';
            $error_html = '<p class="lrob-etk-cf-error" data-field-error hidden></p>';
            return sprintf(
                '<div class="lrob-etk-cf-field lrob-etk-cf-field--checkbox lrob-etk-cf-field--checkbox-single" data-field="%s">%s%s%s</div>',
                esc_attr($slug),
                $control,
                $helper_html,
                $error_html
            );
        }
        return self::render_option_group('checkbox', $attrs);
    }

    public static function submit(array $attrs): string
    {
        if (!FormContext::is_active()) {
            return '';
        }
        $text = isset($attrs['text']) && is_string($attrs['text']) && $attrs['text'] !== ''
            ? (string) $attrs['text']
            : __('Send', 'lrob-email-toolkit');
        $align = isset($attrs['align']) && in_array($attrs['align'], ['left', 'center', 'right', 'stretch'], true)
            ? (string) $attrs['align']
            : 'right';

        // Editor mode: don't actually submit anything (the admin "form" is a
        // <div>, not a <form>) and make the button label inline-editable.
        if (FormContext::is_editor()) {
            return sprintf(
                '<div class="lrob-etk-cf-field lrob-etk-cf-field--submit is-align-%s"><button type="button" class="lrob-etk-cf-submit"><span class="lrob-etk-cf-submit-label" contenteditable="plaintext-only" data-edit="submit-text">%s</span></button></div>',
                esc_attr($align),
                esc_html($text)
            );
        }

        return sprintf(
            '<div class="lrob-etk-cf-field lrob-etk-cf-field--submit is-align-%s"><button type="submit" class="lrob-etk-cf-submit"><span class="lrob-etk-cf-submit-label">%s</span><span class="lrob-etk-cf-submit-spinner" aria-hidden="true"></span></button></div>',
            esc_attr($align),
            esc_html($text)
        );
    }

    /**
     * Captcha slot. Frontend delegates to the Captcha module's service
     * (which picks the active challenge); editor renders a non-interactive
     * stub so the user sees where it'll sit. The per-form "challenge"
     * setting only flips this slot on/off — choice of challenge is global.
     */
    public static function captcha(array $attrs): string
    {
        unset($attrs);
        if (!FormContext::is_active()) {
            return '';
        }
        $form_id = FormContext::form_id();
        $enabled = Settings::effective_challenge($form_id) !== CPT::CHALLENGE_NONE;

        if (FormContext::is_editor()) {
            return self::captcha_editor_stub($form_id);
        }

        if (!$enabled) {
            return '';
        }
        $service = self::captcha_service();
        if ($service === null) {
            return '';
        }
        // If the per-form setting is a specific challenge slug (not "none"
        // and not empty/inherit), force the captcha service to use that
        // exact challenge instead of its global active one.
        $stored = Settings::effective_challenge($form_id);
        $force = ($stored !== '' && $stored !== CPT::CHALLENGE_NONE) ? $stored : '';
        return $service->render([
            'context'    => 'contact_form',
            'form_id'    => $form_id,
            'force_slug' => $force,
        ]);
    }

    private static function captcha_service(): ?CaptchaService
    {
        $container = Plugin::instance()->container();
        return $container->has(CaptchaService::class) ? $container->get(CaptchaService::class) : null;
    }

    /**
     * In-block captcha picker for the WYSIWYG editor: lets the user pick
     * the challenge (or none) right where the captcha will appear, with
     * a live description below. The `data-key` matches the per-form
     * `_lrob_etk_cf_challenge` meta so the existing card auto-save catches
     * it — same wire the Advanced > Challenge dropdown uses.
     */
    private static function captcha_editor_stub(int $form_id): string
    {
        $service = self::captcha_service();
        $available = $service !== null ? $service->available() : [];
        $stored = (string) get_post_meta($form_id, CPT::META_CHALLENGE_KIND, true);

        // Resolve the preview HTML for whatever the current setting is.
        // The select swaps this client-side on change.
        $preview_html = self::captcha_preview_html($stored, $service, $available, $form_id);

        $options_html = '<option value="">' . esc_html__('Form default', 'lrob-email-toolkit') . '</option>'
                      . '<option value="' . esc_attr(CPT::CHALLENGE_NONE) . '"' . selected($stored, CPT::CHALLENGE_NONE, false) . '>'
                      . esc_html__('None', 'lrob-email-toolkit')
                      . '</option>';
        foreach ($available as $slug => $challenge) {
            $options_html .= '<option value="' . esc_attr($slug) . '"' . selected($stored, $slug, false) . '>'
                           . esc_html($challenge->label())
                           . '</option>';
        }

        return sprintf(
            '<div class="lrob-etk-cf-field lrob-etk-cf-field--captcha is-editor-stub" data-captcha-block>' .
            '<div class="lrob-etk-cf-captcha-stub-head">' .
                '<span class="lrob-etk-cf-captcha-stub-icon dashicons dashicons-shield" aria-hidden="true"></span>' .
                '<label class="lrob-etk-cf-captcha-stub-label">%1$s</label>' .
                '<select class="lrob-etk-cf-field lrob-etk-cf-captcha-pick" name="%2$s" data-key="%2$s" data-captcha-pick>%3$s</select>' .
            '</div>' .
            '<div class="lrob-etk-cf-captcha-stub-preview" data-captcha-preview>%4$s</div>' .
            '</div>',
            esc_html__('Anti-spam:', 'lrob-email-toolkit'),
            esc_attr(CPT::META_CHALLENGE_KIND),
            $options_html,
            // The preview HTML is already the challenge's own escaped
            // output; safe to embed directly.
            $preview_html
        );
    }

    /**
     * Build the HTML shown in the captcha preview area for a given
     * stored value. Used on initial render — the editor JS has its own
     * preview swap once the user changes the select.
     *
     * @param array<string, \LRob\EmailToolkit\Modules\Captcha\Challenges\ChallengeInterface> $available
     */
    private static function captcha_preview_html(string $stored, ?CaptchaService $service, array $available, int $form_id): string
    {
        if ($stored === CPT::CHALLENGE_NONE) {
            return '<p class="lrob-etk-cf-captcha-stub-empty">' . esc_html__('No anti-spam challenge.', 'lrob-email-toolkit') . '</p>';
        }
        if ($stored !== '' && isset($available[$stored])) {
            return $available[$stored]->render(['context' => 'preview']);
        }
        // Empty or legacy: render the form's effective default.
        $effective = Settings::effective_challenge($form_id);
        if ($effective === CPT::CHALLENGE_NONE) {
            return '<p class="lrob-etk-cf-captcha-stub-empty">' . esc_html__('Form default: no anti-spam challenge.', 'lrob-email-toolkit') . '</p>';
        }
        if ($service !== null && isset($available[$effective])) {
            return $available[$effective]->render(['context' => 'preview']);
        }
        if ($service !== null && $service->active() !== null) {
            return $service->active()->render(['context' => 'preview']);
        }
        return '<p class="lrob-etk-cf-captcha-stub-empty">' . esc_html__('No challenge registered.', 'lrob-email-toolkit') . '</p>';
    }

    /**
     * Shared renderer for plain &lt;input&gt; field types.
     *
     * @param array<string, mixed> $attrs
     * @param array<string, scalar|null> $extra Additional HTML attributes (null values are dropped).
     */
    private static function render_input(string $input_type, array $attrs, array $extra = []): string
    {
        if (!FormContext::is_active()) {
            return '';
        }
        $slug = self::normalize_slug($attrs);
        if ($slug === '') {
            return '';
        }
        $label = isset($attrs['label']) ? (string) $attrs['label'] : '';
        $helper = isset($attrs['helper']) ? (string) $attrs['helper'] : '';
        $placeholder = isset($attrs['placeholder']) ? (string) $attrs['placeholder'] : '';
        $required = !empty($attrs['required']);
        $max = isset($attrs['maxLength']) ? (int) $attrs['maxLength'] : 0;
        $autocomplete = isset($attrs['autocomplete']) && is_string($attrs['autocomplete']) ? (string) $attrs['autocomplete'] : self::guess_autocomplete($input_type, $slug);

        $id = FormContext::field_id($slug);
        $name = FormContext::field_name($slug);

        $extra_html = '';
        foreach ($extra as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $extra_html .= ' ' . $k . '="' . esc_attr((string) $v) . '"';
        }

        $control = sprintf(
            '<input type="%s" id="%s" name="%s"%s%s%s%s%s>',
            esc_attr($input_type),
            esc_attr($id),
            esc_attr($name),
            $placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '',
            $max > 0 ? ' maxlength="' . $max . '"' : '',
            $required ? ' required aria-required="true"' : '',
            $autocomplete !== '' ? ' autocomplete="' . esc_attr($autocomplete) . '"' : '',
            $extra_html
        );

        return self::wrap_field($input_type, $slug, $label, $helper, $required, $control, $id);
    }

    /**
     * Renders radio / multi-checkbox: a labelled group of input+label pairs.
     *
     * @param array<string, mixed> $attrs
     */
    private static function render_option_group(string $kind, array $attrs): string
    {
        if (!FormContext::is_active()) {
            return '';
        }
        $slug = self::normalize_slug($attrs);
        if ($slug === '') {
            return '';
        }
        $label = isset($attrs['label']) ? (string) $attrs['label'] : '';
        $helper = isset($attrs['helper']) ? (string) $attrs['helper'] : '';
        $required = !empty($attrs['required']);
        $options = self::normalize_options($attrs['options'] ?? []);

        if ($options === []) {
            return '';
        }

        $input_type = $kind === 'radio' ? 'radio' : 'checkbox';
        $name = FormContext::field_name($slug, $kind === 'checkbox');
        $group_id = FormContext::field_id($slug);

        $items = '';
        foreach ($options as $idx => $opt) {
            $opt_id = $group_id . '-' . $idx;
            $items .= sprintf(
                '<label class="lrob-etk-cf-option" for="%s"><input type="%s" id="%s" name="%s" value="%s"%s><span>%s</span></label>',
                esc_attr($opt_id),
                $input_type,
                esc_attr($opt_id),
                esc_attr($name),
                esc_attr($opt['value']),
                $required && $kind === 'radio' && $idx === 0 ? ' required aria-required="true"' : '',
                esc_html($opt['label'])
            );
        }

        $helper_html = self::helper_html($helper);
        $error_html = '<p class="lrob-etk-cf-error" data-field-error hidden></p>';

        // Same editor treatment as wrap_field: the legend's text is wrapped
        // in a contenteditable span so the user can rename the group inline,
        // while the required toggle (editor) or asterisk (frontend) stays
        // outside the editable region.
        if (FormContext::is_editor()) {
            $shown = $label !== '' ? esc_html($label) : '<span class="lrob-etk-cf-label-empty">' . esc_html__('(field label)', 'lrob-email-toolkit') . '</span>';
            $legend_inner = sprintf(
                '<span class="lrob-etk-cf-label-text" contenteditable="plaintext-only" data-edit="label" spellcheck="false">%s</span>%s',
                $shown,
                self::required_toggle_html($required)
            );
        } else {
            $required_marker = $required ? ' <span class="lrob-etk-cf-required" aria-hidden="true">*</span>' : '';
            $legend_inner = esc_html($label) . $required_marker;
        }

        return sprintf(
            '<fieldset class="lrob-etk-cf-field lrob-etk-cf-field--%s" data-field="%s" id="%s"><legend class="lrob-etk-cf-label">%s</legend><div class="lrob-etk-cf-options">%s</div>%s%s</fieldset>',
            $kind,
            esc_attr($slug),
            esc_attr($group_id),
            $legend_inner,
            $items,
            $helper_html,
            $error_html
        );
    }

    /** Wrap a single-control field (text/email/textarea/select/etc.) with label + helper + error markup. */
    private static function wrap_field(
        string $type_class,
        string $slug,
        string $label,
        string $helper,
        bool $required,
        string $control_html,
        string $control_id
    ): string {
        $label_html = $label !== '' || FormContext::is_editor()
            ? self::label_html($label, $required, $control_id)
            : '';
        $helper_html = self::helper_html($helper);
        $error_html = '<p class="lrob-etk-cf-error" data-field-error hidden></p>';

        return sprintf(
            '<div class="lrob-etk-cf-field lrob-etk-cf-field--%s" data-field="%s">%s%s%s%s</div>',
            esc_attr($type_class),
            esc_attr($slug),
            $label_html,
            $control_html,
            $helper_html,
            $error_html
        );
    }

    /**
     * Render a field label. In editor mode the text portion is wrapped in
     * an inline-editable span and the wrapper is a <div> (no `for` attr) —
     * otherwise the browser's label-for-input behavior steals clicks from
     * the contenteditable span and redirects focus to the input below.
     * The required asterisk in editor mode is a toggle button so the user
     * can flip required on/off without opening the gear popup.
     */
    private static function label_html(string $label, bool $required, string $for_id): string
    {
        if (FormContext::is_editor()) {
            $shown = $label !== '' ? esc_html($label) : '<span class="lrob-etk-cf-label-empty">' . esc_html__('(field label)', 'lrob-email-toolkit') . '</span>';
            return sprintf(
                '<div class="lrob-etk-cf-label"><span class="lrob-etk-cf-label-text" contenteditable="plaintext-only" data-edit="label" spellcheck="false">%s</span>%s</div>',
                $shown,
                self::required_toggle_html($required)
            );
        }
        $required_marker = $required ? ' <span class="lrob-etk-cf-required" aria-hidden="true">*</span>' : '';
        return sprintf(
            '<label class="lrob-etk-cf-label" for="%s">%s%s</label>',
            esc_attr($for_id),
            esc_html($label),
            $required_marker
        );
    }

    /**
     * Editor-only: paired star + checkbox marker. The star is a pure
     * visual indicator of the current required state (red when on, dim
     * otherwise) and is hidden whenever the shell is hovered/active;
     * the checkbox with its "Required" label takes over in that state
     * and is the only interactive part — the star itself is not
     * clickable, so users get an obvious labelled control instead of a
     * tiny asterisk to guess at.
     */
    private static function required_toggle_html(bool $required): string
    {
        return sprintf(
            '<span class="lrob-etk-cf-required-control">'
            . '<span class="lrob-etk-cf-required-star%1$s" aria-hidden="true">*</span>'
            . '<label class="lrob-etk-cf-required-check">'
            . '<input type="checkbox" data-required-toggle%2$s>'
            . '<span>%3$s</span>'
            . '</label>'
            . '</span>',
            $required ? ' is-on' : '',
            $required ? ' checked' : '',
            esc_html__('Required', 'lrob-email-toolkit')
        );
    }

    /** Helper text below a field; in editor mode it's inline-editable. */
    private static function helper_html(string $helper): string
    {
        if (FormContext::is_editor()) {
            $shown = $helper !== '' ? esc_html($helper) : esc_html__('(optional helper text)', 'lrob-email-toolkit');
            $cls = $helper !== '' ? 'lrob-etk-cf-helper' : 'lrob-etk-cf-helper lrob-etk-cf-helper-empty';
            return sprintf(
                '<p class="%s" contenteditable="plaintext-only" data-edit="helper" spellcheck="false">%s</p>',
                esc_attr($cls),
                $shown
            );
        }
        return $helper !== '' ? '<p class="lrob-etk-cf-helper">' . esc_html($helper) . '</p>' : '';
    }

    private static function normalize_slug(array $attrs): string
    {
        $raw = isset($attrs['slug']) && is_string($attrs['slug']) ? (string) $attrs['slug'] : '';
        return sanitize_key($raw);
    }

    /**
     * Coerce options into a list of [value, label] pairs. Accepts either
     * the block-attribute shape (objects with value/label) or a plain list
     * of strings.
     *
     * @param mixed $raw
     * @return array<int, array{value:string, label:string}>
     */
    private static function normalize_options(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $value = isset($item['value']) && is_scalar($item['value']) ? (string) $item['value'] : '';
                $label = isset($item['label']) && is_scalar($item['label']) ? (string) $item['label'] : $value;
                if ($label === '' && $value === '') {
                    continue;
                }
                if ($value === '') {
                    $value = sanitize_title($label);
                }
                $out[] = ['value' => $value, 'label' => $label];
                continue;
            }
            if (is_string($item) && $item !== '') {
                $out[] = ['value' => sanitize_title($item), 'label' => $item];
            }
        }
        return $out;
    }

    /** Guess a sensible browser autocomplete value for common field slugs. */
    private static function guess_autocomplete(string $input_type, string $slug): string
    {
        if ($input_type === 'email') {
            return 'email';
        }
        if ($input_type === 'tel') {
            return 'tel';
        }
        $map = [
            'name'      => 'name',
            'firstname' => 'given-name',
            'lastname'  => 'family-name',
            'phone'     => 'tel',
            'company'   => 'organization',
            'website'   => 'url',
            'url'       => 'url',
            'city'      => 'address-level2',
            'country'   => 'country-name',
            'zip'       => 'postal-code',
            'postcode'  => 'postal-code',
        ];
        return $map[$slug] ?? '';
    }
}
