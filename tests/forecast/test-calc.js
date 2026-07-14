// Standalone unit tests for the purchase-forecast math (no browser, no WordPress).
// Run: node tests/forecast/test-calc.js
'use strict';
var C = require( '../../assets/js/ole-forecast-calc.js' );

var fails = 0;
function check( cond, msg ) {
	console.log( ( cond ? 'ok   - ' : 'FAIL - ' ) + msg );
	if ( ! cond ) { fails++; }
}

// ---- mmddList ----
var days = C.mmddList();
check( days.length === 365, 'mmddList has 365 days' );
check( days[ 0 ] === '01-01' && days[ 364 ] === '12-31', 'mmddList spans 01-01..12-31' );
check( days.indexOf( '02-28' ) !== -1 && days.indexOf( '02-29' ) === -1, 'no Feb 29 in mmddList' );

// ---- foldFeb29 ----
var folded = C.foldFeb29( { '02-28': 2, '02-29': 3, '03-01': 1 } );
check( folded[ '02-28' ] === 5 && folded[ '03-01' ] === 1 && ! ( '02-29' in folded ), 'Feb 29 folds into Feb 28' );

// ---- rangeSum ----
var ys = { '01-05': 2, '03-15': 4, '12-25': 6 };
check( C.rangeSum( ys, '01-01', '03-31' ) === 6, 'rangeSum sums inside range' );
check( C.rangeSum( ys, '03-15', '03-15' ) === 4, 'rangeSum boundaries inclusive' );
check( C.rangeSum( ys, '12-20', '01-10' ) === 8, 'wrapped range sums both same-year segments' );
check( C.rangeSum( null, '01-01', '12-31' ) === 0, 'missing year -> 0' );

// ---- unitSeries ----
var variations = [
	{ id: 10, weight_kg: 0.5,  series: { '2025': { '01-02': 4 } } },          // 4 шт = 2 кг
	{ id: 11, weight_kg: null, series: { '2025': { '01-02': 7 } } },          // без ваги
	{ id: 12, weight_kg: 1,    series: { '2025': { '02-29': 2, '01-02': 1 } } } // з 29 лютого
];
var kg = C.unitSeries( variations, { type: 'product' }, 'kg' );
check( kg[ '2025' ][ '01-02' ] === 3, 'product kg sums weighted variations, skips null weight (2+1)' );
check( kg[ '2025' ][ '02-28' ] === 2, 'unitSeries folds Feb 29' );
var pcs = C.unitSeries( variations, { type: 'product' }, 'pcs' );
check( pcs[ '2025' ][ '01-02' ] === 12, 'product pcs counts all variations (4+7+1)' );
var one = C.unitSeries( variations, { type: 'variation', id: 11 }, 'pcs' );
check( one[ '2025' ][ '01-02' ] === 7, 'variation mode filters to one id' );

// ---- cumulative ----
var cum = C.cumulative( { '01-01': 1, '12-31': 2 } );
check( cum[ 0 ] === 1 && cum[ 363 ] === 1 && cum[ 364 ] === 3, 'cumulative accumulates across the year' );

// ---- autoCoefficient ----
var series = { '2025': { '01-10': 10 }, '2026': { '01-10': 5 } };
var co = C.autoCoefficient( series, '2026', '2025', '06-01' );
check( Math.abs( co.value - 0.5 ) < 1e-9 && co.refZero === false, 'auto coefficient = current YTD / ref YTD' );
var co0 = C.autoCoefficient( series, '2026', '2020', '06-01' );
check( co0.value === 1 && co0.refZero === true, 'zero reference slice -> coefficient 1 + flag' );

// ---- forecast / recommendation ----
check( Math.abs( C.forecast( 100, 0.5, 20 ) - 60 ) < 1e-9, 'forecast = slice * coef * (1+margin%)' );
check( C.recommendation( 60, 45.5 ) === 14.5, 'recommendation subtracts stock' );
check( C.recommendation( 10, 25 ) === 0, 'recommendation clamps at 0' );

// ---- stockTotal / expiringBy ----
var weights = { 10: 0.5, 11: null, 12: 1 };
var batches = [
	{ variation_id: 10, expiry: '2026-08-01', qty: 4 },  // 2 кг
	{ variation_id: 11, expiry: '2026-08-01', qty: 3 },  // без ваги
	{ variation_id: 12, expiry: '2026-09-15', qty: 1 },  // 1 кг
	{ variation_id: 12, expiry: '2026-09-15', qty: 0 }   // порожня
];
check( C.stockTotal( batches, { type: 'product' }, 'kg', weights ) === 3, 'stock kg skips null weight + qty<=0' );
check( C.stockTotal( batches, { type: 'product' }, 'pcs', weights ) === 8, 'stock pcs counts everything positive' );
check( C.stockTotal( batches, { type: 'variation', id: 12 }, 'pcs', weights ) === 1, 'stock filters by variation' );
check( C.expiringBy( batches, { type: 'product' }, 'kg', weights, '2026-08-01' ) === 2, 'expiringBy includes expiry == end' );

// ---- variationSplit ----
var vs = [
	{ id: 10, weight_kg: 0.5,  series: { '2025': { '02-01': 8 } } },  // 4 кг ref
	{ id: 11, weight_kg: null, series: { '2025': { '02-01': 9 } } },  // без ваги -> 0
	{ id: 12, weight_kg: 1,    series: { '2025': { '02-01': 4 } } }   // 4 кг ref
];
var split = C.variationSplit( 10, vs, '2025', '01-01', '12-31' );
check( split[ 0 ].id === 10 && split[ 0 ].pcs === 10, 'split: 50% share of 10kg at 0.5kg -> 10 pcs' );
check( split[ 1 ].pcs === 0, 'split: null-weight variation gets 0' );
check( split[ 2 ].pcs === 5, 'split: 50% share of 10kg at 1kg -> 5 pcs' );
check( split[ 0 ].pcs === Math.ceil( split[ 0 ].pcs ), 'split rounds up' );
var none = C.variationSplit( 10, vs, '2019', '01-01', '12-31' );
check( none[ 0 ].pcs === 0 && none[ 2 ].pcs === 0, 'zero reference total -> all zeros' );

console.log( fails ? '\n' + fails + ' FAILED' : '\nALL PASS' );
process.exit( fails ? 1 : 0 );
