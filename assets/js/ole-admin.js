( function () {
	var D      = window.ORDELIST_DATA || {};
	var MAP    = D.map || {};
	var GROUPS = D.groups || {};
	var SHIP   = D.shipping || { rules: [], default: { color: '', label: '' } };
	var PAL    = D.palette || [];
	var FLAGS  = D.flags || {};
	var I18N   = D.i18n || {};
	var CTX    = D.context || 'list';
	var AJAX   = D.ajax || {};
	var DEC    = D.decimalSep || ',';
	var TC  = D.totalColor || { on: false, rules: [] };
	var PF  = D.priceFormat || { decimal: '.', thousand: ',' };
	var OC  = ( typeof window !== 'undefined' && window.ORDELIST_OrderColor ) ? window.ORDELIST_OrderColor : null;
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

	// Append a ring label to whatever tooltip the shipping fill may have set.
	function ringTitle( el, label ) {
		if ( ! label ) { return; }
		el.title = el.title ? ( el.title + ' · ' + label ) : label;
	}

	// Orders list: ring the "Ship to" cell when the row's total meets a threshold.
	function colorTotalRingsList() {
		if ( ! TC.on || ! OC ) { return; }
		var rows = document.querySelectorAll( '.wp-list-table tbody tr' );
		Array.prototype.forEach.call( rows, function ( tr ) {
			var cell = tr.querySelector( 'td.column-shipping_address' );
			if ( ! cell || cell.getAttribute( 'data-ole-ring' ) ) { return; }
			var amtEl = tr.querySelector( 'td.column-order_total .woocommerce-Price-amount' )
				|| tr.querySelector( 'td.order_total .woocommerce-Price-amount' );
			if ( ! amtEl ) { return; }
			cell.setAttribute( 'data-ole-ring', '1' );
			var total = OC.parseAmount( amtEl.textContent || '', PF );
			var m = OC.matchTotal( total, TC.rules );
			if ( m && m.color ) {
				cell.style.setProperty( 'box-shadow', 'inset 0 0 0 3px ' + m.color, 'important' );
				cell.style.borderRadius = '8px';
				ringTitle( cell, m.label );
			}
		} );
	}

	// Edit screen: ring the address panel(s), composed with the shipping shadow.
	function colorTotalRingEdit() {
		if ( ! TC.on || ! OC || TC.orderTotal == null ) { return; }
		var m = OC.matchTotal( TC.orderTotal, TC.rules );
		if ( ! m || ! m.color ) { return; }
		var blocks = document.querySelectorAll( '#order_data .address' );
		Array.prototype.forEach.call( blocks, function ( el ) {
			if ( el.getAttribute( 'data-ole-ring' ) ) { return; }
			el.setAttribute( 'data-ole-ring', '1' );
			var existing = el.style.boxShadow;
			el.style.boxShadow = 'inset 0 0 0 3px ' + m.color + ( existing ? ', ' + existing : '' );
			if ( ! el.style.borderRadius ) { el.style.borderRadius = '6px'; }
			if ( ! el.style.padding ) { el.style.padding = '8px 10px'; }
			ringTitle( el, m.label );
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
				? ( '⚠️ ' + fmt( I18N.dupBadge, [ info.n ] ) + ' 🔍' )
				: ( '👥 ' + fmt( I18N.badge, [ info.n ] ) + ' 🔍' );
			b.title = fmt( I18N.badgeTitle, [ info.r || '-' ] );
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
		if ( ! orders || ! orders.length ) { body.textContent = I18N.noItems || '-'; return; }
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

	function showLoading( body ) {
		body.innerHTML = '';
		var w  = document.createElement( 'div' ); w.className = 'ole-loading';
		var sp = document.createElement( 'span' ); sp.className = 'ole-spinner';
		var tx = document.createElement( 'span' ); tx.textContent = I18N.loading || 'Loading…';
		w.appendChild( sp ); w.appendChild( tx ); body.appendChild( w );
	}

	function openModalMeta( meta ) {
		if ( ! meta ) { return; }
		var m = ensureModal();

		// First line: name · orders count. Second line: "first on … · once every … days".
		var line1 = [ meta.name, fmt( I18N.ordersCount, [ meta.n ] ) ];
		var line2 = [];
		if ( meta.first ) { line2.push( fmt( I18N.since, [ meta.first ] ) ); }
		if ( meta.freq ) { line2.push( meta.freq ); }
		var titleEl = m.querySelector( '.ole-modal__title' );
		titleEl.className = 'ole-modal__title' + ( meta.dup ? ' ole-modal__title--dup' : '' );
		titleEl.textContent = '';
		var main = document.createElement( 'span' );
		main.className = 'ole-modal__title-main';
		main.textContent = ( meta.dup ? '⚠️ ' : '' ) + line1.join( ' · ' );
		titleEl.appendChild( main );
		if ( line2.length ) {
			var sub = document.createElement( 'span' );
			sub.className = 'ole-modal__title-sub';
			sub.textContent = line2.join( ' · ' );
			titleEl.appendChild( sub );
		}

		var body = m.querySelector( '.ole-modal__body' );
		m.classList.add( 'is-open' );

		var key = ( meta.ids || [] ).join( ',' );
		if ( detailsCache[ key ] ) { renderOrders( body, detailsCache[ key ] ); return; }
		showLoading( body );

		var fd = new FormData();
		fd.append( 'action', 'ordelist_group_details' );
		fd.append( 'nonce', AJAX.nonce || '' );
		fd.append( 'ids', key );
		fetch( AJAX.url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) { detailsCache[ key ] = res.data; renderOrders( body, res.data ); }
				else { body.textContent = I18N.error || 'Failed to load.'; }
			} )
			.catch( function () { body.textContent = I18N.error || 'Failed to load.'; } );
	}
	function openModal( g ) { openModalMeta( GROUPS[ String( g ) ] ); }

	// Order edit page: badge in the order title that opens the customer-orders modal.
	function addEditGroupBadge() {
		var meta = D.editGroup;
		if ( ! meta ) { return; }
		var host = document.querySelector( '.woocommerce-order-data__heading' ) || document.querySelector( '#order_data .address' );
		if ( ! host || host.getAttribute( 'data-ole-eg' ) ) { return; }
		host.setAttribute( 'data-ole-eg', '1' );
		var b = document.createElement( 'span' );
		b.className = 'ole-badge ole-badge--click' + ( meta.dup ? ' ole-badge--dup' : '' );
		b.style.background = meta.dup ? '#d63638' : ( PAL[0] || '#2271b1' );
		b.style.display = 'inline-block';
		b.style.marginLeft = '10px';
		b.style.verticalAlign = 'middle';
		b.style.textShadow = 'none';
		b.setAttribute( 'data-ole-editgroup', '1' );
		b.textContent = ( meta.dup ? '⚠️ ' : '👥 ' ) + fmt( I18N.ordersCount, [ meta.n ] ) + ' 🔍';
		host.appendChild( b );
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
		if ( badge.getAttribute( 'data-ole-editgroup' ) ) { openModalMeta( D.editGroup ); return; }
		openModal( badge.getAttribute( 'data-ole-group' ) );
	}, true );
	document.addEventListener( 'keydown', function ( e ) { if ( 'Escape' === e.key ) { closeModal(); } } );

	// Copy-to-clipboard buttons (order edit page).
	var ICON_COPY = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
	var ICON_OK   = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
		document.body.appendChild( ta ); ta.focus(); ta.select();
		try { document.execCommand( 'copy' ); } catch ( e ) {}
		document.body.removeChild( ta );
	}
	function doCopy( text, btn ) {
		function ok() {
			btn.classList.add( 'is-copied' );
			btn.innerHTML = ICON_OK;
			btn.title = I18N.copied || 'Copied';
			setTimeout( function () {
				btn.classList.remove( 'is-copied' );
				btn.innerHTML = ICON_COPY;
				btn.title = I18N.copy || 'Copy';
			}, 1200 );
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( ok, function () { fallbackCopy( text ); ok(); } );
		} else { fallbackCopy( text ); ok(); }
	}
	function copyBtn( getText ) {
		var b = document.createElement( 'button' );
		b.type = 'button'; b.className = 'ole-copy'; b.title = I18N.copy || 'Copy';
		b.innerHTML = ICON_COPY;
		b.addEventListener( 'click', function ( e ) {
			e.preventDefault(); e.stopPropagation();
			var t = getText(); if ( t ) { doCopy( t, b ); }
		} );
		return b;
	}
	function addCopyButtons() {
		var C = FLAGS.copy || {};
		if ( ! C.name && ! C.phone && ! C.total ) { return; }
		// Full name - first line of the first address block.
		var addr = document.querySelector( '#order_data .address' );
		if ( C.name && addr && ! addr.getAttribute( 'data-ole-copy' ) ) {
			addr.setAttribute( 'data-ole-copy', '1' );
			var p = addr.querySelector( 'p' );
			if ( p ) {
				var name = ( p.innerText || p.textContent || '' ).split( '\n' )[0].trim();
				if ( name ) {
					var br = p.querySelector( 'br' );
					var nb = copyBtn( function () { return name; } );
					if ( br ) { p.insertBefore( nb, br ); } else { p.appendChild( nb ); }
				}
			}
		}
		// Phone - the tel: link.
		var tel = document.querySelector( '#order_data a[href^="tel:"]' );
		if ( C.phone && tel && ! tel.getAttribute( 'data-ole-copy' ) ) {
			tel.setAttribute( 'data-ole-copy', '1' );
			var phone = ( tel.textContent || '' ).trim();
			if ( phone ) {
				var pb = copyBtn( function () { return phone; } );
				tel.parentNode.insertBefore( pb, tel.nextSibling );
			}
		}
		// Order total - the numeric amount.
		var tot = document.querySelector( '.ole-order-total' );
		if ( C.total && tot && ! tot.getAttribute( 'data-ole-copy' ) ) {
			tot.setAttribute( 'data-ole-copy', '1' );
			var tb = copyBtn( function () {
				var a = tot.querySelector( '.woocommerce-Price-amount' );
				var s;
				if ( a ) {
					var c   = a.cloneNode( true );
					var sym = c.querySelector( '.woocommerce-Price-currencySymbol' );
					if ( sym && sym.parentNode ) { sym.parentNode.removeChild( sym ); }
					s = c.textContent || '';
				} else {
					s = tot.textContent || '';
				}
				s = s.replace( /[\s ]/g, '' ).replace( /[^\d.,]/g, '' ).replace( /[.,]+$/, '' );
				var m = s.match( /^(\d+)(?:[.,](\d+))?$/ );
				return m ? ( m[1] + ( null != m[2] ? DEC + m[2] : '' ) ) : s.replace( /[.,]/g, DEC );
			} );
			tot.appendChild( tb );
		}
	}

	// Display-only phone normalization (the WC meta box renders the raw phone via
	// the 'edit' context, bypassing the server view filter - so we tidy the text here).
	function normalizePhone( raw, cc ) {
		raw = String( raw == null ? '' : raw );
		var s = raw.trim();
		if ( ! s ) { return raw; }
		cc = String( cc || '' ).replace( /\D+/g, '' );
		if ( 0 === s.indexOf( '00' ) ) { s = '+' + s.slice( 2 ); }
		if ( 0 === s.indexOf( '+0' ) ) { s = s.slice( 2 ); }
		var result;
		if ( 0 === s.indexOf( '+' ) ) {
			result = '+' + s.slice( 1 ).replace( /\D+/g, '' );
		} else {
			var d = s.replace( /\D+/g, '' ).replace( /^0+/, '' );
			if ( cc && 0 === d.indexOf( cc ) ) { result = '+' + d; }
			else if ( cc ) { result = '+' + cc + d; }
			else { result = '+' + d; }
		}
		var dc = result.replace( /\D+/g, '' );
		if ( dc.length < 8 || dc.length > 15 ) { return raw; }
		return result;
	}
	function normalizePhones() {
		var ph = D.phone || {};
		if ( ! ph.on ) { return; }
		var links = document.querySelectorAll( '#order_data a[href^="tel:"]' );
		Array.prototype.forEach.call( links, function ( a ) {
			if ( a.getAttribute( 'data-ole-ph' ) ) { return; }
			a.setAttribute( 'data-ole-ph', '1' );
			var cur  = ( a.textContent || '' ).trim();
			var norm = normalizePhone( cur, ph.cc );
			if ( norm && norm !== cur ) {
				a.textContent = norm;
				a.href = 'tel:' + norm.replace( /[^\d+]/g, '' );
			}
		} );
	}

	var phoneDone = false;
	function markPhoneInvalid() {
		if ( phoneDone ) { return; }
		var set = D.phoneInvalid;
		if ( ! set || ! set.length ) { return; }
		phoneDone = true;
		var ids = {};
		set.forEach( function ( id ) { ids[ String( id ) ] = 1; } );
		var rows = document.querySelectorAll( '.wp-list-table tbody tr' );
		Array.prototype.forEach.call( rows, function ( tr ) {
			var cb = tr.querySelector( '.check-column input[type=checkbox]' );
			if ( ! cb || ! cb.value || ! ids[ String( cb.value ) ] ) { return; }
			var cell = tr.querySelector( 'td.column-order_number, td.order_number' ) || tr.querySelectorAll( 'td' )[0];
			if ( ! cell || cell.querySelector( '.ole-phone-badge' ) ) { return; }
			var b = document.createElement( 'span' );
			b.className = 'ole-phone-badge';
			b.textContent = '⚠ ' + ( ( I18N && I18N.phoneBadge ) || 'phone?' );
			cell.appendChild( document.createElement( 'br' ) );
			cell.appendChild( b );
		} );
	}

	// Orders list bulk-actions menu: capture its entries (for the settings dropdown)
	// and pre-select the configured default. Runs once - re-applying on every tbody
	// mutation would overwrite a selection the user made by hand.
	var bulkDone = false;
	function captureBulkActions( sel ) {
		var map = {};
		Array.prototype.forEach.call( sel.options, function ( op ) {
			if ( ! op.value || '-1' === op.value ) { return; }
			map[ op.value ] = ( op.textContent || '' ).trim();
		} );
		return map;
	}
	function sameMap( a, b ) {
		a = a || {}; b = b || {};
		var ka = Object.keys( a ), kb = Object.keys( b );
		if ( ka.length !== kb.length ) { return false; }
		for ( var i = 0; i < ka.length; i++ ) {
			if ( a[ ka[ i ] ] !== b[ ka[ i ] ] ) { return false; }
		}
		return true;
	}
	function setupBulkActions() {
		if ( bulkDone ) { return; }
		var top = document.getElementById( 'bulk-action-selector-top' );
		if ( ! top ) { return; }
		bulkDone = true;

		// Cache the menu for the settings page (only when it changed).
		if ( D.bulkNonce ) {
			var map = captureBulkActions( top );
			if ( ! sameMap( map, D.bulkCache || {} ) ) {
				var fd = new FormData();
				fd.append( 'action', 'ordelist_save_bulk_actions' );
				fd.append( 'nonce', D.bulkNonce );
				fd.append( 'actions', JSON.stringify( map ) );
				fetch( AJAX.url, { method: 'POST', body: fd, credentials: 'same-origin' } ).catch( function () {} );
			}
		}

		// Pre-select the configured default in both selects (only if the option exists).
		var val = D.bulkDefault || '';
		if ( ! val ) { return; }
		[ 'bulk-action-selector-top', 'bulk-action-selector-bottom' ].forEach( function ( id ) {
			var sel = document.getElementById( id );
			if ( ! sel ) { return; }
			var has = Array.prototype.some.call( sel.options, function ( op ) { return op.value === val; } );
			if ( has ) { sel.value = val; }
		} );
	}

	// Sequential order opener: open each checkbox-selected order in its own tab,
	// one every N seconds, so the server never loads more than one order at a time.
	var seqInit = false, seqTimers = [], seqRunning = false;
	function seqSelectedUrls() {
		var urls = [];
		var hpos = /[?&]page=wc-orders/.test( window.location.search );
		var boxes = document.querySelectorAll( '.wp-list-table tbody .check-column input[type=checkbox]:checked' );
		Array.prototype.forEach.call( boxes, function ( cb ) {
			var id = ( cb.value || '' ).trim();
			if ( ! /^\d+$/.test( id ) ) { return; } // skip non-order checkboxes (e.g. select-all)
			var tr = cb.closest ? cb.closest( 'tr' ) : null;
			var href = '';
			if ( tr ) {
				// Only the real edit link - never the "#"-href preview eye (whose
				// .href resolves to the current orders-list page).
				var a = tr.querySelector( 'a[href*="action=edit"], a[href*="post.php?post="]' );
				if ( a ) { href = a.href; }
			}
			if ( ! href ) {
				// Build the edit URL from the order id (HPOS vs legacy posts table).
				var loc = window.location;
				href = hpos
					? loc.origin + loc.pathname + '?page=wc-orders&action=edit&id=' + id
					: loc.origin + loc.pathname.replace( /[^/]*$/, 'post.php' ) + '?post=' + id + '&action=edit';
			}
			urls.push( href );
		} );
		return urls;
	}
	function seqCount() {
		return document.querySelectorAll( '.wp-list-table tbody .check-column input[type=checkbox]:checked' ).length;
	}
	function setupSeqOpen() {
		if ( seqInit || ! D.seqOpen || ! D.seqOpen.enabled ) { return; }
		// Insert as a clean full-width band right above the orders table (below the
		// floated toolbar/search), so it never overlaps WordPress's filter row.
		var anchor = document.querySelector( '.wp-list-table' ) || document.querySelector( '.tablenav.top' );
		if ( ! anchor || ! anchor.parentNode ) { return; }
		seqInit = true;

		var bar = document.createElement( 'div' );
		bar.className = 'ole-seq';
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button button-primary ole-seq__go';
		var sec = document.createElement( 'input' );
		sec.type = 'number'; sec.min = '1'; sec.max = '300'; sec.className = 'ole-seq__sec';
		sec.value = String( D.seqOpen.interval || 20 );
		var secLbl = document.createElement( 'span' );
		secLbl.className = 'ole-seq__lbl'; secLbl.textContent = ( I18N.seqSec || 'sec' );
		var stop = document.createElement( 'button' );
		stop.type = 'button'; stop.className = 'button ole-seq__stop'; stop.textContent = ( I18N.seqStop || 'Stop' ); stop.style.display = 'none';
		var info = document.createElement( 'span' );
		info.className = 'ole-seq__info';
		bar.appendChild( btn ); bar.appendChild( sec ); bar.appendChild( secLbl ); bar.appendChild( stop ); bar.appendChild( info );
		anchor.parentNode.insertBefore( bar, anchor );

		function refresh() { btn.textContent = '▶ ' + fmt( I18N.seqOpen || 'Open selected (%s)', [ seqCount() ] ); }
		function setRunning( r ) { seqRunning = r; stop.style.display = r ? '' : 'none'; btn.disabled = r; sec.disabled = r; }
		function stopSeq() { seqTimers.forEach( function ( t ) { clearTimeout( t ); } ); seqTimers = []; setRunning( false ); }
		function openUrl( url, idx, total ) {
			var w = window.open( url, '_blank' );
			if ( ! w ) { info.textContent = ( I18N.seqBlocked || 'Pop-ups are blocked - allow them for this site, then try again.' ); stopSeq(); return false; }
			info.textContent = fmt( I18N.seqProgress || 'Opening %1$s / %2$s…', [ idx + 1, total ] );
			return true;
		}
		function start() {
			if ( seqRunning ) { return; }
			var urls = seqSelectedUrls();
			if ( ! urls.length ) { window.alert( I18N.seqNone || 'Select some orders first.' ); return; }
			var interval = Math.max( 1, Math.min( 300, parseInt( sec.value, 10 ) || 20 ) );
			var n = urls.length;
			setRunning( true );
			// First opens within the click gesture (never blocked); the rest are timed.
			if ( ! openUrl( urls[ 0 ], 0, n ) ) { return; }
			if ( n === 1 ) { info.textContent = fmt( I18N.seqDone || 'Done (%s)', [ n ] ); setRunning( false ); return; }
			for ( var i = 1; i < n; i++ ) {
				( function ( idx ) {
					seqTimers.push( setTimeout( function () {
						if ( ! seqRunning ) { return; }
						if ( ! openUrl( urls[ idx ], idx, n ) ) { return; }
						if ( idx === n - 1 ) { info.textContent = fmt( I18N.seqDone || 'Done (%s)', [ n ] ); setRunning( false ); }
					}, idx * interval * 1000 ) );
				} )( i );
			}
		}
		btn.addEventListener( 'click', start );
		stop.addEventListener( 'click', stopSeq );
		document.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.matches && e.target.matches( '.wp-list-table .check-column input[type=checkbox]' ) ) { setTimeout( refresh, 0 ); }
		} );
		refresh();
	}

	function run() {
		if ( 'edit' === CTX ) { normalizePhones(); colorEditAddress(); colorTotalRingEdit(); addCopyButtons(); addEditGroupBadge(); return; }
		colorShipping();
		colorTotalRingsList();
		markDuplicates();
		setupBulkActions();
		markPhoneInvalid();
		setupSeqOpen();
	}
	run();
	if ( 'edit' !== CTX && window.MutationObserver ) {
		var tbody = document.querySelector( '.wp-list-table tbody' );
		if ( tbody ) { new MutationObserver( function () { run(); } ).observe( tbody, { childList: true } ); }
	}
} )();
