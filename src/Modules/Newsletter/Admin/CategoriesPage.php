<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Admin;

use LRob\EmailToolkit\Admin\PageHeader;
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

    /** Categories CRUD inside a header-opened modal (same idiom as Lists). */
    public function render_modal(): void
    {
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-nl-categories-modal" role="dialog" aria-modal="true" aria-labelledby="lrob-etk-nl-categories-title" hidden>
            <div class="lrob-etk-modal-backdrop" data-modal-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--small">
                <header class="lrob-etk-modal-header">
                    <h3 id="lrob-etk-nl-categories-title" class="lrob-etk-modal-title-text"><?php esc_html_e('Manage categories', 'lrob-email-toolkit'); ?></h3>
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
            if (window.__lrobEtkNlCategoriesModalBound) return;
            window.__lrobEtkNlCategoriesModalBound = true;
            function whenReady(fn) {
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
                else fn();
            }
            whenReady(function () {
                if (window.lrobEtkModal) {
                    window.lrobEtkModal.bindHeader('lrob-etk-nl-categories-modal', 'lrob-etk-nl-categories-btn');
                }
            });
        })();
        </script>
        <?php
    }

    /** Standard "Manage categories" tools button used by the Newsletters page. */
    public static function categories_tool(): array
    {
        return [
            'label' => __('Manage categories', 'lrob-email-toolkit'),
            'icon'  => 'dashicons-category',
            'id'    => 'lrob-etk-nl-categories-btn',
        ];
    }

    public function render(?HomePage $hub = null, bool $embedded = false): void
    {
        $rows = $this->categories->list_all();
        $nonce = wp_create_nonce(AjaxController::NONCE_ACTION);
        $ajax_url = admin_url('admin-ajax.php');
        if (!$embedded) {
            PageHeader::render([
                'title' => sprintf(__('Newsletters — %s', 'lrob-email-toolkit'), __('Categories', 'lrob-email-toolkit')),
                'tools' => [HomePage::settings_tool()],
            ]);
            if ($hub) $hub->render_section_tabs(HomePage::VIEW_CATEGORIES);
        } else {
            echo '<h2 class="lrob-etk-section-title">' . esc_html__('Categories', 'lrob-email-toolkit') . '</h2>';
        }
        ?>
        <section class="lrob-etk-nl-resource">
            <p class="lrob-etk-nl-resource-intro">
                <?php esc_html_e('Every newsletter is tagged with a category. Subscribers can opt out of categories individually — useful for separating product updates from promotions, for example.', 'lrob-email-toolkit'); ?>
            </p>

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
            if (window.__lrobEtkNlCategoriesResourceBound) return;
            window.__lrobEtkNlCategoriesResourceBound = true;
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var actionCreate = <?php echo wp_json_encode(AjaxController::ACTION_CATEGORY_CREATE); ?>;
            var actionDelete = <?php echo wp_json_encode(AjaxController::ACTION_CATEGORY_DELETE); ?>;
            var i18nConfirm = <?php
                /* translators: %s: category name */
                echo wp_json_encode(__('Delete the "%s" category? Existing subscriber opt-outs for it will be silently ignored from now on.', 'lrob-email-toolkit'));
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
                    +   ' data-key="rename-category" data-resource-id="' + id + '"'
                    +   ' value="' + escAttr(name) + '" autocomplete="off">'
                    + '<button type="button" class="lrob-etk-card-delete-link"'
                    +   ' data-resource-delete="category" data-resource-id="' + id + '"'
                    +   ' data-resource-name="' + escAttr(name) + '">' + escHtml(i18nYes) + '</button>';
            }

            function ensureList(scope) {
                var list = scope.querySelector('.lrob-etk-nl-resource-list');
                if (list) return list;
                var empty = scope.querySelector('.lrob-etk-nl-resource-empty');
                var ul = document.createElement('ul');
                ul.className = 'lrob-etk-nl-resource-list';
                if (empty) empty.replaceWith(ul);
                else scope.appendChild(ul);
                return ul;
            }

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
                            if (resp && resp.success && resp.data && resp.data.id) {
                                var scope = newForm.closest('.lrob-etk-nl-resource') || document;
                                var list = ensureList(scope);
                                var li = document.createElement('li');
                                li.className = 'lrob-etk-nl-resource-row';
                                li.setAttribute('data-resource-row', '');
                                li.setAttribute('data-resource-id', String(resp.data.id));
                                li.innerHTML = rowHtml(resp.data.id, resp.data.name || name);
                                list.appendChild(li);
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

            document.addEventListener('click', function (e) {
                var yes = e.target.closest && e.target.closest('[data-inline-confirm-yes="category"]');
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
                var no = e.target.closest && e.target.closest('[data-inline-confirm-no="category"]');
                if (no) {
                    var row2 = no.closest('[data-resource-row]');
                    if (!row2 || !row2.__originalHtml) return;
                    row2.innerHTML = row2.__originalHtml;
                    row2.__originalHtml = null;
                    return;
                }
                var btn = e.target.closest && e.target.closest('[data-resource-delete="category"]');
                if (!btn) return;
                var row3 = btn.closest('[data-resource-row]');
                if (!row3) return;
                var name = btn.getAttribute('data-resource-name') || '';
                row3.__originalHtml = row3.innerHTML;
                row3.innerHTML = ''
                    + '<div class="lrob-etk-inline-confirm">'
                    +   '<span class="lrob-etk-inline-confirm-message">' + escHtml(i18nConfirm.replace('%s', name)) + '</span>'
                    +   '<span class="lrob-etk-inline-confirm-actions">'
                    +     '<button type="button" class="button lrob-etk-btn--danger-solid" data-inline-confirm-yes="category">' + escHtml(i18nYes) + '</button>'
                    +     '<button type="button" class="button" data-inline-confirm-no="category">' + escHtml(i18nCancel) + '</button>'
                    +   '</span>'
                    + '</div>';
            });
        })();
        </script>
        <?php
    }
}
