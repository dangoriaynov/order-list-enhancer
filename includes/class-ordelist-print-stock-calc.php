<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Чиста логіка обліку витратних матеріалів (без WordPress).
 * Тестується ізольовано - див. tests/print-stock/test-calc.php.
 */
class ORDELIST_Print_Stock_Calc {

	/** Статуси, за яких замовлення НЕ споживає витратні (можна повернути). */
	const DEAD_STATUSES = array( 'cancelled', 'failed', 'refunded', 'trash', 'auto-draft', 'checkout-draft', 'draft' );

	/**
	 * Дельти списання по витратних для позицій замовлення.
	 *
	 * @param array $lines  [ ['product_id'=>int,'variation_id'=>int,'qty'=>int], ... ]
	 * @param array $config [ 'stickers'=>[ref_id=>cid], 'instructions'=>[ ['id'=>cid,'product_ids'=>[..]], ... ] ]
	 * @return array [ cid => delta<0 ]
	 */
	public static function compute( array $lines, array $config ) {
		$stickers     = isset( $config['stickers'] ) && is_array( $config['stickers'] ) ? $config['stickers'] : array();
		$instructions = isset( $config['instructions'] ) && is_array( $config['instructions'] ) ? $config['instructions'] : array();

		$deltas   = array();
		$products = array(); // унікальні батьківські product_id у замовленні

		foreach ( $lines as $line ) {
			$pid = (int) ( $line['product_id'] ?? 0 );
			$vid = (int) ( $line['variation_id'] ?? 0 );
			$qty = (int) ( $line['qty'] ?? 0 );
			if ( $qty <= 0 ) {
				continue;
			}
			if ( $pid > 0 ) {
				$products[ $pid ] = true;
			}
			$ref = $vid > 0 ? $vid : $pid;
			if ( $ref > 0 && isset( $stickers[ $ref ] ) ) {
				$cid            = (int) $stickers[ $ref ];
				$deltas[ $cid ] = ( $deltas[ $cid ] ?? 0 ) - $qty;
			}
		}

		foreach ( $instructions as $sheet ) {
			$cid  = (int) ( $sheet['id'] ?? 0 );
			$pids = isset( $sheet['product_ids'] ) && is_array( $sheet['product_ids'] ) ? $sheet['product_ids'] : array();
			if ( $cid <= 0 ) {
				continue;
			}
			foreach ( $pids as $p ) {
				if ( isset( $products[ (int) $p ] ) ) {
					$deltas[ $cid ] = ( $deltas[ $cid ] ?? 0 ) - 1;
					break; // -1 на замовлення, незалежно від к-сті збігів
				}
			}
		}

		return $deltas;
	}

	/** Чи перетнув залишок поріг «згори вниз» саме цим рухом (edge-тригер). */
	public static function crosses_low( $before, $after, $threshold ) {
		return (int) $before > (int) $threshold && (int) $after <= (int) $threshold;
	}

	/** Чи «живе» замовлення (споживає витратні) за його статусом. */
	public static function is_live( $status ) {
		return ! in_array( (string) $status, self::DEAD_STATUSES, true );
	}

	/** Позитивні унікальні int-ID вкладень; порядок збережено, ключі переіндексовано. */
	public static function sanitize_attachment_ids( $ids ) {
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** JSON-рядок з БД (колонка attachments) -> масив ID; будь-яке сміття -> array(). */
	public static function decode_attachments( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		// Ensure it came from a JSON array, not an object (only accept numeric-keyed arrays)
		if ( ! empty( $decoded ) && array_keys( $decoded ) !== range( 0, count( $decoded ) - 1 ) ) {
			return array();
		}

		return self::sanitize_attachment_ids( $decoded );
	}
}
