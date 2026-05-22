<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use LRob\EmailToolkit\Forms\CaptchaField as SharedCaptchaField;
use LRob\EmailToolkit\Forms\FieldTypeRegistry;
use LRob\EmailToolkit\Forms\Fields\CheckboxField;
use LRob\EmailToolkit\Forms\Fields\DateField;
use LRob\EmailToolkit\Forms\Fields\EmailField;
use LRob\EmailToolkit\Forms\Fields\NumberField;
use LRob\EmailToolkit\Forms\Fields\PhoneField;
use LRob\EmailToolkit\Forms\Fields\RadioField;
use LRob\EmailToolkit\Forms\Fields\SelectField;
use LRob\EmailToolkit\Forms\Fields\SubmitField;
use LRob\EmailToolkit\Forms\Fields\TextField;
use LRob\EmailToolkit\Forms\Fields\TextareaField;
use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\ContactForm\Admin\AjaxController;
use LRob\EmailToolkit\Modules\ContactForm\Admin\FormsPage;
use LRob\EmailToolkit\Modules\ContactForm\Admin\PageController;
use LRob\EmailToolkit\Modules\ContactForm\Admin\SubmissionsPage;

/**
 * Contact Form module — customizable forms with stacked anti-spam (honeypot,
 * time-trap, rate limit, math challenge) plus a future captcha layer.
 *
 * Architecture summary (see CLAUDE.md for full detail):
 *  - Forms are stored as a CPT (`lrob_etk_contact_form`) edited in Gutenberg.
 *  - Field types are individual Gutenberg blocks registered server-side; each
 *    block has a render_callback that emits frontend HTML scoped to the
 *    current FormContext.
 *  - Page-side, a separate `lrob-etk/contact-form` block picks a form by ID
 *    and wraps its rendered blocks in a &lt;form&gt; element with per-render
 *    instance scoping.
 *  - Submissions hit a single AJAX endpoint (SubmitHandler) and persist to
 *    a dedicated submissions table. Notification email is dispatched through
 *    the SMTP module using the 'contact_form' source.
 */
final class Module extends AbstractModule
{
    public function slug(): string
    {
        return 'contact_form';
    }

    public function name(): string
    {
        return __('Contact Form', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __(
            'Build customizable contact forms with stacked anti-spam and the existing SMTP identity routing.',
            'lrob-email-toolkit'
        );
    }

    public function version(): string
    {
        return '0.0.2';
    }

    public function db_version_int(): int
    {
        return 4;
    }

    public function install(): void
    {
        Schema::install();
        self::migrate_captcha_routing_keys();
    }

    /**
     * v1 → v2: submissions table grew `captcha_slug` + `captcha_outcome`
     * columns. dbDelta handles the additive ALTER TABLE — rerun install().
     *
     * v2 → v3: per-form `_lrob_etk_cf_challenge` post meta now stores
     * captcha routing keys ('homemade:math', 'identity:7', …) instead of
     * bare challenge slugs. The old `lrob_etk_contact_form_settings.challenge`
     * entry is folded into the Captcha module's `lrob_etk_captcha_context_map`
     * if the contact_form context is still set to 'inherit'. install()
     * forwards through to migrate_captcha_routing_keys() which is fully
     * idempotent.
     *
     * v3 → v4: submissions table grew an `ip_address` column. Default empty
     * string means "raw IP not captured" (privacy-first default). dbDelta
     * handles the additive ALTER TABLE.
     */
    public function migrate(int $from_version, int $to_version): void
    {
        unset($to_version);
        if ($from_version < 2) {
            Schema::install();
        }
        if ($from_version < 3) {
            self::migrate_captcha_routing_keys();
        }
        if ($from_version < 4) {
            Schema::install();
        }
    }

    /**
     * One-time conversion of pre-v0.1.0 captcha settings to routing keys.
     * Idempotent: re-runs see nothing left to convert.
     */
    private static function migrate_captcha_routing_keys(): void
    {
        // 1. Per-form meta: bare slugs → 'homemade:<slug>'. 'none' and ''
        //    (empty/inherit) stay as-is. Anything already shaped like a
        //    routing key (contains ':' or is 'none') is left alone.
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
                CPT::META_CHALLENGE_KIND
            ),
            ARRAY_A
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $value = isset($row['meta_value']) ? (string) $row['meta_value'] : '';
                if ($value === '' || $value === 'default' || $value === CPT::CHALLENGE_NONE) {
                    if ($value === 'default') {
                        update_post_meta((int) $row['post_id'], CPT::META_CHALLENGE_KIND, '');
                    }
                    continue;
                }
                if (str_contains($value, ':')) {
                    continue; // already a routing key
                }
                // Pre-v0.1.0 slug — promote to homemade route.
                update_post_meta(
                    (int) $row['post_id'],
                    CPT::META_CHALLENGE_KIND,
                    'homemade:' . sanitize_key($value)
                );
            }
        }

        // 2. Old Contact Form-wide default challenge → Captcha context map's
        //    contact_form entry (only when that entry is still 'inherit',
        //    so we don't clobber what the admin already set on the new
        //    Captcha page).
        $old = get_option(Settings::OPTION, []);
        if (is_array($old) && isset($old['challenge']) && is_string($old['challenge']) && $old['challenge'] !== '') {
            $old_challenge = $old['challenge'];
            $map = get_option('lrob_etk_captcha_context_map', []);
            if (!is_array($map)) {
                $map = [];
            }
            $current = isset($map['contact_form']) ? (string) $map['contact_form'] : 'inherit';
            if ($current === 'inherit' || $current === '') {
                $map['contact_form'] = $old_challenge === CPT::CHALLENGE_NONE
                    ? 'none'
                    : 'homemade:' . sanitize_key($old_challenge);
                update_option('lrob_etk_captcha_context_map', $map);
            }
            // Drop the now-unused challenge key from the option.
            unset($old['challenge']);
            update_option(Settings::OPTION, $old);
        }
    }

    public function uninstall(): void
    {
        // Schedule cleanup of the rate-limit + retention crons before dropping tables.
        (new RateLimiter())->unregister();
        (new SubmissionsRetentionCron(new SubmissionRepository()))->unschedule();

        // Trash every contact form CPT post — uninstall.php's table/option
        // sweeper covers our submissions + rate tables and prefs.
        $posts = get_posts([
            'post_type'      => CPT::POST_TYPE,
            'post_status'    => 'any',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'suppress_filters' => true,
        ]);
        foreach ($posts as $post_id) {
            wp_delete_post((int) $post_id, true);
        }

        Schema::drop();
    }

    public function admin_page_url(): ?string
    {
        return admin_url('admin.php?page=' . FormsPage::SLUG);
    }

    public function data_summary(): string
    {
        $forms = (int) wp_count_posts(CPT::POST_TYPE)->publish
               + (int) wp_count_posts(CPT::POST_TYPE)->draft;
        $submissions = (new SubmissionRepository())->count_total();
        if ($forms === 0 && $submissions === 0) {
            return '';
        }
        $forms_label = sprintf(
            /* translators: %d: number of contact forms. */
            _n('%d form', '%d forms', $forms, 'lrob-email-toolkit'),
            $forms
        );
        $submissions_label = sprintf(
            /* translators: %s: number of submissions (already formatted with i18n thousands separator). */
            _n('%s submission', '%s submissions', $submissions, 'lrob-email-toolkit'),
            number_format_i18n($submissions)
        );
        return $forms_label . ', ' . $submissions_label;
    }

    public function register(): void
    {
        $rate_limiter = new RateLimiter();
        $submissions = new SubmissionRepository();
        $this->container->set(RateLimiter::class, $rate_limiter);
        $this->container->set(SubmissionRepository::class, $submissions);

        // Field types this CPT accepts. The shared form-builder dispatches
        // via the registry — adding a new field type here is the entry
        // point. Captcha is module-specific (its routing reads contact-form
        // meta + the contact_form Captcha context); the rest are stock.
        $registry = $this->container->get(FieldTypeRegistry::class);
        if ($registry instanceof FieldTypeRegistry) {
            $registry->register(CPT::POST_TYPE, new TextField());
            $registry->register(CPT::POST_TYPE, new EmailField());
            $registry->register(CPT::POST_TYPE, new TextareaField());
            $registry->register(CPT::POST_TYPE, new NumberField());
            $registry->register(CPT::POST_TYPE, new PhoneField());
            $registry->register(CPT::POST_TYPE, new DateField());
            $registry->register(CPT::POST_TYPE, new SelectField());
            $registry->register(CPT::POST_TYPE, new RadioField());
            $registry->register(CPT::POST_TYPE, new CheckboxField());
            $registry->register(CPT::POST_TYPE, new SubmitField());
            // Shared captcha field, configured for the contact_form Captcha
            // routing context + the legacy META_CHALLENGE_KIND meta key.
            $registry->register(CPT::POST_TYPE, new SharedCaptchaField('contact_form', CPT::META_CHALLENGE_KIND));
        }

        // Runtime (CPT, blocks, AJAX submit, cron) only when enabled.
        if ($this->is_enabled()) {
            (new CPT())->register();
            (new Blocks())->register();
            (new Frontend())->register();
            $rate_limiter->register();
            (new SubmitHandler($rate_limiter, $submissions))->register();
            $retention = new SubmissionsRetentionCron($submissions);
            $retention->register();
            $retention->schedule();
        }

        // Admin chrome stays registered regardless of enabled state, so the
        // user can land on the Contact Forms page after disabling and
        // re-enable from there (FormsPage shows a disabled-state message).
        if (is_admin()) {
            add_action('admin_post_' . $this->toggle_action(), [$this, 'handle_toggle']);
            $submissions_page = new SubmissionsPage($submissions);
            $forms_page = new FormsPage($this, $submissions_page);
            $forms_page->register();
            (new PageController($this, $forms_page))->register();
            (new AjaxController())->register();
        }
    }
}
