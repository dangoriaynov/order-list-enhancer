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
			'match_phone'        => 'yes',
			'match_email'        => 'yes',
			'match_name'         => 'yes',
			'match_address'      => 'yes',
			'scan_limit'         => 1500,
			'ship_enabled'       => 'yes', // кольорування в списку замовлень
			'ship_color_edit'    => 'yes', // кольорування блоку адреси на сторінці редагування
			'ship_rules'         => array(), // [ ['keyword'=>..,'color'=>..,'label'=>..], ... ]
			'ship_default_color' => '',
			'ship_default_label' => '',
			'total_on_edit'      => 'yes',
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$opts               = array_merge( self::defaults(), $saved );
		$opts['scan_limit'] = max( 100, min( 5000, (int) $opts['scan_limit'] ) );
		if ( ! is_array( $opts['ship_rules'] ) ) {
			$opts['ship_rules'] = array();
		}
		return $opts;
	}

	public static function is_yes( $opts, $key ) {
		return isset( $opts[ $key ] ) && 'yes' === $opts[ $key ];
	}
}
