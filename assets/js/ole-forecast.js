/* global ORDELIST_FC, OrdelistForecastCalc, Chart, jQuery */
( function ( $ ) {
	'use strict';
	if ( 'undefined' === typeof ORDELIST_FC || 'undefined' === typeof OrdelistForecastCalc ) { return; }

	var C = OrdelistForecastCalc;
	var MMDD = C.mmddList();
	var COLORS = [ '#2271b1', '#d63638', '#00a32a', '#b26a00', '#8c5e58', '#3c434a', '#7f54b3' ];
	// Короткі назви місяців мовою адмінки - для підписів осі X.
	var MONTHS = ( function () {
		var lang = document.documentElement.lang || 'en';
		var out = [];
		for ( var m = 0; m < 12; m++ ) {
			try { out.push( new Date( Date.UTC( 2000, m, 1 ) ).toLocaleDateString( lang, { month: 'short', timeZone: 'UTC' } ) ); }
			catch ( e ) { out.push( String( m + 1 ) ); }
		}
		return out;
	} )();

	var state = {
		data: null,          // payload з сервера
		target: null,        // {type:'product'} | {type:'variation', id}
		unit: 'kg',
		refYear: null,
		coefAuto: true,
		chart: null,
		highlight: null      // [[startIdx,endIdx],…] - підсвітка відрізка на графіку
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
	// Поточний коефіцієнт: авто (поле оновлюється) або ручне значення з поля.
	function coefValue( s ) {
		var auto = C.autoCoefficient( s, curYear(), state.refYear, mmddOf( todayYMD() ) );
		if ( state.coefAuto ) { $( '.ole-fc-coef' ).val( Math.round( auto.value * 100 ) / 100 ); }
		var coef = parseFloat( $( '.ole-fc-coef' ).val() );
		if ( isNaN( coef ) || coef < 0 ) { coef = auto.value; }
		return { coef: coef, auto: auto };
	}
	// Оновлює дані прогнозного датасету (створює його drawChart).
	function refreshProjection( s, coef ) {
		if ( ! state.chart ) { return; }
		var ds = state.chart.data.datasets;
		for ( var i = 0; i < ds.length; i++ ) {
			if ( ds[ i ].oleProjection ) {
				ds[ i ].data = C.projection( s[ curYear() ], s[ state.refYear ], mmddOf( todayYMD() ), coef, MMDD );
			}
		}
	}
	// Ціль для збережених налаштувань: варіація або сам товар.
	function targetId() {
		return ( 'variation' === state.target.type ) ? state.target.id : state.data.product_id;
	}
	// Підтягує збережені коефіцієнт+резерв для пари ціль+опорний рік (якщо є).
	function applyTuning() {
		if ( ! state.data || ! state.refYear ) { return; }
		var t = state.data.tuning && state.data.tuning[ targetId() + ':' + state.refYear ];
		if ( ! t ) { return; }
		state.coefAuto = false;
		$( '.ole-fc-coef' ).val( t.coef );
		$( '.ole-fc-margin' ).val( t.margin );
	}
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
		// Поки дані в дорозі: все нижче ховаємо, крутиться лише спінер біля вибору товару.
		$( '.ole-fc-needs-product' ).attr( 'hidden', true );
		$( '.ole-fc-result' ).attr( 'hidden', true );
		$( '.ole-fc-loader' ).removeAttr( 'hidden' );
		post( { product: picked } ).done( function ( r ) {
			var d = r && r.data;
			if ( ! d ) { return; }
			// Показуємо блоки ПЕРЕД малюванням: canvas у hidden-контейнері має нульовий розмір.
			$( '.ole-fc-needs-product' ).removeAttr( 'hidden' );
			state.data = d;
			// Вибрали варіацію - режим варіації; вибрали товар - увесь препарат.
			state.target = ( picked !== d.product_id ) ? { type: 'variation', id: picked } : { type: 'product' };
			// Одиницю визначаємо за ЦІЛЛЮ: у режимі варіації - за вагою саме цієї варіації;
			// у режимі товару - чи є вага хоч у якоїсь варіації.
			var hasWeight;
			if ( 'variation' === state.target.type ) {
				hasWeight = d.variations.some( function ( v ) { return v.id === state.target.id && null !== v.weight_kg; } );
			} else {
				hasWeight = d.variations.some( function ( v ) { return null !== v.weight_kg; } );
			}
			state.unit = hasWeight ? $( 'input[name="ole-fc-unit"]:checked' ).val() : 'pcs';
			if ( ! hasWeight ) { $( 'input[name="ole-fc-unit"][value="pcs"]' ).prop( 'checked', true ); }
			fillRefYears();
			state.coefAuto = true;
			applyTuning();
			// Жодного року з продажами: recalcPanel() сховав би примітку - малюємо
			// порожній графік і таблицю-шапку та показуємо примітку замість панелі.
			if ( ! state.refYear ) {
				drawChart();
				renderTotals( period() );
				$( '.ole-fc-result' ).empty().removeAttr( 'hidden' );
				$( '<div class="ole-fc-note"/>' ).text( ORDELIST_FC.i18n.noSales ).appendTo( '.ole-fc-result' );
			} else {
				recalc();
			}
		} ).fail( function () { window.alert( ORDELIST_FC.i18n.error ); } )
			.always( function () { $( '.ole-fc-loader' ).attr( 'hidden', true ); } );
	} );

	function fillRefYears() {
		// роки - з штучного ряду: він завжди повний, кг-ряд може бути порожній без ваги.
		var ys = yearsOf( C.unitSeries( state.data.variations, state.target, 'pcs' ) );
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
	$( document ).on( 'change', 'input[name="ole-fc-unit"]', function () { state.unit = this.value; state.coefAuto = true; applyTuning(); recalc(); } );
	$( document ).on( 'change', '.ole-fc-ref', function () { state.refYear = $( this ).val(); state.coefAuto = true; applyTuning(); recalc(); } );
	$( document ).on( 'click', '.ole-fc-save-tuning', function () {
		if ( ! state.data || ! state.refYear ) { return; }
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		post( {
			action: 'ordelist_fc_save_tuning',
			target: targetId(),
			year:   state.refYear,
			coef:   parseFloat( $( '.ole-fc-coef' ).val() ) || 0,
			margin: parseInt( $( '.ole-fc-margin' ).val(), 10 ) || 0
		} ).done( function ( r ) {
			if ( r && r.success ) {
				state.data.tuning = state.data.tuning || {};
				state.data.tuning[ targetId() + ':' + state.refYear ] = {
					coef:   parseFloat( $( '.ole-fc-coef' ).val() ) || 0,
					margin: parseInt( $( '.ole-fc-margin' ).val(), 10 ) || 0
				};
				$btn.text( ORDELIST_FC.i18n.saved );
				setTimeout( function () { $btn.text( ORDELIST_FC.i18n.save ); }, 1500 );
			} else {
				window.alert( ORDELIST_FC.i18n.error );
			}
		} ).fail( function () { window.alert( ORDELIST_FC.i18n.error ); } )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );
	$( document ).on( 'input', '.ole-fc-coef', function () { state.coefAuto = false; recalcPanel(); } );
	$( document ).on( 'click', '.ole-fc-coef-auto', function () { state.coefAuto = true; recalcPanel(); } );
	$( document ).on( 'input', '.ole-fc-margin', recalcPanel );
	$( document ).on( 'change', '.ole-fc-start, .ole-fc-end', recalcPanel );
	// Пресети якоряться на КІНЦЕВІЙ даті (за замовчуванням - сьогодні): назад на N днів
	// або до 1 січня того ж року ("з початку року").
	$( document ).on( 'click', '.ole-fc-preset', function () {
		var end = $( '.ole-fc-end' ).val() || todayYMD();
		$( '.ole-fc-end' ).val( end ).trigger( 'ole-sync' );
		var start = $( this ).data( 'ytd' )
			? end.slice( 0, 4 ) + '-01-01'
			: addDays( end, -( parseInt( $( this ).data( 'days' ), 10 ) || 30 ) );
		$( '.ole-fc-start' ).val( start ).trigger( 'ole-sync' );
		recalcPanel();
	} );

	function period() {
		var s = $( '.ole-fc-start' ).val();
		var e = $( '.ole-fc-end' ).val();
		if ( ! s || ! e ) { return null; }
		// Модель порівнює календарний відрізок МІЖ роками, тому довше за 365 днів
		// не представиться: обрізаємо і чесно кажемо про це приміткою.
		var capped = false;
		var maxEnd = addDays( s, 364 );
		if ( e > maxEnd ) {
			e      = maxEnd;
			capped = true;
		}
		return { startYMD: s, endYMD: e, startMMDD: mmddOf( s ), endMMDD: mmddOf( e ), capped: capped };
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
		var todayIdx = MMDD.indexOf( mmddOf( todayYMD() ) );
		var curColor = null;
		var datasets = [];
		for ( var i = 0; i < ys.length; i++ ) {
			var cum = C.cumulative( s[ ys[ i ] ], MMDD );
			var isCur = ( ys[ i ] === curYear() );
			if ( isCur ) {
				curColor = COLORS[ i % COLORS.length ];
				// після сьогодні даних нема - далі малює пунктирний датасет прогнозу
				for ( var j = todayIdx + 1; j < cum.length; j++ ) { cum[ j ] = null; }
			}
			datasets.push( {
				label: ys[ i ],
				data: cum,
				borderColor: COLORS[ i % COLORS.length ],
				backgroundColor: 'transparent',
				borderWidth: isCur ? 3 : 1.5,
				pointRadius: 0,
				tension: 0
			} );
		}
		if ( null !== curColor && state.refYear ) {
			datasets.push( {
				label: curYear() + ' - ' + ORDELIST_FC.i18n.projection,
				oleProjection: true,
				data: [],
				borderColor: curColor,
				backgroundColor: 'transparent',
				borderDash: [ 6, 4 ],
				borderWidth: 1.5,
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
				plugins: { legend: { labels: { filter: function ( item, data ) {
					return ! data.datasets[ item.datasetIndex ].oleProjection;
				} } } },
				scales: {
					x: { ticks: {
						autoSkip: false,
						maxRotation: 0,
						callback: function ( value, index ) {
							var mmdd = MMDD[ index ];
							if ( ! mmdd || '-01' !== mmdd.slice( 2 ) ) { return null; }
							return MONTHS[ parseInt( mmdd.slice( 0, 2 ), 10 ) - 1 ];
						}
					} },
					y: { beginAtZero: true }
				}
			},
			plugins: [ slicePlugin ]
		} );
	}

	// ---- перерахунок панелі + таблиці (без перемальовування кривих) ----
	function recalcPanel() {
		if ( ! state.data ) { return; }
		var p = period();
		renderTotals( p );
		renderVariations( p );
		// Підсвітка відрізка на графіку; перехід через Новий рік - двома сегментами.
		if ( p ) {
			var si = MMDD.indexOf( p.startMMDD );
			var ei = MMDD.indexOf( p.endMMDD );
			state.highlight = ( ei < si ) ? [ [ si, 364 ], [ 0, ei ] ] : [ [ si, ei ] ];
		} else {
			state.highlight = null;
		}
		var s = series();
		var ci = state.refYear ? coefValue( s ) : null;
		if ( ci ) { refreshProjection( s, ci.coef ); }
		if ( state.chart ) { state.chart.update( 'none' ); }
		var $out = $( '.ole-fc-result' );
		if ( ! p || ! state.refYear ) { $out.attr( 'hidden', true ); return; }

		var coef = ci.coef;
		var auto = ci.auto;
		if ( ! $( '.ole-fc-margin' ).val() ) { $( '.ole-fc-margin' ).val( ORDELIST_FC.margin ); }
		var margin = Math.max( 0, Math.min( 100, parseInt( $( '.ole-fc-margin' ).val(), 10 ) || 0 ) );

		// Два стабільні прогнози ТІЛЬКИ з даних опорного року (факт їх не змінює):
		// на весь обраний період і до кінця поточного року.
		var fcPeriod = C.forecast( C.rangeSum( s[ state.refYear ], p.startMMDD, p.endMMDD ), coef, margin );
		var fcYear   = C.forecast( C.rangeSum( s[ state.refYear ], mmddOf( todayYMD() ), '12-31' ), coef, margin );
		var weights = weightsMap();
		// Придатні й прострочені партії - окремо: прострочене видно, але не продається.
		var validBatches   = [];
		var expiredBatches = [];
		for ( var bi = 0; bi < state.data.batches.length; bi++ ) {
			( state.data.batches[ bi ].expired ? expiredBatches : validBatches ).push( state.data.batches[ bi ] );
		}
		var stock        = C.stockTotal( validBatches, state.target, state.unit, weights );
		var expiredStock = C.stockTotal( expiredBatches, state.target, state.unit, weights );
		// Партії для цілі не ведуться - фолбек на склад WooCommerce (без даних про строки).
		var targetHasBatches = state.data.batches.some( function ( b ) {
			return 'product' === state.target.type || b.variation_id === state.target.id;
		} );
		var wcStock = null;
		if ( ! targetHasBatches ) {
			var wcSum = 0;
			var wcAny = false;
			for ( var vi = 0; vi < state.data.variations.length; vi++ ) {
				var vv = state.data.variations[ vi ];
				if ( 'variation' === state.target.type && vv.id !== state.target.id ) { continue; }
				if ( null === vv.wc_stock || undefined === vv.wc_stock ) { continue; }
				if ( 'kg' === state.unit ) {
					if ( null === vv.weight_kg || undefined === vv.weight_kg ) { continue; }
					wcSum += vv.wc_stock * vv.weight_kg;
				} else {
					wcSum += vv.wc_stock;
				}
				wcAny = true;
			}
			if ( wcAny ) { wcStock = wcSum; }
		}
		var effStock = ( null !== wcStock ) ? wcStock : stock;
		// Рекомендація - ДО КІНЦЯ РОКУ: скільки з наявного реально встигне продатись
		// (партія покриває лише попит до свого строку), решту треба докупити.
		var yearEnd  = curYear() + '-12-31';
		var demandTo = function ( expiryYMD ) {
			var capped = ( expiryYMD > yearEnd ) ? yearEnd : expiryYMD;
			if ( capped <= todayYMD() ) { return 0; }
			return C.forecast( C.rangeSum( s[ state.refYear ], mmddOf( todayYMD() ), mmddOf( capped ) ), coef, margin );
		};
		var unitBatches = [];
		for ( var ubi = 0; ubi < validBatches.length; ubi++ ) {
			var vb = validBatches[ ubi ];
			if ( 'variation' === state.target.type && vb.variation_id !== state.target.id ) { continue; }
			var uq = vb.qty;
			if ( 'kg' === state.unit ) {
				var uw = weights[ vb.variation_id ];
				if ( null === uw || undefined === uw ) { continue; }
				uq = vb.qty * uw;
			}
			unitBatches.push( { qty: uq, expiry: vb.expiry } );
		}
		unitBatches.sort( function ( a, b ) { return a.expiry < b.expiry ? -1 : ( a.expiry > b.expiry ? 1 : 0 ); } );
		var usable = ( null !== wcStock ) ? Math.min( wcStock, fcYear ) : C.usableStock( unitBatches, demandTo );
		var buy    = Math.max( 0, fcYear - usable );

		$out.empty().removeAttr( 'hidden' );
		// Факт поточного року за той самий відрізок - поруч із прогнозом, щоб було
		// видно, наскільки прогноз схибив, і підкрутити коефіцієнт/резерв.
		row( $out, ORDELIST_FC.i18n.soldPeriodL, fmt( C.rangeSum( s[ curYear() ], p.startMMDD, p.endMMDD ), state.unit ) );
		row( $out, ORDELIST_FC.i18n.forecastPeriodL, fmt( fcPeriod, state.unit ) );
		row( $out, ORDELIST_FC.i18n.forecastYearL, fmt( fcYear, state.unit ) );
		row( $out, ORDELIST_FC.i18n.stockL, fmt( effStock, state.unit ) );
		if ( expiredStock > 0 ) { row( $out, ORDELIST_FC.i18n.expiredL, fmt( expiredStock, state.unit ) ).addClass( 'ole-fc-expired' ); }
		if ( null !== wcStock ) { note( $out, ORDELIST_FC.i18n.wcStockNote ); }
		else if ( ! targetHasBatches ) { note( $out, ORDELIST_FC.i18n.noBatches ); }
		if ( auto.refZero && state.coefAuto ) { note( $out, ORDELIST_FC.i18n.refZero ); }
		// У штуках округлюємо ВГОРУ - округлення вниз недозамовляє.
		var buyShow = ( 'pcs' === state.unit ) ? Math.ceil( buy ) : buy;
		var $buy = row( $out, ORDELIST_FC.i18n.buyL, fmt( buyShow, state.unit ) );
		$buy.addClass( 'ole-fc-buy' );
		if ( buy > 0 && null === wcStock && effStock - usable > 0.005 ) { note( $out, ORDELIST_FC.i18n.shortExpiry ); }
		if ( p.capped ) { note( $out, ORDELIST_FC.i18n.periodCapped ); }
		// У кг-режимі позначаємо варіації без ваги - вони рахуються лише в штуках.
		// Кілька таких - згортаємо в один рядок, що розкривається (список імен усередині).
		if ( 'kg' === state.unit ) {
			var noW = [];
			for ( var wi = 0; wi < state.data.variations.length; wi++ ) {
				var wv = state.data.variations[ wi ];
				if ( null === wv.weight_kg && ( 'product' === state.target.type || wv.id === state.target.id ) ) {
					noW.push( wv.name );
				}
			}
			if ( 1 === noW.length ) {
				note( $out, noW[ 0 ] + ' - ' + ORDELIST_FC.i18n.noWeight );
			} else if ( noW.length > 1 ) {
				noteDetails( $out, ORDELIST_FC.i18n.noWeightMany.replace( '%s', noW.length ), noW.join( ', ' ) );
			}
		}

		// Розбивка по варіаціях у штуках (лише в кг-режимі всього препарату).
		if ( 'product' === state.target.type && 'kg' === state.unit && buy > 0 ) {
			// Частки варіацій - за опорним роком на тому самому горизонті, що й рекомендація.
			var split = C.variationSplit( buy, state.data.variations, state.refYear, mmddOf( todayYMD() ), '12-31' );
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
	// Згорнута примітка: підсумок видно завжди, деталі - по кліку.
	function noteDetails( $box, summary, detail ) {
		var $d = $( '<details class="ole-fc-note"/>' );
		$( '<summary/>' ).text( summary ).appendTo( $d );
		$( '<div/>' ).text( detail ).appendTo( $d );
		$d.appendTo( $box );
	}

	// Таблиця "наличност і продажби по варіантах" + швидкий запис партії.
	function renderVariations( p ) {
		var $head = $( '.ole-fc-vars thead' ).empty();
		var $body = $( '.ole-fc-vars tbody' ).empty();
		if ( ! p || ! state.data || ! state.refYear ) { return; }
		var year = state.refYear;
		var $tr  = $( '<tr/>' );
		$( '<th/>' ).text( ORDELIST_FC.i18n.varL ).appendTo( $tr );
		$( '<th/>' ).text( ORDELIST_FC.i18n.soldIn.replace( '%s', year ) + ', ' + ORDELIST_FC.i18n.pcs ).appendTo( $tr );
		$( '<th/>' ).text( ORDELIST_FC.i18n.soldIn.replace( '%s', year ) + ', ' + ORDELIST_FC.i18n.kg ).appendTo( $tr );
		$( '<th/>' ).text( ORDELIST_FC.i18n.stockCol + ', ' + ORDELIST_FC.i18n.pcs ).appendTo( $tr );
		$( '<th/>' ).text( ORDELIST_FC.i18n.add ).appendTo( $tr );
		$head.append( $tr );
		for ( var i = 0; i < state.data.variations.length; i++ ) {
			var v = state.data.variations[ i ];
			if ( 'variation' === state.target.type && v.id !== state.target.id ) { continue; }
			var pcs = C.rangeSum( C.foldFeb29( ( v.series && v.series[ year ] ) || {} ), p.startMMDD, p.endMMDD );
			var kg  = ( null === v.weight_kg || undefined === v.weight_kg ) ? null : Math.round( pcs * v.weight_kg * 10 ) / 10;
			var stock = 0;
			for ( var b = 0; b < state.data.batches.length; b++ ) {
				if ( state.data.batches[ b ].variation_id === v.id && ! state.data.batches[ b ].expired ) { stock += state.data.batches[ b ].qty; }
			}
			// Без жодної партії в товару - показуємо склад WooCommerce (як і панель вище).
			if ( 0 === state.data.batches.length && null !== v.wc_stock && undefined !== v.wc_stock ) { stock = v.wc_stock; }
			var $r = $( '<tr/>' );
			$( '<td/>' ).text( v.name ).appendTo( $r );
			$( '<td/>' ).text( pcs ).appendTo( $r );
			$( '<td/>' ).text( null === kg ? '' : kg ).appendTo( $r );
			$( '<td/>' ).text( stock ).appendTo( $r );
			var $cell = $( '<td/>' );
			if ( v.exists ) {
				// Швидкий запис: кількість + необов'язкова дата придатності.
				$( '<input type="number" min="1" step="1" class="ole-fc-add-qty"/>' ).appendTo( $cell );
				$cell.append( ' ' );
				$( '<input type="hidden" class="ole-fc-add-exp ole-date"/>' ).attr( 'title', ORDELIST_FC.i18n.goodUntil ).appendTo( $cell );
				$cell.append( ' ' );
				$( '<button type="button" class="button ole-fc-add-batch"/>' )
					.text( ORDELIST_FC.i18n.add )
					.attr( 'data-target', v.id > 0 ? v.id : state.data.product_id )
					.appendTo( $cell );
			}
			$cell.appendTo( $r );
			$body.append( $r );
		}
		if ( window.ordelistDates ) { window.ordelistDates.init( $body[ 0 ] ); }
	}

	$( document ).on( 'click', '.ole-fc-add-batch', function () {
		var $btn = $( this );
		var $row = $btn.closest( 'tr' );
		var qty  = parseInt( $row.find( '.ole-fc-add-qty' ).val(), 10 ) || 0;
		var exp  = $row.find( '.ole-fc-add-exp' ).val() || '';
		if ( qty <= 0 ) { return; }
		$btn.prop( 'disabled', true );
		post( { action: 'ordelist_fc_add_batch', target: $btn.data( 'target' ), qty: qty, expiry: exp } )
			.done( function ( r ) {
				if ( r && r.success ) {
					// Один источник на истина: презареждаме целия payload.
					$( '.ole-fc-product' ).trigger( 'change' );
				} else {
					window.alert( ORDELIST_FC.i18n.error );
				}
			} )
			.fail( function () { window.alert( ORDELIST_FC.i18n.error ); } )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );

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
			var kgSum  = Math.round( C.rangeSum( kgS[ ys[ i ] ], p.startMMDD, p.endMMDD ) * 10 ) / 10;
			var pcsSum = C.rangeSum( pcsS[ ys[ i ] ], p.startMMDD, p.endMMDD );
			if ( 0 === kgSum && 0 === pcsSum ) { continue; } // нема продажів у відрізку - рядок лише шумить
			var $r = $( '<tr/>' );
			$( '<td/>' ).text( ys[ i ] ).appendTo( $r );
			$( '<td/>' ).text( kgSum ).appendTo( $r );
			$( '<td/>' ).text( pcsSum ).appendTo( $r );
			$body.append( $r );
		}
	}

	// ---- старт: останній місяць до сьогодні за замовчуванням ----
	// (пошук товару ініціалізує спільний модуль ole-product-search.js)
	$( function () {
		$( '.ole-fc-end' ).val( todayYMD() ).trigger( 'ole-sync' );
		$( '.ole-fc-start' ).val( addDays( todayYMD(), -30 ) ).trigger( 'ole-sync' );
		$( '.ole-fc-margin' ).val( ORDELIST_FC.margin );
	} );
} )( jQuery );
