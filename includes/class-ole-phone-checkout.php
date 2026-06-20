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
		// Server enforcement + admin flags are added in later tasks.
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
