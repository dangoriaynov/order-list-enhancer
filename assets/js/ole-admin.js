( function () {
	var D      = window.OLE_DATA || {};
	var MAP    = D.map || {};
	var GROUPS = D.groups || {};
	var SHIP   = D.shipping || { rules: [], default: { color: '', label: '' } };
	var PAL    = D.palette || [];
	var FLAGS  = D.flags || {};
	var I18N   = D.i18n || {};
	var CTX    = D.context || 'list';
	var AJAX   = D.ajax || {};
	var detailsCache = {};

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

	// Знаходить перше правило, чиє ключове слово міститься в тексті адреси.
	function matchShip( text ) {
		var t = String( text || '' ).toLowerCase();
		for ( var i = 0; i < SHIP.rules.length; i++ ) {
			var kw = String( SHIP.rules[ i ].keyword || '' ).toLowerCase();
			if ( kw && t.indexOf( kw ) !== -1 ) {
				return { color: SHIP.rules[ i ].color, label: SHIP.rules[ i ].label };
			}
		}
		if ( SHIP.default.color ) { return { color: SHIP.default.color, label: SHIP.default.label }; }
		return null;
	}

	// Список замовлень: фарбує клітинку «Доставка до».
	function colorShipping() {
		if ( ! FLAGS.shipping ) { return; }
		var rows = document.querySelectorAll( '.wp-list-table tbody tr' );
		Array.prototype.forEach.call( rows, function ( tr ) {
			var cell = tr.querySelector( 'td.column-shipping_address' );
			if ( ! cell || cell.getAttribute( 'data-ole-ship' ) ) { return; }
			var raw = ( cell.textContent || '' );
			if ( raw.replace( /\s+/g, '' ).length < 3 ) { return; }
			cell.setAttribute( 'data-ole-ship', '1' );
			var m = matchShip( raw );
			if ( m && m.color ) {
				cell.style.setProperty( 'background-color', m.color, 'important' );
				if ( m.label ) { cell.title = fmt( I18N.shipTitle, [ m.label ] ); }
			}
		} );
	}

	// Сторінка редагування: фарбує блок(и) адреси тим самим кольором правила.
	function colorEditAddress() {
		if ( ! FLAGS.shipping ) { return; }
		var blocks = document.querySelectorAll( '#order_data .address' );
		Array.prototype.forEach.call( blocks, function ( el ) {
			if ( el.getAttribute( 'data-ole-ship' ) ) { return; }
			var raw = ( el.textContent || '' );
			if ( raw.replace( /\s+/g, '' ).length < 3 ) { return; }
			el.setAttribute( 'data-ole-ship', '1' );
			var m = matchShip( raw );
			if ( m && m.color ) {
				el.style.setProperty( 'background-color', m.color, 'important' );
				el.style.padding = '8px 10px';
				el.style.borderRadius = '6px';
				el.style.boxShadow = 'inset 3px 0 0 0 rgba(0,0,0,.14)';
				if ( m.label ) { el.title = fmt( I18N.shipTitle, [ m.label ] ); }
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
			var isDup = !! info.dup;
			var color = isDup ? '#d63638' : PAL[ ( info.g - 1 ) % PAL.length ];
			tr.classList.add( 'ole-dup' );
			if ( isDup ) { tr.classList.add( 'ole-dup--flag' ); }
			tr.style.setProperty( '--ole-bd', color );
			tr.style.setProperty( '--ole-bg', rgba( color, isDup ? 0.14 : 0.09 ) );

			var cell = tr.querySelector( 'td.column-order_number, td.order_number' ) || tr.querySelectorAll( 'td' )[0];
			if ( ! cell ) { return; }
			var b = document.createElement( 'span' );
			b.className = 'ole-badge ole-badge--click' + ( isDup ? ' ole-badge--dup' : '' );
			b.style.background = color;
			b.setAttribute( 'data-ole-group', info.g );
			b.textContent = isDup
				? ( '⚠️ ' + ( I18N.duplicate || 'duplicate' ) + ' · ' + info.n + ' 🔍' )
				: ( '👥 ' + fmt( I18N.badge, [ info.g, info.n ] ) + ' 🔍' );
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

	function renderOrders( body, orders ) {
		body.innerHTML = '';
		if ( ! orders || ! orders.length ) { body.textContent = I18N.noItems || '—'; return; }
		orders.forEach( function ( o ) {
			var row = document.createElement( 'div' ); row.className = 'ole-o';
			var head = document.createElement( 'div' ); head.className = 'ole-o__head';

			var a = document.createElement( 'a' );
			a.className = 'ole-o__num'; a.href = o.url; a.target = '_blank'; a.rel = 'noopener';
			a.textContent = '#' + o.num;
			var date = document.createElement( 'span' ); date.className = 'ole-o__date'; date.textContent = o.date;
			var st = document.createElement( 'span' ); st.className = 'ole-o__status'; st.textContent = o.status;
			var total = document.createElement( 'span' ); total.className = 'ole-o__total'; total.textContent = o.total;
			head.appendChild( a ); head.appendChild( date ); head.appendChild( st ); head.appendChild( total );
			row.appendChild( head );

			var items = o.items || [];
			if ( items.length ) {
				var tbl = document.createElement( 'table' ); tbl.className = 'ole-items';
				items.forEach( function ( it ) {
					var tr = document.createElement( 'tr' );
					var tdn = document.createElement( 'td' ); tdn.className = 'ole-items__name'; tdn.textContent = it.name;
					var tdq = document.createElement( 'td' ); tdq.className = 'ole-items__qty'; tdq.textContent = '×' + it.qty;
					tr.appendChild( tdn ); tr.appendChild( tdq ); tbl.appendChild( tr );
				} );
				row.appendChild( tbl );
			}
			body.appendChild( row );
		} );
	}

	function openModal( g ) {
		var meta = GROUPS[ String( g ) ];
		if ( ! meta ) { return; }
		var m = ensureModal();

		var parts = [ meta.name, fmt( I18N.ordersCount, [ meta.n ] ) ];
		if ( meta.first ) { parts.push( fmt( I18N.since, [ meta.first ] ) ); }
		if ( meta.freq ) { parts.push( meta.freq ); }
		var titleEl = m.querySelector( '.ole-modal__title' );
		titleEl.textContent = ( meta.dup ? '⚠️ ' : '' ) + parts.join( ' · ' );
		titleEl.className = 'ole-modal__title' + ( meta.dup ? ' ole-modal__title--dup' : '' );

		var body = m.querySelector( '.ole-modal__body' );
		m.classList.add( 'is-open' );

		if ( detailsCache[ g ] ) { renderOrders( body, detailsCache[ g ] ); return; }
		body.textContent = I18N.loading || 'Loading…';

		var fd = new FormData();
		fd.append( 'action', 'ole_group_details' );
		fd.append( 'nonce', AJAX.nonce || '' );
		fd.append( 'ids', ( meta.ids || [] ).join( ',' ) );
		fetch( AJAX.url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) { detailsCache[ g ] = res.data; renderOrders( body, res.data ); }
				else { body.textContent = I18N.error || 'Failed to load.'; }
			} )
			.catch( function () { body.textContent = I18N.error || 'Failed to load.'; } );
	}

	// Capture phase + stopPropagation: the WC orders row is itself clickable
	// (navigates to the order), so we must intercept the badge click before it
	// reaches the row handler.
	document.addEventListener( 'click', function ( e ) {
		var badge = e.target && e.target.closest ? e.target.closest( '.ole-badge--click' ) : null;
		if ( ! badge ) { return; }
		e.preventDefault();
		e.stopPropagation();
		if ( e.stopImmediatePropagation ) { e.stopImmediatePropagation(); }
		openModal( badge.getAttribute( 'data-ole-group' ) );
	}, true );
	document.addEventListener( 'keydown', function ( e ) { if ( 'Escape' === e.key ) { closeModal(); } } );

	function run() {
		if ( 'edit' === CTX ) { colorEditAddress(); return; }
		colorShipping();
		markDuplicates();
	}
	run();
	if ( 'edit' !== CTX && window.MutationObserver ) {
		var tbody = document.querySelector( '.wp-list-table tbody' );
		if ( tbody ) { new MutationObserver( function () { run(); } ).observe( tbody, { childList: true } ); }
	}
} )();
