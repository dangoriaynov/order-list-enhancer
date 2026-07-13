<?php
// Standalone unit tests for ORDELIST_Settings::migrate_copy_buttons (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ordelist-settings.php';

$fails = 0;
function ck( $cond, $msg ) { global $fails; echo ( $cond ? "ok   - " : "FAIL - " ) . "$msg\n"; if ( ! $cond ) { $fails++; } }

function mig( $saved ) {
	$opts = array_merge( ORDELIST_Settings::defaults(), $saved );
	return ORDELIST_Settings::migrate_copy_buttons( $saved, $opts );
}

// New keys default to yes.
$d = ORDELIST_Settings::defaults();
ck( 'yes' === $d['copy_name'] && 'yes' === $d['copy_phone'] && 'yes' === $d['copy_total'], 'defaults: all three yes' );
ck( ! isset( $d['copy_buttons'] ), 'defaults: legacy key gone' );

// No legacy key saved -> defaults untouched.
$o = mig( array() );
ck( 'yes' === $o['copy_name'] && 'yes' === $o['copy_phone'] && 'yes' === $o['copy_total'], 'no legacy -> defaults stay yes' );

// Legacy off -> all three inherit off.
$o = mig( array( 'copy_buttons' => 'no' ) );
ck( 'no' === $o['copy_name'] && 'no' === $o['copy_phone'] && 'no' === $o['copy_total'], 'legacy no -> all three no' );

// Legacy on -> all three on.
$o = mig( array( 'copy_buttons' => 'yes' ) );
ck( 'yes' === $o['copy_name'] && 'yes' === $o['copy_phone'] && 'yes' === $o['copy_total'], 'legacy yes -> all three yes' );

// A new key saved explicitly wins over the legacy value.
$o = mig( array( 'copy_buttons' => 'no', 'copy_phone' => 'yes' ) );
ck( 'no' === $o['copy_name'] && 'yes' === $o['copy_phone'] && 'no' === $o['copy_total'], 'explicit new key beats legacy' );

// Garbage legacy value is treated as no.
$o = mig( array( 'copy_buttons' => '1' ) );
ck( 'no' === $o['copy_name'], 'non-yes legacy -> no' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
