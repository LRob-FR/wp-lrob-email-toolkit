<?php
/**
 * Plugin Name: LRob - Email Toolkit
 * Plugin URI: https://www.lrob.fr/wordpress/plugins/lrob-email-toolkit/
 * Description: All-in-one modular email plugin: SMTP routing, email logging with IMAP "Save to Sent" archiving, contact forms, newsletters, and webhook integrations. Each module is independently activatable.
 * Version: 0.3.1
 * Author: LRob
 * Author URI: https://www.lrob.fr
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: lrob-email-toolkit
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LROB_ETK_VERSION', '0.3.1');
define('LROB_ETK_FILE', __FILE__);
define('LROB_ETK_PATH', plugin_dir_path(__FILE__));
define('LROB_ETK_URL', plugin_dir_url(__FILE__));
define('LROB_ETK_BASENAME', plugin_basename(__FILE__));
define('LROB_ETK_PLUGIN_URL', 'https://www.lrob.fr/wordpress/plugins/lrob-email-toolkit/');
define('LROB_ETK_GITHUB_URL', 'https://github.com/LRob-FR/wp-lrob-email-toolkit');
define('LROB_ETK_GITHUB_ISSUES_URL', LROB_ETK_GITHUB_URL . '/issues');

// PSR-4 autoloader: LRob\EmailToolkit\Foo\Bar -> src/Foo/Bar.php
spl_autoload_register(function (string $class): void {
    $prefix = 'LRob\\EmailToolkit\\';
    $base_dir = LROB_ETK_PATH . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative = substr($class, $len);
    $file = $base_dir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, [\LRob\EmailToolkit\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\LRob\EmailToolkit\Deactivator::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \LRob\EmailToolkit\Plugin::instance()->boot();
});
