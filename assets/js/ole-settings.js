/* global jQuery, ORDELIST_SETTINGS */
jQuery( function ( $ ) {
	var $form = $( '#ole-settings-form' );
	if ( ! $form.length ) { return; }
	// Visual color pickers for every hex field.
	function initColorPickers( $scope ) {
		if ( ! $.fn.wpColorPicker ) { return; }
		( $scope || $form ).find( '.ole-color' ).not( '.wp-color-picker' ).wpColorPicker();
	}

	// Capture a clean blank-row template for EACH repeatable table BEFORE the
	// pickers wrap the inputs. Several tables share the .ole-rules / .ole-rule-add
	// classes (shipping rules, order-total rules), so add/remove must scope to the
	// table the clicked button belongs to and rebuild that table's own row shape.
	$form.find( 'table.ole-rules' ).each( function () {
		var $first = $( this ).find( 'tbody tr' ).first();
		if ( ! $first.length ) { return; }
		var $blank = $first.clone();
		$blank.find( 'input' ).val( '' );
		$( this ).data( 'oleBlankRow', $blank.prop( 'outerHTML' ) );
	} );

	initColorPickers();

	$form.on( 'click', '.ole-rule-add', function () {
		var $t  = $( this ).closest( 'td' ).find( 'table.ole-rules' ).first();
		var tpl = $t.data( 'oleBlankRow' );
		if ( ! tpl ) { return; }
		var $tr = $( tpl );
		$t.find( 'tbody' ).first().append( $tr );
		initColorPickers( $tr );
	} );
	$form.on( 'click', '.ole-rule-remove', function () {
		var $tb = $( this ).closest( 'table.ole-rules' ).find( 'tbody' ).first();
		if ( $tb.find( 'tr' ).length > 1 ) {
			$( this ).closest( 'tr' ).remove();
		}
	} );

	// Unsaved-changes guard. Flipping a switch only changes the form - nothing
	// is stored until the save bar is used, and a switch that slid over reads
	// as already applied. Mark the bar whenever the form differs from what was
	// last stored, and hold the browser on the way out.
	//
	// Compared against a snapshot rather than latched on the first event: the
	// color pickers and product selects fire change on the inputs they wrap
	// while initialising, and a page that greets you already "unsaved" (and
	// prompts on every exit) trains you to ignore the warning. Comparing also
	// clears the mark when an edit is undone by hand.
	var $status  = $form.find( '.ole-save-status' );
	var $bar     = $form.find( '.ole-savebar' );
	var dirty    = false;
	var baseline = null; // form as last stored; null until the initial snapshot

	function idleStatus() {
		if ( dirty ) { $status.text( ORDELIST_SETTINGS.i18n.unsaved ).css( 'color', '#b26a00' ); }
		else { $status.text( '' ).css( 'color', '' ); }
	}
	function setDirty( on ) {
		if ( dirty === on ) { return; }
		dirty = on;
		$bar.toggleClass( 'is-dirty', on );
		idleStatus();
	}
	function refresh() {
		if ( null !== baseline ) { setDirty( $form.serialize() !== baseline ); }
	}
	// Deferred: the row add/remove handlers that rebuild the table are bound on
	// document, so they run after this one - serialize only once they are done.
	function refreshLater() { setTimeout( refresh, 0 ); }

	setTimeout( function () {
		baseline = $form.serialize();
		$form.on( 'change input', ':input', refresh );
		$form.on( 'click', '.ole-rule-add, .ole-rule-remove, .ole-extra-add, .ole-extra-remove', refreshLater );
	}, 0 );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( ! dirty ) { return; }
		e.preventDefault();
		e.returnValue = ''; // Chrome only shows its native prompt when this is set.
	} );

	// AJAX save - no page reload. A nonce lives 24h; if the tab sat open longer
	// the save 403s, so we fetch a fresh nonce once and retry before giving up.
	$form.on( 'submit', function ( e ) {
		e.preventDefault();
		var $btn = $form.find( 'button[type=submit]' );
		$btn.prop( 'disabled', true );
		$status.text( ORDELIST_SETTINGS.i18n.saving ).css( 'color', '' );

		function finish( msg, color ) {
			$status.text( msg ).css( 'color', color );
			$btn.prop( 'disabled', false );
			setTimeout( idleStatus, 4000 );
		}
		function send( isRetry ) {
			var data = $form.serializeArray();
			var sent = $form.serialize(); // what this request stores, not what the form holds when it answers
			data.push( { name: 'action', value: 'ordelist_save_settings' } );
			data.push( { name: 'nonce', value: ORDELIST_SETTINGS.nonce } );
			$.post( ORDELIST_SETTINGS.ajaxUrl, data )
				.done( function ( res ) {
					if ( res && res.success ) {
						baseline = sent;
						refresh(); // an edit made while the request was in flight stays flagged
						finish( ORDELIST_SETTINGS.i18n.saved, '#1a7a3c' );
					} else {
						finish( ORDELIST_SETTINGS.i18n.error, '#d63638' );
					}
				} )
				.fail( function ( xhr ) {
					if ( 403 === xhr.status && ! isRetry ) {
						refreshNonceAndRetry();
					} else if ( 403 === xhr.status ) {
						finish( ORDELIST_SETTINGS.i18n.expired, '#d63638' );
					} else {
						finish( ORDELIST_SETTINGS.i18n.error, '#d63638' );
					}
				} );
		}
		function refreshNonceAndRetry() {
			$.post( ORDELIST_SETTINGS.ajaxUrl, { action: 'ordelist_refresh_nonce' } )
				.done( function ( res ) {
					if ( res && res.success && res.data && res.data.nonce ) {
						ORDELIST_SETTINGS.nonce = res.data.nonce;
						send( true );
					} else {
						finish( ORDELIST_SETTINGS.i18n.expired, '#d63638' );
					}
				} )
				.fail( function () {
					finish( ORDELIST_SETTINGS.i18n.expired, '#d63638' );
				} );
		}
		send( false );
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
							// Сортуємо тут: серверний порядок губиться через числові ключі JSON.
							out.sort( function ( a, b ) { return a.text.localeCompare( b.text, document.documentElement.lang || undefined ); } );
							return { results: out };
						}
					},
					minimumInputLength: 2,
					width: '100%',
					dropdownAutoWidth: true,
					// Lead the selected label with the size ("… - 500 г" -> "500 г - …")
					// so variations stay distinguishable when the box truncates.
					templateSelection: function ( data ) {
						var t = ( data && data.text ) || '';
						var i = t.lastIndexOf( ' - ' );
						return i > -1 ? ( t.slice( i + 3 ) + ' - ' + t.slice( 0, i ) ) : t;
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

( function ( $ ) {
	'use strict';

	function initTabs() {
		var $nav = $( '.ole-tabnav a' );
		var $panels = $( '.ole-tabpanel' );
		if ( ! $nav.length ) { return; }

		function activate( id ) {
			var found = false;
			$panels.each( function () {
				var match = ( '#' + this.id ) === id;
				this.hidden = ! match;
				found = found || match;
			} );
			if ( ! found ) { // invalid hash → first panel
				$panels.each( function ( i ) { this.hidden = i !== 0; } );
				id = '#' + $panels.get( 0 ).id;
			}
			$nav.removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
			$nav.filter( '[href="' + id + '"]' ).addClass( 'is-active' ).attr( 'aria-selected', 'true' );
		}

		$nav.on( 'click', function ( e ) {
			e.preventDefault();
			var id = $( this ).attr( 'href' );
			if ( window.history && history.replaceState ) { history.replaceState( null, '', id ); }
			else { window.location.hash = id; }
			activate( id );
		} );

		activate( window.location.hash || ( '#' + $panels.get( 0 ).id ) );
	}

	function initDisclosure() {
		$( '.ole-card[data-switch]' ).each( function () {
			var $card = $( this );
			var name = $card.data( 'switch' );
			var $cb = $card.find( 'input[name="' + name + '"]' ).first();
			function sync() { $card.toggleClass( 'ole-off', ! $cb.prop( 'checked' ) ); }
			$cb.on( 'change', sync );
			sync();
		} );
	}

	function initHelp() {
		$( '.ole-help' ).on( 'click', function ( e ) { e.preventDefault(); } );
	}

	$( function () { initTabs(); initDisclosure(); initHelp(); } );
} )( jQuery );
