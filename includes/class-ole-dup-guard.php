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
			$cur_hash  = (string) $current['cart_hash'];
			$cand_hash = (string) ( $c['cart_hash'] ?? '' );
			$by_hash   = ( '' !== $cur_hash && $cur_hash === $cand_hash );
			$by_items  = ( '' === $cur_hash || '' === $cand_hash )
				&& (string) $current['items_sig'] === (string) ( $c['items_sig'] ?? '' )
				&& (string) $current['total'] === (string) ( $c['total'] ?? '' );
			if ( $by_hash || $by_items ) {
				return array(
					'number' => (string) ( $c['number'] ?? '' ),
					'mins'   => (int) floor( $age / 60 ),
				);
			}
		}
		return null;
	}
}
