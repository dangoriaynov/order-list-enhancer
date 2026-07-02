<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-glue обліку витратних: звірка consume/restore за станом замовлення + сповіщення.
 */
class OLE_Print_Stock {

	const STATE_META    = '_ole_ps_state';    // '' | 'consumed' | 'restored'
	const DEPLETED_META = '_ole_depleted';    // [ ['name'=>str,'stock'=>int], ... ]

	public static function init() {
		OLE_Print_Stock_Store::maybe_upgrade();

		// Створення замовлення (класичний + Store API checkout).
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order' ), 30, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order' ), 30, 1 );
		// Переходи статусів (скасовано/повернуто/невдало/кошик і назад).
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 30, 4 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'on_order' ), 30, 1 );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'on_order' ), 30, 1 );

		// Адмін-UI реєструється в своїх задачах (поля товару — Task 5; сторінка — Task 6;
		// банер/значок — Task 7). Тут лише споживання/повернення + email.
	}

	public static function threshold_for( $type ) {
		$o = OLE_Settings::get();
		return ( 'instruction' === $type )
			? (int) $o['print_stock_threshold_instruction']
			: (int) $o['print_stock_threshold_sticker'];
	}

	public static function on_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			self::reconcile( $order );
		}
	}

	public static function on_status_changed( $order_id, $from, $to, $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof WC_Order ) {
			self::reconcile( $order );
		}
	}

	/** Єдина точка: приводить облік у відповідність до стану замовлення. */
	public static function reconcile( WC_Order $order ) {
		$live  = OLE_Print_Stock_Calc::is_live( $order->get_status() );
		$state = (string) $order->get_meta( self::STATE_META );

		if ( $live && 'consumed' !== $state ) {
			self::consume( $order );
		} elseif ( ! $live && 'consumed' === $state ) {
			self::restore( $order );
		}
	}

	private static function consume( WC_Order $order ) {
		$config = self::build_config();
		$deltas = OLE_Print_Stock_Calc::compute( self::lines_from_order( $order ), $config );
		if ( empty( $deltas ) ) {
			$order->update_meta_data( self::STATE_META, 'consumed' );
			$order->save();
			return;
		}
		$order_id = $order->get_id();
		$depleted = array();
		$crossed  = array(); // for email
		foreach ( $deltas as $cid => $delta ) {
			if ( $delta >= 0 ) {
				continue;
			}
			$row = OLE_Print_Stock_Store::get_consumable( $cid );
			if ( ! $row ) {
				continue;
			}
			$res       = OLE_Print_Stock_Store::apply_delta( $cid, (int) $delta, $order_id, 'order' );
			$threshold = self::threshold_for( $row['type'] );
			if ( OLE_Print_Stock_Calc::crosses_low( $res['before'], $res['after'], $threshold ) ) {
				OLE_Print_Stock_Store::set_low_notified( (int) $cid, 1 );
				$depleted[] = array( 'name' => $row['name'], 'stock' => $res['after'] );
				$crossed[]  = array( 'name' => $row['name'], 'stock' => $res['after'], 'type' => $row['type'] );
			}
		}
		$order->update_meta_data( self::STATE_META, 'consumed' );
		if ( $depleted ) {
			$order->update_meta_data( self::DEPLETED_META, $depleted );
		}
		$order->save();

		if ( $crossed ) {
			self::send_low_email( $crossed );
		}
	}

	private static function restore( WC_Order $order ) {
		$order_id = $order->get_id();
		$net      = OLE_Print_Stock_Store::ledger_net( $order_id );
		foreach ( $net as $cid => $sum ) {
			if ( 0 === (int) $sum ) {
				continue;
			}
			// Реверс поточного застосованого footprint замовлення.
			$res = OLE_Print_Stock_Store::apply_delta( (int) $cid, -(int) $sum, $order_id, 'restore' );
			$row = OLE_Print_Stock_Store::get_consumable( $cid );
			if ( $row && (int) $row['low_notified'] === 1 && $res['after'] > self::threshold_for( $row['type'] ) ) {
				OLE_Print_Stock_Store::set_low_notified( (int) $cid, 0 );
			}
		}
		$order->update_meta_data( self::STATE_META, 'restored' );
		$order->delete_meta_data( self::DEPLETED_META );
		$order->save();
	}

	/** [ ['product_id'=>,'variation_id'=>,'qty'=>], ... ] з рядків замовлення. */
	public static function lines_from_order( WC_Order $order ) {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$lines[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'qty'          => (int) $item->get_quantity(),
			);
		}
		return $lines;
	}

	public static function build_config() {
		$instructions = array();
		foreach ( OLE_Print_Stock_Store::sheets() as $sheet ) {
			$instructions[] = array(
				'id'          => (int) $sheet['id'],
				'product_ids' => $sheet['product_ids'],
			);
		}
		return array(
			'stickers'     => OLE_Print_Stock_Store::sticker_config(),
			'instructions' => $instructions,
		);
	}

	private static function send_low_email( $crossed ) {
		$to      = get_option( 'admin_email' );
		$subject = __( 'OLE — time to print more consumables', 'order-list-enhancer' );
		$lines   = array( __( 'These items dropped to their low threshold:', 'order-list-enhancer' ), '' );
		foreach ( $crossed as $c ) {
			$label = ( 'instruction' === $c['type'] )
				? __( 'Instruction', 'order-list-enhancer' )
				: __( 'Sticker', 'order-list-enhancer' );
			/* translators: 1: type, 2: name, 3: remaining stock. */
			$lines[] = sprintf( __( '%1$s "%2$s" — %3$d left', 'order-list-enhancer' ), $label, $c['name'], (int) $c['stock'] );
		}
		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
