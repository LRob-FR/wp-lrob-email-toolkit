<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use LRob\EmailToolkit\Modules\AbstractModule;
use LRob\EmailToolkit\Modules\ContactForm\Admin\AjaxController;
use LRob\EmailToolkit\Modules\ContactForm\Admin\FormsPage;
use LRob\EmailToolkit\Modules\ContactForm\Admin\PageController;

/**
 * Contact Form module — customizable forms with stacked anti-spam (honeypot,
 * time-trap, rate limit, math challenge) plus a future captcha layer.
 *
 * Architecture summary (see CLAUDE.md for full detail):
 *  - Forms are stored as a CPT (`lrob_etk_contact_form`) edited in Gutenberg.
 *  - Field types are individual Gutenberg blocks registered server-side; each
 *    block has a render_callback that emits frontend HTML scoped to the
 *    current FormContext.
 *  - Page-side, a separate `lrob-etk/contact-form` block picks a form by ID
 *    and wraps its rendered blocks in a &lt;form&gt; element with per-render
 *    instance scoping.
 *  - Submissions hit a single AJAX endpoint (SubmitHandler) and persist to
 *    a dedicated submissions table. Notification email is dispatched through
 *    the SMTP module using the 'contact_form' source.
 */
final class Module extends AbstractModule
{
    public function slug(): string
    {
        return 'contact_form';
    }

    public function name(): string
    {
        return __('Contact Form', 'lrob-email-toolkit');
    }

    public function description(): string
    {
        return __(
            'Build customizable contact forms with stacked anti-spam and the existing SMTP identity routing.',
            'lrob-email-toolkit'
        );
    }

    public function version(): string
    {
        return '0.0.1';
    }

    public function install(): void
    {
        Schema::install();
    }

    public function uninstall(): void
    {
        // Schedule cleanup of the rate-limit cron before dropping tables.
        (new RateLimiter())->unregister();

        // Trash every contact form CPT post — uninstall.php's table/option
        // sweeper covers our submissions + rate tables and prefs.
        $posts = get_posts([
            'post_type'      => CPT::POST_TYPE,
            'post_status'    => 'any',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'suppress_filters' => true,
        ]);
        foreach ($posts as $post_id) {
            wp_delete_post((int) $post_id, true);
        }

        Schema::drop();
    }

    public function admin_page_url(): ?string
    {
        return admin_url('admin.php?page=' . FormsPage::SLUG);
    }

    public function register(): void
    {
        $rate_limiter = new RateLimiter();
        $submissions = new SubmissionRepository();
        $this->container->set(RateLimiter::class, $rate_limiter);
        $this->container->set(SubmissionRepository::class, $submissions);

        // Runtime (CPT, blocks, AJAX submit, cron) only when enabled.
        if ($this->is_enabled()) {
            (new CPT())->register();
            (new Blocks())->register();
            (new Frontend())->register();
            $rate_limiter->register();
            (new SubmitHandler($rate_limiter, $submissions))->register();
        }

        // Admin chrome stays registered regardless of enabled state, so the
        // user can land on the Contact Forms page after disabling and
        // re-enable from there (FormsPage shows a disabled-state message).
        if (is_admin()) {
            add_action('admin_post_' . $this->toggle_action(), [$this, 'handle_toggle']);
            $forms_page = new FormsPage($this);
            $forms_page->register();
            (new PageController($this, $forms_page))->register();
            (new AjaxController())->register();
        }
    }
}
