const OC = require( '../../assets/js/ole-order-color.js' );
let fails = 0;
function ck( c, m ) { console.log( ( c ? 'ok   - ' : 'FAIL - ' ) + m ); if ( ! c ) fails++; }

const rules = [
	{ threshold: 200, color: '#200', label: 'VIP' },
	{ threshold: 150, color: '#150', label: '' },
	{ threshold: 100, color: '#100', label: '' },
];

// matchTotal: highest matched threshold wins; >= boundary; nothing below the lowest.
ck( OC.matchTotal( 250, rules ).color === '#200', 'total 250 -> highest (200) rule' );
ck( OC.matchTotal( 200, rules ).color === '#200', 'total 200 -> 200 rule (>= boundary)' );
ck( OC.matchTotal( 150, rules ).color === '#150', 'total 150 -> 150 rule' );
ck( OC.matchTotal( 149.99, rules ).color === '#100', 'total 149.99 -> 100 rule' );
ck( OC.matchTotal( 99, rules ) === null, 'total 99 -> no match (below lowest)' );
ck( OC.matchTotal( null, rules ) === null, 'null total -> no match' );
ck( OC.matchTotal( 250, [] ) === null, 'no rules -> no match' );

// parseAmount: handles the separator combinations WooCommerce can render.
ck( OC.parseAmount( '1 234,50 лв.', { decimal: ',', thousand: ' ' } ) === 1234.5, 'space-thousand, comma-decimal (nbsp)' );
ck( OC.parseAmount( '$1,234.50', { decimal: '.', thousand: ',' } ) === 1234.5, 'comma-thousand, dot-decimal' );
ck( OC.parseAmount( '1.234,50 лв.', { decimal: ',', thousand: '.' } ) === 1234.5, 'dot-thousand, comma-decimal' );
ck( OC.parseAmount( '49,00 лв.', { decimal: ',', thousand: ' ' } ) === 49, 'plain comma-decimal' );
ck( OC.parseAmount( '120', { decimal: ',', thousand: ' ' } ) === 120, 'integer only' );
ck( OC.parseAmount( 'abc', { decimal: ',', thousand: ' ' } ) === null, 'non-numeric -> null' );

console.log( fails ? '\n' + fails + ' FAILED' : '\nALL PASS' );
process.exit( fails ? 1 : 0 );
