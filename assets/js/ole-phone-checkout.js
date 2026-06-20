( function () {
	// Validator — must mirror OLE_Phone_Validator (PHP).
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
	var D = window.OLE_PHONE || null;
	if ( ! D ) { return; }

	function fieldEl() { return document.getElementById( 'billing_phone' ); }
	function ensureMsg( field ) {
		var wrap = field.closest( '.form-row' ) || field.parentNode;
		var el = wrap.querySelector( '.ole-phone-msg' );
		if ( ! el ) { el = document.createElement( 'span' ); el.className = 'ole-phone-msg'; wrap.appendChild( el ); }
		return el;
	}
	function check( field ) {
		var raw = field.value || '';
		var msg = ensureMsg( field );
		if ( raw.replace( /\s+/g, '' ) === '' ) { msg.textContent = ''; msg.className = 'ole-phone-msg'; return; }
		var r = validate( raw, D.cc );
		if ( r.valid ) { msg.textContent = '✓ ' + ( D.i18n.ok || '' ); msg.className = 'ole-phone-msg is-ok'; }
		else { msg.textContent = '✗ ' + ( D.i18n[ r.reason ] || D.i18n.invalid ); msg.className = 'ole-phone-msg is-bad'; }
	}
	function bind() {
		var f = fieldEl(); if ( ! f || f.getAttribute( 'data-ole-phone' ) ) { return; }
		f.setAttribute( 'data-ole-phone', '1' );
		f.addEventListener( 'input', function () { check( f ); } );
		f.addEventListener( 'blur', function () { check( f ); } );
	}
	bind();
	// Block checkout re-renders the form; rebind on updates.
	document.body && document.body.addEventListener( 'updated_checkout', bind );
	if ( window.jQuery ) { window.jQuery( document.body ).on( 'updated_checkout', bind ); }
} )();
