<?php
// Standalone unit tests for ORDELIST_Delivery_Notice::vacation_active (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ordelist-delivery-notice.php';

$fails = 0;
function ck( $cond, $msg ) { global $fails; echo ( $cond ? "ok   - " : "FAIL - " ) . "$msg\n"; if ( ! $cond ) { $fails++; } }
function va( $until, $today ) { return ORDELIST_Delivery_Notice::vacation_active( $until, $today ); }

ck( va( '', '2026-06-25' ) === false, "empty until -> false" );
ck( va( 'soon', '2026-06-25' ) === false, "non-date until -> false" );
ck( va( '2026-13-40', '2026-06-25' ) === false, "calendar-invalid until -> false" );
ck( va( '2026-06-24', '2026-06-25' ) === false, "yesterday -> false (expired)" );
ck( va( '2026-06-25', '2026-06-25' ) === true, "today -> true" );
ck( va( '2026-06-30', '2026-06-25' ) === true, "future -> true" );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
