( function () {
	if ( typeof window === 'undefined' || typeof document === 'undefined' ) { return; }
	var D = window.ORDELIST_DELIVERY || null;
	if ( ! D ) { return; }

	// Будуємо акцентний блок (іконка вже всередині тексту title/body).
	function buildBlock( cls, title, body ) {
		var box = document.createElement( 'div' );
		box.className = 'ole-deliv ' + cls;
		if ( title ) {
			var h = document.createElement( 'div' );
			h.className = 'ole-deliv-title';
			h.textContent = title;
			box.appendChild( h );
		}
		if ( body ) {
			var p = document.createElement( 'div' );
			p.className = 'ole-deliv-body';
			p.textContent = body;
			box.appendChild( p );
		}
		return box;
	}

	function wrapperOf( input ) {
		return ( input.closest && input.closest( '.form-row' ) ) || input.parentNode;
	}

	function decorate() {
		var inputs = document.querySelectorAll( 'input[id^="e_deliverydate_"]' );
		for ( var i = 0; i < inputs.length; i++ ) {
			var wrap = wrapperOf( inputs[ i ] );
			if ( ! wrap || wrap.getAttribute( 'data-ole-deliv' ) ) { continue; }
			wrap.setAttribute( 'data-ole-deliv', '1' );
			var frag = document.createDocumentFragment();
			if ( D.vacation && D.vacation.text ) {
				// Банер відпустки - над статичним блоком; весь текст в body-рядку.
				frag.appendChild( buildBlock( 'ole-deliv-vacation', '', D.vacation.text ) );
			}
			frag.appendChild( buildBlock( 'ole-deliv-ship', D.title || '', D.body || '' ) );
			// Під полем (після інпута), а не над лейблом.
			wrap.appendChild( frag );
		}
	}

	decorate();
	// Чекаут перерендерюється на оновленнях - ребайнд через подію WooCommerce.
	if ( window.jQuery ) { window.jQuery( document.body ).on( 'updated_checkout', decorate ); }
} )();
