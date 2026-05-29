<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

// Docs: docs/contact-form.md
// Sentinel values for "inherit global": '' for strings, 0 for ints, 'default' for honeypot/challenge.
final class Settings
{
    public const OPTION = 'lrob_etk_contact_form_settings';

    public const KEY_RECIPIENT             = 'recipient';
    public const KEY_IDENTITY              = 'identity_id';
    public const KEY_REPLY_TO_FIELD        = 'reply_to_field';
    public const KEY_SUBJECT_TEMPLATE      = 'subject_template';
    public const KEY_SUCCESS_MESSAGE       = 'success_message';
    public const KEY_RATE_MAX              = 'rate_max';
    public const KEY_RATE_WINDOW_MINUTES   = 'rate_window_minutes';
    public const KEY_HONEYPOT              = 'honeypot';
    public const KEY_STYLE_PRESET          = 'style_preset';
    public const KEY_ACCENT                = 'accent';
    public const KEY_RADIUS                = 'radius';
    public const KEY_FONT_SIZE             = 'font_size';
    public const KEY_SAVE_SUBMISSIONS      = 'save_submissions';
    /** Save honeypot / time-trap-tripped rows. OFF by default — these are
     *  almost always bots and clutter the inbox. */
    public const KEY_SAVE_SPAM_BOT         = 'save_spam_bot';
    /** Save captcha-failed rows. ON by default — captcha challenges can
     *  also fail for legitimate users (typos, slow connections). */
    public const KEY_SAVE_SPAM_CAPTCHA     = 'save_spam_captcha';
    public const KEY_STORE_RAW_IP          = 'store_raw_ip';
    public const KEY_RETENTION_DELIVERED_DAYS = 'retention_delivered_days';
    public const KEY_RETENTION_RECEIVED_DAYS  = 'retention_received_days';
    public const KEY_RETENTION_FAILED_DAYS    = 'retention_failed_days';
    public const KEY_RETENTION_SPAM_DAYS      = 'retention_spam_days';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            self::KEY_RECIPIENT             => '', // empty → admin_email
            self::KEY_IDENTITY              => 0,  // 0 → SMTP routing
            self::KEY_REPLY_TO_FIELD        => 'email',
            self::KEY_SUBJECT_TEMPLATE      => '',
            self::KEY_SUCCESS_MESSAGE       => '',
            self::KEY_RATE_MAX              => 5,
            self::KEY_RATE_WINDOW_MINUTES   => 10,
            self::KEY_HONEYPOT              => true,
            self::KEY_STYLE_PRESET          => CPT::STYLE_DEFAULT,
            self::KEY_ACCENT                => '',
            self::KEY_RADIUS                => '',
            self::KEY_FONT_SIZE             => '',
            self::KEY_SAVE_SUBMISSIONS      => true,  // privacy-neutral default
            self::KEY_SAVE_SPAM_BOT         => false, // honeypot/time-trap = bots, skip the inbox clutter
            self::KEY_SAVE_SPAM_CAPTCHA     => true,  // captcha-fail can be a legit user — keep for review
            self::KEY_STORE_RAW_IP          => false, // privacy-first default
            // 0 = disabled (kept forever). Spam defaults to 90 days; others default to off.
            self::KEY_RETENTION_DELIVERED_DAYS => 0,
            self::KEY_RETENTION_RECEIVED_DAYS  => 0,
            self::KEY_RETENTION_FAILED_DAYS    => 0,
            self::KEY_RETENTION_SPAM_DAYS      => 90,
        ];
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_replace(self::defaults(), $stored);
    }

    /** @param array<string, mixed> $values */
    public static function save(array $values): void
    {
        $clean = [];
        foreach (self::defaults() as $key => $default) {
            if (!array_key_exists($key, $values)) {
                $clean[$key] = $default;
                continue;
            }
            $clean[$key] = self::sanitize($key, $values[$key], $default);
        }
        update_option(self::OPTION, $clean);
    }

    private static function sanitize(string $key, mixed $value, mixed $default): mixed
    {
        return match ($key) {
            self::KEY_RATE_MAX,
            self::KEY_RATE_WINDOW_MINUTES,
            self::KEY_IDENTITY               => max(0, (int) $value),
            self::KEY_RETENTION_DELIVERED_DAYS,
            self::KEY_RETENTION_RECEIVED_DAYS,
            self::KEY_RETENTION_FAILED_DAYS,
            self::KEY_RETENTION_SPAM_DAYS    => max(0, min(3650, (int) $value)),
            self::KEY_HONEYPOT,
            self::KEY_SAVE_SUBMISSIONS,
            self::KEY_SAVE_SPAM_BOT,
            self::KEY_SAVE_SPAM_CAPTCHA,
            self::KEY_STORE_RAW_IP           => (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN),
            self::KEY_RECIPIENT              => is_string($value) ? self::sanitize_recipient_list($value) : '',
            self::KEY_REPLY_TO_FIELD         => is_string($value) ? sanitize_key($value) : '',
            self::KEY_STYLE_PRESET           => is_string($value) && $value !== '' ? sanitize_html_class($value) : (string) $default,
            self::KEY_SUBJECT_TEMPLATE,
            self::KEY_SUCCESS_MESSAGE        => is_string($value) ? sanitize_textarea_field($value) : '',
            self::KEY_ACCENT,
            self::KEY_RADIUS,
            self::KEY_FONT_SIZE              => is_string($value) ? trim($value) : '',
            default                          => $value,
        };
    }

    private static function sanitize_recipient_list(string $raw): string
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

    // Effective getters: per-form meta → global option → hardcoded fallback.

    public static function effective_recipient(int $form_id): string
    {
        $per_form = trim((string) get_post_meta($form_id, CPT::META_RECIPIENT, true));
        if ($per_form !== '') {
            return $per_form;
        }
        $global = trim((string) (self::all()[self::KEY_RECIPIENT] ?? ''));
        if ($global !== '') {
            return $global;
        }
        return (string) get_option('admin_email');
    }

    public static function effective_identity_id(int $form_id): int
    {
        $per_form = (int) get_post_meta($form_id, CPT::META_RECIPIENT_IDENTITY, true);
        if ($per_form > 0) {
            return $per_form;
        }
        return (int) (self::all()[self::KEY_IDENTITY] ?? 0);
    }

    public static function effective_reply_to_field(int $form_id): string
    {
        $per_form = trim((string) get_post_meta($form_id, CPT::META_REPLY_TO_FIELD, true));
        if ($per_form !== '') {
            return $per_form;
        }
        $global = trim((string) (self::all()[self::KEY_REPLY_TO_FIELD] ?? ''));
        return $global !== '' ? $global : 'email';
    }

    public static function effective_subject_template(int $form_id): string
    {
        $per_form = (string) get_post_meta($form_id, CPT::META_SUBJECT_TEMPLATE, true);
        if (trim($per_form) !== '') {
            return $per_form;
        }
        return (string) (self::all()[self::KEY_SUBJECT_TEMPLATE] ?? '');
    }

    public static function effective_success_message(int $form_id): string
    {
        $per_form = (string) get_post_meta($form_id, CPT::META_SUCCESS_MESSAGE, true);
        if (trim($per_form) !== '') {
            return $per_form;
        }
        $global = (string) (self::all()[self::KEY_SUCCESS_MESSAGE] ?? '');
        if (trim($global) !== '') {
            return $global;
        }
        return __('Thanks! Your message has been sent.', 'lrob-email-toolkit');
    }

    public static function effective_rate_max(int $form_id): int
    {
        $per_form = (int) get_post_meta($form_id, CPT::META_RATE_LIMIT_MAX, true);
        if ($per_form > 0) {
            return $per_form;
        }
        return max(1, (int) (self::all()[self::KEY_RATE_MAX] ?? 5));
    }

    public static function effective_rate_window_seconds(int $form_id): int
    {
        $per_form = (int) get_post_meta($form_id, CPT::META_RATE_LIMIT_WINDOW, true);
        if ($per_form > 0) {
            return $per_form;
        }
        $minutes = max(1, (int) (self::all()[self::KEY_RATE_WINDOW_MINUTES] ?? 10));
        return $minutes * 60;
    }

    public static function effective_honeypot(int $form_id): bool
    {
        $per_form = (string) get_post_meta($form_id, CPT::META_HONEYPOT_ENABLED, true);
        if ($per_form === 'on') {
            return true;
        }
        if ($per_form === 'off') {
            return false;
        }
        return (bool) (self::all()[self::KEY_HONEYPOT] ?? true);
    }

    // '' = inherit Captcha contact_form context; 'none' = off; 'homemade:<slug>'; 'identity:<id>'.
    public static function effective_routing_key(int $form_id): string
    {
        $per_form = (string) get_post_meta($form_id, CPT::META_CHALLENGE_KIND, true);
        if ($per_form === '' || $per_form === 'default') {
            return '';
        }
        return $per_form;
    }

    public static function effective_style_preset(int $form_id): string
    {
        $per_form = (string) get_post_meta($form_id, CPT::META_STYLE_PRESET, true);
        if ($per_form !== '' && $per_form !== 'default-inherit') {
            return $per_form;
        }
        return (string) (self::all()[self::KEY_STYLE_PRESET] ?? CPT::STYLE_DEFAULT);
    }

    // When false: no DB row, but notification email still sends and all anti-spam still runs.
    public static function effective_save_submissions(int $form_id): bool
    {
        $per_form = (string) get_post_meta($form_id, CPT::META_SAVE_SUBMISSIONS, true);
        if ($per_form === 'on') {
            return true;
        }
        if ($per_form === 'off') {
            return false;
        }
        return (bool) (self::all()[self::KEY_SAVE_SUBMISSIONS] ?? true);
    }


    public static function store_raw_ip(): bool
    {
        return (bool) (self::all()[self::KEY_STORE_RAW_IP] ?? false);
    }

    public static function save_spam_bot(): bool
    {
        return (bool) (self::all()[self::KEY_SAVE_SPAM_BOT] ?? false);
    }

    public static function save_spam_captcha(): bool
    {
        return (bool) (self::all()[self::KEY_SAVE_SPAM_CAPTCHA] ?? true);
    }

    public static function retention_delivered_days(): int
    {
        return max(0, (int) (self::all()[self::KEY_RETENTION_DELIVERED_DAYS] ?? 0));
    }

    public static function retention_received_days(): int
    {
        return max(0, (int) (self::all()[self::KEY_RETENTION_RECEIVED_DAYS] ?? 0));
    }

    public static function retention_failed_days(): int
    {
        return max(0, (int) (self::all()[self::KEY_RETENTION_FAILED_DAYS] ?? 0));
    }

    public static function retention_spam_days(): int
    {
        return max(0, (int) (self::all()[self::KEY_RETENTION_SPAM_DAYS] ?? 90));
    }
}
