<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Forms\CaptchaField as SharedCaptchaField;
use LRob\EmailToolkit\Forms\FieldTypeRegistry;
use LRob\EmailToolkit\Forms\Fields\EmailField;
use LRob\EmailToolkit\Forms\Fields\SubmitField;
use LRob\EmailToolkit\Forms\Fields\TextField;
use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\Captcha\Routing as CaptchaRouting;
use LRob\EmailToolkit\Modules\Newsletter\Admin\AjaxController;
use LRob\EmailToolkit\Modules\Newsletter\Admin\CampaignsPage;
use LRob\EmailToolkit\Modules\Newsletter\Admin\CategoriesPage;
use LRob\EmailToolkit\Modules\Newsletter\Admin\FormsPage;
use LRob\EmailToolkit\Modules\Newsletter\Admin\HomePage;
use LRob\EmailToolkit\Modules\Newsletter\Admin\ListsPage;
use LRob\EmailToolkit\Modules\Newsletter\Admin\PageController;
use LRob\EmailToolkit\Modules\Newsletter\Admin\SettingsPage;
use LRob\EmailToolkit\Modules\Newsletter\Admin\SubscribersPage;
use LRob\EmailToolkit\Modules\Newsletter\Fields\CategoryPicker;
use LRob\EmailToolkit\Modules\Newsletter\Fields\ListPicker;

/**
 * Newsletter module — campaigns to WordPress users and email-only
 * subscribers, with rule-based + manual lists, category-scoped opt-outs,
 * Gutenberg-composed emails, AJAX+Cron send pipeline, and tracking.
 *
 * Full design spec at repo-root `newsletter.md`. v0.3.0 ships across
 * multiple iterations; today's slice (step 1) lands: schema, recipient
 * model (subscribers table + WP-user user_meta), admin homepage hub
 * shell with `&view=` dispatch, user-register/deleted-user hooks, and
 * the SMTP-dependency admin notice.
 *
 * Forms, campaigns, templates, send pipeline, and tracking endpoints land
 * in later steps — the menu reflects them as "Coming soon" placeholders.
 */
final class Module extends AbstractModule
{
    /**
     * Pending-seed flag for the system email templates. Set by install()
     * (which can run too early to insert posts safely) and consumed by
     * maybe_seed_templates() on init once the CPT is registered.
     */
    private const OPTION_PENDING_TEMPLATE_SEED = 'lrob_etk_newsletter_pending_template_seed';

    public function slug(): string
    {
        return 'newsletter';
    }

    public function name(): string
    {
        return __('Newsletter', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __(
            'Send campaigns to your WordPress users and email-only subscribers, with rule-based segments, per-category opt-outs, and a throttled AJAX+Cron send pipeline.',
            'lrob-email-toolkit'
        );
    }

    public function version(): string
    {
        return '0.1.0';
    }

    /**
     * Schema versions:
     *   1 — initial install (7 tables + seeded "General" category).
     *   2 — adds the system email-template seeds.
     *   3 — repair pass: deletes default templates that the v2 seeder
     *       persisted with broken URL tokens (esc_url_raw stripped the
     *       `{` and `}` from `{{confirm_url}}`). The pending-seed flag
     *       triggers re-seeding on init with the fixed content.
     *   4 — subscribers table grows `reminder_count` + `last_reminder_at`
     *       columns + a pending-followup index for the reminder cron's
     *       scan query. Also adds a prefs_token index for the public
     *       prefs URL handler's O(1) lookup. dbDelta handles the ALTER
     *       TABLE additively.
     */
    public function db_version_int(): int
    {
        return 4;
    }

    public function install(): void
    {
        Schema::install();
        self::seed_default_category();

        // Template seeding deferred via a pending-flag option. install()
        // can fire from maybe_migrate() during plugins_loaded — too early
        // to safely insert posts (the CPT isn't registered yet AND the
        // $wp_rewrite global hasn't been initialised, so even calling
        // register_post_type defensively here fatal-errors). The pending
        // flag is picked up by maybe_seed_templates() on init priority
        // 20, after TemplateCPT registers at init priority 6.
        update_option(self::OPTION_PENDING_TEMPLATE_SEED, '1', false);

        // Daily reminder cron — schedule on first install + every
        // migration (idempotent; wp_schedule_event short-circuits
        // when the hook is already queued).
        ReminderCron::schedule();

        // Daily trash auto-purge cron — only deletes anything when
        // the admin sets `lrob_etk_nl_trash_auto_purge_days` > 0;
        // otherwise the tick is a cheap no-op.
        TrashCron::schedule();
    }

    /**
     * Idempotent forward-migration. install() handles every additive bit
     * (dbDelta is additive; category seed is guarded; template seed is
     * deferred via flag and the seeder itself skips non-empty purposes).
     * v3 adds a repair step: delete is_default templates whose content
     * is missing required tokens, so the deferred seeder rebuilds them.
     */
    public function migrate(int $from_version, int $to_version): void
    {
        unset($to_version);
        $this->install();
        if ($from_version < 3) {
            self::repair_broken_default_templates();
        }
    }

    /**
     * Delete any is_default-flagged email template whose content is
     * missing a required token (the v2 seeder ran URLs through
     * esc_url_raw which silently stripped `{{` `}}` braces). Direct SQL
     * because this runs at plugins_loaded — the CPT isn't registered
     * yet and wp_delete_post depends on get_post_type_object lookups
     * that would short-circuit.
     */
    private static function repair_broken_default_templates(): void
    {
        global $wpdb;
        $purposes_with_required = [
            TemplateCPT::PURPOSE_CONFIRMATION => ['{{confirm_url}}', '{{refuse_url}}'],
            TemplateCPT::PURPOSE_REMINDER     => ['{{confirm_url}}', '{{refuse_url}}'],
        ];
        foreach ($purposes_with_required as $purpose => $required) {
            $candidates = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_content
                   FROM {$wpdb->posts} p
                   INNER JOIN {$wpdb->postmeta} mp
                     ON mp.post_id = p.ID
                     AND mp.meta_key = %s
                     AND mp.meta_value = %s
                   INNER JOIN {$wpdb->postmeta} md
                     ON md.post_id = p.ID
                     AND md.meta_key = %s
                     AND md.meta_value = '1'
                  WHERE p.post_type = %s",
                TemplateCPT::META_PURPOSE,
                $purpose,
                TemplateCPT::META_IS_DEFAULT,
                TemplateCPT::POST_TYPE
            ));
            if (!is_array($candidates)) {
                continue;
            }
            foreach ($candidates as $row) {
                $content = (string) ($row->post_content ?? '');
                $broken = false;
                foreach ($required as $token) {
                    if (!str_contains($content, $token)) {
                        $broken = true;
                        break;
                    }
                }
                if (!$broken) {
                    continue;
                }
                $post_id = (int) $row->ID;
                $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id], ['%d']);
                $wpdb->delete($wpdb->posts, ['ID' => $post_id], ['%d']);
            }
        }
    }

    public function uninstall(): void
    {
        ReminderCron::unschedule();
        TrashCron::unschedule();
        Schema::drop();
    }

    /**
     * Disable-side cron cleanup. AbstractModule::disable preserves data
     * but doesn't drop cron events — override to clear them so a disabled
     * module isn't still firing its reminder tick.
     */
    public function disable(): void
    {
        ReminderCron::unschedule();
        TrashCron::unschedule();
        parent::disable();
    }

    public function admin_page_url(): ?string
    {
        return admin_url('admin.php?page=' . PageController::SLUG);
    }

    public function data_summary(): string
    {
        $subs = (new SubscriberRepository())->count_total();
        if ($subs === 0) {
            return '';
        }
        return sprintf(
            /* translators: %s: number of subscribers (already formatted with i18n thousands separator). */
            _n('%s subscriber', '%s subscribers', $subs, 'lrob-email-toolkit'),
            number_format_i18n($subs)
        );
    }

    public function register(): void
    {
        $subscribers = new SubscriberRepository();
        $templates = new TemplateRepository();
        $forms = new FormRepository();
        $categories = new CategoryRepository();
        $lists = new ListRepository();
        $campaigns = new CampaignRepository();
        $this->container->set(SubscriberRepository::class, $subscribers);
        $this->container->set(TemplateRepository::class, $templates);
        $this->container->set(FormRepository::class, $forms);
        $this->container->set(CategoryRepository::class, $categories);
        $this->container->set(ListRepository::class, $lists);
        $this->container->set(CampaignRepository::class, $campaigns);

        // CPTs register unconditionally so admin code can edit existing
        // posts even when the module is disabled (data preserved). All
        // three CPTs are non-public and use `rewrite=false`, so
        // registering at any request stage is safe.
        (new TemplateCPT())->register();
        (new FormCPT())->register();
        (new CampaignCPT())->register();

        // Register the field types the subscribe-form CPT accepts.
        // Subset of the full form-builder vocabulary — list-picker and
        // category-picker join in step 4 when lists + categories ship.
        $registry = $this->container->get(FieldTypeRegistry::class);
        if ($registry instanceof FieldTypeRegistry) {
            $registry->register(FormCPT::POST_TYPE, new EmailField());
            $registry->register(FormCPT::POST_TYPE, new TextField());
            $registry->register(FormCPT::POST_TYPE, new SubmitField());
            // Shared captcha field, configured for the newsletter_subscribe
            // Captcha routing context + the per-form override meta key.
            // Same class Contact Form uses (different context + meta key).
            $registry->register(
                FormCPT::POST_TYPE,
                new SharedCaptchaField(CaptchaRouting::CONTEXT_NEWSLETTER, FormCPT::META_CAPTCHA_ROUTE)
            );
            // Newsletter-specific picker fields. Categories + lists
            // are newsletter concepts so these are module-local.
            $registry->register(FormCPT::POST_TYPE, new CategoryPicker());
            $registry->register(FormCPT::POST_TYPE, new ListPicker());
        }

        // Pending-seed handler runs after the CPT's init registration
        // (priority 6); priority 20 here gives that headroom.
        add_action('init', [$this, 'maybe_seed_templates'], 20);

        // Runtime hooks only when the module is enabled. Disabling preserves
        // data but stops promotion/seed behaviors and (later) the daily crons.
        if ($this->is_enabled()) {
            $hooks = new UserHooks($subscribers);
            add_action('user_register', [$hooks, 'on_user_register'], 10, 1);
            add_action('deleted_user', [$hooks, 'on_deleted_user'], 10, 1);

            // Frontend pipeline: block + shortcode + EmbedRenderer (via
            // Blocks::register_blocks render_callback) + submit handler
            // + confirmation URL handler. The frontend script + style
            // register on wp_enqueue_scripts; EmbedRenderer enqueues
            // them on demand when a form actually renders.
            (new Frontend())->register();
            (new Blocks())->register();
            (new SubmitHandler($subscribers, $lists, $categories))->register();
            (new ConfirmationHandler($subscribers))->register();

            // Preferences page + one-click unsubscribe + WP profile
            // section. PrefsHandler hooks on init to catch the public
            // URLs; ProfileSection hooks on show/edit_user_profile to
            // surface the same UI inside the admin user-edit page.
            // PrefsBlock registers a Gutenberg block + shortcode that
            // embeds the same prefs UI on any public page (typically a
            // `/newsletter-preferences/` page linked from the menu).
            (new PrefsHandler($subscribers, $lists, $categories))->register();
            (new ProfileSection($categories, $lists))->register();
            (new PrefsBlock($subscribers, $lists, $categories))->register();

            // Daily reminder cron for pending subscribers — the
            // schedule itself lives on install / disable transitions.
            (new ReminderCron($subscribers))->register();

            // Daily trash auto-purge cron. Reads its retention setting
            // at tick time, so toggling the option in Settings takes
            // effect on the next cron run without a re-schedule.
            (new TrashCron($subscribers))->register();
        }

        if (is_admin()) {
            add_action('admin_post_' . $this->toggle_action(), [$this, 'handle_toggle']);
            (new TemplateValidator())->register();
            (new CampaignMetaboxes($campaigns, $categories, $lists, $this->container))->register();
            $forms_page = new FormsPage($forms, $templates);
            $categories_page = new CategoriesPage($categories);
            $lists_page = new ListsPage($lists);
            $settings_page = new SettingsPage();
            $subscribers_page = new SubscribersPage($subscribers);
            $campaigns_page = new CampaignsPage($campaigns);
            $campaigns_page->register();
            $home = new HomePage($this, $subscribers, $templates, $forms_page, $categories_page, $lists_page, $settings_page, $subscribers_page, $campaigns_page);
            (new PageController($this, $home))->register();
            (new AjaxController())->register();
            add_action('admin_init', [$this, 'handle_new_from_default']);
            add_action('admin_enqueue_scripts', [$home, 'enqueue_assets']);
            add_action('admin_enqueue_scripts', [$forms_page, 'enqueue_assets']);
            add_action('admin_notices', [$this, 'render_smtp_dependency_notice']);
        }
    }

    /**
     * Idempotent seed of the default email templates, deferred from
     * install() so it runs after the CPT is registered (init priority 6)
     * and after WP's request lifecycle is far enough along that
     * wp_insert_post is safe.
     */
    public function maybe_seed_templates(): void
    {
        if (!$this->is_enabled()) {
            return;
        }
        if (get_option(self::OPTION_PENDING_TEMPLATE_SEED) !== '1') {
            return;
        }
        TemplateSeeder::seed_defaults();
        delete_option(self::OPTION_PENDING_TEMPLATE_SEED);
    }

    /**
     * Action target for per-purpose "+ New" buttons in the Onboarding
     * view. Spins up a draft pre-filled with the seed content for the
     * chosen purpose so the admin doesn't start from a blank editor (and
     * doesn't have to remember which tokens are required). Nonce-gated;
     * capability-checked.
     */
    public const ACTION_NEW_FROM_DEFAULT = 'lrob_etk_nl_new_template_from_default';

    public function handle_new_from_default(): void
    {
        if (!isset($_GET['action']) || $_GET['action'] !== self::ACTION_NEW_FROM_DEFAULT) {
            return;
        }
        if (!current_user_can(\LRob\EmailToolkit\Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::ACTION_NEW_FROM_DEFAULT)) {
            wp_die(esc_html__('Security check failed. Please retry.', 'lrob-email-toolkit'));
        }
        $purpose = isset($_GET['purpose']) ? sanitize_key((string) $_GET['purpose']) : '';
        if (!in_array($purpose, TemplateCPT::purposes(), true)) {
            wp_die(esc_html__('Unknown onboarding email purpose.', 'lrob-email-toolkit'));
        }

        // Title hints "(copy)" so the admin can tell the new draft apart
        // from the auto-seeded original at a glance in the Onboarding list.
        $title = sprintf(
            /* translators: %s: localized title of the source default template (e.g. "Confirm your subscription"). */
            __('%s (copy)', 'lrob-email-toolkit'),
            TemplateSeeder::default_title($purpose)
        );

        $new_id = wp_insert_post([
            'post_type'    => TemplateCPT::POST_TYPE,
            'post_status'  => 'draft',
            'post_title'   => $title,
            'post_content' => TemplateSeeder::default_content($purpose),
            'meta_input'   => [
                TemplateCPT::META_PURPOSE    => $purpose,
                TemplateCPT::META_IS_DEFAULT => false,
            ],
        ], true);

        if (is_wp_error($new_id) || !is_int($new_id) || $new_id <= 0) {
            wp_die(esc_html__('Could not create the template draft.', 'lrob-email-toolkit'));
        }

        wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_id));
        exit;
    }

    /**
     * Persistent admin notice when Newsletter is enabled but SMTP isn't.
     * Subscribe-form ingest and admin UI work without SMTP — only campaign
     * sending is gated. Re-renders on every page load until SMTP is on.
     */
    public function render_smtp_dependency_notice(): void
    {
        if (!$this->is_enabled()) {
            return;
        }
        if (!current_user_can(\LRob\EmailToolkit\Activator::CAPABILITY)) {
            return;
        }
        $manager = $this->container->get(\LRob\EmailToolkit\Modules\ModuleManager::class);
        if (!$manager instanceof \LRob\EmailToolkit\Modules\ModuleManager) {
            return;
        }
        $smtp = $manager->get('smtp');
        if ($smtp !== null && $smtp->is_enabled()) {
            return;
        }
        $smtp_url = $smtp !== null && $smtp->admin_page_url() !== null
            ? (string) $smtp->admin_page_url()
            : admin_url('admin.php?page=lrob-etk');
        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong></p><p>%2$s</p><p><a href="%3$s" class="button">%4$s</a></p></div>',
            esc_html__('Newsletter: SMTP module not enabled', 'lrob-email-toolkit'),
            esc_html__('Subscribe forms and the Newsletter admin work, but campaign sending is disabled until you enable the SMTP module — Newsletter routes every send through an SMTP identity.', 'lrob-email-toolkit'),
            esc_url($smtp_url),
            esc_html__('Open SMTP settings', 'lrob-email-toolkit')
        );
    }

    /**
     * Seed a `general` category so single-category sites don't have to
     * think about categories at all — every campaign sent before the admin
     * touches the Categories screen lands on this default. Idempotent: if
     * any category already exists (legacy import, restore) we leave the
     * table alone.
     */
    private static function seed_default_category(): void
    {
        global $wpdb;
        $table = Schema::categories_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
        if ($count > 0) {
            return;
        }
        $wpdb->insert($table, [
            'name'        => __('General', 'lrob-email-toolkit'),
            'slug'        => 'general',
            'description' => '',
            'sort_order'  => 0,
            'created_at'  => current_time('mysql', true),
        ], ['%s', '%s', '%s', '%d', '%s']);
    }
}
