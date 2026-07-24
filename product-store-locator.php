<?php
/**
 * Plugin Name:       Product Store Locator
 * Plugin URI:        https://example.com/product-store-locator
 * Description:       A Google Maps–based store locator. Shows all configured stores as map markers, supports ZIP/postcode search to recenter the map, and displays store details in marker info windows (no side list).
 * Version:           1.9.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Product Store Locator
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       product-store-locator
 * Domain Path:       /languages
 *
 * @package ProductStoreLocator
 */

namespace ProductStoreLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 */
define( 'PSL_VERSION', '1.9.1' );
define( 'PSL_PLUGIN_FILE', __FILE__ );
define( 'PSL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PSL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload-ish include of the plugin classes.
 * The plugin is small enough that a manual require keeps things explicit.
 */
require_once PSL_PLUGIN_DIR . 'includes/class-plugin.php';
require_once PSL_PLUGIN_DIR . 'includes/class-cpt.php';
require_once PSL_PLUGIN_DIR . 'includes/class-settings.php';
require_once PSL_PLUGIN_DIR . 'includes/class-metabox.php';
require_once PSL_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once PSL_PLUGIN_DIR . 'includes/class-block.php';
require_once PSL_PLUGIN_DIR . 'includes/class-api-guard.php';
require_once PSL_PLUGIN_DIR . 'includes/class-import-export.php';
require_once PSL_PLUGIN_DIR . 'includes/class-updater.php';

/**
 * Activation hook: register the CPT then flush rewrite rules.
 */
register_activation_hook(
	__FILE__,
	static function () {
		CPT::register_post_type();
		flush_rewrite_rules();
	}
);

/**
 * Deactivation hook: flush rewrite rules so the CPT slug is cleaned up.
 */
register_deactivation_hook(
	__FILE__,
	static function () {
		flush_rewrite_rules();
	}
);

/**
 * Boot the plugin.
 */
add_action(
	'plugins_loaded',
	static function () {
		Plugin::instance()->init();
	}
);
