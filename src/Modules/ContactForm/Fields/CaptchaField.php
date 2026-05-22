<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Fields;

use LRob\EmailToolkit\Forms\FieldTypeInterface;
use LRob\EmailToolkit\Forms\FormContext;
use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\ContactForm\CPT;
use LRob\EmailToolkit\Modules\ContactForm\Settings;
use LRob\EmailToolkit\Plugin;

/**
 * Contact Form's captcha field type. Renders the active challenge for the
 * `contact_form` Captcha-routing context, with per-form override stored in
 * post meta (CPT::META_CHALLENGE_KIND). In editor mode it renders an
 * inline picker so the admin can change the routing right inside the field
 * preview.
 *
 * Newsletter will register its own captcha field type for its CPT, using
 * the `newsletter_subscribe` context — same idea, different module-specific
 * routing and meta keys.
 */
final class CaptchaField implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'captcha';
    }

    public function label(): string
    {
        return __('Anti-spam challenge', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $align = isset($field['align']) ? (string) $field['align'] : 'center';
        if (!in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'center';
        }
        return [
            'id'    => isset($field['id']) && is_string($field['id']) && $field['id'] !== ''
                ? sanitize_key($field['id'])
                : 'f_' . substr(bin2hex(random_bytes(4)), 0, 8),
            'type'  => $this->slug(),
            'nth'   => isset($field['nth']) ? max(0, (int) $field['nth']) : 0,
            'align' => $align,
        ];
    }

    public function render(array $attrs): string
    {
        $align = isset($attrs['align']) && in_array($attrs['align'], ['left', 'center', 'right'], true)
            ? (string) $attrs['align']
            : 'center';

        if (!FormContext::is_active()) {
            return '';
        }
        $form_id = FormContext::form_id();

        if (FormContext::is_editor()) {
            return $this->editor_stub($form_id, $align);
        }

        $service = $this->captcha_service();
        if ($service === null) {
            return '';
        }
        $inner = $service->render([
            'context'     => 'contact_form',
            'form_id'     => $form_id,
            'force_route' => Settings::effective_routing_key($form_id),
        ]);
        if ($inner === '') {
            return '';
        }
        // Outer wrapper carries the alignment class so any challenge (homemade
        // or hosted) gets positioned consistently. No "stretch" — hCaptcha's
        // iframe is fixed-width.
        return sprintf(
            '<div class="lrob-etk-cf-captcha-align is-align-%s">%s</div>',
            esc_attr($align),
            $inner
        );
    }

    private function captcha_service(): ?CaptchaService
    {
        $container = Plugin::instance()->container();
        $service = $container->get(CaptchaService::class);
        return $service instanceof CaptchaService ? $service : null;
    }

    /**
     * In-block captcha picker for the WYSIWYG editor: lets the user pick
     * the challenge (or none) right where the captcha will appear, with a
     * live preview below. The `data-key` matches the per-form
     * `_lrob_etk_cf_challenge` meta so the existing card auto-save picks
     * it up — same wire the Advanced > Challenge dropdown uses.
     */
    private function editor_stub(int $form_id, string $align = 'center'): string
    {
        $service = $this->captcha_service();
        $stored = (string) get_post_meta($form_id, CPT::META_CHALLENGE_KIND, true);
        $preview_html = $this->preview_html($stored, $service, $form_id);

        $default_label = $this->default_label($service);
        $default_option_label = $default_label !== ''
            ? sprintf(
                /* translators: %s: name of the value "Default" resolves to (e.g. "Math question"), shown as "Default (X)" in pickers and dropdown labels */
                __('Default (%s)', 'lrob-email-toolkit'),
                $default_label
            )
            : __('Default', 'lrob-email-toolkit');

        $options_html = $this->options_html($stored, $service, $default_option_label);

        return sprintf(
            '<div class="lrob-etk-form-field lrob-etk-form-field--captcha is-editor-stub is-align-%5$s" data-captcha-block>' .
            '<div class="lrob-etk-cf-captcha-stub-head">' .
                '<span class="lrob-etk-cf-captcha-stub-icon dashicons dashicons-shield" aria-hidden="true"></span>' .
                '<label class="lrob-etk-cf-captcha-stub-label">%1$s</label>' .
                '<select class="lrob-etk-form-field lrob-etk-cf-captcha-pick" name="%2$s" data-key="%2$s" data-captcha-pick>%3$s</select>' .
            '</div>' .
            '<div class="lrob-etk-cf-captcha-stub-preview" data-captcha-preview>%4$s</div>' .
            '</div>',
            esc_html__('Anti-spam:', 'lrob-email-toolkit'),
            esc_attr(CPT::META_CHALLENGE_KIND),
            $options_html,
            $preview_html,
            esc_attr($align)
        );
    }

    /**
     * `<option>` HTML for the captcha picker. Three groups in order:
     * special routes ("Default", "None"), homemade challenges, and one
     * optgroup per hosted provider listing its identities.
     */
    private function options_html(string $stored, ?CaptchaService $service, string $default_option_label): string
    {
        $html = '<option value=""' . selected($stored, '', false) . '>' . esc_html($default_option_label) . '</option>'
              . '<option value="' . esc_attr(CPT::CHALLENGE_NONE) . '"' . selected($stored, CPT::CHALLENGE_NONE, false) . '>'
              . esc_html__('None', 'lrob-email-toolkit')
              . '</option>';

        if ($service === null) {
            return $html;
        }

        $homemade = $service->homemade_challenges();
        if ($homemade !== []) {
            $html .= '<optgroup label="' . esc_attr__('Built-in challenges', 'lrob-email-toolkit') . '">';
            foreach ($homemade as $slug => $challenge) {
                $route = \LRob\EmailToolkit\Modules\Captcha\Routing::homemade($slug);
                $html .= '<option value="' . esc_attr($route) . '"' . selected($stored, $route, false) . '>'
                       . esc_html($challenge->label()) . '</option>';
            }
            $html .= '</optgroup>';
        }

        // Per-provider optgroups. Empty providers surface as disabled
        // "Configure first" options so the form admin knows the option
        // exists but knows where to set it up.
        $providers = $service->hosted_providers();
        if ($providers !== []) {
            $by_provider = [];
            foreach ($service->identity_repository()->all() as $identity) {
                $by_provider[$identity->provider_slug][] = $identity;
            }
            foreach ($providers as $provider_slug => $provider) {
                $rows = isset($by_provider[$provider_slug]) ? $by_provider[$provider_slug] : [];
                $html .= '<optgroup label="' . esc_attr($provider->label()) . '">';
                if ($rows === []) {
                    $html .= '<option value="" disabled>'
                           . esc_html(sprintf(
                               /* translators: %s: provider label (e.g. hCaptcha) */
                               __('— Configure %s first —', 'lrob-email-toolkit'),
                               $provider->label()
                           ))
                           . '</option>';
                } else {
                    foreach ($rows as $identity) {
                        $route = \LRob\EmailToolkit\Modules\Captcha\Routing::identity((int) $identity->id);
                        $label = $identity->label !== '' ? $identity->label : $provider->label();
                        $disabled = $identity->is_active ? '' : ' disabled';
                        if (!$identity->is_active) {
                            $label .= ' ' . __('(inactive)', 'lrob-email-toolkit');
                        }
                        $html .= '<option value="' . esc_attr($route) . '"'
                               . selected($stored, $route, false) . $disabled . '>'
                               . esc_html($label) . '</option>';
                    }
                }
                $html .= '</optgroup>';
            }
        }

        return $html;
    }

    /**
     * Label shown next to "Default" in the picker — surfaces what the
     * Captcha module's contact_form context currently resolves to so the
     * admin can see at a glance what "Default" means right now.
     */
    private function default_label(?CaptchaService $service): string
    {
        if ($service === null) {
            return '';
        }
        [$challenge, ] = $service->resolve(['context' => 'contact_form']);
        if ($challenge === null) {
            return __('None', 'lrob-email-toolkit');
        }
        return $challenge->label();
    }

    /**
     * HTML shown in the captcha preview area for a given stored routing
     * key. The editor JS swaps this client-side once the user changes the
     * picker. Identity routes need credentials to render their real
     * widget — preview context falls back to a placeholder for hosted
     * providers since we don't want to hit vendor JS from the admin editor.
     */
    private function preview_html(string $stored, ?CaptchaService $service, int $form_id): string
    {
        if ($stored === CPT::CHALLENGE_NONE) {
            return '<p class="lrob-etk-cf-captcha-stub-empty">' . esc_html__('No anti-spam challenge.', 'lrob-email-toolkit') . '</p>';
        }
        if ($service === null) {
            return '<p class="lrob-etk-cf-captcha-stub-empty">' . esc_html__('No challenge registered.', 'lrob-email-toolkit') . '</p>';
        }
        // Empty stored value = inherit; resolve against the contact_form
        // context so the preview shows what visitors will actually see.
        $context = ['context' => 'contact_form', 'form_id' => $form_id, 'preview_call' => true];
        if ($stored !== '') {
            $context['force_route'] = $stored;
        }
        // Use render() so identity credentials are injected; force the
        // preview sub-context so providers can render a placeholder
        // instead of fetching their vendor JS.
        $context['context'] = 'preview';
        $html = $service->render($context);
        if ($html === '') {
            $context['context'] = 'contact_form';
            return $service->render($context);
        }
        return $html;
    }
}
