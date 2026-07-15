<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Чиста перевірка болгарського телефону (без WordPress) - юніт-тестована;
 * правила дзеркалить JS-порт у assets/js/ole-phone-checkout.js.
 */
class ORDELIST_Phone_Validator {

	/** @return array{valid:bool,reason:string,normalized:string} */
	public static function validate( $raw, $cc = '359' ) {
		$cc  = preg_replace( '/\D+/', '', (string) $cc );
		if ( '' === $cc ) {
			$cc = '359';
		}
		$s   = (string) $raw;
		$has_plus = ( '' !== $s && '+' === $s[0] );
		$digits   = preg_replace( '/\D+/', '', $s );

		if ( '' === $digits ) {
			return self::result( 'empty', '', $cc );
		}

		$nsn = null;
		if ( $has_plus ) {
			if ( 0 === strpos( $digits, $cc ) ) {
				$nsn = substr( $digits, strlen( $cc ) );
			} else {
				return self::result( 'not_bulgarian', '', $cc );
			}
		} elseif ( 0 === strpos( $digits, '00' . $cc ) ) {
			$nsn = substr( $digits, 2 + strlen( $cc ) );
		} elseif ( 0 === strpos( $digits, '00' ) ) {
			return self::result( 'not_bulgarian', '', $cc );
		} elseif ( 0 === strpos( $digits, '0' ) ) {
			$nsn = substr( $digits, 1 );
		} elseif ( 0 === strpos( $digits, $cc ) && strlen( $digits ) >= strlen( $cc ) + 8 ) {
			$nsn = substr( $digits, strlen( $cc ) );
		} else {
			$nsn = $digits;
		}

		$len = strlen( $nsn );
		if ( $len < 8 ) {
			return self::result( 'too_short', '', $cc );
		}
		if ( $len > 9 ) {
			return self::result( 'too_long', '', $cc );
		}
		$first = $nsn[0];
		if ( $first < '2' || $first > '9' ) {
			return self::result( 'invalid', '', $cc );
		}
		return self::result( 'ok', $nsn, $cc );
	}

	private static function result( $reason, $nsn, $cc ) {
		return array(
			'valid'      => ( 'ok' === $reason ),
			'reason'     => $reason,
			'normalized' => ( 'ok' === $reason ) ? '+' . $cc . $nsn : '',
		);
	}
}
