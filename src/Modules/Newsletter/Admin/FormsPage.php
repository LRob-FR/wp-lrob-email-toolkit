<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Admin\Assets as SharedAssets;
use LRob\EmailToolkit\Admin\Combobox;
use LRob\EmailToolkit\Forms\CaptchaField as SharedCaptchaField;
use LRob\EmailToolkit\Forms\FormEditorRenderer;
use LRob\EmailToolkit\Forms\StylePresets;
use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\Captcha\Routing as CaptchaRouting;
use LRob\EmailToolkit\Modules\Newsletter\FormCPT;
use LRob\EmailToolkit\Modules\Newsletter\FormRepository;
use LRob\EmailToolkit\Modules\Newsletter\FormTemplateRegistry;
use LRob\EmailToolkit\Modules\Newsletter\TemplateCPT;
use LRob\EmailToolkit\Modules\Newsletter\TemplateRepository;
use LRob\EmailToolkit\Plugin;

/**
 * Newsletter Forms admin — section content rendered inside the
 * Newsletter homepage hub at `?page=lrob-etk-nl&view=forms`.
 *
 * Mirrors ContactForm's FormsPage UX: one page showing every form as
 * an interactive card in a grid, each carrying its title input,
 * per-form settings, and the shared WYSIWYG editor mounted inline.
 * No separate edit screen. "+ New form" opens a picker modal with
 * starter templates and clone-existing options.
 *
 * Shared CSS primitives (.lrob-etk-identity-card, .lrob-etk-card-form,
 * .lrob-etk-card-status, .lrob-etk-identities, .lrob-etk-cf-form-card,
 * .lrob-etk-cf-fields, etc.) come from admin-base / admin-components /
 * admin-contact-form. The form-builder DOM uses the lrob-etk-form-*
 * prefix from the 0.2.2 refactor; admin chrome from Contact Form
 * (cards, picker modal, delete modal) reuses lrob-etk-cf-* classes so
 * styles match without duplication.
 */
final class FormsPage
{
    public function __construct(
        private FormRepository $forms,
        private TemplateRepository $templates,
    ) {
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        // Hub page hook is "email-toolkit_page_lrob-etk-nl" (or similar).
        // Only enqueue when we're on the Newsletter hub AND the Forms view.
        if (!str_contains($hook_suffix, 'lrob-etk-nl')) {
            return;
        }
        $view = isset($_GET['view']) ? sanitize_key((string) $_GET['view']) : '';
        if ($view !== 'forms') {
            return;
        }

        // Frontend form CSS — reused in the admin preview so the editor
        // looks identical to what visitors see (same trick Contact Form
        // pulls).
        wp_enqueue_style(
            'lrob-etk-cf-frontend',
            LROB_ETK_URL . 'assets/css/contact-form.css',
            [],
            SharedAssets::asset_version_for('assets/css/contact-form.css')
        );

        // Per-form-card auto-save script. Mirrors ContactForm's
        // contact-form-admin.js — listens for blur/change on inputs
        // carrying the auto-save marker class and posts to save_meta.
        wp_enqueue_script(
            'lrob-etk-nl-admin',
            LROB_ETK_URL . 'admin/js/newsletter-admin.js',
            [SharedAssets::HANDLE_CONTROLS_JS],
            SharedAssets::asset_version_for('admin/js/newsletter-admin.js'),
            true
        );
        wp_localize_script('lrob-etk-nl-admin', 'lrobEtkNlAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(AjaxController::NONCE_ACTION),
            'actions' => [
                'saveMeta'      => AjaxController::ACTION_SAVE_META,
                'saveStructure' => AjaxController::ACTION_SAVE_STRUCTURE,
            ],
            'i18n'    => [
                'saving' => __('Saving…', 'lrob-email-toolkit'),
                'saved'  => __('Saved', 'lrob-email-toolkit'),
                'error'  => __('Save failed', 'lrob-email-toolkit'),
            ],
        ]);

        // Shared form-builder WYSIWYG editor — mounted on every card.
        wp_enqueue_script(
            'lrob-etk-form-fields-editor',
            LROB_ETK_URL . 'admin/js/form-fields-editor.js',
            ['lrob-etk-nl-admin'],
            SharedAssets::asset_version_for('admin/js/form-fields-editor.js'),
            true
        );
        $captcha_service = Plugin::instance()->container()->get(CaptchaService::class);
        $captcha_service = $captcha_service instanceof CaptchaService ? $captcha_service : null;
        wp_localize_script('lrob-etk-form-fields-editor', 'lrobEtkFormEditor', [
            'fieldTypes'         => self::field_types(),
            'captchaKey'         => FormCPT::META_CAPTCHA_ROUTE,
            'captchaOptions'     => SharedCaptchaField::build_editor_options(CaptchaRouting::CONTEXT_NEWSLETTER, $captcha_service),
            'placeholderPresets' => [],
            'i18n'               => self::editor_i18n(),
            'save'               => [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(AjaxController::NONCE_ACTION),
                'action'  => AjaxController::ACTION_SAVE_STRUCTURE,
            ],
        ]);

        // Picker modal handler.
        wp_enqueue_script(
            'lrob-etk-nl-new-picker',
            LROB_ETK_URL . 'admin/js/newsletter-new-picker.js',
            [],
            SharedAssets::asset_version_for('admin/js/newsletter-new-picker.js'),
            true
        );
        wp_localize_script('lrob-etk-nl-new-picker', 'lrobEtkNlNewPicker', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => AjaxController::ACTION_CREATE_FORM,
            'nonce'   => wp_create_nonce(AjaxController::NONCE_ACTION),
            'pageUrl' => admin_url('admin.php?page=' . PageController::SLUG . '&view=forms'),
        ]);
    }

    public function render(): void
    {
        $forms = $this->forms->list_published();
        $confirmation_templates = $this->templates->list_by_purpose(TemplateCPT::PURPOSE_CONFIRMATION);
        $resolved_default_template_id = $this->templates->default_id_for_purpose(TemplateCPT::PURPOSE_CONFIRMATION);
        ?>
        <section class="lrob-etk-nl-forms-section">
            <header class="lrob-etk-nl-forms-section-head">
                <h2 class="lrob-etk-section-title"><?php esc_html_e('Subscribe forms', 'lrob-email-toolkit'); ?></h2>
                <button type="button" class="button button-primary" id="lrob-etk-nl-new-form-btn">
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <?php esc_html_e('New subscribe form', 'lrob-email-toolkit'); ?>
                </button>
            </header>

            <?php self::render_new_form_picker(); ?>

            <?php if ($forms === []) : ?>
                <div class="lrob-etk-cf-onboard">
                    <div class="lrob-etk-cf-onboard-icon dashicons dashicons-email-alt" aria-hidden="true"></div>
                    <h3 class="lrob-etk-cf-onboard-title"><?php esc_html_e('Create your first subscribe form', 'lrob-email-toolkit'); ?></h3>
                    <p class="lrob-etk-cf-onboard-text">
                        <?php esc_html_e('Subscribe forms collect new email-only subscribers, with double-opt-in confirmation handled automatically.', 'lrob-email-toolkit'); ?>
                    </p>
                    <button type="button" class="button button-primary button-hero" id="lrob-etk-nl-new-form-btn-empty">
                        <?php esc_html_e('Create a subscribe form', 'lrob-email-toolkit'); ?>
                        <span aria-hidden="true">→</span>
                    </button>
                </div>
            <?php else : ?>
                <div class="lrob-etk-identities lrob-etk-cf-forms-grid">
                    <?php foreach ($forms as $post) : ?>
                        <?php $this->render_form_card($post, $confirmation_templates, $resolved_default_template_id); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php self::render_delete_modal();
    }

    /**
     * Per-form card. Same shape as ContactForm's render_form_card — title
     * + status, settings inputs, inline WYSIWYG editor — but with the
     * smaller newsletter-specific settings surface (no SMTP identity,
     * no recipient picker, no style preset; just confirmation template,
     * default list, success message).
     */
    private function render_form_card(\WP_Post $post, array $confirmation_templates, int $resolved_default_template_id): void
    {
        $form_id = (int) $post->ID;
        $title = (string) $post->post_title;
        $confirmation_template_id = (int) get_post_meta($form_id, FormCPT::META_CONFIRMATION_TEMPLATE_ID, true);
        $success_message = (string) get_post_meta($form_id, FormCPT::META_SUCCESS_MESSAGE, true);
        $style_preset = (string) get_post_meta($form_id, FormCPT::META_STYLE_PRESET, true);

        $delete_url = wp_nonce_url(
            add_query_arg(
                ['action' => AjaxController::ACTION_DELETE_FORM, 'form_id' => $form_id],
                admin_url('admin-post.php')
            ),
            AjaxController::ACTION_DELETE_FORM . '_' . $form_id,
            '_lrob_etk_nonce'
        );

        $template_admin_url = add_query_arg(
            ['page' => PageController::SLUG, 'view' => 'onboarding'],
            admin_url('admin.php')
        );

        // Confirmation-template picker options: "Default (X)" inheritor row
        // + every confirmation-purpose template that exists.
        $tpl_options = [['value' => '0', 'label' => self::resolved_default_template_label($resolved_default_template_id)]];
        foreach ($confirmation_templates as $tpl) {
            $tpl_options[] = [
                'value' => (string) $tpl->ID,
                'label' => $tpl->post_title !== '' ? $tpl->post_title : __('(untitled)', 'lrob-email-toolkit'),
            ];
        }

        // Style preset picker: same shape as Contact Form's, driven by
        // the shared StylePresets registry.
        $preset_options = [['value' => '', 'label' => self::label_default(StylePresets::label_for(StylePresets::DEFAULT_SLUG))]];
        foreach (StylePresets::all() as $value => $label) {
            $preset_options[] = ['value' => (string) $value, 'label' => (string) $label];
        }
        ?>
        <article class="lrob-etk-identity-card lrob-etk-cf-form-card" id="form-<?php echo $form_id; ?>" data-form-id="<?php echo $form_id; ?>">
            <form class="lrob-etk-card-form" novalidate onsubmit="return false">
                <header class="lrob-etk-card-form-head">
                    <input
                        type="text"
                        name="title"
                        class="lrob-etk-title-input lrob-etk-nl-field"
                        data-key="title"
                        value="<?php echo esc_attr($title); ?>"
                        placeholder="<?php esc_attr_e('Form name', 'lrob-email-toolkit'); ?>"
                        autocomplete="off">
                    <?php if ($post->post_status === 'draft') : ?>
                        <span class="lrob-etk-status lrob-etk-status--pending"><?php esc_html_e('Draft', 'lrob-email-toolkit'); ?></span>
                    <?php endif; ?>
                    <span class="lrob-etk-card-status" aria-live="polite"></span>
                </header>

                <section class="lrob-etk-cf-essentials">
                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('Confirmation email template', 'lrob-email-toolkit'); ?></label>
                        <?php Combobox::render_fixed_select(
                            FormCPT::META_CONFIRMATION_TEMPLATE_ID,
                            (string) $confirmation_template_id,
                            $tpl_options,
                            '0',
                            'lrob-etk-nl-field'
                        ); ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: opening + closing <a> tags around "Onboarding view". */
                                esc_html__('Edit confirmation emails in the %sOnboarding view%s.', 'lrob-email-toolkit'),
                                '<a href="' . esc_url($template_admin_url) . '">',
                                '</a>'
                            );
                            ?>
                        </p>
                    </div>

                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('Default list', 'lrob-email-toolkit'); ?></label>
                        <?php /* List CRUD lands with step 4; this picker stays disabled-with-placeholder until then. */ ?>
                        <select class="lrob-etk-nl-field"
                                data-key="<?php echo esc_attr(FormCPT::META_DEFAULT_LIST_ID); ?>"
                                disabled>
                            <option value="0"><?php esc_html_e('No automatic list — coming soon', 'lrob-email-toolkit'); ?></option>
                        </select>
                    </div>
                </section>

                <section class="lrob-etk-cf-style-group">
                    <h3 class="lrob-etk-cf-section-title"><?php esc_html_e('Style', 'lrob-email-toolkit'); ?></h3>
                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('Preset', 'lrob-email-toolkit'); ?></label>
                        <?php Combobox::render_fixed_select(
                            FormCPT::META_STYLE_PRESET,
                            $style_preset,
                            $preset_options,
                            '',
                            'lrob-etk-nl-field'
                        ); ?>
                    </div>
                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('Success message', 'lrob-email-toolkit'); ?></label>
                        <input type="text"
                               class="lrob-etk-nl-field"
                               data-key="<?php echo esc_attr(FormCPT::META_SUCCESS_MESSAGE); ?>"
                               value="<?php echo esc_attr($success_message); ?>"
                               placeholder="<?php esc_attr_e('Thanks! Check your inbox to confirm your subscription.', 'lrob-email-toolkit'); ?>"
                               autocomplete="off">
                    </div>
                </section>

                <?php self::render_fields_editor($form_id); ?>

                <footer class="lrob-etk-card-footer">
                    <div class="lrob-etk-card-footer-actions">
                        <button type="button"
                                class="lrob-etk-card-delete-link"
                                data-cf-delete
                                data-form-title="<?php echo esc_attr($title !== '' ? $title : __('(no title)', 'lrob-email-toolkit')); ?>"
                                data-url-orphan="<?php echo esc_attr($delete_url); ?>"
                                data-url-cascade="<?php echo esc_attr($delete_url); ?>"
                                data-submissions="0"
                                data-submissions-received="0"
                                data-submissions-blocked="0">
                            <?php esc_html_e('Delete', 'lrob-email-toolkit'); ?>
                        </button>
                    </div>
                </footer>
            </form>
        </article>
        <?php
    }

    /**
     * "Default (X)" label used for the inherit row of the confirmation-
     * template picker. Centralised because the lookup is non-trivial
     * (resolve via TemplateRepository, fall back to "no template
     * available" message).
     */
    private static function resolved_default_template_label(int $resolved_default_template_id): string
    {
        if ($resolved_default_template_id <= 0) {
            return __('Default — no template available', 'lrob-email-toolkit');
        }
        $post = get_post($resolved_default_template_id);
        $title = $post instanceof \WP_Post && $post->post_title !== ''
            ? $post->post_title
            : __('(untitled)', 'lrob-email-toolkit');
        return self::label_default($title);
    }

    /** "Default (X)" formatter — matches ContactForm's label_default. */
    private static function label_default(string $value): string
    {
        return sprintf(
            /* translators: %s: name of the value "Default" resolves to (e.g. "Math question") */
            __('Default (%s)', 'lrob-email-toolkit'),
            $value
        );
    }

    /**
     * Mounts the shared WYSIWYG editor inside a card. Mirrors ContactForm's
     * render_fields_editor() — same DOM contract, same JS hooks, same
     * keyboard shortcuts (Ctrl-Z undo, etc.) once form-fields-editor.js
     * picks up the section.
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

            <?php echo FormEditorRenderer::render($form_id, FormCPT::FIELD_NAME_PREFIX, FormCPT::FIELD_ID_PREFIX); ?>

            <template data-field-type-picker>
                <div class="lrob-etk-form-type-picker" role="menu">
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
     * Picker modal: blank / starter template / clone existing. Same DOM
     * shape as ContactForm's so the shared CSS (.lrob-etk-cf-new-picker,
     * .lrob-etk-cf-picker-grid, .lrob-etk-cf-picker-card) applies as-is.
     * Behavior is driven by newsletter-new-picker.js.
     */
    private static function render_new_form_picker(): void
    {
        $templates = FormTemplateRegistry::list_for_picker();
        // No existing-form clone source on first render — but if the
        // page is reloaded after creating a form, the next render will
        // include them via the form list passed in.
        ?>
        <div class="lrob-etk-cf-new-picker" id="lrob-etk-nl-new-picker" hidden>
            <div class="lrob-etk-cf-new-picker-inner">
                <header>
                    <h3><?php esc_html_e('Start a new subscribe form', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-cf-icon-btn" data-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </header>

                <section>
                    <h4><?php esc_html_e('Starter templates', 'lrob-email-toolkit'); ?></h4>
                    <div class="lrob-etk-cf-picker-grid">
                        <button type="button" class="lrob-etk-cf-picker-card" data-source="blank">
                            <strong><?php esc_html_e('Blank form', 'lrob-email-toolkit'); ?></strong>
                            <span><?php esc_html_e('Start empty and add the fields you want.', 'lrob-email-toolkit'); ?></span>
                        </button>
                        <?php foreach ($templates as $tpl) : ?>
                            <button type="button" class="lrob-etk-cf-picker-card" data-source="template" data-slug="<?php echo esc_attr($tpl['slug']); ?>">
                                <strong><?php echo esc_html($tpl['name']); ?></strong>
                                <span><?php echo esc_html($tpl['description']); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }

    /**
     * Shared delete-confirm modal. Same JS pattern as ContactForm: the
     * delete trigger button carries data-cf-delete + data-url-orphan;
     * inline script reads those and confirms before navigating.
     */
    private static function render_delete_modal(): void
    {
        ?>
        <div class="lrob-etk-modal lrob-etk-cf-delete-modal" id="lrob-etk-nl-delete-modal" role="dialog" aria-modal="true" aria-labelledby="lrob-etk-nl-delete-title" hidden>
            <div class="lrob-etk-modal-backdrop" data-cf-delete-cancel></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--small">
                <header class="lrob-etk-modal-header">
                    <h3 id="lrob-etk-nl-delete-title" class="lrob-etk-modal-title-text"></h3>
                    <button type="button" class="lrob-etk-modal-close" data-cf-delete-cancel aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p><?php esc_html_e('This will permanently delete the subscribe form. Existing subscribers are not affected.', 'lrob-email-toolkit'); ?></p>
                </div>
                <footer class="lrob-etk-modal-footer lrob-etk-cf-delete-footer">
                    <button type="button" class="button" data-cf-delete-cancel>
                        <?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button" class="button button-link-delete" data-cf-delete-choice="cascade">
                        <?php esc_html_e('Delete', 'lrob-email-toolkit'); ?>
                    </button>
                </footer>
            </div>
        </div>
        <script>
        (function () {
            var modal = document.getElementById('lrob-etk-nl-delete-modal');
            if (!modal) return;
            var titleEl = modal.querySelector('#lrob-etk-nl-delete-title');
            var deleteBtn = modal.querySelector('[data-cf-delete-choice]');
            var currentTrigger = null;

            function open(btn) {
                currentTrigger = btn;
                var formTitle = btn.getAttribute('data-form-title') || '';
                titleEl.textContent =
                    <?php /* translators: %s: subscribe form title */ ?>
                    <?php echo wp_json_encode(__('Delete subscribe form "%s"?', 'lrob-email-toolkit')); ?>
                        .replace('%s', formTitle);
                modal.hidden = false;
                document.body.classList.add('lrob-etk-modal-open');
                deleteBtn.focus();
            }
            function close() {
                modal.hidden = true;
                document.body.classList.remove('lrob-etk-modal-open');
                currentTrigger = null;
            }
            document.addEventListener('click', function (e) {
                var trigger = e.target.closest && e.target.closest('[data-cf-delete]');
                if (trigger && trigger.closest('[data-form-id]')) {
                    // Only fire on triggers inside a newsletter form card
                    // (contact form cards use the same data-cf-delete attribute).
                    var card = trigger.closest('[data-form-id]');
                    if (card && card.closest('#lrob-etk-nl-new-picker') === null
                        && card.closest('.lrob-etk-cf-form-card')
                        && card.closest('.lrob-etk-nl-forms-section')) {
                        e.preventDefault();
                        open(trigger);
                        return;
                    }
                }
                if (e.target.closest && e.target.closest('#lrob-etk-nl-delete-modal [data-cf-delete-cancel]')) {
                    e.preventDefault();
                    close();
                    return;
                }
                var choice = e.target.closest && e.target.closest('#lrob-etk-nl-delete-modal [data-cf-delete-choice]');
                if (choice && currentTrigger) {
                    var url = currentTrigger.getAttribute('data-url-orphan');
                    if (url) window.location.href = url;
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.hidden) close();
            });
        })();
        </script>
        <?php
    }

    /**
     * Field-type picker for newsletter subscribe forms. Subset of the
     * full form-builder vocabulary — list-picker / category-picker
     * join in step 4 when lists + categories CRUD ships.
     *
     * @return array<string, string>
     */
    private static function field_types(): array
    {
        return [
            'email'  => __('Email', 'lrob-email-toolkit'),
            'text'   => __('Text (name, etc.)', 'lrob-email-toolkit'),
            'submit' => __('Submit button', 'lrob-email-toolkit'),
        ];
    }

    /** @return array<string, string> */
    private static function editor_i18n(): array
    {
        return [
            'addField'          => __('Add field', 'lrob-email-toolkit'),
            'fieldOptions'      => __('Field options', 'lrob-email-toolkit'),
            'slug'              => __('Field slug', 'lrob-email-toolkit'),
            'helper'            => __('Helper text', 'lrob-email-toolkit'),
            'required'          => __('Required', 'lrob-email-toolkit'),
            'placeholder'       => __('Placeholder', 'lrob-email-toolkit'),
            'maxLength'         => __('Max length', 'lrob-email-toolkit'),
            'alignment'         => __('Alignment', 'lrob-email-toolkit'),
            'alignLeft'         => __('Left', 'lrob-email-toolkit'),
            'alignCenter'       => __('Center', 'lrob-email-toolkit'),
            'alignRight'        => __('Right', 'lrob-email-toolkit'),
            'alignStretch'      => __('Full width', 'lrob-email-toolkit'),
            'toggleRequired'    => __('Toggle required', 'lrob-email-toolkit'),
            'helperPlaceholder' => __('(optional helper text)', 'lrob-email-toolkit'),
            'labelPlaceholder'  => __('(field label)', 'lrob-email-toolkit'),
            'undo'              => __('Undo', 'lrob-email-toolkit'),
            'redo'              => __('Redo', 'lrob-email-toolkit'),
            'fieldLabel'        => __('Field', 'lrob-email-toolkit'),
        ];
    }
}
