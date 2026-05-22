<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Modules\Newsletter\ListRepository;

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

    public function render(): void
    {
        $rows = $this->lists->list_all();
        $nonce = wp_create_nonce(AjaxController::NONCE_ACTION);
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <section class="lrob-etk-nl-resource">
            <header class="lrob-etk-nl-resource-head">
                <h2 class="lrob-etk-section-title"><?php esc_html_e('Subscriber lists', 'lrob-email-toolkit'); ?></h2>
                <p class="lrob-etk-nl-resource-intro">
                    <?php esc_html_e('Group subscribers so you can target specific audiences when sending. Lists you create here are manual — subscribers join via subscribe forms or admin actions. Rule-based lists (auto-populated by WP role, registration date, etc.) arrive with the send pipeline.', 'lrob-email-toolkit'); ?>
                </p>
            </header>

            <form class="lrob-etk-nl-resource-new" data-resource-new="list">
                <input type="text"
                       class="lrob-etk-nl-resource-new-name"
                       placeholder="<?php esc_attr_e('New list name', 'lrob-email-toolkit'); ?>"
                       required>
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
            <?php else : ?>
                <ul class="lrob-etk-nl-resource-list">
                    <?php foreach ($rows as $row) : ?>
                        <?php
                        $id = (int) ($row['id'] ?? 0);
                        $slug = (string) ($row['slug'] ?? '');
                        $name = (string) ($row['name'] ?? '');
                        ?>
                        <li class="lrob-etk-nl-resource-row" data-resource-row data-resource-id="<?php echo $id; ?>">
                            <input type="text"
                                   class="lrob-etk-nl-resource-name lrob-etk-nl-field"
                                   data-key="rename-list"
                                   data-resource-id="<?php echo $id; ?>"
                                   value="<?php echo esc_attr($name); ?>"
                                   autocomplete="off">
                            <span class="lrob-etk-nl-resource-slug" title="<?php esc_attr_e('Stable slug — used internally; renames don\'t change it.', 'lrob-email-toolkit'); ?>">
                                <?php echo esc_html($slug); ?>
                            </span>
                            <button type="button"
                                    class="lrob-etk-card-delete-link"
                                    data-resource-delete="list"
                                    data-resource-id="<?php echo $id; ?>"
                                    data-resource-name="<?php echo esc_attr($name); ?>">
                                <?php esc_html_e('Delete', 'lrob-email-toolkit'); ?>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <script>
        (function () {
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var actionCreate = <?php echo wp_json_encode(AjaxController::ACTION_LIST_CREATE); ?>;
            var actionDelete = <?php echo wp_json_encode(AjaxController::ACTION_LIST_DELETE); ?>;
            var i18nConfirm = <?php
                /* translators: %s: list name */
                echo wp_json_encode(__('Delete the "%s" list? Subscribers stay on the system but lose their membership in this list.', 'lrob-email-toolkit'));
            ?>;

            var newForm = document.querySelector('[data-resource-new="list"]');
            if (newForm) {
                newForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var nameInput = newForm.querySelector('.lrob-etk-nl-resource-new-name');
                    var status = newForm.querySelector('.lrob-etk-nl-resource-new-status');
                    var name = (nameInput.value || '').trim();
                    if (!name) return;
                    var fd = new FormData();
                    fd.append('action', actionCreate);
                    fd.append('_nonce', nonce);
                    fd.append('name', name);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                        .then(function (resp) {
                            if (resp && resp.success) {
                                window.location.reload();
                            } else {
                                status.textContent = (resp && resp.data && resp.data.message) || 'Error';
                                status.className = 'lrob-etk-nl-resource-new-status is-error';
                            }
                        });
                });
            }

            document.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('[data-resource-delete="list"]');
                if (!btn) return;
                var id = btn.getAttribute('data-resource-id');
                var name = btn.getAttribute('data-resource-name') || '';
                if (!id) return;
                if (!window.confirm(i18nConfirm.replace('%s', name))) return;
                var fd = new FormData();
                fd.append('action', actionDelete);
                fd.append('_nonce', nonce);
                fd.append('id', id);
                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                    .then(function (resp) {
                        if (resp && resp.success) {
                            window.location.reload();
                        } else {
                            window.alert((resp && resp.data && resp.data.message) || 'Could not delete');
                        }
                    });
            });
        })();
        </script>
        <?php
    }
}
