/* global OLE_PS, jQuery */
( function ( $ ) {
	'use strict';
	if ( 'undefined' === typeof OLE_PS ) { return; }

	var TH = OLE_PS.thresholds || { sticker: 0, instruction: 0 };

	// Pristine markup of the blank "new sheet" row, captured before WooCommerce enhances its select.
	var NEW_ROW_HTML = ( function () {
		var el = document.querySelector( '.ole-ps-sheet-new' );
		return el ? el.outerHTML : '';
	} )();

	function post( action, data ) {
		return $.post( OLE_PS.ajaxUrl, $.extend( { action: action, nonce: OLE_PS.nonce }, data ) );
	}

	// WC only enhances not-yet-enhanced selects, so triggering this wires product search on new rows.
	function enhanceSelects() {
		$( document.body ).trigger( 'wc-enhanced-select-init' );
	}

	// Recompute the low / negative highlight class for a row after its stock changes.
	function applyStatus( $row, stock, threshold ) {
		stock = parseInt( stock, 10 ) || 0;
		$row.removeClass( 'ole-ps-low ole-ps-neg' );
		if ( stock < 0 ) { $row.addClass( 'ole-ps-neg' ); }
		else if ( stock <= threshold ) { $row.addClass( 'ole-ps-low' ); }
	}

	// Brief green confirmation that survives on top of the low/neg highlight (class, not inline bg).
	function flash( $row ) {
		$row.addClass( 'ole-ps-flash' );
		setTimeout( function () { $row.removeClass( 'ole-ps-flash' ); }, 700 );
	}

	// Disable a sheet's Save/Add button while its name is empty.
	function syncSaveBtn( $row ) {
		var hasName = $.trim( $row.find( '.ole-ps-sheet-name' ).val() || '' ) !== '';
		$row.find( '.ole-ps-sheet-save' ).prop( 'disabled', ! hasName );
	}
	$( function () { $( '.ole-ps-sheet' ).each( function () { syncSaveBtn( $( this ) ); } ); } );
	$( document ).on( 'input', '.ole-ps-sheet-name', function () { syncSaveBtn( $( this ).closest( 'tr' ) ); } );

	// Set absolute stock (stickers table).
	$( document ).on( 'click', '.ole-ps-save', function () {
		var row   = $( this ).closest( 'tr' );
		var stock = row.find( '.ole-ps-stock' ).val();
		post( 'ole_ps_set_stock', { id: row.data( 'id' ), stock: stock } )
			.done( function () { applyStatus( row, stock, TH.sticker ); flash( row ); } )
			.fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	// Add printed copies (stickers table).
	$( document ).on( 'click', '.ole-ps-add', function () {
		var row = $( this ).closest( 'tr' );
		var n = window.prompt( OLE_PS.i18n.addQ, '100' );
		if ( null === n || '' === n ) { return; }
		post( 'ole_ps_add_stock', { id: row.data( 'id' ), amount: parseInt( n, 10 ) || 0 } )
			.done( function ( r ) {
				row.find( '.ole-ps-stock' ).val( r.data.stock );
				applyStatus( row, r.data.stock, TH.sticker );
				flash( row );
			} )
			.fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	// Add / update a sticker for a product or variation.
	$( document ).on( 'click', '.ole-ps-sticker-add', function () {
		var $btn = $( this );
		var row  = $btn.closest( 'tr' );
		var pid  = row.find( '.ole-ps-sticker-product' ).val();
		if ( ! pid ) { return; } // pick a product first
		$btn.prop( 'disabled', true );
		post( 'ole_ps_add_sticker', { product: pid, stock: row.find( '.ole-ps-sticker-stock' ).val() } )
			.done( function ( r ) {
				upsertStickerRow( ( r && r.data ) || {} );
				row.find( '.ole-ps-sticker-product' ).val( null ).trigger( 'change' );
				row.find( '.ole-ps-sticker-stock' ).val( 0 );
			} )
			.fail( function () { window.alert( OLE_PS.i18n.error ); } )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	// Save / add an instruction sheet — update in place instead of reloading the whole (slow) wp-admin page.
	$( document ).on( 'click', '.ole-ps-sheet-save', function () {
		var $btn  = $( this );
		var row   = $btn.closest( 'tr' );
		var name  = $.trim( row.find( '.ole-ps-sheet-name' ).val() || '' );
		if ( '' === name ) { row.find( '.ole-ps-sheet-name' ).focus(); return; } // a sheet needs a name — skip the doomed round-trip
		var isNew = row.hasClass( 'ole-ps-sheet-new' );
		var stock = row.find( '.ole-ps-sheet-stock' ).val();
		$btn.prop( 'disabled', true );
		post( 'ole_ps_save_sheet', {
			id: row.data( 'id' ),
			name: name,
			stock: stock,
			products: row.find( '.ole-ps-sheet-products' ).val() || []
		} ).done( function ( r ) {
			if ( isNew ) { promoteNewRow( row, ( r && r.data ) || {} ); }
			else { applyStatus( row, stock, TH.instruction ); flash( row ); }
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
		post( 'ole_ps_delete_sheet', { id: row.data( 'id' ) } )
			.done( function () { row.remove(); } )
			.fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	// Delete a sticker.
	$( document ).on( 'click', '.ole-ps-sticker-delete', function () {
		if ( ! window.confirm( OLE_PS.i18n.confirmSticker ) ) { return; }
		var row = $( this ).closest( 'tr' );
		post( 'ole_ps_delete_sticker', { id: row.data( 'id' ) } )
			.done( function () { row.remove(); } )
			.fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	// Turn the just-saved blank sheet row into a saved row + add a fresh blank row.
	function promoteNewRow( row, d ) {
		row.removeClass( 'ole-ps-sheet-new' ).data( 'id', d.id ).attr( 'data-id', d.id );
		row.find( '.ole-ps-sheet-save' )
			.removeClass( 'button-primary' )
			.text( OLE_PS.i18n.save )
			.after( ' <button type="button" class="button ole-ps-sheet-delete">×</button>' );
		applyStatus( row, d.stock, TH.instruction );
		flash( row );
		if ( NEW_ROW_HTML ) {
			var $fresh = $( NEW_ROW_HTML );
			row.after( $fresh );
			enhanceSelects();
			syncSaveBtn( $fresh );
		}
	}

	// Add or update a sticker's row in the Stickers table (name via .text() = XSS-safe).
	function upsertStickerRow( d ) {
		if ( ! d || ! d.id ) { return; }
		var $tbody = $( '.ole-ps-stickers tbody' );
		if ( ! $tbody.length ) { return; }
		var $tr = $tbody.find( 'tr[data-id="' + d.id + '"]' );
		if ( ! $tr.length ) {
			$tr = $(
				'<tr data-id="' + d.id + '">' +
				'<td></td>' +
				'<td><input type="number" step="1" class="ole-ps-stock" style="width:90px"/></td>' +
				'<td><button type="button" class="button ole-ps-save">' + OLE_PS.i18n.set + '</button> ' +
				'<button type="button" class="button ole-ps-add">' + OLE_PS.i18n.addPrinted + '</button> ' +
				'<button type="button" class="button ole-ps-sticker-delete">×</button></td>' +
				'</tr>'
			);
			$tbody.find( '.ole-ps-sticker-new' ).before( $tr );
		}
		if ( typeof d.name !== 'undefined' ) { $tr.children( 'td' ).eq( 0 ).text( d.name ); }
		if ( typeof d.stock !== 'undefined' ) { $tr.find( '.ole-ps-stock' ).val( parseInt( d.stock, 10 ) ); }
		applyStatus( $tr, d.stock, TH.sticker );
		flash( $tr );
	}
} )( jQuery );
