<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Дані для сторінки закупівлі: ЄДИНЕ місце читання таблиці wc_order_product_lookup
 * (аналітичний журнал WooCommerce) + збирання payload для клієнта.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only aggregate over WooCommerce's analytics lookup table; no WP API exposes it; admin-side only.
class ORDELIST_Forecast_Data {

	public static function table_lookup() {
		global $wpdb;
		return $wpdb->prefix . 'wc_order_product_lookup';
	}

	/** Сирі агрегати продажів товару: variation_id / d (Y-m-d) / qty. */
	public static function rows_for_product( $product_id ) {
		global $wpdb;
		// Чисті продажі за семантикою WooCommerce Analytics: неоплачені/скасовані/невдалі
		// не рахуються; wc-refunded лишаються - їх компенсують від'ємні рядки повернень.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.variation_id, DATE(l.date_created) d, SUM(l.product_qty) qty
				FROM %i l
				JOIN %i s ON s.order_id = l.order_id
				WHERE l.product_id = %d
					AND s.status NOT IN ( 'wc-pending', 'wc-cancelled', 'wc-failed', 'wc-checkout-draft', 'wc-auto-draft', 'wc-trash' )
				GROUP BY l.variation_id, DATE(l.date_created)",
				self::table_lookup(),
				$wpdb->prefix . 'wc_order_stats',
				(int) $product_id
			),
			ARRAY_A
		);
	}

	/** Pure: рядки → [ variation_id => [ 'YYYY' => [ 'MM-DD' => qty ] ] ]. Тестується без WP. */
	public static function shape_rows( array $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			$d = (string) ( $r['d'] ?? '' );
			if ( 1 !== preg_match( '/^(\d{4})-(\d{2}-\d{2})$/', $d, $m ) ) {
				continue;
			}
			$vid = (int) ( $r['variation_id'] ?? 0 );
			$qty = (int) ( $r['qty'] ?? 0 );
			// Нульові рядки пропускаємо; від'ємні рядки повернень проходять і віднімаються.
			if ( 0 === $qty ) {
				continue;
			}
			$out[ $vid ][ $m[1] ][ $m[2] ] = ( $out[ $vid ][ $m[1] ][ $m[2] ] ?? 0 ) + $qty;
		}
		return $out;
	}

	/** Вага в кг або null (порожнє поле ваги) - конвертація з одиниці ваги магазину. */
	private static function weight_kg( $product ) {
		$w = $product ? $product->get_weight() : '';
		if ( '' === (string) $w ) {
			return null;
		}
		return (float) wc_get_weight( (float) $w, 'kg' );
	}

	/**
	 * Pure: вага в кг з хвоста назви ('... - 25 г', '... - 1 кг'), або null.
	 * Остання вагова величина в назві перемагає; літри та інші одиниці не збігаються.
	 */
	public static function parse_weight_kg_from_name( $name ) {
		if ( ! preg_match_all( '/(\d+(?:[.,]\d+)?)\s*(кг|гр|г|kg|g)(?![\p{L}\p{N}])/iu', (string) $name, $m, PREG_SET_ORDER ) ) {
			return null;
		}
		$last = end( $m );
		$val  = (float) str_replace( ',', '.', $last[1] );
		$unit = mb_strtolower( $last[2] );
		return ( 'кг' === $unit || 'kg' === $unit ) ? $val : $val / 1000;
	}

	/** Історична назва позиції для варіації з журналу замовлень (для видалених), або ''. */
	public static function item_name_for_variation( $variation_id ) {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT oi.order_item_name FROM %i oi JOIN %i om ON om.order_item_id = oi.order_item_id
				WHERE om.meta_key = %s AND om.meta_value = %s ORDER BY oi.order_item_id DESC LIMIT 1',
				$wpdb->prefix . 'woocommerce_order_items',
				$wpdb->prefix . 'woocommerce_order_itemmeta',
				'_variation_id',
				(string) (int) $variation_id
			)
		);
	}

	/** Повний payload для сторінки; null якщо товар не знайдено. */
	public static function payload( $picked_id ) {
		$p = wc_get_product( (int) $picked_id );
		if ( ! $p ) {
			return null;
		}
		$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
		if ( ! $parent ) {
			return null;
		}

		$shaped = self::shape_rows( self::rows_for_product( (int) $parent->get_id() ) );
		$today  = current_time( 'Y-m-d' );

		$targets = array(); // vid (0 = простий товар) => WC_Product|null
		if ( $parent->is_type( 'variable' ) ) {
			foreach ( $parent->get_children() as $vid ) {
				$targets[ (int) $vid ] = wc_get_product( $vid );
			}
			// Варіації з продажами поза списком дітей: пробуємо завантажити
			// (вимкнені/відв'язані ще існують); справді видалені лишаються null.
			foreach ( array_keys( $shaped ) as $vid ) {
				if ( $vid > 0 && ! isset( $targets[ $vid ] ) ) {
					$loaded          = wc_get_product( $vid );
					$targets[ $vid ] = $loaded ? $loaded : null;
				}
			}
		} else {
			$targets[0] = $parent;
		}

		$variations = array();
		$batches    = array();
		foreach ( $targets as $vid => $prod ) {
			$name   = $prod ? wp_strip_all_tags( $prod->get_formatted_name() ) : '';
			$weight = self::weight_kg( $prod );
			if ( ! $prod ) {
				// Видалена варіація: назву й вагу відновлюємо з журналу замовлень.
				$hist   = self::item_name_for_variation( (int) $vid );
				$name   = ( '' !== $hist ) ? $hist . ' (#' . (int) $vid . ')' : '#' . (int) $vid;
				$weight = self::parse_weight_kg_from_name( $hist );
			} elseif ( null === $weight ) {
				// Поле ваги порожнє, але кількість зашита в назві - беремо звідти.
				$weight = self::parse_weight_kg_from_name( $name );
			}
			$variations[] = array(
				'id'        => (int) $vid,
				'name'      => $name,
				'exists'    => (bool) $prod,
				'weight_kg' => $weight,
				'series'    => isset( $shaped[ $vid ] ) ? $shaped[ $vid ] : array(),
			);
			$target_id = ( $vid > 0 ) ? (int) $vid : (int) $parent->get_id();
			foreach ( ORDELIST_Warranty_Store::batches_for_target( $target_id, $vid > 0 ) as $b ) {
				if ( (int) $b['qty'] > 0 && (string) $b['expiry'] >= $today ) {
					$batches[] = array(
						'variation_id' => (int) $vid,
						'expiry'       => (string) $b['expiry'],
						'qty'          => (int) $b['qty'],
					);
				}
			}
		}

		return array(
			'product_id' => (int) $parent->get_id(),
			'variations' => $variations,
			'batches'    => $batches,
		);
	}
}
