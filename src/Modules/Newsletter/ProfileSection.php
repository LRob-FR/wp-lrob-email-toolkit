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
        private CategoryRepository $categories,
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
            <?php esc_html_e('Pick which kinds of emails you want and which lists you\'re on. To stop receiving everything, uncheck "Receive newsletter emails" below.', 'lrob-email-toolkit'); ?>
        </p>
        <?php
        // Scoped styling that tames the default <fieldset>/<legend>
        // sizing inside profile.php — without this, the legends render
        // 1.3-1.5× the body font (browser default) and visually dwarf
        // the page's own <h2>. PrefsRenderer keeps its fieldset markup
        // for semantic correctness; this overlays profile-appropriate
        // sizing without touching the public prefs page.
        ?>
        <style>
            .lrob-etk-nl-prefs-inputs fieldset {
                border: 0;
                padding: 0;
                margin: 0 0 1.5em;
            }
            .lrob-etk-nl-prefs-inputs legend {
                padding: 0;
                margin: 0 0 0.5em;
                font-size: 13px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                color: #3c434a;
            }
            .lrob-etk-nl-prefs-inputs ul.lrob-etk-nl-prefs-checklist {
                list-style: none;
                margin: 0;
                padding: 0;
                display: flex;
                flex-wrap: wrap;
                gap: 0.25rem 1rem;
            }
            .lrob-etk-nl-prefs-inputs ul.lrob-etk-nl-prefs-checklist li {
                margin: 0;
            }
            .lrob-etk-nl-prefs-inputs p.description {
                margin: 0.25em 0 0.75em;
            }
        </style>
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

        $chosen_categories = isset($_POST['lrob_etk_nl_categories']) && is_array($_POST['lrob_etk_nl_categories'])
            ? array_map('sanitize_title', wp_unslash($_POST['lrob_etk_nl_categories']))
            : [];
        $all_slugs = array_keys($this->categories->slug_label_map());
        $opt_outs = array_values(array_diff($all_slugs, $chosen_categories));
        update_user_meta($user_id, UserMeta::CATEGORY_OPT_OUTS, (string) wp_json_encode($opt_outs));

        $chosen_lists = isset($_POST['lrob_etk_nl_lists']) && is_array($_POST['lrob_etk_nl_lists'])
            ? array_map('intval', wp_unslash($_POST['lrob_etk_nl_lists']))
            : [];
        $current = $this->lists->memberships_for_recipient(UserMeta::KIND_USER, $user_id);
        $to_add = array_diff($chosen_lists, $current);
        $to_remove = array_diff($current, $chosen_lists);
        foreach ($to_add as $list_id) {
            if ($this->lists->find((int) $list_id) === null) {
                continue;
            }
            $this->lists->add_member((int) $list_id, UserMeta::KIND_USER, $user_id);
        }
        foreach ($to_remove as $list_id) {
            $this->lists->remove_member((int) $list_id, UserMeta::KIND_USER, $user_id);
        }
    }

    /** @return array<string, mixed> */
    private function build_state(int $user_id, string $email): array
    {
        $opt_outs_json = (string) get_user_meta($user_id, UserMeta::CATEGORY_OPT_OUTS, true);
        $opt_outs = $opt_outs_json !== ''
            ? array_values(array_filter(array_map('strval', (array) json_decode($opt_outs_json, true)), static fn ($s) => $s !== ''))
            : [];
        $opted_in = (string) get_user_meta($user_id, UserMeta::OPTED_IN, true) === '1';

        return [
            'kind'            => UserMeta::KIND_USER,
            'id'              => $user_id,
            'email'           => $email,
            'opted_in'        => $opted_in,
            'opt_outs'        => $opt_outs,
            'list_member_ids' => $this->lists->memberships_for_recipient(UserMeta::KIND_USER, $user_id),
            'categories'      => array_map(
                static fn (array $c) => ['slug' => (string) ($c['slug'] ?? ''), 'name' => (string) ($c['name'] ?? '')],
                $this->categories->list_all()
            ),
            'lists'           => array_map(
                static fn (array $l) => ['id' => (int) ($l['id'] ?? 0), 'name' => (string) ($l['name'] ?? '')],
                $this->lists->list_all()
            ),
        ];
    }
}
