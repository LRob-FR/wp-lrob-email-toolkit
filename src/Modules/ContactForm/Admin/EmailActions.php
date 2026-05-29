<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm\Admin;

use LRob\EmailToolkit\Activator;
use LRob\EmailToolkit\Modules\ContactForm\FileRepository;
use LRob\EmailToolkit\Modules\ContactForm\SubmissionRepository;

// Docs: docs/contact-form.md — spam/delete require POST (GET bounces to confirm page); unspam accepts GET.
final class EmailActions
{
    public const ACTION_SPAM   = 'lrob_etk_cf_email_spam';
    public const ACTION_UNSPAM = 'lrob_etk_cf_email_unspam';
    public const ACTION_DELETE = 'lrob_etk_cf_email_delete';

    public function __construct(
        private SubmissionRepository $submissions,
        private ?FileRepository $files = null,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_SPAM,   [$this, 'handle_spam']);
        add_action('admin_post_' . self::ACTION_UNSPAM, [$this, 'handle_unspam']);
        add_action('admin_post_' . self::ACTION_DELETE, [$this, 'handle_delete']);
    }

    public static function action_url(string $action, int $submission_id): string
    {
        return wp_nonce_url(
            add_query_arg(
                ['action' => $action, 'id' => $submission_id],
                admin_url('admin-post.php')
            ),
            $action . '_' . $submission_id
        );
    }

    // Email buttons point here (confirm page), not directly to the action URL.
    public static function confirm_url(string $action, int $submission_id): string
    {
        $confirm_action = $action === self::ACTION_SPAM ? 'spam-confirm' : 'delete-confirm';
        return add_query_arg(
            [
                'page'   => FormsPage::SLUG,
                'view'   => FormsPage::VIEW_SUBMISSIONS,
                'action' => $confirm_action,
                'id'     => $submission_id,
            ],
            admin_url('admin.php')
        );
    }

    public function handle_spam(): void
    {
        // GET = stale link; bounce to confirm page rather than mutating state silently.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            wp_safe_redirect(self::confirm_url(self::ACTION_SPAM, $id));
            exit;
        }
        $id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
        check_admin_referer(self::ACTION_SPAM . '_' . $id);
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        if ($id <= 0 || $this->submissions->find($id) === null) {
            $this->redirect_to_inbox('not-found', 0);
        }
        $this->submissions->flag_as_spam($id);
        $this->redirect_to_inbox('spam', $id);
    }

    public function handle_unspam(): void
    {
        $id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
        check_admin_referer(self::ACTION_UNSPAM . '_' . $id);
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        if ($id <= 0 || $this->submissions->find($id) === null) {
            $this->redirect_to_inbox('not-found', 0);
        }
        $this->submissions->restore_from_spam($id, 'manual_unspam');
        $this->redirect_to_inbox('unspam', $id);
    }

    public function handle_delete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            wp_safe_redirect(self::confirm_url(self::ACTION_DELETE, $id));
            exit;
        }
        $id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
        check_admin_referer(self::ACTION_DELETE . '_' . $id);
        if (!current_user_can(Activator::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'lrob-email-toolkit'));
        }
        if ($id <= 0) {
            $this->redirect_to_inbox('not-found', 0);
        }
        // Files first so we never leave orphans behind a deleted submission row.
        if ($this->files !== null) {
            $this->files->delete_by_submission($id);
        }
        $this->submissions->delete_by_id($id);
        $this->redirect_to_inbox('deleted', $id);
    }

    private function redirect_to_inbox(string $notice, int $id): void
    {
        $url = add_query_arg(
            [
                'page'   => FormsPage::SLUG,
                'view'   => FormsPage::VIEW_SUBMISSIONS,
                'notice' => $notice,
                'id'     => $id,
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }
}
