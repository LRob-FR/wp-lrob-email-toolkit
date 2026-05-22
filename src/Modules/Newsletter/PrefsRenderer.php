<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * HTML renderer for the preferences UI. Two render modes:
 *
 *   - render_inputs() — bare form inputs (category + list checkboxes,
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
 *     'opt_outs'       => array<int, string>,     // category slugs the recipient HAS opted out of
 *     'list_member_ids'=> array<int, int>,        // list ids the recipient currently belongs to
 *     'categories'     => array<int, array{slug:string, name:string}>,
 *     'lists'          => array<int, array{id:int, name:string}>,
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
        $opt_outs = (array) ($state['opt_outs'] ?? []);
        $member_ids = array_map('intval', (array) ($state['list_member_ids'] ?? []));
        $categories = (array) ($state['categories'] ?? []);
        $lists = (array) ($state['lists'] ?? []);

        ob_start();
        ?>
        <div class="lrob-etk-nl-prefs-inputs">
            <?php if ($kind === UserMeta::KIND_USER) : ?>
                <fieldset class="lrob-etk-nl-prefs-master">
                    <legend><?php esc_html_e('Receive newsletter emails', 'lrob-email-toolkit'); ?></legend>
                    <label>
                        <input type="checkbox" name="lrob_etk_nl_opted_in" value="1" <?php checked($opted_in); ?>>
                        <?php esc_html_e('Yes, send me newsletter emails for the categories I select below.', 'lrob-email-toolkit'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Turning this off stops every category. To leave entirely, delete your WordPress account from your profile page.', 'lrob-email-toolkit'); ?>
                    </p>
                </fieldset>
            <?php endif; ?>

            <fieldset class="lrob-etk-nl-prefs-categories">
                <legend><?php esc_html_e('Email categories', 'lrob-email-toolkit'); ?></legend>
                <p class="description">
                    <?php esc_html_e('Tick the kinds of emails you want to receive.', 'lrob-email-toolkit'); ?>
                </p>
                <?php if ($categories === []) : ?>
                    <p><em><?php esc_html_e('No categories defined yet.', 'lrob-email-toolkit'); ?></em></p>
                <?php else : ?>
                    <ul class="lrob-etk-nl-prefs-checklist">
                        <?php foreach ($categories as $cat) : ?>
                            <?php
                            $slug = (string) ($cat['slug'] ?? '');
                            $name = (string) ($cat['name'] ?? $slug);
                            // Inverted logic: opt_outs contains the slugs
                            // the recipient does NOT want. The checkbox
                            // shows TICKED = wants, UNTICKED = opt-out.
                            $wanted = !in_array($slug, $opt_outs, true);
                            ?>
                            <li>
                                <label>
                                    <input type="checkbox" name="lrob_etk_nl_categories[]" value="<?php echo esc_attr($slug); ?>" <?php checked($wanted); ?>>
                                    <?php echo esc_html($name); ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </fieldset>

            <fieldset class="lrob-etk-nl-prefs-lists">
                <legend><?php esc_html_e('Subscriber lists', 'lrob-email-toolkit'); ?></legend>
                <?php if ($lists === []) : ?>
                    <p><em><?php esc_html_e('No lists to choose from yet.', 'lrob-email-toolkit'); ?></em></p>
                <?php else : ?>
                    <ul class="lrob-etk-nl-prefs-checklist">
                        <?php foreach ($lists as $list) : ?>
                            <?php
                            $id = (int) ($list['id'] ?? 0);
                            $name = (string) ($list['name'] ?? '');
                            if ($id <= 0 || $name === '') {
                                continue;
                            }
                            $member = in_array($id, $member_ids, true);
                            ?>
                            <li>
                                <label>
                                    <input type="checkbox" name="lrob_etk_nl_lists[]" value="<?php echo $id; ?>" <?php checked($member); ?>>
                                    <?php echo esc_html($name); ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </fieldset>
        </div>
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
