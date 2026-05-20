<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\ContactForm\CPT;
use LRob\EmailToolkit\Modules\ContactForm\Module as ContactFormModule;

/**
 * Mounts the single "Contact Forms" entry under the Email Toolkit top-level
 * menu. The Submissions inbox is a view of the same page (?view=submissions)
 * rather than a separate submenu — see FormsPage::render. We don't expose
 * the bare CPT list (edit.php?post_type=...) because it would duplicate the
 * custom UI and confuse users.
 */
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

    /**
     * Intercept plain visits to `edit.php?post_type=lrob_etk_cform` (the back
     * arrow in Gutenberg lands there) and send the user to our custom Contact
     * Forms admin page instead. Only fires on idle GETs — bulk-action POSTs
     * (?action=trash etc.) pass through so they keep working if they ever fire.
     */
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

    /**
     * Keep the "Email Toolkit → Contact Forms" entry highlighted while the
     * user is editing a contact-form post in Gutenberg. Without this, WP
     * would highlight the (hidden) CPT menu, then nothing visible.
     */
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
