<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Показує сумарну ціну замовлення біля адреси на екрані редагування поръчки.
 * Хук: woocommerce_admin_order_data_after_billing_address (передає WC_Order).
 */
class ORDELIST_Order_Total {

	public static function render( $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			return;
		}
		$opts = ORDELIST_Settings::get();
		if ( ! ORDELIST_Settings::is_yes( $opts, 'total_on_edit' ) ) {
			return;
		}

		$html = $order->get_formatted_order_total();

		// Десетичен разделител по избор - сменяме само разделителя МЕЖДУ цифри,
		// за да не пипнем валутния символ (напр. „лв.").
		$sep    = $opts['total_decimal_sep'];
		$wc_dec = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';
		if ( $sep !== $wc_dec ) {
			$html = preg_replace( '/(\d)' . preg_quote( $wc_dec, '/' ) . '(\d)/', '${1}' . $sep . '${2}', $html );
		}

		echo '<p class="ole-order-total" style="font-size:1.3em;font-weight:600;margin:10px 0 0;">'
			. wp_kses_post( $html )
			. '</p>';
	}
}
