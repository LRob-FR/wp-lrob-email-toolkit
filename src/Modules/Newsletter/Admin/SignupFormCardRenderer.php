<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Admin\Combobox;
use LRob\EmailToolkit\Forms\StyleControls;
use LRob\EmailToolkit\Forms\StylePresets;
use LRob\EmailToolkit\Modules\Newsletter\FormCPT;

/**
 * Renders one newsletter signup-form card (confirmation template +
 * default lists + success message + inline fields editor) for the
 * Newsletter Forms list. Split out of FormsPage to keep that file
 * focused on page chrome + handlers. Shared helpers (lists picker,
 * fields editor, label/template formatters) stay on FormsPage as
 * public statics.
 *
 * Docs: docs/newsletter-internals.md
 */
final class SignupFormCardRenderer
{
    /**
     * Per-form card. Same shape as ContactForm's form card — title
     * + status, settings inputs, inline WYSIWYG editor — but with the
     * smaller newsletter-specific settings surface (no SMTP identity,
     * no recipient picker, no style preset; just confirmation template,
     * default list, success message).
     *
     * @param array<int, \WP_Post> $confirmation_templates
     */
    public function render(\WP_Post $post, array $confirmation_templates, int $resolved_default_template_id): void
    {
        $form_id = (int) $post->ID;
        $title = (string) $post->post_title;
        $confirmation_template_id = (int) get_post_meta($form_id, FormCPT::META_CONFIRMATION_TEMPLATE_ID, true);
        $success_message = (string) get_post_meta($form_id, FormCPT::META_SUCCESS_MESSAGE, true);
        $style_preset = (string) get_post_meta($form_id, FormCPT::META_STYLE_PRESET, true);
        $style_vars_raw = (string) get_post_meta($form_id, FormCPT::META_STYLE_VARS, true);
        $style_vars_decoded = $style_vars_raw !== '' ? json_decode($style_vars_raw, true) : [];
        $style_vars = is_array($style_vars_decoded) ? $style_vars_decoded : [];

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
        $tpl_options = [['value' => '0', 'label' => FormsPage::resolved_default_template_label($resolved_default_template_id)]];
        foreach ($confirmation_templates as $tpl) {
            $tpl_options[] = [
                'value' => (string) $tpl->ID,
                'label' => $tpl->post_title !== '' ? $tpl->post_title : __('(untitled)', 'lrob-email-toolkit'),
            ];
        }

        // Style preset picker: same shape as Contact Form's, driven by
        // the shared StylePresets registry.
        $preset_options = [['value' => '', 'label' => FormsPage::label_default(StylePresets::label_for(StylePresets::DEFAULT_SLUG))]];
        foreach (StylePresets::all() as $value => $label) {
            $preset_options[] = ['value' => (string) $value, 'label' => (string) $label];
        }
        ?>
        <article class="lrob-etk-card lrob-etk-form-card" id="form-<?php echo $form_id; ?>" data-form-id="<?php echo $form_id; ?>">
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
                        <span class="lrob-etk-status lrob-etk-state--pending"><?php esc_html_e('Draft', 'lrob-email-toolkit'); ?></span>
                    <?php endif; ?>
                    <span class="lrob-etk-card-status" aria-live="polite"></span>
                </header>

                <section class="lrob-etk-form-essentials">
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
                                /* translators: %1$s: opening <a> tag, %2$s: closing </a> tag, around "Onboarding view". */
                                esc_html__('Edit confirmation emails in the %1$sOnboarding view%2$s.', 'lrob-email-toolkit'),
                                '<a href="' . esc_url($template_admin_url) . '">',
                                '</a>'
                            );
                            ?>
                        </p>
                    </div>

                    <div class="lrob-etk-field">
                        <label>
                            <?php esc_html_e('Default lists', 'lrob-email-toolkit'); ?>
                            <button type="button"
                                    class="lrob-etk-nl-field-link lrob-etk-nl-open-lists-modal"
                                    title="<?php esc_attr_e('Manage lists', 'lrob-email-toolkit'); ?>">
                                <?php esc_html_e('Manage lists →', 'lrob-email-toolkit'); ?>
                            </button>
                        </label>
                        <?php FormsPage::render_default_lists_picker($form_id); ?>
                        <p class="description">
                            <?php esc_html_e('Confirmed subscribers from this form are added to every list picked here. Leave empty to skip auto-assignment.', 'lrob-email-toolkit'); ?>
                        </p>
                    </div>
                </section>

                <section class="lrob-etk-form-style-group">
                    <h3 class="lrob-etk-section-title"><?php esc_html_e('Style', 'lrob-email-toolkit'); ?></h3>
                    <div class="lrob-etk-field">
                        <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — StyleControls::render escapes internally.
                        echo StyleControls::render('lrob-etk-nl-field', FormCPT::META_STYLE_VARS, $style_vars);
                        ?>
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

                <?php FormsPage::render_fields_editor($form_id); ?>

                <footer class="lrob-etk-card-footer">
                    <span class="lrob-etk-card-status lrob-etk-card-footer-status" aria-live="polite"></span>
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
}
