<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Перевірка телефону на чекауті (frontend + server) та позначки в адмінці.
 * Чисті правила — у [[OLE_Phone_Validator]].
 */
class OLE_Phone_Checkout {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_blocks' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_order_flag' ) );
	}

	private static function cc() {
		$cc = preg_replace( '/\D+/', '', (string) OLE_Settings::get()['phone_cc'] );
		return '' !== $cc ? $cc : '359';
	}

	/** Перекладені повідомлення за кодом причини. */
	public static function messages() {
		return array(
			'ok'            => __( 'Looks valid', 'order-list-enhancer' ),
			'empty'         => __( 'Please enter a phone number.', 'order-list-enhancer' ),
			'too_short'     => __( 'The phone number looks too short. Example: 0888 123 456.', 'order-list-enhancer' ),
			'too_long'      => __( 'The phone number looks too long. Example: 0888 123 456.', 'order-list-enhancer' ),
			'not_bulgarian' => __( 'Please enter a Bulgarian number (e.g. 0888 123 456).', 'order-list-enhancer' ),
			'invalid'       => __( 'Invalid phone number. Example: 0888 123 456 or +359 888 123 456.', 'order-list-enhancer' ),
		);
	}

	/** Перевіряє значення телефону за поточними налаштуваннями. */
	public static function check_value( $phone ) {
		return OLE_Phone_Validator::validate( (string) $phone, self::cc() );
	}

	private static function is_block_mode() {
		return 'block' === OLE_Settings::get()['phone_validate_mode'];
	}

	/** Класичний чекаут: додає помилку у режимі block. */
	public static function validate_classic( $data, $errors = null ) {
		$phone = isset( $data['billing_phone'] ) ? (string) $data['billing_phone'] : '';
		if ( '' === trim( $phone ) ) {
			return;
		}
		$res = self::check_value( $phone );
		if ( ! $res['valid'] && self::is_block_mode() ) {
			$msgs = self::messages();
			$msg  = isset( $msgs[ $res['reason'] ] ) ? $msgs[ $res['reason'] ] : $msgs['invalid'];
			if ( $errors instanceof WP_Error ) {
				$errors->add( 'ole_phone_invalid', $msg );
			} else {
				wc_add_notice( $msg, 'error' );
			}
		}
	}

	/** Блоковий чекаут (Store API): кидає виняток у режимі block. */
	public static function validate_blocks( $order, $request ) {
		if ( ! self::is_block_mode() || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		$phone = $order->get_billing_phone();
		if ( '' === trim( (string) $phone ) ) {
			return;
		}
		$res = self::check_value( $phone );
		if ( ! $res['valid'] ) {
			$msgs = self::messages();
			$msg  = isset( $msgs[ $res['reason'] ] ) ? $msgs[ $res['reason'] ] : $msgs['invalid'];
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'ole_phone_invalid', esc_html( $msg ), 400 );
		}
	}

	/** Червона позначка на сторінці замовлення, якщо телефон невалідний. */
	public static function render_order_flag( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		$phone = (string) $order->get_billing_phone();
		if ( '' === trim( $phone ) ) {
			return;
		}
		$res = self::check_value( $phone );
		if ( $res['valid'] ) {
			return;
		}
		$msgs = self::messages();
		$why  = isset( $msgs[ $res['reason'] ] ) ? $msgs[ $res['reason'] ] : $msgs['invalid'];
		printf(
			'<p class="ole-phone-invalid">⚠ %s<br><span class="ole-phone-why">%s</span></p>',
			esc_html__( 'Phone may be invalid — please verify.', 'order-list-enhancer' ),
			esc_html( $why )
		);
	}

	/** ID останніх замовлень із невалідним білінг-телефоном (для бейджів у списку). */
	public static function invalid_order_ids( $limit = 500 ) {
		$key    = 'ole_phone_invalid_ids';
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$ids = self::compute_invalid_order_ids( (int) $limit );
		set_transient( $key, $ids, 2 * MINUTE_IN_SECONDS );
		return $ids;
	}

	/** Виконує фактичний запит і повертає масив int[] невалідних ID. */
	private static function compute_invalid_order_ids( $limit = 500 ) {
		$ids = array();
		$cc  = self::cc();
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $ids;
		}
		$orders = wc_get_orders(
			array(
				'limit'   => (int) $limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'type'    => 'shop_order',
				'return'  => 'objects',
			)
		);
		foreach ( $orders as $o ) {
			$phone = (string) $o->get_billing_phone();
			if ( '' === trim( $phone ) ) {
				continue;
			}
			$res = OLE_Phone_Validator::validate( $phone, $cc );
			if ( ! $res['valid'] ) {
				$ids[] = $o->get_id();
			}
		}
		return $ids;
	}

	public static function enqueue_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_script( 'ole-phone-checkout', OLE_URL . 'assets/js/ole-phone-checkout.js', array(), OLE_VERSION, true );
		wp_localize_script(
			'ole-phone-checkout',
			'OLE_PHONE',
			array(
				'cc'   => self::cc(),
				'i18n' => self::messages(),
			)
		);
	}
}
