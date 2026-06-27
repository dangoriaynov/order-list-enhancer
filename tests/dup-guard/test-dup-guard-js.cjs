const { stripMarker } = require( '../../assets/js/ole-dup-guard.js' );
let fails = 0;
function ck( c, m ) { console.log( ( c ? 'ok   - ' : 'FAIL - ' ) + m ); if ( ! c ) fails++; }

ck( stripMarker( 'OLEDUP|Вече направихте поръчка преди 3 мин.' ) === 'Вече направихте поръчка преди 3 мин.', 'strips OLEDUP| prefix' );
ck( stripMarker( 'Some other checkout error' ) === null, 'non-dup notice -> null' );
ck( stripMarker( '' ) === null, 'empty -> null' );
ck( stripMarker( '  OLEDUP|x' ) === 'x', 'tolerates leading whitespace' );

console.log( fails ? '\n' + fails + ' FAILED' : '\nALL PASS' );
process.exit( fails ? 1 : 0 );
