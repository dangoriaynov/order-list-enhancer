<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Чиста логіка співставлення екстри з товаром (без WordPress) - повністю юніт-тестована.
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

	/**
	 * Пропорційно масштабує податки рядка (['subtotal'=>[rate=>amt],'total'=>[...]])
	 * на частку $fraction. Використовується, щоб перенести частину податку
	 * батьківського рядка на винесений товарний рядок, лишивши суму податку сталою.
	 */
	public static function scale_taxes( $taxes, $fraction ) {
		$out = array(
			'subtotal' => array(),
			'total'    => array(),
		);
		if ( ! is_array( $taxes ) ) {
			return $out;
		}
		foreach ( array( 'subtotal', 'total' ) as $kind ) {
			if ( ! isset( $taxes[ $kind ] ) || ! is_array( $taxes[ $kind ] ) ) {
				continue;
			}
			foreach ( $taxes[ $kind ] as $rate_id => $amt ) {
				$out[ $kind ][ $rate_id ] = (float) $amt * (float) $fraction;
			}
		}
		return $out;
	}

	/**
	 * Будує індекс комбо: combo_id => ['base'=>id, 'parts'=>[ ['product'=>id,'qty'=>n], ... ]].
	 * Кілька рядків з тим самим combo описують кілька компонентів; base беремо з першого,
	 * де він заданий. Рядки без combo/base/product ігноруються.
	 */
	public static function combo_index( $map ) {
		$idx = array();
		if ( ! is_array( $map ) ) {
			return $idx;
		}
		foreach ( $map as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$combo   = isset( $row['combo'] ) ? (int) $row['combo'] : 0;
			$base    = isset( $row['base'] ) ? (int) $row['base'] : 0;
			$product = isset( $row['product'] ) ? (int) $row['product'] : 0;
			$qty     = isset( $row['qty'] ) ? (int) $row['qty'] : 1;
			if ( $combo <= 0 || $base <= 0 || $product <= 0 ) {
				continue;
			}
			if ( ! isset( $idx[ $combo ] ) ) {
				$idx[ $combo ] = array(
					'base'  => $base,
					'parts' => array(),
				);
			}
			$idx[ $combo ]['parts'][] = array(
				'product' => $product,
				'qty'     => ( $qty >= 1 && $qty <= 99 ) ? $qty : 1,
			);
		}
		return $idx;
	}

	/**
	 * Ділить суму рядка пропорційно до опорних (каталожних) цін $refs = [ключ => ціна].
	 * Кожна частка, крім якірної, округлюється до $precision знаків, а якірна ($anchor)
	 * забирає залишок - тому сума часток ТОЧНО дорівнює $amount за будь-яких округлень.
	 * Якщо опорних цін немає (сума <= 0), усе лишається на якорі.
	 */
	public static function split_amount( $amount, $refs, $anchor, $precision = 2 ) {
		$amount    = (float) $amount;
		$out       = array();
		$total_ref = 0.0;
		foreach ( (array) $refs as $k => $v ) {
			$out[ $k ]  = 0.0;
			$total_ref += max( 0.0, (float) $v );
		}
		if ( ! array_key_exists( $anchor, $out ) ) {
			$out[ $anchor ] = 0.0;
		}
		if ( $total_ref <= 0 ) {
			$out[ $anchor ] = $amount;
			return $out;
		}
		$assigned = 0.0;
		foreach ( $out as $k => $unused ) {
			if ( $k === $anchor ) {
				continue;
			}
			$share      = round( $amount * ( max( 0.0, (float) $refs[ $k ] ) / $total_ref ), max( 0, (int) $precision ) );
			$out[ $k ]  = $share;
			$assigned  += $share;
		}
		$out[ $anchor ] = $amount - $assigned;
		return $out;
	}

	/** Витягує кількість «N бр»/«N бройки» з тексту екстри; типово 1 (межі 1-99). */
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
