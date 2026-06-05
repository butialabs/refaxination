<?php

/**
 * Plugin Name:       Refaxination
 * Plugin URI:        https://github.com/butialabs/refaxination
 * Description:       Audits and manages orphaned files in Uploads. All operations via WP-CLI.
 * Version:           0.0.1
 * Requires at least: 6.5
 * Tested up to:      7.0
 * Requires PHP:      8.2
 * Author:            Butiá Labs
 * Author URI:        https://butialabs.com
 * License:           GPL-2.0-or-later
 * Text Domain:       refaxination
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('REFAXINATION_VERSION', '0.0.1');
define('REFAXINATION_DIR', plugin_dir_path(__FILE__));
define('REFAXINATION_URL', plugin_dir_url(__FILE__));

require_once REFAXINATION_DIR . 'vendor/autoload.php';

register_activation_hook(__FILE__, static function (): void {
    \Refaxination\Database::install();
});

add_action('plugins_loaded', static function (): void {
    \Refaxination\Refaxination::getInstance()->init();
});
