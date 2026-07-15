<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Готує правила кольорування за сумою замовлення для передачі у JS.
 * Сортує за спаданням порога - JS бере перше правило, де сума >= порога
 * («вищий поріг перемагає»).
 */
class ORDELIST_Order_Color {

	public static function for_js( $opts ) {
		$rules = array();
		$src   = isset( $opts['total_color_rules'] ) ? (array) $opts['total_color_rules'] : array();
		foreach ( $src as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$threshold = isset( $r['threshold'] ) ? (float) $r['threshold'] : 0;
			$color     = isset( $r['color'] ) ? (string) $r['color'] : '';
			if ( $threshold <= 0 || '' === $color ) {
				continue;
			}
			$rules[] = array(
				'threshold' => $threshold,
				'color'     => $color,
				'label'     => isset( $r['label'] ) ? (string) $r['label'] : '',
			);
		}
		usort(
			$rules,
			function ( $a, $b ) {
				if ( $a['threshold'] === $b['threshold'] ) {
					return 0;
				}
				return ( $a['threshold'] < $b['threshold'] ) ? 1 : -1; // descending
			}
		);
		return array( 'rules' => $rules );
	}
}
