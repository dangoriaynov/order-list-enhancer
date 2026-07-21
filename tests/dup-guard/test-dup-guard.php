<?php
// Standalone unit tests for ORDELIST_Dup_Guard::find_match (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ordelist-dup-guard.php';

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

$r = ORDELIST_Dup_Guard::find_match( $cur, array( cand( array( 'number' => '11077', 'created_ts' => $now - 180 ) ) ), 5, $now );
ck( $r && '11077' === $r['number'] && 3 === $r['mins'], "cart_hash match within window -> 11077, 3 min" );

$r = ORDELIST_Dup_Guard::find_match( $cur, array( cand( array( 'created_ts' => $now - 6 * 60 ) ) ), 5, $now );
ck( null === $r, "outside 5-min window -> no match" );

$r = ORDELIST_Dup_Guard::find_match( $cur, array( cand( array( 'phone' => '+359999999999', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null === $r, "different phone -> no match" );

foreach ( array( 'cancelled', 'failed', 'trash', 'refunded', 'checkout-draft', 'auto-draft' ) as $dead ) {
	$r = ORDELIST_Dup_Guard::find_match( $cur, array( cand( array( 'status' => $dead, 'created_ts' => $now - 60 ) ) ), 5, $now );
	ck( null === $r, "dead status '$dead' -> no match" );
}
// 'pending' stays LIVE (COD/bank-transfer orders sit pending and are real duplicates).
$r = ORDELIST_Dup_Guard::find_match( $cur, array( cand( array( 'status' => 'pending', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null !== $r, "pending status stays live -> matches" );

$r = ORDELIST_Dup_Guard::find_match( $cur, array( cand( array( 'phone' => '', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null === $r, "candidate empty phone -> no match" );
$rcur = ORDELIST_Dup_Guard::find_match( array( 'phone' => '', 'cart_hash' => 'H', 'items_sig' => '12:0:5', 'total' => '15.35' ), array( cand( array( 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null === $rcur, "current empty phone -> no match (don't block guests w/o phone)" );

// Fallback: cart_hash empty on both -> match by items_sig + total.
$cur2 = array( 'phone' => '+359888123456', 'cart_hash' => '', 'items_sig' => '12:0:5', 'total' => '15.35' );
$r = ORDELIST_Dup_Guard::find_match( $cur2, array( cand( array( 'cart_hash' => '', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null !== $r, "empty cart_hash both -> fallback items_sig+total match" );

$r = ORDELIST_Dup_Guard::find_match( $cur2, array( cand( array( 'cart_hash' => '', 'total' => '99.00', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null === $r, "fallback total mismatch -> no match" );

$r = ORDELIST_Dup_Guard::find_match( $cur2, array( cand( array( 'cart_hash' => '', 'items_sig' => '99:0:1', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null === $r, "fallback items mismatch -> no match" );

// Asymmetric cart_hash — current empty, candidate has a hash -> fallback by items_sig+total.
$cur3 = array( 'phone' => '+359888123456', 'cart_hash' => '', 'items_sig' => '12:0:5', 'total' => '15.35' );
$r = ORDELIST_Dup_Guard::find_match( $cur3, array( cand( array( 'cart_hash' => 'H', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null !== $r, "current empty hash, candidate has hash -> fallback match" );

// Asymmetric — current has a hash, candidate empty -> fallback by items_sig+total.
$r = ORDELIST_Dup_Guard::find_match( $cur, array( cand( array( 'cart_hash' => '', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null !== $r, "current has hash, candidate empty -> fallback match" );

// Two different NON-empty hashes -> no match (fallback must not fire).
$r = ORDELIST_Dup_Guard::find_match( $cur, array( cand( array( 'cart_hash' => 'OTHER', 'created_ts' => $now - 60 ) ) ), 5, $now );
ck( null === $r, "different non-empty cart_hash -> no match" );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
