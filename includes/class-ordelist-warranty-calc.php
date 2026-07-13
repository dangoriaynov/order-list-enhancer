<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Чиста логіка партій з термінами придатності (без WordPress).
 * Тестується ізольовано — див. tests/warranty/test-calc.php.
 */
class ORDELIST_Warranty_Calc {

	const NOTIFIED_NONE    = 0;
	const NOTIFIED_SOON    = 1;
	const NOTIFIED_EXPIRED = 2;

	/**
	 * FIFO-розподіл списання по партіях.
	 *
	 * @param int   $qty     скільки одиниць списати (>0)
	 * @param array $batches [ ['id'=>int,'qty'=>int], ... ] ВЖЕ відсортовані expiry ASC, id ASC
	 * @return array [ batch_id => taken>0 ]; порожньо без партій або без попиту
	 */
	public static function allocate( $qty, array $batches ) {
		$qty = (int) $qty;
		if ( $qty <= 0 || empty( $batches ) ) {
			return array();
		}
		$takes = array();
		$left  = $qty;
		foreach ( $batches as $b ) {
			if ( $left <= 0 ) {
				break;
			}
			$avail = (int) $b['qty'];
			if ( $avail <= 0 ) {
				continue;
			}
			$take                    = min( $left, $avail );
			$takes[ (int) $b['id'] ] = ( $takes[ (int) $b['id'] ] ?? 0 ) + $take;
			$left                   -= $take;
		}
		if ( $left > 0 ) {
			// Решта попиту — в найновішу партію: нехай іде в мінус, це сигнал розбіжності з полицею.
			$last                       = $batches[ count( $batches ) - 1 ];
			$takes[ (int) $last['id'] ] = ( $takes[ (int) $last['id'] ] ?? 0 ) + $left;
		}
		return $takes;
	}

	/** 'expired' | 'soon' | 'ok' для дати Y-m-d (лексикографічне порівняння дат коректне). */
	public static function status( $expiry, $today, $window_days ) {
		if ( $expiry < $today ) {
			return 'expired';
		}
		if ( $expiry <= self::add_days( $today, $window_days ) ) {
			return 'soon';
		}
		return 'ok';
	}

	/** Y-m-d + N днів (чистий PHP). */
	public static function add_days( $ymd, $days ) {
		$d = DateTime::createFromFormat( 'Y-m-d', (string) $ymd );
		if ( ! $d ) {
			return (string) $ymd;
		}
		$d->modify( '+' . (int) $days . ' days' );
		return $d->format( 'Y-m-d' );
	}

	/**
	 * Кому слати попередження: qty>0 і email для цього стану ще не йшов.
	 *
	 * @param array $rows [ ['id','expiry','qty','notified',...], ... ]
	 * @return array [ 'soon'=>[rows], 'expired'=>[rows] ]
	 */
	public static function classify( array $rows, $today, $window_days ) {
		$out = array(
			'soon'    => array(),
			'expired' => array(),
		);
		foreach ( $rows as $r ) {
			if ( (int) $r['qty'] <= 0 ) {
				continue;
			}
			$status   = self::status( (string) $r['expiry'], $today, $window_days );
			$notified = (int) $r['notified'];
			if ( 'expired' === $status && $notified < self::NOTIFIED_EXPIRED ) {
				$out['expired'][] = $r;
			} elseif ( 'soon' === $status && $notified < self::NOTIFIED_SOON ) {
				$out['soon'][] = $r;
			}
		}
		return $out;
	}
}
