// foldFilters() в ole-admin.js: търсенето и редът с филтри се прибират зад едно копче.
//
// Всеки плъгин с филтър за поръчки пише на същия ред (WooCommerce, PIP, order-delivery-date,
// checkout-add-ons, Fulfillments), а търсачката стои точно над него - на зареден магазин това е
// половин екран контроли над всеки списък (собственик, 2026-09-27).

const { classesOf, makeEl, el, fire, memoryStorage, find } = require( '../lib/fake-dom.cjs' );

let fails = 0;
function ck( c, m ) { console.log( ( c ? 'ok   - ' : 'FAIL - ' ) + m ); if ( ! c ) fails++; }

const ADMIN_JS = require.resolve( '../../assets/js/ole-admin.js' );

// Екранът със списъка: търсачка, лента с масови действия и филтри, страници, таблица.
function screen() {
	const root = el( 'div' );
	const form = el( 'form' );
	const search = el( 'p', 'search-box' );
	search.appendChild( el( 'input' ) );
	const nav = el( 'div', 'tablenav top' );
	const bulk = el( 'div', 'alignleft actions bulkactions' );
	const filters = el( 'div', 'alignleft actions' );
	filters.appendChild( el( 'select' ) );
	const pages = el( 'div', 'tablenav-pages' );
	nav.appendChild( bulk );
	nav.appendChild( filters );
	nav.appendChild( pages );
	const table = el( 'table', 'wp-list-table' );
	table.appendChild( el( 'tbody' ) );
	form.appendChild( search );
	form.appendChild( nav );
	form.appendChild( table );
	root.appendChild( form );
	return { root, form, search, nav, bulk, filters, pages };
}

function run( opts ) {
	opts = opts || {};
	const s = screen();
	const storage = memoryStorage();
	if ( opts.stored ) { storage.setItem( 'ordelist-filters-open', opts.stored ); }

	global.window = {
		ORDELIST_DATA: {
			context: 'list',
			flags: { duplicates: false, shipping: false, copy: {}, foldFilters: false !== opts.flag },
			palette: [ '#d63638' ],
			i18n: { filters: 'Search and filters', filtersOn: 'Search and filters (in use)' },
			ajax: { url: '/ajax', nonce: 'n' },
			totalColor: { on: false, rules: [] },
		},
		location: { search: opts.query || '?page=wc-orders' },
		localStorage: storage,
		MutationObserver: null,
	};
	global.document = {
		body: s.root,
		documentElement: { lang: 'bg' },
		querySelector: function ( sel ) { return find( s.root, sel )[ 0 ] || null; },
		querySelectorAll: function ( sel ) { return find( s.root, sel ); },
		getElementById: function () { return null; },
		createElement: makeEl,
		addEventListener: function () {},
	};

	delete require.cache[ ADMIN_JS ];
	require( ADMIN_JS );

	s.box = find( s.root, '.ole-filters' )[ 0 ] || null;
	s.toggle = find( s.root, '.ole-filters-toggle' )[ 0 ] || null;
	s.storage = storage;
	return s;
}

// --- нормален случай: нищо не е филтрирано ---------------------------------
let s = run();
ck( !! s.toggle, 'a toggle is added to the toolbar' );
ck( !! s.box, 'the controls get a box of their own' );
ck( s.box && s.filters.parent === s.box, 'the filter row moves into the box' );
ck( s.box && s.search.parent === s.box, 'the search box moves into the box' );
ck( s.bulk.parent === s.nav, 'bulk actions stay in the toolbar - they act on the checkboxes, not on the list' );
ck( s.box && s.box.parent === s.form, 'the box stays inside the form, or its submit buttons would do nothing' );
ck( s.box && s.box.parent.children.indexOf( s.box ) === s.form.children.indexOf( s.nav ) + 1, 'the box sits right under the toolbar' );
ck( s.box && 'none' === s.box.style.display, 'folded away by default' );
ck( s.toggle && s.nav.children.indexOf( s.toggle ) === s.nav.children.indexOf( s.pages ) - 1, 'the toggle is placed before the pager' );

// --- разгъване ------------------------------------------------------------
fire( s.toggle, 'click' );
ck( 'none' !== s.box.style.display, 'clicking the toggle opens the box' );
ck( '1' === s.storage.getItem( 'ordelist-filters-open' ), 'the choice is remembered' );
ck( 'true' === s.toggle.getAttribute( 'aria-expanded' ), 'the toggle says it is expanded' );
fire( s.toggle, 'click' );
ck( 'none' === s.box.style.display, 'clicking again folds it back' );

// --- когато филтър наистина работи ---------------------------------------
s = run( { query: '?page=wc-orders&m=202609' } );
ck( s.box && 'none' !== s.box.style.display, 'a filter in the URL opens the box by itself' );
ck( s.toggle && -1 !== classesOf( s.toggle ).indexOf( 'ole-filters-on' ), 'and the toggle says a filter is in use' );

s = run( { query: '?page=wc-orders&s=Тодорова' } );
ck( s.box && 'none' !== s.box.style.display, 'a search term opens it too' );

// Страниране, подредба и подразделите на статусите не са филтри - те са къде си в списъка.
s = run( { query: '?page=wc-orders&paged=3&orderby=date&order=desc&status=wc-processing' } );
ck( s.box && 'none' === s.box.style.display, 'paging, sorting and the status tabs are not filters' );
ck( s.toggle && -1 === classesOf( s.toggle ).indexOf( 'ole-filters-on' ), 'and they do not light the toggle up' );

// Празна стойност в адреса е "всички" - filter_action=Filter идва с всяко подаване на формата.
s = run( { query: '?page=wc-orders&m=0&_customer_user=&filter_action=Filter' } );
ck( s.box && 'none' === s.box.style.display, 'empty filter values are not a filter' );

// --- запомнено състояние --------------------------------------------------
s = run( { stored: '1' } );
ck( s.box && 'none' !== s.box.style.display, 'a box left open stays open on the next page' );

// --- изключено -------------------------------------------------------------
s = run( { flag: false } );
ck( ! s.box && ! s.toggle, 'with the setting off nothing is touched' );
ck( s.search.parent === s.form && s.filters.parent === s.nav, 'and the controls stay where WooCommerce put them' );

console.log( fails ? '\n' + fails + ' FAILED' : '\nALL PASS' );
process.exit( fails ? 1 : 0 );
