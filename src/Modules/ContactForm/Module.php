<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use LRob\EmailToolkit\Forms\CaptchaField as SharedCaptchaField;
use LRob\EmailToolkit\Forms\FieldTypeRegistry;
use LRob\EmailToolkit\Forms\Fields\CheckboxField;
use LRob\EmailToolkit\Forms\Fields\DateField;
use LRob\EmailToolkit\Forms\Fields\EmailField;
use LRob\EmailToolkit\Forms\Fields\FileUploadField;
use LRob\EmailToolkit\Forms\Fields\NumberField;
use LRob\EmailToolkit\Forms\Fields\PhoneField;
use LRob\EmailToolkit\Forms\Fields\RadioField;
use LRob\EmailToolkit\Forms\Fields\SelectField;
use LRob\EmailToolkit\Forms\Fields\SubmitField;
use LRob\EmailToolkit\Forms\Fields\TextField;
use LRob\EmailToolkit\Forms\Fields\TextareaField;
use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\ContactForm\Admin\AjaxController;
use LRob\EmailToolkit\Modules\ContactForm\Admin\EmailActions;
use LRob\EmailToolkit\Modules\ContactForm\Admin\FormsPage;
use LRob\EmailToolkit\Modules\ContactForm\Admin\PageController;
use LRob\EmailToolkit\Modules\ContactForm\Admin\SubmissionsAjax;
use LRob\EmailToolkit\Modules\ContactForm\Admin\SubmissionsPage;

// Docs: docs/contact-form.md
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
            'Drag-and-drop contact forms with built-in anti-spam.',
            'lrob-email-toolkit'
        );
    }

    public function version(): string
    {
        return '0.0.2';
    }

    public function db_version_int(): int
    {
        return 5;
    }

    public function install(): void
    {
        Schema::install();
        self::migrate_captcha_routing_keys();
    }

    // Schema version history: docs/contact-form.md § Module lifecycle.
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
        if ($from_version < 5) {
            Schema::install();
        }
    }

    // Idempotent: re-runs see nothing left to convert.
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
        $files = new FileRepository();
        $this->container->set(RateLimiter::class, $rate_limiter);
        $this->container->set(SubmissionRepository::class, $submissions);
        $this->container->set(FileRepository::class, $files);

        // Captcha is module-specific; its routing reads per-form meta + the contact_form Captcha context.
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
            $registry->register(CPT::POST_TYPE, new FileUploadField());
            $registry->register(CPT::POST_TYPE, new SubmitField());
            $registry->register(CPT::POST_TYPE, new SharedCaptchaField('contact_form', CPT::META_CHALLENGE_KIND));
        }

        if ($this->is_enabled()) {
            // Surface "Contact form emails" as a routable source in the SMTP routing UI
            // (SubmitHandler already sends under the contact_form source).
            add_filter('lrob_etk_smtp_known_sources', static function (array $sources): array {
                $sources[\LRob\EmailToolkit\Modules\SMTP\SourceResolver::SOURCE_CONTACT_FORM] = __('Contact form emails', 'lrob-email-toolkit');
                return $sources;
            });

            (new CPT())->register();
            (new Blocks())->register();
            (new Frontend())->register();
            $rate_limiter->register();
            (new SubmitHandler($rate_limiter, $submissions, $files))->register();
            (new FileDownloadController($files))->register();
            $retention = new SubmissionsRetentionCron($submissions, $files);
            $retention->register();
            $retention->schedule();
        }

        // Admin chrome registered regardless of module state so the user can re-enable from the page.
        if (is_admin()) {
            add_action('admin_post_' . $this->toggle_action(), [$this, 'handle_toggle']);
            $submissions_page = new SubmissionsPage($submissions);
            $forms_page = new FormsPage($this, $submissions_page);
            $forms_page->register();
            (new PageController($this, $forms_page))->register();
            (new AjaxController())->register();
            (new EmailActions($submissions, $files))->register();
            (new SubmissionsAjax($submissions_page, $submissions, $files))->register();
        }
    }
}
