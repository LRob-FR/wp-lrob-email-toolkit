<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms;

use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\Captcha\Routing;
use LRob\EmailToolkit\Plugin;

// Docs: docs/forms.md
final class CaptchaField implements FieldTypeInterface
{
    public function __construct(
        private string $context,
        private string $meta_key,
        private string $label_text = ''
    ) {
    }

    public function slug(): string
    {
        return 'captcha';
    }

    public function label(): string
    {
        return $this->label_text !== ''
            ? $this->label_text
            : __('Anti-spam challenge', 'lrob-email-toolkit');
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

        $service = self::captcha_service();
        if ($service === null) {
            return '';
        }
        $inner = $service->render([
            'context'     => $this->context,
            'form_id'     => $form_id,
            'force_route' => $this->effective_route($form_id),
        ]);
        if ($inner === '') {
            return '';
        }
        return sprintf(
            '<div class="lrob-etk-form-captcha-align is-align-%s">%s</div>',
            esc_attr($align),
            $inner
        );
    }

    private function effective_route(int $form_id): string
    {
        $per_form = (string) get_post_meta($form_id, $this->meta_key, true);
        if ($per_form === '' || $per_form === 'default') {
            return '';
        }
        return $per_form;
    }

    private function editor_stub(int $form_id, string $align): string
    {
        $service = self::captcha_service();
        $stored = (string) get_post_meta($form_id, $this->meta_key, true);
        $preview_html = $this->preview_html($stored, $service, $form_id);

        $default_label = $this->default_label($service);
        $default_option_label = $default_label !== ''
            ? sprintf(
                /* translators: %s: what "Default" resolves to — a picker option name (e.g. "Math question") or the fallback email address; shown as "Default (X)" */
                __('Default (%s)', 'lrob-email-toolkit'),
                $default_label
            )
            : __('Default', 'lrob-email-toolkit');

        $options_html = $this->options_html($stored, $service, $default_option_label);

        return sprintf(
            '<div class="lrob-etk-form-field lrob-etk-form-field--captcha is-editor-stub is-align-%5$s" data-captcha-block>' .
            '<div class="lrob-etk-form-captcha-stub-head">' .
                '<span class="lrob-etk-form-captcha-stub-icon dashicons dashicons-shield" aria-hidden="true"></span>' .
                '<label class="lrob-etk-form-captcha-stub-label">%1$s</label>' .
                '<select class="lrob-etk-form-field lrob-etk-form-captcha-pick" name="%2$s" data-key="%2$s" data-captcha-pick>%3$s</select>' .
            '</div>' .
            '<div class="lrob-etk-form-captcha-stub-preview" data-captcha-preview>%4$s</div>' .
            '</div>',
            esc_html__('Anti-spam:', 'lrob-email-toolkit'),
            esc_attr($this->meta_key),
            $options_html,
            $preview_html,
            esc_attr($align)
        );
    }

    private function options_html(string $stored, ?CaptchaService $service, string $default_option_label): string
    {
        $html = '<option value=""' . selected($stored, '', false) . '>' . esc_html($default_option_label) . '</option>'
              . '<option value="' . esc_attr(Routing::ROUTE_NONE) . '"' . selected($stored, Routing::ROUTE_NONE, false) . '>'
              . esc_html__('None', 'lrob-email-toolkit')
              . '</option>';

        if ($service === null) {
            return $html;
        }

        $builtin_prefix = __('Built-in', 'lrob-email-toolkit');
        foreach ($service->homemade_challenges() as $slug => $challenge) {
            $route = Routing::homemade($slug);
            $label = sprintf('%s: %s', $builtin_prefix, (string) $challenge->label());
            $html .= '<option value="' . esc_attr($route) . '"' . selected($stored, $route, false) . '>'
                   . esc_html($label) . '</option>';
        }

        $providers = $service->hosted_providers();
        if ($providers !== []) {
            $by_provider = [];
            foreach ($service->identity_repository()->all() as $identity) {
                $by_provider[$identity->provider_slug][] = $identity;
            }
            foreach ($providers as $provider_slug => $provider) {
                $rows = $by_provider[$provider_slug] ?? [];
                if ($rows === []) {
                    $html .= '<option value="" disabled>'
                           . esc_html(sprintf(
                               /* translators: %s: provider label (e.g. hCaptcha) */
                               __('— Configure %s first —', 'lrob-email-toolkit'),
                               $provider->label()
                           ))
                           . '</option>';
                    continue;
                }
                foreach ($rows as $identity) {
                    $route = Routing::identity((int) $identity->id);
                    $name = $identity->label !== '' ? $identity->label : (string) $provider->label();
                    $label = sprintf('%s: %s', $provider->label(), $name);
                    if (!$identity->is_active) {
                        $label .= ' ' . __('(inactive)', 'lrob-email-toolkit');
                    }
                    $disabled = $identity->is_active ? '' : ' disabled';
                    $html .= '<option value="' . esc_attr($route) . '"'
                           . selected($stored, $route, false) . $disabled . '>'
                           . esc_html($label) . '</option>';
                }
            }
        }

        return $html;
    }

    private function default_label(?CaptchaService $service): string
    {
        if ($service === null) {
            return '';
        }
        [$challenge, ] = $service->resolve(['context' => $this->context]);
        if ($challenge === null) {
            return __('None', 'lrob-email-toolkit');
        }
        return $challenge->label();
    }

    private function preview_html(string $stored, ?CaptchaService $service, int $form_id): string
    {
        if ($stored === Routing::ROUTE_NONE) {
            return '<p class="lrob-etk-form-captcha-stub-empty">' . esc_html__('No anti-spam challenge.', 'lrob-email-toolkit') . '</p>';
        }
        if ($service === null) {
            return '<p class="lrob-etk-form-captcha-stub-empty">' . esc_html__('No challenge registered.', 'lrob-email-toolkit') . '</p>';
        }
        $context = ['context' => 'preview', 'form_id' => $form_id, 'preview_call' => true];
        if ($stored !== '') {
            $context['force_route'] = $stored;
        } else {
            $context['force_route'] = $this->context_default_route($service);
        }
        $html = $service->render($context);
        if ($html === '') {
            return '<p class="lrob-etk-form-captcha-stub-empty">' . esc_html__('No challenge to preview.', 'lrob-email-toolkit') . '</p>';
        }
        return $html;
    }

    private function context_default_route(CaptchaService $service): string
    {
        $map = get_option(Routing::OPTION_CONTEXT_MAP, []);
        if (!is_array($map)) {
            return '';
        }
        $route = isset($map[$this->context]) ? (string) $map[$this->context] : Routing::ROUTE_INHERIT;
        if ($route === Routing::ROUTE_INHERIT) {
            $route = isset($map[Routing::KEY_DEFAULT]) ? (string) $map[Routing::KEY_DEFAULT] : '';
        }
        return $route;
    }

    /**
     * @return array{entries: array<int, array{route:string, label:string, preview:string, optgroup?:string, disabled?:bool}>}
     */
    public static function build_editor_options(string $context, ?CaptchaService $service): array
    {
        $entries = [];
        $default_route_label = __('Default', 'lrob-email-toolkit');
        $none_preview = '<p class="lrob-etk-form-captcha-stub-empty">' . esc_html__('No anti-spam challenge.', 'lrob-email-toolkit') . '</p>';
        $default_preview = $none_preview;

        if ($service !== null) {
            [$default_challenge, $default_credentials] = $service->resolve(['context' => $context]);
            if ($default_challenge !== null) {
                $default_route_label = sprintf(
                    /* translators: %s: what "Default" resolves to — a picker option name (e.g. "Math question") or the fallback email address; shown as "Default (X)" */
                    __('Default (%s)', 'lrob-email-toolkit'),
                    $default_challenge->label()
                );
                $default_preview = $default_challenge->render([
                    'context'     => 'preview',
                    'credentials' => $default_credentials,
                ]);
            } else {
                $default_route_label = __('Default (none)', 'lrob-email-toolkit');
            }
        }

        $entries[] = ['route' => '',                 'label' => $default_route_label,                          'preview' => $default_preview];
        $entries[] = ['route' => Routing::ROUTE_NONE, 'label' => __('None', 'lrob-email-toolkit'),               'preview' => $none_preview];

        if ($service === null) {
            return ['entries' => $entries];
        }

        $builtin_prefix = __('Built-in', 'lrob-email-toolkit');
        foreach ($service->homemade_challenges() as $slug => $challenge) {
            $entries[] = [
                'route'   => Routing::homemade($slug),
                'label'   => sprintf('%s: %s', $builtin_prefix, (string) $challenge->label()),
                'preview' => $challenge->render(['context' => 'preview']),
            ];
        }

        $providers = $service->hosted_providers();
        if ($providers !== []) {
            $by_provider = [];
            foreach ($service->identity_repository()->all() as $identity) {
                $by_provider[$identity->provider_slug][] = $identity;
            }
            foreach ($providers as $provider_slug => $provider) {
                $rows = $by_provider[$provider_slug] ?? [];
                if ($rows === []) {
                    $entries[] = [
                        'route'    => '',
                        'label'    => sprintf(
                            /* translators: %s: provider label (e.g. hCaptcha) */
                            __('— Configure %s first —', 'lrob-email-toolkit'),
                            $provider->label()
                        ),
                        'preview'  => $none_preview,
                        'disabled' => true,
                    ];
                    continue;
                }
                foreach ($rows as $identity) {
                    $name = $identity->label !== '' ? $identity->label : (string) $provider->label();
                    $label = sprintf('%s: %s', $provider->label(), $name);
                    if (!$identity->is_active) {
                        $label .= ' ' . __('(inactive)', 'lrob-email-toolkit');
                    }
                    $credentials = [];
                    if ($identity->is_active && method_exists($identity, 'decrypted_credentials')) {
                        try {
                            $credentials = $identity->decrypted_credentials();
                        } catch (\Throwable) {
                            $credentials = [];
                        }
                    }
                    $preview = $provider->render([
                        'context'     => 'preview',
                        'credentials' => $credentials,
                    ]);
                    $entries[] = [
                        'route'    => Routing::identity((int) $identity->id),
                        'label'    => $label,
                        'preview'  => $preview,
                        'disabled' => !$identity->is_active,
                    ];
                }
            }
        }

        return ['entries' => $entries];
    }

    private static function captcha_service(): ?CaptchaService
    {
        $container = Plugin::instance()->container();
        $service = $container->get(CaptchaService::class);
        return $service instanceof CaptchaService ? $service : null;
    }
}
