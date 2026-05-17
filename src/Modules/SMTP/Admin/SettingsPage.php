<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP\Admin;

use LRob\EmailToolkit\Admin\ModuleToggle;
use LRob\EmailToolkit\Modules\ModuleInterface;
use LRob\EmailToolkit\Modules\SMTP\ConstantOverrides;
use LRob\EmailToolkit\Modules\SMTP\Identity;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Modules\SMTP\RoutingRules;
use LRob\EmailToolkit\Modules\SMTP\SourceResolver;

/**
 * SMTP module landing page. Three states:
 *   1. Disabled + no identities → CTA card asking the user to enable.
 *   2. Disabled with identities  → toggle bar + frozen list (so user can
 *      re-enable without losing config).
 *   3. Enabled                   → toggle bar + identities list + routing rules.
 */
final class SettingsPage
{
    public function __construct(
        private ModuleInterface $module,
        private IdentityRepository $identities,
        private RoutingRules $routing,
        private ConstantOverrides $overrides,
    ) {
    }

    public function render(): void
    {
        $notice = PageController::pop_flash('notice');
        $errors = PageController::pop_flash('errors');
        $identities = $this->identities->all();
        $enabled = $this->module->is_enabled();

        ?>
        <div class="wrap lrob-etk">
            <h1 class="lrob-etk-page-title"><?php esc_html_e('SMTP', 'lrob-email-toolkit'); ?></h1>

            <?php $this->render_flash($notice, $errors); ?>
            <?php $this->render_auth_key_warning(); ?>
            <?php $this->render_toggle_notice(); ?>

            <?php if (!$enabled && $identities === []) : ?>
                <?php
                ModuleToggle::render_cta(
                    $this->module,
                    __('Set up SMTP', 'lrob-email-toolkit'),
                    __('Route every outgoing email through your SMTP server. Configure one or more identities (a "From" address plus its SMTP login), then choose which sources use which.', 'lrob-email-toolkit')
                );
                ?>
            <?php else : ?>
                <?php ModuleToggle::render_bar($this->module); ?>

                <?php $this->render_toolbar(); ?>

                <?php if ($identities === []) : ?>
                    <div class="lrob-etk-empty">
                        <p><?php esc_html_e('No SMTP identities configured yet.', 'lrob-email-toolkit'); ?></p>
                        <p>
                            <a href="<?php echo esc_url($this->edit_url(0)); ?>" class="button button-primary">
                                <?php esc_html_e('Add your first identity', 'lrob-email-toolkit'); ?>
                            </a>
                        </p>
                    </div>
                <?php else : ?>
                    <?php $this->render_identities_table($identities); ?>
                <?php endif; ?>

                <?php if ($identities !== []) : ?>
                    <?php $this->render_routing_section($identities); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_toolbar(): void
    {
        $new_url = $this->edit_url(0);
        ?>
        <div class="lrob-etk-toolbar">
            <a href="<?php echo esc_url($new_url); ?>" class="button button-primary">
                <span class="dashicons dashicons-plus-alt2"></span>
                <?php esc_html_e('Add identity', 'lrob-email-toolkit'); ?>
            </a>
        </div>
        <?php
    }

    /** @param array<int, Identity> $identities */
    private function render_identities_table(array $identities): void
    {
        $action_url = admin_url('admin-post.php');
        ?>
        <table class="widefat striped lrob-etk-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Identity', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('From', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Server', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Default', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Actions', 'lrob-email-toolkit'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($identities as $identity) :
                    $edit_url = $this->edit_url($identity->id);
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($identity->label); ?></a></strong>
                            <div class="row-actions">
                                <span><code><?php echo esc_html($identity->slug); ?></code></span>
                            </div>
                        </td>
                        <td>
                            <?php echo esc_html($identity->from_name); ?>
                            <div class="row-actions"><?php echo esc_html($identity->from_email); ?></div>
                        </td>
                        <td>
                            <?php echo esc_html(sprintf('%s:%d', $identity->smtp_host, $identity->smtp_port)); ?>
                            <?php if ($identity->smtp_encryption !== '') : ?>
                                <div class="row-actions"><?php echo esc_html(strtoupper($identity->smtp_encryption)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($identity->is_default) : ?>
                                <span class="dashicons dashicons-yes" aria-label="<?php esc_attr_e('Default identity', 'lrob-email-toolkit'); ?>"></span>
                            <?php else : ?>
                                <form method="post" action="<?php echo esc_url($action_url); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_SET_DEFAULT); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $identity->id; ?>">
                                    <?php wp_nonce_field(PageController::ACTION_SET_DEFAULT, '_lrob_etk_nonce'); ?>
                                    <button type="submit" class="button-link"><?php esc_html_e('Set default', 'lrob-email-toolkit'); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($identity->is_active) : ?>
                                <span class="lrob-etk-status lrob-etk-status--on"><?php esc_html_e('Active', 'lrob-email-toolkit'); ?></span>
                            <?php else : ?>
                                <span class="lrob-etk-status lrob-etk-status--off"><?php esc_html_e('Inactive', 'lrob-email-toolkit'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">
                                <?php esc_html_e('Edit', 'lrob-email-toolkit'); ?>
                            </a>
                            <form method="post" action="<?php echo esc_url($action_url); ?>" style="display:inline"
                                  onsubmit="return confirm('<?php echo esc_js(__('Delete this identity?', 'lrob-email-toolkit')); ?>');">
                                <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_DELETE); ?>">
                                <input type="hidden" name="id" value="<?php echo (int) $identity->id; ?>">
                                <?php wp_nonce_field(PageController::ACTION_DELETE, '_lrob_etk_nonce'); ?>
                                <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Delete', 'lrob-email-toolkit'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /** @param array<int, Identity> $identities */
    private function render_routing_section(array $identities): void
    {
        if (count($identities) < 2) {
            return;  // single identity → routing is trivial, hide it
        }
        $sources = $this->known_sources();
        $rules = $this->routing->all();
        $action_url = admin_url('admin-post.php');
        ?>
        <h2 class="lrob-etk-section-title"><?php esc_html_e('Routing rules', 'lrob-email-toolkit'); ?></h2>
        <p class="description">
            <?php esc_html_e('Choose which identity to use for each kind of outgoing email. Unset sources fall back to the default identity.', 'lrob-email-toolkit'); ?>
        </p>
        <form method="post" action="<?php echo esc_url($action_url); ?>" class="lrob-etk-routing-form">
            <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_SAVE_ROUTING); ?>">
            <?php wp_nonce_field(PageController::ACTION_SAVE_ROUTING, '_lrob_etk_nonce'); ?>

            <div class="lrob-etk-routing-grid">
                <?php foreach ($sources as $source => $label) :
                    $current = $rules[$source] ?? '';
                    ?>
                    <div class="lrob-etk-routing-row">
                        <label for="lrob-etk-route-<?php echo esc_attr($source); ?>">
                            <strong><?php echo esc_html($label); ?></strong>
                            <code><?php echo esc_html($source); ?></code>
                        </label>
                        <select id="lrob-etk-route-<?php echo esc_attr($source); ?>" name="routing[<?php echo esc_attr($source); ?>]">
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

            <p class="submit">
                <?php submit_button(__('Save routing rules', 'lrob-email-toolkit'), 'primary', 'submit', false); ?>
            </p>
        </form>
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

    private function edit_url(int $id): string
    {
        return add_query_arg(
            ['page' => PageController::SLUG, 'action' => 'edit', 'id' => $id],
            admin_url('admin.php')
        );
    }

    /**
     * @param string|array<int, string>|null $notice
     * @param string|array<int, string>|null $errors
     */
    private function render_flash($notice, $errors): void
    {
        if (is_string($notice) && $notice !== '') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
        }
        if (is_array($errors)) {
            foreach ($errors as $error) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html((string) $error) . '</p></div>';
            }
        }
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
            ? __('SMTP routing is now active.', 'lrob-email-toolkit')
            : __('SMTP routing is now off. Your configuration is preserved.', 'lrob-email-toolkit');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
