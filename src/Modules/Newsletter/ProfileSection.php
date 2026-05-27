<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Renders the newsletter preferences section inside WP's user-edit
 * page (Users → Your Profile, and Users → Edit user for admins).
 * Same UI as the public PrefsRenderer but inline, riding on profile.
 * php's own form + nonce + save flow.
 *
 * Hooks:
 *   - show_user_profile / edit_user_profile         → render the section
 *   - personal_options_update / edit_user_profile_update → save changes
 *
 * Capability-gated: only renders for the user being edited or an
 * admin with edit_user. WP handles that via the hooks themselves —
 * by the time these fire, the request has been authorised.
 */
final class ProfileSection
{
    public function __construct(
        private ListRepository $lists,
    ) {
    }

    public function register(): void
    {
        add_action('show_user_profile',          [$this, 'render']);
        add_action('edit_user_profile',          [$this, 'render']);
        add_action('personal_options_update',    [$this, 'save']);
        add_action('edit_user_profile_update',   [$this, 'save']);
    }

    public function render(\WP_User $user): void
    {
        $user_id = (int) $user->ID;
        $state = $this->build_state($user_id, (string) $user->user_email);
        ?>
        <h2 id="lrob-etk-nl-prefs"><?php esc_html_e('Newsletter preferences', 'lrob-email-toolkit'); ?></h2>
        <p class="description">
            <?php esc_html_e('Pick which mailing lists you\'re on. To stop receiving everything, uncheck "Receive newsletter emails" below.', 'lrob-email-toolkit'); ?>
        </p>
        <div class="lrob-etk-nl-prefs-profile-wrap"><?php echo PrefsRenderer::render_inputs($state); ?></div>
        <?php
    }

    public function save(int $user_id): void
    {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }
        // No own nonce — we ride on profile.php's update_user_meta-nonce
        // which WordPress already verified by the time this hook fires.

        $opted_in = !empty($_POST['lrob_etk_nl_opted_in']);
        update_user_meta($user_id, UserMeta::OPTED_IN, $opted_in ? '1' : '0');

        $chosen_lists = isset($_POST['lrob_etk_nl_lists']) && is_array($_POST['lrob_etk_nl_lists'])
            ? array_map('intval', wp_unslash($_POST['lrob_etk_nl_lists']))
            : [];

        // Clip to public-visible lists only — the profile-section picker
        // only renders public lists, but the POST shape is user-supplied
        // so we re-enforce server-side.
        $public_lists = $this->lists->list_public_for_subscribers();
        $public_ids = array_map(static fn ($l) => (int) $l['id'], $public_lists);
        $chosen_lists = array_values(array_intersect($chosen_lists, $public_ids));

        $current_all = $this->lists->memberships_for_recipient(UserMeta::KIND_USER, $user_id);
        $current_public = array_values(array_intersect($current_all, $public_ids));
        $to_add = array_diff($chosen_lists, $current_public);
        $to_remove = array_diff($current_public, $chosen_lists);
        foreach ($to_add as $list_id) {
            $this->lists->add_member((int) $list_id, UserMeta::KIND_USER, $user_id);
        }
        foreach ($to_remove as $list_id) {
            $this->lists->remove_member((int) $list_id, UserMeta::KIND_USER, $user_id);
        }
    }

    /** @return array<string, mixed> */
    private function build_state(int $user_id, string $email): array
    {
        $opted_in = (string) get_user_meta($user_id, UserMeta::OPTED_IN, true) === '1';
        $lists = $this->lists->list_public_for_subscribers();

        return [
            'kind'            => UserMeta::KIND_USER,
            'id'              => $user_id,
            'email'           => $email,
            'opted_in'        => $opted_in,
            'list_member_ids' => $this->lists->memberships_for_recipient(UserMeta::KIND_USER, $user_id),
            'lists'           => array_map(
                static fn (array $l) => [
                    'id'          => (int) ($l['id'] ?? 0),
                    'name'        => (string) ($l['name'] ?? ''),
                    'description' => (string) ($l['description'] ?? ''),
                ],
                $lists
            ),
        ];
    }
}
