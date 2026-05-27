<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Admin\PageHeader;
use LRob\EmailToolkit\Modules\Newsletter\ListRepository;
use LRob\EmailToolkit\Modules\Newsletter\Lists\RuleRegistry;

/**
 * Lists CRUD inside the Newsletter hub. Mirror of CategoriesPage —
 * the resources have a different table + repository but the UI
 * pattern is identical (top "+ New" form, row-per-item with inline
 * rename + delete).
 *
 * Rule-based lists land later when the targeting UI matters
 * (newsletters + their target picker). For now everything created
 * through this page is a manual list (rule_json stays empty).
 */
final class ListsPage
{
    public function __construct(private ListRepository $lists)
    {
    }

    /** Subscriber-lists CRUD UI rendered inside a JS-opened modal from
     *  the Subscribers page header. The legacy standalone-page render
     *  delegates here too (kept for stale ?view=lists URLs). */
    public function render_modal(): void
    {
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-nl-lists-modal" role="dialog" aria-modal="true" aria-labelledby="lrob-etk-nl-lists-title" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--small">
                <header class="lrob-etk-modal-header">
                    <h3 id="lrob-etk-nl-lists-title" class="lrob-etk-modal-title-text"><?php esc_html_e('Manage subscriber lists', 'lrob-email-toolkit'); ?></h3>
                    <button type="button" class="lrob-etk-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="lrob-etk-modal-body">
                    <?php $this->render(null, true); ?>
                </div>
            </div>
        </div>
        <script>
        (function () {
            if (window.__lrobEtkNlListsModalBound) return;
            window.__lrobEtkNlListsModalBound = true;
            function whenReady(fn) {
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
                else fn();
            }
            whenReady(function () {
                if (window.lrobEtkModal) {
                    window.lrobEtkModal.bindHeader('lrob-etk-nl-lists-modal', 'lrob-etk-nl-lists-btn');
                }
            });
            // Delegated handler — any element with the
            // `.lrob-etk-nl-open-lists-modal` class opens the lists
            // modal. Lets multiple opener buttons coexist on the same
            // page (per-card link in NewslettersPage + FormsPage)
            // without ID collisions.
            document.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('.lrob-etk-nl-open-lists-modal');
                if (!btn) return;
                e.preventDefault();
                var modal = document.getElementById('lrob-etk-nl-lists-modal');
                if (modal) {
                    modal.hidden = false;
                    document.body.style.overflow = 'hidden';
                }
            });
        })();
        </script>
        <?php
    }

    /** Standard "Manage lists" tools button used by the Subscribers page. */
    public static function lists_tool(): array
    {
        return [
            'label' => __('Manage lists', 'lrob-email-toolkit'),
            'icon'  => 'dashicons-list-view',
            'id'    => 'lrob-etk-nl-lists-btn',
        ];
    }

    public function render(?HomePage $hub = null, bool $embedded = false): void
    {
        $rows = $this->lists->list_all();
        $counts = $this->lists->member_counts();
        $nonce = wp_create_nonce(AjaxController::NONCE_ACTION);
        $ajax_url = admin_url('admin-ajax.php');
        if (!$embedded) {
            PageHeader::render([
                'title' => sprintf(__('Newsletters — %s', 'lrob-email-toolkit'), __('Lists', 'lrob-email-toolkit')),
                'tools' => [HomePage::settings_tool()],
            ]);
            if ($hub) $hub->render_section_tabs(HomePage::VIEW_LISTS);
        }
        // Embedded mode (inside modal): no <h2> needed, modal header
        // already carries the title.
        ?>
        <?php $providers = RuleRegistry::all(); ?>
        <section class="lrob-etk-nl-resource">
            <p class="lrob-etk-nl-resource-intro">
                <?php esc_html_e('Group subscribers so you can target specific audiences when sending. A "Subscribers list" collects members who joined explicitly (via subscribe forms, contact forms, admin actions, …). A rule-based list auto-includes every WP user matching a filter — set up via the "Rule" button on each row. The two modes can stack: a list can both collect explicit members AND auto-include rule matches.', 'lrob-email-toolkit'); ?>
            </p>

            <?php $providers_for_create = RuleRegistry::all(); ?>
            <form class="lrob-etk-nl-resource-new lrob-etk-nl-resource-new--listkind" data-resource-new="list">
                <input type="text"
                       class="lrob-etk-nl-resource-new-name"
                       placeholder="<?php esc_attr_e('New list name', 'lrob-email-toolkit'); ?>"
                       required>
                <label class="lrob-etk-nl-resource-new-kind">
                    <span><?php esc_html_e('Kind', 'lrob-email-toolkit'); ?></span>
                    <select data-new-list-kind>
                        <option value="<?php echo esc_attr(ListRepository::KIND_SUBSCRIBERS); ?>">
                            <?php echo esc_html(ListRepository::kind_label(ListRepository::KIND_SUBSCRIBERS)); ?>
                        </option>
                        <?php foreach ($providers_for_create as $pslug => $provider) : ?>
                            <option value="<?php echo esc_attr(ListRepository::KIND_USERS); ?>"
                                    data-provider="<?php echo esc_attr($pslug); ?>">
                                <?php
                                /* translators: %s: rule provider label (WP users / WP role / WC subscribers / …) */
                                echo esc_html(sprintf(__('WP users — %s', 'lrob-email-toolkit'), $provider->label()));
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <?php esc_html_e('Add list', 'lrob-email-toolkit'); ?>
                </button>
                <span class="lrob-etk-nl-resource-new-status" aria-live="polite"></span>
            </form>

            <?php if ($rows === []) : ?>
                <p class="lrob-etk-nl-resource-empty">
                    <?php esc_html_e('No lists yet. Add one above to start grouping subscribers.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else :
                // "All subscribers" pseudo-kind displays under the
                // Subscribers section — admins think of it as the
                // catch-all subscribers list, not a separate species.
                $grouped = [
                    ListRepository::KIND_SUBSCRIBERS => [],
                    ListRepository::KIND_USERS       => [],
                ];
                foreach ($rows as $row) {
                    $k = ListRepository::kind_of($row);
                    if ($k === ListRepository::KIND_ALL_SUBSCRIBERS) {
                        $k = ListRepository::KIND_SUBSCRIBERS;
                    }
                    if (!isset($grouped[$k])) {
                        $grouped[$k] = [];
                    }
                    $grouped[$k][] = $row;
                }
                $section_headers = [
                    ListRepository::KIND_SUBSCRIBERS => __('Subscribers lists', 'lrob-email-toolkit'),
                    ListRepository::KIND_USERS       => __('WP users lists', 'lrob-email-toolkit'),
                ];
                ?>
                <?php foreach ($grouped as $kind_key => $group_rows) :
                    if ($group_rows === []) continue;
                    ?>
                    <section class="lrob-etk-nl-resource-section is-kind-<?php echo esc_attr($kind_key); ?>">
                        <h3 class="lrob-etk-nl-resource-section-title"><?php echo esc_html($section_headers[$kind_key]); ?></h3>
                        <ul class="lrob-etk-nl-resource-list" data-resource-list-kind="<?php echo esc_attr($kind_key); ?>">
                            <?php foreach ($group_rows as $row) : self::render_row($row, (int) ($counts[(int) ($row['id'] ?? 0)] ?? 0)); endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php
        $this->render_inline_script();
    }

    /**
     * Render a single `<li>` for a list row. Public-static so the AJAX
     * create handler can call it and return the markup to the client —
     * lets the lists modal stay open after an insert. Captures the
     * provider registry on each call (cheap; the registry caches).
     *
     * @param array<string, mixed> $row
     */
    public static function render_row(array $row, int $member_count = 0): void
    {
        $providers = RuleRegistry::all();
        $id = (int) ($row['id'] ?? 0);
        $slug = (string) ($row['slug'] ?? '');
        $name = (string) ($row['name'] ?? '');
        $kind = ListRepository::kind_of($row);
        $is_users_kind = ($kind === ListRepository::KIND_USERS);
        $is_system = ListRepository::is_system($row);
        $rule = ListRepository::decode_rule((string) ($row['rule_json'] ?? ''));
        $rule_provider_slug = $rule['provider'] ?? '';
        $rule_config = $rule['config'] ?? [];
        $visibility = ListRepository::visibility_of($row);
        $is_public = ($visibility === ListRepository::VISIBILITY_PUBLIC);
        // Visibility chip only meaningful on user-editable subscribers
        // lists (system + users-kind don't accept self-toggle from
        // subscribers anyway).
        $show_visibility = !$is_system && $kind === ListRepository::KIND_SUBSCRIBERS;
        ?>
        <li class="lrob-etk-nl-resource-row<?php echo $rule_provider_slug !== '' ? ' has-rule' : ''; ?> is-kind-<?php echo esc_attr($kind); ?><?php echo $is_system ? ' is-system' : ''; ?>"
            data-resource-row data-resource-id="<?php echo $id; ?>" data-list-kind="<?php echo esc_attr($kind); ?>"
            data-list-visibility="<?php echo esc_attr($visibility); ?>">
                            <div class="lrob-etk-nl-resource-row-main">
                                <input type="text"
                                       class="lrob-etk-nl-resource-name lrob-etk-nl-field"
                                       data-key="rename-list"
                                       data-resource-id="<?php echo $id; ?>"
                                       value="<?php echo esc_attr($name); ?>"
                                       <?php echo $is_system ? 'readonly ' : ''; ?>
                                       autocomplete="off">
                                <?php if ($is_users_kind && $rule_provider_slug !== '' && isset($providers[$rule_provider_slug])) : ?>
                                    <span class="lrob-etk-nl-list-provider-badge"
                                          title="<?php echo esc_attr($providers[$rule_provider_slug]->label()); ?>">
                                        <?php echo esc_html($providers[$rule_provider_slug]->label()); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($is_system) : ?>
                                    <span class="lrob-etk-nl-list-system-badge"
                                          title="<?php esc_attr_e('Built-in list — cannot be renamed or deleted.', 'lrob-email-toolkit'); ?>">
                                        <?php esc_html_e('System', 'lrob-email-toolkit'); ?>
                                    </span>
                                <?php endif; ?>
                                <?php
                                // Member-count badge. For all_subscribers + subscribers
                                // lists the count is exact (membership rows). For users
                                // lists it's the manual-membership count only —
                                // rule-matched users aren't materialised until send time
                                // so a "live" rule-resolved count would need a per-row
                                // Materializer dry-run (too expensive for an index view).
                                if (!$is_users_kind || $member_count > 0) :
                                    ?>
                                    <span class="lrob-etk-nl-list-count-badge"
                                          title="<?php echo $is_users_kind
                                              ? esc_attr__('Manually-added members. Rule matches resolve at send time and aren\'t counted here.', 'lrob-email-toolkit')
                                              : esc_attr__('Subscribers on this list.', 'lrob-email-toolkit'); ?>">
                                        <?php echo esc_html(sprintf(
                                            /* translators: %s: number of members on this list (already formatted) */
                                            _n('%s member', '%s members', $member_count, 'lrob-email-toolkit'),
                                            number_format_i18n($member_count)
                                        )); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($show_visibility) : ?>
                                    <button type="button"
                                            class="lrob-etk-nl-list-visibility-chip<?php echo $is_public ? ' is-public' : ' is-private'; ?>"
                                            data-list-visibility-toggle="<?php echo $id; ?>"
                                            data-current="<?php echo esc_attr($visibility); ?>"
                                            title="<?php echo $is_public
                                                ? esc_attr__('Public — subscribers can join or leave this list themselves from the preferences page. Click to make private.', 'lrob-email-toolkit')
                                                : esc_attr__('Private — admin-managed. Subscribers don\'t see this list on the preferences page. Click to make public.', 'lrob-email-toolkit');
                                            ?>">
                                        <span class="dashicons dashicons-<?php echo $is_public ? 'visibility' : 'hidden'; ?>" aria-hidden="true"></span>
                                        <?php echo $is_public
                                            ? esc_html__('Public', 'lrob-email-toolkit')
                                            : esc_html__('Private', 'lrob-email-toolkit'); ?>
                                    </button>
                                <?php endif; ?>
                                <span class="lrob-etk-nl-resource-row-actions">
                                    <?php if ($is_users_kind && !$is_system) : ?>
                                        <button type="button"
                                                class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost"
                                                data-rule-toggle="<?php echo $id; ?>"
                                                aria-expanded="false"
                                                title="<?php esc_attr_e('Set rules', 'lrob-email-toolkit'); ?>"
                                                aria-label="<?php esc_attr_e('Set rules', 'lrob-email-toolkit'); ?>">
                                            <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($is_users_kind && $is_system) : ?>
                                        <button type="button"
                                                class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost"
                                                data-rule-exclusions-toggle="<?php echo $id; ?>"
                                                aria-expanded="false"
                                                title="<?php esc_attr_e('Manage exclusions', 'lrob-email-toolkit'); ?>"
                                                aria-label="<?php esc_attr_e('Manage exclusions', 'lrob-email-toolkit'); ?>">
                                            <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (!$is_system) : ?>
                                        <button type="button"
                                                class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-icon-btn--danger"
                                                data-resource-delete="list"
                                                data-resource-id="<?php echo $id; ?>"
                                                data-resource-name="<?php echo esc_attr($name); ?>"
                                                title="<?php esc_attr_e('Delete list', 'lrob-email-toolkit'); ?>"
                                                aria-label="<?php esc_attr_e('Delete list', 'lrob-email-toolkit'); ?>">
                                            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                        </button>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if ($is_users_kind) : ?>
                            <div class="lrob-etk-nl-list-rule-editor"
                                 data-rule-editor="<?php echo $id; ?>"
                                 data-rule-locked-provider="<?php echo esc_attr($rule_provider_slug); ?>"
                                 hidden>
                                <?php
                                $active_provider = isset($providers[$rule_provider_slug]) ? $providers[$rule_provider_slug] : null;
                                if ($active_provider !== null) :
                                    $pslug = $rule_provider_slug;
                                    $provider = $active_provider;
                                    $is_active = true;
                                    ?>
                                    <div class="lrob-etk-nl-list-rule-config"
                                         data-rule-config-for="<?php echo esc_attr($pslug); ?>">
                                        <p class="description"><?php echo esc_html($provider->description()); ?></p>
                                        <?php foreach ($provider->config_fields() as $field) :
                                            $field_name = (string) ($field['name'] ?? '');
                                            $field_label = (string) ($field['label'] ?? $field_name);
                                            $field_type = (string) ($field['type'] ?? 'text');
                                            $field_options = (array) ($field['options'] ?? []);
                                            $field_default = $field['default'] ?? '';
                                            $field_value = $is_active && array_key_exists($field_name, $rule_config)
                                                ? $rule_config[$field_name]
                                                : $field_default;
                                            ?>
                                            <div class="lrob-etk-nl-list-rule-field">
                                                <?php if ($field_type === 'wc_product_search') : ?>
                                                    <?php
                                                    $current_ids = is_array($field_value) ? array_values(array_filter(array_map('intval', $field_value))) : [];
                                                    ?>
                                                    <label>
                                                        <span><?php echo esc_html($field_label); ?></span>
                                                        <div class="lrob-etk-nl-wc-product-picker"
                                                             data-wc-picker
                                                             data-field-name="<?php echo esc_attr($field_name); ?>"
                                                             data-initial-ids="<?php echo esc_attr(implode(',', $current_ids)); ?>">
                                                            <ul class="lrob-etk-nl-wc-product-chips" data-wc-chips></ul>
                                                            <input type="search"
                                                                   class="lrob-etk-nl-wc-product-search"
                                                                   data-wc-search
                                                                   placeholder="<?php esc_attr_e('Search a product by name or SKU…', 'lrob-email-toolkit'); ?>"
                                                                   autocomplete="off">
                                                            <ul class="lrob-etk-nl-wc-product-results" data-wc-results hidden></ul>
                                                        </div>
                                                    </label>
                                                <?php elseif ($field_type === 'multiselect') : ?>
                                                    <?php
                                                    $current_values = is_array($field_value) ? array_map('strval', $field_value) : [];
                                                    $known_values = array_map('strval', array_keys($field_options));
                                                    $stale_values = array_diff($current_values, $known_values);
                                                    ?>
                                                    <fieldset class="lrob-etk-nl-list-rule-tickboxes">
                                                        <legend><?php echo esc_html($field_label); ?></legend>
                                                        <?php foreach ($field_options as $ovalue => $olabel) :
                                                            $ovalue_s = (string) $ovalue;
                                                            $checked = in_array($ovalue_s, $current_values, true);
                                                            ?>
                                                            <label class="lrob-etk-nl-list-rule-tickbox">
                                                                <input type="checkbox"
                                                                       data-rule-field="<?php echo esc_attr($field_name); ?>"
                                                                       value="<?php echo esc_attr($ovalue_s); ?>"
                                                                       <?php checked($checked); ?>>
                                                                <span><?php echo esc_html((string) $olabel); ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                        <?php foreach ($stale_values as $stale_value) : ?>
                                                            <label class="lrob-etk-nl-list-rule-tickbox is-stale"
                                                                   title="<?php esc_attr_e('This option is no longer available on this site — uncheck or save to drop it.', 'lrob-email-toolkit'); ?>">
                                                                <input type="checkbox"
                                                                       data-rule-field="<?php echo esc_attr($field_name); ?>"
                                                                       value="<?php echo esc_attr($stale_value); ?>"
                                                                       checked>
                                                                <span>
                                                                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                                                    <?php echo esc_html($stale_value); ?>
                                                                    <em><?php esc_html_e('(missing)', 'lrob-email-toolkit'); ?></em>
                                                                </span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </fieldset>
                                                <?php else : ?>
                                                <label>
                                                    <span><?php echo esc_html($field_label); ?></span>
                                                    <?php if ($field_type === 'select') : ?>
                                                        <select data-rule-field="<?php echo esc_attr($field_name); ?>">
                                                            <?php foreach ($field_options as $ovalue => $olabel) : ?>
                                                                <option value="<?php echo esc_attr((string) $ovalue); ?>" <?php selected((string) $ovalue, (string) $field_value); ?>>
                                                                    <?php echo esc_html((string) $olabel); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php elseif ($field_type === 'checkbox') : ?>
                                                        <input type="checkbox" data-rule-field="<?php echo esc_attr($field_name); ?>" <?php checked((bool) $field_value); ?>>
                                                    <?php else : ?>
                                                        <input type="text" data-rule-field="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr((string) $field_value); ?>">
                                                    <?php endif; ?>
                                                </label>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="lrob-etk-nl-list-rule-actions">
                                    <button type="button" class="button" data-rule-preview="<?php echo $id; ?>">
                                        <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                        <?php esc_html_e('Preview matches', 'lrob-email-toolkit'); ?>
                                    </button>
                                    <button type="button"
                                            class="button"
                                            data-rule-exclusions-toggle="<?php echo $id; ?>"
                                            aria-expanded="false">
                                        <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                                        <?php esc_html_e('Manage exclusions', 'lrob-email-toolkit'); ?>
                                    </button>
                                </div>
                                <div class="lrob-etk-nl-list-rule-preview" data-rule-preview-pane="<?php echo $id; ?>" hidden></div>
                            </div>
                            <div class="lrob-etk-nl-list-exclusions" data-exclusions-pane="<?php echo $id; ?>" hidden>
                                <header class="lrob-etk-nl-list-exclusions-head">
                                    <span class="lrob-etk-nl-list-exclusions-title"><?php esc_html_e('Excluded users', 'lrob-email-toolkit'); ?></span>
                                    <span class="description"><?php esc_html_e('These WP users are skipped even when the rule matches them. Type to search by name or email.', 'lrob-email-toolkit'); ?></span>
                                </header>
                                <div class="lrob-etk-nl-list-exclusions-picker" data-exclusion-picker="<?php echo $id; ?>">
                                    <input type="search"
                                           class="lrob-etk-nl-list-exclusions-search"
                                           data-exclusion-search
                                           placeholder="<?php esc_attr_e('Search a WP user…', 'lrob-email-toolkit'); ?>"
                                           autocomplete="off">
                                    <ul class="lrob-etk-nl-list-exclusions-results" data-exclusion-results hidden></ul>
                                </div>
                                <ul class="lrob-etk-nl-list-exclusions-list" data-exclusion-list="<?php echo $id; ?>"></ul>
                                <div class="lrob-etk-nl-list-exclusions-status" data-exclusion-status></div>
                            </div>
                            <?php endif; ?>
                        </li>
        <?php
    }

    /**
     * Inline script bound to the list-modal — wired by render(); kept
     * out of render_row so AJAX-inserted rows don't ship a duplicate
     * script tag on every insert.
     */
    private function render_inline_script(): void
    {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce(AjaxController::NONCE_ACTION);
        ?>
        <script>
        (function () {
            if (window.__lrobEtkNlListsResourceBound) return;
            window.__lrobEtkNlListsResourceBound = true;
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var actionCreate = <?php echo wp_json_encode(AjaxController::ACTION_LIST_CREATE); ?>;
            var actionDelete = <?php echo wp_json_encode(AjaxController::ACTION_LIST_DELETE); ?>;
            var actionRuleSave = <?php echo wp_json_encode(AjaxController::ACTION_LIST_RULE_SAVE); ?>;
            var actionRulePreview = <?php echo wp_json_encode(AjaxController::ACTION_LIST_RULE_PREVIEW); ?>;
            var actionVisibilitySet = <?php echo wp_json_encode(AjaxController::ACTION_LIST_VISIBILITY_SET); ?>;
            var i18nLabelPublic  = <?php echo wp_json_encode(__('Public', 'lrob-email-toolkit')); ?>;
            var i18nLabelPrivate = <?php echo wp_json_encode(__('Private', 'lrob-email-toolkit')); ?>;
            var i18nTipPublic    = <?php echo wp_json_encode(__('Public — subscribers can join or leave this list themselves from the preferences page. Click to make private.', 'lrob-email-toolkit')); ?>;
            var i18nTipPrivate   = <?php echo wp_json_encode(__('Private — admin-managed. Subscribers don\'t see this list on the preferences page. Click to make public.', 'lrob-email-toolkit')); ?>;

            document.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('[data-list-visibility-toggle]');
                if (!btn) return;
                e.preventDefault();
                var current = btn.getAttribute('data-current') || 'private';
                var next = current === 'public' ? 'private' : 'public';
                var id = btn.getAttribute('data-list-visibility-toggle');
                btn.disabled = true;
                var fd = new FormData();
                fd.append('action', actionVisibilitySet);
                fd.append('_nonce', nonce);
                fd.append('id', id);
                fd.append('visibility', next);
                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                    .then(function (resp) {
                        btn.disabled = false;
                        if (!resp || !resp.success) return;
                        var isPub = (next === 'public');
                        btn.setAttribute('data-current', next);
                        btn.classList.toggle('is-public', isPub);
                        btn.classList.toggle('is-private', !isPub);
                        btn.setAttribute('title', isPub ? i18nTipPublic : i18nTipPrivate);
                        var icon = btn.querySelector('.dashicons');
                        if (icon) {
                            icon.classList.remove('dashicons-visibility', 'dashicons-hidden');
                            icon.classList.add(isPub ? 'dashicons-visibility' : 'dashicons-hidden');
                        }
                        var labelNode = btn.childNodes[btn.childNodes.length - 1];
                        if (labelNode && labelNode.nodeType === 3) {
                            labelNode.nodeValue = ' ' + (isPub ? i18nLabelPublic : i18nLabelPrivate);
                        } else {
                            btn.appendChild(document.createTextNode(' ' + (isPub ? i18nLabelPublic : i18nLabelPrivate)));
                        }
                        var row = btn.closest('[data-list-visibility]');
                        if (row) row.setAttribute('data-list-visibility', next);
                    });
            });
            var actionWcProductSearch = <?php echo wp_json_encode(AjaxController::ACTION_WC_PRODUCT_SEARCH); ?>;
            var i18nWcRemove = <?php echo wp_json_encode(__('Remove', 'lrob-email-toolkit')); ?>;
            var i18nWcEmpty  = <?php echo wp_json_encode(__('No matches.', 'lrob-email-toolkit')); ?>;
            var i18nWcSearching = <?php echo wp_json_encode(__('Searching…', 'lrob-email-toolkit')); ?>;
            var i18nPreviewLoading = <?php echo wp_json_encode(__('Resolving…', 'lrob-email-toolkit')); ?>;
            var i18nPreviewEmpty   = <?php echo wp_json_encode(__('No users match this rule.', 'lrob-email-toolkit')); ?>;
            var i18nPreviewTotal   = <?php
                /* translators: %d: total number of users matched by the rule */
                echo wp_json_encode(__('%d user(s) match — showing', 'lrob-email-toolkit'));
            ?>;
            var i18nLoadMore       = <?php echo wp_json_encode(__('Load more', 'lrob-email-toolkit')); ?>;
            var i18nShowAll        = <?php echo wp_json_encode(__('Show all', 'lrob-email-toolkit')); ?>;

            function escHtml(s) {
                return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; });
            }

            function appendRuleConfigToFormData(fd, editor, providerSlug) {
                if (providerSlug === '') return;
                var block = editor.querySelector('[data-rule-config-for="' + providerSlug + '"]');
                if (!block) return;
                // WC-product pickers serialise their selected chip IDs.
                block.querySelectorAll('[data-wc-picker]').forEach(function (picker) {
                    var fname = picker.getAttribute('data-field-name');
                    picker.querySelectorAll('[data-wc-chip-id]').forEach(function (chip) {
                        fd.append('config[' + fname + '][]', chip.getAttribute('data-wc-chip-id'));
                    });
                });
                block.querySelectorAll('[data-rule-field]').forEach(function (fld) {
                    var fname = fld.getAttribute('data-rule-field');
                    if (fld.tagName === 'SELECT' && fld.multiple) {
                        Array.prototype.forEach.call(fld.selectedOptions, function (opt) {
                            fd.append('config[' + fname + '][]', opt.value);
                        });
                    } else if (fld.type === 'checkbox') {
                        if (fld.value && fld.value !== 'on') {
                            if (fld.checked) fd.append('config[' + fname + '][]', fld.value);
                        } else {
                            fd.append('config[' + fname + ']', fld.checked ? '1' : '');
                        }
                    } else {
                        fd.append('config[' + fname + ']', fld.value);
                    }
                });
            }

            // WC product picker — boot on first sight of each picker
            // element, populate chips from the initial IDs, wire search.
            function bootWcPicker(picker) {
                if (picker.__etkBooted) return;
                picker.__etkBooted = true;
                var chipsEl = picker.querySelector('[data-wc-chips]');
                var searchEl = picker.querySelector('[data-wc-search]');
                var resultsEl = picker.querySelector('[data-wc-results]');
                var initial = (picker.getAttribute('data-initial-ids') || '').split(',').map(function (n) { return parseInt(n, 10); }).filter(function (n) { return n > 0; });

                function renderChip(item) {
                    if (chipsEl.querySelector('[data-wc-chip-id="' + item.id + '"]')) return;
                    var li = document.createElement('li');
                    li.className = 'lrob-etk-nl-wc-product-chip';
                    li.setAttribute('data-wc-chip-id', String(item.id));
                    li.innerHTML = '<span class="lrob-etk-nl-wc-product-chip-name">' + escHtml(item.name) + '</span>'
                        + (item.sku ? '<code>' + escHtml(item.sku) + '</code>' : '')
                        + '<button type="button" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-icon-btn--danger" data-wc-chip-remove aria-label="' + escHtml(i18nWcRemove) + '" title="' + escHtml(i18nWcRemove) + '">'
                        +   '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>'
                        + '</button>';
                    chipsEl.appendChild(li);
                }

                if (initial.length) {
                    var fdInit = new FormData();
                    fdInit.append('action', actionWcProductSearch);
                    fdInit.append('_nonce', nonce);
                    initial.forEach(function (id) { fdInit.append('ids[]', String(id)); });
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fdInit })
                        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                        .then(function (resp) {
                            if (resp && resp.success && resp.data && Array.isArray(resp.data.items)) {
                                resp.data.items.forEach(renderChip);
                            }
                        });
                }

                var searchTimer = null;
                function runSearch(q) {
                    if (searchTimer) clearTimeout(searchTimer);
                    searchTimer = setTimeout(function () {
                        var fd = new FormData();
                        fd.append('action', actionWcProductSearch);
                        fd.append('_nonce', nonce);
                        fd.append('q', q);
                        resultsEl.hidden = false;
                        resultsEl.innerHTML = '<li class="lrob-etk-nl-wc-product-empty">' + escHtml(i18nWcSearching) + '</li>';
                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                            .then(function (resp) {
                                resultsEl.innerHTML = '';
                                var items = (resp && resp.success && resp.data && Array.isArray(resp.data.items)) ? resp.data.items : [];
                                if (!items.length) {
                                    resultsEl.innerHTML = '<li class="lrob-etk-nl-wc-product-empty">' + escHtml(i18nWcEmpty) + '</li>';
                                    return;
                                }
                                items.forEach(function (item) {
                                    var li = document.createElement('li');
                                    li.className = 'lrob-etk-nl-wc-product-result';
                                    li.setAttribute('data-wc-result-id', String(item.id));
                                    li.innerHTML = '<span class="lrob-etk-nl-wc-product-result-name">' + escHtml(item.name) + '</span>'
                                        + (item.sku ? '<code>' + escHtml(item.sku) + '</code>' : '');
                                    resultsEl.appendChild(li);
                                });
                            });
                    }, 250);
                }

                searchEl.addEventListener('input', function () { runSearch(searchEl.value); });
                searchEl.addEventListener('focus', function () { if (resultsEl.children.length) resultsEl.hidden = false; });
                document.addEventListener('click', function (e) {
                    if (!picker.contains(e.target)) {
                        resultsEl.hidden = true;
                    }
                });

                resultsEl.addEventListener('click', function (e) {
                    var li = e.target.closest && e.target.closest('[data-wc-result-id]');
                    if (!li) return;
                    var name = li.querySelector('.lrob-etk-nl-wc-product-result-name');
                    var sku  = li.querySelector('code');
                    renderChip({
                        id: parseInt(li.getAttribute('data-wc-result-id'), 10) || 0,
                        name: name ? name.textContent : '',
                        sku: sku ? sku.textContent : '',
                    });
                    searchEl.value = '';
                    resultsEl.hidden = true;
                    // Trigger rule-preview refresh.
                    var editor = picker.closest('[data-rule-editor]');
                    if (editor) maybeRefreshPreview(editor);
                });

                chipsEl.addEventListener('click', function (e) {
                    var rem = e.target.closest && e.target.closest('[data-wc-chip-remove]');
                    if (!rem) return;
                    var chip = rem.closest('[data-wc-chip-id]');
                    if (!chip) return;
                    chip.parentNode.removeChild(chip);
                    var editor = picker.closest('[data-rule-editor]');
                    if (editor) maybeRefreshPreview(editor);
                });
            }

            // Boot any picker present at page load + observe rule-editor
            // opens to boot pickers inside.
            function bootAllWcPickers(scope) {
                (scope || document).querySelectorAll('[data-wc-picker]').forEach(bootWcPicker);
            }
            bootAllWcPickers();

            // WP-user search — drives the exclusions picker. Single
            // shared handler since there's at most one open exclusions
            // pane per rule editor.
            var excSearchTimer = null;
            document.addEventListener('input', function (e) {
                var input = e.target.closest && e.target.closest('[data-exclusion-search]');
                if (!input) return;
                var picker = input.closest('[data-exclusion-picker]');
                if (!picker) return;
                var resultsEl = picker.querySelector('[data-exclusion-results]');
                if (!resultsEl) return;
                var q = input.value.trim();
                if (q === '') {
                    resultsEl.hidden = true;
                    resultsEl.innerHTML = '';
                    return;
                }
                if (excSearchTimer) clearTimeout(excSearchTimer);
                excSearchTimer = setTimeout(function () {
                    resultsEl.hidden = false;
                    resultsEl.innerHTML = '<li class="lrob-etk-nl-wc-product-empty">' + escHtml(i18nExcSearching) + '</li>';
                    var fd = new FormData();
                    fd.append('action', actionWpUserSearch);
                    fd.append('_nonce', nonce);
                    fd.append('q', q);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                        .then(function (resp) {
                            resultsEl.innerHTML = '';
                            var items = (resp && resp.success && resp.data && Array.isArray(resp.data.items)) ? resp.data.items : [];
                            if (!items.length) {
                                resultsEl.innerHTML = '<li class="lrob-etk-nl-wc-product-empty">' + escHtml(i18nExcNoMatch) + '</li>';
                                return;
                            }
                            items.forEach(function (u) {
                                var li = document.createElement('li');
                                li.className = 'lrob-etk-nl-wc-product-result';
                                li.setAttribute('data-exclusion-result-id', String(u.id));
                                li.innerHTML = '<span class="lrob-etk-nl-wc-product-result-name">' + escHtml(u.name || '') + '</span>'
                                    + ' <code>' + escHtml(u.email || '') + '</code>';
                                resultsEl.appendChild(li);
                            });
                        });
                }, 250);
            });
            document.addEventListener('click', function (e) {
                // Close exclusion results when clicking outside.
                document.querySelectorAll('[data-exclusion-picker]').forEach(function (picker) {
                    if (!picker.contains(e.target)) {
                        var r = picker.querySelector('[data-exclusion-results]');
                        if (r) r.hidden = true;
                    }
                });
            });

            // Run the preview AJAX for an editor. When append=true, the
            // results are appended to the existing list (Load more path);
            // when append=false, the pane is reset (fresh "Preview matches"
            // click). limit controls the page size (default 20 on the
            // first page, configurable up to 200).
            function runRulePreview(listId, append, pageLimit) {
                var editor = document.querySelector('[data-rule-editor="' + listId + '"]');
                if (!editor) return;
                var pane = editor.querySelector('[data-rule-preview-pane="' + listId + '"]');
                // Provider is locked at creation — read it off the editor's
                // data attr rather than a (now removed) select element.
                var providerSlug = editor.getAttribute('data-rule-locked-provider') || '';
                if (providerSlug === '') {
                    if (pane) { pane.hidden = true; pane.innerHTML = ''; }
                    return;
                }
                if (!pane) return;

                var existingList = pane.querySelector('.lrob-etk-nl-list-rule-preview-list');
                var offset = 0;
                if (append && existingList) {
                    offset = existingList.children.length;
                } else {
                    pane.hidden = false;
                    pane.textContent = i18nPreviewLoading;
                }
                var limit = pageLimit || 20;

                var fdp = new FormData();
                fdp.append('action', actionRulePreview);
                fdp.append('_nonce', nonce);
                fdp.append('provider', providerSlug);
                fdp.append('limit', String(limit));
                fdp.append('offset', String(offset));
                appendRuleConfigToFormData(fdp, editor, providerSlug);

                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fdp })
                    .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                    .then(function (resp) {
                        if (!resp || !resp.success || !resp.data) {
                            pane.innerHTML = '<span class="lrob-etk-nl-list-rule-status is-failure">✗ ' + ((resp && resp.data && resp.data.message) || i18nRuleError) + '</span>';
                            return;
                        }
                        var total = resp.data.total || 0;
                        var sample = resp.data.sample || [];
                        var hasMore = !!resp.data.hasMore;
                        if (!append && total === 0) {
                            pane.innerHTML = '<div class="lrob-etk-nl-list-rule-preview-head">' + escHtml(i18nPreviewEmpty) + '</div>';
                            return;
                        }
                        var listEl;
                        if (append && existingList) {
                            listEl = existingList;
                        } else {
                            pane.innerHTML = '<div class="lrob-etk-nl-list-rule-preview-head"></div>'
                                + '<ul class="lrob-etk-nl-list-rule-preview-list"></ul>'
                                + '<div class="lrob-etk-nl-list-rule-preview-footer"></div>';
                            listEl = pane.querySelector('.lrob-etk-nl-list-rule-preview-list');
                        }
                        sample.forEach(function (u) {
                            var li = document.createElement('li');
                            li.innerHTML = '<span class="lrob-etk-nl-list-rule-preview-name">' + escHtml(u.name || '') + '</span>'
                                + ' <span class="lrob-etk-nl-list-rule-preview-email">&lt;' + escHtml(u.email || '') + '&gt;</span>';
                            listEl.appendChild(li);
                        });
                        var shown = listEl.children.length;
                        var head = pane.querySelector('.lrob-etk-nl-list-rule-preview-head');
                        if (head) {
                            head.textContent = i18nPreviewTotal.replace('%d', total) + ' ' + shown + '.';
                        }
                        var footer = pane.querySelector('.lrob-etk-nl-list-rule-preview-footer');
                        if (footer) {
                            footer.innerHTML = '';
                            if (hasMore) {
                                var remaining = total - shown;
                                var step = Math.min(50, remaining);
                                var btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'button';
                                btn.setAttribute('data-rule-preview-load-more', listId);
                                btn.setAttribute('data-step', String(step));
                                btn.textContent = i18nLoadMore + ' (' + remaining + ')';
                                footer.appendChild(btn);
                                if (remaining > step) {
                                    var allBtn = document.createElement('button');
                                    allBtn.type = 'button';
                                    allBtn.className = 'button';
                                    allBtn.setAttribute('data-rule-preview-load-more', listId);
                                    allBtn.setAttribute('data-step', String(remaining));
                                    allBtn.textContent = i18nShowAll;
                                    footer.appendChild(allBtn);
                                }
                            }
                        }
                    });
            }
            var i18nRuleError   = <?php echo wp_json_encode(__('Could not save the rule.', 'lrob-email-toolkit')); ?>;
            var i18nConfirm = <?php
                /* translators: %s: list name */
                echo wp_json_encode(__('Delete the "%s" list? Subscribers stay on the system but lose their membership.', 'lrob-email-toolkit'));
            ?>;
            var i18nYes    = <?php echo wp_json_encode(__('Delete', 'lrob-email-toolkit')); ?>;
            var i18nCancel = <?php echo wp_json_encode(__('Cancel', 'lrob-email-toolkit')); ?>;
            var i18nAdded  = <?php echo wp_json_encode(__('Added', 'lrob-email-toolkit')); ?>;
            var i18nDeleteFailed = <?php echo wp_json_encode(__('Could not delete', 'lrob-email-toolkit')); ?>;

            function escAttr(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
            function escHtml(s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }

            function rowHtml(id, name) {
                return ''
                    + '<input type="text" class="lrob-etk-nl-resource-name lrob-etk-nl-field"'
                    +   ' data-key="rename-list" data-resource-id="' + id + '"'
                    +   ' value="' + escAttr(name) + '" autocomplete="off">'
                    + '<button type="button" class="lrob-etk-card-delete-link"'
                    +   ' data-resource-delete="list" data-resource-id="' + id + '"'
                    +   ' data-resource-name="' + escAttr(name) + '">' + escHtml(i18nYes) + '</button>';
            }

            function ensureList(scope, kind) {
                kind = kind || 'subscribers';
                // Prefer the section that matches this kind.
                var list = scope.querySelector('[data-resource-list-kind="' + kind + '"]');
                if (list) return list;
                // No section for this kind yet — drop empty-state if any
                // and build a fresh `<section><h3><ul>` block.
                var empty = scope.querySelector('.lrob-etk-nl-resource-empty');
                if (empty) empty.parentNode.removeChild(empty);
                var section = document.createElement('section');
                section.className = 'lrob-etk-nl-resource-section is-kind-' + kind;
                var heading = document.createElement('h3');
                heading.className = 'lrob-etk-nl-resource-section-title';
                heading.textContent = kind === 'users'
                    ? <?php echo wp_json_encode(__('WP users lists', 'lrob-email-toolkit')); ?>
                    : <?php echo wp_json_encode(__('Subscribers lists', 'lrob-email-toolkit')); ?>;
                var ul = document.createElement('ul');
                ul.className = 'lrob-etk-nl-resource-list';
                ul.setAttribute('data-resource-list-kind', kind);
                section.appendChild(heading);
                section.appendChild(ul);
                scope.appendChild(section);
                return ul;
            }

            var newForm = document.querySelector('[data-resource-new="list"]');
            if (newForm) {
                newForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var nameInput = newForm.querySelector('.lrob-etk-nl-resource-new-name');
                    var kindInput = newForm.querySelector('[data-new-list-kind]');
                    var status = newForm.querySelector('.lrob-etk-nl-resource-new-status');
                    var name = (nameInput.value || '').trim();
                    var kind = kindInput ? kindInput.value : 'subscribers';
                    // The kind dropdown carries the provider on each
                    // users-kind option via data-provider — the create
                    // endpoint locks it in on the new row.
                    var selectedOption = kindInput ? kindInput.options[kindInput.selectedIndex] : null;
                    var provider = selectedOption ? (selectedOption.getAttribute('data-provider') || '') : '';
                    if (!name) return;
                    var fd = new FormData();
                    fd.append('action', actionCreate);
                    fd.append('_nonce', nonce);
                    fd.append('name', name);
                    fd.append('kind', kind);
                    if (provider) fd.append('provider', provider);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                        .then(function (resp) {
                            if (resp && resp.success && resp.data && resp.data.id) {
                                var scope = newForm.closest('.lrob-etk-nl-resource') || document;
                                var list = ensureList(scope, resp.data.kind || kind);
                                if (resp.data.html) {
                                    // Server-rendered <li> — keeps rule editor +
                                    // exclusions UI for users-kind lists intact
                                    // without reloading the modal.
                                    var wrap = document.createElement('div');
                                    wrap.innerHTML = String(resp.data.html).trim();
                                    var newRow = wrap.firstElementChild;
                                    if (newRow) list.appendChild(newRow);
                                } else {
                                    // Fallback when server didn't return HTML.
                                    var li = document.createElement('li');
                                    li.className = 'lrob-etk-nl-resource-row is-kind-subscribers';
                                    li.setAttribute('data-resource-row', '');
                                    li.setAttribute('data-resource-id', String(resp.data.id));
                                    li.setAttribute('data-list-kind', 'subscribers');
                                    li.innerHTML = rowHtml(resp.data.id, resp.data.name || name);
                                    list.appendChild(li);
                                }
                                nameInput.value = '';
                                nameInput.focus();
                                status.textContent = '✓ ' + i18nAdded;
                                status.className = 'lrob-etk-nl-resource-new-status is-success';
                            } else {
                                status.textContent = (resp && resp.data && resp.data.message) || 'Error';
                                status.className = 'lrob-etk-nl-resource-new-status is-error';
                            }
                        });
                });
            }

            var actionExcAdd     = <?php echo wp_json_encode(AjaxController::ACTION_LIST_EXCLUSION_ADD); ?>;
            var actionExcRemove  = <?php echo wp_json_encode(AjaxController::ACTION_LIST_EXCLUSION_REMOVE); ?>;
            var actionExcList    = <?php echo wp_json_encode(AjaxController::ACTION_LIST_EXCLUSIONS_LIST); ?>;
            var actionWpUserSearch = <?php echo wp_json_encode(AjaxController::ACTION_WP_USER_SEARCH); ?>;
            var i18nExcEmpty     = <?php echo wp_json_encode(__('No exclusions yet.', 'lrob-email-toolkit')); ?>;
            var i18nExcRemove    = <?php echo wp_json_encode(__('Remove from exclusions', 'lrob-email-toolkit')); ?>;
            var i18nExcSearching = <?php echo wp_json_encode(__('Searching…', 'lrob-email-toolkit')); ?>;
            var i18nExcNoMatch   = <?php echo wp_json_encode(__('No users match.', 'lrob-email-toolkit')); ?>;

            function renderExclusionList(ul, items) {
                ul.innerHTML = '';
                if (!items.length) {
                    var li = document.createElement('li');
                    li.className = 'lrob-etk-nl-list-exclusions-empty';
                    li.textContent = i18nExcEmpty;
                    ul.appendChild(li);
                    return;
                }
                items.forEach(function (u) {
                    var li = document.createElement('li');
                    li.setAttribute('data-exclusion-user-id', String(u.id));
                    li.innerHTML = '<span class="lrob-etk-nl-list-exclusions-name">' + escHtml(u.name || '') + '</span>'
                        + ' <span class="lrob-etk-nl-list-exclusions-email">&lt;' + escHtml(u.email || '') + '&gt;</span>'
                        + ' <button type="button" class="lrob-etk-icon-btn lrob-etk-icon-btn--ghost lrob-etk-icon-btn--danger" '
                        +   'data-exclusion-remove="' + u.id + '" '
                        +   'aria-label="' + escHtml(i18nExcRemove) + '" title="' + escHtml(i18nExcRemove) + '">'
                        +   '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>'
                        + '</button>';
                    ul.appendChild(li);
                });
            }

            function refreshExclusions(listId) {
                var ul = document.querySelector('[data-exclusion-list="' + listId + '"]');
                if (!ul) return;
                var fd = new FormData();
                fd.append('action', actionExcList);
                fd.append('_nonce', nonce);
                fd.append('list_id', listId);
                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                    .then(function (resp) {
                        if (resp && resp.success && resp.data) {
                            renderExclusionList(ul, resp.data.items || []);
                        }
                    });
            }

            // Exclusions: toggle panel, add, remove.
            document.addEventListener('click', function (e) {
                var toggle = e.target.closest && e.target.closest('[data-rule-exclusions-toggle], [data-exclusions-toggle]');
                if (toggle) {
                    var tid = toggle.getAttribute('data-rule-exclusions-toggle') || toggle.getAttribute('data-exclusions-toggle');
                    var pane = document.querySelector('[data-exclusions-pane="' + tid + '"]');
                    if (!pane) return;
                    var was = pane.hidden;
                    pane.hidden = !was;
                    toggle.setAttribute('aria-expanded', was ? 'true' : 'false');
                    if (was) refreshExclusions(tid);
                    return;
                }
                // Exclusion search-picker: click on a result row adds it
                // to the exclusion set (single click = single add).
                var excResult = e.target.closest && e.target.closest('[data-exclusion-result-id]');
                if (excResult) {
                    var picker = excResult.closest('[data-exclusion-picker]');
                    if (!picker) return;
                    var listId = picker.getAttribute('data-exclusion-picker');
                    var uidPick = parseInt(excResult.getAttribute('data-exclusion-result-id'), 10) || 0;
                    if (uidPick === 0 || !listId) return;
                    var fdA = new FormData();
                    fdA.append('action', actionExcAdd);
                    fdA.append('_nonce', nonce);
                    fdA.append('list_id', listId);
                    fdA.append('user_ids[]', String(uidPick));
                    picker.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'saving' } }));
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fdA })
                        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                        .then(function (resp) {
                            if (resp && resp.success) {
                                picker.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'saved' } }));
                                refreshExclusions(listId);
                                var editor = picker.closest('[data-rule-editor]');
                                if (editor) maybeRefreshPreview(editor);
                                var resultsEl = picker.querySelector('[data-exclusion-results]');
                                var searchEl = picker.querySelector('[data-exclusion-search]');
                                if (resultsEl) { resultsEl.hidden = true; resultsEl.innerHTML = ''; }
                                if (searchEl) searchEl.value = '';
                            } else {
                                picker.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'error' } }));
                            }
                        });
                    return;
                }
                var rem = e.target.closest && e.target.closest('[data-exclusion-remove]');
                if (rem) {
                    var uid = rem.getAttribute('data-exclusion-remove');
                    var row = rem.closest('[data-exclusion-user-id]');
                    var paneR = rem.closest('[data-exclusions-pane]');
                    var listIdR = paneR ? paneR.getAttribute('data-exclusions-pane') : '';
                    if (!uid || !listIdR) return;
                    rem.disabled = true;
                    paneR.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'saving' } }));
                    var fdr = new FormData();
                    fdr.append('action', actionExcRemove);
                    fdr.append('_nonce', nonce);
                    fdr.append('list_id', listIdR);
                    fdr.append('user_id', uid);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fdr })
                        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                        .then(function (resp) {
                            if (resp && resp.success && row && row.parentNode) {
                                row.parentNode.removeChild(row);
                                refreshExclusions(listIdR);
                                paneR.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'saved' } }));
                                // Re-run the preview if it's open — the
                                // resolved set just grew by one user.
                                var editor = paneR.closest('[data-rule-editor]');
                                if (editor) maybeRefreshPreview(editor);
                            } else {
                                rem.disabled = false;
                                paneR.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'error' } }));
                            }
                        });
                    return;
                }
            });

            // Rule editor: toggle / provider switch / save.
            document.addEventListener('click', function (e) {
                var toggle = e.target.closest && e.target.closest('[data-rule-toggle]');
                if (toggle) {
                    var lid = toggle.getAttribute('data-rule-toggle');
                    var editor = document.querySelector('[data-rule-editor="' + lid + '"]');
                    if (!editor) return;
                    var expanded = !editor.hidden;
                    editor.hidden = expanded;
                    toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    return;
                }
                var preview = e.target.closest && e.target.closest('[data-rule-preview]');
                if (preview) {
                    var pid = preview.getAttribute('data-rule-preview');
                    runRulePreview(pid, /* append */ false);
                    return;
                }
                var loadMore = e.target.closest && e.target.closest('[data-rule-preview-load-more]');
                if (loadMore) {
                    var lid = loadMore.getAttribute('data-rule-preview-load-more');
                    var step = parseInt(loadMore.getAttribute('data-step') || '50', 10) || 50;
                    runRulePreview(lid, /* append */ true, step);
                    return;
                }
            });

            // Autosave the rule's config whenever a field changes —
            // debounced so a rapid string of clicks coalesces into one
            // request. Status badge bubbles to the modal header via the
            // shared lrob-etk:save-status event.
            var ruleSaveDebounceTimers = {};
            function autosaveRule(editor) {
                if (!editor) return;
                var listId = editor.getAttribute('data-rule-editor');
                if (!listId) return;
                clearTimeout(ruleSaveDebounceTimers[listId]);
                ruleSaveDebounceTimers[listId] = setTimeout(function () {
                    var providerSlug = editor.getAttribute('data-rule-locked-provider') || '';
                    if (providerSlug === '') return;
                    // Status feedback lives in the modal header now — no
                    // inline render to avoid shifting the layout below.
                    editor.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'saving' } }));
                    var fd = new FormData();
                    fd.append('action', actionRuleSave);
                    fd.append('_nonce', nonce);
                    fd.append('id', listId);
                    appendRuleConfigToFormData(fd, editor, providerSlug);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                        .then(function (resp) {
                            if (resp && resp.success) {
                                editor.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'saved' } }));
                            } else {
                                var errMsg = (resp && resp.data && resp.data.message) || i18nRuleError;
                                editor.dispatchEvent(new CustomEvent('lrob-etk:save-status', { bubbles: true, detail: { state: 'error', message: errMsg } }));
                            }
                        });
                }, 350);
            }

            // Provider switch + config-field edits: auto-refresh the
            // preview pane if it's already shown (debounced so a string
            // of clicks coalesces). The preview stays hidden until the
            // admin clicks "Preview matches" the first time.
            var previewDebounceTimers = {};
            function maybeRefreshPreview(editor) {
                if (!editor) return;
                var listId = editor.getAttribute('data-rule-editor');
                if (!listId) return;
                var pane = editor.querySelector('[data-rule-preview-pane]');
                if (!pane || pane.hidden) return;
                clearTimeout(previewDebounceTimers[listId]);
                previewDebounceTimers[listId] = setTimeout(function () {
                    runRulePreview(listId, /* append */ false);
                }, 250);
            }
            document.addEventListener('change', function (e) {
                var fld = e.target.closest && e.target.closest('[data-rule-field]');
                if (fld) {
                    var editor2 = fld.closest('[data-rule-editor]');
                    if (!editor2) return;
                    autosaveRule(editor2);
                    maybeRefreshPreview(editor2);
                }
            });
            // The WC picker chip add/remove fires outside the standard
            // change event flow — boot a custom emitter to trigger autosave.
            document.addEventListener('click', function (e) {
                if (e.target.closest && e.target.closest('[data-wc-result-id], [data-wc-chip-remove]')) {
                    var picker = e.target.closest('[data-wc-picker]');
                    var editor = picker ? picker.closest('[data-rule-editor]') : null;
                    if (editor) {
                        // setTimeout so the picker's own click handler has
                        // already mutated the DOM by the time autosave runs.
                        setTimeout(function () { autosaveRule(editor); }, 50);
                    }
                }
            });

            document.addEventListener('click', function (e) {
                // Inline-confirm Yes button.
                var yes = e.target.closest && e.target.closest('[data-inline-confirm-yes="list"]');
                if (yes) {
                    var row = yes.closest('[data-resource-row]');
                    if (!row) return;
                    var id = row.getAttribute('data-resource-id');
                    if (!id) return;
                    yes.disabled = true;
                    var fd = new FormData();
                    fd.append('action', actionDelete);
                    fd.append('_nonce', nonce);
                    fd.append('id', id);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                        .then(function (resp) {
                            if (resp && resp.success) {
                                row.parentNode.removeChild(row);
                            } else {
                                yes.disabled = false;
                                yes.parentNode.parentNode.querySelector('.lrob-etk-inline-confirm-message').textContent =
                                    (resp && resp.data && resp.data.message) || i18nDeleteFailed;
                            }
                        });
                    return;
                }
                // Inline-confirm Cancel button.
                var no = e.target.closest && e.target.closest('[data-inline-confirm-no="list"]');
                if (no) {
                    var row2 = no.closest('[data-resource-row]');
                    if (!row2 || !row2.__originalHtml) return;
                    row2.innerHTML = row2.__originalHtml;
                    row2.__originalHtml = null;
                    return;
                }
                // Delete trigger — morph the row into the confirm prompt.
                var btn = e.target.closest && e.target.closest('[data-resource-delete="list"]');
                if (!btn) return;
                var row3 = btn.closest('[data-resource-row]');
                if (!row3) return;
                var name = btn.getAttribute('data-resource-name') || '';
                row3.__originalHtml = row3.innerHTML;
                row3.innerHTML = ''
                    + '<div class="lrob-etk-inline-confirm">'
                    +   '<span class="lrob-etk-inline-confirm-message">' + escHtml(i18nConfirm.replace('%s', name)) + '</span>'
                    +   '<span class="lrob-etk-inline-confirm-actions">'
                    +     '<button type="button" class="button lrob-etk-btn--danger-solid" data-inline-confirm-yes="list">' + escHtml(i18nYes) + '</button>'
                    +     '<button type="button" class="button" data-inline-confirm-no="list">' + escHtml(i18nCancel) + '</button>'
                    +   '</span>'
                    + '</div>';
            });
        })();
        </script>
        <?php
    }
}
