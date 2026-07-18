/* global jQuery */
/* Пошук товарів із сортуванням за алфавітом. Сервер віддає JSON-об'єкт із
   числовими ключами (ID), а браузер перебирає такі ключі за зростанням - тому
   серверний порядок губиться і сортувати треба на клієнті. Ініціалізує кожен
   select.ole-psearch; action та exclude_type беруться з data-атрибутів. */
( function ( $ ) {
	'use strict';

	window.ordelistSearch = {
		init: function ( root ) {
			if ( ! $.fn.selectWoo ) { return; }
			$( root || document.body ).find( 'select.ole-psearch' ).each( function () {
				var $s = $( this );
				if ( $s.data( 'select2' ) ) { return; }
				$s.selectWoo( {
					ajax: {
						url: ( window.wc_enhanced_select_params && window.wc_enhanced_select_params.ajax_url ) || window.ajaxurl,
						dataType: 'json',
						delay: 250,
						data: function ( params ) {
							return {
								term: params.term,
								action: $s.data( 'action' ) || 'woocommerce_json_search_products_and_variations',
								security: ( window.wc_enhanced_select_params || {} ).search_products_nonce,
								exclude_type: $s.data( 'exclude_type' ) || ''
							};
						},
						processResults: function ( data ) {
							var out = [];
							$.each( data, function ( id, text ) { out.push( { id: id, text: text } ); } );
							out.sort( function ( a, b ) { return a.text.localeCompare( b.text, document.documentElement.lang || undefined ); } );
							return { results: out };
						}
					},
					minimumInputLength: 2,
					placeholder: $s.data( 'placeholder' ) || '',
					dropdownAutoWidth: true
				} );
			} );
		}
	};

	$( function () { window.ordelistSearch.init(); } );
} )( jQuery );
