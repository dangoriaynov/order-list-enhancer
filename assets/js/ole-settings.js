/* global jQuery, OLE_SETTINGS */
jQuery( function ( $ ) {
	var $form = $( '#ole-settings-form' );
	if ( ! $form.length ) { return; }
	var $tbody = $form.find( '.ole-rules tbody' );

	// Repeatable shipping rules.
	$form.on( 'click', '.ole-rule-add', function () {
		var $tr = $tbody.find( 'tr' ).first().clone();
		$tr.find( 'input' ).val( '' );
		$tbody.append( $tr );
	} );
	$form.on( 'click', '.ole-rule-remove', function () {
		if ( $tbody.find( 'tr' ).length > 1 ) {
			$( this ).closest( 'tr' ).remove();
		}
	} );

	// AJAX save — no page reload.
	$form.on( 'submit', function ( e ) {
		e.preventDefault();
		var $btn    = $form.find( 'button[type=submit]' );
		var $status = $form.find( '.ole-save-status' );
		$btn.prop( 'disabled', true );
		$status.text( OLE_SETTINGS.i18n.saving ).css( 'color', '' );

		var data = $form.serializeArray();
		data.push( { name: 'action', value: 'ole_save_settings' } );
		data.push( { name: 'nonce', value: OLE_SETTINGS.nonce } );

		$.post( OLE_SETTINGS.ajaxUrl, data )
			.done( function ( res ) {
				if ( res && res.success ) {
					$status.text( OLE_SETTINGS.i18n.saved ).css( 'color', '#1a7a3c' );
				} else {
					$status.text( OLE_SETTINGS.i18n.error ).css( 'color', '#d63638' );
				}
			} )
			.fail( function () {
				$status.text( OLE_SETTINGS.i18n.error ).css( 'color', '#d63638' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
				setTimeout( function () { $status.text( '' ); }, 2500 );
			} );
	} );
} );
