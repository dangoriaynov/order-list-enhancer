/* Чиста математика прогнозу закупівлі (без DOM/jQuery).
   Тестується локально: node tests/forecast/test-calc.js */
( function ( root ) {
	'use strict';

	var C = {};

	function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }
	function has( o, k ) { return Object.prototype.hasOwnProperty.call( o, k ); }

	// 365 ключів 'MM-DD' (без 29 лютого - воно складається у 28-ме).
	C.mmddList = function () {
		var days = [ 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
		var out = [];
		for ( var m = 0; m < 12; m++ ) {
			for ( var d = 1; d <= days[ m ]; d++ ) { out.push( pad( m + 1 ) + '-' + pad( d ) ); }
		}
		return out;
	};

	// Скласти 29 лютого у 28-ме, щоб роки накладались 1-в-1.
	C.foldFeb29 = function ( yearSeries ) {
		var out = {};
		for ( var k in yearSeries ) {
			if ( ! has( yearSeries, k ) ) { continue; }
			var key = ( '02-29' === k ) ? '02-28' : k;
			out[ key ] = ( out[ key ] || 0 ) + yearSeries[ k ];
		}
		return out;
	};

	// Сума за календарний відрізок [start..end] включно; відрізок через Новий рік
	// (end < start) береться з ТОГО САМОГО року двома шматками (свідоме спрощення).
	C.rangeSum = function ( yearSeries, startMMDD, endMMDD ) {
		if ( ! yearSeries ) { return 0; }
		if ( endMMDD < startMMDD ) {
			return C.rangeSum( yearSeries, startMMDD, '12-31' ) + C.rangeSum( yearSeries, '01-01', endMMDD );
		}
		var sum = 0;
		for ( var k in yearSeries ) {
			if ( has( yearSeries, k ) && k >= startMMDD && k <= endMMDD ) { sum += yearSeries[ k ]; }
		}
		return sum;
	};

	// Об'єднаний ряд цілі у вибраних одиницях: {year: {mmdd: value}}.
	// target: {type:'product'} | {type:'variation', id}; unit: 'kg' | 'pcs'.
	// У 'kg' варіації без ваги пропускаються (вони рахуються лише в штуках).
	C.unitSeries = function ( variations, target, unit ) {
		var out = {};
		for ( var i = 0; i < variations.length; i++ ) {
			var v = variations[ i ];
			if ( 'variation' === target.type && v.id !== target.id ) { continue; }
			var factor = 1;
			if ( 'kg' === unit ) {
				if ( null === v.weight_kg || undefined === v.weight_kg ) { continue; }
				factor = v.weight_kg;
			}
			for ( var year in v.series ) {
				if ( ! has( v.series, year ) ) { continue; }
				var folded = C.foldFeb29( v.series[ year ] );
				out[ year ] = out[ year ] || {};
				for ( var k in folded ) {
					if ( has( folded, k ) ) { out[ year ][ k ] = ( out[ year ][ k ] || 0 ) + folded[ k ] * factor; }
				}
			}
		}
		return out;
	};

	// Кумулятивна крива року по 365 точках (для графіка).
	C.cumulative = function ( yearSeries, mmdd ) {
		mmdd = mmdd || C.mmddList();
		var out = [];
		var acc = 0;
		for ( var i = 0; i < mmdd.length; i++ ) {
			acc += ( yearSeries && yearSeries[ mmdd[ i ] ] ) ? yearSeries[ mmdd[ i ] ] : 0;
			out.push( acc );
		}
		return out;
	};

	// Прогнозна крива поточного року: до сьогодні - null (там малює фактична лінія),
	// від сьогодні - факт + приріст опорного року за той самий відрізок × коефіцієнт.
	C.projection = function ( curSeries, refSeries, todayMMDD, coefficient, mmdd ) {
		mmdd = mmdd || C.mmddList();
		var cumCur = C.cumulative( curSeries, mmdd );
		var cumRef = C.cumulative( refSeries, mmdd );
		var ti = mmdd.indexOf( todayMMDD );
		if ( -1 === ti ) { ti = mmdd.length - 1; }
		var out = [];
		for ( var i = 0; i < mmdd.length; i++ ) {
			out.push( i < ti ? null : cumCur[ ti ] + ( cumRef[ i ] - cumRef[ ti ] ) * coefficient );
		}
		return out;
	};

	// Авто-коефіцієнт: цей рік [01-01..сьогодні] ÷ опорний рік за той самий відрізок.
	C.autoCoefficient = function ( series, currentYear, refYear, todayMMDD ) {
		var cur = C.rangeSum( series[ currentYear ], '01-01', todayMMDD );
		var ref = C.rangeSum( series[ refYear ], '01-01', todayMMDD );
		if ( ref <= 0 ) { return { value: 1, refZero: true }; }
		var value = cur / ref;
		if ( value < 0 ) { value = 0; } // чистий мінус цього року - коефіцієнт 0, прогноз нульовий
		return { value: value, refZero: false };
	};

	C.forecast = function ( refSliceSum, coefficient, marginPct ) {
		return refSliceSum * coefficient * ( 1 + marginPct / 100 );
	};

	// Придатний залишок з партій у вибраних одиницях. weights: {variation_id: kg|null}.
	C.stockTotal = function ( batches, target, unit, weights ) {
		var sum = 0;
		for ( var i = 0; i < batches.length; i++ ) {
			var b = batches[ i ];
			if ( 'variation' === target.type && b.variation_id !== target.id ) { continue; }
			if ( b.qty <= 0 ) { continue; }
			if ( 'kg' === unit ) {
				var w = weights[ b.variation_id ];
				if ( null === w || undefined === w ) { continue; }
				sum += b.qty * w;
			} else {
				sum += b.qty;
			}
		}
		return sum;
	};

	// Частина залишку, що зійде з термінів до endYMD включно (для попередження).
	C.expiringBy = function ( batches, target, unit, weights, endYMD ) {
		var soon = [];
		for ( var i = 0; i < batches.length; i++ ) {
			if ( batches[ i ].expiry <= endYMD ) { soon.push( batches[ i ] ); }
		}
		return C.stockTotal( soon, target, unit, weights );
	};

	C.recommendation = function ( forecastVal, stockVal ) {
		return Math.max( 0, forecastVal - stockVal );
	};

	// Розбивка прогнозу (кг) по варіаціях у штуках за кг-частками опорного року.
	C.variationSplit = function ( forecastKg, variations, refYear, startMMDD, endMMDD ) {
		var shares = [];
		var total = 0;
		var i;
		for ( i = 0; i < variations.length; i++ ) {
			var v = variations[ i ];
			var kg = 0;
			if ( null !== v.weight_kg && undefined !== v.weight_kg ) {
				kg = C.rangeSum( C.foldFeb29( v.series[ refYear ] || {} ), startMMDD, endMMDD ) * v.weight_kg;
			}
			if ( kg < 0 ) { kg = 0; } // повернень більше ніж продажів - частка нульова, не спотворює інших
			shares.push( kg );
			total += kg;
		}
		var out = [];
		for ( i = 0; i < variations.length; i++ ) {
			var pcs = 0;
			if ( total > 0 && shares[ i ] > 0 && variations[ i ].weight_kg > 0 ) {
				pcs = Math.ceil( ( forecastKg * shares[ i ] / total ) / variations[ i ].weight_kg );
			}
			out.push( { id: variations[ i ].id, pcs: pcs } );
		}
		return out;
	};

	if ( 'undefined' !== typeof module && module.exports ) { module.exports = C; }
	else { root.OrdelistForecastCalc = C; }
} )( 'undefined' !== typeof self ? self : this );
