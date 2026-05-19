<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Admin;

use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\ModuleInterface;

/**
 * Captcha settings page. One choice for now (which challenge is active);
 * future providers (hCaptcha, Turnstile, reCAPTCHA) plug in here with their
 * own config sub-sections.
 */
final class SettingsPage
{
    public function __construct(
        private ModuleInterface $module,
        private CaptchaService $service,
    ) {
    }

    public function render(): void
    {
        $available = $this->service->available();
        $active = $this->service->active_slug();
        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
        ?>
        <div class="lrob-etk wrap">
            <div class="lrob-etk-page-header">
                <h1 class="lrob-etk-page-title"><?php esc_html_e('Captcha', 'lrob-email-toolkit'); ?></h1>
            </div>

            <?php if ($saved) : ?>
                <div class="lrob-etk-flash"><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Captcha settings saved.', 'lrob-email-toolkit'); ?></p></div></div>
            <?php endif; ?>

            <p class="description" style="max-width: 720px;">
                <?php esc_html_e('Pick which anti-bot challenge is presented across the toolkit. Contact forms, newsletter sign-ups, and other modules use this single setting so visitors see one consistent prompt.', 'lrob-email-toolkit'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_SAVE); ?>">
                <?php wp_nonce_field(PageController::ACTION_SAVE, '_lrob_etk_nonce'); ?>

                <div class="lrob-etk-form-section" style="background:#fff; border:1px solid var(--etk-line); border-radius: var(--etk-radius); padding: 16px 20px; max-width: 720px;">
                    <h2 style="margin: 0 0 12px; font-size: 14px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--etk-muted); font-weight: 600;">
                        <?php esc_html_e('Active challenge', 'lrob-email-toolkit'); ?>
                    </h2>

                    <?php foreach ($available as $slug => $challenge) : ?>
                        <label style="display:block; padding: 10px 0; border-bottom: 1px solid var(--etk-soft); cursor: pointer;">
                            <input type="radio" name="active_challenge" value="<?php echo esc_attr($slug); ?>" <?php checked($active, $slug); ?>>
                            <strong style="margin-left: 6px;"><?php echo esc_html($challenge->label()); ?></strong>
                            <p style="margin: 4px 0 0 24px; color: var(--etk-muted); font-size: 12px;">
                                <?php echo esc_html($challenge->description()); ?>
                            </p>
                        </label>
                    <?php endforeach; ?>

                    <label style="display:block; padding: 10px 0; cursor: pointer;">
                        <input type="radio" name="active_challenge" value="<?php echo esc_attr(CaptchaService::SLUG_NONE); ?>" <?php checked($active, CaptchaService::SLUG_NONE); ?>>
                        <strong style="margin-left: 6px;"><?php esc_html_e('No challenge (not recommended)', 'lrob-email-toolkit'); ?></strong>
                        <p style="margin: 4px 0 0 24px; color: var(--etk-muted); font-size: 12px;">
                            <?php esc_html_e('Public-facing forms become more vulnerable to spam bots. Other anti-spam layers (honeypot, time-trap, rate-limit) stay active.', 'lrob-email-toolkit'); ?>
                        </p>
                    </label>
                </div>

                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save', 'lrob-email-toolkit'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }
}
