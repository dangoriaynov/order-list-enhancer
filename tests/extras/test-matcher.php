<?php
// Standalone unit tests for OLE_Extras_Matcher (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ole-extras-matcher.php';

$fails = 0;
function check( $cond, $msg ) {
	global $fails;
	if ( $cond ) { echo "ok   - $msg\n"; } else { echo "FAIL - $msg\n"; $fails++; }
}

// normalize: trim, collapse whitespace, lowercase (incl. Cyrillic).
check( OLE_Extras_Matcher::normalize( "  + 500 г  ЯНТАРНА  " ) === '+ 500 г янтарна', 'normalize trims/collapses/lowercases cyrillic' );

// index: keeps valid rows, drops empties / zero product.
$idx = OLE_Extras_Matcher::index( array(
	array( 'match' => '+ 500 г янтарна киселина', 'product' => 3907 ),
	array( 'match' => '',                          'product' => 10 ),
	array( 'match' => 'x',                         'product' => 0 ),
) );
check( count( $idx ) === 1 && $idx['+ 500 г янтарна киселина'] === 3907, 'index keeps only valid rows' );

// match: case/space-insensitive hit, miss returns 0.
check( OLE_Extras_Matcher::match( $idx, '+ 500 Г  Янтарна Киселина' ) === 3907, 'match is case/space-insensitive' );
check( OLE_Extras_Matcher::match( $idx, '+ unknown' ) === 0, 'match miss returns 0' );
check( OLE_Extras_Matcher::index( 'not-array' ) === array(), 'index tolerates non-array' );
check( OLE_Extras_Matcher::match( 'bad', 'label' ) === 0, 'match tolerates non-array index' );

// parse_addons: extract from _pao_ids array.
$pao = array(
	array( 'key' => 'Екстри', 'value' => '+ 16 бр pH тест ленти', 'id' => '169', 'raw_value' => '+ 16 бр pH тест ленти', 'raw_price' => 1, 'price_type' => 'flat_fee' ),
);
$parsed = OLE_Extras_Matcher::parse_addons( $pao );
check( count( $parsed ) === 1 && $parsed[0]['label'] === '+ 16 бр pH тест ленти' && $parsed[0]['price'] === 1.0 && $parsed[0]['field'] === 'Екстри', 'parse_addons extracts fields' );
check( OLE_Extras_Matcher::parse_addons( 'not-array' ) === array(), 'parse_addons tolerates non-array' );
$pao_diff = array( array( 'key' => 'F', 'value' => 'DISPLAY', 'raw_value' => 'RAW', 'raw_price' => 2, 'price_type' => 'flat_fee', 'id' => '9' ) );
$pd = OLE_Extras_Matcher::parse_addons( $pao_diff );
check( $pd[0]['label'] === 'DISPLAY' && $pd[0]['price'] === 2.0, 'parse_addons uses value (not raw_value) for label' );

// prices_balance.
check( OLE_Extras_Matcher::prices_balance( $parsed, 1.0 ) === true, 'prices_balance true when equal' );
check( OLE_Extras_Matcher::prices_balance( $parsed, 2.0 ) === false, 'prices_balance false when off' );
check( OLE_Extras_Matcher::prices_balance( $parsed, 1.005, 0.001 ) === false, 'prices_balance custom epsilon 0.001 rejects 0.005 diff' );
check( OLE_Extras_Matcher::prices_balance( $parsed, 1.005, 0.01 ) === true, 'prices_balance custom epsilon 0.01 accepts 0.005 diff' );

// parse_qty.
check( OLE_Extras_Matcher::parse_qty( '+ 2 бр фолиамин (аминокиселини)' ) === 2, 'parse_qty 2 бр' );
check( OLE_Extras_Matcher::parse_qty( '+ 5 бр цитокининова паста' ) === 5, 'parse_qty 5 бр' );
check( OLE_Extras_Matcher::parse_qty( '+ 1 бр Валтан' ) === 1, 'parse_qty 1 бр' );
check( OLE_Extras_Matcher::parse_qty( '3 бройки' ) === 3, 'parse_qty 3 бройки' );
check( OLE_Extras_Matcher::parse_qty( '+ 500 г янтарна киселина' ) === 1, 'parse_qty grams -> 1' );
check( OLE_Extras_Matcher::parse_qty( '' ) === 1, 'parse_qty empty -> 1' );
check( OLE_Extras_Matcher::parse_qty( '+ 16 бр pH тест ленти (5 мм х 50 мм)' ) === 16, 'parse_qty picks the бр count, not the мм' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
