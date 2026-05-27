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

/**
 * Newsletter module — newsletters to WordPress users and email-only
 * subscribers, with manual lists, category-scoped opt-outs, Gutenberg-
 * composed emails, AJAX+Cron send pipeline, and HMAC-signed tracking.
 * Status + remaining work tracked in repo-root completed.md / todo.md.
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
        return __('Newsletters', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __(
            'Send newsletters to your WordPress users and email-only subscribers, with rule-based segments, per-category opt-outs, and a throttled AJAX+Cron send pipeline.',
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
     *   5 — subscribers table grows `language` (varchar(20), default '')
     *       so future target-picker filters can scope by recipient
     *       locale. Captured from Accept-Language at subscribe time;
     *       empty means "unknown" — caller must tolerate the gap.
     *   6 — vocabulary rename pass: Campaign → Newsletter at the
     *       persistence layer. RENAMEs companion tables
     *       (lrob_etk_nl_campaigns → lrob_etk_nl_newsletters,
     *       lrob_etk_nl_campaign_recipients → newsletter_recipients),
     *       renames every `campaign_id` column to `newsletter_id`
     *       (including the dropped/recreated indexes), and migrates
     *       CPT posts via UPDATE wp_posts SET post_type=
     *       'lrob_etk_newsletter' WHERE post_type='lrob_etk_nl_campaign'.
     *       Class names + event names + payload keys are renamed in
     *       code only — no further data migration needed.
     *   7 — adds `pause_reason varchar(50) DEFAULT NULL` to the
     *       newsletters companion table for the SMTP circuit-breaker
     *       (step 7c). Additive — dbDelta handles it via plain install().
     *   8 — step 9 (tracking): adds the two side tables (newsletter_assets
     *       + newsletter_links) and six lifetime-engagement columns on
     *       subscribers (total_sent / total_opened / total_clicked /
     *       sends_since_engagement / last_sent_at / last_engagement_at)
     *       plus a cold_subscribers index. Additive — dbDelta + idempotent
     *       install() path. WP-user equivalents live in user_meta keys
     *       declared on UserMeta.
     *   9 — extended subscriber profile fields for marketing filters:
     *       first_name / last_name (lets subscribe forms collect names as
     *       one composite or two separate fields), gender (with neutral
     *       options), phone, postal address (line / line2 / postcode /
     *       city / region / country). All optional + plain VARCHAR;
     *       SubmitHandler populates them from form fields that declare a
     *       `data-attr-maps-to` target. Additive — dbDelta handles it.
     *  10 — two list kinds: adds `kind varchar(20) DEFAULT 'subscribers'`
     *       on lists + `wp_lrob_etk_nl_list_exclusions` table. Backfill:
     *       existing lists carrying rule_json become kind='users' (the
     *       rule-driven mode); everything else stays 'subscribers'. The
     *       Materializer branches on kind so the two list types stay
     *       semantically distinct from this point on.
     *  11 — `lists.is_system` flag + seed of built-in users-kind lists:
     *       "All WP members", "All WC customers", "Active WC subscribers".
     *       System lists can't be deleted from the UI; rule config is
     *       fixed and the trash icon is suppressed. Newsletter audience
     *       picker offers them alongside admin-created lists.
     *  12 — Categories merged into Lists. Adds `lists.visibility`
     *       ('private' | 'public'). Each existing category becomes a
     *       public subscribers-kind list; subscribers who hadn't opted
     *       out get an explicit list_members row. Newsletters with
     *       META_CATEGORY_ID rewritten to META_TARGET_SPEC = {kind:
     *       'lists', list_ids: [<migrated_list_id>]}.
     *  13 — Destructive cleanup of v12's safety net. Drops the
     *       `wp_lrob_etk_nl_categories` table, the
     *       `subscribers.category_opt_outs` column, the
     *       `lrob_etk_nl_category_opt_outs` user_meta key. v12
     *       migrated all the data; v13 closes the door.
     *  14 — Subscriber email-change confirmation flow. Adds three
     *       columns on subscribers: `pending_email` (the requested
     *       new address), `pending_email_token` (single-use secret
     *       sent to the new address), `pending_email_requested_at`
     *       (TTL anchor). Additive — dbDelta handles it.
     */
    public function db_version_int(): int
    {
        return 14;
    }

    public function install(): void
    {
        Schema::install();
        self::seed_system_lists();

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

        // 1-minute send safety-net cron. Picks up sending newsletters
        // whose last_tick_at is stale (>2 min) so a closed-browser
        // mid-send doesn't strand a batch.
        SendCron::schedule();

        // Daily prune of tracking_events older than the retention window
        // (lrob_etk_nl_tracking_retention_days, default 365). Companion-
        // row aggregate counters are kept forever; only per-event detail
        // ages out.
        TrackingRetentionCron::schedule();
    }

    /**
     * Idempotent forward-migration. install() handles every additive bit
     * (dbDelta is additive; category seed is guarded; template seed is
     * deferred via flag and the seeder itself skips non-empty purposes).
     * v3 adds a repair step: delete is_default templates whose content
     * is missing required tokens, so the deferred seeder rebuilds them.
     *
     * v6 runs the campaign→newsletter rename BEFORE install() since
     * dbDelta would otherwise try to CREATE the new tables alongside
     * the still-present old ones.
     */
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

    /**
     * Schema-v6: rename the persistence layer to align with the
     * Campaign → Newsletter vocabulary shift. ALTER + UPDATE statements;
     * dbDelta can't rename tables/columns/indexes so we do it by hand
     * before install() runs. Each step is guarded with information_schema
     * checks so re-running is safe.
     */
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

    /**
     * Delete any is_default-flagged email template whose content is
     * missing a required token (the v2 seeder ran URLs through
     * esc_url_raw which silently stripped `{{` `}}` braces). Direct SQL
     * because this runs at plugins_loaded — the CPT isn't registered
     * yet and wp_delete_post depends on get_post_type_object lookups
     * that would short-circuit.
     */
    /**
     * Schema v10 backfill: every list with a non-empty rule_json was
     * a rule-driven list pre-v10 and becomes kind='users'. Everything
     * else stays the default 'subscribers'. Idempotent.
     */
    private static function backfill_list_kinds(): void
    {
        global $wpdb;
        $lists = Schema::lists_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("UPDATE `$lists` SET kind = 'users' WHERE rule_json <> '' AND kind = 'subscribers'");
    }

    /**
     * Schema v12 backfill: turn every Category row into a public
     * Subscribers-kind list, and materialise list_members rows for
     * every recipient who hadn't opted out of that category. Then
     * rewrite each newsletter's META_TARGET_SPEC to point at the
     * migrated list instead of META_CATEGORY_ID.
     *
     * Idempotent via a one-time option flag — partial migrations
     * (e.g. a fatal mid-loop) re-run cleanly because list creation +
     * member insertion both go through INSERT IGNORE on UNIQUE keys,
     * and target_spec is rewritten only when it doesn't already
     * point to the migrated list.
     *
     * Categories table + subscribers.category_opt_outs + user_meta
     * CATEGORY_OPT_OUTS all stay in place for one version as a
     * safety net — read paths stop consulting them this version.
     */
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

        // Newsletter side: rewrite META_TARGET_SPEC for every
        // newsletter that had a META_CATEGORY_ID. Walks postmeta
        // directly to keep the query count bounded — one SELECT,
        // one UPDATE per affected newsletter.
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
            // Read current target_spec — only rewrite when it's
            // 'all' / empty / single-list-to-the-same-id, so we
            // don't clobber a multi-list audience already set up.
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

    /**
     * Schema v13: close the door on the categories safety net that v12
     * left in place. v12 migrated every category into a list + every
     * non-opt-out into a list_members row, so the legacy artifacts are
     * pure dead weight at this point. Drops:
     *   - `wp_lrob_etk_nl_categories` table
     *   - `subscribers.category_opt_outs` column
     *   - all `lrob_etk_nl_category_opt_outs` user_meta rows
     *
     * Each step is guarded so re-running is safe. Migration MUST run
     * after migrate_categories_to_lists in the same upgrade pass.
     */
    private static function drop_legacy_category_artifacts(): void
    {
        global $wpdb;
        $cats_table = $wpdb->prefix . 'lrob_etk_nl_categories';
        $subs_table = Schema::subscribers_table();

        // Drop the categories table. IF EXISTS is supported on both
        // MySQL + MariaDB so no information_schema dance required.
        $prev_suppress = $wpdb->suppress_errors(true);
        $wpdb->query("DROP TABLE IF EXISTS `$cats_table`");

        // Drop the category_opt_outs column. ALTER … DROP COLUMN doesn't
        // accept IF EXISTS on MySQL 5.7; check information_schema first.
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

        // Delete every lrob_etk_nl_category_opt_outs user_meta row in
        // one DELETE. delete_metadata('user', 0, $key, '', true) deletes
        // for all users when $delete_all is true — but only when $value
        // is empty AND $object_id is 0. Use raw DELETE for clarity.
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

    /**
     * Disable-side cron cleanup. AbstractModule::disable preserves data
     * but doesn't drop cron events — override to clear them so a disabled
     * module isn't still firing its reminder tick.
     */
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

        // CPTs register unconditionally so admin code can edit existing
        // posts even when the module is disabled (data preserved). All
        // three CPTs are non-public and use `rewrite=false`, so
        // registering at any request stage is safe.
        (new TemplateCPT())->register();
        (new FormCPT())->register();
        (new NewsletterCPT())->register();

        // Newsletter lifecycle hooks (ensure companion row on save,
        // cascade delete) — unconditional so deletes from any
        // context (admin, REST, wp-cli) clean up the companion table
        // even when the module is disabled.
        (new NewsletterLifecycle($newsletters))->register();

        // Register the field types the subscribe-form CPT accepts.
        // Subset of the full form-builder vocabulary — list-picker and
        // category-picker join in step 4 when lists + categories ship.
        $registry = $this->container->get(FieldTypeRegistry::class);
        if ($registry instanceof FieldTypeRegistry) {
            $registry->register(FormCPT::POST_TYPE, new EmailField());
            $registry->register(FormCPT::POST_TYPE, new TextField());
            $registry->register(FormCPT::POST_TYPE, new PhoneField());
            $registry->register(FormCPT::POST_TYPE, new SelectField());
            $registry->register(FormCPT::POST_TYPE, new SubmitField());
            // Shared captcha field, configured for the newsletter_subscribe
            // Captcha routing context + the per-form override meta key.
            // Same class Contact Form uses (different context + meta key).
            $registry->register(
                FormCPT::POST_TYPE,
                new SharedCaptchaField(CaptchaRouting::CONTEXT_NEWSLETTER, FormCPT::META_CAPTCHA_ROUTE)
            );
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
            (new SubmitHandler($subscribers, $lists))->register();
            (new ConfirmationHandler($subscribers))->register();

            // Preferences page + one-click unsubscribe + WP profile
            // section. PrefsHandler hooks on init to catch the public
            // URLs; ProfileSection hooks on show/edit_user_profile to
            // surface the same UI inside the admin user-edit page.
            // PrefsBlock registers a Gutenberg block + shortcode that
            // embeds the same prefs UI on any public page (typically a
            // `/newsletter-preferences/` page linked from the menu).
            (new PrefsHandler($subscribers, $lists))->register();
            (new ProfileSection($lists))->register();
            (new PrefsBlock($subscribers, $lists))->register();

            // Daily reminder cron for pending subscribers — the
            // schedule itself lives on install / disable transitions.
            (new ReminderCron($subscribers))->register();

            // Daily trash auto-purge cron. Reads its retention setting
            // at tick time, so toggling the option in Settings takes
            // effect on the next cron run without a re-schedule.
            (new TrashCron($subscribers))->register();

            // Send pipeline AJAX endpoints + 1-minute safety-net
            // cron. Per-domain throttle + CSS inliner stay deferred.
            //
            // Tracking pipeline (step 9): rewriters + side-table repos
            // + the public REST endpoints that handle open/click events.
            // The Pipeline is passed into SendLoop so every outbound
            // body gets rewritten per-recipient; the RestController
            // handles inbound /track/img + /track/click requests.
            $tracking_assets = new TrackingAssetRepository();
            $tracking_links  = new TrackingLinkRepository();
            $tracking        = new TrackingPipeline($tracking_assets, $tracking_links);
            $materializer = new Materializer($newsletters, $subscribers);
            $send_loop = new SendLoop($newsletters, $tracking);
            (new SendAjaxController($materializer, $send_loop, $newsletters, $lists))->register();
            (new SendCron($newsletters, $materializer, $send_loop))->register();
            (new TrackingRestController($newsletters, $subscribers, $tracking_assets, $tracking_links))->register();

            // Daily retention cron: prune tracking_events older than
            // lrob_etk_nl_tracking_retention_days. Reads the option at
            // tick time so toggling Settings takes effect on the next run.
            (new TrackingRetentionCron())->register();
        }

        if (is_admin()) {
            add_action('admin_post_' . $this->toggle_action(), [$this, 'handle_toggle']);
            (new TemplateValidator())->register();
            $forms_page = new FormsPage($forms, $templates);
            $lists_page = new ListsPage($lists);
            $settings_page = new SettingsPage();
            $subscribers_page = new SubscribersPage($subscribers);
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

    /**
     * Persistent admin notice when Newsletter is enabled but SMTP isn't.
     * Subscribe-form ingest and admin UI work without SMTP — only newsletter
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
            esc_html__('Subscribe forms and the Newsletter admin work, but newsletter sending is disabled until you enable the SMTP module — Newsletter routes every send through an SMTP identity.', 'lrob-email-toolkit'),
            esc_url($smtp_url),
            esc_html__('Open SMTP settings', 'lrob-email-toolkit')
        );
    }

    /**
     * Seed the built-in `is_system=1` users-kind lists. Idempotent —
     * each entry is keyed by slug (UNIQUE), so re-running just upserts
     * the rule_json + name in case the seed shape changed across
     * versions. Slugs are reserved and never reused by user lists.
     */
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
