/* global ORDELIST_FC, OrdelistForecastCalc, Chart, jQuery */
( function ( $ ) {
	'use strict';
	if ( 'undefined' === typeof ORDELIST_FC || 'undefined' === typeof OrdelistForecastCalc ) { return; }

	var C = OrdelistForecastCalc;
	var MMDD = C.mmddList();
	var COLORS = [ '#2271b1', '#d63638', '#00a32a', '#b26a00', '#8c5e58', '#3c434a', '#7f54b3' ];

	var state = {
		data: null,          // payload з сервера
		target: null,        // {type:'product'} | {type:'variation', id}
		unit: 'kg',
		refYear: null,
		coefAuto: true,
		chart: null,
		highlight: null      // [[startIdx,endIdx],…] — підсвітка відрізка на графіку
	};

	// Підсвічує вибраний календарний відрізок на кривих (без плагінів-залежностей).
	var slicePlugin = {
		id: 'oleFcSlice',
		beforeDatasetsDraw: function ( chart ) {
			if ( ! state.highlight ) { return; }
			var x = chart.scales.x;
			var y = chart.scales.y;
			var ctx = chart.ctx;
			ctx.save();
			ctx.fillStyle = 'rgba(34, 113, 177, 0.08)';
			for ( var i = 0; i < state.highlight.length; i++ ) {
				var x1 = x.getPixelForValue( state.highlight[ i ][ 0 ] );
				var x2 = x.getPixelForValue( state.highlight[ i ][ 1 ] );
				ctx.fillRect( x1, y.top, x2 - x1, y.bottom - y.top );
			}
			ctx.restore();
		}
	};

	function post( data ) {
		return $.post( ORDELIST_FC.ajaxUrl, $.extend( { action: 'ordelist_fc_series', nonce: ORDELIST_FC.nonce }, data ) );
	}

	function todayYMD() { return ORDELIST_FC.today; }
	function curYear() { return todayYMD().slice( 0, 4 ); }
	function mmddOf( ymd ) {
		var k = ( ymd || '' ).slice( 5 );
		return ( '02-29' === k ) ? '02-28' : k;
	}
	function addDays( ymd, days ) {
		var d = new Date( ymd + 'T00:00:00Z' );
		d.setUTCDate( d.getUTCDate() + days );
		return d.toISOString().slice( 0, 10 );
	}
	function fmt( n, unit ) {
		var v = ( 'kg' === unit ) ? ( Math.round( n * 10 ) / 10 ) : Math.round( n );
		return v + ' ' + ORDELIST_FC.i18n[ unit ];
	}
	function weightsMap() {
		var w = {};
		for ( var i = 0; i < state.data.variations.length; i++ ) { w[ state.data.variations[ i ].id ] = state.data.variations[ i ].weight_kg; }
		return w;
	}
	function series() { return C.unitSeries( state.data.variations, state.target, state.unit ); }
	function yearsOf( s ) {
		var ys = [];
		for ( var y in s ) { if ( Object.prototype.hasOwnProperty.call( s, y ) ) { ys.push( y ); } }
		ys.sort();
		return ys;
	}

	// ---- завантаження товару ----
	$( document ).on( 'change', '.ole-fc-product', function () {
		var picked = parseInt( $( this ).val(), 10 ) || 0;
		if ( ! picked ) { return; }
		post( { product: picked } ).done( function ( r ) {
			var d = r && r.data;
			if ( ! d ) { return; }
			state.data = d;
			// Вибрали варіацію — режим варіації; вибрали товар — увесь препарат.
			state.target = ( picked !== d.product_id ) ? { type: 'variation', id: picked } : { type: 'product' };
			// Товар без жодної ваги → одиниця за замовчуванням шт.
			var anyWeight = d.variations.some( function ( v ) { return null !== v.weight_kg; } );
			state.unit = anyWeight ? $( 'input[name="ole-fc-unit"]:checked' ).val() : 'pcs';
			if ( ! anyWeight ) { $( 'input[name="ole-fc-unit"][value="pcs"]' ).prop( 'checked', true ); }
			fillRefYears();
			state.coefAuto = true;
			recalc();
		} ).fail( function () { window.alert( ORDELIST_FC.i18n.error ); } );
	} );

	function fillRefYears() {
		var ys = yearsOf( series() );
		var $ref = $( '.ole-fc-ref' ).empty();
		var def = null;
		for ( var i = 0; i < ys.length; i++ ) {
			$ref.append( $( '<option/>' ).val( ys[ i ] ).text( ys[ i ] ) );
			if ( ys[ i ] < curYear() ) { def = ys[ i ]; } // останній повний рік з даними
		}
		state.refYear = def || ys[ ys.length - 1 ] || null;
		if ( state.refYear ) { $ref.val( state.refYear ); }
	}

	// ---- контролі ----
	$( document ).on( 'change', 'input[name="ole-fc-unit"]', function () { state.unit = this.value; state.coefAuto = true; recalc(); } );
	$( document ).on( 'change', '.ole-fc-ref', function () { state.refYear = $( this ).val(); state.coefAuto = true; recalc(); } );
	$( document ).on( 'input', '.ole-fc-coef', function () { state.coefAuto = false; recalcPanel(); } );
	$( document ).on( 'click', '.ole-fc-coef-auto', function () { state.coefAuto = true; recalcPanel(); } );
	$( document ).on( 'input', '.ole-fc-margin', recalcPanel );
	$( document ).on( 'change', '.ole-fc-start, .ole-fc-end', recalcPanel );
	$( document ).on( 'click', '.ole-fc-preset', function () {
		$( '.ole-fc-start' ).val( todayYMD() );
		$( '.ole-fc-end' ).val( addDays( todayYMD(), parseInt( $( this ).data( 'days' ), 10 ) || 30 ) );
		recalcPanel();
	} );

	function period() {
		var s = $( '.ole-fc-start' ).val();
		var e = $( '.ole-fc-end' ).val();
		if ( ! s || ! e ) { return null; }
		return { startYMD: s, endYMD: e, startMMDD: mmddOf( s ), endMMDD: mmddOf( e ) };
	}

	// ---- повний перерахунок (графік + таблиця + панель) ----
	function recalc() {
		if ( ! state.data ) { return; }
		drawChart();
		recalcPanel();
	}

	function drawChart() {
		var s = series();
		var ys = yearsOf( s );
		var datasets = [];
		for ( var i = 0; i < ys.length; i++ ) {
			datasets.push( {
				label: ys[ i ],
				data: C.cumulative( s[ ys[ i ] ], MMDD ),
				borderColor: COLORS[ i % COLORS.length ],
				backgroundColor: 'transparent',
				borderWidth: ( ys[ i ] === curYear() ) ? 3 : 1.5,
				pointRadius: 0,
				tension: 0
			} );
		}
		if ( state.chart ) { state.chart.destroy(); }
		state.chart = new Chart( document.getElementById( 'ole-fc-canvas' ), {
			type: 'line',
			data: { labels: MMDD, datasets: datasets },
			options: {
				animation: false,
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				scales: { x: { ticks: { maxTicksLimit: 12 } }, y: { beginAtZero: true } }
			},
			plugins: [ slicePlugin ]
		} );
	}

	// ---- перерахунок панелі + таблиці (без перемальовування кривих) ----
	function recalcPanel() {
		if ( ! state.data ) { return; }
		var p = period();
		renderTotals( p );
		// Підсвітка відрізка на графіку; перехід через Новий рік — двома сегментами.
		if ( p ) {
			var si = MMDD.indexOf( p.startMMDD );
			var ei = MMDD.indexOf( p.endMMDD );
			state.highlight = ( ei < si ) ? [ [ si, 364 ], [ 0, ei ] ] : [ [ si, ei ] ];
		} else {
			state.highlight = null;
		}
		if ( state.chart ) { state.chart.update( 'none' ); }
		var $out = $( '.ole-fc-result' );
		if ( ! p || ! state.refYear ) { $out.attr( 'hidden', true ); return; }

		var s = series();
		var auto = C.autoCoefficient( s, curYear(), state.refYear, mmddOf( todayYMD() ) );
		if ( state.coefAuto ) { $( '.ole-fc-coef' ).val( Math.round( auto.value * 100 ) / 100 ); }
		var coef = parseFloat( $( '.ole-fc-coef' ).val() );
		if ( isNaN( coef ) || coef < 0 ) { coef = auto.value; }
		if ( ! $( '.ole-fc-margin' ).val() ) { $( '.ole-fc-margin' ).val( ORDELIST_FC.margin ); }
		var margin = parseInt( $( '.ole-fc-margin' ).val(), 10 ) || 0;

		var refSlice = C.rangeSum( s[ state.refYear ], p.startMMDD, p.endMMDD );
		var fc = C.forecast( refSlice, coef, margin );
		var weights = weightsMap();
		var stock = C.stockTotal( state.data.batches, state.target, state.unit, weights );
		var buy = C.recommendation( fc, stock );
		var expiring = C.expiringBy( state.data.batches, state.target, state.unit, weights, p.endYMD );

		$out.empty().removeAttr( 'hidden' );
		row( $out, ORDELIST_FC.i18n.forecastL, fmt( fc, state.unit ) );
		row( $out, ORDELIST_FC.i18n.stockL, fmt( stock, state.unit ) );
		if ( 0 === state.data.batches.length ) { note( $out, ORDELIST_FC.i18n.noBatches ); }
		if ( auto.refZero && state.coefAuto ) { note( $out, ORDELIST_FC.i18n.refZero ); }
		var $buy = row( $out, ORDELIST_FC.i18n.buyL, fmt( buy, state.unit ) );
		$buy.addClass( 'ole-fc-buy' );
		if ( expiring > 0 ) { note( $out, ORDELIST_FC.i18n.expiring.replace( '%s', fmt( expiring, state.unit ) ) ); }
		// У кг-режимі позначаємо варіації без ваги — вони рахуються лише в штуках.
		if ( 'kg' === state.unit ) {
			for ( var wi = 0; wi < state.data.variations.length; wi++ ) {
				var wv = state.data.variations[ wi ];
				if ( null === wv.weight_kg && ( 'product' === state.target.type || wv.id === state.target.id ) ) {
					note( $out, wv.name + ' — ' + ORDELIST_FC.i18n.noWeight );
				}
			}
		}

		// Розбивка по варіаціях у штуках (лише в кг-режимі всього препарату).
		if ( 'product' === state.target.type && 'kg' === state.unit && buy > 0 ) {
			var split = C.variationSplit( buy, state.data.variations, state.refYear, p.startMMDD, p.endMMDD );
			for ( var i = 0; i < split.length; i++ ) {
				if ( split[ i ].pcs > 0 ) {
					row( $out, nameOf( split[ i ].id ), split[ i ].pcs + ' ' + ORDELIST_FC.i18n.pcs ).addClass( 'ole-fc-split' );
				}
			}
		}
	}

	function nameOf( vid ) {
		for ( var i = 0; i < state.data.variations.length; i++ ) {
			if ( state.data.variations[ i ].id === vid ) { return state.data.variations[ i ].name; }
		}
		return '#' + vid;
	}

	function row( $box, label, value ) {
		var $r = $( '<div class="ole-fc-row"/>' );
		$( '<span class="ole-fc-label"/>' ).text( label ).appendTo( $r );
		$( '<span class="ole-fc-value"/>' ).text( value ).appendTo( $r );
		return $r.appendTo( $box );
	}
	function note( $box, text ) {
		$( '<div class="ole-fc-note"/>' ).text( text ).appendTo( $box );
	}

	// Таблиця «продано за відрізок»: рік | кг | шт (кг лише якщо є ваги).
	function renderTotals( p ) {
		var $head = $( '.ole-fc-totals thead' ).empty();
		var $body = $( '.ole-fc-totals tbody' ).empty();
		if ( ! p ) { return; }
		var kgS = C.unitSeries( state.data.variations, state.target, 'kg' );
		var pcsS = C.unitSeries( state.data.variations, state.target, 'pcs' );
		var ys = yearsOf( pcsS );
		var $tr = $( '<tr/>' );
		$( '<th/>' ).text( ORDELIST_FC.i18n.year ).appendTo( $tr );
		$( '<th/>' ).text( ORDELIST_FC.i18n.kg ).appendTo( $tr );
		$( '<th/>' ).text( ORDELIST_FC.i18n.pcs ).appendTo( $tr );
		$head.append( $tr );
		for ( var i = 0; i < ys.length; i++ ) {
			var $r = $( '<tr/>' );
			$( '<td/>' ).text( ys[ i ] ).appendTo( $r );
			$( '<td/>' ).text( Math.round( C.rangeSum( kgS[ ys[ i ] ], p.startMMDD, p.endMMDD ) * 10 ) / 10 ).appendTo( $r );
			$( '<td/>' ).text( C.rangeSum( pcsS[ ys[ i ] ], p.startMMDD, p.endMMDD ) ).appendTo( $r );
			$body.append( $r );
		}
	}

	// ---- старт: місяць уперед за замовчуванням ----
	$( function () {
		$( '.ole-fc-start' ).val( todayYMD() );
		$( '.ole-fc-end' ).val( addDays( todayYMD(), 30 ) );
		$( '.ole-fc-margin' ).val( ORDELIST_FC.margin );
	} );
} )( jQuery );
