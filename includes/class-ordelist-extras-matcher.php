<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Чиста логіка співставлення екстри з товаром (без WordPress) — повністю юніт-тестована.
 */
class ORDELIST_Extras_Matcher {

	/** Нормалізує текст ярлика: trim, стиснуті пробіли, нижній регістр. */
	public static function normalize( $s ) {
		$s = preg_replace( '/\s+/u', ' ', (string) $s );
		$s = trim( $s );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/** Будує індекс normalized_label => product_id з рядків мапінгу. */
	public static function index( $map ) {
		$idx = array();
		if ( ! is_array( $map ) ) {
			return $idx;
		}
		foreach ( $map as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$m = isset( $row['match'] ) ? self::normalize( $row['match'] ) : '';
			$p = isset( $row['product'] ) ? (int) $row['product'] : 0;
			if ( '' !== $m && $p > 0 ) {
				$idx[ $m ] = $p;
			}
		}
		return $idx;
	}

	/** Шукає ярлик в індексі. Повертає product id або 0. */
	public static function match( $index, $label ) {
		$n = self::normalize( $label );
		return ( is_array( $index ) && isset( $index[ $n ] ) ) ? (int) $index[ $n ] : 0;
	}

	/** Розбирає масив _pao_ids у список екстр. */
	public static function parse_addons( $pao_ids ) {
		$out = array();
		if ( ! is_array( $pao_ids ) ) {
			return $out;
		}
		foreach ( $pao_ids as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$out[] = array(
				'label'      => isset( $a['value'] ) ? (string) $a['value'] : '',
				'field'      => isset( $a['key'] ) ? (string) $a['key'] : '',
				'price'      => isset( $a['raw_price'] ) ? (float) $a['raw_price'] : 0.0,
				'price_type' => isset( $a['price_type'] ) ? (string) $a['price_type'] : '',
				'id'         => isset( $a['id'] ) ? (string) $a['id'] : '',
			);
		}
		return $out;
	}

	/** Перевірка балансу: сума цін екстр == _pao_total (в межах epsilon). */
	public static function prices_balance( $addons, $pao_total, $epsilon = 0.01 ) {
		$sum = 0.0;
		foreach ( (array) $addons as $a ) {
			$sum += isset( $a['price'] ) ? (float) $a['price'] : 0.0;
		}
		return abs( $sum - (float) $pao_total ) <= $epsilon;
	}

	/** Витягує кількість «N бр»/«N бройки» з тексту екстри; типово 1 (межі 1–99). */
	public static function parse_qty( $text ) {
		if ( preg_match( '/(\d+)\s*(?:бр|бройк)/u', (string) $text, $m ) ) {
			$q = (int) $m[1];
			if ( $q >= 1 && $q <= 99 ) {
				return $q;
			}
		}
		return 1;
	}
}
