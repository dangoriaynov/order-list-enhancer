/* global OLE_PS, jQuery */
( function ( $ ) {
	'use strict';
	if ( 'undefined' === typeof OLE_PS ) { return; }

	function post( action, data ) {
		return $.post( OLE_PS.ajaxUrl, $.extend( { action: action, nonce: OLE_PS.nonce }, data ) );
	}

	// Enhance product search selects.
	$( '.ole-ps-sheet-products' ).each( function () {
		if ( $.fn.selectWoo ) { $( this ).selectWoo(); }
	} );

	// Set absolute stock.
	$( document ).on( 'click', '.ole-ps-save', function () {
		var row = $( this ).closest( 'tr' );
		post( 'ole_ps_set_stock', { id: row.data( 'id' ), stock: row.find( '.ole-ps-stock' ).val() } )
			.done( function () { flash( row ); } )
			.fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	// Add printed copies.
	$( document ).on( 'click', '.ole-ps-add', function () {
		var row = $( this ).closest( 'tr' );
		var n = window.prompt( OLE_PS.i18n.addQ, '100' );
		if ( null === n || '' === n ) { return; }
		post( 'ole_ps_add_stock', { id: row.data( 'id' ), amount: parseInt( n, 10 ) || 0 } )
			.done( function ( r ) { row.find( '.ole-ps-stock' ).val( r.data.stock ); flash( row ); } )
			.fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	// Save / add a sheet.
	$( document ).on( 'click', '.ole-ps-sheet-save', function () {
		var row = $( this ).closest( 'tr' );
		post( 'ole_ps_save_sheet', {
			id: row.data( 'id' ),
			name: row.find( '.ole-ps-sheet-name' ).val(),
			stock: row.find( '.ole-ps-sheet-stock' ).val(),
			products: row.find( '.ole-ps-sheet-products' ).val() || []
		} ).done( function () { window.location.reload(); } )
		   .fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	// Delete a sheet.
	$( document ).on( 'click', '.ole-ps-sheet-delete', function () {
		if ( ! window.confirm( OLE_PS.i18n.confirm ) ) { return; }
		var row = $( this ).closest( 'tr' );
		post( 'ole_ps_delete_sheet', { id: row.data( 'id' ) } )
			.done( function () { row.remove(); } )
			.fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	function flash( row ) {
		row.css( 'background-color', '#e6ffed' );
		setTimeout( function () { row.css( 'background-color', '' ); }, 700 );
	}
} )( jQuery );
