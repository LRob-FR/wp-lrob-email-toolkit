<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\ContactForm\CPT;
use LRob\EmailToolkit\Modules\ContactForm\Module as ContactFormModule;

// The CPT has no Gutenberg editor (no 'editor' support) — forms are built on the
// custom FormsPage. So the default CPT list (edit.php) + the bare title-only
// create/edit screens (post-new.php / post.php) are obsolete; redirect them all
// to FormsPage so neither a stray bookmark nor a back-arrow lands on a dead screen.
final class PageController
{
    public function __construct(
        private ContactFormModule $module,
        private FormsPage $forms_page,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu'], 30);
        add_action('admin_head', [$this, 'highlight_parent_menu']);
        add_action('admin_init', [$this, 'redirect_legacy_cpt_screens']);
    }

    // Only redirect idle GETs — bulk-action / save POSTs pass through to WP.
    public function redirect_legacy_cpt_screens(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        global $pagenow;
        $forms_url = admin_url('admin.php?page=' . FormsPage::SLUG);

        // edit.php?post_type=… (the duplicate CPT list) + post-new.php?post_type=… (bare
        // create screen). edit.php bulk actions (?action=…) pass through untouched.
        if (($pagenow === 'edit.php' || $pagenow === 'post-new.php')
            && ($_GET['post_type'] ?? '') === CPT::POST_TYPE
            && empty($_GET['action'])) {
            wp_safe_redirect($forms_url);
            exit;
        }

        // post.php?post=<id>&action=edit for a contact form → the card on FormsPage.
        if ($pagenow === 'post.php' && ($_GET['action'] ?? '') === 'edit') {
            $post_id = (int) ($_GET['post'] ?? 0);
            if ($post_id > 0 && get_post_type($post_id) === CPT::POST_TYPE) {
                wp_safe_redirect($forms_url . '#form-' . $post_id);
                exit;
            }
        }
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'lrob-etk',
            __('Contact Forms', 'lrob-email-toolkit'),
            __('Contact Forms', 'lrob-email-toolkit'),
            Activator::CAPABILITY,
            FormsPage::SLUG,
            [$this->forms_page, 'render']
        );
    }

    // Without this WP highlights the hidden CPT menu entry during Gutenberg editing.
    public function highlight_parent_menu(): void
    {
        global $parent_file, $submenu_file, $current_screen;
        if (!$current_screen instanceof \WP_Screen) {
            return;
        }
        if ($current_screen->post_type === CPT::POST_TYPE) {
            $parent_file = 'lrob-etk';
            $submenu_file = FormsPage::SLUG;
        }
    }
}
