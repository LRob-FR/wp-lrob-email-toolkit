<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * HTML renderer for the preferences UI. Two render modes:
 *
 *   - render_inputs() — bare form inputs (public-list memberships +
 *     opt-in master toggle for WP users). No `<form>` wrapper, no
 *     submit button. Profile section embeds this inside profile.php's
 *     own form.
 *   - render_full_form() — wraps the inputs in a `<form>` with a
 *     nonce, submit button, and (for subscribers only) the "leave
 *     entirely" / "forget me" destructive actions. Used by the
 *     public PrefsHandler.
 *
 * State shape (consumed by both methods):
 *   [
 *     'kind'           => 'user' | 'subscriber',
 *     'id'             => int,                    // wp_user.ID or subscribers.id
 *     'email'          => string,                 // display only
 *     'opted_in'       => bool,                   // WP users only; subscriber = always true unless status=unsub
 *     'list_member_ids'=> array<int, int>,        // list ids the recipient currently belongs to
 *     'lists'          => array<int, array{id:int, name:string, description:string}>,
 *   ]
 */
final class PrefsRenderer
{
    /**
     * Render the inputs without any surrounding form chrome. For use
     * inside profile.php's existing form.
     *
     * @param array<string, mixed> $state
     */
    public static function render_inputs(array $state): string
    {
        $kind = (string) ($state['kind'] ?? '');
        $opted_in = (bool) ($state['opted_in'] ?? false);
        $member_ids = array_map('intval', (array) ($state['list_member_ids'] ?? []));
        $lists = (array) ($state['lists'] ?? []);
        $profile = (array) ($state['profile'] ?? []);
        $pending_email = (string) ($state['pending_email'] ?? '');
        $email = (string) ($state['email'] ?? '');

        ob_start();
        ?>
        <div class="lrob-etk-nl-prefs-inputs">
            <?php if ($kind === UserMeta::KIND_SUBSCRIBER) : ?>
                <?php echo self::render_profile_section($profile); ?>
                <?php echo self::render_email_change_section($email, $pending_email); ?>
            <?php endif; ?>

            <?php if ($kind === UserMeta::KIND_USER) : ?>
                <section class="lrob-etk-nl-prefs-master">
                    <h3 class="lrob-etk-nl-prefs-section-title"><?php esc_html_e('Receive newsletter emails', 'lrob-email-toolkit'); ?></h3>
                    <label>
                        <input type="checkbox" name="lrob_etk_nl_opted_in" value="1" <?php checked($opted_in); ?>>
                        <?php esc_html_e('Yes, send me newsletter emails for the lists I select below.', 'lrob-email-toolkit'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Turning this off stops every newsletter. Transactional emails (password resets, order notifications, …) keep coming — to stop those too, delete your WordPress account from your profile page.', 'lrob-email-toolkit'); ?>
                    </p>
                </section>
            <?php endif; ?>

            <section class="lrob-etk-nl-prefs-lists">
                <h3 class="lrob-etk-nl-prefs-section-title"><?php esc_html_e('Mailing lists', 'lrob-email-toolkit'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Tick the lists you want to receive emails from.', 'lrob-email-toolkit'); ?>
                </p>
                <?php if ($lists === []) : ?>
                    <p><em><?php esc_html_e('No lists are open for self-subscription yet.', 'lrob-email-toolkit'); ?></em></p>
                <?php else : ?>
                    <ul class="lrob-etk-nl-prefs-checklist">
                        <?php foreach ($lists as $list) : ?>
                            <?php
                            $id = (int) ($list['id'] ?? 0);
                            $name = (string) ($list['name'] ?? '');
                            $description = (string) ($list['description'] ?? '');
                            if ($id <= 0 || $name === '') {
                                continue;
                            }
                            $member = in_array($id, $member_ids, true);
                            ?>
                            <li>
                                <label>
                                    <input type="checkbox" name="lrob_etk_nl_lists[]" value="<?php echo $id; ?>" <?php checked($member); ?>>
                                    <span><?php echo esc_html($name); ?></span>
                                    <?php if ($description !== '') : ?>
                                        <em class="lrob-etk-nl-prefs-list-desc"><?php echo esc_html($description); ?></em>
                                    <?php endif; ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Subscriber-only: editable profile fields. The "Save preferences"
     * primary submit at the bottom of the form catches the
     * profile[<column>] POST shape (PrefsHandler whitelists against
     * SubscriberFields::PROFILE_COLUMNS server-side). Email is NOT
     * here — it has its own confirm-on-new-address flow below.
     *
     * @param array<string, string> $profile
     */
    private static function render_profile_section(array $profile): string
    {
        $val = static fn (string $col): string => (string) ($profile[$col] ?? '');
        ob_start();
        ?>
        <section class="lrob-etk-nl-prefs-profile">
            <h3 class="lrob-etk-nl-prefs-section-title"><?php esc_html_e('Your details', 'lrob-email-toolkit'); ?></h3>
            <p class="description">
                <?php esc_html_e('Edit your profile. Empty fields stay empty — none of these are required.', 'lrob-email-toolkit'); ?>
            </p>
            <div class="lrob-etk-nl-prefs-profile-grid">
                <label>
                    <span><?php esc_html_e('First name', 'lrob-email-toolkit'); ?></span>
                    <input type="text" name="profile[first_name]" value="<?php echo esc_attr($val('first_name')); ?>" autocomplete="given-name">
                </label>
                <label>
                    <span><?php esc_html_e('Last name', 'lrob-email-toolkit'); ?></span>
                    <input type="text" name="profile[last_name]" value="<?php echo esc_attr($val('last_name')); ?>" autocomplete="family-name">
                </label>
                <label>
                    <span><?php esc_html_e('Phone', 'lrob-email-toolkit'); ?></span>
                    <input type="tel" name="profile[phone]" value="<?php echo esc_attr($val('phone')); ?>" autocomplete="tel">
                </label>
                <label>
                    <span><?php esc_html_e('Gender', 'lrob-email-toolkit'); ?></span>
                    <select name="profile[gender]">
                        <option value=""><?php esc_html_e('—', 'lrob-email-toolkit'); ?></option>
                        <?php foreach (SubscriberFields::GENDER_VALUES as $slug) : ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($val('gender'), $slug); ?>>
                                <?php echo esc_html(SubscriberFields::gender_label($slug)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="lrob-etk-nl-prefs-profile-address">
                <span class="lrob-etk-nl-prefs-profile-address-title"><?php esc_html_e('Postal address', 'lrob-email-toolkit'); ?></span>
                <label class="lrob-etk-nl-prefs-profile-row">
                    <span><?php esc_html_e('Street address', 'lrob-email-toolkit'); ?></span>
                    <input type="text" name="profile[address_line]" value="<?php echo esc_attr($val('address_line')); ?>" autocomplete="address-line1">
                </label>
                <label class="lrob-etk-nl-prefs-profile-row">
                    <span><?php esc_html_e('Address line 2', 'lrob-email-toolkit'); ?></span>
                    <input type="text" name="profile[address_line2]" value="<?php echo esc_attr($val('address_line2')); ?>" autocomplete="address-line2">
                </label>
                <div class="lrob-etk-nl-prefs-profile-grid">
                    <label>
                        <span><?php esc_html_e('Postcode', 'lrob-email-toolkit'); ?></span>
                        <input type="text" name="profile[address_postcode]" value="<?php echo esc_attr($val('address_postcode')); ?>" autocomplete="postal-code">
                    </label>
                    <label>
                        <span><?php esc_html_e('City', 'lrob-email-toolkit'); ?></span>
                        <input type="text" name="profile[address_city]" value="<?php echo esc_attr($val('address_city')); ?>" autocomplete="address-level2">
                    </label>
                    <label>
                        <span><?php esc_html_e('State / region', 'lrob-email-toolkit'); ?></span>
                        <input type="text" name="profile[address_region]" value="<?php echo esc_attr($val('address_region')); ?>" autocomplete="address-level1">
                    </label>
                    <label>
                        <span><?php esc_html_e('Country (ISO-2)', 'lrob-email-toolkit'); ?></span>
                        <input type="text" name="profile[address_country]" value="<?php echo esc_attr($val('address_country')); ?>" maxlength="2" autocomplete="country">
                    </label>
                </div>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Subscriber-only: change-your-email mini-section. Two states:
     *   - No pending request: show current email + new-email input +
     *     "Change email" button.
     *   - Pending request: show "pending change to X" + "Cancel" button.
     *
     * Both buttons are <button name="..." value="1"> so they submit
     * the same outer form but route differently in PrefsHandler.
     */
    private static function render_email_change_section(string $current_email, string $pending_email): string
    {
        ob_start();
        ?>
        <section class="lrob-etk-nl-prefs-email-change">
            <h3 class="lrob-etk-nl-prefs-section-title"><?php esc_html_e('Email address', 'lrob-email-toolkit'); ?></h3>
            <p>
                <?php
                printf(
                    /* translators: %s: subscriber's current email address. */
                    esc_html__('Current address: %s', 'lrob-email-toolkit'),
                    '<strong>' . esc_html($current_email) . '</strong>'
                );
                ?>
            </p>
            <?php if ($pending_email !== '') : ?>
                <p class="lrob-etk-nl-prefs-email-pending">
                    <?php
                    printf(
                        /* translators: %s: new email address awaiting confirmation. */
                        esc_html__('Pending change to %s — check that inbox for the confirmation link (valid 24h).', 'lrob-email-toolkit'),
                        '<strong>' . esc_html($pending_email) . '</strong>'
                    );
                    ?>
                </p>
                <button type="submit" name="lrob_etk_nl_cancel_email_change" value="1" class="lrob-etk-nl-prefs-secondary">
                    <?php esc_html_e('Cancel pending change', 'lrob-email-toolkit'); ?>
                </button>
            <?php else : ?>
                <label class="lrob-etk-nl-prefs-email-change-row">
                    <span><?php esc_html_e('New email', 'lrob-email-toolkit'); ?></span>
                    <input type="email" name="lrob_etk_nl_new_email" placeholder="<?php esc_attr_e('you@example.com', 'lrob-email-toolkit'); ?>" autocomplete="email">
                </label>
                <button type="submit" name="lrob_etk_nl_request_email_change" value="1" class="lrob-etk-nl-prefs-secondary">
                    <?php esc_html_e('Change email', 'lrob-email-toolkit'); ?>
                </button>
                <p class="description">
                    <?php esc_html_e('We\'ll send a confirmation link to the new address. The change kicks in once you click it.', 'lrob-email-toolkit'); ?>
                </p>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render the full prefs page form with submit + (subscriber-only)
     * destructive actions. Used by the public PrefsHandler.
     *
     * @param array<string, mixed> $state
     */
    public static function render_full_form(array $state, string $form_action, string $nonce_action, string $return_to = ''): string
    {
        $kind = (string) ($state['kind'] ?? '');
        $email = (string) ($state['email'] ?? '');

        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url($form_action); ?>" class="lrob-etk-nl-prefs-form">
            <?php wp_nonce_field($nonce_action, '_lrob_etk_nl_nonce'); ?>
            <input type="hidden" name="lrob_etk_nl_prefs_submit" value="1">
            <?php if ($return_to !== '') : ?>
                <input type="hidden" name="_lrob_etk_nl_return_to" value="<?php echo esc_attr($return_to); ?>">
            <?php endif; ?>
            <p class="lrob-etk-nl-prefs-email">
                <?php
                printf(
                    /* translators: %s: recipient email address. */
                    esc_html__('Preferences for %s', 'lrob-email-toolkit'),
                    '<strong>' . esc_html($email) . '</strong>'
                );
                ?>
            </p>
            <?php echo self::render_inputs($state); ?>
            <p class="lrob-etk-nl-prefs-submit">
                <button type="submit" name="lrob_etk_nl_prefs_save" value="1" class="lrob-etk-nl-prefs-primary">
                    <?php esc_html_e('Save preferences', 'lrob-email-toolkit'); ?>
                </button>
            </p>
            <?php if ($kind === UserMeta::KIND_SUBSCRIBER) : ?>
                <hr class="lrob-etk-nl-prefs-divider">
                <details class="lrob-etk-nl-prefs-danger">
                    <summary><?php esc_html_e('Leave entirely', 'lrob-email-toolkit'); ?></summary>
                    <p>
                        <?php esc_html_e('Unsubscribing soft-removes you — your row stays so admins can see what happened. "Forget me entirely" hard-deletes you and you can re-subscribe later as a brand-new visitor.', 'lrob-email-toolkit'); ?>
                    </p>
                    <p class="lrob-etk-nl-prefs-danger-actions">
                        <button type="submit" name="lrob_etk_nl_prefs_unsubscribe" value="1" class="lrob-etk-nl-prefs-secondary">
                            <?php esc_html_e('Unsubscribe from everything', 'lrob-email-toolkit'); ?>
                        </button>
                        <button type="submit" name="lrob_etk_nl_prefs_forget" value="1" class="lrob-etk-nl-prefs-secondary lrob-etk-nl-prefs-destructive"
                            onclick="return confirm(<?php echo wp_json_encode(__('Permanently delete your subscription? This cannot be undone.', 'lrob-email-toolkit')); ?>);">
                            <?php esc_html_e('Forget me entirely', 'lrob-email-toolkit'); ?>
                        </button>
                    </p>
                </details>
            <?php endif; ?>
        </form>
        <?php
        return (string) ob_get_clean();
    }
}
