<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Admin;

use LRob\EmailToolkit\Admin\Tooltip;
use LRob\EmailToolkit\Forms\FormStructure;
use LRob\EmailToolkit\Modules\ContactForm\CPT;
use LRob\EmailToolkit\Modules\ContactForm\Settings;

/**
 * Renders one contact-form card (recipient / subject / success /
 * anti-spam settings + the embedded fields editor) for the Forms list.
 * Split out of FormsPage to keep that file focused on page chrome,
 * modals, and admin-post handlers. Shared formatters (combobox/
 * recipients/placeholder helpers) stay on FormsPage as public statics.
 *
 * Docs: docs/contact-form.md
 */
final class FormCardRenderer
{
    /**
     * @param array<string, mixed>             $form
     * @param array<int, array<string, mixed>> $identities
     * @param array<string, mixed>             $globals
     */
    public function render(array $form, array $identities, array $globals): void
    {
        $form_id = (int) $form['id'];
        $delete_url_base = wp_nonce_url(
            add_query_arg(
                ['action' => FormsPage::ACTION_DELETE_FORM, 'form_id' => $form_id],
                admin_url('admin-post.php')
            ),
            FormsPage::ACTION_DELETE_FORM . '_' . $form_id,
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

        $default_identity_label = FormsPage::default_identity_label($identities);
        $default_identity_text = $default_identity_label !== ''
            ? FormsPage::placeholder_default($default_identity_label)
            : __('Default — SMTP routing', 'lrob-email-toolkit');

        $globals_recipient = (string) ($globals[Settings::KEY_RECIPIENT] ?? '');
        $admin_email = (string) get_option('admin_email');
        $effective_recipient = $globals_recipient !== '' ? $globals_recipient : $admin_email;
        $recipient_placeholder = FormsPage::placeholder_default($effective_recipient);
        $no_recipient_anywhere = $meta['recipient'] === '' && $globals_recipient === '' && $admin_email === '';

        $form_structure = FormStructure::load($form_id);
        $form_email_slugs = FormsPage::email_field_slugs($form_structure);
        $global_reply_slug = (string) ($globals[Settings::KEY_REPLY_TO_FIELD] ?? '');
        // Pretty label for the inherited default: show the actual slug, or
        // "(none)" when the global setting opts out, or "(first email field)"
        // when the global is empty but this form has email fields.
        if ($global_reply_slug === FormsPage::REPLY_TO_NONE) {
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
        $subject_placeholder = FormsPage::placeholder_default($subject_default);

        $success_default = (string) ($globals[Settings::KEY_SUCCESS_MESSAGE] ?? '');
        if ($success_default === '') {
            $success_default = __('Thanks! Your message has been sent.', 'lrob-email-toolkit');
        }
        $success_placeholder = FormsPage::placeholder_default($success_default);

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
            ['value' => 'default', 'label' => FormsPage::label_default($hp_default_label)],
            ['value' => 'on',      'label' => __('On',  'lrob-email-toolkit')],
            ['value' => 'off',     'label' => __('Off', 'lrob-email-toolkit')],
        ];

        $save_default_label = !empty($globals[Settings::KEY_SAVE_SUBMISSIONS])
            ? __('On', 'lrob-email-toolkit')
            : __('Off', 'lrob-email-toolkit');
        $save_value = $meta['save_subs'] !== '' ? $meta['save_subs'] : 'default';
        $save_subs_options = [
            ['value' => 'default', 'label' => FormsPage::label_default($save_default_label)],
            ['value' => 'on',      'label' => __('On',  'lrob-email-toolkit')],
            ['value' => 'off',     'label' => __('Off', 'lrob-email-toolkit')],
        ];

        $captcha_service = FormsPage::captcha_service();
        [$ch_default_challenge, ] = $captcha_service !== null
            ? $captcha_service->resolve(['context' => 'contact_form'])
            : [null, []];
        $ch_default_label = $ch_default_challenge !== null
            ? $ch_default_challenge->label()
            : __('None', 'lrob-email-toolkit');
        $challenge_options = [
            ['value' => '',                  'label' => FormsPage::label_default($ch_default_label)],
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
            ['value' => '',                   'label' => FormsPage::label_default($reply_to_default_label)],
            ['value' => FormsPage::REPLY_TO_NONE,  'label' => __('None — no Reply-To header', 'lrob-email-toolkit')],
        ];
        foreach ($form_email_slugs as $slug) {
            $reply_to_options[] = ['value' => $slug, 'label' => $slug];
        }

        $preset_labels = FormsPage::style_presets();
        $preset_default = (string) ($globals[Settings::KEY_STYLE_PRESET] ?? CPT::STYLE_DEFAULT);
        $preset_default_label = $preset_labels[$preset_default] ?? $preset_labels[CPT::STYLE_DEFAULT];
        $preset_options = [
            ['value' => '', 'label' => FormsPage::label_default($preset_default_label)],
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
                 id="form-<?php echo $form_id; ?>"
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
                        <?php FormsPage::render_recipients($meta['recipient'], $recipient_placeholder); ?>
                    </div>
                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('SMTP identity', 'lrob-email-toolkit'); ?></label>
                        <?php FormsPage::render_combobox(CPT::META_RECIPIENT_IDENTITY, (string) $meta['identity_id'], $identity_options, '0'); ?>
                    </div>
                </section>

                <section class="lrob-etk-form-style-group">
                    <h3 class="lrob-etk-section-title"><?php esc_html_e('Style', 'lrob-email-toolkit'); ?></h3>
                    <div class="lrob-etk-field">
                        <label><?php esc_html_e('Preset', 'lrob-email-toolkit'); ?></label>
                        <?php FormsPage::render_combobox(CPT::META_STYLE_PRESET, $meta['style_preset'], $preset_options); ?>
                    </div>
                </section>

                <?php FormsPage::render_fields_editor($form_id); ?>

                <section class="lrob-etk-form-success-message">
                    <div class="lrob-etk-field">
                        <label>
                            <?php esc_html_e('Success message', 'lrob-email-toolkit'); ?>
                            <?php Tooltip::render(__('Shown to the visitor right after they submit the form. Leave empty to use the site-language default (it then updates automatically if the site language changes) — the dropdown resets to that default.', 'lrob-email-toolkit')); ?>
                        </label>
                        <?php FormsPage::render_free_combobox(
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
                                    <?php FormsPage::render_combobox(CPT::META_REPLY_TO_FIELD, $meta['reply_to'], $reply_to_options); ?>
                                </div>
                                <div class="lrob-etk-field">
                                    <label>
                                        <?php esc_html_e('Subject template', 'lrob-email-toolkit'); ?>
                                        <?php Tooltip::render(__('Subject line of the notification email. Tokens like {title} are replaced with form values. Open the dropdown to insert the default.', 'lrob-email-toolkit')); ?>
                                    </label>
                                    <?php FormsPage::render_free_combobox(
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
                                    <?php FormsPage::render_combobox(CPT::META_HONEYPOT_ENABLED, $hp_value, $honeypot_options, 'default'); ?>
                                </div>
                                <div class="lrob-etk-field">
                                    <label>
                                        <?php esc_html_e('Challenge', 'lrob-email-toolkit'); ?>
                                        <?php Tooltip::render(__('Anti-bot prompt visitors see (e.g. a tiny math question). Configured globally in the Captcha settings page; override here per form if needed.', 'lrob-email-toolkit')); ?>
                                    </label>
                                    <?php FormsPage::render_combobox(CPT::META_CHALLENGE_KIND, $meta['challenge'], $challenge_options); ?>
                                </div>
                                <div class="lrob-etk-field">
                                    <label>
                                        <?php esc_html_e('Save submissions', 'lrob-email-toolkit'); ?>
                                        <?php Tooltip::render(__('Archive received submissions to the database. When off, the notification email still goes out but no row is written and this form drops out of the Submissions inbox.', 'lrob-email-toolkit')); ?>
                                    </label>
                                    <?php FormsPage::render_combobox(CPT::META_SAVE_SUBMISSIONS, $save_value, $save_subs_options, 'default'); ?>
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
                                               placeholder="<?php echo esc_attr(FormsPage::placeholder_default((string) ($globals[Settings::KEY_RATE_MAX] ?? 5))); ?>">
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
                                               placeholder="<?php echo esc_attr(FormsPage::placeholder_default((string) ($globals[Settings::KEY_RATE_WINDOW_MINUTES] ?? 10))); ?>">
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
}
