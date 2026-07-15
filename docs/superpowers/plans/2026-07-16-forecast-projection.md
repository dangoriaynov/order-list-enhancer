# Forecast Projection Curve + Month Ticks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the current-year line on the purchase-planning chart with a dashed projection (reference-year pace × coefficient) and put X-axis ticks on month starts.

**Architecture:** New pure function `C.projection` in the WP-free calc module (node-tested); chart wiring in `ole-forecast.js` adds one dashed dataset (hidden from legend, live-updated on coefficient/ref-year changes) and a month-start tick callback. One new i18n string.

**Tech Stack:** Vanilla JS (ES5 style, matches existing), Chart.js 4.5.1 (bundled), node for tests, gettext for bg_BG.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-16-forecast-projection-design.md`
- Margin (Резерв %) must NOT affect the projection curve.
- ES5-style JS matching existing files (var, no arrows) — the plugin ships unbundled.
- All user-visible strings via `ORDELIST_FC.i18n` (PHP `__()`), translated to bg_BG, `.mo` rebuilt (msgfmt).
- No AI attribution in commits.
- Version bump to 1.0.51 (asset cache busting — JS enqueued with `ORDELIST_VERSION`).

---

### Task 1: `C.projection` in the calc module

**Files:**
- Modify: `assets/js/ole-forecast-calc.js` (after `C.cumulative`, ~line 81)
- Test: `tests/forecast/test-calc.js` (append before the exit-code footer)

**Interfaces:**
- Consumes: `C.cumulative( yearSeries, mmdd )`, `C.mmddList()` (existing).
- Produces: `C.projection( curSeries, refSeries, todayMMDD, coefficient, mmdd ) -> Array(365)` — `null` before today's index; from today's index onward `cumCur[today] + (cumRef[i] − cumRef[today]) × coefficient`. Element at today's index equals the actual cumulative (curve connects seamlessly).

- [ ] **Step 1: Write the failing tests** — append to `tests/forecast/test-calc.js`:

```js
// ---- projection ----
var pjCur = { '01-01': 10, '01-02': 5 };                       // факт: 10,15,15,…
var pjRef = { '01-01': 100, '01-03': 20, '01-04': 30 };        // опорний: 100,100,120,150,…
var pj = C.projection( pjCur, pjRef, '01-02', 0.5 );
check( pj[ 0 ] === null, 'projection is null before today' );
check( pj[ 1 ] === 15, 'projection starts at actual cumulative today' );
check( pj[ 2 ] === 25, 'projection adds ref growth × coefficient (15 + 20×0.5)' );
check( pj[ 3 ] === 40, 'projection accumulates ref growth (15 + 50×0.5)' );
check( pj[ 364 ] === 40, 'projection flat when reference has no later sales' );
var pjNoRef = C.projection( pjCur, null, '01-02', 0.5 );
check( pjNoRef[ 200 ] === 15, 'empty reference year -> flat projection' );
var pjEnd = C.projection( pjCur, pjRef, '12-31', 2 );
check( pjEnd[ 363 ] === null && pjEnd[ 364 ] === 15, 'today at year end -> single point' );
var pjZero = C.projection( pjCur, pjRef, '01-02', 0 );
check( pjZero[ 300 ] === 15, 'coefficient 0 -> flat projection' );
```

- [ ] **Step 2: Run to verify failure**

Run: `node tests/forecast/test-calc.js; echo exit=$?`
Expected: crash or FAIL lines (`C.projection is not a function`), exit ≠ 0.

- [ ] **Step 3: Implement** — add to `assets/js/ole-forecast-calc.js` after `C.cumulative`:

```js
	// Прогнозна крива поточного року: до сьогодні — null (там малює фактична лінія),
	// від сьогодні — факт + приріст опорного року за той самий відрізок × коефіцієнт.
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
```

- [ ] **Step 4: Run tests to verify pass**

Run: `node tests/forecast/test-calc.js; echo exit=$?`
Expected: all `ok`, exit=0.

- [ ] **Step 5: Commit**

```bash
git add assets/js/ole-forecast-calc.js tests/forecast/test-calc.js
git commit -m "feat(forecast): projection math for the current-year curve"
```

### Task 2: chart wiring — dashed projection dataset + month-start ticks

**Files:**
- Modify: `assets/js/ole-forecast.js` (drawChart ~146-173, recalcPanel ~176-236)
- Modify: `includes/class-ordelist-forecast-admin.php` (i18n array, ~line 57-71)

**Interfaces:**
- Consumes: `C.projection` from Task 1; `ORDELIST_FC.i18n.projection` (added here).
- Produces: chart behavior only; no exports.

- [ ] **Step 1: Add the i18n string** in `class-ordelist-forecast-admin.php`, inside the `'i18n' => array(...)`, after `'noSales'`:

```php
					'projection' => __( 'projection', 'order-list-enhancer' ),
```

- [ ] **Step 2: Rework `ole-forecast.js`.**

(a) Month names helper + today index, after `var COLORS = [...]`:

```js
	var MONTHS = ( function () {
		var lang = document.documentElement.lang || 'en';
		var out = [];
		for ( var m = 0; m < 12; m++ ) {
			try { out.push( new Date( Date.UTC( 2000, m, 1 ) ).toLocaleDateString( lang, { month: 'short', timeZone: 'UTC' } ) ); }
			catch ( e ) { out.push( String( m + 1 ) ); }
		}
		return out;
	} )();
```

(b) Coefficient resolution extracted so the chart and panel share it — insert near `weightsMap()`:

```js
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
```

(c) In `drawChart()`: truncate the current-year line after today, remember its color, append the dashed dataset, legend filter, month ticks:

```js
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
				// після сьогодні даних нема — прогноз малює окремий пунктирний датасет
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
				label: curYear() + ' — ' + ORDELIST_FC.i18n.projection,
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
```

(d) In `recalcPanel()`: refresh the projection before the single `chart.update`, and reuse `coefValue`. Replace the section from the highlight block to the `margin` line with:

```js
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
```

(the old duplicate `var s = series();` + auto/coef lines below are removed).

- [ ] **Step 3: Verify** — `node tests/forecast/test-calc.js` still exits 0 (calc untouched); `php -l includes/class-ordelist-forecast-admin.php` passes.

- [ ] **Step 4: Commit**

```bash
git add assets/js/ole-forecast.js includes/class-ordelist-forecast-admin.php
git commit -m "feat(forecast): dashed projection of the current year, month-start axis ticks"
```

### Task 3: bg_BG translation, POT, version bump

**Files:**
- Modify: `languages/order-list-enhancer.pot`, `languages/order-list-enhancer-bg_BG.po`, rebuild `.mo`
- Modify: `order-list-enhancer.php` (Version header + `ORDELIST_VERSION`), `readme.txt` (Stable tag + changelog)

- [ ] **Step 1:** Add to `.pot` and `.po` (match neighboring entry formatting; reference `includes/class-ordelist-forecast-admin.php`):

```po
msgid "projection"
msgstr "прогноза"
```

(`msgstr ""` in the `.pot`.)

- [ ] **Step 2:** Rebuild: `msgfmt -o languages/order-list-enhancer-bg_BG.mo languages/order-list-enhancer-bg_BG.po`

- [ ] **Step 3:** Bump `1.0.50` → `1.0.51` in `order-list-enhancer.php` (both places) and `readme.txt` Stable tag; add changelog entry mirroring the existing format.

- [ ] **Step 4:** Run the full suite: every PHP test under `tests/` plus `node tests/forecast/test-calc.js` — all pass.

- [ ] **Step 5: Commit**

```bash
git add languages order-list-enhancer.php readme.txt
git commit -m "chore: bump to 1.0.51 (forecast projection curve), bg translation"
```

### Task 4: deploy to live + verify

- [ ] **Step 1:** Deploy per procedure (backup tar → rsync whitelist `order-list-enhancer.php uninstall.php LICENSE readme.txt includes assets languages` → `chown -R dobavki:dobavki`).
- [ ] **Step 2:** Verify: `grep Version:` on the server shows 1.0.51; `curl -sI https://dobavki.club/` → HTTP 200.
- [ ] **Step 3:** Ask Dan to hard-refresh the forecast page and confirm the dashed curve + month ticks.
