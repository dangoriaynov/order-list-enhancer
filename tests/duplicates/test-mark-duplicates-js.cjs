// Standalone test for markDuplicates() in ole-admin.js: the row coloring and the
// badge are separate settings, so each flag combination has to draw exactly its
// own part. No WordPress, no browser - ole-admin.js is driven against a fake DOM.
//
// The fake row is built the way WP_List_Table really prints one: the checkbox
// cell is a <td class="check-column"> and the order-number column, being the
// primary one, is a <th scope="row">. A lookup restricted to td used to miss the
// <th> and fall back to the first <td> - the checkbox cell - so the badge came
// out under the checkbox, on top of the customer name (owner, 2026-09-26).

let fails = 0;
function ck( c, m ) { console.log( ( c ? 'ok   - ' : 'FAIL - ' ) + m ); if ( ! c ) fails++; }

const ADMIN_JS = require.resolve( '../../assets/js/ole-admin.js' );

// --- tiny DOM ------------------------------------------------------------

function classesOf( el ) {
	return String( el.className || '' ).split( /\s+/ ).filter( Boolean ).concat( el.classes || [] );
}

// Supports what ole-admin.js asks for: tag, .class, tag.class, tag:not(.class),
// [attr=value], comma-separated alternatives and descendant combinations.
function matchesSimple( el, sel ) {
	const not = [];
	sel = sel.replace( /:not\(\.([\w-]+)\)/g, function ( m, c ) { not.push( c ); return ''; } );
	const attrs = [];
	sel = sel.replace( /\[([\w-]+)=([^\]]+)\]/g, function ( m, k, v ) { attrs.push( [ k, v ] ); return ''; } );
	const parts = sel.split( '.' );
	const tag = parts.shift();
	const cls = classesOf( el );
	if ( tag && String( el.tagName ).toLowerCase() !== tag.toLowerCase() ) { return false; }
	if ( parts.some( c => c && -1 === cls.indexOf( c ) ) ) { return false; }
	if ( not.some( c => -1 !== cls.indexOf( c ) ) ) { return false; }
	if ( attrs.some( a => String( el.attrs[ a[ 0 ] ] ) !== a[ 1 ] ) ) { return false; }
	return true;
}

function descendants( el, out ) {
	out = out || [];
	( el.children || [] ).forEach( function ( c ) { out.push( c ); descendants( c, out ); } );
	return out;
}

function find( root, sel ) {
	const hits = [];
	String( sel ).split( ',' ).forEach( function ( alt ) {
		let scope = [ root ];
		alt.trim().split( /\s+/ ).forEach( function ( step ) {
			const next = [];
			scope.forEach( function ( s ) {
				descendants( s ).forEach( function ( d ) { if ( matchesSimple( d, step ) && -1 === next.indexOf( d ) ) { next.push( d ); } } );
			} );
			scope = next;
		} );
		scope.forEach( function ( n ) { if ( -1 === hits.indexOf( n ) ) { hits.push( n ); } } );
	} );
	return hits;
}

function makeEl( tag ) {
	const el = {
		tagName: tag,
		className: '',
		textContent: '',
		title: '',
		parent: null,
		children: [],
		attrs: {},
		classes: [],
		styleProps: {},
		style: {},
		classList: { add: function ( c ) { el.classes.push( c ); } },
		setAttribute: function ( k, v ) { el.attrs[ k ] = String( v ); },
		getAttribute: function ( k ) { return Object.prototype.hasOwnProperty.call( el.attrs, k ) ? el.attrs[ k ] : null; },
		appendChild: function ( c ) { c.parent = el; el.children.push( c ); return c; },
		insertBefore: function ( c, ref ) {
			const i = ref ? el.children.indexOf( ref ) : -1;
			if ( -1 === i ) { el.children.push( c ); } else { el.children.splice( i, 0, c ); }
			c.parent = el;
			return c;
		},
		querySelector: function ( sel ) { return find( el, sel )[ 0 ] || null; },
		querySelectorAll: function ( sel ) { return find( el, sel ); },
		addEventListener: function () {},
	};
	el.style.setProperty = function ( k, v ) { el.styleProps[ k ] = v; };
	Object.defineProperty( el, 'nextSibling', {
		get: function () {
			if ( ! el.parent ) { return null; }
			return el.parent.children[ el.parent.children.indexOf( el ) + 1 ] || null;
		},
	} );
	return el;
}

function el( tag, className, extra ) {
	const e = makeEl( tag );
	e.className = className || '';
	Object.assign( e, extra || {} );
	return e;
}

// One orders-list row, as WooCommerce prints it: checkbox cell, then the order
// number column as the row header, carrying the order link and the date/status
// blocks that only show on small screens.
function makeRow( orderId ) {
	const row = el( 'tr' );
	const check = el( 'td', 'check-column' );
	const box = el( 'input' );
	box.attrs.type = 'checkbox';
	box.value = String( orderId );
	check.appendChild( box );

	const cell = el( 'th', 'order_number column-order_number has-row-actions column-primary' );
	cell.attrs.scope = 'row';
	const link = el( 'a', 'order-view' );
	link.appendChild( el( 'strong', '', { textContent: '#' + orderId + ' Тодорка Тодорова' } ) );
	cell.appendChild( link );
	const date = el( 'div', 'order_date small-screen-only' );
	cell.appendChild( date );
	cell.appendChild( el( 'div', 'order_status small-screen-only' ) );

	row.appendChild( check );
	row.appendChild( cell );
	row.appendChild( el( 'td', 'order_total column-order_total' ) );

	row.cell = cell;
	row.check = check;
	row.date = date;
	return row;
}

// Runs ole-admin.js fresh with the given flags and returns the single row it drew on.
function run( flags, data ) {
	const row = makeRow( 6213 );
	const body = makeEl( 'body' );

	global.window = {
		ORDELIST_DATA: Object.assign( {
			context: 'list',
			flags: Object.assign( { duplicates: true, shipping: false, copy: {} }, flags ),
			map: { 6213: { g: 1, n: 2, dup: false, r: 'phone' } },
			groups: { 1: { n: 2, orders: [] } },
			palette: [ '#d63638', '#b26a00' ],
			i18n: { badge: 'customer %s', dupBadge: 'duplicate %s', badgeTitle: 'matches %s', phoneBadge: 'phone?' },
			ajax: { url: '/ajax', nonce: 'n' },
			totalColor: { on: false, rules: [] },
		}, data || {} ),
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

	const strip = row.cell.querySelector( '.ole-badges' );
	return {
		colored: -1 !== row.classes.indexOf( 'ole-dup' ),
		bd: row.styleProps['--ole-bd'],
		bg: row.styleProps['--ole-bg'],
		strip: strip,
		badge: strip ? strip.querySelector( '.ole-badge' ) : null,
		phone: strip ? strip.querySelector( '.ole-phone-badge' ) : null,
		row: row,
	};
}

// Both on: today's behaviour, unchanged.
let r = run( { dupColor: true, dupBadge: true } );
ck( r.colored, 'both on: the row is marked for coloring' );
ck( !! r.bd && !! r.bg, 'both on: the row gets its outline and fill colors' );
ck( !! r.badge, 'both on: the badge is added' );

// Where the badge ends up: in the order-number cell, never in the checkbox cell.
ck( 0 === r.row.check.children.filter( c => 'input' !== c.tagName ).length, 'the checkbox cell is left alone' );
ck( r.badge && r.badge.parent === r.strip, 'the badge sits in its own strip under the order number' );
ck( r.strip && r.row.cell.children.indexOf( r.strip ) < r.row.cell.children.indexOf( r.row.date ),
	'the strip stands above the date/status blocks, next to the customer name' );

// A second badge joins the strip instead of starting another line.
r = run( { dupColor: true, dupBadge: true, phone: true }, { phoneInvalid: [ 6213 ] } );
ck( !! r.badge && !! r.phone, 'the phone badge joins the same strip' );
ck( r.strip && 2 === r.strip.children.length, 'both badges share one line' );

// Coloring off, badge on: the badge must survive on an unstyled row.
r = run( { dupColor: false, dupBadge: true } );
ck( ! r.colored, 'coloring off: the row is not marked' );
ck( ! r.bd && ! r.bg, 'coloring off: no outline or fill color is set' );
ck( !! r.badge, 'coloring off: the badge is still added' );
ck( r.badge && -1 !== classesOf( r.badge ).indexOf( 'ole-badge--click' ), 'coloring off: the badge stays clickable for the modal' );

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
