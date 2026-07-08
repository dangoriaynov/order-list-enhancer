/* global jQuery */
( function ( $ ) {
	'use strict';

	// Move each note block from the hidden carrier column into the order-number cell, under the order number.
	$( function () {
		$( '.ole-oc-wrap' ).each( function () {
			var $wrap = $( this );
			var $tr   = $wrap.closest( 'tr' );
			var $cell = $tr.find( 'td.order_number' ).first();
			if ( ! $cell.length ) { $cell = $tr.find( '.order-view' ).first().closest( 'td' ); }
			if ( $cell.length ) { $cell.append( $wrap ); }
		} );
	} );

	// Click a note to expand / collapse its full text (native title also shows it on hover).
	$( document ).on( 'click', '.ole-oc-note', function () {
		$( this ).toggleClass( 'ole-oc-open' );
	} );
} )( jQuery );
