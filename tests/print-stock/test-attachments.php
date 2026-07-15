<?php
// Standalone unit tests for attachment-ID helpers (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ordelist-print-stock-calc.php';

$fails = 0;
function check( $cond, $msg ) {
	global $fails;
	if ( $cond ) { echo "ok   - $msg\n"; } else { echo "FAIL - $msg\n"; $fails++; }
}

// sanitize_attachment_ids
check( ORDELIST_Print_Stock_Calc::sanitize_attachment_ids( array( 5, 3, 5, '7', 0, -2, 'x' ) ) === array( 5, 3, 7 ), 'sanitize: dedup, order kept, non-positive and junk dropped' );
check( ORDELIST_Print_Stock_Calc::sanitize_attachment_ids( array() ) === array(), 'sanitize: empty in, empty out' );
check( ORDELIST_Print_Stock_Calc::sanitize_attachment_ids( 'nope' ) === array(), 'sanitize: non-array input -> empty' );
check( ORDELIST_Print_Stock_Calc::sanitize_attachment_ids( array( '12', 12.9 ) ) === array( 12 ), 'sanitize: strings/floats cast to int, dedup' );

// decode_attachments
check( ORDELIST_Print_Stock_Calc::decode_attachments( '[3,9,3]' ) === array( 3, 9 ), 'decode: JSON array -> sanitized ids' );
check( ORDELIST_Print_Stock_Calc::decode_attachments( null ) === array(), 'decode: null -> empty' );
check( ORDELIST_Print_Stock_Calc::decode_attachments( '' ) === array(), 'decode: empty string -> empty' );
check( ORDELIST_Print_Stock_Calc::decode_attachments( '{"a":1}' ) === array(), 'decode: JSON object -> empty' );
check( ORDELIST_Print_Stock_Calc::decode_attachments( 'garbage' ) === array(), 'decode: invalid JSON -> empty' );

echo $fails ? "\n$fails FAILED\n" : "\nALL OK\n";
exit( $fails ? 1 : 0 );
