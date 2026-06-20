<?php
// Standalone unit tests for OLE_Phone_Validator (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ole-phone-validator.php';

$fails = 0;
function ck( $cond, $msg ) { global $fails; echo ( $cond ? "ok   - " : "FAIL - " ) . "$msg\n"; if ( ! $cond ) { $fails++; } }
function v( $raw ) { return OLE_Phone_Validator::validate( $raw ); }

// Valid Bulgarian forms (all → +359888123456).
foreach ( array( '0888123456', '+359888123456', '00359888123456', '359888123456', '888123456', '0888 123 456', '0888/123-456', '(+359) 888 123 456' ) as $in ) {
	$r = v( $in );
	ck( $r['valid'] && $r['normalized'] === '+359888123456', "valid: '$in' -> +359888123456" );
}
// Valid Sofia landline (NSN 8 digits, first '2').
$r = v( '028123456' ); ck( $r['valid'] && $r['normalized'] === '+35928123456', "valid Sofia landline 028123456" );

// Invalid cases with specific reasons.
ck( v( '' )['reason'] === 'empty', "empty -> empty" );
ck( v( '   ' )['reason'] === 'empty', "spaces -> empty" );
ck( v( '12345' )['reason'] === 'too_short', "12345 -> too_short" );
ck( v( '08881234567' )['reason'] === 'too_long', "08881234567 (NSN 10) -> too_long" );
ck( v( '0188123456' )['reason'] === 'invalid', "0188123456 (NSN starts 1) -> invalid" );
ck( v( '+4915112345678' )['reason'] === 'not_bulgarian', "+49… -> not_bulgarian" );
ck( v( '004915112345' )['reason'] === 'not_bulgarian', "0049… -> not_bulgarian" );
$bad = v( '12345' ); ck( $bad['valid'] === false && $bad['normalized'] === '', "invalid has valid=false, normalized=''" );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
