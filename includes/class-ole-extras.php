<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Перетворює зіставлені екстри (Product Add-Ons / Checkout Add-Ons) на окремі товарні рядки
 * при створенні замовлення. Чиста логіка — в [[OLE_Extras_Matcher]].
 */
class OLE_Extras {

	/** Реєстрація хуків (викликається з OLE_Plugin, якщо фіча увімкнена). */
	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_processed' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order_processed' ), 20, 1 );
	}

	public static function on_order_processed( $order ) {
		$order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order );
		if ( $order ) {
			self::convert( $order );
		}
	}

	/**
	 * Головна конвертація. Повертає к-сть перетворених екстр. Ідемпотентна.
	 */
	public static function convert( WC_Order $order ) {
		if ( $order->get_meta( '_ole_extras_converted' ) ) {
			return 0;
		}
		$opts = OLE_Settings::get();
		if ( ! OLE_Settings::is_yes( $opts, 'extras_enabled' ) ) {
			return 0;
		}
		$index = OLE_Extras_Matcher::index( $opts['extras_map'] );
		if ( empty( $index ) ) {
			return 0;
		}

		$notes = array();
		$count = self::convert_product_addons( $order, $index, $notes );
		$count += self::convert_checkout_addons( $order, $index, $notes );

		if ( $count > 0 ) {
			$order->add_order_note( __( 'OLE — extras converted to product lines:', 'order-list-enhancer' ) . "\n" . implode( "\n", $notes ) );
			$order->update_meta_data( '_ole_extras_converted', 1 );
			$order->save();
		}
		return $count;
	}

	/** Конвертує Product Add-Ons на товарних рядках. */
	private static function convert_product_addons( WC_Order $order, $index, &$notes ) {
		$count = 0;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$pao = $item->get_meta( '_pao_ids' );
			if ( ! is_array( $pao ) || empty( $pao ) ) {
				continue;
			}
			$pao       = array_values( $pao );
			$addons    = OLE_Extras_Matcher::parse_addons( $pao );
			$pao_total = (float) $item->get_meta( '_pao_total' );

			// Safety: only proceed if the parsed add-on prices reconcile with _pao_total.
			if ( ! OLE_Extras_Matcher::prices_balance( $addons, $pao_total ) ) {
				continue;
			}

			$keep_pao   = array();   // _pao_ids entries to keep (unconverted)
			$moved      = array();   // provenance for the parent
			$drop_label = array();   // visible field=>label rows to drop

			foreach ( $addons as $idx => $a ) {
				$pid = OLE_Extras_Matcher::match( $index, $a['label'] );
				if ( ! $pid || 'flat_fee' !== $a['price_type'] ) {
					$keep_pao[] = $pao[ $idx ];
					continue;
				}
				$price   = (float) $a['price'];
				$new_id  = self::add_product_line( $order, $pid, $price, array(
					'source'   => 'pa',
					'label'    => $a['label'],
					'price'    => $price,
					'src_item' => $item_id,
				) );
				if ( 0 === $new_id ) {
					$keep_pao[] = $pao[ $idx ];
					continue;
				}
				// Reduce parent line by the add-on price.
				$item->set_subtotal( (float) $item->get_subtotal() - $price );
				$item->set_total( (float) $item->get_total() - $price );
				$drop_label[ $a['field'] ][] = $a['label'];
				$moved[] = array( 'label' => $a['label'], 'price' => $price, 'item' => $new_id );
				$notes[] = sprintf( '«%s» → %s (%s)', $a['label'], self::product_name( $pid ), self::money( $price, $order ) );
				++$count;
			}

			if ( empty( $moved ) ) {
				continue;
			}

			// Rewrite _pao_ids / _pao_total to keep only unconverted add-ons.
			$kept_total = 0.0;
			foreach ( $keep_pao as $k ) {
				$kept_total += isset( $k['raw_price'] ) ? (float) $k['raw_price'] : 0.0;
			}
			if ( $keep_pao ) {
				$item->update_meta_data( '_pao_ids', array_values( $keep_pao ) );
				$item->update_meta_data( '_pao_total', $kept_total );
			} else {
				$item->delete_meta_data( '_pao_ids' );
				$item->delete_meta_data( '_pao_total' );
			}

			// Remove the visible field=>label rows for converted add-ons (keep unconverted values).
			foreach ( $drop_label as $field => $converted_vals ) {
				$remaining = array();
				foreach ( $item->get_meta( $field, false ) as $meta ) {
					if ( ! in_array( (string) $meta->value, $converted_vals, true ) ) {
						$remaining[] = (string) $meta->value;
					}
				}
				$item->delete_meta_data( $field );
				foreach ( $remaining as $v ) {
					$item->add_meta_data( $field, $v, false );
				}
			}

			// Provenance for admin display (hidden from invoices).
			$item->update_meta_data( '_ole_extra_moved', $moved );
			$item->save();
		}
		return $count;
	}

	/** Додає новий товарний рядок із заданою ціною та provenance-метою. */
	public static function add_product_line( WC_Order $order, $product_id, $price, $origin ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return 0;
		}
		$line = new WC_Order_Item_Product();
		$line->set_product( $product );
		$line->set_quantity( 1 );
		$line->set_subtotal( (float) $price );
		$line->set_total( (float) $price );
		$line->set_subtotal_tax( 0 );
		$line->set_total_tax( 0 );
		$line->add_meta_data( '_ole_addon_origin', $origin, true );
		$order->add_item( $line );
		$line->save();

		// If stock was already reduced for this order, reduce the new product manually.
		if ( 'yes' === $order->get_meta( '_order_stock_reduced' ) && $product->managing_stock() ) {
			wc_update_product_stock( $product, 1, 'decrease' );
		}
		return $line->get_id();
	}

	private static function product_name( $product_id ) {
		$p = wc_get_product( $product_id );
		return $p ? wp_strip_all_tags( $p->get_formatted_name() ) : ( '#' . (int) $product_id );
	}

	private static function money( $amount, WC_Order $order ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ) );
	}

	/** Конвертує Checkout Add-Ons (fee-рядки). */
	private static function convert_checkout_addons( WC_Order $order, $index, &$notes ) {
		$count = 0;
		foreach ( $order->get_items( 'fee' ) as $fee_id => $fee ) {
			$pid = OLE_Extras_Matcher::match( $index, $fee->get_name() );
			if ( ! $pid ) {
				continue;
			}
			$price  = (float) $fee->get_total();
			$new_id = self::add_product_line( $order, $pid, $price, array(
				'source'   => 'ca',
				'label'    => $fee->get_name(),
				'price'    => $price,
				'src_item' => $fee->get_name(),
			) );
			if ( 0 === $new_id ) {
				continue;
			}
			$order->remove_item( $fee_id );
			$notes[] = sprintf( '«%s» → %s (%s)', $fee->get_name(), self::product_name( $pid ), self::money( $price, $order ) );
			++$count;
		}
		return $count;
	}
}
