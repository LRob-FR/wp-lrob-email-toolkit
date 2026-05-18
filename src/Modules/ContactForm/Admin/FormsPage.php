<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\ModuleToggle;
use LRob\EmailToolkit\Modules\ContactForm\CPT;
use LRob\EmailToolkit\Modules\ContactForm\FormStructure;
use LRob\EmailToolkit\Modules\ContactForm\Module as ContactFormModule;
use LRob\EmailToolkit\Modules\ContactForm\Settings;
use LRob\EmailToolkit\Modules\ContactForm\SubmissionRepository;
use LRob\EmailToolkit\Modules\ContactForm\TemplateRegistry;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;

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

    public const ACTION_SAVE_DEFAULTS = 'lrob_etk_cform_save_defaults';

    public const ACTION_DELETE_FORM = 'lrob_etk_cform_delete';

    public function __construct(private ContactFormModule $module)
    {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_SAVE_DEFAULTS, [$this, 'handle_save_defaults']);
        add_action('admin_post_' . self::ACTION_DELETE_FORM, [$this, 'handle_delete_form']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if (!str_contains($hook_suffix, self::SLUG)) {
            return;
        }
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
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(AjaxController::NONCE_ACTION),
            'action'  => AjaxController::ACTION_SAVE_META,
            'i18n'    => [
                'saving' => __('Saving…', 'lrob-email-toolkit'),
                'saved'  => __('Saved', 'lrob-email-toolkit'),
                'error'  => __('Save failed', 'lrob-email-toolkit'),
            ],
        ]);

        // Fields editor (inline rows / columns / fields) — owns its own
        // serialization but shares the admin JS's nonce + status indicator.
        wp_enqueue_script(
            'lrob-etk-cf-fields-editor',
            LROB_ETK_URL . 'admin/js/contact-form-fields-editor.js',
            ['lrob-etk-cf-admin'],
            self::asset_version('admin/js/contact-form-fields-editor.js'),
            true
        );
    }

    private static function asset_version(string $relative): string
    {
        $version = LROB_ETK_VERSION;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $full = LROB_ETK_PATH . ltrim($relative, '/');
            if (is_file($full)) {
                $version .= '.' . filemtime($full);
            }
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
    private static function render_combobox(string $meta_key, string $current_value, array $options): void
    {
        // Find the option matching the current value for the display string.
        $current_label = '';
        foreach ($options as $opt) {
            if ((string) $opt['value'] === (string) $current_value) {
                $current_label = (string) $opt['label'];
                break;
            }
        }
        $name = $meta_key;
        $combo_id = 'lrob-etk-cf-combo-' . md5($meta_key . wp_generate_uuid4());
        ?>
        <div class="lrob-etk-combo lrob-etk-combo--select"
             data-options="<?php echo esc_attr((string) wp_json_encode($options)); ?>">
            <input type="text"
                   id="<?php echo esc_attr($combo_id); ?>"
                   class="lrob-etk-combo-input"
                   value="<?php echo esc_attr($current_label); ?>"
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
                    <button type="button" class="button button-primary lrob-etk-page-add" id="lrob-etk-cf-new-form-btn">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php esc_html_e('New form', 'lrob-email-toolkit'); ?>
                    </button>
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
                <?php $this->render_defaults_section(); ?>
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
        $reply_to_placeholder  = self::placeholder_default((string) ($globals[Settings::KEY_REPLY_TO_FIELD] ?? 'email'));

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

        $ch_default_label = ($globals[Settings::KEY_CHALLENGE] ?? '') === CPT::CHALLENGE_NONE
            ? __('None', 'lrob-email-toolkit')
            : __('Math (a + b)', 'lrob-email-toolkit');
        $challenge_options = [
            ['value' => '',                  'label' => self::label_default($ch_default_label)],
            ['value' => CPT::CHALLENGE_NONE, 'label' => __('None', 'lrob-email-toolkit')],
            ['value' => CPT::CHALLENGE_MATH, 'label' => __('Math (a + b)', 'lrob-email-toolkit')],
        ];

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

                <div class="lrob-etk-modal-columns">
                    <section class="lrob-etk-form-column">
                        <h3 class="lrob-etk-form-column-head">
                            <span class="lrob-etk-form-column-title"><?php esc_html_e('Delivery', 'lrob-email-toolkit'); ?></span>
                        </h3>
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('Recipients', 'lrob-email-toolkit'); ?></label>
                            <input type="text" name="<?php echo esc_attr(CPT::META_RECIPIENT); ?>"
                                   class="lrob-etk-cf-field"
                                   data-key="<?php echo esc_attr(CPT::META_RECIPIENT); ?>"
                                   value="<?php echo esc_attr($meta['recipient']); ?>"
                                   placeholder="<?php echo esc_attr($recipient_placeholder); ?>"
                                   autocomplete="off">
                            <p class="description"><?php esc_html_e('One or more emails, comma-separated.', 'lrob-email-toolkit'); ?></p>
                        </div>
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('SMTP identity', 'lrob-email-toolkit'); ?></label>
                            <?php self::render_combobox(CPT::META_RECIPIENT_IDENTITY, (string) $meta['identity_id'], $identity_options); ?>
                        </div>
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('Reply-To uses', 'lrob-email-toolkit'); ?></label>
                            <input type="text" name="<?php echo esc_attr(CPT::META_REPLY_TO_FIELD); ?>"
                                   class="lrob-etk-cf-field"
                                   data-key="<?php echo esc_attr(CPT::META_REPLY_TO_FIELD); ?>"
                                   value="<?php echo esc_attr($meta['reply_to']); ?>"
                                   placeholder="<?php echo esc_attr($reply_to_placeholder); ?>"
                                   autocomplete="off">
                            <p class="description"><?php esc_html_e('Slug of an email field on this form (e.g. "email").', 'lrob-email-toolkit'); ?></p>
                        </div>
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('Subject template', 'lrob-email-toolkit'); ?></label>
                            <input type="text" name="<?php echo esc_attr(CPT::META_SUBJECT_TEMPLATE); ?>"
                                   class="lrob-etk-cf-field"
                                   data-key="<?php echo esc_attr(CPT::META_SUBJECT_TEMPLATE); ?>"
                                   value="<?php echo esc_attr($meta['subject']); ?>"
                                   placeholder="<?php echo esc_attr($subject_placeholder); ?>">
                        </div>
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('Success message', 'lrob-email-toolkit'); ?></label>
                            <textarea name="<?php echo esc_attr(CPT::META_SUCCESS_MESSAGE); ?>"
                                      rows="2"
                                      class="lrob-etk-cf-field"
                                      data-key="<?php echo esc_attr(CPT::META_SUCCESS_MESSAGE); ?>"
                                      placeholder="<?php echo esc_attr($success_placeholder); ?>"><?php
                                echo esc_textarea($meta['success']);
                            ?></textarea>
                        </div>
                    </section>

                    <section class="lrob-etk-form-column">
                        <h3 class="lrob-etk-form-column-head">
                            <span class="lrob-etk-form-column-title"><?php esc_html_e('Anti-spam', 'lrob-email-toolkit'); ?></span>
                        </h3>
                        <div class="lrob-etk-cf-defaults-inline-pair">
                            <div>
                                <label><?php esc_html_e('Max per IP', 'lrob-email-toolkit'); ?></label>
                                <input type="number" name="<?php echo esc_attr(CPT::META_RATE_LIMIT_MAX); ?>"
                                       class="lrob-etk-cf-field"
                                       data-key="<?php echo esc_attr(CPT::META_RATE_LIMIT_MAX); ?>"
                                       min="0" max="999" step="1"
                                       value="<?php echo $meta['rate_max'] > 0 ? (int) $meta['rate_max'] : ''; ?>"
                                       placeholder="<?php echo esc_attr((string) ($globals[Settings::KEY_RATE_MAX] ?? 5)); ?>">
                            </div>
                            <div>
                                <label><?php esc_html_e('Window (min)', 'lrob-email-toolkit'); ?></label>
                                <input type="number" name="<?php echo esc_attr(CPT::META_RATE_LIMIT_WINDOW); ?>"
                                       class="lrob-etk-cf-field"
                                       data-key="<?php echo esc_attr(CPT::META_RATE_LIMIT_WINDOW); ?>"
                                       data-unit="minutes"
                                       min="0" max="1440" step="1"
                                       value="<?php echo $rate_window_minutes_value > 0 ? $rate_window_minutes_value : ''; ?>"
                                       placeholder="<?php echo esc_attr((string) ($globals[Settings::KEY_RATE_WINDOW_MINUTES] ?? 10)); ?>">
                            </div>
                        </div>
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('Honeypot', 'lrob-email-toolkit'); ?></label>
                            <?php self::render_combobox(CPT::META_HONEYPOT_ENABLED, $hp_value, $honeypot_options); ?>
                        </div>
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('Challenge', 'lrob-email-toolkit'); ?></label>
                            <?php self::render_combobox(CPT::META_CHALLENGE_KIND, $meta['challenge'], $challenge_options); ?>
                        </div>
                        <div class="lrob-etk-field">
                            <label><?php esc_html_e('Style preset', 'lrob-email-toolkit'); ?></label>
                            <?php self::render_combobox(CPT::META_STYLE_PRESET, $meta['style_preset'], $preset_options); ?>
                        </div>
                    </section>
                </div>

                <?php self::render_fields_editor($form_id); ?>

                <div class="lrob-etk-cf-form-card-stats">
                    <strong><?php echo number_format_i18n((int) $form['submissions']); ?></strong>
                    <span><?php echo esc_html(_n('submission', 'submissions', (int) $form['submissions'], 'lrob-email-toolkit')); ?></span>
                    <?php if ($created_display !== '') : ?>
                        <span class="lrob-etk-cf-form-card-since">
                            <?php echo esc_html(sprintf(
                                /* translators: %s: localized creation date */
                                __('since %s', 'lrob-email-toolkit'),
                                $created_display
                            )); ?>
                        </span>
                    <?php endif; ?>
                </div>

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
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce(AjaxController::NONCE_ACTION);
        $page_url = admin_url('admin.php?page=' . self::SLUG);
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

        <script>
        (function () {
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var pageUrl = <?php echo wp_json_encode($page_url); ?>;

            function ready(fn) {
                if (document.readyState !== 'loading') fn();
                else document.addEventListener('DOMContentLoaded', fn);
            }

            ready(function () {
                var picker = document.getElementById('lrob-etk-cf-new-picker');
                if (!picker) return;
                // The onboarding-state button only exists when there are no
                // forms yet — but render order puts the picker script BEFORE
                // the empty state in the DOM, so binding must wait for parse.
                var openBtns = [
                    document.getElementById('lrob-etk-cf-new-form-btn'),
                    document.getElementById('lrob-etk-cf-new-form-btn-empty')
                ];

                function open() { picker.hidden = false; document.body.style.overflow = 'hidden'; }
                function close() { picker.hidden = true; document.body.style.overflow = ''; }

                openBtns.forEach(function (b) { if (b) b.addEventListener('click', open); });
            picker.addEventListener('click', function (e) {
                if (e.target === picker || e.target.closest('[data-close]')) {
                    close();
                    return;
                }
                var card = e.target.closest('.lrob-etk-cf-picker-card');
                if (!card) return;
                Array.prototype.forEach.call(picker.querySelectorAll('.lrob-etk-cf-picker-card'), function (c) { c.disabled = true; });
                var fd = new FormData();
                fd.append('action', 'lrob_etk_cf_create_form');
                fd.append('_nonce', nonce);
                fd.append('source', card.getAttribute('data-source') || 'blank');
                if (card.getAttribute('data-slug')) fd.append('slug', card.getAttribute('data-slug'));
                if (card.getAttribute('data-form-id')) fd.append('form_id', card.getAttribute('data-form-id'));
                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                    .then(function (resp) {
                        if (resp && resp.success && resp.data && resp.data.form_id) {
                            window.location.href = pageUrl + '#form-' + resp.data.form_id;
                            // Force reload so the new card renders.
                            window.location.reload();
                        } else {
                            Array.prototype.forEach.call(picker.querySelectorAll('.lrob-etk-cf-picker-card'), function (c) { c.disabled = false; });
                            alert((resp && resp.data && resp.data.message) || 'Could not create form.');
                        }
                    })
                    .catch(function () {
                        Array.prototype.forEach.call(picker.querySelectorAll('.lrob-etk-cf-picker-card'), function (c) { c.disabled = false; });
                    });
                });
                document.addEventListener('keydown', function (e) { if (!picker.hidden && e.key === 'Escape') close(); });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render the inline fields editor for one form. Rows of columns of
     * fields, each with controls. The contact-form-fields-editor.js
     * wires up the buttons and serializes the DOM back to JSON on changes.
     */
    private static function render_fields_editor(int $form_id): void
    {
        $structure = FormStructure::load($form_id);
        ?>
        <section class="lrob-etk-cf-fields" data-form-id="<?php echo $form_id; ?>">
            <h3 class="lrob-etk-cf-fields-head">
                <span class="lrob-etk-form-column-title"><?php esc_html_e('Fields', 'lrob-email-toolkit'); ?></span>
                <span class="lrob-etk-cf-fields-hint">
                    <?php esc_html_e('Click a field to edit its label, slug, and options. Use the column buttons to arrange fields side by side.', 'lrob-email-toolkit'); ?>
                </span>
            </h3>

            <div class="lrob-etk-cf-rows" data-rows-root>
                <?php foreach ($structure['rows'] as $row) : ?>
                    <?php self::render_editor_row($row); ?>
                <?php endforeach; ?>
            </div>

            <div class="lrob-etk-cf-fields-add-row">
                <button type="button" class="button" data-action="add-row">
                    <span class="dashicons dashicons-plus-alt2"></span>
                    <?php esc_html_e('Add row', 'lrob-email-toolkit'); ?>
                </button>
            </div>

            <div class="lrob-etk-cf-submit-settings">
                <div class="lrob-etk-field">
                    <label><?php esc_html_e('Submit button text', 'lrob-email-toolkit'); ?></label>
                    <input type="text" data-submit-prop="text" value="<?php echo esc_attr($structure['submit']['text']); ?>">
                </div>
                <div class="lrob-etk-field">
                    <label><?php esc_html_e('Alignment', 'lrob-email-toolkit'); ?></label>
                    <select data-submit-prop="align" class="lrob-etk-select">
                        <?php
                        $aligns = [
                            'left'    => __('Left',       'lrob-email-toolkit'),
                            'center'  => __('Center',     'lrob-email-toolkit'),
                            'right'   => __('Right',      'lrob-email-toolkit'),
                            'stretch' => __('Full width', 'lrob-email-toolkit'),
                        ];
                        foreach ($aligns as $value => $label) :
                            ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($structure['submit']['align'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Field-type picker shown when "Add field" is clicked -->
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

    /** @param array{id:string, columns:array<int, array{id:string, fields:array<int, array>}>} $row */
    private static function render_editor_row(array $row): void
    {
        $cols = count($row['columns']);
        ?>
        <div class="lrob-etk-cf-editor-row" data-row-id="<?php echo esc_attr($row['id']); ?>"
             data-draggable-type="row" draggable="true">
            <div class="lrob-etk-cf-editor-row-head">
                <span class="lrob-etk-cf-drag-handle dashicons dashicons-move" aria-hidden="true"></span>
                <span class="lrob-etk-cf-editor-row-label">
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of columns in this form row */
                        _n('Row · %d column', 'Row · %d columns', $cols, 'lrob-email-toolkit'),
                        $cols
                    )); ?>
                </span>
                <button type="button" class="lrob-etk-cf-icon-btn lrob-etk-cf-icon-btn--delete" data-action="delete-row" aria-label="<?php esc_attr_e('Delete row', 'lrob-email-toolkit'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
            <div class="lrob-etk-cf-editor-cols" data-cols="<?php echo $cols; ?>">
                <?php foreach ($row['columns'] as $col) : ?>
                    <?php self::render_editor_column($col); ?>
                <?php endforeach; ?>
                <?php if ($cols < 4) : ?>
                    <button type="button" class="lrob-etk-cf-add-col" data-action="add-column" aria-label="<?php esc_attr_e('Add column', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-plus-alt2"></span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /** @param array{id:string, fields:array<int, array>} $col */
    private static function render_editor_column(array $col): void
    {
        ?>
        <div class="lrob-etk-cf-editor-col" data-col-id="<?php echo esc_attr($col['id']); ?>"
             data-draggable-type="col" draggable="true">
            <div class="lrob-etk-cf-editor-col-head">
                <span class="lrob-etk-cf-drag-handle dashicons dashicons-move" aria-hidden="true"></span>
                <button type="button" class="lrob-etk-cf-icon-btn lrob-etk-cf-icon-btn--delete" data-action="delete-col" aria-label="<?php esc_attr_e('Delete column', 'lrob-email-toolkit'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
            <?php foreach ($col['fields'] as $field) : ?>
                <?php self::render_editor_field($field); ?>
            <?php endforeach; ?>
            <button type="button" class="lrob-etk-cf-add-field" data-action="add-field">
                <span class="dashicons dashicons-plus-alt2"></span>
                <?php esc_html_e('Add field', 'lrob-email-toolkit'); ?>
            </button>
        </div>
        <?php
    }

    /** @param array<string, mixed> $field */
    private static function render_editor_field(array $field): void
    {
        $type = (string) ($field['type'] ?? 'text');
        $label = (string) ($field['label'] ?? '');
        ?>
        <div class="lrob-etk-cf-editor-field" data-field-id="<?php echo esc_attr((string) ($field['id'] ?? '')); ?>" data-field-type="<?php echo esc_attr($type); ?>"
             data-draggable-type="field" draggable="true">
            <div class="lrob-etk-cf-editor-field-summary" data-action="toggle-edit">
                <span class="lrob-etk-cf-drag-handle dashicons dashicons-move" aria-hidden="true"></span>
                <span class="lrob-etk-cf-editor-field-type"><?php echo esc_html(self::field_type_label($type)); ?></span>
                <span class="lrob-etk-cf-editor-field-label"><?php echo esc_html($label !== '' ? $label : __('(no label)', 'lrob-email-toolkit')); ?></span>
                <span class="lrob-etk-cf-editor-field-actions">
                    <?php if (!empty($field['required'])) : ?>
                        <span class="lrob-etk-cf-required-pill"><?php esc_html_e('required', 'lrob-email-toolkit'); ?></span>
                    <?php endif; ?>
                    <button type="button" class="lrob-etk-cf-icon-btn lrob-etk-cf-icon-btn--delete" data-action="delete-field" aria-label="<?php esc_attr_e('Delete field', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </span>
            </div>
            <div class="lrob-etk-cf-editor-field-body" hidden>
                <?php self::render_field_body($field); ?>
            </div>
        </div>
        <?php
    }

    /** @param array<string, mixed> $field */
    private static function render_field_body(array $field): void
    {
        $type = (string) ($field['type'] ?? 'text');
        ?>
        <div class="lrob-etk-cf-editor-field-grid">
            <div class="lrob-etk-field">
                <label><?php esc_html_e('Label', 'lrob-email-toolkit'); ?></label>
                <input type="text" data-prop="label" value="<?php echo esc_attr((string) ($field['label'] ?? '')); ?>">
            </div>
            <div class="lrob-etk-field">
                <label><?php esc_html_e('Field slug', 'lrob-email-toolkit'); ?></label>
                <input type="text" data-prop="slug" value="<?php echo esc_attr((string) ($field['slug'] ?? '')); ?>">
            </div>
            <div class="lrob-etk-field lrob-etk-cf-editor-field-grid-full">
                <label><?php esc_html_e('Helper text', 'lrob-email-toolkit'); ?></label>
                <input type="text" data-prop="helper" value="<?php echo esc_attr((string) ($field['helper'] ?? '')); ?>">
            </div>
            <?php if (in_array($type, ['text', 'email', 'textarea', 'number', 'phone', 'date'], true)) : ?>
                <div class="lrob-etk-field lrob-etk-cf-editor-field-grid-full">
                    <label><?php esc_html_e('Placeholder', 'lrob-email-toolkit'); ?></label>
                    <input type="text" data-prop="placeholder" value="<?php echo esc_attr((string) ($field['placeholder'] ?? '')); ?>">
                </div>
            <?php endif; ?>
            <div class="lrob-etk-field lrob-etk-cf-editor-field-grid-full">
                <label class="lrob-etk-cf-inline-check">
                    <input type="checkbox" data-prop="required" <?php checked(!empty($field['required'])); ?>>
                    <?php esc_html_e('Required', 'lrob-email-toolkit'); ?>
                </label>
            </div>
            <?php self::render_field_type_specific($field); ?>
        </div>
        <?php
    }

    /** @param array<string, mixed> $field */
    private static function render_field_type_specific(array $field): void
    {
        $type = (string) ($field['type'] ?? '');
        switch ($type) {
            case 'textarea':
                ?>
                <div class="lrob-etk-field">
                    <label><?php esc_html_e('Rows', 'lrob-email-toolkit'); ?></label>
                    <input type="number" min="2" max="20" data-prop="rows" value="<?php echo esc_attr((string) ($field['rows'] ?? 5)); ?>">
                </div>
                <div class="lrob-etk-field">
                    <label><?php esc_html_e('Max length', 'lrob-email-toolkit'); ?></label>
                    <input type="number" min="0" data-prop="maxLength" value="<?php echo esc_attr((string) ($field['maxLength'] ?? 0)); ?>">
                </div>
                <?php
                break;
            case 'text':
            case 'email':
                ?>
                <div class="lrob-etk-field">
                    <label><?php esc_html_e('Max length', 'lrob-email-toolkit'); ?></label>
                    <input type="number" min="0" data-prop="maxLength" value="<?php echo esc_attr((string) ($field['maxLength'] ?? 0)); ?>">
                </div>
                <?php
                break;
            case 'number':
                ?>
                <div class="lrob-etk-field"><label><?php esc_html_e('Min', 'lrob-email-toolkit'); ?></label><input type="text" data-prop="min" value="<?php echo esc_attr((string) ($field['min'] ?? '')); ?>"></div>
                <div class="lrob-etk-field"><label><?php esc_html_e('Max', 'lrob-email-toolkit'); ?></label><input type="text" data-prop="max" value="<?php echo esc_attr((string) ($field['max'] ?? '')); ?>"></div>
                <div class="lrob-etk-field"><label><?php esc_html_e('Step', 'lrob-email-toolkit'); ?></label><input type="text" data-prop="step" value="<?php echo esc_attr((string) ($field['step'] ?? '')); ?>"></div>
                <?php
                break;
            case 'phone':
                ?>
                <div class="lrob-etk-field lrob-etk-cf-editor-field-grid-full">
                    <label><?php esc_html_e('Regex pattern (optional)', 'lrob-email-toolkit'); ?></label>
                    <input type="text" data-prop="pattern" value="<?php echo esc_attr((string) ($field['pattern'] ?? '')); ?>">
                </div>
                <?php
                break;
            case 'date':
                ?>
                <div class="lrob-etk-field"><label><?php esc_html_e('Earliest (YYYY-MM-DD)', 'lrob-email-toolkit'); ?></label><input type="text" data-prop="min" value="<?php echo esc_attr((string) ($field['min'] ?? '')); ?>"></div>
                <div class="lrob-etk-field"><label><?php esc_html_e('Latest (YYYY-MM-DD)', 'lrob-email-toolkit'); ?></label><input type="text" data-prop="max" value="<?php echo esc_attr((string) ($field['max'] ?? '')); ?>"></div>
                <?php
                break;
            case 'select':
            case 'radio':
                self::render_options_editor($field['options'] ?? []);
                break;
            case 'checkbox':
                ?>
                <div class="lrob-etk-field lrob-etk-cf-editor-field-grid-full">
                    <label class="lrob-etk-cf-inline-check">
                        <input type="checkbox" data-prop="multiple" <?php checked(!isset($field['multiple']) || !empty($field['multiple'])); ?>>
                        <?php esc_html_e('Multiple choices (off = single consent checkbox)', 'lrob-email-toolkit'); ?>
                    </label>
                </div>
                <?php
                self::render_options_editor($field['options'] ?? []);
                break;
        }
    }

    /** @param array<int, array{value:string, label:string}>|mixed $options */
    private static function render_options_editor(mixed $options): void
    {
        $options = is_array($options) ? $options : [];
        ?>
        <div class="lrob-etk-field lrob-etk-cf-editor-field-grid-full lrob-etk-cf-editor-options" data-options-root>
            <label><?php esc_html_e('Options', 'lrob-email-toolkit'); ?></label>
            <div class="lrob-etk-cf-editor-options-list" data-options-list>
                <?php foreach ($options as $opt) : ?>
                    <div class="lrob-etk-cf-editor-option" data-option>
                        <input type="text" data-option-prop="label" value="<?php echo esc_attr((string) ($opt['label'] ?? '')); ?>" placeholder="<?php esc_attr_e('Label', 'lrob-email-toolkit'); ?>">
                        <input type="text" data-option-prop="value" value="<?php echo esc_attr((string) ($opt['value'] ?? '')); ?>" placeholder="<?php esc_attr_e('value', 'lrob-email-toolkit'); ?>">
                        <button type="button" class="lrob-etk-cf-icon-btn lrob-etk-cf-icon-btn--delete" data-action="delete-option" aria-label="<?php esc_attr_e('Remove option', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button-link" data-action="add-option">
                + <?php esc_html_e('Add option', 'lrob-email-toolkit'); ?>
            </button>
        </div>
        <?php
    }

    /** @return array<string, string> */
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

    private function render_defaults_section(): void
    {
        $settings = Settings::all();
        $identities = self::active_identities();
        $action_url = admin_url('admin-post.php');
        ?>
        <h2 class="lrob-etk-section-title"><?php esc_html_e('Defaults', 'lrob-email-toolkit'); ?></h2>
        <p class="lrob-etk-section-intro">
            <?php esc_html_e('These apply to every form unless the form overrides them in its sidebar.', 'lrob-email-toolkit'); ?>
        </p>

        <form method="post" action="<?php echo esc_url($action_url); ?>" class="lrob-etk-card-form lrob-etk-cf-defaults">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SAVE_DEFAULTS); ?>">
            <?php wp_nonce_field(self::ACTION_SAVE_DEFAULTS, '_lrob_etk_nonce'); ?>

            <div class="lrob-etk-modal-columns lrob-etk-cf-defaults-columns">
                <?php $this->render_delivery_column($settings, $identities); ?>
                <?php $this->render_antispam_column($settings); ?>
            </div>

            <?php $this->render_style_section($settings); ?>

            <footer class="lrob-etk-card-footer">
                <div class="lrob-etk-card-footer-actions">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Save defaults', 'lrob-email-toolkit'); ?>
                    </button>
                </div>
            </footer>
        </form>
        <?php
    }

    /**
     * @param array<string, mixed> $s
     * @param array<int, array{id:int, label:string, is_default:bool}> $identities
     */
    private function render_delivery_column(array $s, array $identities): void
    {
        ?>
        <section class="lrob-etk-form-column">
            <h3 class="lrob-etk-form-column-head">
                <span class="lrob-etk-form-column-title">
                    <?php esc_html_e('Delivery', 'lrob-email-toolkit'); ?>
                </span>
            </h3>

            <div class="lrob-etk-field">
                <label for="cf-recipient"><?php esc_html_e('Default recipient(s)', 'lrob-email-toolkit'); ?></label>
                <input type="text" id="cf-recipient" name="<?php echo esc_attr(Settings::KEY_RECIPIENT); ?>"
                       value="<?php echo esc_attr((string) $s[Settings::KEY_RECIPIENT]); ?>"
                       placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"
                       autocomplete="off">
                <p class="description"><?php esc_html_e('One or more emails, comma-separated. Empty = site admin email.', 'lrob-email-toolkit'); ?></p>
            </div>

            <div class="lrob-etk-field">
                <label for="cf-identity"><?php esc_html_e('SMTP identity', 'lrob-email-toolkit'); ?></label>
                <select id="cf-identity" name="<?php echo esc_attr(Settings::KEY_IDENTITY); ?>" class="lrob-etk-select">
                    <?php
                    $default_label = self::default_identity_label($identities);
                    $default_text = $default_label !== ''
                        ? self::placeholder_default($default_label)
                        : __('Default — SMTP routing', 'lrob-email-toolkit');
                    ?>
                    <option value="0"><?php echo esc_html($default_text); ?></option>
                    <?php foreach ($identities as $identity) : ?>
                        <?php if ($identity['is_default']) {
                            continue;
                        } ?>
                        <option value="<?php echo (int) $identity['id']; ?>" <?php selected((int) $s[Settings::KEY_IDENTITY], (int) $identity['id']); ?>>
                            <?php echo esc_html($identity['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="lrob-etk-field">
                <label for="cf-reply-to"><?php esc_html_e('Reply-to field slug', 'lrob-email-toolkit'); ?></label>
                <input type="text" id="cf-reply-to" name="<?php echo esc_attr(Settings::KEY_REPLY_TO_FIELD); ?>"
                       value="<?php echo esc_attr((string) $s[Settings::KEY_REPLY_TO_FIELD]); ?>"
                       placeholder="email">
            </div>

            <div class="lrob-etk-field">
                <label for="cf-subject"><?php esc_html_e('Subject template', 'lrob-email-toolkit'); ?></label>
                <input type="text" id="cf-subject" name="<?php echo esc_attr(Settings::KEY_SUBJECT_TEMPLATE); ?>"
                       value="<?php echo esc_attr((string) $s[Settings::KEY_SUBJECT_TEMPLATE]); ?>"
                       placeholder="<?php esc_attr_e('[Site] New submission from {title}', 'lrob-email-toolkit'); ?>">
                <p class="description"><?php esc_html_e('Use {title} and {field:slug}.', 'lrob-email-toolkit'); ?></p>
            </div>

            <div class="lrob-etk-field">
                <label for="cf-success"><?php esc_html_e('Success message', 'lrob-email-toolkit'); ?></label>
                <textarea id="cf-success" name="<?php echo esc_attr(Settings::KEY_SUCCESS_MESSAGE); ?>" rows="2"
                          placeholder="<?php esc_attr_e('Thanks! Your message has been sent.', 'lrob-email-toolkit'); ?>"><?php
                    echo esc_textarea((string) $s[Settings::KEY_SUCCESS_MESSAGE]);
                ?></textarea>
            </div>
        </section>
        <?php
    }

    /** @param array<string, mixed> $s */
    private function render_antispam_column(array $s): void
    {
        ?>
        <section class="lrob-etk-form-column">
            <h3 class="lrob-etk-form-column-head">
                <span class="lrob-etk-form-column-title">
                    <?php esc_html_e('Anti-spam', 'lrob-email-toolkit'); ?>
                </span>
            </h3>

            <div class="lrob-etk-field lrob-etk-cf-defaults-inline-pair">
                <div>
                    <label for="cf-rate-max"><?php esc_html_e('Max per IP', 'lrob-email-toolkit'); ?></label>
                    <input type="number" id="cf-rate-max" name="<?php echo esc_attr(Settings::KEY_RATE_MAX); ?>"
                           min="1" max="999" step="1"
                           value="<?php echo (int) $s[Settings::KEY_RATE_MAX]; ?>">
                </div>
                <div>
                    <label for="cf-rate-window"><?php esc_html_e('Window (min)', 'lrob-email-toolkit'); ?></label>
                    <input type="number" id="cf-rate-window" name="<?php echo esc_attr(Settings::KEY_RATE_WINDOW_MINUTES); ?>"
                           min="1" max="1440" step="1"
                           value="<?php echo (int) $s[Settings::KEY_RATE_WINDOW_MINUTES]; ?>">
                </div>
            </div>

            <div class="lrob-etk-field">
                <label for="cf-honeypot"><?php esc_html_e('Honeypot field', 'lrob-email-toolkit'); ?></label>
                <select id="cf-honeypot" name="<?php echo esc_attr(Settings::KEY_HONEYPOT); ?>" class="lrob-etk-select">
                    <option value="1" <?php selected(!empty($s[Settings::KEY_HONEYPOT])); ?>><?php esc_html_e('On', 'lrob-email-toolkit'); ?></option>
                    <option value="0" <?php selected(empty($s[Settings::KEY_HONEYPOT])); ?>><?php esc_html_e('Off', 'lrob-email-toolkit'); ?></option>
                </select>
            </div>

            <div class="lrob-etk-field">
                <label for="cf-challenge"><?php esc_html_e('Challenge', 'lrob-email-toolkit'); ?></label>
                <select id="cf-challenge" name="<?php echo esc_attr(Settings::KEY_CHALLENGE); ?>" class="lrob-etk-select">
                    <option value="<?php echo esc_attr(CPT::CHALLENGE_MATH); ?>" <?php selected((string) $s[Settings::KEY_CHALLENGE], CPT::CHALLENGE_MATH); ?>><?php esc_html_e('Math (a + b)', 'lrob-email-toolkit'); ?></option>
                    <option value="<?php echo esc_attr(CPT::CHALLENGE_NONE); ?>" <?php selected((string) $s[Settings::KEY_CHALLENGE], CPT::CHALLENGE_NONE); ?>><?php esc_html_e('None', 'lrob-email-toolkit'); ?></option>
                </select>
            </div>
        </section>
        <?php
    }

    /** @param array<string, mixed> $s */
    private function render_style_section(array $s): void
    {
        ?>
        <section class="lrob-etk-form-row-full lrob-etk-cf-style-section">
            <h3 class="lrob-etk-form-column-head">
                <span class="lrob-etk-form-column-title">
                    <?php esc_html_e('Style', 'lrob-email-toolkit'); ?>
                </span>
                <span class="lrob-etk-label-hint">
                    <?php esc_html_e('Empty fields inherit from your theme.', 'lrob-email-toolkit'); ?>
                </span>
            </h3>

            <div class="lrob-etk-cf-style-grid">
                <div class="lrob-etk-field">
                    <label for="cf-preset"><?php esc_html_e('Preset', 'lrob-email-toolkit'); ?></label>
                    <select id="cf-preset" name="<?php echo esc_attr(Settings::KEY_STYLE_PRESET); ?>" class="lrob-etk-select">
                        <?php foreach (self::style_presets() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected((string) $s[Settings::KEY_STYLE_PRESET], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lrob-etk-field">
                    <label for="cf-accent"><?php esc_html_e('Accent color', 'lrob-email-toolkit'); ?></label>
                    <input type="text" id="cf-accent" name="<?php echo esc_attr(Settings::KEY_ACCENT); ?>"
                           value="<?php echo esc_attr((string) $s[Settings::KEY_ACCENT]); ?>"
                           placeholder="<?php esc_attr_e('Inherit from theme', 'lrob-email-toolkit'); ?>">
                </div>
                <div class="lrob-etk-field">
                    <label for="cf-radius"><?php esc_html_e('Corner roundness', 'lrob-email-toolkit'); ?></label>
                    <input type="text" id="cf-radius" name="<?php echo esc_attr(Settings::KEY_RADIUS); ?>"
                           value="<?php echo esc_attr((string) $s[Settings::KEY_RADIUS]); ?>"
                           placeholder="8px">
                </div>
                <div class="lrob-etk-field">
                    <label for="cf-font-size"><?php esc_html_e('Font size', 'lrob-email-toolkit'); ?></label>
                    <input type="text" id="cf-font-size" name="<?php echo esc_attr(Settings::KEY_FONT_SIZE); ?>"
                           value="<?php echo esc_attr((string) $s[Settings::KEY_FONT_SIZE]); ?>"
                           placeholder="1rem">
                </div>
            </div>
        </section>
        <?php
    }

    public function handle_save_defaults(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $nonce = isset($_POST['_lrob_etk_nonce']) ? (string) wp_unslash($_POST['_lrob_etk_nonce']) : '';
        if (!wp_verify_nonce($nonce, self::ACTION_SAVE_DEFAULTS)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }

        $values = [];
        foreach (array_keys(Settings::defaults()) as $key) {
            if (array_key_exists($key, $_POST)) {
                $values[$key] = wp_unslash($_POST[$key]);
            }
        }
        Settings::save($values);

        wp_safe_redirect(add_query_arg(['saved' => '1'], admin_url('admin.php?page=' . self::SLUG)));
        exit;
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
            'order'          => 'DESC',
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
