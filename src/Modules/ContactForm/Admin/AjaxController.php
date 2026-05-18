<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\ContactForm\CPT;
use LRob\EmailToolkit\Modules\ContactForm\FormStructure;
use LRob\EmailToolkit\Modules\ContactForm\TemplateRegistry;

/**
 * Single admin-AJAX endpoint that backs the auto-save on every interactive
 * form card. The card JS sends one field at a time — `key` says which
 * setting and `value` is the new value. We dispatch to the right post_meta
 * or post_title update.
 *
 * Whitelist-based: any unknown key is rejected so the endpoint can never
 * be coerced into writing arbitrary meta or post fields. Empty/sentinel
 * values are persisted as-is — they mean "inherit the global default"
 * (see Settings::effective_*).
 */
final class AjaxController
{
    public const ACTION_SAVE_META = 'lrob_etk_cf_save_meta';

    public const ACTION_SAVE_STRUCTURE = 'lrob_etk_cf_save_structure';

    public const ACTION_CREATE_FORM = 'lrob_etk_cf_create_form';

    public const NONCE_ACTION = 'lrob_etk_cf_admin';

    /** Keys that map to post_meta (value type → coerce on save). */
    private const META_KEYS = [
        CPT::META_RECIPIENT           => 'recipient_list',
        CPT::META_RECIPIENT_IDENTITY  => 'int',
        CPT::META_REPLY_TO_FIELD      => 'slug',
        CPT::META_SUBJECT_TEMPLATE    => 'string',
        CPT::META_SUCCESS_MESSAGE     => 'textarea',
        CPT::META_RATE_LIMIT_MAX      => 'int',
        CPT::META_RATE_LIMIT_WINDOW   => 'int',  // stored in seconds
        CPT::META_HONEYPOT_ENABLED    => 'tristate',
        CPT::META_CHALLENGE_KIND      => 'challenge',
        CPT::META_STYLE_PRESET        => 'style_preset',
    ];

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_SAVE_META, [$this, 'handle_save_meta']);
        add_action('wp_ajax_' . self::ACTION_SAVE_STRUCTURE, [$this, 'handle_save_structure']);
        add_action('wp_ajax_' . self::ACTION_CREATE_FORM, [$this, 'handle_create_form']);
    }

    public function handle_save_meta(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'lrob-email-toolkit')], 403);
        }

        $post = wp_unslash($_POST);
        $nonce = isset($post['_nonce']) ? (string) $post['_nonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload the page.', 'lrob-email-toolkit')], 400);
        }

        $form_id = isset($post['form_id']) ? (int) $post['form_id'] : 0;
        $form = $form_id > 0 ? get_post($form_id) : null;
        if (!$form || $form->post_type !== CPT::POST_TYPE) {
            wp_send_json_error(['message' => __('Form not found.', 'lrob-email-toolkit')], 404);
        }

        $key = isset($post['key']) ? (string) $post['key'] : '';
        $value = $post['value'] ?? '';

        if ($key === 'title') {
            self::save_title($form_id, (string) $value);
            wp_send_json_success(['key' => $key]);
        }

        if (!isset(self::META_KEYS[$key])) {
            wp_send_json_error(['message' => __('Unknown setting.', 'lrob-email-toolkit')], 400);
        }

        $coerced = self::coerce(self::META_KEYS[$key], $value);
        update_post_meta($form_id, $key, $coerced);

        wp_send_json_success(['key' => $key, 'stored' => $coerced]);
    }

    private static function save_title(int $form_id, string $title): void
    {
        wp_update_post([
            'ID'         => $form_id,
            'post_title' => sanitize_text_field($title),
        ]);
    }

    /**
     * Replace the entire form structure (rows / columns / fields / submit).
     * Editor JS sends the JSON; FormStructure::save normalizes + persists.
     */
    public function handle_save_structure(): void
    {
        [$form_id] = self::guard_request();

        $structure_raw = isset($_POST['structure']) ? wp_unslash($_POST['structure']) : '';
        if (!is_string($structure_raw) || $structure_raw === '') {
            wp_send_json_error(['message' => __('Missing structure.', 'lrob-email-toolkit')], 400);
        }
        $decoded = json_decode($structure_raw, true);
        if (!is_array($decoded)) {
            wp_send_json_error(['message' => __('Invalid structure JSON.', 'lrob-email-toolkit')], 400);
        }
        FormStructure::save($form_id, $decoded);
        wp_send_json_success(['version' => FormStructure::VERSION]);
    }

    /**
     * Create a new contact form. Source is either a built-in template
     * (`source=template`, `slug=<template_slug>`) or another existing form
     * (`source=form`, `form_id=<id>`). The new form starts as a draft with
     * just the field structure copied; per-form settings reset to defaults.
     */
    public function handle_create_form(): void
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'lrob-email-toolkit')], 403);
        }
        $post = wp_unslash($_POST);
        $nonce = isset($post['_nonce']) ? (string) $post['_nonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload the page.', 'lrob-email-toolkit')], 400);
        }

        $source = isset($post['source']) ? (string) $post['source'] : 'blank';
        $structure = FormStructure::empty_structure();
        $title = isset($post['title']) ? sanitize_text_field((string) $post['title']) : '';

        if ($source === 'template') {
            $slug = isset($post['slug']) ? sanitize_key((string) $post['slug']) : '';
            $tpl = TemplateRegistry::get($slug);
            if ($tpl === null) {
                wp_send_json_error(['message' => __('Unknown template.', 'lrob-email-toolkit')], 400);
            }
            $structure = TemplateRegistry::structure_for($slug);
            if ($title === '') {
                $title = $tpl['name'];
            }
        } elseif ($source === 'form') {
            $src_id = isset($post['form_id']) ? (int) $post['form_id'] : 0;
            $src = $src_id > 0 ? get_post($src_id) : null;
            if (!$src || $src->post_type !== CPT::POST_TYPE) {
                wp_send_json_error(['message' => __('Source form not found.', 'lrob-email-toolkit')], 404);
            }
            $structure = FormStructure::load($src_id);
            if ($title === '') {
                /* translators: %s: name of the source form being cloned */
                $title = sprintf(__('%s (copy)', 'lrob-email-toolkit'), $src->post_title);
            }
        }

        if ($title === '') {
            $title = __('Untitled form', 'lrob-email-toolkit');
        }

        $new_id = wp_insert_post([
            'post_type'    => CPT::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => (string) wp_json_encode($structure),
        ], true);

        if (is_wp_error($new_id) || $new_id === 0) {
            wp_send_json_error(['message' => __('Could not create form.', 'lrob-email-toolkit')], 500);
        }

        wp_send_json_success(['form_id' => (int) $new_id]);
    }

    /**
     * Shared guard for handle_* methods that operate on an existing form.
     * Verifies capability, nonce, and that form_id resolves to a CPT post.
     *
     * @return array{0:int, 1:\WP_Post}
     */
    private static function guard_request(): array
    {
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'lrob-email-toolkit')], 403);
        }
        $post_data = wp_unslash($_POST);
        $nonce = isset($post_data['_nonce']) ? (string) $post_data['_nonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload the page.', 'lrob-email-toolkit')], 400);
        }
        $form_id = isset($post_data['form_id']) ? (int) $post_data['form_id'] : 0;
        $form = $form_id > 0 ? get_post($form_id) : null;
        if (!$form || $form->post_type !== CPT::POST_TYPE) {
            wp_send_json_error(['message' => __('Form not found.', 'lrob-email-toolkit')], 404);
        }
        return [$form_id, $form];
    }

    private static function coerce(string $type, mixed $value): mixed
    {
        return match ($type) {
            'int'             => max(0, (int) $value),
            'string'          => is_string($value) ? sanitize_text_field($value) : '',
            'slug'            => is_string($value) ? sanitize_key($value) : '',
            'textarea'        => is_string($value) ? sanitize_textarea_field($value) : '',
            'recipient_list'  => is_string($value) ? self::clean_recipient_list($value) : '',
            'tristate'        => in_array($value, ['default', 'on', 'off'], true) ? (string) $value : 'default',
            'challenge'       => in_array($value, ['', CPT::CHALLENGE_NONE, CPT::CHALLENGE_MATH], true) ? (string) $value : '',
            'style_preset'    => is_string($value) ? sanitize_html_class($value) : '',
            default           => $value,
        };
    }

    /** Comma-separated email list — drop invalid pieces, normalize spacing. */
    private static function clean_recipient_list(string $raw): string
    {
        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $email = sanitize_email(trim($piece));
            if ($email !== '' && is_email($email)) {
                $out[] = $email;
            }
        }
        return implode(', ', $out);
    }
}
