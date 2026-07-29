// Standalone test for markDuplicates() in ole-admin.js: the row coloring and the
// badge are separate settings, so each flag combination has to draw exactly its
// own part. No WordPress, no browser - ole-admin.js is driven against a fake DOM.

let fails = 0;
function ck( c, m ) { console.log( ( c ? 'ok   - ' : 'FAIL - ' ) + m ); if ( ! c ) fails++; }

const ADMIN_JS = require.resolve( '../../assets/js/ole-admin.js' );

function makeEl( tag ) {
	const el = {
		tagName: tag,
		className: '',
		textContent: '',
		title: '',
		children: [],
		attrs: {},
		classes: [],
		styleProps: {},
		style: {},
		classList: { add: function ( c ) { el.classes.push( c ); } },
		setAttribute: function ( k, v ) { el.attrs[ k ] = String( v ); },
		getAttribute: function ( k ) { return Object.prototype.hasOwnProperty.call( el.attrs, k ) ? el.attrs[ k ] : null; },
		appendChild: function ( c ) { el.children.push( c ); return c; },
		querySelector: function () { return null; },
		querySelectorAll: function () { return []; },
		addEventListener: function () {},
	};
	el.style.setProperty = function ( k, v ) { el.styleProps[ k ] = v; };
	return el;
}

// One orders-list row: a checkbox carrying the order id, and a first cell the
// badge would be appended to.
function makeRow( orderId ) {
	const row  = makeEl( 'tr' );
	const cell = makeEl( 'td' );
	row.cell = cell;
	row.querySelector = function ( sel ) {
		if ( sel.indexOf( 'check-column' ) !== -1 ) { return { value: String( orderId ) }; }
		if ( sel.indexOf( 'order_number' ) !== -1 ) { return cell; }
		return null;
	};
	row.querySelectorAll = function () { return [ cell ]; };
	return row;
}

// Runs ole-admin.js fresh with the given flags and returns the single row it drew on.
function run( flags ) {
	const row = makeRow( 6213 );
	const body = makeEl( 'body' );

	global.window = {
		ORDELIST_DATA: {
			context: 'list',
			flags: Object.assign( { duplicates: true, shipping: false, copy: {} }, flags ),
			map: { 6213: { g: 1, n: 2, dup: false, r: 'phone' } },
			groups: { 1: { n: 2, orders: [] } },
			palette: [ '#d63638', '#b26a00' ],
			i18n: { badge: 'customer %s', dupBadge: 'duplicate %s', badgeTitle: 'matches %s' },
			ajax: { url: '/ajax', nonce: 'n' },
			totalColor: { on: false, rules: [] },
		},
		MutationObserver: null,
	};
	global.document = {
		body: body,
		documentElement: { lang: 'bg' },
		querySelectorAll: function ( sel ) {
			return ( '.wp-list-table tbody tr' === sel ) ? [ row ] : [];
		},
		querySelector: function () { return null; },
		getElementById: function () { return null; },
		createElement: makeEl,
		addEventListener: function () {},
	};

	delete require.cache[ ADMIN_JS ];
	require( ADMIN_JS );

	const badge = row.cell.children.filter( c => -1 !== String( c.className ).indexOf( 'ole-badge' ) )[ 0 ] || null;
	return {
		colored: -1 !== row.classes.indexOf( 'ole-dup' ),
		bd: row.styleProps['--ole-bd'],
		bg: row.styleProps['--ole-bg'],
		badge: badge,
	};
}

// Both on: today's behaviour, unchanged.
let r = run( { dupColor: true, dupBadge: true } );
ck( r.colored, 'both on: the row is marked for coloring' );
ck( !! r.bd && !! r.bg, 'both on: the row gets its outline and fill colors' );
ck( !! r.badge, 'both on: the badge is added' );

// Coloring off, badge on: the badge must survive on an unstyled row.
r = run( { dupColor: false, dupBadge: true } );
ck( ! r.colored, 'coloring off: the row is not marked' );
ck( ! r.bd && ! r.bg, 'coloring off: no outline or fill color is set' );
ck( !! r.badge, 'coloring off: the badge is still added' );
ck( r.badge && -1 !== String( r.badge.className ).indexOf( 'ole-badge--click' ), 'coloring off: the badge stays clickable for the modal' );

// Badge off, coloring on: the row keeps its colors, nothing is appended.
r = run( { dupColor: true, dupBadge: false } );
ck( r.colored, 'badge off: the row is still marked for coloring' );
ck( !! r.bd && !! r.bg, 'badge off: the outline and fill colors are still set' );
ck( ! r.badge, 'badge off: no badge is added' );

// Both off: nothing at all. The server stops scanning in this case, but the
// browser must not draw anything either if it is told both are off.
r = run( { duplicates: false, dupColor: false, dupBadge: false } );
ck( ! r.colored && ! r.bd && ! r.badge, 'both off: the row is left untouched' );

console.log( fails ? '\n' + fails + ' FAILED' : '\nALL PASS' );
process.exit( fails ? 1 : 0 );
