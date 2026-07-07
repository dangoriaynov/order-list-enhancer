<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Збереження/читання налаштувань (без UI).
 */
class OLE_Settings {

	const OPTION       = 'ole_settings';
	const BULK_ACTIONS = 'ole_bulk_actions'; // cached value=>label map captured from the orders screen

	public static function defaults() {
		return array(
			'dup_enabled'         => 'yes',
			'match_mode'          => 'phone', // phone | names | name_phone
			'scan_limit'          => 1500,
			'dup_window_days'     => 3, // поръчки в рамките на N дни → флаг „дубликат"
			'ship_enabled'        => 'yes', // кольорування в списку замовлень
			'ship_color_edit'     => 'yes', // кольорування блоку адреси на сторінці редагування
			'ship_rules'          => array(), // [ ['keyword'=>..,'color'=>..,'label'=>..], ... ]
			'ship_default_color'  => '',
			'ship_default_label'  => '',
			'total_on_edit'       => 'yes',
			'total_decimal_sep'   => ',', // ',' or '.' for the order total under the address
			'copy_buttons'        => 'yes', // copy name/phone/total on edit page
			'normalize_phone'     => 'no', // display-only phone normalization
			'phone_cc'            => '', // default country dial code (digits, e.g. 359)
			'bulk_default_action' => '', // pre-selected entry in the orders-list bulk-actions menu ('' = none)
			'extras_enabled'      => 'no', // convert mapped add-on extras into real product lines at order creation
			'extras_map'          => array(), // [ ['match'=>'<extra label>','product'=>123], ... ]
			'phone_validate_enabled' => 'no', // checkout phone-number validation
			'phone_validate_mode'    => 'warn', // 'warn' (allow + flag) | 'block' (stop order)
			'dup_guard_enabled'      => 'no', // checkout duplicate-order guard
			'dup_guard_mode'         => 'confirm', // 'confirm' (ask in modal) | 'block' (hard stop)
			'dup_guard_window_min'   => 5, // minutes window for "identical recent order"
			'total_color_enabled' => 'no', // ring orders whose total reaches a threshold
			'total_color_rules'   => array(), // [ ['threshold'=>float,'color'=>'#hex','label'=>''], ... ]
			'seq_open_enabled'    => 'yes', // "open selected one-by-one" button on the orders list
			'seq_open_interval'   => 7, // default seconds between opened tabs
			'delivery_notice_enabled'   => 'no',  // highlight the orddd delivery-date field at checkout
			'delivery_notice_title'     => '',     // empty → translatable default
			'delivery_notice_body'      => '',     // empty → translatable default
			'delivery_vacation_enabled' => 'no',  // show the "we are away" banner
			'delivery_vacation_until'   => '',     // YYYY-MM-DD; empty/past → banner hidden
			'delivery_vacation_text'    => '',     // empty → translatable default; supports one %s (date)
			'print_stock_enabled'                => 'no',  // track printed stickers + instruction sheets
			'print_stock_threshold_sticker'      => 20,    // low threshold for stickers
			'print_stock_threshold_instruction'  => 5,     // low threshold for instruction sheets
		);
	}

	/**
	 * Кешований список групових дій (value=>label), зібраний JS-ом з екрана замовлень.
	 */
	public static function bulk_actions() {
		$a = get_option( self::BULK_ACTIONS, array() );
		return is_array( $a ) ? $a : array();
	}

	/**
	 * Нормалізує таблицю відповідності екстра→товар: лишає лише рядки з текстом і product id > 0.
	 */
	public static function clean_extras_map( $rows ) {
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$match   = isset( $r['match'] ) ? sanitize_text_field( (string) $r['match'] ) : '';
			$product = isset( $r['product'] ) ? absint( $r['product'] ) : 0;
			if ( '' !== $match && $product > 0 ) {
				$out[] = array(
					'match'   => $match,
					'product' => $product,
				);
			}
		}
		return $out;
	}

	/**
	 * Нормалізує правила кольорування за сумою: лишає рядки з порогом > 0 і валідним кольором.
	 */
	public static function clean_total_color_rules( $rows ) {
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$threshold = isset( $r['threshold'] ) ? (float) $r['threshold'] : 0;
			$color     = isset( $r['color'] ) ? (string) sanitize_hex_color( $r['color'] ) : '';
			$label     = isset( $r['label'] ) ? sanitize_text_field( (string) $r['label'] ) : '';
			if ( $threshold > 0 && '' !== $color ) {
				$out[] = array(
					'threshold' => $threshold,
					'color'     => $color,
					'label'     => $label,
				);
			}
		}
		return $out;
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
		$opts['bulk_default_action'] = sanitize_text_field( (string) $opts['bulk_default_action'] );
		$opts['extras_map'] = self::clean_extras_map( $opts['extras_map'] );
		$opts['total_color_rules'] = self::clean_total_color_rules( $opts['total_color_rules'] );
		$opts['phone_validate_mode'] = ( 'block' === $opts['phone_validate_mode'] ) ? 'block' : 'warn';
		$opts['dup_guard_mode']       = ( 'block' === $opts['dup_guard_mode'] ) ? 'block' : 'confirm';
		$opts['dup_guard_window_min'] = max( 1, min( 120, (int) $opts['dup_guard_window_min'] ) );
		$opts['seq_open_interval'] = max( 1, min( 300, (int) $opts['seq_open_interval'] ) );
		$opts['delivery_notice_title']  = sanitize_text_field( (string) $opts['delivery_notice_title'] );
		$opts['delivery_notice_body']   = sanitize_textarea_field( (string) $opts['delivery_notice_body'] );
		$opts['delivery_vacation_text'] = sanitize_textarea_field( (string) $opts['delivery_vacation_text'] );
		$opts['delivery_vacation_until'] = ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $opts['delivery_vacation_until'] ) )
			? (string) $opts['delivery_vacation_until']
			: '';
		$opts['print_stock_threshold_sticker']     = max( 0, min( 100000, (int) $opts['print_stock_threshold_sticker'] ) );
		$opts['print_stock_threshold_instruction'] = max( 0, min( 100000, (int) $opts['print_stock_threshold_instruction'] ) );
		if ( ! is_array( $opts['ship_rules'] ) ) {
			$opts['ship_rules'] = array();
		}
		return $opts;
	}

	public static function is_yes( $opts, $key ) {
		return isset( $opts[ $key ] ) && 'yes' === $opts[ $key ];
	}
}
