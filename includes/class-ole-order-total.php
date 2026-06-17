<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Показує сумарну ціну замовлення біля адреси на екрані редагування поръчки.
 * Хук: woocommerce_admin_order_data_after_billing_address (передає WC_Order).
 */
class OLE_Order_Total {

	public static function render( $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			return;
		}
		$opts = OLE_Settings::get();
		if ( ! OLE_Settings::is_yes( $opts, 'total_on_edit' ) ) {
			return;
		}
		echo '<p class="ole-order-total" style="font-size:1.3em;font-weight:600;margin:10px 0 0;">'
			. wp_kses_post( $order->get_formatted_order_total() )
			. '</p>';
	}
}
