( function () {
	// Pure: a dup notice from the server is prefixed with "OLEDUP|". Return the
	// human body (for the modal), or null for any other notice. Mirrored in the test.
	function stripMarker( text ) {
		var s = String( text == null ? '' : text ).replace( /^\s+/, '' );
		var P = 'OLEDUP|';
		return s.indexOf( P ) === 0 ? s.slice( P.length ) : null;
	}

	// Node export for parity tests; harmless in the browser.
	if ( typeof module !== 'undefined' && module.exports ) { module.exports = { stripMarker: stripMarker }; }

	if ( typeof window === 'undefined' || typeof document === 'undefined' ) { return; }
	var D = window.ORDELIST_DUP || null;
	if ( ! D || ! window.jQuery ) { return; }
	var $ = window.jQuery;

	function placeOrderBtn() { return document.getElementById( 'place_order' ); }

	// (1) Block the same-second double-tap: disable the button as soon as the
	// checkout form is submitted (capture phase, so it runs before WooCommerce's
	// own handler). Re-enabled on checkout_error below.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form || ! form.classList || ! form.classList.contains( 'checkout' ) ) { return; }
		var b = placeOrderBtn();
		if ( b ) { b.setAttribute( 'disabled', 'disabled' ); b.classList.add( 'ole-dup-disabled' ); }
	}, true );

	function reenable() {
		var b = placeOrderBtn();
		if ( b ) { b.removeAttribute( 'disabled' ); b.classList.remove( 'ole-dup-disabled' ); }
	}

	// (2) On a failed checkout, look for our dup notice and show the confirm modal.
	$( document.body ).on( 'checkout_error', function () {
		reenable();
		var body = null;
		// Assumes the dup notice isn't the first <li> in a multi-error list (phone-checkout
		// registers before dup-guard, so its error is added first). Safe given that ordering.
		$( '.woocommerce-error li, .woocommerce-error' ).each( function () {
			if ( body ) { return; }
			var hit = stripMarker( $( this ).text() );
			if ( hit !== null ) { body = hit; $( this ).hide(); }
		} );
		if ( body !== null ) { showModal( body ); }
	} );

	function showModal( bodyText ) {
		if ( document.getElementById( 'ole-dup-modal' ) ) { return; }
		var overlay = document.createElement( 'div' );
		overlay.id = 'ole-dup-modal';
		overlay.className = 'ole-dup-overlay';
		overlay.innerHTML =
			'<div class="ole-dup-card" role="dialog" aria-modal="true">' +
			'<p class="ole-dup-text"></p>' +
			'<div class="ole-dup-actions">' +
			'<button type="button" class="ole-dup-cancel">' + escapeHtml( D.i18n.cancel ) + '</button>' +
			'<button type="button" class="ole-dup-ok">' + escapeHtml( D.i18n.confirm ) + '</button>' +
			'</div></div>';
		overlay.querySelector( '.ole-dup-text' ).textContent = bodyText; // server text as plain text
		document.body.appendChild( overlay );
		function killModal() { if ( overlay.parentNode ) { overlay.parentNode.removeChild( overlay ); } }
		overlay.querySelector( '.ole-dup-cancel' ).addEventListener( 'click', killModal );
		overlay.addEventListener( 'click', function ( e ) { if ( e.target === overlay ) { killModal(); } } );
		overlay.querySelector( '.ole-dup-ok' ).addEventListener( 'click', function () {
			// Tell the server "this customer confirmed the duplicate for the current cart",
			// then resubmit the checkout. The server bound the confirm token to the cart hash.
			$.post( D.ajaxUrl, { action: D.action, nonce: D.nonce } ).always( function () {
				killModal();
				var form = document.querySelector( 'form.checkout' );
				if ( form && window.jQuery ) { window.jQuery( form ).submit(); }
			} );
		} );
	}

	function escapeHtml( s ) {
		return String( s == null ? '' : s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}
} )();
