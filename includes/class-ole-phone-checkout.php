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
