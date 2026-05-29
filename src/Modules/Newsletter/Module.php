<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Forms\CaptchaField as SharedCaptchaField;
use LRob\EmailToolkit\Forms\FieldTypeRegistry;
use LRob\EmailToolkit\Forms\Fields\EmailField;
use LRob\EmailToolkit\Forms\Fields\PhoneField;
use LRob\EmailToolkit\Forms\Fields\SelectField;
use LRob\EmailToolkit\Forms\Fields\SubmitField;
use LRob\EmailToolkit\Forms\Fields\TextField;
use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\Captcha\Routing as CaptchaRouting;
use LRob\EmailToolkit\Modules\Newsletter\Admin\AjaxController;
use LRob\EmailToolkit\Modules\Newsletter\Admin\FormsPage;
use LRob\EmailToolkit\Modules\Newsletter\Admin\HomePage;
use LRob\EmailToolkit\Modules\Newsletter\Admin\ListsPage;
use LRob\EmailToolkit\Modules\Newsletter\Admin\NewslettersPage;
use LRob\EmailToolkit\Modules\Newsletter\Admin\PageController;
use LRob\EmailToolkit\Modules\Newsletter\Admin\SettingsPage;
use LRob\EmailToolkit\Modules\Newsletter\Admin\SubscribersPage;
use LRob\EmailToolkit\Modules\Newsletter\Fields\GenderField;
use LRob\EmailToolkit\Modules\Newsletter\Fields\ListPicker;
use LRob\EmailToolkit\Modules\Newsletter\Send\Materializer;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendAjaxController;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendCron;
use LRob\EmailToolkit\Modules\Newsletter\Send\SendLoop;
use LRob\EmailToolkit\Modules\Newsletter\Tracking\AssetRepository as TrackingAssetRepository;
use LRob\EmailToolkit\Modules\Newsletter\Tracking\LinkRepository as TrackingLinkRepository;
use LRob\EmailToolkit\Modules\Newsletter\Tracking\Pipeline as TrackingPipeline;
use LRob\EmailToolkit\Modules\Newsletter\Tracking\RestController as TrackingRestController;
use LRob\EmailToolkit\Modules\Newsletter\Tracking\RetentionCron as TrackingRetentionCron;

// Docs: docs/newsletter-internals.md
final class Module extends AbstractModule
{
    private const OPTION_PENDING_TEMPLATE_SEED = 'lrob_etk_newsletter_pending_template_seed';

    public function slug(): string
    {
        return 'newsletter';
    }

    public function name(): string
    {
        return __('Newsletters', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __(
            'Send newsletters to your subscribers and WordPress users.',
            'lrob-email-toolkit'
        );
    }

    public function version(): string
    {
        return '0.1.0';
    }

    // Schema version history: docs/newsletter-internals.md → "Schema versions"
    public function db_version_int(): int
    {
        return 14;
    }

    public function install(): void
    {
        Schema::install();
        self::seed_system_lists();

        // CPT not registered yet at plugins_loaded; defer post insertion to init.
        update_option(self::OPTION_PENDING_TEMPLATE_SEED, '1', false);

        ReminderCron::schedule();
        TrashCron::schedule();
        SendCron::schedule();
        TrackingRetentionCron::schedule();
    }

    public function migrate(int $from_version, int $to_version): void
    {
        unset($to_version);
        if ($from_version < 6) {
            self::migrate_campaign_to_newsletter();
        }
        $this->install();
        if ($from_version < 10) {
            self::backfill_list_kinds();
        }
        if ($from_version < 12) {
            self::migrate_categories_to_lists();
        }
        if ($from_version < 13) {
            self::drop_legacy_category_artifacts();
        }
        if ($from_version < 3) {
            self::repair_broken_default_templates();
        }
    }

    // v6: hand-coded ALTER/RENAME before install() — dbDelta can't rename.
    private static function migrate_campaign_to_newsletter(): void
    {
        global $wpdb;
        $database = (string) $wpdb->get_var('SELECT DATABASE()');
        $old_campaigns = $wpdb->prefix . 'lrob_etk_nl_campaigns';
        $new_newsletters = $wpdb->prefix . 'lrob_etk_nl_newsletters';
        $old_recipients = $wpdb->prefix . 'lrob_etk_nl_campaign_recipients';
        $new_recipients = $wpdb->prefix . 'lrob_etk_nl_newsletter_recipients';
        $tracking = $wpdb->prefix . 'lrob_etk_nl_tracking_events';

        $table_exists = static function (string $table) use ($wpdb, $database): bool {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                $database,
                $table
            )) > 0;
        };
        $column_exists = static function (string $table, string $column) use ($wpdb, $database): bool {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $database,
                $table,
                $column
            )) > 0;
        };
        $index_exists = static function (string $table, string $index) use ($wpdb, $database): bool {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
                $database,
                $table,
                $index
            )) > 0;
        };

        // 1. Rename companion tables.
        if ($table_exists($old_campaigns) && !$table_exists($new_newsletters)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("RENAME TABLE `$old_campaigns` TO `$new_newsletters`");
        }
        if ($table_exists($old_recipients) && !$table_exists($new_recipients)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("RENAME TABLE `$old_recipients` TO `$new_recipients`");
        }

        // 2. Rename campaign_id columns. dbDelta will then see the
        //    renamed columns and run as a no-op against the new
        //    CREATE TABLE statements.
        if ($table_exists($new_recipients) && $column_exists($new_recipients, 'campaign_id')) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("ALTER TABLE `$new_recipients` CHANGE `campaign_id` `newsletter_id` bigint(20) unsigned NOT NULL");
            // Old index names use the campaign_* prefix; drop so dbDelta
            // recreates them under the newsletter_* names.
            if ($index_exists($new_recipients, 'campaign_recipient')) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query("ALTER TABLE `$new_recipients` DROP INDEX `campaign_recipient`");
            }
            if ($index_exists($new_recipients, 'domain_pending')) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query("ALTER TABLE `$new_recipients` DROP INDEX `domain_pending`");
            }
        }
        if ($table_exists($tracking) && $column_exists($tracking, 'campaign_id')) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("ALTER TABLE `$tracking` CHANGE `campaign_id` `newsletter_id` bigint(20) unsigned NOT NULL");
            if ($index_exists($tracking, 'campaign_kind')) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query("ALTER TABLE `$tracking` DROP INDEX `campaign_kind`");
            }
        }

        // 3. Migrate CPT posts to the new post_type slug. wp_posts is
        //    a core WP table — column name is `post_type` and slug
        //    needs to fit the 20-char post_type column.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s",
            'lrob_etk_newsletter',
            'lrob_etk_nl_campaign'
        ));
    }

    private static function backfill_list_kinds(): void
    {
        global $wpdb;
        $lists = Schema::lists_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("UPDATE `$lists` SET kind = 'users' WHERE rule_json <> '' AND kind = 'subscribers'");
    }

    private static function migrate_categories_to_lists(): void
    {
        $option = 'lrob_etk_nl_categories_merged_into_lists';
        if (get_option($option) === '1') {
            return;
        }

        global $wpdb;
        // Inline literal — Schema::categories_table() retires in v13.
        $cats_table = $wpdb->prefix . 'lrob_etk_nl_categories';
        $lists_table = Schema::lists_table();
        $subs_table = Schema::subscribers_table();
        $members_table = Schema::list_members_table();

        // Suppress the wpdb error log when the table doesn't exist (fresh
        // install upgrading directly to v13 — no categories ever created).
        $prev_suppress = $wpdb->suppress_errors(true);
        $table_exists = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1",
            $cats_table
        ));
        if ($table_exists === '') {
            $wpdb->suppress_errors($prev_suppress);
            update_option($option, '1', false);
            return;
        }
        $wpdb->suppress_errors($prev_suppress);

        // Bail cleanly when the categories table doesn't exist
        // (fresh install — install() already ran via the migrate
        // flow but the legacy table wasn't seeded into).
        $cats = (array) $wpdb->get_results("SELECT * FROM `$cats_table` ORDER BY id ASC", ARRAY_A);
        if ($cats === []) {
            update_option($option, '1', false);
            return;
        }

        $now = current_time('mysql', true);
        $slug_to_list_id = [];

        foreach ($cats as $cat) {
            $cat_slug = (string) ($cat['slug'] ?? '');
            $cat_name = (string) ($cat['name'] ?? $cat_slug);
            if ($cat_slug === '') {
                continue;
            }

            // Use the original category slug as-is for the new list.
            // Schema collision risk: very low — categories never shared
            // slugs with lists pre-v12 (different tables, no overlap).
            // If a clash exists, append '-list' to disambiguate.
            $target_slug = $cat_slug;
            $existing_list = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `$lists_table` WHERE slug = %s LIMIT 1",
                $target_slug
            ));
            if ($existing_list > 0) {
                // Already migrated in a previous run — reuse it.
                $slug_to_list_id[$cat_slug] = $existing_list;
                continue;
            }

            $wpdb->insert($lists_table, [
                'name'        => $cat_name,
                'slug'        => $target_slug,
                'kind'        => 'subscribers',
                'is_system'   => 0,
                'visibility'  => 'public',
                'description' => (string) ($cat['description'] ?? ''),
                'rule_json'   => '',
                'created_at'  => $now,
                'updated_at'  => $now,
            ], ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']);
            $new_id = (int) $wpdb->insert_id;
            if ($new_id > 0) {
                $slug_to_list_id[$cat_slug] = $new_id;
            }
        }

        // Subscriber side: walk subscribers, for each whose
        // category_opt_outs JSON DOES NOT contain a category's slug,
        // INSERT IGNORE into list_members. Status filter: every
        // status except 'trashed' (preserve memberships for unsubs
        // so they show up if they restore).
        $subscribers = (array) $wpdb->get_results(
            "SELECT id, category_opt_outs FROM `$subs_table` WHERE status != 'trashed'",
            ARRAY_A
        );
        foreach ($subscribers as $sub) {
            $sid = (int) $sub['id'];
            $opt_outs = self::decode_opt_outs((string) ($sub['category_opt_outs'] ?? ''));
            foreach ($slug_to_list_id as $slug => $list_id) {
                if (in_array($slug, $opt_outs, true)) {
                    continue;
                }
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO `$members_table` (list_id, recipient_kind, recipient_id, added_at) VALUES (%d, %s, %d, %s)",
                    $list_id,
                    'subscriber',
                    $sid,
                    $now
                ));
            }
        }

        // WP-user side: only users with an explicit OPTED_IN='1' meta
        // flag get migrated (the others are eligible but uninstantiated).
        // Materialising every WP user as a list_member would inflate the
        // table; the Materializer already treats absent OPTED_IN as
        // "eligible" via the OR NOT EXISTS clause on system lists.
        $opted_in_users = get_users([
            'meta_key'   => UserMeta::OPTED_IN,
            'meta_value' => '1',
            'fields'     => ['ID'],
            'number'     => -1,
        ]);
        foreach (is_array($opted_in_users) ? $opted_in_users : [] as $u) {
            $uid = (int) $u->ID;
            $opt_outs_json = (string) get_user_meta($uid, UserMeta::CATEGORY_OPT_OUTS, true);
            $opt_outs = self::decode_opt_outs($opt_outs_json);
            foreach ($slug_to_list_id as $slug => $list_id) {
                if (in_array($slug, $opt_outs, true)) {
                    continue;
                }
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO `$members_table` (list_id, recipient_kind, recipient_id, added_at) VALUES (%d, %s, %d, %s)",
                    $list_id,
                    'user',
                    $uid,
                    $now
                ));
            }
        }

        $newsletter_meta = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
              WHERE meta_key = %s AND meta_value <> '' AND meta_value <> '0'",
            NewsletterCPT::META_CATEGORY_ID
        ), ARRAY_A);
        $cat_id_to_slug = [];
        foreach ($cats as $cat) {
            $cat_id_to_slug[(int) ($cat['id'] ?? 0)] = (string) ($cat['slug'] ?? '');
        }
        foreach ($newsletter_meta as $row) {
            $post_id = (int) ($row['post_id'] ?? 0);
            $cat_id = (int) ($row['meta_value'] ?? 0);
            if ($post_id <= 0 || $cat_id <= 0) {
                continue;
            }
            $slug = $cat_id_to_slug[$cat_id] ?? '';
            $list_id = $slug !== '' ? ($slug_to_list_id[$slug] ?? 0) : 0;
            if ($list_id <= 0) {
                continue;
            }
            // Skip if already a multi-list audience (don't clobber an existing target_spec).
            $current_raw = (string) get_post_meta($post_id, NewsletterCPT::META_TARGET_SPEC, true);
            $current = $current_raw !== '' ? (array) json_decode($current_raw, true) : [];
            $current_kind = (string) ($current['kind'] ?? NewsletterCPT::TARGET_KIND_ALL);
            if (!in_array($current_kind, [
                NewsletterCPT::TARGET_KIND_ALL,
                NewsletterCPT::TARGET_KIND_ALL_SUBSCRIBERS,
                NewsletterCPT::TARGET_KIND_ALL_USERS,
            ], true)) {
                continue;
            }
            update_post_meta($post_id, NewsletterCPT::META_TARGET_SPEC, (string) wp_json_encode([
                'kind'     => NewsletterCPT::TARGET_KIND_LISTS,
                'list_ids' => [$list_id],
            ]));
        }

        update_option($option, '1', false);
    }

    private static function drop_legacy_category_artifacts(): void
    {
        global $wpdb;
        $cats_table = $wpdb->prefix . 'lrob_etk_nl_categories';
        $subs_table = Schema::subscribers_table();

        $prev_suppress = $wpdb->suppress_errors(true);
        $wpdb->query("DROP TABLE IF EXISTS `$cats_table`");

        // ALTER … DROP COLUMN has no IF EXISTS on MySQL 5.7; check information_schema first.
        $has_column = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'category_opt_outs'",
            $subs_table
        ));
        if ($has_column > 0) {
            $wpdb->query("ALTER TABLE `$subs_table` DROP COLUMN `category_opt_outs`");
        }
        $wpdb->suppress_errors($prev_suppress);

        $wpdb->delete($wpdb->usermeta, ['meta_key' => 'lrob_etk_nl_category_opt_outs'], ['%s']);
    }

    /** Defensive opt-outs JSON decode — tolerates malformed payloads. @return array<int, string> */
    private static function decode_opt_outs(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $arr = json_decode($json, true);
        if (!is_array($arr)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $arr), static fn ($s) => $s !== ''));
    }

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
        SendCron::unschedule();
        TrackingRetentionCron::unschedule();
        Schema::drop();
    }

    public function disable(): void
    {
        ReminderCron::unschedule();
        TrashCron::unschedule();
        SendCron::unschedule();
        TrackingRetentionCron::unschedule();
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
        $lists = new ListRepository();
        $newsletters = new NewsletterRepository();
        $this->container->set(SubscriberRepository::class, $subscribers);
        $this->container->set(TemplateRepository::class, $templates);
        $this->container->set(FormRepository::class, $forms);
        $this->container->set(ListRepository::class, $lists);
        $this->container->set(NewsletterRepository::class, $newsletters);

        (new TemplateCPT())->register();
        (new FormCPT())->register();
        (new NewsletterCPT())->register();
        (new NewsletterLifecycle($newsletters))->register();

        $registry = $this->container->get(FieldTypeRegistry::class);
        if ($registry instanceof FieldTypeRegistry) {
            $registry->register(FormCPT::POST_TYPE, new EmailField());
            $registry->register(FormCPT::POST_TYPE, new TextField());
            $registry->register(FormCPT::POST_TYPE, new PhoneField());
            $registry->register(FormCPT::POST_TYPE, new SelectField());
            $registry->register(FormCPT::POST_TYPE, new SubmitField());
            $registry->register(
                FormCPT::POST_TYPE,
                new SharedCaptchaField(CaptchaRouting::CONTEXT_NEWSLETTER, FormCPT::META_CAPTCHA_ROUTE)
            );
            $registry->register(FormCPT::POST_TYPE, new GenderField());
            $registry->register(FormCPT::POST_TYPE, new ListPicker());
        }

        add_action('init', [$this, 'maybe_seed_templates'], 20);

        if ($this->is_enabled()) {
            $hooks = new UserHooks($subscribers);
            add_action('user_register', [$hooks, 'on_user_register'], 10, 1);
            add_action('deleted_user', [$hooks, 'on_deleted_user'], 10, 1);

            (new Frontend())->register();
            (new Blocks())->register();
            (new SubmitHandler($subscribers, $lists))->register();
            (new ConfirmationHandler($subscribers))->register();
            (new PrefsHandler($subscribers, $lists))->register();
            (new ProfileSection($lists))->register();
            (new PrefsBlock($subscribers, $lists))->register();
            (new ReminderCron($subscribers))->register();
            (new TrashCron($subscribers))->register();

            $tracking_assets = new TrackingAssetRepository();
            $tracking_links  = new TrackingLinkRepository();
            $tracking        = new TrackingPipeline($tracking_assets, $tracking_links);
            $materializer = new Materializer($newsletters, $subscribers);
            $send_loop = new SendLoop($newsletters, $tracking);
            (new SendAjaxController($materializer, $send_loop, $newsletters, $lists))->register();
            (new SendCron($newsletters, $materializer, $send_loop))->register();
            (new TrackingRestController($newsletters, $subscribers, $tracking_assets, $tracking_links))->register();
            (new TrackingRetentionCron())->register();
        }

        if (is_admin()) {
            add_action('admin_post_' . $this->toggle_action(), [$this, 'handle_toggle']);
            (new TemplateValidator())->register();
            $forms_page = new FormsPage($forms, $templates);
            $lists_page = new ListsPage($lists);
            $settings_page = new SettingsPage();
            $subscribers_page = new SubscribersPage($subscribers, new \LRob\EmailToolkit\Modules\Newsletter\WpUserRepository());
            $newsletters_page = new NewslettersPage($newsletters, $lists, $this->container);
            $newsletters_page->register();
            $home = new HomePage($this, $subscribers, $templates, $forms_page, $lists_page, $settings_page, $subscribers_page, $newsletters_page);
            (new PageController($this, $home))->register();
            (new AjaxController())->register();
            add_action('admin_init', [$this, 'handle_new_from_default']);
            add_action('admin_enqueue_scripts', [$home, 'enqueue_assets']);
            add_action('admin_enqueue_scripts', [$forms_page, 'enqueue_assets']);
            add_action('admin_notices', [$this, 'render_smtp_dependency_notice']);
        }
    }

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

        $title = sprintf(
            /* translators: %s: original item title being cloned (form, newsletter, template, etc.) */
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
            esc_html__('Subscribe forms and the Newsletter admin work, but newsletter sending is disabled until you enable the SMTP module — Newsletter routes every send through an SMTP identity.', 'lrob-email-toolkit'),
            esc_url($smtp_url),
            esc_html__('Open SMTP settings', 'lrob-email-toolkit')
        );
    }

    private static function seed_system_lists(): void
    {
        global $wpdb;
        $table = Schema::lists_table();
        $now = current_time('mysql', true);
        $seeds = [
            'all_subscribers' => [
                'name'      => __('All subscribers', 'lrob-email-toolkit'),
                'kind'      => 'all_subscribers',
                'rule_json' => '',
            ],
            'all_wp_members' => [
                'name'      => __('All WP members', 'lrob-email-toolkit'),
                'kind'      => 'users',
                'rule_json' => (string) wp_json_encode([
                    'provider' => 'wp_all_users',
                    'config'   => (object) [],
                ]),
            ],
            'all_wc_customers' => [
                'name'      => __('All WC customers', 'lrob-email-toolkit'),
                'kind'      => 'users',
                'rule_json' => (string) wp_json_encode([
                    'provider' => 'wc_customers',
                    'config'   => (object) [],
                ]),
            ],
            'active_wc_subs' => [
                'name'      => __('Active WC subscribers', 'lrob-email-toolkit'),
                'kind'      => 'users',
                'rule_json' => (string) wp_json_encode([
                    'provider' => 'woo_subscriptions',
                    'config'   => ['statuses' => ['active'], 'product_ids' => []],
                ]),
            ],
        ];
        foreach ($seeds as $slug => $row) {
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `$table` WHERE slug = %s LIMIT 1",
                $slug
            ));
            if ($existing > 0) {
                $wpdb->update($table, [
                    'name'       => $row['name'],
                    'rule_json'  => $row['rule_json'],
                    'kind'       => $row['kind'],
                    'is_system'  => 1,
                    'visibility' => 'private',
                    'updated_at' => $now,
                ], ['id' => $existing], ['%s', '%s', '%s', '%d', '%s', '%s'], ['%d']);
                continue;
            }
            $wpdb->insert($table, [
                'name'        => $row['name'],
                'slug'        => $slug,
                'kind'        => $row['kind'],
                'is_system'   => 1,
                'visibility'  => 'private',
                'description' => '',
                'rule_json'   => $row['rule_json'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ], ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']);
        }
    }
}
