/* global jQuery, OLE_SETTINGS */
jQuery( function ( $ ) {
	var $form = $( '#ole-settings-form' );
	if ( ! $form.length ) { return; }
	var $tbody = $form.find( '.ole-rules tbody' );

	// Visual color pickers for every hex field.
	function initColorPickers( $scope ) {
		if ( ! $.fn.wpColorPicker ) { return; }
		( $scope || $form ).find( '.ole-color' ).not( '.wp-color-picker' ).wpColorPicker();
	}
	initColorPickers();

	function ruleRowHtml() {
		return '<tr>' +
			'<td><input type="text" name="rule_keyword[]" value="" class="regular-text"/></td>' +
			'<td><input type="text" name="rule_color[]" value="" class="ole-color" placeholder="#dcefd2"/></td>' +
			'<td><input type="text" name="rule_label[]" value="" class="regular-text"/></td>' +
			'<td><button type="button" class="button ole-rule-remove">&times;</button></td>' +
			'</tr>';
	}

	// Repeatable shipping rules.
	$form.on( 'click', '.ole-rule-add', function () {
		var $tr = $( ruleRowHtml() );
		$tbody.append( $tr );
		initColorPickers( $tr );
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

	// Extras mapping: add/remove rows + (re)init WC product search.
	function oleInitProductSearch( $scope ) {
		( $scope || jQuery( '.ole-extras' ) ).find( 'select.wc-product-search' ).each( function () {
			var $s = jQuery( this );
			if ( $s.data( 'select2' ) ) { return; }
			if ( jQuery.fn.selectWoo ) {
				$s.selectWoo( {
					ajax: {
						url: ( window.wc_enhanced_select_params && window.wc_enhanced_select_params.ajax_url ) || window.ajaxurl,
						dataType: 'json',
						delay: 250,
						data: function ( params ) {
							return { term: params.term, action: 'woocommerce_json_search_products_and_variations', security: ( window.wc_enhanced_select_params || {} ).search_products_nonce };
						},
						processResults: function ( data ) {
							var out = [];
							jQuery.each( data, function ( id, text ) { out.push( { id: id, text: text } ); } );
							return { results: out };
						}
					},
					minimumInputLength: 2,
					width: '100%',
					dropdownAutoWidth: true,
					// Lead the selected label with the size ("… - 500 г" -> "500 г — …")
					// so variations stay distinguishable when the box truncates.
					templateSelection: function ( data ) {
						var t = ( data && data.text ) || '';
						var i = t.lastIndexOf( ' - ' );
						return i > -1 ? ( t.slice( i + 3 ) + ' — ' + t.slice( 0, i ) ) : t;
					}
				} );
			}
		} );
	}
	oleInitProductSearch();
	jQuery( document ).on( 'click', '.ole-extra-add', function ( e ) {
		e.preventDefault();
		var $tbody = jQuery( '.ole-extras tbody' );
		var $row = $tbody.find( 'tr' ).first().clone();
		$row.find( 'input' ).val( '' );
		$row.find( 'select' ).empty().append( '<option value="" selected></option>' ).val( '' );
		$tbody.append( $row );
		oleInitProductSearch( $row );
	} );
	jQuery( document ).on( 'click', '.ole-extra-remove', function ( e ) {
		e.preventDefault();
		var $rows = jQuery( '.ole-extras tbody tr' );
		if ( $rows.length > 1 ) { jQuery( this ).closest( 'tr' ).remove(); }
		else { jQuery( this ).closest( 'tr' ).find( 'input' ).val( '' ); jQuery( this ).closest( 'tr' ).find( 'select' ).val( null ); }
	} );
} );
