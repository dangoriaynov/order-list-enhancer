<?php
/**
 * Plugin Name:       Order List Enhancer
 * Plugin URI:        https://github.com/dangoriaynov/order-list-enhancer
 * Description:       Order-management tools for the WooCommerce admin: repeat-customer highlighting with duplicate flags, shipping-column & high-value coloring, checkout safeguards, and printed-consumables tracking — each with its own toggle on a tabbed settings page.
 * Version:           1.0.33
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Dan Goriaynov
 * Author URI:        https://github.com/dangoriaynov
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       order-list-enhancer
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   9.3
 *
 * @package OrderListEnhancer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OLE_VERSION', '1.0.33' );
define( 'OLE_FILE', __FILE__ );
define( 'OLE_DIR', plugin_dir_path( __FILE__ ) );
define( 'OLE_URL', plugin_dir_url( __FILE__ ) );

require_once OLE_DIR . 'includes/class-ole-settings.php';
require_once OLE_DIR . 'includes/class-ole-settings-page.php';
require_once OLE_DIR . 'includes/class-ole-duplicates.php';
require_once OLE_DIR . 'includes/class-ole-shipping.php';
require_once OLE_DIR . 'includes/class-ole-order-color.php';
require_once OLE_DIR . 'includes/class-ole-phone.php';
require_once OLE_DIR . 'includes/class-ole-order-total.php';
require_once OLE_DIR . 'includes/class-ole-extras-matcher.php';
require_once OLE_DIR . 'includes/class-ole-extras.php';
require_once OLE_DIR . 'includes/class-ole-phone-validator.php';
require_once OLE_DIR . 'includes/class-ole-phone-checkout.php';
require_once OLE_DIR . 'includes/class-ole-delivery-notice.php';
require_once OLE_DIR . 'includes/class-ole-dup-guard.php';
require_once OLE_DIR . 'includes/class-ole-print-stock-calc.php';
require_once OLE_DIR . 'includes/class-ole-print-stock-store.php';
require_once OLE_DIR . 'includes/class-ole-print-stock.php';
require_once OLE_DIR . 'includes/class-ole-print-stock-admin.php';
require_once OLE_DIR . 'includes/class-ole-plugin.php';

// Declare HPOS (custom order tables) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OLE_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		// Translations load just-in-time from /languages (text domain = plugin slug); no manual call needed.
		OLE_Plugin::instance();
	}
);
