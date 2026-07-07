/* global OLE_PS, jQuery */
( function ( $ ) {
	'use strict';
	if ( 'undefined' === typeof OLE_PS ) { return; }

	// Pristine markup of the blank "new sheet" row, captured before WooCommerce enhances its select.
	var NEW_ROW_HTML = ( function () {
		var el = document.querySelector( '.ole-ps-sheet-new' );
		return el ? el.outerHTML : '';
	} )();

	function post( action, data ) {
		return $.post( OLE_PS.ajaxUrl, $.extend( { action: action, nonce: OLE_PS.nonce }, data ) );
	}

	// (Re)enhance WooCommerce product-search selects — WC only enhances not-yet-enhanced ones,
	// so triggering this after inserting a new row wires product search on it (and no double-init).
	function enhanceSelects() {
		$( document.body ).trigger( 'wc-enhanced-select-init' );
	}

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

	// Save / add a sheet — update the DOM in place instead of reloading the whole (slow) wp-admin page.
	$( document ).on( 'click', '.ole-ps-sheet-save', function () {
		var $btn  = $( this );
		var row   = $btn.closest( 'tr' );
		var isNew = row.hasClass( 'ole-ps-sheet-new' );
		$btn.prop( 'disabled', true );
		post( 'ole_ps_save_sheet', {
			id: row.data( 'id' ),
			name: row.find( '.ole-ps-sheet-name' ).val(),
			stock: row.find( '.ole-ps-sheet-stock' ).val(),
			products: row.find( '.ole-ps-sheet-products' ).val() || []
		} ).done( function ( r ) {
			var d = ( r && r.data ) || {};
			if ( isNew ) {
				promoteNewRow( row, d );
			} else {
				flash( row );
				upsertStockRow( d );
			}
		} ).fail( function () {
			window.alert( OLE_PS.i18n.error );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// Delete a sheet.
	$( document ).on( 'click', '.ole-ps-sheet-delete', function () {
		if ( ! window.confirm( OLE_PS.i18n.confirm ) ) { return; }
		var row = $( this ).closest( 'tr' );
		var id  = row.data( 'id' );
		post( 'ole_ps_delete_sheet', { id: id } )
			.done( function () {
				row.remove();
				$( '.ole-ps-table tbody tr[data-id="' + id + '"]' ).remove();
			} )
			.fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	// Turn the just-saved blank row into a saved row, add a fresh blank row, sync the stock table.
	function promoteNewRow( row, d ) {
		row.removeClass( 'ole-ps-sheet-new' ).data( 'id', d.id ).attr( 'data-id', d.id );
		row.find( '.ole-ps-sheet-save' )
			.removeClass( 'button-primary' )
			.text( OLE_PS.i18n.save )
			.after( ' <button type="button" class="button ole-ps-sheet-delete">×</button>' );
		flash( row );
		if ( NEW_ROW_HTML ) {
			row.after( NEW_ROW_HTML );
			enhanceSelects();
		}
		upsertStockRow( d );
	}

	// Add or update this instruction's row in the top "Current stock" table (name via .text() = XSS-safe).
	function upsertStockRow( d ) {
		if ( ! d || ! d.id ) { return; }
		var $tbody = $( '.ole-ps-table tbody' );
		if ( ! $tbody.length ) { return; }
		$tbody.find( 'td[colspan]' ).closest( 'tr' ).remove(); // drop the "no consumables yet" placeholder
		var $tr = $tbody.find( 'tr[data-id="' + d.id + '"]' );
		if ( ! $tr.length ) {
			$tr = $(
				'<tr data-id="' + d.id + '">' +
				'<td></td><td>' + OLE_PS.i18n.instruction + '</td>' +
				'<td><input type="number" step="1" class="ole-ps-stock" style="width:90px"/></td>' +
				'<td><button type="button" class="button ole-ps-save">' + OLE_PS.i18n.set + '</button> ' +
				'<button type="button" class="button ole-ps-add">' + OLE_PS.i18n.addPrinted + '</button></td>' +
				'</tr>'
			);
			$tbody.append( $tr );
		}
		if ( typeof d.name !== 'undefined' ) { $tr.children( 'td' ).eq( 0 ).text( d.name ); }
		if ( typeof d.stock !== 'undefined' ) { $tr.find( '.ole-ps-stock' ).val( parseInt( d.stock, 10 ) ); }
	}

	function flash( row ) {
		row.css( 'background-color', '#e6ffed' );
		setTimeout( function () { row.css( 'background-color', '' ); }, 700 );
	}
} )( jQuery );
