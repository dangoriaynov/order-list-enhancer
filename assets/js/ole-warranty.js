/* global ORDELIST_WR, jQuery */
( function ( $ ) {
	'use strict';
	if ( 'undefined' === typeof ORDELIST_WR ) { return; }

	function post( action, data ) {
		return $.post( ORDELIST_WR.ajaxUrl, $.extend( { action: action, nonce: ORDELIST_WR.nonce }, data ) );
	}

	// Короткий зелений спалах після збереження (клас, не inline-стиль).
	function flash( $row ) {
		$row.addClass( 'ole-wr-flash' );
		setTimeout( function () { $row.removeClass( 'ole-wr-flash' ); }, 700 );
	}

	function setStatus( $row, cls ) {
		$row.removeClass( 'ole-wr-expired ole-wr-soon ole-wr-zero' );
		if ( cls ) { $row.addClass( cls ); }
	}

	// «Add» активна лише коли вибрано товар і вказано дату.
	function syncAddBtn() {
		var $row = $( '.ole-wr-new' );
		var ok   = $row.find( '.ole-wr-product' ).val() && $row.find( '.ole-wr-expiry' ).val();
		$row.find( '.ole-wr-add' ).prop( 'disabled', ! ok );
	}
	$( syncAddBtn );
	$( document ).on( 'change input', '.ole-wr-new .ole-wr-product, .ole-wr-new .ole-wr-expiry', syncAddBtn );

	// Додати партію - рядок з'являється без перезавантаження сторінки.
	$( document ).on( 'click', '.ole-wr-add', function () {
		var $btn = $( this );
		var $row = $btn.closest( 'tr' );
		$btn.prop( 'disabled', true );
		post( 'ordelist_wr_add', {
			product: $row.find( '.ole-wr-product' ).val(),
			expiry:  $row.find( '.ole-wr-expiry' ).val(),
			qty:     $row.find( '.ole-wr-qty' ).val(),
			note:    $row.find( '.ole-wr-note' ).val()
		} ).done( function ( r ) {
			appendRow( ( r && r.data ) || {} );
			$row.find( '.ole-wr-product' ).val( null ).trigger( 'change' );
			$row.find( '.ole-wr-expiry' ).val( '' ).trigger( 'ole-sync' );
			$row.find( '.ole-wr-qty' ).val( 0 );
			$row.find( '.ole-wr-note' ).val( '' );
		} ).fail( function () {
			window.alert( ORDELIST_WR.i18n.error );
		} ).always( function () {
			syncAddBtn();
		} );
	} );

	// Зберегти рядок.
	$( document ).on( 'click', '.ole-wr-save', function () {
		var $row = $( this ).closest( 'tr' );
		if ( ! $row.find( '.ole-wr-expiry' ).val() ) { $row.find( '.ole-wr-expiry' ).focus(); return; }
		post( 'ordelist_wr_save', {
			id:     $row.data( 'id' ),
			expiry: $row.find( '.ole-wr-expiry' ).val(),
			qty:    $row.find( '.ole-wr-qty' ).val(),
			note:   $row.find( '.ole-wr-note' ).val()
		} ).done( function ( r ) {
			setStatus( $row, ( r && r.data && r.data.status ) || '' );
			$row.find( '.ole-wr-status' ).text( ( r && r.data && r.data.status_label ) || '' );
			flash( $row );
		} ).fail( function () {
			window.alert( ORDELIST_WR.i18n.error );
		} );
	} );

	// Видалити партію.
	$( document ).on( 'click', '.ole-wr-delete', function () {
		if ( ! window.confirm( ORDELIST_WR.i18n.confirm ) ) { return; }
		var $row = $( this ).closest( 'tr' );
		post( 'ordelist_wr_delete', { id: $row.data( 'id' ) } )
			.done( function () { $row.remove(); } )
			.fail( function () { window.alert( ORDELIST_WR.i18n.error ); } );
	} );

	// Новий збережений рядок (текст - через .text()/val(), XSS-safe); стане на місце за датою після перезавантаження.
	function appendRow( d ) {
		if ( ! d || ! d.id ) { return; }
		var $tr = $(
			'<tr data-id="' + parseInt( d.id, 10 ) + '">' +
			'<td><a></a></td>' +
			'<td><input type="hidden" class="ole-wr-expiry ole-date"/></td>' +
			'<td><input type="number" step="1" class="ole-wr-qty" style="width:80px"/></td>' +
			'<td><input type="text" class="ole-wr-note regular-text" maxlength="200"/></td>' +
			'<td class="ole-wr-status"></td>' +
			'<td><button type="button" class="button ole-wr-save">' + ORDELIST_WR.i18n.save + '</button> ' +
			'<button type="button" class="button ole-wr-delete" aria-label="' + ORDELIST_WR.i18n.del + '">×</button></td>' +
			'</tr>'
		);
		$tr.find( 'a' ).text( d.name || ( '#' + d.id ) ).attr( 'href', d.url || '#' );
		$tr.find( '.ole-wr-expiry' ).val( d.expiry || '' );
		if ( window.ordelistDates ) { window.ordelistDates.init( $tr[ 0 ] ); }
		$tr.find( '.ole-wr-expiry' ).trigger( 'ole-sync' );
		$tr.find( '.ole-wr-qty' ).val( parseInt( d.qty, 10 ) || 0 );
		$tr.find( '.ole-wr-note' ).val( d.note || '' );
		$tr.find( '.ole-wr-status' ).text( d.status_label || '' );
		setStatus( $tr, d.status || '' );
		$( '.ole-wr-new' ).after( $tr );
		flash( $tr );
	}
} )( jQuery );
