<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Admin\Assets as SharedAssets;
use LRob\EmailToolkit\Admin\PageHeader;
use LRob\EmailToolkit\Forms\CaptchaField as SharedCaptchaField;
use LRob\EmailToolkit\Forms\CountryData;
use LRob\EmailToolkit\Forms\FormEditorRenderer;
use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\Captcha\Routing as CaptchaRouting;
use LRob\EmailToolkit\Modules\ContactForm\Frontend as ContactFormFrontend;
use LRob\EmailToolkit\Modules\Newsletter\FormCPT;
use LRob\EmailToolkit\Modules\Newsletter\FormRepository;
use LRob\EmailToolkit\Modules\Newsletter\FormTemplateRegistry;
use LRob\EmailToolkit\Modules\Newsletter\ListRepository;
use LRob\EmailToolkit\Modules\Newsletter\Lists\RuleRegistry;
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
 * .lrob-etk-card-status, .lrob-etk-card-grid, .lrob-etk-form-card,
 * .lrob-etk-form-fields, etc.) come from admin-base / admin-components /
 * admin-contact-form. The form-builder DOM uses the lrob-etk-form-*
 * prefix from the 0.2.2 refactor; admin chrome from Contact Form
 * (cards, picker modal, delete modal) reuses lrob-etk-cf-* classes so
 * styles match without duplication.
 */
final class FormsPage
{
    private SignupFormCardRenderer $form_cards;

    public function __construct(
        private FormRepository $forms,
        private TemplateRepository $templates,
    ) {
        $this->form_cards = new SignupFormCardRenderer();
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        // Hub page hook is "email-toolkit_page_lrob-etk-nl" (or similar).
        // Only enqueue when we're on the Newsletter hub AND the Forms view.
        // The hub-wide newsletter-admin.js (auto-save listener) is enqueued
        // separately by HomePage::enqueue_assets so it's available across
        // every hub view, not just Forms.
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

        // Frontend form JS — gives the editor preview the phone country
        // picker hydration (`window.lrobEtkPhone.attach`) and the country
        // list (`window.lrobEtkForm.countries`). Submit handler is a no-op
        // on the editor's <div> wrapper.
        ContactFormFrontend::enqueue_assets();

        // Shared form-builder WYSIWYG editor — mounted on every card.
        // Depends on the hub-wide newsletter-admin.js for auto-save
        // status indicator flashing.
        wp_enqueue_script(
            'lrob-etk-form-fields-editor',
            LROB_ETK_URL . 'admin/js/form-fields-editor.js',
            [HomePage::HANDLE_ADMIN_JS],
            SharedAssets::asset_version_for('admin/js/form-fields-editor.js'),
            true
        );
        $captcha_service = Plugin::instance()->container()->get(CaptchaService::class);
        $captcha_service = $captcha_service instanceof CaptchaService ? $captcha_service : null;
        $maps_to_targets = [];
        foreach (\LRob\EmailToolkit\Modules\Newsletter\SubscriberFields::PROFILE_COLUMNS as $col) {
            $maps_to_targets[] = ['value' => $col, 'label' => \LRob\EmailToolkit\Modules\Newsletter\SubscriberFields::label($col)];
        }
        wp_localize_script('lrob-etk-form-fields-editor', 'lrobEtkFormEditor', [
            'fieldTypes'         => self::field_types(),
            'captchaKey'         => FormCPT::META_CAPTCHA_ROUTE,
            'captchaOptions'     => SharedCaptchaField::build_editor_options(CaptchaRouting::CONTEXT_NEWSLETTER, $captcha_service),
            'countries'          => CountryData::all_translated(),
            'placeholderPresets' => [],
            // List of subscriber-profile column targets the per-field
            // "Maps to" picker offers. Empty on Contact Form pages —
            // only Newsletter subscribe forms actually populate
            // subscriber columns at submit time.
            'mapsToTargets'      => $maps_to_targets,
            // Quick-add presets surfaced at the top of the field-type
            // picker. Pre-mapped to the right subscriber column so the
            // admin doesn't have to set `Maps to` by hand.
            'fieldPresets'       => self::field_presets(),
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

    public function render(?HomePage $hub = null): void
    {
        $forms = $this->forms->list_published();
        $confirmation_templates = $this->templates->list_by_purpose(TemplateCPT::PURPOSE_CONFIRMATION);
        $resolved_default_template_id = $this->templates->default_id_for_purpose(TemplateCPT::PURPOSE_CONFIRMATION);
        PageHeader::render([
            /* translators: %s: sub-page name (e.g. "Forms", "Subscribers") shown after "Newsletters —". */
            'title'   => sprintf(__('Newsletters — %s', 'lrob-email-toolkit'), __('Forms', 'lrob-email-toolkit')),
            'primary' => [
                'label' => __('New subscribe form', 'lrob-email-toolkit'),
                'icon'  => 'dashicons-plus-alt2',
                'id'    => 'lrob-etk-nl-new-form-btn',
            ],
            'tools' => [HomePage::subscription_emails_tool(), HomePage::settings_tool()],
        ]);
        if ($hub) $hub->render_section_tabs(HomePage::VIEW_FORMS);
        ?>
        <section class="lrob-etk-nl-forms-section">

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
                <div class="lrob-etk-card-grid lrob-etk-card-grid--wide">
                    <?php foreach ($forms as $post) : ?>
                        <?php $this->form_cards->render($post, $confirmation_templates, $resolved_default_template_id); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php self::render_delete_modal();
    }


    /**
     * Default-lists picker — same dropdown idiom as the newsletter
     * card's audience picker (same CSS classes; behaviour driven by
     * the shared admin/js/etk-audience-picker.js, parameterised via
     * data attrs). Multi-select; persists the picked IDs to
     * `META_DEFAULT_LIST_IDS` via the `default_list_ids` pseudo-key.
     *
     * Renders only the picker shell; the JS handles open/close,
     * persist, and summary updates. Reuses every `.lrob-etk-nl-
     * audience-*` style — no form-specific CSS needed.
     */
    public static function render_default_lists_picker(int $form_id): void
    {
        $repo = new ListRepository();
        // Only admin-created Subscribers lists are eligible as a form
        // default. System lists (All subscribers / All WP members /
        // All WC customers / Active WC subscribers) are computed —
        // adding a fresh subscriber to them either no-ops or makes
        // no semantic sense. WP users lists are rule-based — they
        // don't accept manual subscriber memberships at all.
        $lists = array_values(array_filter(
            $repo->list_all(),
            static fn (array $row): bool =>
                ListRepository::kind_of($row) === ListRepository::KIND_SUBSCRIBERS
                && !ListRepository::is_system($row)
        ));
        $counts = $repo->member_counts();
        $opted_out = $repo->opted_out_counts_per_list();
        $rule_providers = RuleRegistry::all();

        // Read both new (plural) + legacy (singular) so older forms
        // pre-fill correctly on first render.
        $raw = (string) get_post_meta($form_id, FormCPT::META_DEFAULT_LIST_IDS, true);
        $picked = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $picked = array_values(array_filter(array_map('intval', $decoded), static fn ($n) => $n > 0));
            }
        }
        if ($picked === []) {
            $legacy = (int) get_post_meta($form_id, FormCPT::META_DEFAULT_LIST_ID, true);
            if ($legacy > 0) {
                $picked = [$legacy];
            }
        }

        $by_id = [];
        foreach ($lists as $l) {
            $by_id[(int) ($l['id'] ?? 0)] = $l;
        }

        // Group by kind; system rows pushed to the end. Same logic the
        // newsletter audience picker uses — admins recognise the order.
        $subs_lists = $user_lists = $subs_system = $user_system = [];
        foreach ($lists as $list) {
            $k = ListRepository::kind_of($list);
            $is_sys = ListRepository::is_system($list);
            if ($k === ListRepository::KIND_ALL_SUBSCRIBERS || $k === ListRepository::KIND_SUBSCRIBERS) {
                $is_sys ? $subs_system[] = $list : $subs_lists[] = $list;
            } elseif ($k === ListRepository::KIND_USERS) {
                $is_sys ? $user_system[] = $list : $user_lists[] = $list;
            }
        }
        $subs_lists = array_merge($subs_lists, $subs_system);
        $user_lists = array_merge($user_lists, $user_system);

        $summary = '';
        if ($picked !== []) {
            $names = [];
            foreach ($picked as $lid) {
                if (isset($by_id[$lid])) {
                    $names[] = (string) ($by_id[$lid]['name'] ?? '');
                }
            }
            $summary = implode(', ', $names);
        }
        ?>
        <div class="lrob-etk-nl-audience"
             data-audience-picker
             data-audience-action="<?php echo esc_attr(AjaxController::ACTION_SAVE_META); ?>"
             data-audience-key="default_list_ids"
             data-audience-id-param="form_id"
             data-audience-id="<?php echo (int) $form_id; ?>"
             data-audience-nonce="<?php echo esc_attr(wp_create_nonce(AjaxController::NONCE_ACTION)); ?>"
             data-audience-ajax-url="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>"
             data-audience-empty-label="<?php esc_attr_e('no automatic list', 'lrob-email-toolkit'); ?>">
            <button type="button"
                    class="lrob-etk-nl-audience-trigger"
                    data-audience-toggle
                    aria-haspopup="true"
                    aria-expanded="false">
                <span class="lrob-etk-nl-audience-summary">
                    <em data-audience-lists-summary class="lrob-etk-nl-audience-summary-lists">
                        <?php
                        echo esc_html($summary !== ''
                            ? $summary
                            : __('no automatic list', 'lrob-email-toolkit'));
                        ?>
                    </em>
                </span>
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
            <div class="lrob-etk-nl-audience-menu" data-audience-menu hidden role="menu">
                <?php
                $render_section = static function (string $title, array $section_lists) use ($counts, $opted_out, $picked, $rule_providers): void {
                    if ($section_lists === []) return;
                    ?>
                    <div class="lrob-etk-nl-audience-section">
                        <h4 class="lrob-etk-nl-audience-section-title"><?php echo esc_html($title); ?></h4>
                        <ul class="lrob-etk-nl-audience-list">
                            <?php foreach ($section_lists as $list) :
                                $lid = (int) ($list['id'] ?? 0);
                                if ($lid <= 0) continue;
                                $cnt = (int) ($counts[$lid] ?? 0);
                                $oo  = (int) ($opted_out[$lid] ?? 0);
                                $checked = in_array($lid, $picked, true);
                                $is_sys = ListRepository::is_system($list);
                                $rule = ListRepository::decode_rule((string) ($list['rule_json'] ?? ''));
                                $provider_slug = $rule['provider'] ?? '';
                                ?>
                                <li class="lrob-etk-nl-audience-item">
                                    <label>
                                        <input type="checkbox" data-audience-list="<?php echo $lid; ?>" <?php checked($checked); ?>>
                                        <span class="lrob-etk-nl-audience-item-name"><?php echo esc_html((string) ($list['name'] ?? '')); ?></span>
                                        <?php if ($is_sys) : ?>
                                            <span class="lrob-etk-nl-list-system-badge"
                                                  title="<?php esc_attr_e('Built-in list — cannot be renamed or deleted.', 'lrob-email-toolkit'); ?>">
                                                <?php esc_html_e('System', 'lrob-email-toolkit'); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($provider_slug !== '' && isset($rule_providers[$provider_slug])) : ?>
                                            <span class="lrob-etk-nl-list-provider-badge"
                                                  title="<?php echo esc_attr($rule_providers[$provider_slug]->label()); ?>">
                                                <?php echo esc_html($rule_providers[$provider_slug]->label()); ?>
                                            </span>
                                        <?php endif; ?>
                                        <span class="lrob-etk-nl-audience-item-counts">
                                            <?php if ($oo > 0) : ?>
                                                <span class="lrob-etk-nl-audience-item-optout">
                                                    <?php printf(
                                                        /* translators: %s: number of opted-out users (already formatted). */
                                                        esc_html__('−%s opt-out', 'lrob-email-toolkit'),
                                                        esc_html(number_format_i18n($oo))
                                                    ); ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="lrob-etk-nl-audience-item-count">
                                                <?php echo esc_html(number_format_i18n($cnt)); ?>
                                            </span>
                                        </span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php
                };
                $render_section(__('Subscribers lists', 'lrob-email-toolkit'), $subs_lists);
                $render_section(__('WP users lists', 'lrob-email-toolkit'), $user_lists);
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * "Default (X)" label used for the inherit row of the confirmation-
     * template picker. Centralised because the lookup is non-trivial
     * (resolve via TemplateRepository, fall back to "no template
     * available" message).
     */
    public static function resolved_default_template_label(int $resolved_default_template_id): string
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
    public static function label_default(string $value): string
    {
        return sprintf(
            /* translators: %s: what "Default" resolves to — a picker option name (e.g. "Math question") or the fallback email address; shown as "Default (X)" */
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
    public static function render_fields_editor(int $form_id): void
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

            <?php echo FormEditorRenderer::render($form_id, FormCPT::FIELD_NAME_PREFIX, FormCPT::FIELD_ID_PREFIX); ?>

            <template data-field-type-picker>
                <div class="lrob-etk-form-type-picker" role="menu">
                    <?php $presets = self::field_presets(); ?>
                    <?php if ($presets !== []) : ?>
                        <div class="lrob-etk-form-type-picker-section">
                            <span class="lrob-etk-form-type-picker-section-label"><?php esc_html_e('Quick add (pre-mapped)', 'lrob-email-toolkit'); ?></span>
                            <?php foreach ($presets as $preset) : ?>
                                <button type="button"
                                        role="menuitem"
                                        class="lrob-etk-form-type-picker-preset"
                                        data-preset="<?php echo esc_attr((string) $preset['slug']); ?>">
                                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                                    <?php echo esc_html((string) $preset['label']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="lrob-etk-form-type-picker-section">
                            <span class="lrob-etk-form-type-picker-section-label"><?php esc_html_e('Generic fields', 'lrob-email-toolkit'); ?></span>
                            <?php foreach (self::field_types() as $type => $label) : ?>
                                <button type="button" role="menuitem" data-type="<?php echo esc_attr($type); ?>">
                                    <?php echo esc_html($label); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <?php foreach (self::field_types() as $type => $label) : ?>
                            <button type="button" role="menuitem" data-type="<?php echo esc_attr($type); ?>">
                                <?php echo esc_html($label); ?>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
                <footer class="lrob-etk-modal-footer lrob-etk-delete-footer">
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
                        && card.closest('.lrob-etk-form-card')
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
     * full form-builder vocabulary scoped to fields that make sense
     * on a signup form.
     *
     * @return array<string, string>
     */
    private static function field_types(): array
    {
        return [
            'email'            => __('Email', 'lrob-email-toolkit'),
            'text'             => __('Text (name, etc.)', 'lrob-email-toolkit'),
            'phone'            => __('Phone', 'lrob-email-toolkit'),
            'gender'           => __('Gender', 'lrob-email-toolkit'),
            'select'           => __('Dropdown (select)', 'lrob-email-toolkit'),
            'list_picker'      => __('List picker', 'lrob-email-toolkit'),
            'submit'           => __('Submit button', 'lrob-email-toolkit'),
        ];
    }

    /**
     * Quick-add presets surfaced at the top of the field-type picker.
     * Each preset expands into one or more pre-mapped fields so admins
     * don't have to drop a generic Text + set its `Maps to` themselves.
     * Storage shape is plain: every preset compiles to existing field
     * types (no new types — the FieldTypeRegistry stays untouched).
     *
     * @return array<int, array{slug:string,label:string,fields:array<int, array<string, mixed>>}>
     */
    private static function field_presets(): array
    {
        return [
            [
                'slug'   => 'name_full',
                'label'  => __('Full name', 'lrob-email-toolkit'),
                'fields' => [
                    ['type' => 'text', 'label' => __('Full name', 'lrob-email-toolkit'), 'maps_to' => 'name', 'required' => true],
                ],
            ],
            [
                'slug'   => 'name_split',
                'label'  => __('First + Last name', 'lrob-email-toolkit'),
                'fields' => [
                    ['type' => 'text', 'label' => __('First name', 'lrob-email-toolkit'), 'maps_to' => 'first_name'],
                    ['type' => 'text', 'label' => __('Last name',  'lrob-email-toolkit'), 'maps_to' => 'last_name'],
                ],
            ],
            [
                'slug'   => 'phone',
                'label'  => __('Phone', 'lrob-email-toolkit'),
                'fields' => [
                    ['type' => 'phone', 'label' => __('Phone', 'lrob-email-toolkit'), 'maps_to' => 'phone'],
                ],
            ],
            [
                'slug'   => 'address',
                'label'  => __('Postal address', 'lrob-email-toolkit'),
                'fields' => [
                    ['type' => 'text',   'label' => __('Street address', 'lrob-email-toolkit'), 'maps_to' => 'address_line'],
                    ['type' => 'text',   'label' => __('Address line 2', 'lrob-email-toolkit'), 'maps_to' => 'address_line2'],
                    ['type' => 'text',   'label' => __('Postcode',       'lrob-email-toolkit'), 'maps_to' => 'address_postcode'],
                    ['type' => 'text',   'label' => __('City',           'lrob-email-toolkit'), 'maps_to' => 'address_city'],
                    ['type' => 'select', 'label' => __('Country',        'lrob-email-toolkit'), 'maps_to' => 'address_country', 'options' => self::country_select_options()],
                ],
            ],
        ];
    }

    /**
     * Country `<option>` list for the address preset's country field —
     * iso2 → "Flag Name" via CountryData (the same source the phone
     * field uses). Stored value is the ISO-2 code, so the
     * subscribers.address_country VARCHAR(2) column stays correct.
     *
     * @return array<int, array{value:string,label:string}>
     */
    private static function country_select_options(): array
    {
        $out = [];
        foreach (CountryData::all_translated() as $row) {
            $out[] = [
                'value' => (string) $row['iso'],
                'label' => $row['flag'] . ' ' . $row['name'],
            ];
        }
        return $out;
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
            'pattern'           => __('Regex pattern', 'lrob-email-toolkit'),
            'countryPicker'     => __('Country code picker', 'lrob-email-toolkit'),
            'defaultCountry'    => __('Default', 'lrob-email-toolkit'),
            'autoDetectCountry' => __('Auto-detect from browser', 'lrob-email-toolkit'),
            'autoFromLocale'    => __('Auto (locale)', 'lrob-email-toolkit'),
            'mapsTo'            => __('Maps to', 'lrob-email-toolkit'),
            'mapsToNone'        => __('— (none)', 'lrob-email-toolkit'),
        ];
    }

    /** Dashboard tile counter — proxies to the repo. */
    public function count_for_dashboard(): int
    {
        return $this->forms->count_total();
    }

}
