<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP\Admin;

use LRob\EmailToolkit\Modules\SMTP\ConstantOverrides;
use LRob\EmailToolkit\Modules\SMTP\Identity;
use LRob\EmailToolkit\Modules\SMTP\IdentityRepository;
use LRob\EmailToolkit\Modules\SMTP\RoutingRules;
use LRob\EmailToolkit\Modules\SMTP\SourceResolver;

/**
 * Identities list + routing rules. Renders inside admin.php?page=lrob-etk-smtp.
 */
final class SettingsPage
{
    public function __construct(
        private IdentityRepository $identities,
        private RoutingRules $routing,
        private ConstantOverrides $overrides,
    ) {
    }

    public function render(): void
    {
        $identities = $this->identities->all();
        $sources = $this->known_sources();
        $rules = $this->routing->all();
        $action_url = admin_url('admin-post.php');
        $edit_url_new = add_query_arg(
            ['page' => PageController::SLUG, 'action' => 'edit', 'id' => 0],
            admin_url('admin.php')
        );

        $notice = PageController::pop_flash('notice');
        $errors = PageController::pop_flash('errors');
        ?>
        <div class="wrap lrob-etk">
            <h1 class="wp-heading-inline">
                <?php esc_html_e('Email Toolkit — SMTP', 'lrob-email-toolkit'); ?>
            </h1>
            <a href="<?php echo esc_url($edit_url_new); ?>" class="page-title-action">
                <?php esc_html_e('Add identity', 'lrob-email-toolkit'); ?>
            </a>
            <hr class="wp-header-end">

            <?php $this->render_flash($notice, $errors); ?>
            <?php $this->render_auth_key_warning(); ?>

            <h2><?php esc_html_e('SMTP identities', 'lrob-email-toolkit'); ?></h2>

            <?php if ($identities === []) : ?>
                <div class="lrob-etk-empty">
                    <p>
                        <?php esc_html_e('No SMTP identities configured yet.', 'lrob-email-toolkit'); ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url($edit_url_new); ?>" class="button button-primary">
                            <?php esc_html_e('Create your first identity', 'lrob-email-toolkit'); ?>
                        </a>
                    </p>
                </div>
            <?php else : ?>
                <?php $this->render_identities_table($identities, $action_url); ?>
            <?php endif; ?>

            <?php if (count($identities) >= 1) : ?>
                <h2><?php esc_html_e('Routing rules', 'lrob-email-toolkit'); ?></h2>
                <p class="description">
                    <?php esc_html_e(
                        'Choose which identity to use for each kind of outgoing email. Unset sources fall back to the default identity.',
                        'lrob-email-toolkit'
                    ); ?>
                </p>
                <?php $this->render_routing_form($sources, $rules, $identities, $action_url); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<int, Identity> $identities
     */
    private function render_identities_table(array $identities, string $action_url): void
    {
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Label', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Slug', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('From', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Server', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Default', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                    <th><?php esc_html_e('Actions', 'lrob-email-toolkit'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($identities as $identity) :
                    $edit_url = add_query_arg(
                        ['page' => PageController::SLUG, 'action' => 'edit', 'id' => $identity->id],
                        admin_url('admin.php')
                    );
                    ?>
                    <tr>
                        <td><strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($identity->label); ?></a></strong></td>
                        <td><code><?php echo esc_html($identity->slug); ?></code></td>
                        <td>
                            <?php echo esc_html($identity->from_name); ?>
                            &lt;<?php echo esc_html($identity->from_email); ?>&gt;
                        </td>
                        <td>
                            <?php echo esc_html(sprintf('%s:%d', $identity->smtp_host, $identity->smtp_port)); ?>
                            <?php if ($identity->smtp_encryption !== '') : ?>
                                <small>(<?php echo esc_html(strtoupper($identity->smtp_encryption)); ?>)</small>
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
                                    <button type="submit" class="button-link"><?php esc_html_e('Set as default', 'lrob-email-toolkit'); ?></button>
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
                            <form method="post" action="<?php echo esc_url($action_url); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js(__('Delete this identity?', 'lrob-email-toolkit')); ?>');">
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

    /**
     * @param array<string, string>     $sources    source name → human label
     * @param array<string, string>     $rules      stored source → identity slug
     * @param array<int, Identity>      $identities
     */
    private function render_routing_form(array $sources, array $rules, array $identities, string $action_url): void
    {
        ?>
        <form method="post" action="<?php echo esc_url($action_url); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(PageController::ACTION_SAVE_ROUTING); ?>">
            <?php wp_nonce_field(PageController::ACTION_SAVE_ROUTING, '_lrob_etk_nonce'); ?>

            <table class="form-table">
                <tbody>
                    <?php foreach ($sources as $source => $label) :
                        $current = $rules[$source] ?? '';
                        ?>
                        <tr>
                            <th scope="row">
                                <label for="lrob-etk-route-<?php echo esc_attr($source); ?>">
                                    <?php echo esc_html($label); ?>
                                </label>
                                <p class="description"><code><?php echo esc_html($source); ?></code></p>
                            </th>
                            <td>
                                <select id="lrob-etk-route-<?php echo esc_attr($source); ?>" name="routing[<?php echo esc_attr($source); ?>]">
                                    <option value=""><?php esc_html_e('— Use default identity —', 'lrob-email-toolkit'); ?></option>
                                    <?php foreach ($identities as $identity) : ?>
                                        <option
                                            value="<?php echo esc_attr($identity->slug); ?>"
                                            <?php selected($current, $identity->slug); ?>
                                        ><?php echo esc_html($identity->label); ?> (<?php echo esc_html($identity->slug); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button(__('Save routing rules', 'lrob-email-toolkit')); ?>
        </form>
        <?php
    }

    /** @return array<string, string> source name → human label */
    private function known_sources(): array
    {
        $sources = [
            SourceResolver::SOURCE_DEFAULT => __('Default — any email not matching another source', 'lrob-email-toolkit'),
        ];
        if (class_exists('WooCommerce')) {
            $sources[SourceResolver::SOURCE_WOOCOMMERCE] = __('WooCommerce emails', 'lrob-email-toolkit');
        }
        return apply_filters('lrob_etk_smtp_known_sources', $sources);
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
                <?php esc_html_e(
                    'SMTP passwords cannot be encrypted at rest until you configure AUTH_KEY. Please generate one at https://api.wordpress.org/secret-key/1.1/salt/ and add it to wp-config.php.',
                    'lrob-email-toolkit'
                ); ?>
            </p>
        </div>
        <?php
    }
}
