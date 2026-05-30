<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP\Admin;

use LRob\EmailToolkit\Admin\PageHeader;
use LRob\EmailToolkit\Admin\Tooltip;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\SMTP\ConstantOverrides;
use LRob\EmailToolkit\Modules\SMTP\Identity;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Modules\SMTP\RoutingRules;
use LRob\EmailToolkit\Modules\SMTP\SourceResolver;

// Docs: docs/smtp.md
final class SettingsPage
{
    private IdentityCardRenderer $cards;

    public function __construct(
        private ModuleInterface $module,
        private IdentityRepository $identities,
        private RoutingRules $routing,
        private ConstantOverrides $overrides,
    ) {
        $this->cards = new IdentityCardRenderer($overrides);
    }

    public function render(): void
    {
        $identities = $this->identities->all();
        $enabled = $this->module->is_enabled();

        ?>
        <div class="wrap lrob-etk lrob-etk-smtp-page">
            <?php PageHeader::render([
                'title'   => __('SMTP', 'lrob-email-toolkit'),
                'module'  => $this->module,
                'primary' => [
                    'label' => __('New identity', 'lrob-email-toolkit'),
                    'icon'  => 'dashicons-plus-alt2',
                    'id'    => 'lrob-etk-add-identity',
                ],
            ]); ?>

            <div id="lrob-etk-flash" class="lrob-etk-flash" aria-live="polite"></div>

            <?php $this->render_toggle_notice(); ?>
            <?php $this->render_auth_key_warning(); ?>

            <?php if (!$enabled) : ?>
                <p class="lrob-etk-disabled-message">
                    <?php esc_html_e('Enable the SMTP module to start managing your outgoing emails with LRob — Email Toolkit.', 'lrob-email-toolkit'); ?>
                </p>
            <?php else : ?>
                <?php if ($identities === []) : ?>
                    <div class="lrob-etk-empty">
                        <p><?php esc_html_e('No SMTP identities yet — click "New identity" to start.', 'lrob-email-toolkit'); ?></p>
                    </div>
                <?php else : ?>
                    <div class="lrob-etk-card-grid">
                        <?php foreach ($identities as $identity) : ?>
                            <?php $this->render_identity_card($identity); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php $this->render_routing_section($identities); ?>
            <?php endif; ?>

            <?php $this->render_card_template(); ?>
            <?php $this->render_test_send_modal($identities); ?>
            <?php $this->render_conn_popover(); ?>
        </div>
        <?php
    }

    private function render_identity_card(Identity $identity): void
    {
        ?>
        <article class="lrob-etk-card lrob-etk-card--container lrob-etk-identity-card<?php echo $identity->is_active ? '' : ' lrob-etk-is-dimmed'; ?>" data-identity-id="<?php echo (int) $identity->id; ?>" data-state="existing">
            <?php $this->cards->render($identity); ?>
        </article>
        <?php
    }

    private function render_card_template(): void
    {
        ?>
        <template id="lrob-etk-card-template">
            <article class="lrob-etk-card lrob-etk-card--container lrob-etk-identity-card is-new" data-identity-id="0" data-state="new">
                <?php $this->cards->render(null); ?>
            </article>
        </template>
        <?php
    }

    /** @param array<int, Identity> $identities */
    private function render_routing_section(array $identities): void
    {
        if (count($identities) < 2) {
            return;
        }
        $sources = $this->known_sources();
        $rules = $this->routing->all();
        ?>
        <h2 class="lrob-etk-section-title">
            <?php esc_html_e('Routing rules', 'lrob-email-toolkit'); ?>
            <?php Tooltip::render(__('Each source can use a specific identity. Sources without a rule fall back to the default.', 'lrob-email-toolkit')); ?>
        </h2>
        <form id="lrob-etk-routing-form" class="lrob-etk-routing-form">
            <div class="lrob-etk-routing-grid">
                <?php foreach ($sources as $source => $label) :
                    $current = $rules[$source] ?? '';
                    ?>
                    <div class="lrob-etk-routing-row">
                        <label>
                            <strong><?php echo esc_html($label); ?></strong>
                            <code><?php echo esc_html($source); ?></code>
                        </label>
                        <select name="routing[<?php echo esc_attr($source); ?>]">
                            <option value=""><?php esc_html_e('— Use default —', 'lrob-email-toolkit'); ?></option>
                            <?php foreach ($identities as $identity) : ?>
                                <option value="<?php echo esc_attr($identity->slug); ?>" <?php selected($current, $identity->slug); ?>>
                                    <?php echo esc_html($identity->label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
            <p>
                <button type="submit" class="button button-secondary"><?php esc_html_e('Save routing rules', 'lrob-email-toolkit'); ?></button>
            </p>
        </form>
        <?php
    }

    /** @param array<int, Identity> $identities */
    private function render_test_send_modal(array $identities): void
    {
        $current_user = wp_get_current_user();
        $admin_email = (string) get_option('admin_email');
        ?>
        <div class="lrob-etk-modal" id="lrob-etk-test-modal" role="dialog" aria-modal="true" hidden>
            <div class="lrob-etk-modal-backdrop" data-lrob-etk-close></div>
            <div class="lrob-etk-modal-dialog lrob-etk-modal-dialog--small" role="document">
                <header class="lrob-etk-modal-header">
                    <h2 class="lrob-etk-modal-title-text"><?php esc_html_e('Send a test email', 'lrob-email-toolkit'); ?></h2>
                    <button type="button" class="lrob-etk-modal-close" data-lrob-etk-close aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </header>

                <form id="lrob-etk-test-form" class="lrob-etk-modal-body">
                    <input type="hidden" id="lrob-etk-test-id" value="0">

                    <?php if (count($identities) > 1) : ?>
                        <div class="lrob-etk-field">
                            <label for="lrob-etk-test-identity-pick"><?php esc_html_e('Identity', 'lrob-email-toolkit'); ?></label>
                            <select id="lrob-etk-test-identity-pick">
                                <?php foreach ($identities as $identity) : ?>
                                    <option value="<?php echo (int) $identity->id; ?>" <?php selected($identity->is_default); ?>>
                                        <?php echo esc_html($identity->label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="lrob-etk-field">
                        <label for="lrob-etk-test-recipient-choice"><?php esc_html_e('Recipient', 'lrob-email-toolkit'); ?></label>
                        <select id="lrob-etk-test-recipient-choice">
                            <?php /* translators: %s: current user's email address */ ?>
                            <option value="current"><?php echo esc_html(sprintf(__('Me (%s)', 'lrob-email-toolkit'), $current_user->user_email)); ?></option>
                            <?php /* translators: %s: site admin email address */ ?>
                            <option value="admin"><?php echo esc_html(sprintf(__('Site admin (%s)', 'lrob-email-toolkit'), $admin_email)); ?></option>
                            <option value="custom"><?php esc_html_e('Custom address…', 'lrob-email-toolkit'); ?></option>
                        </select>
                    </div>

                    <div class="lrob-etk-field" id="lrob-etk-test-custom-wrap" hidden>
                        <label for="lrob-etk-test-recipient-custom"><?php esc_html_e('Custom recipient', 'lrob-email-toolkit'); ?></label>
                        <input type="email" id="lrob-etk-test-recipient-custom" placeholder="you@example.com">
                    </div>

                    <div id="lrob-etk-test-result" class="lrob-etk-test-result" hidden></div>
                </form>

                <footer class="lrob-etk-modal-footer">
                    <button type="button" class="button" data-lrob-etk-close><?php esc_html_e('Cancel', 'lrob-email-toolkit'); ?></button>
                    <button type="button" class="button button-primary" id="lrob-etk-send-test">
                        <?php esc_html_e('Send test', 'lrob-email-toolkit'); ?>
                    </button>
                </footer>
            </div>
        </div>
        <?php
    }

    private function render_conn_popover(): void
    {
        ?>
        <div class="lrob-etk-popover" id="lrob-etk-conn-popover" role="dialog" aria-label="<?php esc_attr_e('Connection test result', 'lrob-email-toolkit'); ?>" hidden>
            <header class="lrob-etk-popover-header">
                <h3><?php esc_html_e('Connection test', 'lrob-email-toolkit'); ?></h3>
                <button type="button" class="lrob-etk-popover-close" aria-label="<?php esc_attr_e('Close', 'lrob-email-toolkit'); ?>"
                        onclick="this.closest('.lrob-etk-popover').hidden = true">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </header>
            <div class="lrob-etk-popover-body">
                <p class="lrob-etk-popover-message"></p>
                <pre class="lrob-etk-popover-debug" hidden></pre>
            </div>
            <footer class="lrob-etk-popover-footer">
                <button type="button" class="button button-primary lrob-etk-popover-rerun">
                    <?php esc_html_e('Run again', 'lrob-email-toolkit'); ?>
                </button>
            </footer>
        </div>
        <?php
    }

    /** @return array<string, string> */
    private function known_sources(): array
    {
        $sources = [
            SourceResolver::SOURCE_DEFAULT => __('Default emails', 'lrob-email-toolkit'),
        ];
        if (class_exists('WooCommerce')) {
            $sources[SourceResolver::SOURCE_WOOCOMMERCE] = __('WooCommerce emails', 'lrob-email-toolkit');
        }
        return apply_filters('lrob_etk_smtp_known_sources', $sources);
    }

    private function render_auth_key_warning(): void
    {
        if (\LRob\EmailToolkit\Support\Encryption::is_available()) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('AUTH_KEY is missing or unset in wp-config.php.', 'lrob-email-toolkit'); ?></strong>
                <?php esc_html_e('SMTP passwords cannot be encrypted at rest until you configure AUTH_KEY. Generate one at https://api.wordpress.org/secret-key/1.1/salt/ and add it to wp-config.php.', 'lrob-email-toolkit'); ?>
            </p>
        </div>
        <?php
    }

    private function render_toggle_notice(): void
    {
        if (!isset($_GET['toggled'])) {
            return;
        }
        $toggled = sanitize_key((string) $_GET['toggled']);
        $message = $toggled === 'on'
            ? __('Email routing is now active.', 'lrob-email-toolkit')
            : __('Email routing is now off. Your configuration is preserved.', 'lrob-email-toolkit');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
