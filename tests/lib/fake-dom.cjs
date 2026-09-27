// Достатъчно DOM, колкото ole-admin.js пипа: без WordPress, без браузър.
//
// Изнесено тук, защото тестовете вече са два и двата строят един и същи списък с поръчки. Селекторите
// са подмножеството, което кодът използва: таг, .клас, таг.клас, таг:not(.клас), [атрибут=стойност],
// изброени с запетая и вложени през интервал.

function classesOf( el ) {
	return String( el.className || '' ).split( /\s+/ ).filter( Boolean ).concat( el.classes || [] );
}

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
				descendants( s ).forEach( function ( d ) {
					if ( matchesSimple( d, step ) && -1 === next.indexOf( d ) ) { next.push( d ); }
				} );
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
		value: '',
		parent: null,
		children: [],
		attrs: {},
		classes: [],
		styleProps: {},
		style: {},
		listeners: {},
		classList: {
			add: function ( c ) { if ( -1 === el.classes.indexOf( c ) ) { el.classes.push( c ); } },
			remove: function ( c ) { el.classes = el.classes.filter( x => x !== c ); },
			contains: function ( c ) { return -1 !== classesOf( el ).indexOf( c ); },
			toggle: function ( c, on ) { if ( on ) { el.classList.add( c ); } else { el.classList.remove( c ); } },
		},
		setAttribute: function ( k, v ) { el.attrs[ k ] = String( v ); },
		getAttribute: function ( k ) { return Object.prototype.hasOwnProperty.call( el.attrs, k ) ? el.attrs[ k ] : null; },
		// Истинският appendChild МЕСТИ възела - иначе тестът не вижда, че лентата се е изпразнила.
		appendChild: function ( c ) { detach( c ); c.parent = el; el.children.push( c ); return c; },
		insertBefore: function ( c, ref ) {
			detach( c );
			const i = ref ? el.children.indexOf( ref ) : -1;
			if ( -1 === i ) { el.children.push( c ); } else { el.children.splice( i, 0, c ); }
			c.parent = el;
			return c;
		},
		removeChild: function ( c ) { detach( c ); return c; },
		querySelector: function ( sel ) { return find( el, sel )[ 0 ] || null; },
		querySelectorAll: function ( sel ) { return find( el, sel ); },
		addEventListener: function ( type, fn ) { ( el.listeners[ type ] = el.listeners[ type ] || [] ).push( fn ); },
	};
	el.style.setProperty = function ( k, v ) { el.styleProps[ k ] = v; };
	Object.defineProperty( el, 'parentNode', { get: function () { return el.parent; } } );
	Object.defineProperty( el, 'nextSibling', {
		get: function () {
			if ( ! el.parent ) { return null; }
			return el.parent.children[ el.parent.children.indexOf( el ) + 1 ] || null;
		},
	} );
	return el;
}

function detach( node ) {
	if ( node && node.parent ) {
		node.parent.children = node.parent.children.filter( c => c !== node );
		node.parent = null;
	}
}

function el( tag, className, extra ) {
	const e = makeEl( tag );
	e.className = className || '';
	Object.assign( e, extra || {} );
	return e;
}

function fire( node, type ) {
	( ( node.listeners || {} )[ type ] || [] ).forEach( function ( fn ) { fn.call( node, { target: node } ); } );
}

function memoryStorage() {
	const bag = {};
	return {
		getItem: function ( k ) { return Object.prototype.hasOwnProperty.call( bag, k ) ? bag[ k ] : null; },
		setItem: function ( k, v ) { bag[ k ] = String( v ); },
	};
}

module.exports = { classesOf, matchesSimple, find, makeEl, el, fire, memoryStorage };
