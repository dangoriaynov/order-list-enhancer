<?php
/**
 * Plugin Name:       Ordelist - Order List Enhancer for WooCommerce
 * Plugin URI:        https://github.com/dangoriaynov/order-list-enhancer
 * Description:       Order-management tools for the WooCommerce admin: repeat-customer highlighting with duplicate flags, shipping-column & high-value coloring, checkout safeguards, and printed-consumables tracking - each with its own toggle on a tabbed settings page.
 * Version:           1.0.64
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Dan Goriaynov
 * Author URI:        https://github.com/dangoriaynov
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ordelist
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

define( 'ORDELIST_VERSION', '1.0.64' );
define( 'ORDELIST_FILE', __FILE__ );
define( 'ORDELIST_DIR', plugin_dir_path( __FILE__ ) );
define( 'ORDELIST_URL', plugin_dir_url( __FILE__ ) );

require_once ORDELIST_DIR . 'includes/class-ordelist-settings.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-settings-page.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-duplicates.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-shipping.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-order-color.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-phone.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-order-total.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-extras-matcher.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-extras.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-phone-validator.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-phone-checkout.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-delivery-notice.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-dup-guard.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-print-stock-calc.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-print-stock-store.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-print-stock.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-print-stock-admin.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-warranty-calc.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-warranty-store.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-warranty.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-warranty-admin.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-forecast-data.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-forecast-admin.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-order-comments.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-plugin.php';

// Declare HPOS (custom order tables) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ORDELIST_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		// Translations load just-in-time from /languages (text domain = plugin slug); no manual call needed.
		ORDELIST_Plugin::instance();
	}
);

// Прибрати щоденну перевірку термінів придатності при деактивації плагіна.
register_deactivation_hook( __FILE__, array( 'ORDELIST_Warranty', 'unschedule' ) );
