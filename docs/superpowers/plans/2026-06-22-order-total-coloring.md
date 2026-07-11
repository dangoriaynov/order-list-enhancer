# Order Total Coloring (Threshold Rings) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Highlight an order with a colored ring around its `Ship to` cell (orders list) and address panel (edit screen) when the order total reaches a configured threshold; multiple thresholds each get a color and the highest matched one wins.

**Architecture:** A new "Order total coloring" settings section stores a rules table (`threshold → color → label`). A pure PHP class `OLE_Order_Color` prepares the rules (sorted descending) for the front end. A pure JS module `ole-order-color.js` (dual browser/node export, mirroring `ole-phone-checkout.js`) holds the threshold-matching and currency-parsing logic and is unit-tested under node. The existing admin script `ole-admin.js` consumes that module to draw an inset `box-shadow` ring — a separate visual channel from the existing shipping background fill, so both can show at once. On the edit screen the exact total is passed from PHP; on the list the displayed amount is parsed using the store's price separators.

**Tech Stack:** PHP 7.4+, WordPress/WooCommerce admin hooks, vanilla JS (no build step), plain-PHP + node test scripts (no framework).

## Global Constraints

- Plugin slug / text domain: `order-list-enhancer`. All user-facing strings wrapped in `__()`/`esc_html__()` etc. with that domain.
- Target version after this feature: **1.0.24** (update `Version:` header + `OLE_VERSION` constant + readme `Stable tag`).
- Requires PHP 7.4, WordPress 6.0+, WooCommerce 8.0+. HPOS-compatible (already declared) — do not break the `woocommerce_page_wc-orders` / `edit-shop_order` / `shop_order` screen handling.
- No new runtime dependencies, no build tooling. JS stays ES5-compatible IIFE style (match existing files).
- Follow existing patterns: inline styles applied via JS (like `OLE_Shipping`/`colorShipping`), settings saved through the existing `ole_save_settings` AJAX action, color fields use the `.ole-color` class + wp-color-picker.
- Feature is **off by default** (`total_color_enabled = 'no'`).
- Coloring is **additive**: never change the shipping background fill, the duplicate row tinting, or any other existing behavior. The ring is the only new visual.
- **i18n (Bulgarian):** every user-facing admin string uses `__()`/`esc_html_e()` etc. with domain `order-list-enhancer`. The `bg_BG` translation must cover **all** admin options and their value-labels — backfilling pre-existing gaps (e.g. the Extras and default-bulk-action sections), not only this feature — and the compiled `.mo` is rebuilt. Preserve `%s`/`%1$s` placeholders and HTML entities verbatim in translations.

---

### Task 1: PHP data layer — settings sanitization + `OLE_Order_Color::for_js`

**Files:**
- Modify: `includes/class-ole-settings.php` (add defaults, add `clean_total_color_rules`, apply it in `get()`)
- Create: `includes/class-ole-order-color.php`
- Modify: `order-list-enhancer.php` (require the new class)
- Test: `tests/order-color/test-rules.php`

**Interfaces:**
- Produces: `OLE_Settings::clean_total_color_rules( array $rows ) : array` — each kept row `['threshold'=>float, 'color'=>string, 'label'=>string]`, only when `threshold > 0` and `color` is a valid hex.
- Produces: `OLE_Order_Color::for_js( array $opts ) : array` → `['rules' => [ ['threshold'=>float,'color'=>string,'label'=>string], … ]]` sorted **descending** by threshold, dropping rows with `threshold <= 0` or empty color.
- Produces: defaults `total_color_enabled` (`'no'`), `total_color_rules` (`array()`) and a cleaned `total_color_rules` out of `OLE_Settings::get()`.

- [ ] **Step 1: Write the failing test**

Create `tests/order-color/test-rules.php`:

```php
<?php
// Standalone unit tests for the order-total coloring data layer (no WordPress).
define( 'ABSPATH', true );

// Minimal WP stubs used by OLE_Settings::clean_total_color_rules.
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', (string) $s ) ); }
function sanitize_hex_color( $c ) { $c = (string) $c; return preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c ) ? $c : ''; }

require __DIR__ . '/../../includes/class-ole-settings.php';
require __DIR__ . '/../../includes/class-ole-order-color.php';

$fails = 0;
function ck( $cond, $msg ) { global $fails; echo ( $cond ? 'ok   - ' : 'FAIL - ' ) . "$msg\n"; if ( ! $cond ) { $fails++; } }

// clean_total_color_rules: keep only valid rows, cast float, sanitize.
$clean = OLE_Settings::clean_total_color_rules( array(
	array( 'threshold' => '149.50', 'color' => '#ff0000', 'label' => '  big   order ' ),
	array( 'threshold' => 0,        'color' => '#00ff00', 'label' => 'zero' ),    // dropped: threshold 0
	array( 'threshold' => 50,       'color' => 'nope',    'label' => 'x' ),       // dropped: bad color
	array( 'threshold' => 100,      'color' => '#abc' ),                          // label optional
) );
ck( count( $clean ) === 2, 'clean keeps only the 2 valid rows' );
ck( $clean[0]['threshold'] === 149.5, 'threshold "149.50" cast to float 149.5' );
ck( $clean[0]['label'] === 'big order', 'label sanitized/collapsed' );
ck( $clean[1]['threshold'] === 100.0 && $clean[1]['label'] === '', 'missing label -> empty string' );

// for_js: sort descending by threshold, drop invalid rows.
$out = OLE_Order_Color::for_js( array( 'total_color_rules' => array(
	array( 'threshold' => 100, 'color' => '#100' ),
	array( 'threshold' => 200, 'color' => '#200' ),
	array( 'threshold' => 150, 'color' => '#150' ),
	array( 'threshold' => 0,   'color' => '#000' ),  // dropped: threshold 0
	array( 'threshold' => 75,  'color' => '' ),      // dropped: no color
) ) );
$th = array_map( function ( $r ) { return $r['threshold']; }, $out['rules'] );
ck( $th === array( 200.0, 150.0, 100.0 ), 'for_js sorts thresholds descending and drops invalid' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/order-color/test-rules.php`
Expected: FAIL — fatal `Class "OLE_Order_Color" not found` (or `Call to undefined method OLE_Settings::clean_total_color_rules`).

- [ ] **Step 3: Add defaults + `clean_total_color_rules` to `OLE_Settings`**

In `includes/class-ole-settings.php`, inside `defaults()`, add these two entries to the returned array (next to the existing `extras_*` / `phone_validate_*` keys):

```php
			'total_color_enabled' => 'no', // ring orders whose total reaches a threshold
			'total_color_rules'   => array(), // [ ['threshold'=>float,'color'=>'#hex','label'=>''], ... ]
```

Add this method next to `clean_extras_map()`:

```php
	/**
	 * Нормалізує правила кольорування за сумою: лишає рядки з порогом > 0 і валідним кольором.
	 */
	public static function clean_total_color_rules( $rows ) {
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$threshold = isset( $r['threshold'] ) ? (float) $r['threshold'] : 0;
			$color     = isset( $r['color'] ) ? (string) sanitize_hex_color( $r['color'] ) : '';
			$label     = isset( $r['label'] ) ? sanitize_text_field( (string) $r['label'] ) : '';
			if ( $threshold > 0 && '' !== $color ) {
				$out[] = array(
					'threshold' => $threshold,
					'color'     => $color,
					'label'     => $label,
				);
			}
		}
		return $out;
	}
```

In `get()`, after the existing `$opts['extras_map'] = self::clean_extras_map( $opts['extras_map'] );` line, add:

```php
		$opts['total_color_rules'] = self::clean_total_color_rules( $opts['total_color_rules'] );
```

- [ ] **Step 4: Create `OLE_Order_Color`**

Create `includes/class-ole-order-color.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Готує правила кольорування за сумою замовлення для передачі у JS.
 * Сортує за спаданням порога — JS бере перше правило, де сума >= порога
 * («вищий поріг перемагає»).
 */
class OLE_Order_Color {

	public static function for_js( $opts ) {
		$rules = array();
		$src   = isset( $opts['total_color_rules'] ) ? (array) $opts['total_color_rules'] : array();
		foreach ( $src as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$threshold = isset( $r['threshold'] ) ? (float) $r['threshold'] : 0;
			$color     = isset( $r['color'] ) ? (string) $r['color'] : '';
			if ( $threshold <= 0 || '' === $color ) {
				continue;
			}
			$rules[] = array(
				'threshold' => $threshold,
				'color'     => $color,
				'label'     => isset( $r['label'] ) ? (string) $r['label'] : '',
			);
		}
		usort(
			$rules,
			function ( $a, $b ) {
				if ( $a['threshold'] === $b['threshold'] ) {
					return 0;
				}
				return ( $a['threshold'] < $b['threshold'] ) ? 1 : -1; // descending
			}
		);
		return array( 'rules' => $rules );
	}
}
```

In `order-list-enhancer.php`, add the require next to the other includes (after the `class-ole-shipping.php` line):

```php
require_once OLE_DIR . 'includes/class-ole-order-color.php';
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/order-color/test-rules.php`
Expected: PASS — ends with `ALL PASS`, exit 0.

- [ ] **Step 6: Commit**

```bash
git add includes/class-ole-settings.php includes/class-ole-order-color.php order-list-enhancer.php tests/order-color/test-rules.php
git commit -m "feat(total-color): settings sanitization + OLE_Order_Color rule prep

```

---

### Task 2: Settings page — "Order total coloring" section + save

**Files:**
- Modify: `includes/class-ole-settings-page.php` (render new section in `render()`; parse + save in `ajax_save()`)

**Interfaces:**
- Consumes: `OLE_Settings::clean_total_color_rules()` (Task 1).
- Produces: posted fields `total_color_enabled`, `total_threshold[]`, `total_color[]`, `total_label[]` saved into option key `total_color_rules` + `total_color_enabled`.

> No unit test — this is WordPress form rendering + AJAX wiring (matches how the sibling "Shipping coloring" / "Extras" sections are verified). Verification is the manual save round-trip in Step 3.

- [ ] **Step 1: Render the section**

In `includes/class-ole-settings-page.php`, in `render()`, immediately **after** the closing `</table>` of the "Shipping coloring" section (the one ending right before `<h2>…Order total on edit page…</h2>`), insert:

```php
				<h2><?php esc_html_e( 'Order total coloring', 'order-list-enhancer' ); ?></h2>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="total_color_enabled" <?php echo $cb( 'total_color_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'Ring the order’s row in the list — and its address panel on the order screen — when the total reaches a threshold below. Independent of the shipping color; the highest matched threshold wins.', 'order-list-enhancer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Threshold rules', 'order-list-enhancer' ); ?></th>
						<td>
							<?php
							$trules = $o['total_color_rules'];
							if ( empty( $trules ) ) {
								$trules = array(
									array(
										'threshold' => '',
										'color'     => '',
										'label'     => '',
									),
								);
							}
							?>
							<table class="widefat ole-rules" style="max-width:680px"><thead><tr>
								<th style="text-align:center"><?php esc_html_e( 'Order total ≥', 'order-list-enhancer' ); ?></th>
								<th style="text-align:center"><?php esc_html_e( 'Color', 'order-list-enhancer' ); ?></th>
								<th style="text-align:center"><?php esc_html_e( 'Label', 'order-list-enhancer' ); ?></th>
								<th></th>
							</tr></thead><tbody>
							<?php foreach ( $trules as $r ) : ?>
								<tr>
									<td><input type="number" step="0.01" min="0" name="total_threshold[]" value="<?php echo esc_attr( '' === $r['threshold'] ? '' : $r['threshold'] ); ?>" class="regular-text" placeholder="100"/></td>
									<td><input type="text" name="total_color[]" value="<?php echo esc_attr( $r['color'] ); ?>" class="ole-color" placeholder="#d63638"/></td>
									<td><input type="text" name="total_label[]" value="<?php echo esc_attr( $r['label'] ); ?>" class="regular-text"/></td>
									<td><button type="button" class="button ole-rule-remove">&times;</button></td>
								</tr>
							<?php endforeach; ?>
							</tbody></table>
							<p><button type="button" class="button ole-rule-add"><?php esc_html_e( 'Add rule', 'order-list-enhancer' ); ?></button></p>
							<p class="description"><?php esc_html_e( 'When an order’s total is at or above a threshold it gets a ring in that color. If several apply, the highest threshold wins. The ring is drawn on top of any shipping color — both stay visible.', 'order-list-enhancer' ); ?></p>
						</td>
					</tr>
				</tbody></table>
```

> Note: the `.ole-rule-add` / `.ole-rule-remove` buttons are already wired by `assets/js/ole-settings.js` (shared with the shipping rules table). The new rows reuse them as-is — no JS change needed. Confirm in Step 3 that Add/Remove work here.

- [ ] **Step 2: Parse + save the posted rules**

In `ajax_save()`, after the block that builds `$extras_map` (just before `$opts = array(` ), add:

```php
		$total_color_rules = array();
		if ( isset( $in['total_threshold'] ) && is_array( $in['total_threshold'] ) ) {
			$tc = isset( $in['total_color'] ) ? (array) $in['total_color'] : array();
			$tl = isset( $in['total_label'] ) ? (array) $in['total_label'] : array();
			foreach ( $in['total_threshold'] as $i => $th ) {
				$total_color_rules[] = array(
					'threshold' => $th,
					'color'     => isset( $tc[ $i ] ) ? $tc[ $i ] : '',
					'label'     => isset( $tl[ $i ] ) ? $tl[ $i ] : '',
				);
			}
		}
		$total_color_rules = OLE_Settings::clean_total_color_rules( $total_color_rules );
```

Then add these two entries to the `$opts = array( … )` literal (next to `'bulk_default_action' => …`):

```php
			'total_color_enabled' => $bool( 'total_color_enabled' ),
			'total_color_rules'   => $total_color_rules,
```

- [ ] **Step 3: Manual verification (save round-trip)**

1. WooCommerce → Order List Enhancer. Confirm the new "Order total coloring" section renders with the rules table.
2. Tick **Enable**, add a rule (e.g. threshold `100`, pick a color, label `Big`), click **Add rule**, confirm a blank row appears; click **×**, confirm it removes.
3. Save changes; reload the page. Confirm the enable checkbox stays ticked and the rule persists with the float threshold and color.
4. Save with an empty threshold row → confirm it is dropped after reload (no zero-threshold row persists).

- [ ] **Step 4: Commit**

```bash
git add includes/class-ole-settings-page.php
git commit -m "feat(total-color): settings section + save for threshold rules

```

---

### Task 3: JS logic module `ole-order-color.js` (parse + match) + node test

**Files:**
- Create: `assets/js/ole-order-color.js`
- Test: `tests/order-color/test-order-color-js.cjs`

**Interfaces:**
- Produces (CommonJS export for node **and** `window.OLE_OrderColor` in the browser):
  - `parseAmount( text, fmt ) : number|null` — parse a displayed price string into a float, where `fmt = { decimal: string, thousand: string }`. Returns `null` when no number is found.
  - `matchTotal( total, rules ) : { color, label } | null` — given a float `total` and rules **already sorted descending by threshold**, return the first rule where `total >= threshold`, else `null`. `rules[i] = { threshold, color, label }`.

- [ ] **Step 1: Write the failing test**

Create `tests/order-color/test-order-color-js.cjs`:

```js
const OC = require( '../../assets/js/ole-order-color.js' );
let fails = 0;
function ck( c, m ) { console.log( ( c ? 'ok   - ' : 'FAIL - ' ) + m ); if ( ! c ) fails++; }

const rules = [
	{ threshold: 200, color: '#200', label: 'VIP' },
	{ threshold: 150, color: '#150', label: '' },
	{ threshold: 100, color: '#100', label: '' },
];

// matchTotal: highest matched threshold wins; >= boundary; nothing below the lowest.
ck( OC.matchTotal( 250, rules ).color === '#200', 'total 250 -> highest (200) rule' );
ck( OC.matchTotal( 200, rules ).color === '#200', 'total 200 -> 200 rule (>= boundary)' );
ck( OC.matchTotal( 150, rules ).color === '#150', 'total 150 -> 150 rule' );
ck( OC.matchTotal( 149.99, rules ).color === '#100', 'total 149.99 -> 100 rule' );
ck( OC.matchTotal( 99, rules ) === null, 'total 99 -> no match (below lowest)' );
ck( OC.matchTotal( null, rules ) === null, 'null total -> no match' );
ck( OC.matchTotal( 250, [] ) === null, 'no rules -> no match' );

// parseAmount: handles the separator combinations WooCommerce can render.
ck( OC.parseAmount( '1\u00a0234,50 лв.', { decimal: ',', thousand: ' ' } ) === 1234.5, 'space-thousand, comma-decimal (nbsp)' );
ck( OC.parseAmount( '$1,234.50', { decimal: '.', thousand: ',' } ) === 1234.5, 'comma-thousand, dot-decimal' );
ck( OC.parseAmount( '1.234,50 лв.', { decimal: ',', thousand: '.' } ) === 1234.5, 'dot-thousand, comma-decimal' );
ck( OC.parseAmount( '49,00 лв.', { decimal: ',', thousand: ' ' } ) === 49, 'plain comma-decimal' );
ck( OC.parseAmount( '120', { decimal: ',', thousand: ' ' } ) === 120, 'integer only' );
ck( OC.parseAmount( 'abc', { decimal: ',', thousand: ' ' } ) === null, 'non-numeric -> null' );

console.log( fails ? '\n' + fails + ' FAILED' : '\nALL PASS' );
process.exit( fails ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/order-color/test-order-color-js.cjs`
Expected: FAIL — `Cannot find module '../../assets/js/ole-order-color.js'`.

- [ ] **Step 3: Create the module**

Create `assets/js/ole-order-color.js`:

```js
( function () {
	// Parse a displayed price string into a float using the store's separators.
	// Order matters: strip the thousand separator BEFORE turning the decimal
	// separator into '.', otherwise "1.234,50" (dot-thousand) breaks.
	function parseAmount( text, fmt ) {
		fmt = fmt || {};
		var dec = fmt.decimal || '.';
		var tho = fmt.thousand || '';
		var s = String( text == null ? '' : text ).replace( /\u00a0/g, ' ' ); // normalize nbsp -> space
		if ( tho ) { s = s.split( tho ).join( '' ); }
		if ( dec && dec !== '.' ) { s = s.split( dec ).join( '.' ); }
		s = s.replace( /[^0-9.\-]/g, '' );
		if ( s === '' || s === '-' || s === '.' ) { return null; }
		var f = parseFloat( s );
		return isNaN( f ) ? null : f;
	}

	// rules MUST be pre-sorted descending by threshold (OLE_Order_Color::for_js does this).
	function matchTotal( total, rules ) {
		if ( total == null || isNaN( total ) || ! rules || ! rules.length ) { return null; }
		for ( var i = 0; i < rules.length; i++ ) {
			var th = parseFloat( rules[ i ].threshold );
			if ( ! isNaN( th ) && total >= th ) {
				return { color: rules[ i ].color, label: rules[ i ].label || '' };
			}
		}
		return null;
	}

	var api = { parseAmount: parseAmount, matchTotal: matchTotal };
	// Node export for parity tests; harmless in the browser.
	if ( typeof module !== 'undefined' && module.exports ) { module.exports = api; }
	// Browser: expose for ole-admin.js.
	if ( typeof window !== 'undefined' ) { window.OLE_OrderColor = api; }
} )();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node tests/order-color/test-order-color-js.cjs`
Expected: PASS — ends with `ALL PASS`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add assets/js/ole-order-color.js tests/order-color/test-order-color-js.cjs
git commit -m "feat(total-color): parseAmount + matchTotal JS module with node tests

```

---

### Task 4: Plugin enqueue — payload, gating, conditional script load

**Files:**
- Modify: `includes/class-ole-plugin.php` (`enqueue()`)

**Interfaces:**
- Consumes: `OLE_Order_Color::for_js()` (Task 1), `assets/js/ole-order-color.js` (Task 3), `OLE_Settings::is_yes()`.
- Produces (into `OLE_DATA`): `totalColor = { on: bool, rules: [...], orderTotal?: float }` and `priceFormat = { decimal: string, thousand: string }`. Loads `ole-order-color.js` as a dependency of `ole-admin` when the feature is on.

> No unit test — enqueue wiring. Verified via the browser `OLE_DATA` check in Step 6.

- [ ] **Step 1: Compute the feature flag**

In `enqueue()`, just after the existing line `$copy_on     = OLE_Settings::is_yes( $opts, 'copy_buttons' );`, add:

```php
		$total_color_active = OLE_Settings::is_yes( $opts, 'total_color_enabled' );
```

- [ ] **Step 2: Keep assets loading when only this feature is on**

Update the edit-context early return. Change:

```php
		if ( 'edit' === $context && ! $ship_active && ! $copy_on && ! $edit_group ) {
			return;
		}
```

to:

```php
		if ( 'edit' === $context && ! $ship_active && ! $copy_on && ! $edit_group && ! $total_color_active ) {
			return;
		}
```

Update the list-context early return. Change:

```php
		if ( 'list' === $context && ! $dup_on && ! $ship_active && '' === $bulk_def && ! OLE_Settings::is_yes( $opts, 'phone_validate_enabled' ) ) {
			return;
		}
```

to:

```php
		if ( 'list' === $context && ! $dup_on && ! $ship_active && '' === $bulk_def && ! OLE_Settings::is_yes( $opts, 'phone_validate_enabled' ) && ! $total_color_active ) {
			return;
		}
```

- [ ] **Step 3: Add the payload**

After the existing conditional additions to `$data` (i.e. just before the `wp_enqueue_style( 'ole-admin', … )` line), add:

```php
		$data['totalColor'] = array(
			'on'    => $total_color_active,
			'rules' => $total_color_active ? OLE_Order_Color::for_js( $opts )['rules'] : array(),
		);
		$data['priceFormat'] = array(
			'decimal'  => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
			'thousand' => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
		);
		if ( 'edit' === $context && $total_color_active ) {
			$oid_tc = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $oid_tc ) {
				$ord_tc = wc_get_order( $oid_tc );
				if ( $ord_tc ) {
					$data['totalColor']['orderTotal'] = (float) $ord_tc->get_total();
				}
			}
		}
```

- [ ] **Step 4: Conditionally load the logic module before `ole-admin`**

Replace the final `wp_enqueue_script( 'ole-admin', … )` line:

```php
		wp_enqueue_script( 'ole-admin', OLE_URL . 'assets/js/ole-admin.js', array(), OLE_VERSION, true );
```

with:

```php
		$admin_deps = array();
		if ( $total_color_active ) {
			wp_enqueue_script( 'ole-order-color', OLE_URL . 'assets/js/ole-order-color.js', array(), OLE_VERSION, true );
			$admin_deps[] = 'ole-order-color';
		}
		wp_enqueue_script( 'ole-admin', OLE_URL . 'assets/js/ole-admin.js', $admin_deps, OLE_VERSION, true );
```

(The `wp_enqueue_style( 'ole-admin', … )` line directly above stays unchanged.)

- [ ] **Step 5: Lint**

Run: `php -l includes/class-ole-plugin.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Manual verification (payload present)**

1. Enable the feature with at least one rule (Task 2). Open the WooCommerce Orders list.
2. In the browser console: `OLE_DATA.totalColor` → `{ on: true, rules: [ {threshold, color, label}, … ] }` sorted descending; `OLE_DATA.priceFormat` → your store's `{decimal, thousand}`.
3. Open a single order (edit screen): `OLE_DATA.totalColor.orderTotal` is that order's numeric total.
4. Confirm `ole-order-color.js` is loaded **before** `ole-admin.js` (Network tab / `window.OLE_OrderColor` is defined).
5. Disable the feature, reload the Orders list: if no other feature is on, `OLE_DATA` is absent (early return still works).

- [ ] **Step 7: Commit**

```bash
git add includes/class-ole-plugin.php
git commit -m "feat(total-color): enqueue payload, gating, conditional module load

```

---

### Task 5: `ole-admin.js` — draw the rings (list + edit)

**Files:**
- Modify: `assets/js/ole-admin.js`

**Interfaces:**
- Consumes: `window.OLE_OrderColor.parseAmount` / `.matchTotal` (Task 3); `OLE_DATA.totalColor` + `OLE_DATA.priceFormat` (Task 4).
- Produces: an inset `box-shadow` ring on `td.column-shipping_address` (list) and `#order_data .address` (edit) for matching orders, composed with any existing shipping styling.

> No unit test — the testable logic lives in `ole-order-color.js` (Task 3). DOM application is verified manually in Step 4.

- [ ] **Step 1: Add module/data references**

Near the top of the IIFE, after the existing `var DEC    = D.decimalSep || ',';` line, add:

```js
	var TC  = D.totalColor || { on: false, rules: [] };
	var PF  = D.priceFormat || { decimal: '.', thousand: ',' };
	var OC  = ( typeof window !== 'undefined' && window.OLE_OrderColor ) ? window.OLE_OrderColor : null;
```

- [ ] **Step 2: Add the ring functions**

After the `colorEditAddress()` function (just before `function markDuplicates()`), add:

```js
	// Append a ring label to whatever tooltip the shipping fill may have set.
	function ringTitle( el, label ) {
		if ( ! label ) { return; }
		el.title = el.title ? ( el.title + ' · ' + label ) : label;
	}

	// Orders list: ring the "Ship to" cell when the row's total meets a threshold.
	function colorTotalRingsList() {
		if ( ! TC.on || ! OC ) { return; }
		var rows = document.querySelectorAll( '.wp-list-table tbody tr' );
		Array.prototype.forEach.call( rows, function ( tr ) {
			var cell = tr.querySelector( 'td.column-shipping_address' );
			if ( ! cell || cell.getAttribute( 'data-ole-ring' ) ) { return; }
			var amtEl = tr.querySelector( 'td.column-order_total .woocommerce-Price-amount' )
				|| tr.querySelector( 'td.order_total .woocommerce-Price-amount' );
			if ( ! amtEl ) { return; }
			cell.setAttribute( 'data-ole-ring', '1' );
			var total = OC.parseAmount( amtEl.textContent || '', PF );
			var m = OC.matchTotal( total, TC.rules );
			if ( m && m.color ) {
				cell.style.setProperty( 'box-shadow', 'inset 0 0 0 3px ' + m.color, 'important' );
				cell.style.borderRadius = '8px';
				ringTitle( cell, m.label );
			}
		} );
	}

	// Edit screen: ring the address panel(s), composed with the shipping shadow.
	function colorTotalRingEdit() {
		if ( ! TC.on || ! OC || TC.orderTotal == null ) { return; }
		var m = OC.matchTotal( TC.orderTotal, TC.rules );
		if ( ! m || ! m.color ) { return; }
		var blocks = document.querySelectorAll( '#order_data .address' );
		Array.prototype.forEach.call( blocks, function ( el ) {
			if ( el.getAttribute( 'data-ole-ring' ) ) { return; }
			el.setAttribute( 'data-ole-ring', '1' );
			var existing = el.style.boxShadow;
			el.style.boxShadow = 'inset 0 0 0 3px ' + m.color + ( existing ? ', ' + existing : '' );
			if ( ! el.style.borderRadius ) { el.style.borderRadius = '6px'; }
			if ( ! el.style.padding ) { el.style.padding = '8px 10px'; }
			ringTitle( el, m.label );
		} );
	}
```

- [ ] **Step 3: Wire into `run()`**

Replace the `run()` function:

```js
	function run() {
		if ( 'edit' === CTX ) { normalizePhones(); colorEditAddress(); addCopyButtons(); addEditGroupBadge(); return; }
		colorShipping();
		markDuplicates();
		setupBulkActions();
		markPhoneInvalid();
	}
```

with (adds `colorTotalRingEdit()` after `colorEditAddress()` so it composes with the shipping shadow, and `colorTotalRingsList()` after `colorShipping()`):

```js
	function run() {
		if ( 'edit' === CTX ) { normalizePhones(); colorEditAddress(); colorTotalRingEdit(); addCopyButtons(); addEditGroupBadge(); return; }
		colorShipping();
		colorTotalRingsList();
		markDuplicates();
		setupBulkActions();
		markPhoneInvalid();
	}
```

- [ ] **Step 4: Manual verification (visual)**

1. Configure two rules (e.g. `100` → orange, `200` → red). Orders list:
   - An order with total `120` → orange ring on its `Ship to` cell.
   - An order with total `250` → red ring (highest wins).
   - An order with total `50` → no ring.
2. With a shipping rule also matching a ringed order, confirm the **fill stays** and the ring shows on top (both visible).
3. Open a `250` order's edit screen → red ring around the address panel; if shipping colored it, the left bar shadow remains.
4. Hover a ringed cell with a label set → tooltip shows the label (appended after any shipping label).
5. Re-run is idempotent: scroll / let the list re-render (MutationObserver) → no duplicate/teetering styles (the `data-ole-ring` guard holds).

- [ ] **Step 5: Re-run the JS unit test (regression guard)**

Run: `node tests/order-color/test-order-color-js.cjs`
Expected: PASS (the module is unchanged, but confirm nothing broke).

- [ ] **Step 6: Commit**

```bash
git add assets/js/ole-admin.js
git commit -m "feat(total-color): draw threshold rings on list cells and edit panel

```

---

### Task 6: Release — version bump + readme/changelog

**Files:**
- Modify: `order-list-enhancer.php` (`Version:` header + `OLE_VERSION`)
- Modify: `readme.txt` (`Stable tag`, Description bullet, Changelog entry)

**Interfaces:** none (release metadata).

- [ ] **Step 1: Bump the plugin version**

In `order-list-enhancer.php`, change the header line `* Version:           1.0.23` to `* Version:           1.0.24`, and change `define( 'OLE_VERSION', '1.0.23' );` to `define( 'OLE_VERSION', '1.0.24' );`.

- [ ] **Step 2: Update readme stable tag + description + changelog**

In `readme.txt`:

Change `Stable tag: 1.0.19` to `Stable tag: 1.0.24`.

In the `== Description ==` section, after the existing `**Pick a default bulk action.**` paragraph, add:

```
**Flag high-value orders.**
Set order-total thresholds, each with its own color. When an order's total reaches a threshold, its row in the list — and its address panel on the order screen — gets a colored ring, so big orders stand out at a glance. The ring is independent of the shipping color (both show at once), and when several thresholds apply the highest one wins. Off by default.
```

In the `== Changelog ==` section, add a new top entry directly under the `== Changelog ==` heading:

```
= 1.0.24 =
* New: optional order-total coloring — set value thresholds, each with a color; an order whose total reaches a threshold gets a colored ring on its list row and its order-screen address panel (highest threshold wins). Drawn on top of the shipping color, so both stay visible. Off by default.
```

- [ ] **Step 3: Verify the version is consistent**

Run: `grep -n "1.0.24" order-list-enhancer.php readme.txt`
Expected: the `Version:` header, the `OLE_VERSION` define, the `Stable tag`, and the changelog entry all show `1.0.24`.

- [ ] **Step 4: Run the full test suite (final regression)**

Run: `php tests/order-color/test-rules.php && node tests/order-color/test-order-color-js.cjs && php tests/phone/test-phone-validator.php && node tests/phone/test-phone-js.cjs`
Expected: every script ends with `ALL PASS` (exit 0).

- [ ] **Step 5: Commit**

```bash
git add order-list-enhancer.php readme.txt
git commit -m "chore(release): order total coloring; bump 1.0.24

```

---

### Task 7: Bulgarian translation backfill + `.mo` rebuild

**Files:**
- Modify: `languages/order-list-enhancer.pot` (regenerate from source)
- Modify: `languages/order-list-enhancer-bg_BG.po` (merge + translate every empty/fuzzy entry)
- Modify: `languages/order-list-enhancer-bg_BG.mo` (recompile)

**Interfaces:** none (translation assets). Runs last, after all admin UI strings exist (Tasks 2 + 6).

**Context:** Recent features added English admin strings that were never translated — e.g. the Extras section ("Extras → products", "Enable conversion", "Mapping (extra → product)", …) and the default-bulk-action section ("Orders list — default bulk action", "Pre-selected action", "— (none)") are missing from `bg_BG`, and the new "Order total coloring" strings don't exist yet. The store admin is Bulgarian; fill the gaps and rebuild the compiled catalog. Use the existing `bg_BG.po` as the terminology/style reference (formal imperative, e.g. "Включи маркирането"). `xgettext`, `msgmerge`, `msgfmt` are installed (Homebrew gettext); `wp-cli` is NOT available — use gettext directly.

> No automated unit test — verification is `msgfmt --statistics` reporting **0 untranslated, 0 fuzzy**, plus a spot-check of a few entries.

- [ ] **Step 1: Regenerate the `.pot` from all plugin PHP**

Run (from repo root):

```bash
xgettext --default-domain=order-list-enhancer --language=PHP --from-code=UTF-8 \
  --add-comments=translators --no-wrap \
  -k__ -k_e -k_x:1,2c -kesc_html__ -kesc_html_e -kesc_html_x:1,2c \
  -kesc_attr__ -kesc_attr_e -kesc_attr_x:1,2c -k_n:1,2 -k_nx:1,2,4c \
  -o languages/order-list-enhancer.pot \
  order-list-enhancer.php $(find includes -name '*.php')
# xgettext writes "charset=CHARSET"; fix it to UTF-8 (macOS sed needs the '' arg).
sed -i '' 's/charset=CHARSET/charset=UTF-8/' languages/order-list-enhancer.pot
```

Expected: `order-list-enhancer.pot` now contains every translatable admin string (including the new "Order total coloring" ones).

- [ ] **Step 2: Merge new strings into the Bulgarian catalog**

```bash
msgmerge --update --no-fuzzy-matching --no-wrap --backup=none \
  languages/order-list-enhancer-bg_BG.po languages/order-list-enhancer.pot
```

(`--no-fuzzy-matching` so newly added strings arrive as empty rather than guessed; existing translations are preserved.) Then update the header `Project-Id-Version` to `Order List Enhancer 1.0.24`.

- [ ] **Step 3: Translate every untranslated entry to Bulgarian**

List what still needs translating:

```bash
msgattrib --untranslated languages/order-list-enhancer-bg_BG.po | grep -c '^msgid '
msgattrib --untranslated languages/order-list-enhancer-bg_BG.po | grep '^msgid '
```

Translate each empty `msgstr` to idiomatic Bulgarian, matching the tone and terminology already in the file. Rules:
- Keep `%s`, `%1$s`, `%2$s`, `→`, `≥`, `—`, and any HTML entities (`&times;`, `&nbsp;`) **verbatim** in the translation.
- Keep proper nouns as-is where the existing file does (e.g. "Order List Enhancer", "WooCommerce", "Product Add-Ons").
- Cover the new "Order total coloring" section strings AND the pre-existing untranslated ones (Extras, default-bulk-action, any phone-validation leftovers) — i.e. **all** options and their value-labels.

- [ ] **Step 4: Compile and verify**

```bash
msgfmt --check --statistics -o languages/order-list-enhancer-bg_BG.mo languages/order-list-enhancer-bg_BG.po
msgattrib --untranslated languages/order-list-enhancer-bg_BG.po | grep -c '^msgid '   # expect 0
```

Expected: `msgfmt` prints "N translated messages" with **no** "untranslated" or "fuzzy" counts; the `msgattrib` count is `0`; `--check` passes (placeholder consistency OK).

- [ ] **Step 5: Commit**

```bash
git add languages/order-list-enhancer.pot languages/order-list-enhancer-bg_BG.po languages/order-list-enhancer-bg_BG.mo
git commit -m "i18n(bg_BG): translate all admin options + values; rebuild catalog

```

---

## Verification (whole feature)

- **Automated:** `php tests/order-color/test-rules.php` and `node tests/order-color/test-order-color-js.cjs` both `ALL PASS`.
- **Manual smoke (see Task 5 Step 4):** rings appear on qualifying list rows and the edit panel; highest threshold wins; fill + ring coexist; feature off = zero visual change.
- **Regression:** existing phone tests still pass; shipping fill, duplicate tinting, copy buttons, and the default-bulk-action feature behave exactly as before.
- **i18n:** `msgattrib --untranslated languages/order-list-enhancer-bg_BG.po` reports 0 strings; `msgfmt --check` passes; the new "Order total coloring" section renders in Bulgarian.

## Notes / decisions captured from the spec

- Thresholds compared in the store's own currency; `>=` semantics (a total equal to a threshold counts).
- Ring = `box-shadow: inset 0 0 0 3px <color>`, slightly rounded; applied inline (consistent with the shipping feature) — no CSS file change.
- One enable toggle covers both screens (per the design choice "Both list + edit").
- Equal-threshold rows: deterministic after the descending sort; not de-duped (only color differs).
