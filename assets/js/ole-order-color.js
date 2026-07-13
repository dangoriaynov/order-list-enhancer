( function () {
	// Parse a displayed price string into a float using the store's separators.
	// Order matters: strip the thousand separator BEFORE turning the decimal
	// separator into '.', otherwise "1.234,50" (dot-thousand) breaks.
	function parseAmount( text, fmt ) {
		fmt = fmt || {};
		var dec = fmt.decimal || '.';
		var tho = fmt.thousand || '';
		var s = String( text == null ? '' : text ).replace( /\u00a0/g, ' ' ); // normalize nbsp -> space
		if ( tho ) { s = s.split( tho ).join( '' ); }
		if ( dec && dec !== '.' ) { s = s.split( dec ).join( '.' ); }
		s = s.replace( /[^0-9.\-]/g, '' );
		if ( s === '' || s === '-' || s === '.' ) { return null; }
		var f = parseFloat( s );
		return isNaN( f ) ? null : f;
	}

	// rules MUST be pre-sorted descending by threshold (ORDELIST_Order_Color::for_js does this).
	function matchTotal( total, rules ) {
		if ( total == null || isNaN( total ) || ! rules || ! rules.length ) { return null; }
		for ( var i = 0; i < rules.length; i++ ) {
			var th = parseFloat( rules[ i ].threshold );
			if ( ! isNaN( th ) && total >= th ) {
				return { color: rules[ i ].color, label: rules[ i ].label || '' };
			}
		}
		return null;
	}

	var api = { parseAmount: parseAmount, matchTotal: matchTotal };
	// Node export for parity tests; harmless in the browser.
	if ( typeof module !== 'undefined' && module.exports ) { module.exports = api; }
	// Browser: expose for ole-admin.js.
	if ( typeof window !== 'undefined' ) { window.ORDELIST_OrderColor = api; }
} )();
