<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Збереження/читання налаштувань (без UI).
 */
class OLE_Settings {

	const OPTION = 'ole_settings';

	public static function defaults() {
		return array(
			'dup_enabled'        => 'yes',
			'match_mode'         => 'phone', // phone | names | name_phone
			'scan_limit'         => 1500,
			'dup_window_days'    => 3, // поръчки в рамките на N дни → флаг „дубликат"
			'ship_enabled'       => 'yes', // кольорування в списку замовлень
			'ship_color_edit'    => 'yes', // кольорування блоку адреси на сторінці редагування
			'ship_rules'         => array(), // [ ['keyword'=>..,'color'=>..,'label'=>..], ... ]
			'ship_default_color' => '',
			'ship_default_label' => '',
			'total_on_edit'      => 'yes',
			'total_decimal_sep'  => ',', // ',' or '.' for the order total under the address
			'copy_buttons'       => 'yes', // copy name/phone/total on edit page
			'normalize_phone'    => 'no', // display-only phone normalization
			'phone_cc'           => '', // default country dial code (digits, e.g. 359)
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$opts               = array_merge( self::defaults(), $saved );
		$opts['scan_limit']      = max( 100, min( 5000, (int) $opts['scan_limit'] ) );
		$opts['dup_window_days'] = max( 1, min( 60, (int) $opts['dup_window_days'] ) );
		if ( '.' !== $opts['total_decimal_sep'] ) {
			$opts['total_decimal_sep'] = ',';
		}
		$opts['phone_cc'] = preg_replace( '/\D+/', '', (string) $opts['phone_cc'] );
		if ( ! is_array( $opts['ship_rules'] ) ) {
			$opts['ship_rules'] = array();
		}
		return $opts;
	}

	public static function is_yes( $opts, $key ) {
		return isset( $opts[ $key ] ) && 'yes' === $opts[ $key ];
	}
}
