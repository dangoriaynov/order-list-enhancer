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

		// Поле «Запас наліпок» на простому товарі та на кожній варіації.
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'render_simple_field' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_simple_field' ), 20, 1 );
		add_action( 'woocommerce_variation_options_inventory', array( __CLASS__, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_field' ), 20, 2 );

		OLE_Print_Stock_Admin::init();

		// Банер «пора друкувати».
		add_action( 'admin_notices', array( __CLASS__, 'low_banner' ) );
		// Значок у списку замовлень (HPOS + legacy).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_order_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_order_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_column_legacy' ), 10, 2 );

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
		// Бекстоп ідемпотентності: якщо журнал уже має списання цього замовлення
		// (напр. мета-прапорець не зберігся через збій) — не списувати вдруге.
		if ( OLE_Print_Stock_Store::is_consumed( $order->get_id() ) ) {
			$order->update_meta_data( self::STATE_META, 'consumed' );
			$order->save();
			return;
		}

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

	/** Поточний запас наліпки товару/варіації (для відображення в полі). */
	private static function sticker_stock_value( $ref_id ) {
		$row = OLE_Print_Stock_Store::get_sticker( (int) $ref_id );
		return $row ? (string) (int) $row['stock'] : '';
	}

	public static function render_simple_field() {
		global $post;
		$product = $post ? wc_get_product( $post->ID ) : null;
		if ( $product && $product->is_type( 'variable' ) ) {
			return; // variations carry their own sticker field (per-variation tracking)
		}
		$val = self::sticker_stock_value( (int) $post->ID );
		woocommerce_wp_text_input(
			array(
				'id'                => '_ole_sticker_stock',
				'label'             => __( 'Sticker stock', 'order-list-enhancer' ),
				'desc_tip'          => true,
				'description'       => __( 'Printed stickers on hand for this product. Decreases by the quantity ordered. Leave blank to not track.', 'order-list-enhancer' ),
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '1' ),
				'value'             => $val,
			)
		);
	}

	public static function save_simple_field( $post_id ) {
		if ( ! isset( $_POST['_ole_sticker_stock'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$raw = wp_unslash( $_POST['_ole_sticker_stock'] ); // phpcs:ignore WordPress.Security
		if ( '' === trim( (string) $raw ) ) {
			return; // не трекаємо / не чіпаємо існуючий запас
		}
		$product = wc_get_product( $post_id );
		$name    = $product ? wp_strip_all_tags( $product->get_name() ) : ( '#' . (int) $post_id );
		OLE_Print_Stock_Store::upsert_sticker( (int) $post_id, $name, (int) $raw );
	}

	public static function render_variation_field( $loop, $variation_data, $variation ) {
		$vid = (int) $variation->ID;
		$val = self::sticker_stock_value( $vid );
		woocommerce_wp_text_input(
			array(
				'id'                => '_ole_sticker_stock_' . $loop,
				'name'              => '_ole_sticker_stock_var[' . $loop . ']',
				'label'             => __( 'Sticker stock', 'order-list-enhancer' ),
				'wrapper_class'     => 'form-row form-row-first',
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '1' ),
				'value'             => $val,
			)
		);
	}

	public static function save_variation_field( $variation_id, $i ) {
		if ( ! isset( $_POST['_ole_sticker_stock_var'][ $i ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$raw = wp_unslash( $_POST['_ole_sticker_stock_var'][ $i ] ); // phpcs:ignore WordPress.Security
		if ( '' === trim( (string) $raw ) ) {
			return;
		}
		$variation = wc_get_product( $variation_id );
		$name      = $variation ? wp_strip_all_tags( $variation->get_name() ) : ( '#' . (int) $variation_id );
		OLE_Print_Stock_Store::upsert_sticker( (int) $variation_id, $name, (int) $raw );
	}

	public static function low_banner() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$o     = OLE_Settings::get();
		$count = OLE_Print_Stock_Store::low_count(
			(int) $o['print_stock_threshold_sticker'],
			(int) $o['print_stock_threshold_instruction']
		);
		if ( $count < 1 ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . OLE_Print_Stock_Admin::SLUG );
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( sprintf( /* translators: %d: number of low items. */ _n( '%d print consumable is low — time to print more.', '%d print consumables are low — time to print more.', $count, 'order-list-enhancer' ), $count ) ),
			esc_url( $url ),
			esc_html__( 'Open stock', 'order-list-enhancer' )
		);
	}

	public static function add_order_column( $columns ) {
		$columns['ole_depleted'] = __( 'Print', 'order-list-enhancer' );
		return $columns;
	}

	private static function badge_html( $depleted ) {
		if ( ! is_array( $depleted ) || empty( $depleted ) ) {
			return '';
		}
		$names = array();
		$neg   = false;
		foreach ( $depleted as $d ) {
			$stock   = (int) ( $d['stock'] ?? 0 );
			$neg     = $neg || $stock < 0;
			$names[] = ( $d['name'] ?? '' ) . ': ' . $stock;
		}
		$title = esc_attr( implode( ' | ', $names ) );
		$style = $neg ? 'background:#d63638' : 'background:#b26a00';
		return '<span title="' . $title . '" style="display:inline-block;padding:1px 7px;border-radius:9px;color:#fff;font-size:11px;' . $style . '">🖨️ ' . count( $names ) . '</span>';
	}

	public static function render_order_column( $column, $order ) {
		if ( 'ole_depleted' !== $column ) {
			return;
		}
		echo self::badge_html( $order->get_meta( self::DEPLETED_META ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function render_order_column_legacy( $column, $post_id ) {
		if ( 'ole_depleted' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order ) {
			echo self::badge_html( $order->get_meta( self::DEPLETED_META ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
