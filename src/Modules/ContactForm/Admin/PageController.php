<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\ContactForm\CPT;
use LRob\EmailToolkit\Modules\ContactForm\Module as ContactFormModule;

// Redirects edit.php?post_type=lrob_etk_cform to the custom page to avoid a duplicate CPT list.
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
        add_action('admin_init', [$this, 'redirect_post_type_list']);
    }

    // Gutenberg's back arrow lands here; only redirect idle GETs (bulk-action POSTs pass through).
    public function redirect_post_type_list(): void
    {
        global $pagenow;
        if ($pagenow !== 'edit.php') {
            return;
        }
        if (($_GET['post_type'] ?? '') !== CPT::POST_TYPE) {
            return;
        }
        if (!empty($_GET['action']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=' . FormsPage::SLUG));
        exit;
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
