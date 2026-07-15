<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Нормализация на телефонен номер - САМО за показване (не пипа БД).
 * Правила: водещо „00" → „+"; „+0"/„0" в началото на локален номер се махат
 * и се добавя кодът на държавата. При нещо нередно връща оригинала.
 */
class ORDELIST_Phone {

	public static function normalize( $raw, $cc ) {
		$raw = (string) $raw;
		$s   = trim( $raw );
		if ( '' === $s ) {
			return $raw;
		}
		$cc = preg_replace( '/\D+/', '', (string) $cc );

		// Водещо 00 → +
		if ( 0 === strpos( $s, '00' ) ) {
			$s = '+' . substr( $s, 2 );
		}
		// Водещо +0 → махаме го (локален номер, грешно сложен 0 след +)
		if ( 0 === strpos( $s, '+0' ) ) {
			$s = substr( $s, 2 );
		}

		if ( 0 === strpos( $s, '+' ) ) {
			// Вече има код на държавата.
			$result = '+' . preg_replace( '/\D+/', '', substr( $s, 1 ) );
		} else {
			$d = preg_replace( '/\D+/', '', $s );
			$d = ltrim( $d, '0' );
			if ( '' !== $cc && 0 === strpos( $d, $cc ) ) {
				$result = '+' . $d;
			} elseif ( '' !== $cc ) {
				$result = '+' . $cc . $d;
			} else {
				$result = '+' . $d;
			}
		}

		// Предпазно: трябва да изглежда като телефон (8-15 цифри), иначе оригинала.
		$dc = preg_replace( '/\D+/', '', $result );
		if ( strlen( $dc ) < 8 || strlen( $dc ) > 15 ) {
			return $raw;
		}
		return $result;
	}
}
