( function () {
	// Validator — must mirror ORDELIST_Phone_Validator (PHP).
	function validate( raw, cc ) {
		cc = String( cc || '359' ).replace( /\D+/g, '' ) || '359';
		var s = String( raw == null ? '' : raw );
		var hasPlus = s.length > 0 && s.charAt( 0 ) === '+';
		var digits = s.replace( /\D+/g, '' );
		function res( reason, nsn ) {
			return { valid: reason === 'ok', reason: reason, normalized: reason === 'ok' ? '+' + cc + nsn : '' };
		}
		if ( digits === '' ) { return res( 'empty', '' ); }
		var nsn = null;
		if ( hasPlus ) {
			if ( digits.indexOf( cc ) === 0 ) { nsn = digits.slice( cc.length ); }
			else { return res( 'not_bulgarian', '' ); }
		} else if ( digits.indexOf( '00' + cc ) === 0 ) { nsn = digits.slice( 2 + cc.length ); }
		else if ( digits.indexOf( '00' ) === 0 ) { return res( 'not_bulgarian', '' ); }
		else if ( digits.indexOf( '0' ) === 0 ) { nsn = digits.slice( 1 ); }
		else if ( digits.indexOf( cc ) === 0 && digits.length >= cc.length + 8 ) { nsn = digits.slice( cc.length ); }
		else { nsn = digits; }
		if ( nsn.length < 8 ) { return res( 'too_short', '' ); }
		if ( nsn.length > 9 ) { return res( 'too_long', '' ); }
		var f = nsn.charAt( 0 );
		if ( f < '2' || f > '9' ) { return res( 'invalid', '' ); }
		return res( 'ok', nsn );
	}

	// Node export for parity tests; harmless in the browser.
	if ( typeof module !== 'undefined' && module.exports ) { module.exports = validate; }

	// Browser: live feedback under the billing phone field.
	if ( typeof window === 'undefined' || typeof document === 'undefined' ) { return; }
	var D = window.ORDELIST_PHONE || null;
	if ( ! D ) { return; }

	function fieldEl() { return document.getElementById( 'billing_phone' ); }
	function rowEl( field ) { return ( field.closest && field.closest( '.form-row' ) ) || field.parentNode; }
	function ensureMsg( field ) {
		var wrap = rowEl( field );
		var el = wrap.querySelector( '.ole-phone-msg' );
		if ( ! el ) { el = document.createElement( 'span' ); el.className = 'ole-phone-msg'; wrap.appendChild( el ); }
		return el;
	}
	// Drive WooCommerce's own field state classes so the field gets the theme's
	// native red (invalid) / green (validated) styling — the standard checkout look.
	function setRowState( field, state ) {
		var row = rowEl( field );
		if ( ! row || ! row.classList ) { return; }
		row.classList.remove( 'woocommerce-invalid', 'woocommerce-invalid-required-field', 'woocommerce-validated' );
		if ( 'invalid' === state ) { row.classList.add( 'woocommerce-invalid' ); }
		else if ( 'valid' === state ) { row.classList.add( 'woocommerce-validated' ); }
	}
	function check( field ) {
		var raw = field.value || '';
		var msg = ensureMsg( field );
		if ( raw.replace( /\s+/g, '' ) === '' ) { msg.textContent = ''; msg.style.display = 'none'; setRowState( field, '' ); return; }
		var r = validate( raw, D.cc );
		if ( r.valid ) {
			msg.textContent = ''; msg.style.display = 'none';
			setRowState( field, 'valid' );
		} else {
			msg.textContent = ( D.i18n[ r.reason ] || D.i18n.invalid );
			msg.style.display = 'block';
			setRowState( field, 'invalid' );
		}
	}
	function bind() {
		var f = fieldEl(); if ( ! f || f.getAttribute( 'data-ole-phone' ) ) { return; }
		f.setAttribute( 'data-ole-phone', '1' );
		f.addEventListener( 'input', function () { check( f ); } );
		// Defer the blur check so our state wins over WooCommerce's own blur validation.
		f.addEventListener( 'blur', function () { setTimeout( function () { check( f ); }, 0 ); } );
	}
	bind();
	// The checkout form re-renders on updates; rebind via the jQuery event WooCommerce fires.
	if ( window.jQuery ) { window.jQuery( document.body ).on( 'updated_checkout', bind ); }
} )();
