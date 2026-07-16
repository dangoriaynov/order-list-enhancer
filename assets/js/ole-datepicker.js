/* global jQuery */
/* Єдиний вигляд дат у плагіні: видиме поле dd/mm/yyyy (jQuery UI datepicker),
   приховане поле зберігає Y-m-d для розрахунків і AJAX. Після програмної зміни
   прихованого поля викликайте .trigger( 'ole-sync' ), щоб оновити видиме. */
( function ( $ ) {
	'use strict';

	function parseYmd( v ) {
		try { return v ? $.datepicker.parseDate( 'yy-mm-dd', v ) : null; } catch ( e ) { return null; }
	}

	window.ordelistDates = {
		init: function ( root ) {
			if ( ! $.fn.datepicker ) { return; }
			$( root || document.body ).find( 'input.ole-date' ).each( function () {
				var $h = $( this );
				if ( $h.data( 'oleDp' ) ) { return; }
				$h.data( 'oleDp', 1 );
				var $vis = $( '<input type="text" class="ole-date-vis" size="10" placeholder="dd/mm/yyyy" autocomplete="off"/>' );
				if ( $h.attr( 'title' ) ) { $vis.attr( 'title', $h.attr( 'title' ) ); }
				$vis.insertAfter( $h );
				$vis.datepicker( {
					dateFormat: 'dd/mm/yy',
					altField: $h[ 0 ],
					altFormat: 'yy-mm-dd',
					firstDay: 1,
					onSelect: function () { $h.trigger( 'change' ); }
				} );
				var initial = parseYmd( $h.val() );
				if ( initial ) { $vis.datepicker( 'setDate', initial ); }
				// Ручний ввід приймаємо теж; сміття очищає обидва поля.
				$vis.on( 'change', function () {
					var typed = null;
					try { typed = $.datepicker.parseDate( 'dd/mm/yy', $vis.val() ); } catch ( e ) { typed = null; }
					if ( typed ) {
						$vis.datepicker( 'setDate', typed );
					} else {
						$vis.val( '' );
						$h.val( '' );
					}
					$h.trigger( 'change' );
				} );
				$h.on( 'ole-sync', function () {
					var d = parseYmd( $h.val() );
					if ( d ) { $vis.datepicker( 'setDate', d ); } else { $vis.val( '' ); }
				} );
			} );
		}
	};

	$( function () { window.ordelistDates.init(); } );
} )( jQuery );
