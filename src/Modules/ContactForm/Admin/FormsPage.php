<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Admin\Assets;
use LRob\EmailToolkit\Admin\Combobox;
use LRob\EmailToolkit\Admin\PageHeader;
use LRob\EmailToolkit\Admin\RetentionToggle;
use LRob\EmailToolkit\Admin\Tooltip;
use LRob\EmailToolkit\Forms\CaptchaField as SharedCaptchaField;
use LRob\EmailToolkit\Forms\CountryData;
use LRob\EmailToolkit\Forms\FormEditorRenderer;
use LRob\EmailToolkit\Forms\FormStructure;
use LRob\EmailToolkit\Forms\StylePresets;
use LRob\EmailToolkit\Forms\Upload\UploadPolicy;
use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\ContactForm\Admin\SubmissionsAjax;
use LRob\EmailToolkit\Modules\ContactForm\CPT;
use LRob\EmailToolkit\Modules\ContactForm\Frontend as FormFrontend;
use LRob\EmailToolkit\Modules\ContactForm\Module as ContactFormModule;
use LRob\EmailToolkit\Modules\ContactForm\Settings;
use LRob\EmailToolkit\Modules\ContactForm\SubmissionRepository;
use LRob\EmailToolkit\Modules\ContactForm\TemplateRegistry;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Plugin;

// Docs: docs/contact-form.md
final class FormsPage
{
    public const SLUG = 'lrob-etk-cform';

    public const ACTION_DELETE_FORM = 'lrob_etk_cform_delete';

    /** Sub-view of this page that delegates rendering to SubmissionsPage. */
    public const VIEW_SUBMISSIONS = 'submissions';

    public function __construct(
        private ContactFormModule $module,
        private SubmissionsPage $submissions_page,
    ) {
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

        wp_enqueue_script(
            'lrob-etk-cf-submissions-inbox',
            LROB_ETK_URL . 'admin/js/contact-form-submissions-inbox.js',
            [Assets::HANDLE_LIST_FILTER_JS],
            self::asset_version('admin/js/contact-form-submissions-inbox.js'),
            true
        );
        $auto_open = 0;
        if (
            isset($_GET['view'], $_GET['detail'])
            && sanitize_key((string) $_GET['view']) === self::VIEW_SUBMISSIONS
        ) {
            $auto_open = max(0, (int) $_GET['detail']);
        }
        wp_localize_script('lrob-etk-cf-submissions-inbox', 'lrobEtkCfInbox', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce(SubmissionsAjax::NONCE_ACTION),
            'actionFilter'  => SubmissionsAjax::ACTION_FILTER,
            'actionBulk'    => SubmissionsAjax::ACTION_BULK,
            'actionDetail'  => SubmissionsAjax::ACTION_DETAIL,
            'autoOpen'      => $auto_open,
            'i18n'          => [
                /* translators: %d: count of selected submissions */
                'confirmSpam'      => __('Mark the %d selected submissions as spam?', 'lrob-email-toolkit'),
                'confirmSpamOne'   => __('Mark this submission as spam?', 'lrob-email-toolkit'),
                'confirmSpamBtn'   => __('Yes, mark as spam', 'lrob-email-toolkit'),
                'spamReversible'   => __('Spam-marked submissions stay in the inbox under the Spam filter — you can restore them at any time.', 'lrob-email-toolkit'),
                /* translators: %d: count of selected submissions */
                'confirmDelete'    => __('Permanently delete the %d selected submissions?', 'lrob-email-toolkit'),
                'confirmDeleteOne' => __('Delete this submission?', 'lrob-email-toolkit'),
                'confirmDeleteBtn' => __('Yes, delete permanently', 'lrob-email-toolkit'),
                'deleteIrrev'      => __('This is irreversible. Field data and any attached files will be permanently removed.', 'lrob-email-toolkit'),
                'cancel'           => __('Cancel', 'lrob-email-toolkit'),
                'close'            => __('Close', 'lrob-email-toolkit'),
                'nothingPicked'    => __('Select at least one submission first.', 'lrob-email-toolkit'),
                'selectAction'     => __('Choose a bulk action first.', 'lrob-email-toolkit'),
                /* translators: %d: count of selected submissions */
                'selectedCount'    => __('%d selected', 'lrob-email-toolkit'),
                'error'            => __('Something went wrong. Please try again.', 'lrob-email-toolkit'),
                'detailPrev'       => __('Previous', 'lrob-email-toolkit'),
                'detailNext'       => __('Next', 'lrob-email-toolkit'),
                'detailLoading'    => __('Loading…', 'lrob-email-toolkit'),
                'detailMarkSpam'   => __('Mark as spam', 'lrob-email-toolkit'),
                'detailRestore'    => __('Restore from spam', 'lrob-email-toolkit'),
                'detailDelete'     => __('Delete', 'lrob-email-toolkit'),
            ],
        ]);

        FormFrontend::enqueue_assets();

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

        // form-fields-editor.js is host-neutral — Newsletter reuses the same handle.
        wp_enqueue_script(
            'lrob-etk-form-fields-editor',
            LROB_ETK_URL . 'admin/js/form-fields-editor.js',
            ['lrob-etk-cf-admin'],
            self::asset_version('admin/js/form-fields-editor.js'),
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

        $captcha_service = self::captcha_service();
        $captcha_options = SharedCaptchaField::build_editor_options('contact_form', $captcha_service);
        wp_localize_script('lrob-etk-form-fields-editor', 'lrobEtkFormEditor', [
            'fieldTypes'     => self::field_types(),
            'captchaKey'     => CPT::META_CHALLENGE_KIND,
            'captchaOptions' => $captcha_options,
            'countries'      => CountryData::all_translated(),
            'uploadPresets'         => self::upload_preset_options(),
            'uploadDeliveryOptions' => self::upload_delivery_options(),
            'uploadTier1Extensions' => UploadPolicy::tier1_extensions(),
            'uploadTier2Extensions' => UploadPolicy::tier2_extensions(),
            'serverMaxUploadBytes'  => (int) wp_max_upload_size(),
            'save' => [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(AjaxController::NONCE_ACTION),
                'action'  => AjaxController::ACTION_SAVE_STRUCTURE,
            ],
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
                'countryPicker'      => __('Country code picker', 'lrob-email-toolkit'),
                'defaultCountry'     => __('Default', 'lrob-email-toolkit'),
                'autoDetectCountry'  => __('Auto-detect from browser', 'lrob-email-toolkit'),
                'autoFromLocale'     => __('Auto (locale)', 'lrob-email-toolkit'),
                'multiple'           => __('Multiple choices', 'lrob-email-toolkit'),
                'multipleFiles'      => __('Multiple files', 'lrob-email-toolkit'),
                'maxCount'           => __('Max files', 'lrob-email-toolkit'),
                'maxSizeMb'          => __('Max MB per file', 'lrob-email-toolkit'),
                'totalSizeMb'        => __('Max MB total', 'lrob-email-toolkit'),
                'acceptPreset'       => __('Types', 'lrob-email-toolkit'),
                'acceptCustom'       => __('Custom (e.g. pdf, jpg)', 'lrob-email-toolkit'),
                'stripExif'          => __('Strip image metadata', 'lrob-email-toolkit'),
                'allowDangerous'     => __('I understand the risks (allow scripts / executables)', 'lrob-email-toolkit'),
                'fileDelivery'       => __('Delivery', 'lrob-email-toolkit'),
                /* translators: %d: max number of MB the host server allows per upload (computed live). */
                'serverMaxHint'      => __('(server max %d MB)', 'lrob-email-toolkit'),
                'chooseFile'         => __('Choose a file', 'lrob-email-toolkit'),
                /* translators: %d: max number of files the field allows. */
                'chooseFilesMulti'   => __('Choose files (max %d)', 'lrob-email-toolkit'),
                /* translators: 1: max size per file MB, 2: comma-separated extension list */
                'uploadHintTpl'      => __('Max %1$d MB per file — %2$s', 'lrob-email-toolkit'),
                'uploadHintNoLimit'  => __('no file type restriction', 'lrob-email-toolkit'),
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

    /** Sentinel stored in reply_to_field when the form explicitly opts OUT of any Reply-To header. */
    public const REPLY_TO_NONE = '__none__';

    private static function captcha_service(): ?CaptchaService
    {
        $container = Plugin::instance()->container();
        return $container->has(CaptchaService::class) ? $container->get(CaptchaService::class) : null;
    }

    /**
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

    /** @return array<int, array{value:string, label:string}> */
    private static function known_email_suggestions(): array
    {
        $out = [];
        $seen = [];
        $admin = (string) get_option('admin_email');

        // "Default" entry (empty value): picking it clears the row so the field
        // stays unset and inherits the global default recipient at send time —
        // kept live rather than freezing an address into the form. Mirrors the
        // Subject / Success-message dropdowns' "Default" behaviour.
        $global_recipient = trim((string) (Settings::all()[Settings::KEY_RECIPIENT] ?? ''));
        $effective_default = $global_recipient !== '' ? $global_recipient : $admin;
        if ($effective_default !== '') {
            $out[] = [
                'value' => '',
                'label' => sprintf(
                    /* translators: %s: what "Default" resolves to — a picker option name (e.g. "Math question") or the fallback email address; shown as "Default (X)" */
                    __('Default (%s)', 'lrob-email-toolkit'),
                    $effective_default
                ),
            ];
        }

        if ($admin !== '' && is_email($admin)) {
            $out[] = [
                'value' => $admin,
                'label' => sprintf(
                    /* translators: %s: site admin email address */
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
                    /* translators: %s: current user's email address */
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

    // Known-emails list injected client-side so it isn't repeated per card.
    private static function render_recipients(string $current_value, string $placeholder, string $key = CPT::META_RECIPIENT): void
    {
        $emails = array_filter(array_map('trim', explode(',', $current_value)));
        if ($emails === []) {
            $emails = [''];
        }
        ?>
        <div class="lrob-etk-recipient-list" data-recipient-input>
            <input type="hidden"
                   name="<?php echo esc_attr($key); ?>"
                   class="lrob-etk-cf-field"
                   data-key="<?php echo esc_attr($key); ?>"
                   value="<?php echo esc_attr($current_value); ?>">
            <div class="lrob-etk-recipient-list-rows" data-recipient-rows>
                <?php foreach ($emails as $i => $email) : ?>
                    <div class="lrob-etk-recipient-row">
                        <div class="lrob-etk-combo">
                            <input type="email"
                                   class="lrob-etk-combo-input"
                                   value="<?php echo esc_attr((string) $email); ?>"
                                   placeholder="<?php echo esc_attr($placeholder); ?>"
                                   autocomplete="off">
                            <button type="button" class="lrob-etk-combo-toggle" aria-label="<?php esc_attr_e('Pick a known email', 'lrob-email-toolkit'); ?>" title="<?php esc_attr_e('Pick a known email', 'lrob-email-toolkit'); ?>">
                                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                            </button>
                        </div>
                        <button type="button" class="lrob-etk-recipient-remove" aria-label="<?php esc_attr_e('Remove this recipient', 'lrob-email-toolkit'); ?>" title="<?php esc_attr_e('Remove', 'lrob-email-toolkit'); ?>">
                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button button-small lrob-etk-recipient-add" data-recipient-add>
                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                <?php esc_html_e('Add recipient', 'lrob-email-toolkit'); ?>
            </button>
        </div>
        <?php
    }

    /** @param array<int, array{value:string, label:string}> $suggestions */
    private static function render_free_combobox(string $meta_key, string $current_value, array $suggestions, string $placeholder = ''): void
    {
        Combobox::render_free_text($meta_key, $current_value, $suggestions, $placeholder, 'lrob-etk-cf-field');
    }

    private static function render_combobox(string $meta_key, string $current_value, array $options, string $inherit_value = ''): void
    {
        Combobox::render_fixed_select($meta_key, $current_value, $options, $inherit_value, 'lrob-etk-cf-field');
    }

    public function render(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }

        $view = isset($_GET['view']) && is_string($_GET['view']) ? sanitize_key((string) $_GET['view']) : '';
        if ($view === self::VIEW_SUBMISSIONS) {
            $this->submissions_page->render();
            return;
        }

        $enabled = $this->module->is_enabled();
        ?>
        <div class="wrap lrob-etk lrob-etk-cform-page">
            <?php PageHeader::render([
                'title'   => __('Contact Forms', 'lrob-email-toolkit'),
                'module'  => $this->module,
                'primary' => [
                    'label' => __('New form', 'lrob-email-toolkit'),
                    'icon'  => 'dashicons-plus-alt2',
                    'id'    => 'lrob-etk-cf-new-form-btn',
                ],
                'tools'   => [
                    [
                        'label' => __('Defaults', 'lrob-email-toolkit'),
                        'icon'  => 'dashicons-admin-generic',
                        'id'    => 'lrob-etk-defaults-btn',
                        'attrs' => ['data-defaults-modal-open' => null],
                    ],
                    [
                        'label' => __('Storage', 'lrob-email-toolkit'),
                        'icon'  => 'dashicons-database',
                        'id'    => 'lrob-etk-cf-storage-btn',
                    ],
                ],
                'nav'     => [
                    [
                        'label' => __('Submissions', 'lrob-email-toolkit'),
                        'icon'  => 'dashicons-feedback',
                        'href'  => SubmissionsPage::base_url(),
                    ],
                ],
            ]); ?>

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
                <?php $this->render_submissions_panel(); ?>
                <?php $this->render_defaults_modal(); ?>
                <?php $this->render_storage_modal(); ?>
                <?php $this->render_delete_modal(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // Two delete paths (orphan vs cascade) are pre-signed; modal JS picks one on click.
    private function render_delete_modal(): void
    {
        ?>
        <div class="lrob-etk-modal lrob-etk-cf-delete-modal" id="lrob-etk-cf-delete-modal" role="dialog" aria-modal="true" aria-labelledby="lrob-etk-cf-delete-title" hidden>
            <div class="lrob-etk-modal-backdrop" data-cf-delete-cancel></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--small">
                <header class="lrob-etk-modal-header">
                    <h3 id="lrob-etk-cf-delete-title" class="lrob-etk-modal-title-text"></h3>
                    <button type="button" class="lrob-etk-modal-close" data-cf-delete-cancel aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p class="lrob-etk-delete-summary"></p>
                    <p class="lrob-etk-delete-warning lrob-etk-delete-warning--no-subs">
                        <?php esc_html_e('This form has no submissions yet. Deleting is final.', 'lrob-email-toolkit'); ?>
                    </p>
                </div>
                <footer class="lrob-etk-modal-footer lrob-etk-delete-footer">
                    <button type="button" class="button" data-cf-delete-cancel>
                        <?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button" class="button lrob-etk-cf-delete-orphan" data-cf-delete-choice="orphan">
                        <?php esc_html_e('Delete form only', 'lrob-email-toolkit'); ?>
                    </button>
                    <button type="button" class="button button-link-delete lrob-etk-cf-delete-cascade" data-cf-delete-choice="cascade">
                        <?php esc_html_e('Delete form + submissions', 'lrob-email-toolkit'); ?>
                    </button>
                </footer>
            </div>
        </div>
        <script>
        (function () {
            var modal = document.getElementById('lrob-etk-cf-delete-modal');
            if (!modal) return;
            var titleEl   = modal.querySelector('#lrob-etk-cf-delete-title');
            var summary   = modal.querySelector('.lrob-etk-delete-summary');
            var noSubsMsg = modal.querySelector('.lrob-etk-delete-warning--no-subs');
            var orphanBtn = modal.querySelector('.lrob-etk-cf-delete-orphan');
            var cascadeBtn = modal.querySelector('.lrob-etk-cf-delete-cascade');
            var currentTrigger = null;

            function open(btn) {
                currentTrigger = btn;
                var formTitle = btn.getAttribute('data-form-title') || '';
                var total = parseInt(btn.getAttribute('data-submissions') || '0', 10);
                var received = parseInt(btn.getAttribute('data-submissions-received') || '0', 10);
                var blocked = parseInt(btn.getAttribute('data-submissions-blocked') || '0', 10);

                titleEl.textContent =
                    <?php /* translators: %s: contact form title */ ?>
                    <?php echo wp_json_encode(__('Delete contact form "%s"?', 'lrob-email-toolkit')); ?>
                        .replace('%s', formTitle);

                if (total > 0) {
                    var parts = [];
                    if (received > 0) parts.push(received + ' ' + (received === 1
                        ? <?php echo wp_json_encode(__('received', 'lrob-email-toolkit')); ?>
                        : <?php echo wp_json_encode(__('received', 'lrob-email-toolkit')); ?>));
                    if (blocked > 0) parts.push(blocked + ' ' + <?php echo wp_json_encode(__('blocked', 'lrob-email-toolkit')); ?>);
                    summary.textContent =
                        <?php /* translators: %1$d total submissions, %2$s breakdown */ ?>
                        <?php echo wp_json_encode(__('This form has %1$d submissions (%2$s). What should happen to them?', 'lrob-email-toolkit')); ?>
                            .replace('%1$d', total)
                            .replace('%2$s', parts.join(', '));
                    summary.hidden = false;
                    noSubsMsg.hidden = true;
                    orphanBtn.hidden = false;
                    cascadeBtn.textContent =
                        <?php /* translators: %d: number of submissions */ ?>
                        <?php echo wp_json_encode(__('Delete form + %d submissions', 'lrob-email-toolkit')); ?>
                            .replace('%d', total);
                } else {
                    summary.hidden = true;
                    noSubsMsg.hidden = false;
                    orphanBtn.hidden = true;
                    cascadeBtn.textContent = <?php echo wp_json_encode(__('Delete', 'lrob-email-toolkit')); ?>;
                }

                modal.hidden = false;
                document.body.classList.add('lrob-etk-modal-open');
                cascadeBtn.focus();
            }

            function close() {
                modal.hidden = true;
                document.body.classList.remove('lrob-etk-modal-open');
                currentTrigger = null;
            }

            document.addEventListener('click', function (e) {
                var trigger = e.target.closest && e.target.closest('[data-cf-delete]');
                if (trigger) {
                    e.preventDefault();
                    open(trigger);
                    return;
                }
                if (e.target.closest && e.target.closest('[data-cf-delete-cancel]')) {
                    e.preventDefault();
                    close();
                    return;
                }
                var choice = e.target.closest && e.target.closest('[data-cf-delete-choice]');
                if (choice && currentTrigger) {
                    var mode = choice.getAttribute('data-cf-delete-choice');
                    var url = mode === 'cascade'
                        ? currentTrigger.getAttribute('data-url-cascade')
                        : currentTrigger.getAttribute('data-url-orphan');
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

    private function render_submissions_panel(): void
    {
        $counts = (new SubmissionRepository())->counts_by_status();
        if ($counts['total'] === 0) {
            return;
        }
        $inbox = SubmissionsPage::base_url();
        $blocked_url = add_query_arg('status', SubmissionRepository::STATUS_SPAM_BLOCKED, $inbox);
        $delivered_url = add_query_arg('status', SubmissionRepository::STATUS_DELIVERED, $inbox);
        $failed_url = add_query_arg('status', SubmissionRepository::STATUS_FAILED, $inbox);
        ?>
        <section class="lrob-etk-cf-submissions-panel">
            <header class="lrob-etk-cf-submissions-panel-head">
                <h2 class="lrob-etk-section-title"><?php esc_html_e('Submissions', 'lrob-email-toolkit'); ?></h2>
                <a href="<?php echo esc_url($inbox); ?>" class="button button-primary">
                    <?php esc_html_e('View all submissions', 'lrob-email-toolkit'); ?>
                    <span aria-hidden="true">→</span>
                </a>
            </header>
            <div class="lrob-etk-cf-submissions-panel-stats">
                <a class="lrob-etk-cf-submissions-stat" href="<?php echo esc_url($delivered_url); ?>">
                    <span class="lrob-etk-cf-submissions-stat-value"><?php echo esc_html(number_format_i18n($counts['delivered'])); ?></span>
                    <span class="lrob-etk-cf-submissions-stat-label"><?php esc_html_e('Delivered', 'lrob-email-toolkit'); ?></span>
                </a>
                <a class="lrob-etk-cf-submissions-stat" href="<?php echo esc_url($blocked_url); ?>">
                    <span class="lrob-etk-cf-submissions-stat-value"><?php echo esc_html(number_format_i18n($counts['blocked'])); ?></span>
                    <span class="lrob-etk-cf-submissions-stat-label"><?php esc_html_e('Spam blocked', 'lrob-email-toolkit'); ?></span>
                </a>
                <?php if ($counts['failed'] > 0) : ?>
                    <a class="lrob-etk-cf-submissions-stat is-failed" href="<?php echo esc_url($failed_url); ?>">
                        <span class="lrob-etk-cf-submissions-stat-value"><?php echo esc_html(number_format_i18n($counts['failed'])); ?></span>
                        <span class="lrob-etk-cf-submissions-stat-label"><?php esc_html_e('Failed to send', 'lrob-email-toolkit'); ?></span>
                    </a>
                <?php endif; ?>
                <div class="lrob-etk-cf-submissions-stat is-total">
                    <span class="lrob-etk-cf-submissions-stat-value"><?php echo esc_html(number_format_i18n($counts['total'])); ?></span>
                    <span class="lrob-etk-cf-submissions-stat-label"><?php esc_html_e('Total (all time)', 'lrob-email-toolkit'); ?></span>
                </div>
            </div>
        </section>
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
            <div class="lrob-etk-card-grid lrob-etk-card-grid--wide">
                <?php foreach ($forms as $form) : ?>
                    <?php $this->render_form_card($form, $identities, $globals); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * @param array{id:int, title:string, status:string, created:string, submissions:int} $form
     * @param array<int, array{id:int, label:string, is_default:bool}> $identities
     * @param array<string, mixed> $globals
     */
    private function render_form_card(array $form, array $identities, array $globals): void
    {
        $form_id = (int) $form['id'];
        $edit_url = admin_url('post.php?action=edit&post=' . $form_id);
        $delete_url_base = wp_nonce_url(
            add_query_arg(
                ['action' => self::ACTION_DELETE_FORM, 'form_id' => $form_id],
                admin_url('admin-post.php')
            ),
            self::ACTION_DELETE_FORM . '_' . $form_id,
            '_lrob_etk_nonce'
        );
        // Two delete paths: orphan submissions (keep historical record) vs
        // cascade (drop them too). Modal JS picks one when the user clicks
        // Delete; URLs are pre-signed so the modal stays stateless.
        $delete_url_orphan = $delete_url_base;
        $delete_url_cascade = add_query_arg('cascade', 'yes', $delete_url_base);
        $form_received = (int) ($form['submissions_received'] ?? 0);
        $form_blocked = (int) ($form['submissions_blocked'] ?? 0);
        $form_submissions_total = $form_received + $form_blocked;
        $created_display = $form['created'] !== ''
            ? mysql2date(get_option('date_format'), $form['created'])
            : '';
        $title = $form['title'];

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
            'save_subs'    => (string) get_post_meta($form_id, CPT::META_SAVE_SUBMISSIONS, true),
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

        $save_default_label = !empty($globals[Settings::KEY_SAVE_SUBMISSIONS])
            ? __('On', 'lrob-email-toolkit')
            : __('Off', 'lrob-email-toolkit');
        $save_value = $meta['save_subs'] !== '' ? $meta['save_subs'] : 'default';
        $save_subs_options = [
            ['value' => 'default', 'label' => self::label_default($save_default_label)],
            ['value' => 'on',      'label' => __('On',  'lrob-email-toolkit')],
            ['value' => 'off',     'label' => __('Off', 'lrob-email-toolkit')],
        ];

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
            $builtin_prefix = __('Built-in', 'lrob-email-toolkit');
            foreach ($captcha_service->homemade_challenges() as $slug => $challenge) {
                $challenge_options[] = [
                    'value' => \LRob\EmailToolkit\Modules\Captcha\Routing::homemade($slug),
                    'label' => sprintf('%s: %s', $builtin_prefix, (string) $challenge->label()),
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
                    $name = $identity->label !== '' ? $identity->label : (string) $provider->label();
                    $challenge_options[] = [
                        'value' => \LRob\EmailToolkit\Modules\Captcha\Routing::identity((int) $identity->id),
                        'label' => sprintf('%s: %s', $provider->label(), $name),
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
        <?php
        // Save-state attributes scoped to the card: CSS uses them to surface a
        // warning under each file_upload field when "Save submissions" is off
        // (uploads silently fall back to email-attachment mode at submit time).
        $save_global_default = !empty($globals[Settings::KEY_SAVE_SUBMISSIONS]);
        $save_effective_off = !Settings::effective_save_submissions($form_id);
        ?>
        <article class="lrob-etk-card lrob-etk-form-card"
                 data-form-id="<?php echo $form_id; ?>"
                 data-save-global-off="<?php echo $save_global_default ? '0' : '1'; ?>"
                 data-save-effective-off="<?php echo $save_effective_off ? '1' : '0'; ?>">
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
                        <span class="lrob-etk-status lrob-etk-state--pending"><?php esc_html_e('Draft', 'lrob-email-toolkit'); ?></span>
                    <?php endif; ?>
                    <span class="lrob-etk-card-status" aria-live="polite"></span>
                </header>

                <section class="lrob-etk-form-essentials">
                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('Recipients', 'lrob-email-toolkit'); ?></label>
                        <?php if ($no_recipient_anywhere) : ?>
                            <div class="lrob-etk-banner-warning" role="status">
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

                <section class="lrob-etk-form-style-group">
                    <h3 class="lrob-etk-section-title"><?php esc_html_e('Style', 'lrob-email-toolkit'); ?></h3>
                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('Preset', 'lrob-email-toolkit'); ?></label>
                        <?php self::render_combobox(CPT::META_STYLE_PRESET, $meta['style_preset'], $preset_options); ?>
                    </div>
                </section>

                <?php self::render_fields_editor($form_id); ?>

                <section class="lrob-etk-form-success-message">
                    <div class="lrob-etk-field">
                        <label>
                            <?php esc_html_e('Success message', 'lrob-email-toolkit'); ?>
                            <?php Tooltip::render(__('Shown to the visitor right after they submit the form. Leave empty to use the site-language default (it then updates automatically if the site language changes) — the dropdown resets to that default.', 'lrob-email-toolkit')); ?>
                        </label>
                        <?php self::render_free_combobox(
                            CPT::META_SUCCESS_MESSAGE,
                            $meta['success'],
                            // Empty value = keep the field unset so it inherits the
                            // live site-language default (dynamic), instead of
                            // freezing today's default text into the field.
                            [['value' => '', 'label' => $success_placeholder]],
                            $success_placeholder
                        ); ?>
                    </div>
                </section>

                <?php
                $received = (int) ($form['submissions_received'] ?? 0);
                $blocked = (int) ($form['submissions_blocked'] ?? 0);
                if ($received > 0 || $blocked > 0) :
                    $submissions_url = add_query_arg('form_id', $form_id, SubmissionsPage::base_url());
                    ?>
                    <a class="lrob-etk-cf-submissions-link" href="<?php echo esc_url($submissions_url); ?>">
                        <span class="lrob-etk-cf-submissions-link-received">
                            <strong><?php echo esc_html(number_format_i18n($received)); ?></strong>
                            <?php echo esc_html(_n('received', 'received', $received, 'lrob-email-toolkit')); ?>
                        </span>
                        <?php if ($blocked > 0) : ?>
                            <span class="lrob-etk-cf-submissions-link-sep">·</span>
                            <span class="lrob-etk-cf-submissions-link-blocked">
                                <strong><?php echo esc_html(number_format_i18n($blocked)); ?></strong>
                                <?php esc_html_e('blocked', 'lrob-email-toolkit'); ?>
                            </span>
                        <?php endif; ?>
                        <span class="lrob-etk-cf-submissions-link-arrow" aria-hidden="true">→</span>
                    </a>
                <?php endif; ?>

                <details class="lrob-etk-form-advanced">
                    <summary class="lrob-etk-advanced-summary">
                        <span class="lrob-etk-advanced-caret" aria-hidden="true">▸</span>
                        <span class="lrob-etk-advanced-label"><?php esc_html_e('Advanced settings', 'lrob-email-toolkit'); ?></span>
                        <?php if ($created_display !== '') : ?>
                            <span class="lrob-etk-advanced-meta">
                                <?php
                                /* translators: %s: localized creation date */
                                printf(esc_html__('Since %s', 'lrob-email-toolkit'), esc_html($created_display));
                                ?>
                            </span>
                        <?php endif; ?>
                    </summary>
                    <div class="lrob-etk-advanced-body">
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
                                        [['value' => '', 'label' => $subject_placeholder]],
                                        $subject_placeholder
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
                                <div class="lrob-etk-field">
                                    <label>
                                        <?php esc_html_e('Save submissions', 'lrob-email-toolkit'); ?>
                                        <?php Tooltip::render(__('Archive received submissions to the database. When off, the notification email still goes out but no row is written and this form drops out of the Submissions inbox.', 'lrob-email-toolkit')); ?>
                                    </label>
                                    <?php self::render_combobox(CPT::META_SAVE_SUBMISSIONS, $save_value, $save_subs_options, 'default'); ?>
                                </div>

                                <h3 class="lrob-etk-form-column-head" style="margin-top: 12px;">
                                    <span class="lrob-etk-form-column-title"><?php esc_html_e('Throttling', 'lrob-email-toolkit'); ?></span>
                                    <?php Tooltip::render(__('Server-side rate limit per submitter (identified by IP hash). Blocks the same address from re-submitting more than Max times within Window minutes.', 'lrob-email-toolkit')); ?>
                                </h3>
                                <div class="lrob-etk-defaults-inline-pair">
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
                        <button type="button"
                                class="lrob-etk-card-delete-link"
                                data-cf-delete
                                data-form-title="<?php echo esc_attr($title !== '' ? $title : __('(no title)', 'lrob-email-toolkit')); ?>"
                                data-submissions="<?php echo (int) $form_submissions_total; ?>"
                                data-submissions-blocked="<?php echo (int) $form_blocked; ?>"
                                data-submissions-received="<?php echo (int) $form_received; ?>"
                                data-url-orphan="<?php echo esc_attr($delete_url_orphan); ?>"
                                data-url-cascade="<?php echo esc_attr($delete_url_cascade); ?>">
                            <?php esc_html_e('Delete', 'lrob-email-toolkit'); ?>
                        </button>
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
        <section class="lrob-etk-form-fields" data-form-id="<?php echo $form_id; ?>">
            <div class="lrob-etk-form-editor-toolbar">
                <div class="lrob-etk-form-editor-toolbar-actions">
                    <button type="button"
                            class="lrob-etk-form-editor-toolbar-btn lrob-etk-form-editor-toolbar-btn--primary"
                            data-editor-action="add-field"
                            title="<?php esc_attr_e('Add a field', 'lrob-email-toolkit'); ?>"
                            aria-label="<?php esc_attr_e('Add a field', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    </button>
                    <button type="button"
                            class="lrob-etk-form-editor-toolbar-btn"
                            data-editor-action="undo"
                            disabled
                            title="<?php esc_attr_e('Undo (Ctrl+Z)', 'lrob-email-toolkit'); ?>"
                            aria-label="<?php esc_attr_e('Undo', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-undo" aria-hidden="true"></span>
                    </button>
                    <button type="button"
                            class="lrob-etk-form-editor-toolbar-btn"
                            data-editor-action="redo"
                            disabled
                            title="<?php esc_attr_e('Redo (Ctrl+Shift+Z)', 'lrob-email-toolkit'); ?>"
                            aria-label="<?php esc_attr_e('Redo', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-redo" aria-hidden="true"></span>
                    </button>
                </div>
                <span class="lrob-etk-form-editor-status" aria-live="polite"></span>
            </div>

            <?php echo FormEditorRenderer::render($form_id, CPT::FIELD_NAME_PREFIX, CPT::FIELD_ID_PREFIX); ?>

            <!-- Field-type picker (cloned by the editor JS each time + is clicked) -->
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
            /* translators: %s: what "Default" resolves to — a picker option name (e.g. "Math question") or the fallback email address; shown as "Default (X)" */
            __('Default (%s)', 'lrob-email-toolkit'),
            $value
        );
    }

    private static function field_types(): array
    {
        return [
            'text'        => __('Text',         'lrob-email-toolkit'),
            'email'       => __('Email',        'lrob-email-toolkit'),
            'textarea'    => __('Long text',    'lrob-email-toolkit'),
            'number'      => __('Number',       'lrob-email-toolkit'),
            'phone'       => __('Phone',        'lrob-email-toolkit'),
            'date'        => __('Date',         'lrob-email-toolkit'),
            'select'      => __('Dropdown',     'lrob-email-toolkit'),
            'radio'       => __('Radio',        'lrob-email-toolkit'),
            'checkbox'    => __('Checkbox',     'lrob-email-toolkit'),
            'file_upload' => __('File upload',  'lrob-email-toolkit'),
        ];
    }

    private static function field_type_label(string $type): string
    {
        return self::field_types()[$type] ?? $type;
    }

    /**
     * Localised delivery options for the file_upload field's delivery
     * combobox. Same shape as upload_preset_options for symmetry.
     *
     * @return list<array{value:string, label:string}>
     */
    private static function upload_delivery_options(): array
    {
        return [
            ['value' => UploadPolicy::DELIVERY_WEBSERVER,  'label' => __('Save on web server', 'lrob-email-toolkit')],
            ['value' => UploadPolicy::DELIVERY_ATTACHMENT, 'label' => __('Attach to email',  'lrob-email-toolkit')],
            ['value' => UploadPolicy::DELIVERY_BOTH,       'label' => __('Both — save + attach', 'lrob-email-toolkit')],
        ];
    }

    /**
     * Localised preset list passed to the form-builder editor JS for the
     * file_upload field's accept-preset combobox. Each entry carries the
     * preset slug, its translated label, and the resolved extension list
     * for the inline hint (e.g. "pdf, jpg, png").
     *
     * @return list<array{value:string, label:string, exts:string}>
     */
    private static function upload_preset_options(): array
    {
        $labels = UploadPolicy::preset_labels();
        $out = [];
        foreach (UploadPolicy::presets() as $slug => $exts) {
            $out[] = [
                'value' => $slug,
                'label' => $labels[$slug] ?? $slug,
                'exts'  => implode(', ', $exts),
            ];
        }
        return $out;
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
        <div class="lrob-etk-modal" id="lrob-etk-defaults-modal" role="dialog" aria-modal="true" aria-labelledby="lrob-etk-defaults-title" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog">
                <header class="lrob-etk-modal-header">
                    <h3 id="lrob-etk-defaults-title" class="lrob-etk-modal-title-text"><?php esc_html_e('Default settings for new forms', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p class="description" style="margin-top: 0;">
                        <?php esc_html_e('Apply to every form unless overridden in the form\'s settings. Changes save automatically.', 'lrob-email-toolkit'); ?>
                    </p>

        <article class="lrob-etk-card lrob-etk-form-card lrob-etk-form-card--defaults" data-defaults-card="1">
            <form class="lrob-etk-card-form" onsubmit="return false">
                <header class="lrob-etk-card-form-head">
                    <span class="lrob-etk-card-status" aria-live="polite"></span>
                </header>

                <section class="lrob-etk-form-essentials">
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

                <section class="lrob-etk-form-style-group">
                    <h3 class="lrob-etk-section-title"><?php esc_html_e('Style', 'lrob-email-toolkit'); ?></h3>
                    <div class="lrob-etk-form-style-grid">
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

                <div class="lrob-etk-advanced-body">
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
                                        wp_kses(
                                            /* translators: %s: URL to the Captcha settings page */
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
                            <div class="lrob-etk-defaults-inline-pair">
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
     * Storage modal — split from the Defaults modal so the two concerns
     * stay distinct: Defaults is "how new forms behave", Storage is
     * "what we keep, how, how long, with what privacy". Submissions are
     * currently the only thing the toolkit captures with a client IP
     * (outbound logs have no client IP), so this lives on the Contact
     * Form page rather than in a plugin-level settings screen.
     *
     * Reuses the same auto-save mechanism as the Defaults modal: the
     * article carries `data-defaults-card="1"`, every input has a
     * `data-key` matching a key in AjaxController::DEFAULT_KEYS, and the
     * shared JS save handler in contact-form-admin.js binds on init.
     */
    /**
     * Public + static so both this page (FormsPage) and the Submissions
     * inbox (SubmissionsPage) can render the same modal. Inputs use the
     * shared `lrob-etk-cf-field + data-key` auto-save plumbing wired by
     * contact-form-admin.js — same JS works on both pages.
     */
    public static function render_storage_modal(): void
    {
        $s = Settings::all();
        $save_options = [
            ['value' => '1', 'label' => __('On', 'lrob-email-toolkit')],
            ['value' => '0', 'label' => __('Off', 'lrob-email-toolkit')],
        ];
        $ip_options = [
            ['value' => '0', 'label' => __('Hash only (recommended)', 'lrob-email-toolkit')],
            ['value' => '1', 'label' => __('Store raw IP', 'lrob-email-toolkit')],
        ];
        $save_subs_on = !empty($s[Settings::KEY_SAVE_SUBMISSIONS]);
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-cf-storage-modal" role="dialog" aria-modal="true" aria-labelledby="lrob-etk-cf-storage-title" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--wide">
                <header class="lrob-etk-modal-header">
                    <h3 id="lrob-etk-cf-storage-title" class="lrob-etk-modal-title-text"><?php esc_html_e('Submissions storage', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <p class="description" style="margin-top: 0;">
                        <?php esc_html_e('What gets kept when a form is submitted, for how long, and how IPs are recorded. Changes save automatically.', 'lrob-email-toolkit'); ?>
                    </p>

                    <article class="lrob-etk-card lrob-etk-form-card lrob-etk-form-card--defaults" data-defaults-card="1" data-storage-card<?php echo $save_subs_on ? '' : ' data-save-off'; ?>>
                        <form class="lrob-etk-card-form" onsubmit="return false">
                            <header class="lrob-etk-card-form-head">
                                <span class="lrob-etk-card-status" aria-live="polite"></span>
                            </header>

                            <section class="lrob-etk-cf-submissions-storage-section">
                                <h4 class="lrob-etk-popover-section-title"><?php esc_html_e('Archive policy', 'lrob-email-toolkit'); ?></h4>
                                <div class="lrob-etk-defaults-grid">
                                    <div class="lrob-etk-field">
                                        <label>
                                            <?php esc_html_e('Save submissions', 'lrob-email-toolkit'); ?>
                                            <?php Tooltip::render(__('Master switch. When off, the notification email still goes out but nothing is archived (captcha + honeypot still run). All other settings below are inert.', 'lrob-email-toolkit')); ?>
                                        </label>
                                        <?php self::render_combobox(Settings::KEY_SAVE_SUBMISSIONS, $save_subs_on ? '1' : '0', $save_options); ?>
                                    </div>
                                    <div class="lrob-etk-field" data-storage-conditional>
                                        <label>
                                            <?php esc_html_e('IP storage', 'lrob-email-toolkit'); ?>
                                            <?php Tooltip::render(__('Hashed by default for privacy / GDPR friendliness. Storing the raw IP makes abuse investigation easier but requires a lawful basis in some jurisdictions.', 'lrob-email-toolkit')); ?>
                                        </label>
                                        <?php self::render_combobox(Settings::KEY_STORE_RAW_IP, !empty($s[Settings::KEY_STORE_RAW_IP]) ? '1' : '0', $ip_options); ?>
                                    </div>
                                </div>

                                <h4 class="lrob-etk-popover-section-title" data-storage-conditional><?php esc_html_e('Spam recording', 'lrob-email-toolkit'); ?></h4>
                                <p class="description" data-storage-conditional><?php esc_html_e('Even when archiving is on, you can skip these bot-blocked rows so the inbox stays clean.', 'lrob-email-toolkit'); ?></p>
                                <div class="lrob-etk-defaults-grid" data-storage-conditional>
                                    <div class="lrob-etk-field">
                                        <label>
                                            <?php esc_html_e('Save honeypot / time-trap', 'lrob-email-toolkit'); ?>
                                            <?php Tooltip::render(__('Almost always bots — keep off unless you\'re debugging your forms.', 'lrob-email-toolkit')); ?>
                                        </label>
                                        <?php self::render_combobox(Settings::KEY_SAVE_SPAM_BOT, !empty($s[Settings::KEY_SAVE_SPAM_BOT]) ? '1' : '0', $save_options); ?>
                                    </div>
                                    <div class="lrob-etk-field">
                                        <label>
                                            <?php esc_html_e('Save captcha-failed', 'lrob-email-toolkit'); ?>
                                            <?php Tooltip::render(__('Captcha can fail for legitimate users (typos, slow connections). Keep on so you can recover their messages.', 'lrob-email-toolkit')); ?>
                                        </label>
                                        <?php self::render_combobox(Settings::KEY_SAVE_SPAM_CAPTCHA, !empty($s[Settings::KEY_SAVE_SPAM_CAPTCHA]) ? '1' : '0', $save_options); ?>
                                    </div>
                                </div>

                                <h4 class="lrob-etk-popover-section-title" data-storage-conditional><?php esc_html_e('Automatic retention', 'lrob-email-toolkit'); ?></h4>
                                <p class="description" data-storage-conditional><?php esc_html_e('Older rows are deleted daily by a cron event. Each bucket has its own toggle — turn it on only for rows you want auto-pruned.', 'lrob-email-toolkit'); ?></p>
                                <div class="lrob-etk-defaults-grid" data-storage-conditional>
                                    <div class="lrob-etk-field">
                                        <label><?php esc_html_e('Delivered', 'lrob-email-toolkit'); ?></label>
                                        <?php RetentionToggle::render([
                                            'key'              => Settings::KEY_RETENTION_DELIVERED_DAYS,
                                            'value'            => (int) $s[Settings::KEY_RETENTION_DELIVERED_DAYS],
                                            'auto_save_marker' => 'lrob-etk-cf-field',
                                            'default_days'     => 365,
                                        ]); ?>
                                    </div>
                                    <div class="lrob-etk-field">
                                        <label><?php esc_html_e('Received', 'lrob-email-toolkit'); ?></label>
                                        <?php RetentionToggle::render([
                                            'key'              => Settings::KEY_RETENTION_RECEIVED_DAYS,
                                            'value'            => (int) $s[Settings::KEY_RETENTION_RECEIVED_DAYS],
                                            'auto_save_marker' => 'lrob-etk-cf-field',
                                            'default_days'     => 365,
                                        ]); ?>
                                    </div>
                                    <div class="lrob-etk-field">
                                        <label>
                                            <?php esc_html_e('Failed', 'lrob-email-toolkit'); ?>
                                            <?php Tooltip::render(__('Failed sends often hold useful debug info (rejected mailboxes, SMTP errors). Auto-deleting them is usually undesirable.', 'lrob-email-toolkit')); ?>
                                        </label>
                                        <?php RetentionToggle::render([
                                            'key'              => Settings::KEY_RETENTION_FAILED_DAYS,
                                            'value'            => (int) $s[Settings::KEY_RETENTION_FAILED_DAYS],
                                            'auto_save_marker' => 'lrob-etk-cf-field',
                                            'default_days'     => 365,
                                        ]); ?>
                                    </div>
                                    <div class="lrob-etk-field">
                                        <label><?php esc_html_e('Spam', 'lrob-email-toolkit'); ?></label>
                                        <?php RetentionToggle::render([
                                            'key'              => Settings::KEY_RETENTION_SPAM_DAYS,
                                            'value'            => (int) $s[Settings::KEY_RETENTION_SPAM_DAYS],
                                            'auto_save_marker' => 'lrob-etk-cf-field',
                                            'default_days'     => 90,
                                        ]); ?>
                                    </div>
                                </div>

                                <h4 class="lrob-etk-popover-section-title"><?php esc_html_e('Manual cleanup', 'lrob-email-toolkit'); ?></h4>
                                <p class="description"><?php esc_html_e('One-shot deletion. Pick an age + the statuses to include, then run. Works on existing rows even when archiving is off.', 'lrob-email-toolkit'); ?></p>
                                <div class="lrob-etk-cleanup-row">
                                    <label>
                                        <?php esc_html_e('Delete submissions older than', 'lrob-email-toolkit'); ?>
                                        <input type="number" id="lrob-etk-cf-cleanup-days" class="small-text" min="1" max="3650" value="30">
                                        <?php esc_html_e('days, in:', 'lrob-email-toolkit'); ?>
                                    </label>
                                </div>
                                <div class="lrob-etk-cleanup-statuses">
                                    <label><input type="checkbox" data-cf-cleanup-status value="delivered" checked> <?php esc_html_e('Delivered', 'lrob-email-toolkit'); ?></label>
                                    <label><input type="checkbox" data-cf-cleanup-status value="received"> <?php esc_html_e('Received', 'lrob-email-toolkit'); ?></label>
                                    <label><input type="checkbox" data-cf-cleanup-status value="failed"> <?php esc_html_e('Failed', 'lrob-email-toolkit'); ?></label>
                                    <label><input type="checkbox" data-cf-cleanup-status value="spam_blocked" checked> <?php esc_html_e('Spam', 'lrob-email-toolkit'); ?></label>
                                </div>
                                <div class="lrob-etk-cleanup-actions">
                                    <button type="button" class="button button-secondary" id="lrob-etk-cf-cleanup-apply">
                                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                        <?php esc_html_e('Delete matching submissions', 'lrob-email-toolkit'); ?>
                                    </button>
                                </div>
                                <div class="lrob-etk-cleanup-result lrob-etk-test-result" hidden></div>
                            </section>
                        </form>
                    </article>
                </div>
            </div>
        </div>
        <script>
        // Co-located with the modal markup so it ships on whichever page
        // renders the modal (Forms admin OR Submissions inbox). Guarded so
        // double-rendering the modal in the same page wouldn't double-bind.
        (function () {
            if (window.__lrobEtkCfStorageCleanupBound) return;
            window.__lrobEtkCfStorageCleanupBound = true;

            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce   = <?php echo wp_json_encode(wp_create_nonce(SubmissionsAjax::NONCE_ACTION)); ?>;
            var action  = <?php echo wp_json_encode(SubmissionsAjax::ACTION_PURGE); ?>;
            var i18n = {
                error:    <?php echo wp_json_encode(__('Something went wrong.', 'lrob-email-toolkit')); ?>,
                working:  <?php echo wp_json_encode(__('Working…', 'lrob-email-toolkit')); ?>,
                noStatus: <?php echo wp_json_encode(__('Tick at least one status.', 'lrob-email-toolkit')); ?>,
                confirm:  <?php
                    /* translators: %d: number of days */
                    echo wp_json_encode(__('Delete every submission older than %d days in the selected statuses? This cannot be undone.', 'lrob-email-toolkit'));
                ?>

            };

            function whenReady(fn) {
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
                else fn();
            }

            whenReady(function () {
                var btn = document.getElementById('lrob-etk-cf-cleanup-apply');
                if (!btn) return;
                var modal = document.getElementById('lrob-etk-cf-storage-modal');
                if (!modal) return;
                var result = modal.querySelector('.lrob-etk-cleanup-result');

                btn.addEventListener('click', function () {
                    var daysEl = document.getElementById('lrob-etk-cf-cleanup-days');
                    var days = daysEl ? Math.max(1, parseInt(daysEl.value, 10) || 1) : 30;
                    var statuses = [];
                    modal.querySelectorAll('[data-cf-cleanup-status]:checked').forEach(function (cb) {
                        statuses.push(cb.value);
                    });
                    if (statuses.length === 0) {
                        if (result) {
                            result.hidden = false;
                            result.className = 'lrob-etk-test-result lrob-etk-cleanup-result is-failure';
                            result.textContent = '✗ ' + i18n.noStatus;
                        }
                        return;
                    }
                    var askPromise = window.lrobEtkConfirm
                        ? window.lrobEtkConfirm.prompt({
                            title: i18n.confirmTitle || 'Run cleanup?',
                            message: i18n.confirm.replace('%d', days),
                            confirmLabel: i18n.confirmLabel || 'Delete',
                            danger: true
                        })
                        : Promise.resolve(true);
                    askPromise.then(function (ok) {
                        if (!ok) return;
                        btn.disabled = true;
                        if (result) {
                            result.hidden = false;
                            result.className = 'lrob-etk-test-result lrob-etk-cleanup-result is-pending';
                            result.textContent = i18n.working;
                        }

                        var fd = new FormData();
                        fd.append('action', action);
                        fd.append('_ajax_nonce', nonce);
                        fd.append('days', String(days));
                        statuses.forEach(function (s) { fd.append('statuses[]', s); });

                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(function (r) { return r.json(); })
                            .then(function (resp) {
                                btn.disabled = false;
                                if (resp && resp.success) {
                                    if (result) {
                                        result.className = 'lrob-etk-test-result lrob-etk-cleanup-result is-success';
                                        result.textContent = '✓ ' + resp.data.message;
                                    }
                                    setTimeout(function () { window.location.reload(); }, 800);
                                } else if (result) {
                                    result.className = 'lrob-etk-test-result lrob-etk-cleanup-result is-failure';
                                    result.textContent = '✗ ' + ((resp && resp.data && resp.data.message) || i18n.error);
                                }
                            })
                            .catch(function () {
                                btn.disabled = false;
                                if (result) {
                                    result.className = 'lrob-etk-test-result lrob-etk-cleanup-result is-failure';
                                    result.textContent = '✗ ' + i18n.error;
                                }
                            });
                    });
                });
            });
        })();
        </script>
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

        // Cascade flag: 'yes' = drop submissions too, anything else = orphan
        // (submissions stay browsable in the inbox under "Deleted form #N").
        // Modal UI in render_form_card surfaces the choice; if the request
        // arrives without the flag (e.g. direct URL), default to orphaning
        // since that's non-destructive for the historical record.
        $cascade = isset($_GET['cascade']) && (string) $_GET['cascade'] === 'yes';

        $post = get_post($form_id);
        if ($post instanceof \WP_Post && $post->post_type === CPT::POST_TYPE) {
            if ($cascade) {
                // Files get nuked alongside submissions when the admin opts
                // into the destructive cascade. Without cascade, files stay
                // on disk attached to orphaned submission rows — admin can
                // clean them up later via the Storage maintenance tab.
                $container = Plugin::instance()->container();
                if ($container->has(\LRob\EmailToolkit\Modules\ContactForm\FileRepository::class)) {
                    $file_repo = $container->get(\LRob\EmailToolkit\Modules\ContactForm\FileRepository::class);
                    if ($file_repo instanceof \LRob\EmailToolkit\Modules\ContactForm\FileRepository) {
                        $file_repo->delete_by_form($form_id);
                    }
                }
                (new SubmissionRepository())->delete_for_form($form_id);
            }
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
            $split = $submissions_repo
                ? $submissions_repo->counts_for_form_split((int) $post->ID)
                : ['received' => 0, 'blocked' => 0];
            $out[] = [
                'id'                  => (int) $post->ID,
                'title'               => (string) $post->post_title,
                'status'              => (string) $post->post_status,
                'created'             => (string) $post->post_date_gmt,
                'submissions'         => $split['received'] + $split['blocked'],
                'submissions_received' => $split['received'],
                'submissions_blocked'  => $split['blocked'],
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

    /**
     * Style presets shared with Newsletter via src/Forms/StylePresets.
     * Kept as a public static here so any consumer that still imports
     * it from ContactForm (legacy) continues to work.
     *
     * @return array<string, string>
     */
    public static function style_presets(): array
    {
        return StylePresets::all();
    }
}
