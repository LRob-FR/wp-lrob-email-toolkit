<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

use LRob\EmailToolkit\Modules\ModuleManager;

final class DashboardPage
{
    public function __construct(private ModuleManager $manager)
    {
    }

    public function render(): void
    {
        $modules = $this->manager->all();
        $modules_url = admin_url('admin.php?page=' . Menu::SLUG_MODULES);
        ?>
        <div class="wrap lrob-etk">
            <h1><?php esc_html_e('LRob — Email Toolkit', 'lrob-email-toolkit'); ?></h1>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: plugin version */
                    esc_html__('Modular email plugin — version %s.', 'lrob-email-toolkit'),
                    esc_html(LROB_ETK_VERSION)
                );
                ?>
            </p>

            <h2><?php esc_html_e('Modules', 'lrob-email-toolkit'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Module', 'lrob-email-toolkit'); ?></th>
                        <th><?php esc_html_e('Description', 'lrob-email-toolkit'); ?></th>
                        <th><?php esc_html_e('Status', 'lrob-email-toolkit'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modules as $module) :
                        $enabled = $this->manager->is_enabled($module->slug());
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($module->name()); ?></strong></td>
                            <td><?php echo esc_html($module->description()); ?></td>
                            <td>
                                <?php if ($enabled) : ?>
                                    <span class="lrob-etk-status lrob-etk-status--on">
                                        <?php esc_html_e('Enabled', 'lrob-email-toolkit'); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="lrob-etk-status lrob-etk-status--off">
                                        <?php esc_html_e('Disabled', 'lrob-email-toolkit'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <a href="<?php echo esc_url($modules_url); ?>" class="button button-primary">
                    <?php esc_html_e('Manage modules', 'lrob-email-toolkit'); ?>
                </a>
            </p>

            <p class="lrob-etk-footer">
                <?php
                printf(
                    /* translators: %s: link to lrob.fr */
                    wp_kses(
                        __('Built with care by %s.', 'lrob-email-toolkit'),
                        ['a' => ['href' => [], 'target' => [], 'rel' => []]]
                    ),
                    '<a href="https://www.lrob.fr" target="_blank" rel="noopener">LRob</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }
}
