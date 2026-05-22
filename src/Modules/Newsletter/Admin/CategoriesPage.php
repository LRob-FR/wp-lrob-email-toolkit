<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Modules\Newsletter\CategoryRepository;

/**
 * Categories CRUD inside the Newsletter hub. Simple list:
 *
 *   - Top: "+ New category" inline form (name + create).
 *   - Below: one row per category — name (rename inline on blur) +
 *     description hint + delete button (suppressed for the protected
 *     "general" seed).
 *
 * No card grid — categories are simple labels, the visual weight of
 * the form-card pattern would be overkill. The shared .lrob-etk-card-
 * footer / .lrob-etk-card-delete-link primitives apply where they
 * make sense.
 *
 * Auto-save for renames uses the same data-key="rename-category"
 * marker the newsletter-admin.js script knows; create + delete are
 * handled by tiny inline JS at the bottom of the render.
 */
final class CategoriesPage
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    public function render(): void
    {
        $rows = $this->categories->list_all();
        $nonce = wp_create_nonce(AjaxController::NONCE_ACTION);
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <section class="lrob-etk-nl-resource">
            <header class="lrob-etk-nl-resource-head">
                <h2 class="lrob-etk-section-title"><?php esc_html_e('Email categories', 'lrob-email-toolkit'); ?></h2>
                <p class="lrob-etk-nl-resource-intro">
                    <?php esc_html_e('Every campaign is tagged with a category. Subscribers can opt out of categories individually — useful for separating product updates from promotions, for example.', 'lrob-email-toolkit'); ?>
                </p>
            </header>

            <form class="lrob-etk-nl-resource-new" data-resource-new="category">
                <input type="text"
                       class="lrob-etk-nl-resource-new-name"
                       placeholder="<?php esc_attr_e('New category name', 'lrob-email-toolkit'); ?>"
                       required>
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <?php esc_html_e('Add category', 'lrob-email-toolkit'); ?>
                </button>
                <span class="lrob-etk-nl-resource-new-status" aria-live="polite"></span>
            </form>

            <?php if ($rows === []) : ?>
                <p class="lrob-etk-nl-resource-empty">
                    <?php esc_html_e('No categories yet. The default "General" category is auto-created when the module is enabled.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else : ?>
                <ul class="lrob-etk-nl-resource-list">
                    <?php foreach ($rows as $row) : ?>
                        <?php
                        $id = (int) ($row['id'] ?? 0);
                        $slug = (string) ($row['slug'] ?? '');
                        $name = (string) ($row['name'] ?? '');
                        $protected = ($slug === CategoryRepository::PROTECTED_SLUG);
                        ?>
                        <li class="lrob-etk-nl-resource-row" data-resource-row data-resource-id="<?php echo $id; ?>">
                            <input type="text"
                                   class="lrob-etk-nl-resource-name lrob-etk-nl-field"
                                   data-key="rename-category"
                                   data-resource-id="<?php echo $id; ?>"
                                   value="<?php echo esc_attr($name); ?>"
                                   autocomplete="off">
                            <span class="lrob-etk-nl-resource-slug" title="<?php esc_attr_e('Stable slug — used internally; renames don\'t change it.', 'lrob-email-toolkit'); ?>">
                                <?php echo esc_html($slug); ?>
                            </span>
                            <?php if (!$protected) : ?>
                                <button type="button"
                                        class="lrob-etk-card-delete-link"
                                        data-resource-delete="category"
                                        data-resource-id="<?php echo $id; ?>"
                                        data-resource-name="<?php echo esc_attr($name); ?>">
                                    <?php esc_html_e('Delete', 'lrob-email-toolkit'); ?>
                                </button>
                            <?php else : ?>
                                <span class="lrob-etk-nl-resource-protected" title="<?php esc_attr_e('The default category cannot be deleted.', 'lrob-email-toolkit'); ?>">
                                    <?php esc_html_e('Default', 'lrob-email-toolkit'); ?>
                                </span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <script>
        (function () {
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var actionCreate = <?php echo wp_json_encode(AjaxController::ACTION_CATEGORY_CREATE); ?>;
            var actionDelete = <?php echo wp_json_encode(AjaxController::ACTION_CATEGORY_DELETE); ?>;
            var i18nConfirm = <?php
                /* translators: %s: category name */
                echo wp_json_encode(__('Delete the "%s" category? Existing subscriber opt-outs for this category will be silently ignored from now on.', 'lrob-email-toolkit'));
            ?>;

            var newForm = document.querySelector('[data-resource-new="category"]');
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
                var btn = e.target.closest && e.target.closest('[data-resource-delete="category"]');
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
