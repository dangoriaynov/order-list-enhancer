<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Захист від дублікатів замовлень на чекауті.
 * find_match — чиста функція (без WordPress), тестується ізольовано.
 * WP-glue (хуки/сесія/AJAX) додається в Task A4.
 */
class OLE_Dup_Guard {

	/** Статуси, які НЕ вважаємо «живим» замовленням (можна законно перезамовити). */
	const DEAD_STATUSES = array( 'cancelled', 'failed', 'trash' );

	/**
	 * Чи дублює поточний сабміт нещодавнє замовлення-кандидат?
	 *
	 * @param array $current    ['phone','cart_hash','items_sig','total']
	 * @param array $candidates список ['number','phone','cart_hash','items_sig','total','created_ts','status']
	 * @param int   $window_min вікно у хвилинах
	 * @param int   $now_ts     поточний unix-час
	 * @return array|null       ['number'=>string,'mins'=>int] першого збігу або null
	 */
	public static function find_match( $current, $candidates, $window_min, $now_ts ) {
		$phone = isset( $current['phone'] ) ? trim( (string) $current['phone'] ) : '';
		if ( '' === $phone ) {
			return null;
		}
		$window = max( 1, (int) $window_min ) * 60;
		foreach ( (array) $candidates as $c ) {
			if ( in_array( (string) ( $c['status'] ?? '' ), self::DEAD_STATUSES, true ) ) {
				continue;
			}
			$age = $now_ts - (int) ( $c['created_ts'] ?? 0 );
			if ( $age < 0 || $age > $window ) {
				continue;
			}
			if ( trim( (string) ( $c['phone'] ?? '' ) ) !== $phone ) {
				continue;
			}
			$cur_hash  = (string) ( $current['cart_hash'] ?? '' );
			$cand_hash = (string) ( $c['cart_hash'] ?? '' );
			$by_hash   = ( '' !== $cur_hash && $cur_hash === $cand_hash );
			$by_items  = ( '' === $cur_hash || '' === $cand_hash )
				&& (string) ( $current['items_sig'] ?? '' ) === (string) ( $c['items_sig'] ?? '' )
				&& (string) ( $current['total'] ?? '' ) === (string) ( $c['total'] ?? '' );
			if ( $by_hash || $by_items ) {
				return array(
					'number' => (string) ( $c['number'] ?? '' ),
					'mins'   => (int) floor( $age / 60 ),
				);
			}
		}
		return null;
	}

	const SESS_PENDING   = 'ole_dup_pending';
	const SESS_CONFIRMED = 'ole_dup_confirmed';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic' ), 10, 2 );
		add_action( 'wp_ajax_ole_dup_confirm', array( __CLASS__, 'ajax_confirm' ) );
		add_action( 'wp_ajax_nopriv_ole_dup_confirm', array( __CLASS__, 'ajax_confirm' ) );
		// Keep our guard out of wp-rocket "delay JS" so the button-lock binds on load.
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'rocket_exclude' ) );
	}

	public static function rocket_exclude( $excluded ) {
		$excluded   = is_array( $excluded ) ? $excluded : array();
		$excluded[] = 'ole-dup-guard';
		return $excluded;
	}

	private static function cc() {
		$cc = preg_replace( '/\D+/', '', (string) OLE_Settings::get()['phone_cc'] );
		return '' !== $cc ? $cc : '359';
	}

	private static function window_min() {
		return (int) OLE_Settings::get()['dup_guard_window_min'];
	}

	/** Підпис набору позицій: відсортовані "<pid>:<vid>:<qty>" через "|". */
	public static function items_sig_from_cart() {
		$parts = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$parts[] = (int) ( $item['product_id'] ?? 0 ) . ':' . (int) ( $item['variation_id'] ?? 0 ) . ':' . (int) ( $item['quantity'] ?? 0 );
			}
		}
		sort( $parts );
		return implode( '|', $parts );
	}

	public static function items_sig_from_order( $order ) {
		$parts = array();
		foreach ( $order->get_items() as $item ) {
			$parts[] = (int) $item->get_product_id() . ':' . (int) $item->get_variation_id() . ':' . (int) $item->get_quantity();
		}
		sort( $parts );
		return implode( '|', $parts );
	}

	private static function cart_hash() {
		return ( function_exists( 'WC' ) && WC()->cart ) ? (string) WC()->cart->get_cart_hash() : '';
	}

	private static function current_from_checkout( $data ) {
		$phone = isset( $data['billing_phone'] ) ? (string) $data['billing_phone'] : '';
		$cart  = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart : null;
		return array(
			'phone'     => OLE_Phone::normalize( $phone, self::cc() ),
			'cart_hash' => self::cart_hash(),
			'items_sig' => self::items_sig_from_cart(),
			'total'     => $cart ? number_format( (float) $cart->get_total( 'edit' ), 2, '.', '' ) : '',
		);
	}

	private static function candidates() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$since  = time() - self::window_min() * 60;
		$orders = wc_get_orders(
			array(
				'limit'        => 30,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'type'         => 'shop_order',
				'date_created' => '>' . $since,
				'return'       => 'objects',
			)
		);
		$cc  = self::cc();
		$out = array();
		foreach ( $orders as $o ) {
			$out[] = array(
				'number'     => $o->get_order_number(),
				'phone'      => OLE_Phone::normalize( (string) $o->get_billing_phone(), $cc ),
				'cart_hash'  => (string) $o->get_cart_hash(),
				'items_sig'  => self::items_sig_from_order( $o ),
				'total'      => number_format( (float) $o->get_total(), 2, '.', '' ),
				'created_ts' => $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0,
				'status'     => $o->get_status(),
			);
		}
		return $out;
	}

	/** Класичний чекаут: не блокуємо — просимо підтвердження через сесію + модалку. */
	public static function validate_classic( $data, $errors = null ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$current = self::current_from_checkout( $data );

		// Клієнт уже підтвердив дубль саме для цього кошика — пропускаємо й чистимо прапорець.
		$confirmed = (string) WC()->session->get( self::SESS_CONFIRMED, '' );
		if ( '' !== $confirmed && $confirmed === $current['cart_hash'] ) {
			WC()->session->set( self::SESS_CONFIRMED, '' );
			return;
		}

		$match = self::find_match( $current, self::candidates(), self::window_min(), time() );
		if ( ! $match ) {
			return;
		}

		// Block mode: hard stop, no confirm round-trip / no modal (plain error, no OLEDUP| marker).
		if ( 'block' === OLE_Settings::get()['dup_guard_mode'] ) {
			$msg = sprintf(
				/* translators: 1: order number, 2: minutes ago */
				__( 'You already placed a similar order %2$d min ago (#%1$s). To place another one, please contact us.', 'order-list-enhancer' ),
				$match['number'],
				$match['mins']
			);
			if ( $errors instanceof WP_Error ) {
				$errors->add( 'ole_dup_guard', $msg );
			} else {
				wc_add_notice( $msg, 'error' );
			}
			return;
		}

		// Прив'язуємо очікуване підтвердження до поточного хешу кошика (AJAX його зчитає).
		WC()->session->set( self::SESS_PENDING, $current['cart_hash'] );

		$msg = 'OLEDUP|' . sprintf(
			/* translators: 1: order number, 2: minutes ago */
			__( 'You already placed a similar order %2$d min ago (#%1$s). Are you sure you want to place another one?', 'order-list-enhancer' ),
			$match['number'],
			$match['mins']
		);
		if ( $errors instanceof WP_Error ) {
			$errors->add( 'ole_dup_guard', $msg );
		} else {
			wc_add_notice( $msg, 'error' );
		}
	}

	/** Клієнт натиснув «Да, поръчай отново» — переносимо pending → confirmed. */
	public static function ajax_confirm() {
		check_ajax_referer( 'ole_dup_confirm', 'nonce' );
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			wp_send_json_error( array( 'message' => 'no_session' ), 400 );
			return;
		}
		$pending = (string) WC()->session->get( self::SESS_PENDING, '' );
		WC()->session->set( self::SESS_CONFIRMED, $pending );
		wp_send_json_success();
	}

	public static function enqueue_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}
		wp_enqueue_style( 'ole-dup-guard', OLE_URL . 'assets/css/ole-dup-guard.css', array(), OLE_VERSION );
		wp_enqueue_script( 'ole-dup-guard', OLE_URL . 'assets/js/ole-dup-guard.js', array( 'jquery' ), OLE_VERSION, true );
		wp_localize_script(
			'ole-dup-guard',
			'OLE_DUP',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => 'ole_dup_confirm',
				'nonce'   => wp_create_nonce( 'ole_dup_confirm' ),
				'i18n'    => array(
					'confirm' => __( 'Yes, order again', 'order-list-enhancer' ),
					'cancel'  => __( 'Cancel', 'order-list-enhancer' ),
				),
			)
		);
	}
}
