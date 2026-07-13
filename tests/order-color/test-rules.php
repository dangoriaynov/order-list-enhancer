<?php
// Standalone unit tests for the order-total coloring data layer (no WordPress).
define( 'ABSPATH', true );

// Minimal WP stubs used by ORDELIST_Settings::clean_total_color_rules.
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', (string) $s ) ); }
function sanitize_hex_color( $c ) { $c = (string) $c; return preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c ) ? $c : ''; }

require __DIR__ . '/../../includes/class-ordelist-settings.php';
require __DIR__ . '/../../includes/class-ordelist-order-color.php';

$fails = 0;
function ck( $cond, $msg ) { global $fails; echo ( $cond ? 'ok   - ' : 'FAIL - ' ) . "$msg\n"; if ( ! $cond ) { $fails++; } }

// clean_total_color_rules: keep only valid rows, cast float, sanitize.
$clean = ORDELIST_Settings::clean_total_color_rules( array(
	array( 'threshold' => '149.50', 'color' => '#ff0000', 'label' => '  big   order ' ),
	array( 'threshold' => 0,        'color' => '#00ff00', 'label' => 'zero' ),    // dropped: threshold 0
	array( 'threshold' => 50,       'color' => 'nope',    'label' => 'x' ),       // dropped: bad color
	array( 'threshold' => 100,      'color' => '#abc' ),                          // label optional
) );
ck( count( $clean ) === 2, 'clean keeps only the 2 valid rows' );
ck( $clean[0]['threshold'] === 149.5, 'threshold "149.50" cast to float 149.5' );
ck( $clean[0]['label'] === 'big order', 'label sanitized/collapsed' );
ck( $clean[1]['threshold'] === 100.0 && $clean[1]['label'] === '', 'missing label -> empty string' );

// for_js: sort descending by threshold, drop invalid rows.
$out = ORDELIST_Order_Color::for_js( array( 'total_color_rules' => array(
	array( 'threshold' => 100, 'color' => '#100' ),
	array( 'threshold' => 200, 'color' => '#200' ),
	array( 'threshold' => 150, 'color' => '#150' ),
	array( 'threshold' => 0,   'color' => '#000' ),  // dropped: threshold 0
	array( 'threshold' => 75,  'color' => '' ),      // dropped: no color
) ) );
$th = array_map( function ( $r ) { return $r['threshold']; }, $out['rules'] );
ck( $th === array( 200.0, 150.0, 100.0 ), 'for_js sorts thresholds descending and drops invalid' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
