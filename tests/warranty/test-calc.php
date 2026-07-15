<?php
// Standalone unit tests for ORDELIST_Warranty_Calc (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ordelist-warranty-calc.php';

$fails = 0;
function check( $cond, $msg ) {
	global $fails;
	if ( $cond ) { echo "ok   - $msg\n"; } else { echo "FAIL - $msg\n"; $fails++; }
}

// ---- allocate ----
$b = array(
	array( 'id' => 1, 'qty' => 5 ),  // oldest expiry
	array( 'id' => 2, 'qty' => 3 ),
	array( 'id' => 3, 'qty' => 4 ),  // newest expiry
);
check( ORDELIST_Warranty_Calc::allocate( 2, $b ) === array( 1 => 2 ), 'single batch covers demand' );
check( ORDELIST_Warranty_Calc::allocate( 5, $b ) === array( 1 => 5 ), 'exact fit drains oldest batch only' );
check( ORDELIST_Warranty_Calc::allocate( 7, $b ) === array( 1 => 5, 2 => 2 ), 'cascade into second batch' );
check( ORDELIST_Warranty_Calc::allocate( 12, $b ) === array( 1 => 5, 2 => 3, 3 => 4 ), 'exact fit across all batches' );
check( ORDELIST_Warranty_Calc::allocate( 15, $b ) === array( 1 => 5, 2 => 3, 3 => 7 ), 'over-demand pushes newest batch negative (4-7=-3)' );
check( ORDELIST_Warranty_Calc::allocate( 3, array() ) === array(), 'no batches -> empty map' );
check( ORDELIST_Warranty_Calc::allocate( 0, $b ) === array(), 'zero demand -> empty map' );
$holey = array(
	array( 'id' => 4, 'qty' => 0 ),   // already sold out
	array( 'id' => 5, 'qty' => -2 ),  // already negative
	array( 'id' => 6, 'qty' => 3 ),
);
check( ORDELIST_Warranty_Calc::allocate( 2, $holey ) === array( 6 => 2 ), 'skips qty<=0 batches' );
$dead = array(
	array( 'id' => 7, 'qty' => 0 ),
	array( 'id' => 8, 'qty' => 0 ),
);
check( ORDELIST_Warranty_Calc::allocate( 4, $dead ) === array( 8 => 4 ), 'all-zero batches: newest takes the whole demand' );

// ---- add_days / status ----
check( ORDELIST_Warranty_Calc::add_days( '2026-07-13', 30 ) === '2026-08-12', 'add_days crosses a month' );
check( ORDELIST_Warranty_Calc::add_days( '2026-12-25', 10 ) === '2027-01-04', 'add_days crosses a year' );
check( ORDELIST_Warranty_Calc::status( '2026-07-12', '2026-07-13', 30 ) === 'expired', 'yesterday is expired' );
check( ORDELIST_Warranty_Calc::status( '2026-07-13', '2026-07-13', 30 ) === 'soon',    'today itself is soon (not yet expired)' );
check( ORDELIST_Warranty_Calc::status( '2026-08-12', '2026-07-13', 30 ) === 'soon',    'today+window boundary is soon' );
check( ORDELIST_Warranty_Calc::status( '2026-08-13', '2026-07-13', 30 ) === 'ok',      'today+window+1 is ok' );

// ---- classify ----
$rows = array(
	array( 'id' => 1, 'expiry' => '2026-07-01', 'qty' => 5,  'notified' => 0 ), // expired, unwarned
	array( 'id' => 2, 'expiry' => '2026-07-01', 'qty' => 5,  'notified' => 2 ), // expired, already warned
	array( 'id' => 3, 'expiry' => '2026-07-01', 'qty' => 0,  'notified' => 0 ), // expired but sold out -> silent
	array( 'id' => 4, 'expiry' => '2026-08-01', 'qty' => 9,  'notified' => 0 ), // soon, unwarned
	array( 'id' => 5, 'expiry' => '2026-08-01', 'qty' => 9,  'notified' => 1 ), // soon, already warned
	array( 'id' => 6, 'expiry' => '2026-07-05', 'qty' => 9,  'notified' => 1 ), // was warned soon, NOW expired -> warn again
	array( 'id' => 7, 'expiry' => '2026-12-01', 'qty' => 9,  'notified' => 0 ), // far future -> silent
	array( 'id' => 8, 'expiry' => '2026-08-01', 'qty' => -1, 'notified' => 0 ), // negative qty -> silent
);
$c = ORDELIST_Warranty_Calc::classify( $rows, '2026-07-13', 30 );
$soon_ids    = array_map( function ( $r ) { return $r['id']; }, $c['soon'] );
$expired_ids = array_map( function ( $r ) { return $r['id']; }, $c['expired'] );
check( $soon_ids === array( 4 ), 'soon: only unwarned positive-qty rows inside the window' );
check( $expired_ids === array( 1, 6 ), 'expired: unwarned + soon-warned-now-expired rows' );

// ---- clean_date ----
check( ORDELIST_Warranty_Calc::clean_date( '2026-08-01' ) === '2026-08-01', 'clean_date passes a valid date' );
check( ORDELIST_Warranty_Calc::clean_date( '2026-02-30' ) === '', 'clean_date rejects Feb 30' );
check( ORDELIST_Warranty_Calc::clean_date( '01/08/2026' ) === '', 'clean_date rejects wrong format' );
check( ORDELIST_Warranty_Calc::clean_date( '' ) === '', 'clean_date rejects empty' );

// ---- stock_expiry (бърз запис на наличност: празно -> далечен страж) ----
check( ORDELIST_Warranty_Calc::stock_expiry( '2027-01-15' ) === '2027-01-15', 'stock_expiry keeps a valid date' );
check( ORDELIST_Warranty_Calc::stock_expiry( '' ) === '2099-12-31', 'stock_expiry: empty -> far-future sentinel' );
check( ORDELIST_Warranty_Calc::stock_expiry( 'garbage' ) === null, 'stock_expiry: invalid -> null (reject)' );
check( ORDELIST_Warranty_Calc::stock_expiry( '2026-13-01' ) === null, 'stock_expiry: impossible date -> null' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
