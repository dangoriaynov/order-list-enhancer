( function () {
	var D      = window.OLE_DATA || {};
	var MAP    = D.map || {};
	var GROUPS = D.groups || {};
	var SHIP   = D.shipping || { rules: [], default: { color: '', label: '' } };
	var PAL    = D.palette || [];
	var FLAGS  = D.flags || {};
	var I18N   = D.i18n || {};

	function fmt( tmpl, args ) {
		var i = 0;
		return String( tmpl == null ? '' : tmpl )
			.replace( /%(\d+)\$s/g, function ( m, n ) { return args[ n - 1 ] != null ? args[ n - 1 ] : ''; } )
			.replace( /%s/g, function () { i++; return args[ i - 1 ] != null ? args[ i - 1 ] : ''; } );
	}

	function rgba( hex, a ) {
		var h = String( hex ).replace( '#', '' );
		if ( h.length !== 6 ) { return hex; }
		return 'rgba(' + parseInt( h.substr( 0, 2 ), 16 ) + ',' + parseInt( h.substr( 2, 2 ), 16 ) + ',' + parseInt( h.substr( 4, 2 ), 16 ) + ',' + a + ')';
	}

	function colorShipping() {
		if ( ! FLAGS.shipping ) { return; }
		var rows = document.querySelectorAll( '.wp-list-table tbody tr' );
		Array.prototype.forEach.call( rows, function ( tr ) {
			var cell = tr.querySelector( 'td.column-shipping_address' );
			if ( ! cell || cell.getAttribute( 'data-ole-ship' ) ) { return; }
			var raw = ( cell.textContent || '' );
			if ( raw.replace( /\s+/g, '' ).length < 3 ) { return; }
			var t = raw.toLowerCase();
			var color = '', label = '';
			for ( var i = 0; i < SHIP.rules.length; i++ ) {
				var kw = String( SHIP.rules[ i ].keyword || '' ).toLowerCase();
				if ( kw && t.indexOf( kw ) !== -1 ) { color = SHIP.rules[ i ].color; label = SHIP.rules[ i ].label; break; }
			}
			if ( ! color && SHIP.default.color ) { color = SHIP.default.color; label = SHIP.default.label; }
			cell.setAttribute( 'data-ole-ship', '1' );
			if ( color ) {
				cell.style.setProperty( 'background-color', color, 'important' );
				if ( label ) { cell.title = fmt( I18N.shipTitle, [ label ] ); }
			}
		} );
	}

	function markDuplicates() {
		if ( ! FLAGS.duplicates ) { return; }
		var rows = document.querySelectorAll( '.wp-list-table tbody tr' );
		Array.prototype.forEach.call( rows, function ( tr ) {
			if ( tr.getAttribute( 'data-ole-dup' ) ) { return; }
			var cb = tr.querySelector( '.check-column input[type=checkbox]' );
			if ( ! cb || ! cb.value ) { return; }
			var info = MAP[ String( cb.value ) ];
			if ( ! info ) { return; }
			tr.setAttribute( 'data-ole-dup', '1' );
			var color = PAL[ ( info.g - 1 ) % PAL.length ];
			tr.classList.add( 'ole-dup' );
			tr.style.setProperty( '--ole-bd', color );
			tr.style.setProperty( '--ole-bg', rgba( color, 0.09 ) );

			var cell = tr.querySelector( 'td.column-order_number, td.order_number' ) || tr.querySelectorAll( 'td' )[0];
			if ( ! cell ) { return; }
			var b = document.createElement( 'span' );
			b.className = 'ole-badge ole-badge--click';
			b.style.background = color;
			b.setAttribute( 'data-ole-group', info.g );
			b.textContent = '👥 ' + fmt( I18N.badge, [ info.g, info.n ] ) + ' 🔍';
			b.title = fmt( I18N.badgeTitle, [ info.r || '—' ] );
			cell.appendChild( document.createElement( 'br' ) );
			cell.appendChild( b );
		} );
	}

	var modalEl = null;
	function ensureModal() {
		if ( modalEl ) { return modalEl; }
		modalEl = document.createElement( 'div' );
		modalEl.className = 'ole-modal';
		modalEl.innerHTML =
			'<div class="ole-modal__overlay"></div>' +
			'<div class="ole-modal__card" role="dialog" aria-modal="true">' +
				'<div class="ole-modal__head"><span class="ole-modal__title"></span>' +
				'<button type="button" class="ole-modal__close">✕</button></div>' +
				'<div class="ole-modal__body"></div>' +
			'</div>';
		document.body.appendChild( modalEl );
		modalEl.querySelector( '.ole-modal__close' ).setAttribute( 'aria-label', I18N.close || 'Close' );
		modalEl.querySelector( '.ole-modal__overlay' ).addEventListener( 'click', closeModal );
		modalEl.querySelector( '.ole-modal__close' ).addEventListener( 'click', closeModal );
		return modalEl;
	}

	function closeModal() { if ( modalEl ) { modalEl.classList.remove( 'is-open' ); } }

	function openModal( g ) {
		var data = GROUPS[ String( g ) ];
		if ( ! data ) { return; }
		var m = ensureModal();
		var reason = data.reason ? ' (' + data.reason + ')' : '';
		m.querySelector( '.ole-modal__title' ).textContent = fmt( I18N.modalTitle, [ g, data.orders.length, reason ] );
		var body = m.querySelector( '.ole-modal__body' );
		body.innerHTML = '';
		data.orders.forEach( function ( o ) {
			var row = document.createElement( 'div' ); row.className = 'ole-o';
			var head = document.createElement( 'div' ); head.className = 'ole-o__head';

			var a = document.createElement( 'a' );
			a.className = 'ole-o__num'; a.href = o.url; a.target = '_blank'; a.rel = 'noopener';
			a.textContent = '#' + o.num;

			var date = document.createElement( 'span' ); date.className = 'ole-o__date'; date.textContent = o.date;
			var st = document.createElement( 'span' ); st.className = 'ole-o__status'; st.textContent = o.status;
			var total = document.createElement( 'span' ); total.className = 'ole-o__total'; total.textContent = o.total;

			head.appendChild( a ); head.appendChild( date ); head.appendChild( st ); head.appendChild( total );

			var items = document.createElement( 'div' ); items.className = 'ole-o__items';
			items.textContent = o.items || ( I18N.noItems || '—' );

			row.appendChild( head ); row.appendChild( items );
			body.appendChild( row );
		} );
		m.classList.add( 'is-open' );
	}

	document.addEventListener( 'click', function ( e ) {
		var badge = e.target && e.target.closest ? e.target.closest( '.ole-badge--click' ) : null;
		if ( ! badge ) { return; }
		e.preventDefault();
		openModal( badge.getAttribute( 'data-ole-group' ) );
	} );
	document.addEventListener( 'keydown', function ( e ) { if ( 'Escape' === e.key ) { closeModal(); } } );

	function run() { colorShipping(); markDuplicates(); }
	run();
	if ( window.MutationObserver ) {
		var tbody = document.querySelector( '.wp-list-table tbody' );
		if ( tbody ) { new MutationObserver( function () { run(); } ).observe( tbody, { childList: true } ); }
	}
} )();
