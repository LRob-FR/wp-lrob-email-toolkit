<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\ModuleToggle;
use LRob\EmailToolkit\Admin\Tooltip;
use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\ContactForm\CPT;
use LRob\EmailToolkit\Modules\ContactForm\FormEditorRenderer;
use LRob\EmailToolkit\Modules\ContactForm\FormStructure;
use LRob\EmailToolkit\Modules\ContactForm\Module as ContactFormModule;
use LRob\EmailToolkit\Modules\ContactForm\Settings;
use LRob\EmailToolkit\Modules\ContactForm\SubmissionRepository;
use LRob\EmailToolkit\Modules\ContactForm\TemplateRegistry;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Plugin;

/**
 * Unified Contact Form admin page: module toggle, list of forms (cards),
 * and global defaults grouped on a single screen. Replaces both the
 * separate "Add New" submenu and the standalone defaults page; users edit
 * a form by clicking it (which opens Gutenberg), create a new one via the
 * header button, and tweak global defaults in the section below the list.
 *
 * Matches the SMTP page chrome: `.lrob-etk-page-header` with inline module
 * toggle, `.lrob-etk-identities` card grid for entities, and
 * `.lrob-etk-modal-columns` for multi-column settings — no full-width
 * stretches.
 */
final class FormsPage
{
    public const SLUG = 'lrob-etk-cform';

    public const ACTION_DELETE_FORM = 'lrob_etk_cform_delete';

    public function __construct(private ContactFormModule $module)
    {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_DELETE_FORM, [$this, 'handle_delete_form']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if (!str_contains($hook_suffix, self::SLUG)) {
            return;
        }

        // Reuse the FRONTEND form CSS in the admin so the editor preview
        // looks identical to what visitors see. No parallel preview stylesheet.
        wp_enqueue_style(
            'lrob-etk-cf-frontend',
            LROB_ETK_URL . 'assets/css/contact-form.css',
            [],
            self::asset_version('assets/css/contact-form.css')
        );

        // The shared combobox (lrob-etk-controls) is enqueued plugin-wide
        // via Admin\Assets, so we only need our auto-save script here.
        wp_enqueue_script(
            'lrob-etk-cf-admin',
            LROB_ETK_URL . 'admin/js/contact-form-admin.js',
            ['lrob-etk-controls'],
            self::asset_version('admin/js/contact-form-admin.js'),
            true
        );
        wp_localize_script('lrob-etk-cf-admin', 'lrobEtkCfAdmin', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce(AjaxController::NONCE_ACTION),
            'action'        => AjaxController::ACTION_SAVE_META,
            'actionDefault' => AjaxController::ACTION_SAVE_DEFAULT,
            'knownEmails'   => self::known_email_suggestions(),
            'i18n'          => [
                'saving'        => __('Saving…', 'lrob-email-toolkit'),
                'saved'         => __('Saved', 'lrob-email-toolkit'),
                'error'         => __('Save failed', 'lrob-email-toolkit'),
                'addRecipient'  => __('Add recipient', 'lrob-email-toolkit'),
                'removeRow'     => __('Remove this recipient', 'lrob-email-toolkit'),
                'pickKnown'     => __('Pick a known email', 'lrob-email-toolkit'),
                'recipientPh'   => __('email@example.com', 'lrob-email-toolkit'),
            ],
        ]);

        // WYSIWYG fields editor — hover overlays, contenteditable handlers,
        // gear popup, "+" insertion zones, drag-drop, serializer.
        wp_enqueue_script(
            'lrob-etk-cf-fields-editor',
            LROB_ETK_URL . 'admin/js/contact-form-fields-editor.js',
            ['lrob-etk-cf-admin'],
            self::asset_version('admin/js/contact-form-fields-editor.js'),
            true
        );
        // "Start a new form" picker modal.
        wp_enqueue_script(
            'lrob-etk-cf-new-picker',
            LROB_ETK_URL . 'admin/js/contact-form-new-picker.js',
            [],
            self::asset_version('admin/js/contact-form-new-picker.js'),
            true
        );
        wp_localize_script('lrob-etk-cf-new-picker', 'lrobEtkCfNewPicker', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => AjaxController::ACTION_CREATE_FORM,
            'nonce'   => wp_create_nonce(AjaxController::NONCE_ACTION),
            'pageUrl' => admin_url('admin.php?page=' . self::SLUG),
        ]);

        // Pass the captcha picker's option list to the editor JS so the
        // in-block picker can build itself on field insert + swap the
        // preview HTML on change. Shape mirrors what FieldRenderer's
        // server-side picker emits (routing keys + optgroups + previews),
        // so the freshly-inserted captcha block looks identical to a
        // page-reloaded one.
        $captcha_service = self::captcha_service();
        $captcha_options = self::build_editor_captcha_options($captcha_service);
        wp_localize_script('lrob-etk-cf-fields-editor', 'lrobEtkCfEditor', [
            'fieldTypes'     => self::field_types(),
            'captchaKey'     => CPT::META_CHALLENGE_KIND,
            'captchaOptions' => $captcha_options,
            'i18n'        => [
                'addField'     => __('Add field', 'lrob-email-toolkit'),
                'fieldOptions' => __('Field options', 'lrob-email-toolkit'),
                'slug'         => __('Field slug', 'lrob-email-toolkit'),
                'helper'       => __('Helper text', 'lrob-email-toolkit'),
                'required'     => __('Required', 'lrob-email-toolkit'),
                'placeholder'  => __('Placeholder', 'lrob-email-toolkit'),
                'maxLength'    => __('Max length', 'lrob-email-toolkit'),
                'rows'         => __('Rows', 'lrob-email-toolkit'),
                'min'          => __('Min', 'lrob-email-toolkit'),
                'max'          => __('Max', 'lrob-email-toolkit'),
                'step'         => __('Step', 'lrob-email-toolkit'),
                'pattern'      => __('Regex pattern', 'lrob-email-toolkit'),
                'options'      => __('Options', 'lrob-email-toolkit'),
                'addOption'    => __('Add option', 'lrob-email-toolkit'),
                'multiple'     => __('Multiple choices', 'lrob-email-toolkit'),
                'alignment'    => __('Alignment', 'lrob-email-toolkit'),
                'alignLeft'    => __('Left', 'lrob-email-toolkit'),
                'alignCenter'  => __('Center', 'lrob-email-toolkit'),
                'alignRight'   => __('Right', 'lrob-email-toolkit'),
                'alignStretch' => __('Full width', 'lrob-email-toolkit'),
                'toggleRequired'    => __('Toggle required', 'lrob-email-toolkit'),
                'helperPlaceholder' => __('(optional helper text)', 'lrob-email-toolkit'),
                'labelPlaceholder'  => __('(field label)', 'lrob-email-toolkit'),
                'undo'              => __('Undo', 'lrob-email-toolkit'),
                'redo'              => __('Redo', 'lrob-email-toolkit'),
                'fieldLabel'        => __('Field', 'lrob-email-toolkit'),
                'captchaPick'       => __('Anti-spam challenge', 'lrob-email-toolkit'),
                'captchaDefault'    => __('Default', 'lrob-email-toolkit'),
                'captchaNone'       => __('None — no anti-spam challenge', 'lrob-email-toolkit'),
                'captchaInherit'    => __('Uses the form\'s default challenge (set in Advanced settings).', 'lrob-email-toolkit'),
                'captchaOff'        => __('No anti-spam challenge will be shown to visitors.', 'lrob-email-toolkit'),
                'optionLabel'       => __('Option', 'lrob-email-toolkit'),
                'removeOption'      => __('Remove option', 'lrob-email-toolkit'),
                'setAsDefault'      => __('Use as default', 'lrob-email-toolkit'),
                'unsetDefault'      => __('Remove as default', 'lrob-email-toolkit'),
                'placeholderText'   => __('Placeholder text', 'lrob-email-toolkit'),
                'singleCheckboxHint'=> __('Single checkbox — no options needed.', 'lrob-email-toolkit'),
            ],
            // Shown in the per-select inline placeholder combobox. Leaving
            // the value empty falls back to "— select —" at render time, so
            // the dropdown never silently swallows the user's "no default"
            // intent.
            'placeholderPresets' => [
                ['value' => '— select —',     'label' => '— select —'],
                ['value' => 'Choose one…',    'label' => 'Choose one…'],
                ['value' => 'Pick one…',      'label' => 'Pick one…'],
                ['value' => 'Please choose…', 'label' => 'Please choose…'],
            ],
        ]);
    }

    private static function asset_version(string $relative): string
    {
        $version = LROB_ETK_VERSION;
        $full = LROB_ETK_PATH . ltrim($relative, '/');
        if (is_file($full)) {
            $version .= '.' . filemtime($full);
        }
        return $version;
    }

    /**
     * Render the shared `.lrob-etk-combo` combobox in fixed-value mode.
     * Hidden input carries the actual value and gets the `lrob-etk-cf-field`
     * class so the auto-save picks up its `change` event.
     *
     * @param array<int, array{value:string|int, label:string}> $options
     */
    /** Sentinel stored in reply_to_field when the form explicitly opts OUT of any Reply-To header. */
    public const REPLY_TO_NONE = '__none__';

    private static function captcha_service(): ?CaptchaService
    {
        $container = Plugin::instance()->container();
        return $container->has(CaptchaService::class) ? $container->get(CaptchaService::class) : null;
    }

    /**
     * Build the captcha picker's option list for the WYSIWYG editor JS.
     * Same shape the in-block picker (`FieldRenderer::captcha_options_html`)
     * builds on the server, but as structured data so the editor can
     * rebuild the picker after inserting / swapping captcha blocks.
     *
     * @return array<string, mixed>
     */
    private static function build_editor_captcha_options(?CaptchaService $captcha_service): array
    {
        $entries = [];
        $default_route_label = __('Default', 'lrob-email-toolkit');
        $none_preview = '<p class="lrob-etk-cf-captcha-stub-empty">' . esc_html__('No anti-spam challenge.', 'lrob-email-toolkit') . '</p>';
        $default_preview = $none_preview;

        if ($captcha_service !== null) {
            // "Default" route resolves the contact_form context — surface
            // its current target label + preview HTML so the picker can
            // render "Default (Math question)" and show the live preview.
            // Pass credentials through so identity-backed defaults (e.g.
            // hCaptcha) render their actual widget, not the empty-key
            // placeholder.
            [$default_challenge, $default_credentials] = $captcha_service->resolve(['context' => 'contact_form']);
            if ($default_challenge !== null) {
                $default_route_label = sprintf(
                    /* translators: %s: name of the challenge "Default" resolves to (e.g. Math question) */
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

        $entries[] = ['route' => '', 'label' => $default_route_label, 'preview' => $default_preview];
        $entries[] = ['route' => CPT::CHALLENGE_NONE, 'label' => __('None', 'lrob-email-toolkit'), 'preview' => $none_preview];

        if ($captcha_service === null) {
            return ['entries' => $entries];
        }

        foreach ($captcha_service->homemade_challenges() as $slug => $challenge) {
            $entries[] = [
                'route'    => \LRob\EmailToolkit\Modules\Captcha\Routing::homemade($slug),
                'label'    => $challenge->label(),
                'preview'  => $challenge->render(['context' => 'preview']),
                'optgroup' => __('Built-in challenges', 'lrob-email-toolkit'),
            ];
        }

        $providers = $captcha_service->hosted_providers();
        if ($providers !== []) {
            $by_provider = [];
            foreach ($captcha_service->identity_repository()->all() as $identity) {
                $by_provider[$identity->provider_slug][] = $identity;
            }
            foreach ($providers as $provider_slug => $provider) {
                $rows = isset($by_provider[$provider_slug]) ? $by_provider[$provider_slug] : [];
                if ($rows === []) {
                    $entries[] = [
                        'route'    => '',
                        'label'    => sprintf(
                            /* translators: %s: provider label */
                            __('— Configure %s first —', 'lrob-email-toolkit'),
                            $provider->label()
                        ),
                        'preview'  => $none_preview,
                        'optgroup' => $provider->label(),
                        'disabled' => true,
                    ];
                    continue;
                }
                foreach ($rows as $identity) {
                    $label = $identity->label !== '' ? $identity->label : $provider->label();
                    if (!$identity->is_active) {
                        $label .= ' ' . __('(inactive)', 'lrob-email-toolkit');
                    }
                    // Hosted providers render their real widget on-page;
                    // for the editor preview we just show a placeholder
                    // (preview context already triggers this in HCaptcha).
                    $preview = $provider->render([
                        'context'     => 'preview',
                        'credentials' => $identity->is_active && method_exists($identity, 'decrypted_credentials') ? (function () use ($identity) {
                            try { return $identity->decrypted_credentials(); } catch (\Throwable) { return []; }
                        })() : [],
                    ]);
                    $entries[] = [
                        'route'    => \LRob\EmailToolkit\Modules\Captcha\Routing::identity((int) $identity->id),
                        'label'    => $label,
                        'preview'  => $preview,
                        'optgroup' => $provider->label(),
                        'disabled' => !$identity->is_active,
                    ];
                }
            }
        }

        return ['entries' => $entries];
    }

    /**
     * Walk a form's structure and return the slugs of every email-type
     * field. Used by render_form_card to populate the Reply-To picker.
     *
     * @param array<string, mixed> $structure
     * @return array<int, string>
     */
    private static function email_field_slugs(array $structure): array
    {
        $slugs = [];
        if (!isset($structure['rows']) || !is_array($structure['rows'])) {
            return $slugs;
        }
        foreach ($structure['rows'] as $row) {
            if (!isset($row['columns']) || !is_array($row['columns'])) {
                continue;
            }
            foreach ($row['columns'] as $col) {
                if (!isset($col['fields']) || !is_array($col['fields'])) {
                    continue;
                }
                foreach ($col['fields'] as $field) {
                    if (($field['type'] ?? '') === 'email' && isset($field['slug']) && is_string($field['slug']) && $field['slug'] !== '') {
                        $slugs[] = $field['slug'];
                    }
                }
            }
        }
        return array_values(array_unique($slugs));
    }

    /**
     * Known-email suggestions shown in the recipient-row chevron menu:
     * admin email, current user email, other administrators. De-duplicated,
     * stable order: admin first, current user second (if different), other
     * admins after.
     *
     * @return array<int, array{value:string, label:string}>
     */
    private static function known_email_suggestions(): array
    {
        $out = [];
        $seen = [];
        $admin = (string) get_option('admin_email');
        if ($admin !== '' && is_email($admin)) {
            $out[] = [
                'value' => $admin,
                'label' => sprintf(
                    /* translators: %s: admin email */
                    __('Site admin (%s)', 'lrob-email-toolkit'),
                    $admin
                ),
            ];
            $seen[$admin] = true;
        }
        $current_user = wp_get_current_user();
        if ($current_user instanceof \WP_User && $current_user->user_email !== '' && empty($seen[$current_user->user_email])) {
            $out[] = [
                'value' => $current_user->user_email,
                'label' => sprintf(
                    /* translators: %s: current user email */
                    __('Me (%s)', 'lrob-email-toolkit'),
                    $current_user->user_email
                ),
            ];
            $seen[$current_user->user_email] = true;
        }
        $admins = get_users([
            'role'   => 'administrator',
            'fields' => ['user_email', 'display_name'],
        ]);
        foreach ($admins as $admin_user) {
            $email = (string) $admin_user->user_email;
            if ($email === '' || !is_email($email) || isset($seen[$email])) {
                continue;
            }
            $out[] = [
                'value' => $email,
                'label' => sprintf(
                    /* translators: 1: admin display name, 2: admin email */
                    __('%1$s (%2$s)', 'lrob-email-toolkit'),
                    (string) $admin_user->display_name,
                    $email
                ),
            ];
            $seen[$email] = true;
        }
        return $out;
    }

    /**
     * Render the recipients control: stacked rows (one email each) with a
     * "+ Add recipient" trigger, plus a hidden mirror input that carries
     * the canonical comma-separated value to the auto-save layer. The
     * chevron button on each row pops a small menu of known emails (admin,
     * current user, other admins) — populated client-side from
     * lrobEtkCfAdmin.knownEmails so we don't render the same list 20× when
     * 20 forms share the page.
     */
    private static function render_recipients(string $current_value, string $placeholder, string $key = CPT::META_RECIPIENT): void
    {
        $emails = array_filter(array_map('trim', explode(',', $current_value)));
        if ($emails === []) {
            $emails = [''];
        }
        ?>
        <div class="lrob-etk-cf-recipients" data-recipient-input>
            <input type="hidden"
                   name="<?php echo esc_attr($key); ?>"
                   class="lrob-etk-cf-field"
                   data-key="<?php echo esc_attr($key); ?>"
                   value="<?php echo esc_attr($current_value); ?>">
            <div class="lrob-etk-cf-recipients-rows" data-recipient-rows>
                <?php foreach ($emails as $i => $email) : ?>
                    <div class="lrob-etk-cf-recipient-row">
                        <input type="email"
                               class="lrob-etk-cf-recipient-input"
                               value="<?php echo esc_attr((string) $email); ?>"
                               placeholder="<?php echo esc_attr($placeholder); ?>"
                               autocomplete="off">
                        <button type="button" class="lrob-etk-cf-recipient-pick" aria-label="<?php esc_attr_e('Pick a known email', 'lrob-email-toolkit'); ?>" title="<?php esc_attr_e('Pick a known email', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        </button>
                        <button type="button" class="lrob-etk-cf-recipient-remove" aria-label="<?php esc_attr_e('Remove this recipient', 'lrob-email-toolkit'); ?>" title="<?php esc_attr_e('Remove', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button button-small lrob-etk-cf-recipient-add" data-recipient-add>
                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                <?php esc_html_e('Add recipient', 'lrob-email-toolkit'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Each setting picks its own "inherit" sentinel: most use '' (empty),
     * SMTP identity uses '0', Honeypot uses 'default'. Pass that sentinel
     * as $inherit_value so the picker knows which option's label should
     * become the muted placeholder and when to leave the readonly input
     * empty. This kills the "some defaults are greyed, some aren't"
     * inconsistency the user flagged.
     */
    /**
     * Free-text combobox: the input is editable AND a chevron opens a list
     * of suggestions (typically the inheritable default value). Same shape
     * as the SMTP host / from-email comboboxes — wired up by the auto-init
     * in contact-form-admin.js once the card mounts.
     *
     * @param array<int, array{value:string, label:string}> $suggestions
     */
    private static function render_free_combobox(string $meta_key, string $current_value, array $suggestions, string $placeholder = ''): void
    {
        ?>
        <div class="lrob-etk-combo lrob-etk-cf-free-combo"
             data-options="<?php echo esc_attr((string) wp_json_encode($suggestions)); ?>">
            <input type="text"
                   name="<?php echo esc_attr($meta_key); ?>"
                   class="lrob-etk-combo-input lrob-etk-cf-field"
                   data-key="<?php echo esc_attr($meta_key); ?>"
                   value="<?php echo esc_attr($current_value); ?>"
                   placeholder="<?php echo esc_attr($placeholder); ?>"
                   autocomplete="off">
            <button type="button" class="lrob-etk-combo-toggle" tabindex="-1"
                    aria-label="<?php esc_attr_e('Open suggestions', 'lrob-email-toolkit'); ?>">
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
            <ul class="lrob-etk-combo-menu" role="listbox" hidden></ul>
        </div>
        <?php
    }

    private static function render_combobox(string $meta_key, string $current_value, array $options, string $inherit_value = ''): void
    {
        $default_label = '';
        $current_label = '';
        foreach ($options as $opt) {
            if ((string) $opt['value'] === $inherit_value) {
                $default_label = (string) $opt['label'];
            }
            if ((string) $opt['value'] === (string) $current_value) {
                $current_label = (string) $opt['label'];
            }
        }
        $is_inheriting = ((string) $current_value === $inherit_value);
        $input_value = $is_inheriting ? '' : $current_label;
        $placeholder = $default_label;

        $name = $meta_key;
        $combo_id = 'lrob-etk-cf-combo-' . md5($meta_key . wp_generate_uuid4());
        ?>
        <div class="lrob-etk-combo lrob-etk-combo--select"
             data-options="<?php echo esc_attr((string) wp_json_encode($options)); ?>"
             data-inherit-value="<?php echo esc_attr($inherit_value); ?>"
             data-default-placeholder="<?php echo esc_attr($placeholder); ?>">
            <input type="text"
                   id="<?php echo esc_attr($combo_id); ?>"
                   class="lrob-etk-combo-input"
                   value="<?php echo esc_attr($input_value); ?>"
                   placeholder="<?php echo esc_attr($placeholder); ?>"
                   readonly
                   autocomplete="off">
            <button type="button" class="lrob-etk-combo-toggle" tabindex="-1"
                    aria-label="<?php esc_attr_e('Open options', 'lrob-email-toolkit'); ?>">
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
            <ul class="lrob-etk-combo-menu" role="listbox" hidden></ul>
            <input type="hidden"
                   name="<?php echo esc_attr($name); ?>"
                   class="lrob-etk-combo-value lrob-etk-cf-field"
                   data-key="<?php echo esc_attr($meta_key); ?>"
                   value="<?php echo esc_attr((string) $current_value); ?>">
        </div>
        <?php
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }

        $enabled = $this->module->is_enabled();
        ?>
        <div class="wrap lrob-etk lrob-etk-cform-page">
            <header class="lrob-etk-page-header">
                <h1 class="lrob-etk-page-title"><?php esc_html_e('Contact Forms', 'lrob-email-toolkit'); ?></h1>
                <?php ModuleToggle::render_inline($this->module); ?>
                <?php if ($enabled) : ?>
                    <div class="lrob-etk-page-header-actions">
                        <button type="button" class="button" id="lrob-etk-cf-defaults-btn" data-defaults-modal-open>
                            <span class="dashicons dashicons-admin-generic"></span>
                            <?php esc_html_e('Default settings', 'lrob-email-toolkit'); ?>
                        </button>
                        <button type="button" class="button button-primary" id="lrob-etk-cf-new-form-btn">
                            <span class="dashicons dashicons-plus-alt2"></span>
                            <?php esc_html_e('New form', 'lrob-email-toolkit'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </header>

            <?php if ($enabled) : ?>
                <?php self::render_new_form_picker(); ?>
            <?php endif; ?>

            <?php $this->render_flash(); ?>

            <?php if (!$enabled) : ?>
                <p class="lrob-etk-disabled-message">
                    <?php esc_html_e('Enable the Contact Form module to start building forms.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else : ?>
                <?php $this->render_forms_section(); ?>
                <?php $this->render_defaults_modal(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_flash(): void
    {
        $saved = isset($_GET['saved']) && (string) $_GET['saved'] === '1';
        $deleted = isset($_GET['deleted']) && (string) $_GET['deleted'] === '1';
        if (!$saved && !$deleted) {
            return;
        }
        ?>
        <div class="lrob-etk-flash is-success" role="status">
            <?php
            if ($saved) {
                esc_html_e('Defaults saved.', 'lrob-email-toolkit');
            } elseif ($deleted) {
                esc_html_e('Form deleted.', 'lrob-email-toolkit');
            }
            ?>
        </div>
        <?php
    }

    private function render_forms_section(): void
    {
        $forms = self::fetch_forms();
        ?>
        <h2 class="lrob-etk-section-title"><?php esc_html_e('Your contact forms', 'lrob-email-toolkit'); ?></h2>

        <?php if ($forms === []) : ?>
            <div class="lrob-etk-cf-onboard">
                <div class="lrob-etk-cf-onboard-icon dashicons dashicons-feedback" aria-hidden="true"></div>
                <h3 class="lrob-etk-cf-onboard-title"><?php esc_html_e('Create my first contact form', 'lrob-email-toolkit'); ?></h3>
                <p class="lrob-etk-cf-onboard-text">
                    <?php esc_html_e('Pick a starter template or build from scratch. Stacked anti-spam is on by default.', 'lrob-email-toolkit'); ?>
                </p>
                <button type="button" class="button button-primary button-hero" id="lrob-etk-cf-new-form-btn-empty">
                    <?php esc_html_e('Create a contact form', 'lrob-email-toolkit'); ?>
                    <span aria-hidden="true">→</span>
                </button>
            </div>
        <?php else : ?>
            <?php
            $identities = self::active_identities();
            $globals = Settings::all();
            ?>
            <div class="lrob-etk-identities lrob-etk-cf-forms-grid">
                <?php foreach ($forms as $form) : ?>
                    <?php $this->render_form_card($form, $identities, $globals); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Interactive form card. Every input auto-saves on blur/change via the
     * AjaxController endpoint. Per-form sentinel values ('', 0, 'default')
     * mean "inherit the global default" — placeholders / "Default" options
     * make that explicit. Edit mode (Gutenberg) is reserved for the form's
     * field layout; settings live exclusively here.
     *
     * @param array{id:int, title:string, status:string, created:string, submissions:int} $form
     * @param array<int, array{id:int, label:string, is_default:bool}> $identities
     * @param array<string, mixed> $globals
     */
    private function render_form_card(array $form, array $identities, array $globals): void
    {
        $form_id = (int) $form['id'];
        $edit_url = admin_url('post.php?action=edit&post=' . $form_id);
        $delete_url = wp_nonce_url(
            add_query_arg(
                ['action' => self::ACTION_DELETE_FORM, 'form_id' => $form_id],
                admin_url('admin-post.php')
            ),
            self::ACTION_DELETE_FORM . '_' . $form_id,
            '_lrob_etk_nonce'
        );
        $created_display = $form['created'] !== ''
            ? mysql2date(get_option('date_format'), $form['created'])
            : '';
        $title = $form['title'];

        // Raw per-form values (sentinels included) — the JS auto-save sends
        // these back as-is. Display-time fallback to globals is purely for
        // showing placeholders / "Default (X)" labels.
        $meta = [
            'recipient'    => (string) get_post_meta($form_id, CPT::META_RECIPIENT, true),
            'identity_id'  => (int) get_post_meta($form_id, CPT::META_RECIPIENT_IDENTITY, true),
            'reply_to'     => (string) get_post_meta($form_id, CPT::META_REPLY_TO_FIELD, true),
            'subject'      => (string) get_post_meta($form_id, CPT::META_SUBJECT_TEMPLATE, true),
            'success'      => (string) get_post_meta($form_id, CPT::META_SUCCESS_MESSAGE, true),
            'rate_max'     => (int) get_post_meta($form_id, CPT::META_RATE_LIMIT_MAX, true),
            'rate_window'  => (int) get_post_meta($form_id, CPT::META_RATE_LIMIT_WINDOW, true),
            'honeypot'     => (string) get_post_meta($form_id, CPT::META_HONEYPOT_ENABLED, true),
            'challenge'    => (string) get_post_meta($form_id, CPT::META_CHALLENGE_KIND, true),
            'style_preset' => (string) get_post_meta($form_id, CPT::META_STYLE_PRESET, true),
        ];
        $rate_window_minutes_value = $meta['rate_window'] > 0 ? (int) round($meta['rate_window'] / 60) : 0;

        $default_identity_label = self::default_identity_label($identities);
        $default_identity_text = $default_identity_label !== ''
            ? self::placeholder_default($default_identity_label)
            : __('Default — SMTP routing', 'lrob-email-toolkit');

        $globals_recipient = (string) ($globals[Settings::KEY_RECIPIENT] ?? '');
        $admin_email = (string) get_option('admin_email');
        $effective_recipient = $globals_recipient !== '' ? $globals_recipient : $admin_email;
        $recipient_placeholder = self::placeholder_default($effective_recipient);
        $no_recipient_anywhere = $meta['recipient'] === '' && $globals_recipient === '' && $admin_email === '';

        $form_structure = FormStructure::load($form_id);
        $form_email_slugs = self::email_field_slugs($form_structure);
        $global_reply_slug = (string) ($globals[Settings::KEY_REPLY_TO_FIELD] ?? '');
        // Pretty label for the inherited default: show the actual slug, or
        // "(none)" when the global setting opts out, or "(first email field)"
        // when the global is empty but this form has email fields.
        if ($global_reply_slug === self::REPLY_TO_NONE) {
            $reply_to_default_label = __('None — no Reply-To header', 'lrob-email-toolkit');
        } elseif ($global_reply_slug !== '' && in_array($global_reply_slug, $form_email_slugs, true)) {
            $reply_to_default_label = $global_reply_slug;
        } elseif ($form_email_slugs !== []) {
            $reply_to_default_label = sprintf(
                /* translators: %s: form field slug used as the auto-default. */
                __('Auto (%s — first email field)', 'lrob-email-toolkit'),
                $form_email_slugs[0]
            );
        } else {
            $reply_to_default_label = __('No email field yet', 'lrob-email-toolkit');
        }

        $subject_default = (string) ($globals[Settings::KEY_SUBJECT_TEMPLATE] ?? '');
        if ($subject_default === '') {
            $subject_default = __('[Site] New submission from {title}', 'lrob-email-toolkit');
        }
        $subject_placeholder = self::placeholder_default($subject_default);

        $success_default = (string) ($globals[Settings::KEY_SUCCESS_MESSAGE] ?? '');
        if ($success_default === '') {
            $success_default = __('Thanks! Your message has been sent.', 'lrob-email-toolkit');
        }
        $success_placeholder = self::placeholder_default($success_default);

        // Build the option lists for every fixed-value combobox up front, so
        // the markup below stays declarative.
        $identity_options = [
            ['value' => '0', 'label' => $default_identity_text],
        ];
        foreach ($identities as $identity) {
            if ($identity['is_default']) {
                continue;
            }
            $identity_options[] = ['value' => (string) $identity['id'], 'label' => $identity['label']];
        }

        $hp_default_label = !empty($globals[Settings::KEY_HONEYPOT])
            ? __('On', 'lrob-email-toolkit')
            : __('Off', 'lrob-email-toolkit');
        $hp_value = $meta['honeypot'] !== '' ? $meta['honeypot'] : 'default';
        $honeypot_options = [
            ['value' => 'default', 'label' => self::label_default($hp_default_label)],
            ['value' => 'on',      'label' => __('On',  'lrob-email-toolkit')],
            ['value' => 'off',     'label' => __('Off', 'lrob-email-toolkit')],
        ];

        // Per-form captcha picker — same routing-key options the in-block
        // editor picker uses, surfaced as combobox entries with optgroup
        // hints baked into the label. Lets a form override the Captcha
        // module's contact_form context for this one form.
        $captcha_service = self::captcha_service();
        [$ch_default_challenge, ] = $captcha_service !== null
            ? $captcha_service->resolve(['context' => 'contact_form'])
            : [null, []];
        $ch_default_label = $ch_default_challenge !== null
            ? $ch_default_challenge->label()
            : __('None', 'lrob-email-toolkit');
        $challenge_options = [
            ['value' => '',                  'label' => self::label_default($ch_default_label)],
            ['value' => CPT::CHALLENGE_NONE, 'label' => __('None', 'lrob-email-toolkit')],
        ];
        if ($captcha_service !== null) {
            foreach ($captcha_service->homemade_challenges() as $slug => $challenge) {
                $challenge_options[] = [
                    'value' => \LRob\EmailToolkit\Modules\Captcha\Routing::homemade($slug),
                    'label' => $challenge->label(),
                ];
            }
            $by_provider = [];
            foreach ($captcha_service->identity_repository()->all() as $identity) {
                $by_provider[$identity->provider_slug][] = $identity;
            }
            foreach ($captcha_service->hosted_providers() as $provider_slug => $provider) {
                $rows = isset($by_provider[$provider_slug]) ? $by_provider[$provider_slug] : [];
                foreach ($rows as $identity) {
                    if (!$identity->is_active) {
                        continue;
                    }
                    $label = $identity->label !== '' ? $identity->label : $provider->label();
                    $challenge_options[] = [
                        'value' => \LRob\EmailToolkit\Modules\Captcha\Routing::identity((int) $identity->id),
                        'label' => $provider->label() . ' · ' . $label,
                    ];
                }
            }
        }

        $reply_to_options = [
            ['value' => '',                   'label' => self::label_default($reply_to_default_label)],
            ['value' => self::REPLY_TO_NONE,  'label' => __('None — no Reply-To header', 'lrob-email-toolkit')],
        ];
        foreach ($form_email_slugs as $slug) {
            $reply_to_options[] = ['value' => $slug, 'label' => $slug];
        }

        $preset_labels = self::style_presets();
        $preset_default = (string) ($globals[Settings::KEY_STYLE_PRESET] ?? CPT::STYLE_DEFAULT);
        $preset_default_label = $preset_labels[$preset_default] ?? $preset_labels[CPT::STYLE_DEFAULT];
        $preset_options = [
            ['value' => '', 'label' => self::label_default($preset_default_label)],
        ];
        foreach ($preset_labels as $value => $label) {
            $preset_options[] = ['value' => $value, 'label' => $label];
        }
        ?>
        <article class="lrob-etk-identity-card lrob-etk-cf-form-card" data-form-id="<?php echo $form_id; ?>">
            <form class="lrob-etk-card-form" novalidate onsubmit="return false">
                <header class="lrob-etk-card-form-head">
                    <input
                        type="text"
                        name="title"
                        class="lrob-etk-title-input lrob-etk-cf-field"
                        data-key="title"
                        value="<?php echo esc_attr($title); ?>"
                        placeholder="<?php esc_attr_e('Form name', 'lrob-email-toolkit'); ?>"
                        autocomplete="off">
                    <?php if ($form['status'] === 'draft') : ?>
                        <span class="lrob-etk-status lrob-etk-status--pending"><?php esc_html_e('Draft', 'lrob-email-toolkit'); ?></span>
                    <?php endif; ?>
                    <span class="lrob-etk-card-status" aria-live="polite"></span>
                </header>

                <section class="lrob-etk-cf-essentials">
                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('Recipients', 'lrob-email-toolkit'); ?></label>
                        <?php if ($no_recipient_anywhere) : ?>
                            <div class="lrob-etk-cf-warning" role="status">
                                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                <span><?php esc_html_e('No recipient is set anywhere — submissions will only be saved on this site, no email will be sent. Add at least one recipient below, or set a global default.', 'lrob-email-toolkit'); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php self::render_recipients($meta['recipient'], $recipient_placeholder); ?>
                    </div>
                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('SMTP identity', 'lrob-email-toolkit'); ?></label>
                        <?php self::render_combobox(CPT::META_RECIPIENT_IDENTITY, (string) $meta['identity_id'], $identity_options, '0'); ?>
                    </div>
                </section>

                <section class="lrob-etk-cf-style-group">
                    <h3 class="lrob-etk-cf-section-title"><?php esc_html_e('Style', 'lrob-email-toolkit'); ?></h3>
                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('Preset', 'lrob-email-toolkit'); ?></label>
                        <?php self::render_combobox(CPT::META_STYLE_PRESET, $meta['style_preset'], $preset_options); ?>
                    </div>
                </section>

                <?php self::render_fields_editor($form_id); ?>

                <details class="lrob-etk-cf-advanced">
                    <summary class="lrob-etk-cf-advanced-summary">
                        <span class="lrob-etk-cf-advanced-caret" aria-hidden="true">▸</span>
                        <span class="lrob-etk-cf-advanced-label"><?php esc_html_e('Advanced settings', 'lrob-email-toolkit'); ?></span>
                        <?php if ($created_display !== '') : ?>
                            <span class="lrob-etk-cf-advanced-meta">
                                <?php
                                printf(
                                    /* translators: 1: number of submissions, 2: localized creation date */
                                    esc_html__('%1$s submissions · since %2$s', 'lrob-email-toolkit'),
                                    '<strong>' . esc_html(number_format_i18n((int) $form['submissions'])) . '</strong>',
                                    esc_html($created_display)
                                );
                                ?>
                            </span>
                        <?php endif; ?>
                    </summary>
                    <div class="lrob-etk-cf-advanced-body">
                        <div class="lrob-etk-modal-columns">
                            <section class="lrob-etk-form-column">
                                <h3 class="lrob-etk-form-column-head">
                                    <span class="lrob-etk-form-column-title"><?php esc_html_e('Email', 'lrob-email-toolkit'); ?></span>
                                </h3>
                                <div class="lrob-etk-field">
                                    <label>
                                        <?php esc_html_e('Reply-To field', 'lrob-email-toolkit'); ?>
                                        <?php Tooltip::render(__('Which form field\'s email address is set as the Reply-To header on the notification. Pick "None" if you don\'t want a Reply-To header — some mail providers flag a Reply-To different from the From address as spam.', 'lrob-email-toolkit')); ?>
                                    </label>
                                    <?php self::render_combobox(CPT::META_REPLY_TO_FIELD, $meta['reply_to'], $reply_to_options); ?>
                                </div>
                                <div class="lrob-etk-field">
                                    <label>
                                        <?php esc_html_e('Subject template', 'lrob-email-toolkit'); ?>
                                        <?php Tooltip::render(__('Subject line of the notification email. Tokens like {title} are replaced with form values. Open the dropdown to insert the default.', 'lrob-email-toolkit')); ?>
                                    </label>
                                    <?php self::render_free_combobox(
                                        CPT::META_SUBJECT_TEMPLATE,
                                        $meta['subject'],
                                        [['value' => $subject_default, 'label' => $subject_placeholder]],
                                        $subject_placeholder
                                    ); ?>
                                </div>
                                <div class="lrob-etk-field">
                                    <label>
                                        <?php esc_html_e('Success message', 'lrob-email-toolkit'); ?>
                                        <?php Tooltip::render(__('Message shown to the visitor after a successful submission. Open the dropdown to insert the default.', 'lrob-email-toolkit')); ?>
                                    </label>
                                    <?php self::render_free_combobox(
                                        CPT::META_SUCCESS_MESSAGE,
                                        $meta['success'],
                                        [['value' => $success_default, 'label' => $success_placeholder]],
                                        $success_placeholder
                                    ); ?>
                                </div>
                            </section>

                            <section class="lrob-etk-form-column">
                                <h3 class="lrob-etk-form-column-head">
                                    <span class="lrob-etk-form-column-title"><?php esc_html_e('Anti-spam', 'lrob-email-toolkit'); ?></span>
                                </h3>
                                <div class="lrob-etk-field">
                                    <label>
                                        <?php esc_html_e('Honeypot', 'lrob-email-toolkit'); ?>
                                        <?php Tooltip::render(__('A hidden field invisible to humans. If anything fills it, the submission is silently treated as spam. Effective against most automated bots; no impact on real visitors.', 'lrob-email-toolkit')); ?>
                                    </label>
                                    <?php self::render_combobox(CPT::META_HONEYPOT_ENABLED, $hp_value, $honeypot_options, 'default'); ?>
                                </div>
                                <div class="lrob-etk-field">
                                    <label>
                                        <?php esc_html_e('Challenge', 'lrob-email-toolkit'); ?>
                                        <?php Tooltip::render(__('Anti-bot prompt visitors see (e.g. a tiny math question). Configured globally in the Captcha settings page; override here per form if needed.', 'lrob-email-toolkit')); ?>
                                    </label>
                                    <?php self::render_combobox(CPT::META_CHALLENGE_KIND, $meta['challenge'], $challenge_options); ?>
                                </div>

                                <h3 class="lrob-etk-form-column-head" style="margin-top: 12px;">
                                    <span class="lrob-etk-form-column-title"><?php esc_html_e('Throttling', 'lrob-email-toolkit'); ?></span>
                                    <?php Tooltip::render(__('Server-side rate limit per submitter (identified by IP hash). Blocks the same address from re-submitting more than Max times within Window minutes.', 'lrob-email-toolkit')); ?>
                                </h3>
                                <div class="lrob-etk-cf-defaults-inline-pair">
                                    <div>
                                        <label><?php esc_html_e('Max per IP', 'lrob-email-toolkit'); ?></label>
                                        <input type="text" inputmode="numeric" pattern="[0-9]*"
                                               name="<?php echo esc_attr(CPT::META_RATE_LIMIT_MAX); ?>"
                                               class="lrob-etk-cf-field"
                                               data-key="<?php echo esc_attr(CPT::META_RATE_LIMIT_MAX); ?>"
                                               maxlength="3"
                                               value="<?php echo $meta['rate_max'] > 0 ? (int) $meta['rate_max'] : ''; ?>"
                                               placeholder="<?php echo esc_attr(self::placeholder_default((string) ($globals[Settings::KEY_RATE_MAX] ?? 5))); ?>">
                                    </div>
                                    <div>
                                        <label><?php esc_html_e('Window (min)', 'lrob-email-toolkit'); ?></label>
                                        <input type="text" inputmode="numeric" pattern="[0-9]*"
                                               name="<?php echo esc_attr(CPT::META_RATE_LIMIT_WINDOW); ?>"
                                               class="lrob-etk-cf-field"
                                               data-key="<?php echo esc_attr(CPT::META_RATE_LIMIT_WINDOW); ?>"
                                               data-unit="minutes"
                                               maxlength="4"
                                               value="<?php echo $rate_window_minutes_value > 0 ? $rate_window_minutes_value : ''; ?>"
                                               placeholder="<?php echo esc_attr(self::placeholder_default((string) ($globals[Settings::KEY_RATE_WINDOW_MINUTES] ?? 10))); ?>">
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                </details>

                <footer class="lrob-etk-card-footer">
                    <div class="lrob-etk-card-footer-actions">
                        <a href="<?php echo esc_url($delete_url); ?>"
                           class="lrob-etk-card-delete-link"
                           onclick="return confirm('<?php echo esc_js(sprintf(
                               /* translators: %s: contact form title */
                               __('Delete the contact form "%s"? This cannot be undone.', 'lrob-email-toolkit'),
                               $title !== '' ? $title : __('(no title)', 'lrob-email-toolkit')
                           )); ?>');">
                            <?php esc_html_e('Delete', 'lrob-email-toolkit'); ?>
                        </a>
                    </div>
                </footer>
            </form>
        </article>
        <?php
    }

    /**
     * Hidden popover triggered by the "+ New form" button. Shows the bundled
     * starter templates and the user's own existing forms as cloning sources.
     * The inline script handles open/close + AJAX form creation.
     */
    private static function render_new_form_picker(): void
    {
        $templates = TemplateRegistry::list_for_picker();
        $existing = self::fetch_forms();
        ?>
        <div class="lrob-etk-cf-new-picker" id="lrob-etk-cf-new-picker" hidden>
            <div class="lrob-etk-cf-new-picker-inner">
                <header>
                    <h3><?php esc_html_e('Start a new contact form', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-cf-icon-btn" data-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </header>

                <section>
                    <h4><?php esc_html_e('Starter templates', 'lrob-email-toolkit'); ?></h4>
                    <div class="lrob-etk-cf-picker-grid">
                        <button type="button" class="lrob-etk-cf-picker-card" data-source="blank">
                            <strong><?php esc_html_e('Blank form', 'lrob-email-toolkit'); ?></strong>
                            <span><?php esc_html_e('Start with no fields and build from scratch.', 'lrob-email-toolkit'); ?></span>
                        </button>
                        <?php foreach ($templates as $tpl) : ?>
                            <button type="button" class="lrob-etk-cf-picker-card" data-source="template" data-slug="<?php echo esc_attr($tpl['slug']); ?>">
                                <strong><?php echo esc_html($tpl['name']); ?></strong>
                                <span><?php echo esc_html($tpl['description']); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php if ($existing !== []) : ?>
                    <section>
                        <h4><?php esc_html_e('From your existing forms', 'lrob-email-toolkit'); ?></h4>
                        <div class="lrob-etk-cf-picker-grid">
                            <?php foreach ($existing as $form) : ?>
                                <button type="button" class="lrob-etk-cf-picker-card" data-source="form" data-form-id="<?php echo (int) $form['id']; ?>">
                                    <strong><?php echo esc_html($form['title'] !== '' ? $form['title'] : __('(no title)', 'lrob-email-toolkit')); ?></strong>
                                    <span><?php
                                        printf(
                                            /* translators: %s: submission count */
                                            esc_html__('Clone this form (%s submissions so far)', 'lrob-email-toolkit'),
                                            number_format_i18n((int) $form['submissions'])
                                        );
                                    ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </div>

        <?php
    }

    /**
     * Render the WYSIWYG fields editor for one form. The preview comes from
     * FormEditorRenderer (same FieldRenderer code as the frontend), and the
     * editor JS overlays hover-revealed controls, contenteditable handlers,
     * insert "+" pickers, and the gear popup.
     */
    private static function render_fields_editor(int $form_id): void
    {
        ?>
        <section class="lrob-etk-cf-fields" data-form-id="<?php echo $form_id; ?>">
            <div class="lrob-etk-cf-editor-toolbar">
                <div class="lrob-etk-cf-editor-toolbar-actions">
                    <button type="button"
                            class="lrob-etk-cf-editor-toolbar-btn lrob-etk-cf-editor-toolbar-btn--primary"
                            data-editor-action="add-field"
                            title="<?php esc_attr_e('Add a field', 'lrob-email-toolkit'); ?>"
                            aria-label="<?php esc_attr_e('Add a field', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    </button>
                    <button type="button"
                            class="lrob-etk-cf-editor-toolbar-btn"
                            data-editor-action="undo"
                            disabled
                            title="<?php esc_attr_e('Undo (Ctrl+Z)', 'lrob-email-toolkit'); ?>"
                            aria-label="<?php esc_attr_e('Undo', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-undo" aria-hidden="true"></span>
                    </button>
                    <button type="button"
                            class="lrob-etk-cf-editor-toolbar-btn"
                            data-editor-action="redo"
                            disabled
                            title="<?php esc_attr_e('Redo (Ctrl+Shift+Z)', 'lrob-email-toolkit'); ?>"
                            aria-label="<?php esc_attr_e('Redo', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-redo" aria-hidden="true"></span>
                    </button>
                </div>
                <span class="lrob-etk-cf-editor-status" aria-live="polite"></span>
            </div>

            <?php echo FormEditorRenderer::render($form_id); ?>

            <!-- Field-type picker (cloned by the editor JS each time + is clicked) -->
            <template data-field-type-picker>
                <div class="lrob-etk-cf-type-picker" role="menu">
                    <?php foreach (self::field_types() as $type => $label) : ?>
                        <button type="button" role="menuitem" data-type="<?php echo esc_attr($type); ?>">
                            <?php echo esc_html($label); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </template>

        </section>
        <?php
    }

    /**
     * "Default — X" placeholder used on every free-text field where empty
     * means "inherit". Single translator comment so make-pot doesn't warn
     * about duplicate contexts on the same source string.
     */
    private static function placeholder_default(string $value): string
    {
        return sprintf(
            /* translators: %s: current default value, shown as "Default — X" in placeholders and Default-identity labels */
            __('Default — %s', 'lrob-email-toolkit'),
            $value
        );
    }

    /**
     * "Default (X)" label used inside dropdowns for the inherit option.
     * Same rationale as placeholder_default.
     */
    private static function label_default(string $value): string
    {
        return sprintf(
            /* translators: %s: current default value, shown as "Default (X)" in dropdown labels */
            __('Default (%s)', 'lrob-email-toolkit'),
            $value
        );
    }

    private static function field_types(): array
    {
        return [
            'text'     => __('Text',         'lrob-email-toolkit'),
            'email'    => __('Email',        'lrob-email-toolkit'),
            'textarea' => __('Long text',    'lrob-email-toolkit'),
            'number'   => __('Number',       'lrob-email-toolkit'),
            'phone'    => __('Phone',        'lrob-email-toolkit'),
            'date'     => __('Date',         'lrob-email-toolkit'),
            'select'   => __('Dropdown',     'lrob-email-toolkit'),
            'radio'    => __('Radio',        'lrob-email-toolkit'),
            'checkbox' => __('Checkbox',     'lrob-email-toolkit'),
        ];
    }

    private static function field_type_label(string $type): string
    {
        return self::field_types()[$type] ?? $type;
    }

    /**
     * Global Defaults card, wrapped in a modal opened by the "Default
     * settings" button in the page header. Same layout pattern as per-form
     * cards (Essentials → Style → Advanced sections) and auto-saves via
     * the same data-key plumbing — only the AJAX action differs
     * (handle_save_default writes to the settings option). Marked with
     * `data-defaults-card` so the JS routes there instead of save_meta.
     * Advanced settings stay expanded here (no <details>) since the modal
     * gives them their own attention — the dropdown belongs on per-form
     * cards where it hides chrome that's rarely touched.
     */
    private function render_defaults_modal(): void
    {
        $s = Settings::all();
        $identities = self::active_identities();
        $admin_email = (string) get_option('admin_email');

        // Pre-build option lists.
        $identity_options = [
            ['value' => '0', 'label' => __('SMTP routing decides', 'lrob-email-toolkit')],
        ];
        foreach ($identities as $identity) {
            if ($identity['is_default']) {
                continue;
            }
            $identity_options[] = ['value' => (string) $identity['id'], 'label' => $identity['label']];
        }

        $honeypot_options = [
            ['value' => '1', 'label' => __('On', 'lrob-email-toolkit')],
            ['value' => '0', 'label' => __('Off', 'lrob-email-toolkit')],
        ];

        // Default challenge is now configured on the Captcha settings page
        // (Email Toolkit → Captcha → Routing → "Contact forms"). The Defaults
        // modal links there instead of duplicating the dropdown.
        $captcha_settings_url = admin_url('admin.php?page=lrob-etk-captcha');

        $preset_options = [];
        foreach (self::style_presets() as $value => $label) {
            $preset_options[] = ['value' => $value, 'label' => $label];
        }

        // Reply-To suggestions: dedupe across all current forms' email fields.
        $reply_slugs = self::all_email_field_slugs();
        $reply_to_options = [
            ['value' => self::REPLY_TO_NONE, 'label' => __('None — no Reply-To header', 'lrob-email-toolkit')],
        ];
        foreach ($reply_slugs as $slug) {
            $reply_to_options[] = ['value' => $slug, 'label' => $slug];
        }

        // System defaults shown as free-combo suggestions.
        $subject_default = __('[Site] New submission from {title}', 'lrob-email-toolkit');
        $success_default = __('Thanks! Your message has been sent.', 'lrob-email-toolkit');
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-cf-defaults-modal" role="dialog" aria-modal="true" aria-labelledby="lrob-etk-cf-defaults-title" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog">
                <header class="lrob-etk-modal-header">
                    <h3 id="lrob-etk-cf-defaults-title" class="lrob-etk-modal-title-text"><?php esc_html_e('Default settings for new forms', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p class="description" style="margin-top: 0;">
                        <?php esc_html_e('Apply to every form unless overridden in the form\'s settings. Changes save automatically.', 'lrob-email-toolkit'); ?>
                    </p>

        <article class="lrob-etk-identity-card lrob-etk-cf-form-card lrob-etk-cf-form-card--defaults" data-defaults-card="1">
            <form class="lrob-etk-card-form" onsubmit="return false">
                <header class="lrob-etk-card-form-head">
                    <span class="lrob-etk-card-status" aria-live="polite"></span>
                </header>

                <section class="lrob-etk-cf-essentials">
                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('Recipients', 'lrob-email-toolkit'); ?></label>
                        <?php self::render_recipients(
                            (string) $s[Settings::KEY_RECIPIENT],
                            $admin_email !== '' ? $admin_email : __('email@example.com', 'lrob-email-toolkit'),
                            Settings::KEY_RECIPIENT
                        ); ?>
                    </div>
                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('SMTP identity', 'lrob-email-toolkit'); ?></label>
                        <?php self::render_combobox(Settings::KEY_IDENTITY, (string) $s[Settings::KEY_IDENTITY], $identity_options, '0'); ?>
                    </div>
                </section>

                <section class="lrob-etk-cf-style-group">
                    <h3 class="lrob-etk-cf-section-title"><?php esc_html_e('Style', 'lrob-email-toolkit'); ?></h3>
                    <div class="lrob-etk-cf-style-grid">
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('Preset', 'lrob-email-toolkit'); ?></label>
                            <?php self::render_combobox(Settings::KEY_STYLE_PRESET, (string) $s[Settings::KEY_STYLE_PRESET], $preset_options); ?>
                        </div>
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('Accent color', 'lrob-email-toolkit'); ?></label>
                            <input type="text" name="<?php echo esc_attr(Settings::KEY_ACCENT); ?>"
                                   class="lrob-etk-cf-field"
                                   data-key="<?php echo esc_attr(Settings::KEY_ACCENT); ?>"
                                   value="<?php echo esc_attr((string) $s[Settings::KEY_ACCENT]); ?>"
                                   placeholder="<?php esc_attr_e('Inherit from theme', 'lrob-email-toolkit'); ?>">
                        </div>
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('Corner roundness', 'lrob-email-toolkit'); ?></label>
                            <input type="text" name="<?php echo esc_attr(Settings::KEY_RADIUS); ?>"
                                   class="lrob-etk-cf-field"
                                   data-key="<?php echo esc_attr(Settings::KEY_RADIUS); ?>"
                                   value="<?php echo esc_attr((string) $s[Settings::KEY_RADIUS]); ?>"
                                   placeholder="8px">
                        </div>
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('Font size', 'lrob-email-toolkit'); ?></label>
                            <input type="text" name="<?php echo esc_attr(Settings::KEY_FONT_SIZE); ?>"
                                   class="lrob-etk-cf-field"
                                   data-key="<?php echo esc_attr(Settings::KEY_FONT_SIZE); ?>"
                                   value="<?php echo esc_attr((string) $s[Settings::KEY_FONT_SIZE]); ?>"
                                   placeholder="1rem">
                        </div>
                    </div>
                </section>

                <div class="lrob-etk-cf-advanced-body">
                    <div class="lrob-etk-modal-columns">
                        <section class="lrob-etk-form-column">
                            <h3 class="lrob-etk-form-column-head">
                                <span class="lrob-etk-form-column-title"><?php esc_html_e('Email', 'lrob-email-toolkit'); ?></span>
                            </h3>
                            <div class="lrob-etk-field">
                                <label>
                                    <?php esc_html_e('Reply-To field', 'lrob-email-toolkit'); ?>
                                    <?php Tooltip::render(__('Slug of an email field on the form whose value becomes the Reply-To header. Forms override this individually.', 'lrob-email-toolkit')); ?>
                                </label>
                                <?php self::render_combobox(Settings::KEY_REPLY_TO_FIELD, (string) $s[Settings::KEY_REPLY_TO_FIELD], $reply_to_options); ?>
                            </div>
                            <div class="lrob-etk-field">
                                <label>
                                    <?php esc_html_e('Subject template', 'lrob-email-toolkit'); ?>
                                    <?php Tooltip::render(__('Subject line of the notification email. Tokens like {title} are replaced with form values.', 'lrob-email-toolkit')); ?>
                                </label>
                                <?php self::render_free_combobox(
                                    Settings::KEY_SUBJECT_TEMPLATE,
                                    (string) $s[Settings::KEY_SUBJECT_TEMPLATE],
                                    [['value' => $subject_default, 'label' => self::placeholder_default($subject_default)]],
                                    $subject_default
                                ); ?>
                            </div>
                            <div class="lrob-etk-field">
                                <label>
                                    <?php esc_html_e('Success message', 'lrob-email-toolkit'); ?>
                                    <?php Tooltip::render(__('Message shown to the visitor after a successful submission.', 'lrob-email-toolkit')); ?>
                                </label>
                                <?php self::render_free_combobox(
                                    Settings::KEY_SUCCESS_MESSAGE,
                                    (string) $s[Settings::KEY_SUCCESS_MESSAGE],
                                    [['value' => $success_default, 'label' => self::placeholder_default($success_default)]],
                                    $success_default
                                ); ?>
                            </div>
                        </section>

                        <section class="lrob-etk-form-column">
                            <h3 class="lrob-etk-form-column-head">
                                <span class="lrob-etk-form-column-title"><?php esc_html_e('Anti-spam', 'lrob-email-toolkit'); ?></span>
                            </h3>
                            <div class="lrob-etk-field">
                                <label>
                                    <?php esc_html_e('Honeypot', 'lrob-email-toolkit'); ?>
                                    <?php Tooltip::render(__('A hidden field invisible to humans. If anything fills it, the submission is silently treated as spam.', 'lrob-email-toolkit')); ?>
                                </label>
                                <?php self::render_combobox(Settings::KEY_HONEYPOT, !empty($s[Settings::KEY_HONEYPOT]) ? '1' : '0', $honeypot_options); ?>
                            </div>
                            <div class="lrob-etk-field">
                                <label>
                                    <?php esc_html_e('Challenge', 'lrob-email-toolkit'); ?>
                                </label>
                                <p class="description" style="margin: 4px 0 0;">
                                    <?php
                                    printf(
                                        /* translators: %s: URL to the Captcha settings page */
                                        wp_kses(
                                            __('Configured on the <a href="%s">Captcha settings page</a> under "Contact forms". Each form can still override this default.', 'lrob-email-toolkit'),
                                            ['a' => ['href' => true]]
                                        ),
                                        esc_url($captcha_settings_url)
                                    );
                                    ?>
                                </p>
                            </div>

                            <h3 class="lrob-etk-form-column-head" style="margin-top: 12px;">
                                <span class="lrob-etk-form-column-title"><?php esc_html_e('Throttling', 'lrob-email-toolkit'); ?></span>
                                <?php Tooltip::render(__('Server-side rate limit per submitter (IP hash). Blocks more than Max submissions within Window minutes.', 'lrob-email-toolkit')); ?>
                            </h3>
                            <div class="lrob-etk-cf-defaults-inline-pair">
                                <div>
                                    <label><?php esc_html_e('Max per IP', 'lrob-email-toolkit'); ?></label>
                                    <input type="text" inputmode="numeric" pattern="[0-9]*"
                                           name="<?php echo esc_attr(Settings::KEY_RATE_MAX); ?>"
                                           class="lrob-etk-cf-field"
                                           data-key="<?php echo esc_attr(Settings::KEY_RATE_MAX); ?>"
                                           maxlength="3"
                                           value="<?php echo (int) $s[Settings::KEY_RATE_MAX]; ?>">
                                </div>
                                <div>
                                    <label><?php esc_html_e('Window (min)', 'lrob-email-toolkit'); ?></label>
                                    <input type="text" inputmode="numeric" pattern="[0-9]*"
                                           name="<?php echo esc_attr(Settings::KEY_RATE_WINDOW_MINUTES); ?>"
                                           class="lrob-etk-cf-field"
                                           data-key="<?php echo esc_attr(Settings::KEY_RATE_WINDOW_MINUTES); ?>"
                                           maxlength="4"
                                           value="<?php echo (int) $s[Settings::KEY_RATE_WINDOW_MINUTES]; ?>">
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </form>
        </article>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Union of email-field slugs across every published+draft contact form.
     * Powers the Reply-To picker in the Defaults card (where there's no
     * single form to pull from). Lazy: just scans current forms; if the
     * site has hundreds this might want caching, but a typical install has
     * a handful.
     *
     * @return array<int, string>
     */
    private static function all_email_field_slugs(): array
    {
        $slugs = [];
        $posts = get_posts([
            'post_type'        => CPT::POST_TYPE,
            'post_status'      => ['publish', 'draft'],
            'numberposts'      => 100,
            'suppress_filters' => true,
            'fields'           => 'ids',
        ]);
        foreach ($posts as $form_id) {
            $structure = FormStructure::load((int) $form_id);
            foreach (self::email_field_slugs($structure) as $slug) {
                $slugs[$slug] = true;
            }
        }
        return array_keys($slugs);
    }

    public function handle_delete_form(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $form_id = isset($_GET['form_id']) ? (int) $_GET['form_id'] : 0;
        $nonce = isset($_GET['_lrob_etk_nonce']) ? (string) $_GET['_lrob_etk_nonce'] : '';
        if ($form_id <= 0 || !wp_verify_nonce($nonce, self::ACTION_DELETE_FORM . '_' . $form_id)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }

        $post = get_post($form_id);
        if ($post instanceof \WP_Post && $post->post_type === CPT::POST_TYPE) {
            wp_delete_post($form_id, true);
        }

        wp_safe_redirect(add_query_arg(['deleted' => '1'], admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }

    /** @return array<int, array{id:int, title:string, status:string, created:string, submissions:int}> */
    private static function fetch_forms(): array
    {
        $posts = get_posts([
            'post_type'      => CPT::POST_TYPE,
            'post_status'    => ['publish', 'draft'],
            'numberposts'    => 100,
            'orderby'        => 'date',
            // Oldest first: new forms appear at the BOTTOM of the list,
            // matching the natural creation timeline. The new-form picker
            // navigates to `#form-<id>` after creation so the JS can
            // smooth-scroll to the freshly-added card.
            'order'          => 'ASC',
            'suppress_filters' => true,
        ]);

        $submissions_repo = class_exists(SubmissionRepository::class) ? new SubmissionRepository() : null;

        $out = [];
        foreach ($posts as $post) {
            $out[] = [
                'id'          => (int) $post->ID,
                'title'       => (string) $post->post_title,
                'status'      => (string) $post->post_status,
                'created'     => (string) $post->post_date_gmt,
                'submissions' => $submissions_repo ? $submissions_repo->count_for_form((int) $post->ID) : 0,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array{id:int, label:string, is_default:bool}> $identities
     */
    private static function default_identity_label(array $identities): string
    {
        foreach ($identities as $identity) {
            if ($identity['is_default']) {
                return $identity['label'];
            }
        }
        return '';
    }

    /** @return array<int, array{id:int, label:string, is_default:bool}> */
    private static function active_identities(): array
    {
        if (!class_exists(IdentityRepository::class)) {
            return [];
        }
        $out = [];
        foreach ((new IdentityRepository())->all() as $identity) {
            if (!$identity->is_active || $identity->id === null) {
                continue;
            }
            $out[] = [
                'id'         => $identity->id,
                'label'      => $identity->label,
                'is_default' => $identity->is_default,
            ];
        }
        return $out;
    }

    /** @return array<string, string> */
    public static function style_presets(): array
    {
        return [
            'default'  => __('Default', 'lrob-email-toolkit'),
            'minimal'  => __('Minimal', 'lrob-email-toolkit'),
            'soft'     => __('Soft', 'lrob-email-toolkit'),
            'contrast' => __('Contrast', 'lrob-email-toolkit'),
        ];
    }
}
