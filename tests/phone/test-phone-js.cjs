const v = require( '../../assets/js/ole-phone-checkout.js' );
let fails = 0;
function ck( c, m ) { console.log( ( c ? 'ok   - ' : 'FAIL - ' ) + m ); if ( ! c ) fails++; }
[ '0888123456', '+359888123456', '00359888123456', '359888123456', '888123456', '0888 123 456', '0888/123-456', '(+359) 888 123 456' ].forEach( function ( i ) {
	const r = v( i, '359' ); ck( r.valid && r.normalized === '+359888123456', "valid: '" + i + "'" );
} );
ck( v( '028123456', '359' ).normalized === '+35928123456', 'Sofia landline' );
ck( v( '', '359' ).reason === 'empty', 'empty' );
ck( v( '12345', '359' ).reason === 'too_short', 'too_short' );
ck( v( '08881234567', '359' ).reason === 'too_long', 'too_long' );
ck( v( '0188123456', '359' ).reason === 'invalid', 'invalid' );
ck( v( '+4915112345678', '359' ).reason === 'not_bulgarian', 'not_bulgarian' );
console.log( fails ? '\n' + fails + ' FAILED' : '\nALL PASS' );
process.exit( fails ? 1 : 0 );
