/* global jQuery */
( function ( $ ) {
	'use strict';

	// Move each note block from the hidden carrier column into the order-number cell, under the order number.
	$( function () {
		$( '.ole-oc-wrap' ).each( function () {
			var $wrap = $( this );
			var $tr   = $wrap.closest( 'tr' );
			// The order-number cell is a <th scope="row"> (WP_List_Table marks the primary
			// column as the row header), so the lookup must not be restricted to td.
			var $cell = $tr.find( '.column-order_number, .order_number' ).first();
			if ( ! $cell.length ) { $cell = $tr.find( '.order-view' ).first().closest( 'td, th' ); }
			if ( $cell.length ) { $cell.append( $wrap ); }
		} );
	} );

	// Click a note to expand / collapse its full text (native title also shows it on hover).
	$( document ).on( 'click', '.ole-oc-note', function () {
		$( this ).toggleClass( 'ole-oc-open' );
	} );
} )( jQuery );
