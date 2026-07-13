<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Готує правила кольорування доставки для передачі у JS.
 */
class ORDELIST_Shipping {

	public static function for_js( $opts ) {
		$rules = array();
		foreach ( (array) $opts['ship_rules'] as $r ) {
			$kw = isset( $r['keyword'] ) ? (string) $r['keyword'] : '';
			if ( '' === $kw ) {
				continue;
			}
			$rules[] = array(
				'keyword' => $kw,
				'color'   => isset( $r['color'] ) ? (string) $r['color'] : '',
				'label'   => isset( $r['label'] ) ? (string) $r['label'] : '',
			);
		}
		return array(
			'rules'   => $rules,
			'default' => array(
				'color' => (string) $opts['ship_default_color'],
				'label' => (string) $opts['ship_default_label'],
			),
		);
	}
}
