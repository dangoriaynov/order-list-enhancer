<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'ordelist_settings' );
delete_option( 'ordelist_bulk_actions' );
delete_option( 'ordelist_warranty_db' );
delete_option( 'ordelist_print_stock_db' );
delete_option( 'ordelist_fc_tuning' );
