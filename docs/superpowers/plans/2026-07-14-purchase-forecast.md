# Purchase Forecast & Sales Analytics Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An admin page that overlays per-year sales curves for a product/variation, compares any calendar slice across years in kg and pieces, and computes a purchase recommendation (reference-year slice × adjustable coefficient × margin − sellable batch stock).

**Architecture:** Read-only feature mirroring the repo's established shapes: a data class that is the only reader of WooCommerce's `{prefix}wc_order_product_lookup` table (one AJAX payload per product), a pure JS math module tested by node, a page-glue JS driving a locally-bundled Chart.js, and an admin page class + Inventory-tab settings card. No cron, no order hooks, no writes. Spec: `docs/superpowers/specs/2026-07-14-purchase-forecast-design.md`.

**Tech Stack:** WordPress 6.2+ / WooCommerce 8+ plugin, PHP 7.4-compatible, jQuery + bundled Chart.js 4.5.1 (MIT), framework-free tests via local `php` and `node`.

## Global Constraints

- Prefix rules: classes `ORDELIST_Forecast_*`; AJAX `wp_ajax_ordelist_fc_*`; nonce `ordelist_fc`; script/style handles `ordelist-forecast*` / `ordelist-chartjs`; CSS classes `ole-fc-*`; asset filenames `ole-forecast*` (the `ole-` filename convention is deliberate — do not "fix" it).
- Text domain is exactly `order-list-enhancer` in every gettext call.
- SQL: table names only via `%i` inside `$wpdb->prepare()`; the lookup table is read ONLY in `ORDELIST_Forecast_Data` (class-level phpcs:disable like `includes/class-ordelist-warranty-store.php`).
- Every new PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`; PHP test files instead `define( 'ABSPATH', true );`.
- PHP 7.4 syntax only; tabs indentation; source comments in Ukrainian (repo convention); translator comments before gettext calls with placeholders.
- JS: page glue is a jQuery IIFE like `assets/js/ole-warranty.js`; the math module has NO DOM/jQuery and exports via `module.exports` for node.
- Commit after each task, style `feat(forecast): …`. NEVER add Co-Authored-By or any AI-attribution line.
- Do NOT bump the plugin version or touch readme.txt (release pass is separate). Languages files change ONLY in Task 6.
- Run every command from the repo root `/Users/danko/PycharmProjects/order-list-enhancer` (cwd resets between bash calls — cd first each time). No local WordPress: WP-glue verifies via `php -l` + greps; math verifies via node/php tests.

---

### Task 1: Pure JS forecast math + node tests — TDD

**Files:**
- Create: `tests/forecast/test-calc.js`
- Create: `assets/js/ole-forecast-calc.js`

**Interfaces:**
- Consumes: nothing.
- Produces (used by Task 4's page JS as `OrdelistForecastCalc` in the browser and by the node test via `require`):
  - `mmddList(): string[]` — 365 `'MM-DD'` keys, no `'02-29'`.
  - `foldFeb29(yearSeries): object` — `'02-29'` merged into `'02-28'`.
  - `rangeSum(yearSeries, startMMDD, endMMDD): number` — inclusive; a wrapped range (end < start) sums `[start..12-31] + [01-01..end]` of the SAME year (spec's deliberate simplification).
  - `unitSeries(variations, target, unit): object` — `{year: {mmdd: value}}`; `target` is `{type:'product'}` or `{type:'variation', id}`; `unit` `'kg'` (skips `weight_kg === null`, multiplies by weight) or `'pcs'`.
  - `cumulative(yearSeries, mmdd?): number[]` — 365 cumulative values.
  - `autoCoefficient(series, currentYear, refYear, todayMMDD): {value, refZero}` — ref slice 0 → `{value:1, refZero:true}`.
  - `forecast(refSliceSum, coefficient, marginPct): number`.
  - `stockTotal(batches, target, unit, weights): number` — skips `qty<=0`; kg mode skips null-weight variation ids.
  - `expiringBy(batches, target, unit, weights, endYMD): number` — subtotal of batches with `expiry <= endYMD`.
  - `recommendation(forecastVal, stockVal): number` — clamped at 0.
  - `variationSplit(forecastKg, variations, refYear, startMMDD, endMMDD): [{id, pcs}]` — proportional to reference-year kg shares, `Math.ceil` per variation, zero-share/null-weight → 0, zero total → all 0.

- [ ] **Step 1: Write the failing test**

Create `tests/forecast/test-calc.js`:

```js
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/forecast/test-calc.js`
Expected: `Error: Cannot find module '../../assets/js/ole-forecast-calc.js'`, non-zero exit.

- [ ] **Step 3: Write the implementation**

Create `assets/js/ole-forecast-calc.js`:

```js
/* Чиста математика прогнозу закупівлі (без DOM/jQuery).
   Тестується локально: node tests/forecast/test-calc.js */
( function ( root ) {
	'use strict';

	var C = {};

	function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }
	function has( o, k ) { return Object.prototype.hasOwnProperty.call( o, k ); }

	// 365 ключів 'MM-DD' (без 29 лютого — воно складається у 28-ме).
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

	// Авто-коефіцієнт: цей рік [01-01..сьогодні] ÷ опорний рік за той самий відрізок.
	C.autoCoefficient = function ( series, currentYear, refYear, todayMMDD ) {
		var cur = C.rangeSum( series[ currentYear ], '01-01', todayMMDD );
		var ref = C.rangeSum( series[ refYear ], '01-01', todayMMDD );
		if ( ref <= 0 ) { return { value: 1, refZero: true }; }
		return { value: cur / ref, refZero: false };
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `node tests/forecast/test-calc.js`
Expected: every line `ok   - …`, final `ALL PASS`, exit 0.
Also: `node --check assets/js/ole-forecast-calc.js && echo JS-OK` → `JS-OK`.

- [ ] **Step 5: Commit**

```bash
git add tests/forecast/test-calc.js assets/js/ole-forecast-calc.js
git commit -m "feat(forecast): pure forecast math module with node tests"
```

---

### Task 2: Data layer (lookup-table reader + payload) — TDD on the pure part

**Files:**
- Create: `tests/forecast/test-shape.php`
- Create: `includes/class-ordelist-forecast-data.php`

**Interfaces:**
- Consumes: `ORDELIST_Warranty_Store::batches_for_target( $target_id, $is_variation )` (existing, includes/class-ordelist-warranty-store.php), WooCommerce `wc_get_product()`, `wc_get_weight()`, `current_time()`.
- Produces (used by Task 3):
  - `ORDELIST_Forecast_Data::shape_rows( array $rows ): array` — pure; rows `['variation_id'=>, 'd'=>'YYYY-MM-DD', 'qty'=>]` → `[ variation_id => [ 'YYYY' => [ 'MM-DD' => qty ] ] ]`, skipping malformed dates and `qty <= 0`.
  - `ORDELIST_Forecast_Data::payload( $picked_id ): ?array` — `[ 'product_id'=>int, 'variations'=>[ ['id','name','weight_kg'(float|null),'series'] ], 'batches'=>[ ['variation_id','expiry','qty'] ] ]` or null when the product doesn't exist.

- [ ] **Step 1: Write the failing test**

Create `tests/forecast/test-shape.php`:

```php
<?php
// Standalone unit tests for ORDELIST_Forecast_Data::shape_rows (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ordelist-forecast-data.php';

$fails = 0;
function check( $cond, $msg ) {
	global $fails;
	if ( $cond ) { echo "ok   - $msg\n"; } else { echo "FAIL - $msg\n"; $fails++; }
}

$rows = array(
	array( 'variation_id' => 10, 'd' => '2025-03-15', 'qty' => 2 ),
	array( 'variation_id' => 10, 'd' => '2025-03-15', 'qty' => 3 ),  // той самий день — сумується
	array( 'variation_id' => 10, 'd' => '2026-01-02', 'qty' => 1 ),
	array( 'variation_id' => 0,  'd' => '2025-07-01', 'qty' => 4 ),  // простий товар
	array( 'variation_id' => 11, 'd' => 'garbage',    'qty' => 9 ),  // зіпсована дата — пропуск
	array( 'variation_id' => 11, 'd' => '2025-07-01', 'qty' => 0 ),  // нульова кількість — пропуск
	array( 'variation_id' => 12, 'd' => '2024-02-29', 'qty' => 5 ),  // 29 лютого лишається як є (клієнт складає)
);
$out = ORDELIST_Forecast_Data::shape_rows( $rows );

check( $out[10]['2025']['03-15'] === 5, 'same-day rows summed (2+3)' );
check( $out[10]['2026']['01-02'] === 1, 'years separated' );
check( $out[0]['2025']['07-01'] === 4, 'simple product keyed by variation_id 0' );
check( ! isset( $out[11] ), 'garbage date and zero qty rows skipped entirely' );
check( $out[12]['2024']['02-29'] === 5, 'Feb 29 passes through untouched' );
check( ORDELIST_Forecast_Data::shape_rows( array() ) === array(), 'empty input -> empty array' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/forecast/test-shape.php`
Expected: fatal — `Failed opening required '…/class-ordelist-forecast-data.php'`, non-zero exit.

- [ ] **Step 3: Write the implementation**

Create `includes/class-ordelist-forecast-data.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Дані для сторінки закупівлі: ЄДИНЕ місце читання таблиці wc_order_product_lookup
 * (аналітичний журнал WooCommerce) + збирання payload для клієнта.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only aggregate over WooCommerce's analytics lookup table; no WP API exposes it; admin-side only.
class ORDELIST_Forecast_Data {

	public static function table_lookup() {
		global $wpdb;
		return $wpdb->prefix . 'wc_order_product_lookup';
	}

	/** Сирі агрегати продажів товару: variation_id / d (Y-m-d) / qty. */
	public static function rows_for_product( $product_id ) {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT variation_id, DATE(date_created) d, SUM(product_qty) qty FROM %i WHERE product_id = %d GROUP BY variation_id, DATE(date_created)',
				self::table_lookup(),
				(int) $product_id
			),
			ARRAY_A
		);
	}

	/** Pure: рядки → [ variation_id => [ 'YYYY' => [ 'MM-DD' => qty ] ] ]. Тестується без WP. */
	public static function shape_rows( array $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			$d = (string) ( $r['d'] ?? '' );
			if ( 1 !== preg_match( '/^(\d{4})-(\d{2}-\d{2})$/', $d, $m ) ) {
				continue;
			}
			$vid = (int) ( $r['variation_id'] ?? 0 );
			$qty = (int) ( $r['qty'] ?? 0 );
			if ( $qty <= 0 ) {
				continue;
			}
			$out[ $vid ][ $m[1] ][ $m[2] ] = ( $out[ $vid ][ $m[1] ][ $m[2] ] ?? 0 ) + $qty;
		}
		return $out;
	}

	/** Вага в кг або null (порожнє поле ваги) — конвертація з одиниці ваги магазину. */
	private static function weight_kg( $product ) {
		$w = $product ? $product->get_weight() : '';
		if ( '' === (string) $w ) {
			return null;
		}
		return (float) wc_get_weight( (float) $w, 'kg' );
	}

	/** Повний payload для сторінки; null якщо товар не знайдено. */
	public static function payload( $picked_id ) {
		$p = wc_get_product( (int) $picked_id );
		if ( ! $p ) {
			return null;
		}
		$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
		if ( ! $parent ) {
			return null;
		}

		$shaped = self::shape_rows( self::rows_for_product( (int) $parent->get_id() ) );
		$today  = current_time( 'Y-m-d' );

		$targets = array(); // vid (0 = простий товар) => WC_Product|null
		if ( $parent->is_type( 'variable' ) ) {
			foreach ( $parent->get_children() as $vid ) {
				$targets[ (int) $vid ] = wc_get_product( $vid );
			}
			// Варіації з продажами, але вже видалені — показуємо як '#id'.
			foreach ( array_keys( $shaped ) as $vid ) {
				if ( $vid > 0 && ! isset( $targets[ $vid ] ) ) {
					$targets[ $vid ] = null;
				}
			}
		} else {
			$targets[0] = $parent;
		}

		$variations = array();
		$batches    = array();
		foreach ( $targets as $vid => $prod ) {
			$variations[] = array(
				'id'        => (int) $vid,
				'name'      => $prod ? wp_strip_all_tags( $prod->get_formatted_name() ) : ( '#' . (int) $vid ),
				'weight_kg' => self::weight_kg( $prod ),
				'series'    => isset( $shaped[ $vid ] ) ? $shaped[ $vid ] : array(),
			);
			$target_id = ( $vid > 0 ) ? (int) $vid : (int) $parent->get_id();
			foreach ( ORDELIST_Warranty_Store::batches_for_target( $target_id, $vid > 0 ) as $b ) {
				if ( (int) $b['qty'] > 0 && (string) $b['expiry'] >= $today ) {
					$batches[] = array(
						'variation_id' => (int) $vid,
						'expiry'       => (string) $b['expiry'],
						'qty'          => (int) $b['qty'],
					);
				}
			}
		}

		return array(
			'product_id' => (int) $parent->get_id(),
			'variations' => $variations,
			'batches'    => $batches,
		);
	}
}
```

- [ ] **Step 4: Run tests + audits**

Run: `php tests/forecast/test-shape.php`
Expected: all `ok`, `ALL PASS`, exit 0.
Run: `php -l includes/class-ordelist-forecast-data.php` → clean.
Run: `grep -n '\$wpdb->prepare' includes/class-ordelist-forecast-data.php | grep -v '%i'; echo "exit=$?"` → no lines, `exit=1`.
Run: `grep -rn "wc_order_product_lookup" includes/*.php | grep -v class-ordelist-forecast-data.php; echo "exit=$?"` → no lines, `exit=1`.

- [ ] **Step 5: Commit**

```bash
git add tests/forecast/test-shape.php includes/class-ordelist-forecast-data.php
git commit -m "feat(forecast): lookup-table data layer with pure row shaping"
```

---

### Task 3: Bundled Chart.js + admin page class

**Files:**
- Create: `assets/vendor/chartjs/chart.umd.js` (downloaded, pinned 4.5.1)
- Create: `assets/vendor/chartjs/LICENSE.md` (downloaded)
- Create: `includes/class-ordelist-forecast-admin.php`

**Interfaces:**
- Consumes: `ORDELIST_Forecast_Data::payload()` (Task 2), `ORDELIST_Settings::get()` key `forecast_margin` (Task 5 adds it — this class is unreachable until Task 5 wires the toggle, same forward-dependency pattern the warranty feature used), constants `ORDELIST_URL`, `ORDELIST_VERSION`.
- Produces: `ORDELIST_Forecast_Admin::SLUG = 'ordelist-forecast'`, `::init()`, AJAX `wp_ajax_ordelist_fc_series` (nonce `ordelist_fc`), JS global `ORDELIST_FC` `{ajaxUrl, nonce, today, margin, i18n{…}}`, and the page markup with these hooks for Task 4's JS: `.ole-fc-product`, `input[name="ole-fc-unit"]`, `#ole-fc-canvas`, `.ole-fc-start`, `.ole-fc-end`, `.ole-fc-preset[data-days]`, `.ole-fc-ref`, `.ole-fc-coef`, `.ole-fc-coef-auto`, `.ole-fc-margin`, `.ole-fc-result`, `.ole-fc-totals`.

- [ ] **Step 1: Vendor Chart.js (pinned)**

```bash
mkdir -p assets/vendor/chartjs
curl -fsSL -o assets/vendor/chartjs/chart.umd.js https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.js
curl -fsSL -o assets/vendor/chartjs/LICENSE.md https://cdn.jsdelivr.net/npm/chart.js@4.5.1/LICENSE.md
grep -c "4.5.1" assets/vendor/chartjs/chart.umd.js   # expect >= 1 (embedded version string)
grep -ci "MIT" assets/vendor/chartjs/LICENSE.md       # expect >= 1
node --check assets/vendor/chartjs/chart.umd.js && echo VENDOR-OK
```
Expected: both greps ≥ 1, `VENDOR-OK`. Do not edit the vendored files.

- [ ] **Step 2: Write the admin class**

Create `includes/class-ordelist-forecast-admin.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Сторінка «Планування закупівлі»: графік продажів по роках + рекомендація
 * скільки взяти на період. Уся математика — на клієнті; тут меню, ассети й AJAX.
 */
class ORDELIST_Forecast_Admin {

	const SLUG = 'ordelist-forecast';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_ordelist_fc_series', array( __CLASS__, 'ajax_series' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Purchase planning', 'order-list-enhancer' ),
			__( 'Purchase planning', 'order-list-enhancer' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	private static function is_screen() {
		$s = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $s && false !== strpos( (string) $s->id, self::SLUG );
	}

	public static function assets( $hook ) {
		if ( ! self::is_screen() ) {
			return;
		}
		$o = ORDELIST_Settings::get();
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'ordelist-forecast', ORDELIST_URL . 'assets/css/ole-forecast.css', array(), ORDELIST_VERSION );
		wp_enqueue_script( 'ordelist-chartjs', ORDELIST_URL . 'assets/vendor/chartjs/chart.umd.js', array(), '4.5.1', true );
		wp_enqueue_script( 'ordelist-forecast-calc', ORDELIST_URL . 'assets/js/ole-forecast-calc.js', array(), ORDELIST_VERSION, true );
		wp_enqueue_script( 'ordelist-forecast', ORDELIST_URL . 'assets/js/ole-forecast.js', array( 'jquery', 'wc-enhanced-select', 'ordelist-chartjs', 'ordelist-forecast-calc' ), ORDELIST_VERSION, true );
		wp_localize_script(
			'ordelist-forecast',
			'ORDELIST_FC',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ordelist_fc' ),
				'today'   => current_time( 'Y-m-d' ),
				'margin'  => (int) $o['forecast_margin'],
				'i18n'    => array(
					'error'     => __( 'Failed.', 'order-list-enhancer' ),
					'kg'        => __( 'kg', 'order-list-enhancer' ),
					'pcs'       => __( 'pcs', 'order-list-enhancer' ),
					'year'      => __( 'Year', 'order-list-enhancer' ),
					'noWeight'  => __( 'no weight set — pieces only', 'order-list-enhancer' ),
					'noBatches' => __( 'Stock not subtracted — no batches are tracked for this product.', 'order-list-enhancer' ),
					'refZero'   => __( 'Reference year has no sales in this slice — coefficient set to 1.', 'order-list-enhancer' ),
					/* translators: %s: amount with unit, e.g. "3 kg". */
					'expiring'  => __( 'of which %s expires before the period ends', 'order-list-enhancer' ),
					'forecastL' => __( 'Forecast demand', 'order-list-enhancer' ),
					'stockL'    => __( 'Sellable stock', 'order-list-enhancer' ),
					'buyL'      => __( 'Recommended purchase', 'order-list-enhancer' ),
				),
			)
		);
	}

	private static function guard() {
		check_ajax_referer( 'ordelist_fc', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified first via self::guard() (check_ajax_referer).
	public static function ajax_series() {
		self::guard();
		$picked  = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$payload = $picked ? ORDELIST_Forecast_Data::payload( $picked ) : null;
		if ( ! $payload ) {
			wp_send_json_error( array( 'message' => 'not_found' ), 404 );
		}
		wp_send_json_success( $payload );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	public static function render() {
		?>
		<div class="wrap ole-fc-wrap">
			<h1><?php esc_html_e( 'Purchase planning', 'order-list-enhancer' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Pick a product or a single variation: yearly sales curves overlay on the chart, the table compares the selected slice across years, and the panel computes how much to order for the chosen period.', 'order-list-enhancer' ); ?></p>

			<div class="ole-fc-controls">
				<select class="wc-product-search ole-fc-product" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'order-list-enhancer' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:360px"></select>
				<label><input type="radio" name="ole-fc-unit" value="kg" checked/> <?php esc_html_e( 'kg', 'order-list-enhancer' ); ?></label>
				<label><input type="radio" name="ole-fc-unit" value="pcs"/> <?php esc_html_e( 'pcs', 'order-list-enhancer' ); ?></label>
			</div>

			<div class="ole-fc-chart"><canvas id="ole-fc-canvas"></canvas></div>

			<div class="ole-fc-controls">
				<label><?php esc_html_e( 'Period', 'order-list-enhancer' ); ?> <input type="date" class="ole-fc-start"/> — <input type="date" class="ole-fc-end"/></label>
				<button type="button" class="button ole-fc-preset" data-days="30"><?php esc_html_e( 'Month', 'order-list-enhancer' ); ?></button>
				<button type="button" class="button ole-fc-preset" data-days="91"><?php esc_html_e( 'Quarter', 'order-list-enhancer' ); ?></button>
				<button type="button" class="button ole-fc-preset" data-days="182"><?php esc_html_e( 'Half-year', 'order-list-enhancer' ); ?></button>
				<label><?php esc_html_e( 'Reference year', 'order-list-enhancer' ); ?> <select class="ole-fc-ref"></select></label>
				<label><?php esc_html_e( 'Coefficient', 'order-list-enhancer' ); ?> <input type="number" step="0.01" min="0" class="ole-fc-coef" style="width:90px"/></label>
				<button type="button" class="button ole-fc-coef-auto"><?php esc_html_e( 'auto', 'order-list-enhancer' ); ?></button>
				<label><?php esc_html_e( 'Margin, %', 'order-list-enhancer' ); ?> <input type="number" step="1" min="0" max="100" class="ole-fc-margin" style="width:80px"/></label>
			</div>

			<div class="ole-fc-result" hidden></div>

			<h2><?php esc_html_e( 'Sold in the selected slice', 'order-list-enhancer' ); ?></h2>
			<table class="widefat striped ole-fc-totals"><thead></thead><tbody></tbody></table>
		</div>
		<?php
	}
}
```

- [ ] **Step 3: Lint + prefix audit**

Run: `php -l includes/class-ordelist-forecast-admin.php` → clean.
Run: `grep -nE "wp_ajax_|wp_create_nonce|check_ajax_referer|add_submenu_page|wp_enqueue_(script|style)|wp_localize_script" includes/class-ordelist-forecast-admin.php` → every own registered name carries `ordelist` (`ordelist_fc_series`, `ordelist_fc`, `ordelist-forecast*`, `ordelist-chartjs`); only `wc-enhanced-select` / `woocommerce_admin_styles` core handles are unprefixed.

- [ ] **Step 4: Commit**

```bash
git add assets/vendor/chartjs/chart.umd.js assets/vendor/chartjs/LICENSE.md includes/class-ordelist-forecast-admin.php
git commit -m "feat(forecast): admin page + AJAX endpoint, bundle Chart.js 4.5.1 (MIT)"
```

---

### Task 4: Page JS + CSS

**Files:**
- Create: `assets/js/ole-forecast.js`
- Create: `assets/css/ole-forecast.css`

**Interfaces:**
- Consumes: `OrdelistForecastCalc` (Task 1), `ORDELIST_FC` + the markup hooks (Task 3), global `Chart` (bundled), payload fields `product_id / variations[{id,name,weight_kg,series}] / batches[{variation_id,expiry,qty}]` (Task 2).
- Produces: nothing consumed later; behavior only.

- [ ] **Step 1: Write the page JS**

Create `assets/js/ole-forecast.js`:

```js
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
		return v + ' ' + ORDELIST_FC.i18n[ unit ];
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
					row( $out, nameOf( split[ i ].id ), split[ i ].pcs + ' ' + ORDELIST_FC.i18n.pcs ).addClass( 'ole-fc-split' );
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
```

- [ ] **Step 2: Write the CSS**

Create `assets/css/ole-forecast.css`:

```css
.ole-fc-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 12px 0; }
.ole-fc-chart { position: relative; height: 340px; max-width: 1100px; background: #fff; border: 1px solid #dcdcde; padding: 8px; }
.ole-fc-result { max-width: 560px; background: #fff; border: 1px solid #dcdcde; padding: 12px 16px; margin: 12px 0; }
.ole-fc-row { display: flex; justify-content: space-between; padding: 3px 0; }
.ole-fc-row.ole-fc-buy { font-weight: 700; border-top: 1px solid #dcdcde; margin-top: 6px; padding-top: 8px; }
.ole-fc-row.ole-fc-split { color: #50575e; padding-left: 14px; }
.ole-fc-note { color: #996800; margin-top: 6px; }
.ole-fc-totals { max-width: 560px; }
```

- [ ] **Step 3: Verify JS↔PHP contract + lint**

Run: `node --check assets/js/ole-forecast.js && node tests/forecast/test-calc.js | tail -1`
Expected: no errors, `ALL PASS`.
Cross-check by grep that every selector the JS uses exists in the Task 3 markup: `for sel in ole-fc-product ole-fc-unit ole-fc-canvas ole-fc-start ole-fc-end ole-fc-preset ole-fc-ref ole-fc-coef ole-fc-coef-auto ole-fc-margin ole-fc-result ole-fc-totals; do grep -q "$sel" includes/class-ordelist-forecast-admin.php || echo "MISSING $sel"; done; echo SELECTORS-DONE` → only `SELECTORS-DONE`.
Also confirm every `ORDELIST_FC.i18n.*` key read in the JS (`error, kg, pcs, year, noWeight, noBatches, refZero, expiring, forecastL, stockL, buyL`) exists in the localize array of Task 3 (grep each).

- [ ] **Step 4: Commit**

```bash
git add assets/js/ole-forecast.js assets/css/ole-forecast.css
git commit -m "feat(forecast): interactive chart page glue and styles"
```

---

### Task 5: Settings card, wiring, gating — feature becomes live

**Files:**
- Modify: `includes/class-ordelist-settings.php` (defaults + clamp in `get()`)
- Modify: `includes/class-ordelist-settings-page.php` (`render_tab_inventory` + `ajax_save`)
- Modify: `includes/class-ordelist-plugin.php` (gating)
- Modify: `order-list-enhancer.php` (requires)

**Interfaces:**
- Consumes: `ORDELIST_Forecast_Admin::init()` (Task 3).
- Produces: settings keys `forecast_enabled` (default `'no'`) and `forecast_margin` (default `20`, clamped 0–100) via `ORDELIST_Settings::get()`.

- [ ] **Step 1: Defaults**

In `includes/class-ordelist-settings.php`, `defaults()`, right after the `warranty_days` line, add:

```php
			'forecast_enabled'                   => 'no',  // сторінка планування закупівлі
			'forecast_margin'                    => 20,    // запас похибки прогнозу, %
```

- [ ] **Step 2: Clamp in `get()`**

Same file, right after the `warranty_days` clamp, add:

```php
		$opts['forecast_margin'] = max( 0, min( 100, (int) $opts['forecast_margin'] ) );
```

- [ ] **Step 3: Inventory-tab card**

In `includes/class-ordelist-settings-page.php`, `render_tab_inventory()`, immediately after the warranty card's `self::card_close();` (still inside the method), add:

```php
		self::card_open(
			__( 'Purchase planning', 'order-list-enhancer' ),
			__( 'Multi-year sales chart with a purchase recommendation for a chosen period. Uses WooCommerce sales history; subtracts warranty-batch stock when batches are tracked.', 'order-list-enhancer' ),
			array( 'name' => 'forecast_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'forecast_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Safety margin, %', 'order-list-enhancer' ); ?></th>
				<td><input type="number" name="forecast_margin" min="0" max="100" step="1" value="<?php echo esc_attr( (string) $o['forecast_margin'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Added on top of the forecast. Allowed range 0–100, default 20; empty or out-of-range values are clamped when saving. Adjustable on the page per calculation.', 'order-list-enhancer' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Planning page', 'order-list-enhancer' ); ?></th>
				<td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ordelist-forecast' ) ); ?>"><?php esc_html_e( 'Open purchase planning', 'order-list-enhancer' ); ?></a></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();
```

- [ ] **Step 4: Persist in `ajax_save()`**

Same file, inside the `$opts = array( … );` literal, after the `'warranty_days'` entry, add:

```php
			'forecast_enabled'                  => $bool( 'forecast_enabled' ),
			'forecast_margin'                   => isset( $in['forecast_margin'] ) ? max( 0, min( 100, (int) $in['forecast_margin'] ) ) : 20,
```

(No schedule-sync call — this feature has no cron.)

- [ ] **Step 5: Requires + gating**

In `order-list-enhancer.php`, after the `class-ordelist-warranty-admin.php` require, add:

```php
require_once ORDELIST_DIR . 'includes/class-ordelist-forecast-data.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-forecast-admin.php';
```

In `includes/class-ordelist-plugin.php`, `__construct()`, after the `warranty_enabled` block, add:

```php
		if ( ORDELIST_Settings::is_yes( $opts, 'forecast_enabled' ) ) {
			ORDELIST_Forecast_Admin::init();
		}
```

- [ ] **Step 6: Lint + full test run + triangle check**

Run: `for f in order-list-enhancer.php includes/*.php; do php -l "$f" >/dev/null || echo "LINT FAIL: $f"; done; echo LINT-DONE` → only `LINT-DONE`.
Run: `for t in tests/*/test-*.php; do php "$t" >/dev/null 2>&1 || echo "FAIL $t"; done; node tests/forecast/test-calc.js >/dev/null 2>&1 || echo "FAIL js"; echo TESTS-DONE` → only `TESTS-DONE` (9 PHP suites + 1 node suite).
Triangle check (the repo's data-loss trap): `for k in forecast_enabled forecast_margin; do grep -q "'$k'" includes/class-ordelist-settings.php || echo "NO DEFAULT $k"; grep -q "$k" includes/class-ordelist-settings-page.php || echo "NO UI/SAVE $k"; done; echo TRIANGLE-DONE` → only `TRIANGLE-DONE`, and manually confirm both keys appear BOTH in `render_tab_inventory` and in the `ajax_save` `$opts` array.

- [ ] **Step 7: Commit**

```bash
git add includes/class-ordelist-settings.php includes/class-ordelist-settings-page.php includes/class-ordelist-plugin.php order-list-enhancer.php
git commit -m "feat(forecast): settings card (enable + safety margin), wiring and gating"
```

---

### Task 6: Bulgarian i18n + final sweep

**Files:**
- Modify: `languages/order-list-enhancer.pot` (append), `languages/order-list-enhancer-bg_BG.po` (append)
- Rebuild: `languages/order-list-enhancer-bg_BG.mo`

Already-existing msgids reused (add NO new entries for them): `Failed.`, `Search for a product…`. Everything below is new — but BEFORE appending, grep each msgid in the `.po` (`grep -n '^msgid "Year"' …` etc.) and skip any block that already exists (msgfmt hard-fails on duplicates).

- [ ] **Step 1: Append to the POT** (same entries as Step 2 but with empty `msgstr ""`; `#:` file references may be approximate).

- [ ] **Step 2: Append Bulgarian translations to the .po**

```
msgid "Purchase planning"
msgstr "Планиране на закупките"

msgid "Pick a product or a single variation: yearly sales curves overlay on the chart, the table compares the selected slice across years, and the panel computes how much to order for the chosen period."
msgstr "Изберете продукт или отделна вариация: графиката наслагва продажбите по години, таблицата сравнява избрания отрязък между годините, а панелът изчислява колко да поръчате за избрания период."

msgid "kg"
msgstr "кг"

msgid "pcs"
msgstr "бр"

msgid "Period"
msgstr "Период"

msgid "Month"
msgstr "Месец"

msgid "Quarter"
msgstr "Тримесечие"

msgid "Half-year"
msgstr "Полугодие"

msgid "Reference year"
msgstr "Опорна година"

msgid "Coefficient"
msgstr "Коефициент"

msgid "auto"
msgstr "авто"

msgid "Margin, %"
msgstr "Резерв, %"

msgid "Sold in the selected slice"
msgstr "Продадено за избрания отрязък"

msgid "Year"
msgstr "Година"

msgid "no weight set — pieces only"
msgstr "без тегло — само бройки"

msgid "Stock not subtracted — no batches are tracked for this product."
msgstr "Наличността не е приспадната — за този продукт не се водят партиди."

msgid "Reference year has no sales in this slice — coefficient set to 1."
msgstr "Опорната година няма продажби в този отрязък — коефициентът е 1."

#. translators: %s: amount with unit, e.g. "3 kg".
msgid "of which %s expires before the period ends"
msgstr "от тях %s изтича преди края на периода"

msgid "Forecast demand"
msgstr "Прогнозно търсене"

msgid "Sellable stock"
msgstr "Годна наличност"

msgid "Recommended purchase"
msgstr "Препоръчана поръчка"

msgid "Multi-year sales chart with a purchase recommendation for a chosen period. Uses WooCommerce sales history; subtracts warranty-batch stock when batches are tracked."
msgstr "Графика на продажбите по години с препоръка колко да поръчате за избран период. Използва историята на продажбите на WooCommerce; приспада наличността от партидите, когато се водят."

msgid "Safety margin, %"
msgstr "Резерв за сигурност, %"

msgid "Added on top of the forecast. Allowed range 0–100, default 20; empty or out-of-range values are clamped when saving. Adjustable on the page per calculation."
msgstr "Добавя се върху прогнозата. Позволен диапазон 0–100, по подразбиране 20; празни или извън диапазона стойности се ограничават при запис. Може да се променя на страницата при всяко изчисление."

msgid "Planning page"
msgstr "Страница за планиране"

msgid "Open purchase planning"
msgstr "Отвори планирането на закупките"
```

- [ ] **Step 3: Rebuild and verify**

Run: `msgfmt --statistics -o languages/order-list-enhancer-bg_BG.mo languages/order-list-enhancer-bg_BG.po`
Expected: `N translated messages.`, 0 untranslated / 0 fuzzy (N = previous count + number of appended blocks).
Run: `msgfmt --check -o /dev/null languages/order-list-enhancer-bg_BG.po` → no new warnings.
Byte-match each appended msgid against the actual source strings (`grep -F` the exact string in includes/) — em-dashes and the `…` ellipsis have bitten this repo before.

- [ ] **Step 4: Final sweep**

```bash
for t in tests/*/test-*.php; do php "$t" | tail -1; done
node tests/forecast/test-calc.js | tail -1
for f in order-list-enhancer.php uninstall.php includes/*.php; do php -l "$f" >/dev/null || echo "LINT FAIL: $f"; done; echo LINT-DONE
grep -rnE "OLE_[A-Za-z]|[^a-zA-Z_]ole_" includes/class-ordelist-forecast*.php assets/js/ole-forecast*.js | grep -vE "ORDELIST_|ole-fc-"; echo "prefix-audit exit=$?"
```

Expected: nine `ALL PASS` + one `ALL PASS` (node); `LINT-DONE` clean; prefix audit prints nothing, `exit=1`.

- [ ] **Step 5: Commit**

```bash
git add languages/order-list-enhancer.pot languages/order-list-enhancer-bg_BG.po languages/order-list-enhancer-bg_BG.mo
git commit -m "i18n(forecast): Bulgarian translations for the purchase-planning page, rebuild .mo"
```

---

## Out of scope for this plan (deliberate)

- Version bump, readme.txt changelog, release zip (`bin/build-zip.sh` already whitelists `assets` wholesale, so the vendor dir ships automatically — release pass just verifies zip size).
- Live verification (real lookup data, picker, chart rendering) — happens on dobavki.club after deploy; feature ships default-OFF, no schema, no migration.
- Filling the one variation that lacks a weight — Dan does it in the product editor; the page shows the pieces-only hint.
