<?php
// Standalone unit tests for OLE_Dup_Guard::find_match (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ole-dup-guard.php';

$fails = 0;
function ck( $cond, $msg ) { global $fails; echo ( $cond ? "ok   - " : "FAIL - " ) . "$msg\n"; if ( ! $cond ) { $fails++; } }
function cand( $o ) {
	return array_merge(
		array( 'number' => '1', 'phone' => '+359888123456', 'cart_hash' => 'H', 'items_sig' => '12:0:5', 'total' => '15.35', 'created_ts' => 0, 'status' => 'processing' ),
		$o
	);
}
$now = 1000000;
$cur = array( 'phone' => '+359888123456', 'cart_hash' => 'H', 'items_sig' => '12:0:5', 'total' => '15.35' );

$r = OLE_Dup_Guard::find_match( $cur, array( cand( array( 'number' => '11077', 'created_ts' => $now - 180 ) ) ), 5, $now );
ck( $r && '11077' === $r['number'] && 3 === $r['mins'], "cart_hash match within window -> 11077, 3 min" );

$r = OLE_Dup_Guard::find_match( $cur, array( cand( array( 'created_ts' => $now - 6 * 60 ) ) ), 5, $now );
ck( null === $r, "outside 5-min window -> no match" );

$r = OLE_Dup_Guard::find_match( $cur, array( cand( array( 'phone' => '+359999999999', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null === $r, "different phone -> no match" );

foreach ( array( 'cancelled', 'failed', 'trash' ) as $dead ) {
	$r = OLE_Dup_Guard::find_match( $cur, array( cand( array( 'status' => $dead, 'created_ts' => $now - 60 ) ) ), 5, $now );
	ck( null === $r, "dead status '$dead' -> no match" );
}

$r = OLE_Dup_Guard::find_match( $cur, array( cand( array( 'phone' => '', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null === $r, "candidate empty phone -> no match" );
$rcur = OLE_Dup_Guard::find_match( array( 'phone' => '', 'cart_hash' => 'H', 'items_sig' => '12:0:5', 'total' => '15.35' ), array( cand( array( 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null === $rcur, "current empty phone -> no match (don't block guests w/o phone)" );

// Fallback: cart_hash empty on both -> match by items_sig + total.
$cur2 = array( 'phone' => '+359888123456', 'cart_hash' => '', 'items_sig' => '12:0:5', 'total' => '15.35' );
$r = OLE_Dup_Guard::find_match( $cur2, array( cand( array( 'cart_hash' => '', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null !== $r, "empty cart_hash both -> fallback items_sig+total match" );

$r = OLE_Dup_Guard::find_match( $cur2, array( cand( array( 'cart_hash' => '', 'total' => '99.00', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null === $r, "fallback total mismatch -> no match" );

$r = OLE_Dup_Guard::find_match( $cur2, array( cand( array( 'cart_hash' => '', 'items_sig' => '99:0:1', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null === $r, "fallback items mismatch -> no match" );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
