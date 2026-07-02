<?php
// Standalone unit tests for OLE_Print_Stock_Calc (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ole-print-stock-calc.php';

$fails = 0;
function check( $cond, $msg ) {
	global $fails;
	if ( $cond ) { echo "ok   - $msg\n"; } else { echo "FAIL - $msg\n"; $fails++; }
}

// Config: product 10 -> sticker cid 100, variation 21 -> sticker cid 101.
// Sheet cid 200 covers products 10 and 30; sheet cid 201 covers product 99.
$config = array(
	'stickers'     => array( 10 => 100, 21 => 101 ),
	'instructions' => array(
		array( 'id' => 200, 'product_ids' => array( 10, 30 ) ),
		array( 'id' => 201, 'product_ids' => array( 99 ) ),
	),
);

// Case A: 3x product 10 (simple) + 1x variation 21 (parent 30).
$lines = array(
	array( 'product_id' => 10, 'variation_id' => 0,  'qty' => 3 ),
	array( 'product_id' => 30, 'variation_id' => 21, 'qty' => 1 ),
);
$d = OLE_Print_Stock_Calc::compute( $lines, $config );
check( ( $d[100] ?? 0 ) === -3, 'sticker for product 10 = -3 (by qty)' );
check( ( $d[101] ?? 0 ) === -1, 'sticker for variation 21 = -1 (keyed on variation_id)' );
check( ( $d[200] ?? 0 ) === -1, 'sheet 200 = -1 once (covers products 10 and 30, both present)' );
check( ! isset( $d[201] ), 'sheet 201 untouched (its product 99 absent)' );

// Case B: same product twice as two lines -> sticker sums, sheet still -1.
$lines2 = array(
	array( 'product_id' => 10, 'variation_id' => 0, 'qty' => 2 ),
	array( 'product_id' => 10, 'variation_id' => 0, 'qty' => 4 ),
);
$d2 = OLE_Print_Stock_Calc::compute( $lines2, $config );
check( ( $d2[100] ?? 0 ) === -6, 'sticker sums across duplicate lines (-6)' );
check( ( $d2[200] ?? 0 ) === -1, 'sheet still -1 for duplicate product lines' );

// Case C: untracked product (no sticker row, in no sheet) -> no deltas.
$d3 = OLE_Print_Stock_Calc::compute( array( array( 'product_id' => 500, 'variation_id' => 0, 'qty' => 9 ) ), $config );
check( $d3 === array(), 'untracked product yields no deltas' );

// Case D: empty lines / empty config.
check( OLE_Print_Stock_Calc::compute( array(), $config ) === array(), 'no lines -> no deltas' );
check( OLE_Print_Stock_Calc::compute( $lines, array() ) === array(), 'empty config -> no deltas' );

// crosses_low: edge only.
check( OLE_Print_Stock_Calc::crosses_low( 21, 20, 20 ) === true,  'above->at threshold crosses' );
check( OLE_Print_Stock_Calc::crosses_low( 25, 19, 20 ) === true,  'above->below threshold crosses' );
check( OLE_Print_Stock_Calc::crosses_low( 20, 19, 20 ) === false, 'already at threshold does not re-cross' );
check( OLE_Print_Stock_Calc::crosses_low( 5, -2, 20 ) === false,  'staying below does not re-cross' );
check( OLE_Print_Stock_Calc::crosses_low( 1, 21, 20 ) === false,  'rising above does not cross' );

// is_live.
check( OLE_Print_Stock_Calc::is_live( 'processing' ) === true,  'processing is live' );
check( OLE_Print_Stock_Calc::is_live( 'completed' ) === true,   'completed is live' );
check( OLE_Print_Stock_Calc::is_live( 'pending' ) === true,     'pending is live' );
check( OLE_Print_Stock_Calc::is_live( 'on-hold' ) === true,     'on-hold is live' );
check( OLE_Print_Stock_Calc::is_live( 'cancelled' ) === false,  'cancelled is dead' );
check( OLE_Print_Stock_Calc::is_live( 'failed' ) === false,     'failed is dead' );
check( OLE_Print_Stock_Calc::is_live( 'refunded' ) === false,   'refunded is dead' );
check( OLE_Print_Stock_Calc::is_live( 'trash' ) === false,      'trash is dead' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
