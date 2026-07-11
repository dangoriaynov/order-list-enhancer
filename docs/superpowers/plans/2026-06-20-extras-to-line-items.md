# Extras → Real Product Line Items — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** At order creation, automatically replace mapped WooCommerce add-on "extras" (Product Add-Ons selections and Checkout Add-Ons fees) with real product line items priced at exactly what the customer paid, with admin-only provenance so staff can verify what was converted.

**Architecture:** A new OLE module split into a pure-logic matcher (`OLE_Extras_Matcher`, no WordPress dependencies → unit-testable) and a WooCommerce integration class (`OLE_Extras`) that hooks order creation, mutates order items, writes provenance meta + an order note, and renders admin-only provenance. Conversion is a net-zero money move (add-on price/fee removed, equal product line added) so the order total never changes.

**Tech Stack:** PHP 7.4+, WooCommerce 8+ (HPOS enabled), WordPress 6.x. No build step. Pure-logic tests run with plain `php`; integration tests run via WP-CLI `eval` on the server.

## Global Constraints

- Plugin text domain: `order-list-enhancer`. Every user-facing string wrapped in `__()`/`esc_html_e()` with this domain.
- All files start with `if ( ! defined( 'ABSPATH' ) ) { exit; }` — EXCEPT nothing; the matcher keeps the guard too and tests `define('ABSPATH', true)` before requiring it.
- Provenance meta keys are `_`-prefixed (`_ole_addon_origin`, `_ole_extra_moved`, `_ole_extras_converted`) so WooCommerce/PIP never print them.
- Convert at order creation only; never call `WC_Order::calculate_totals()` (it would reprice from the catalog).
- Conversion must be idempotent (guard meta `_ole_extras_converted`).
- Server WP-CLI: `PHP=/opt/alt/php-fpm83/usr/bin/php; WP="$PHP /usr/local/bin/wp --allow-root"`, site root `/home/dobavki/public_html`. SSH: `ssh -p 6676 root@31.131.26.210`.
- Spec: `docs/superpowers/specs/2026-06-20-extras-to-line-items-design.md`.

---

## File Structure

- Create `includes/class-ole-extras-matcher.php` — pure logic: normalize, index, match, parse PA add-ons, parity. No WP calls.
- Create `includes/class-ole-extras.php` — WC integration: settings gate, hooks, `convert()`, admin rendering, order note.
- Create `tests/extras/test-matcher.php` — standalone pure-PHP unit tests for the matcher.
- Modify `includes/class-ole-settings.php` — add `extras_enabled`, `extras_map` to defaults + sanitize in `get()`.
- Modify `includes/class-ole-settings-page.php` — new "Extras → products" section + save handling + enqueue `wc-enhanced-select`.
- Modify `assets/js/ole-settings.js` — add/remove mapping rows + init WC product search.
- Modify `assets/css/ole-admin.css` — converted-line highlight on the order edit screen.
- Modify `order-list-enhancer.php` — require new classes, init `OLE_Extras`, bump version.

---

## Task 1: Settings — add `extras_enabled` + `extras_map`

**Files:**
- Modify: `includes/class-ole-settings.php`

**Interfaces:**
- Produces: `OLE_Settings::get()['extras_enabled']` (`'yes'|'no'`), `OLE_Settings::get()['extras_map']` (array of `['match'=>string,'product'=>int]`).

- [ ] **Step 1: Add defaults**

In `defaults()` add two keys after `bulk_default_action`:

```php
			'bulk_default_action' => '', // pre-selected entry in the orders-list bulk-actions menu ('' = none)
			'extras_enabled'      => 'no', // convert mapped add-on extras into real product lines at order creation
			'extras_map'          => array(), // [ ['match'=>'<extra label>','product'=>123], ... ]
```

- [ ] **Step 2: Sanitize in `get()`**

After the `bulk_default_action` sanitize line, add:

```php
		$opts['extras_map'] = self::clean_extras_map( $opts['extras_map'] );
```

- [ ] **Step 3: Add the `clean_extras_map` helper**

Add this static method to the class (after `bulk_actions()`):

```php
	/**
	 * Нормалізує таблицю відповідності екстра→товар: лишає лише рядки з текстом і product id > 0.
	 */
	public static function clean_extras_map( $rows ) {
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$match   = isset( $r['match'] ) ? sanitize_text_field( (string) $r['match'] ) : '';
			$product = isset( $r['product'] ) ? absint( $r['product'] ) : 0;
			if ( '' !== $match && $product > 0 ) {
				$out[] = array(
					'match'   => $match,
					'product' => $product,
				);
			}
		}
		return $out;
	}
```

- [ ] **Step 4: Verify (server, read-only) the option round-trips**

Run via SSH:

```bash
ssh -p 6676 root@31.131.26.210 'PHP=/opt/alt/php-fpm83/usr/bin/php; cd /home/dobavki/public_html && $PHP /usr/local/bin/wp --allow-root eval '"'"'$o=OLE_Settings::get(); echo $o["extras_enabled"]." | map=".count($o["extras_map"]);'"'"''
```

Expected: `no | map=0` (before deploy this won't reflect new code; this step is the post-deploy smoke check — run it again after Task 7 deploy).

- [ ] **Step 5: Commit**

```bash
git add includes/class-ole-settings.php
git commit -m "feat(extras): add extras_enabled + extras_map settings

```

---

## Task 2: Pure matcher (`OLE_Extras_Matcher`) with unit tests

**Files:**
- Create: `includes/class-ole-extras-matcher.php`
- Test: `tests/extras/test-matcher.php`

**Interfaces:**
- Produces:
  - `OLE_Extras_Matcher::normalize( string ) : string`
  - `OLE_Extras_Matcher::index( array $map ) : array` — `[ normalized_label => product_id ]`
  - `OLE_Extras_Matcher::match( array $index, string $label ) : int` — product id or `0`
  - `OLE_Extras_Matcher::parse_addons( $pao_ids ) : array` — `[ ['label'=>,'field'=>,'price'=>float,'price_type'=>,'id'=>], ... ]`
  - `OLE_Extras_Matcher::prices_balance( array $addons, float $pao_total, float $epsilon=0.01 ) : bool`

- [ ] **Step 1: Write the failing tests**

Create `tests/extras/test-matcher.php`:

```php
<?php
// Standalone unit tests for OLE_Extras_Matcher (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ole-extras-matcher.php';

$fails = 0;
function check( $cond, $msg ) {
	global $fails;
	if ( $cond ) { echo "ok   - $msg\n"; } else { echo "FAIL - $msg\n"; $fails++; }
}

// normalize: trim, collapse whitespace, lowercase (incl. Cyrillic).
check( OLE_Extras_Matcher::normalize( "  + 500 г  ЯНТАРНА  " ) === '+ 500 г янтарна', 'normalize trims/collapses/lowercases cyrillic' );

// index: keeps valid rows, drops empties / zero product.
$idx = OLE_Extras_Matcher::index( array(
	array( 'match' => '+ 500 г янтарна киселина', 'product' => 3907 ),
	array( 'match' => '',                          'product' => 10 ),
	array( 'match' => 'x',                         'product' => 0 ),
) );
check( count( $idx ) === 1 && $idx['+ 500 г янтарна киселина'] === 3907, 'index keeps only valid rows' );

// match: case/space-insensitive hit, miss returns 0.
check( OLE_Extras_Matcher::match( $idx, '+ 500 Г  Янтарна Киселина' ) === 3907, 'match is case/space-insensitive' );
check( OLE_Extras_Matcher::match( $idx, '+ unknown' ) === 0, 'match miss returns 0' );

// parse_addons: extract from _pao_ids array.
$pao = array(
	array( 'key' => 'Екстри', 'value' => '+ 16 бр pH тест ленти', 'id' => '169', 'raw_value' => '+ 16 бр pH тест ленти', 'raw_price' => 1, 'price_type' => 'flat_fee' ),
);
$parsed = OLE_Extras_Matcher::parse_addons( $pao );
check( count( $parsed ) === 1 && $parsed[0]['label'] === '+ 16 бр pH тест ленти' && $parsed[0]['price'] === 1.0 && $parsed[0]['field'] === 'Екстри', 'parse_addons extracts fields' );
check( OLE_Extras_Matcher::parse_addons( 'not-array' ) === array(), 'parse_addons tolerates non-array' );

// prices_balance.
check( OLE_Extras_Matcher::prices_balance( $parsed, 1.0 ) === true, 'prices_balance true when equal' );
check( OLE_Extras_Matcher::prices_balance( $parsed, 2.0 ) === false, 'prices_balance false when off' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run (uses any local `php`, or the server PHP via SSH):

```bash
php tests/extras/test-matcher.php
```

Expected: FAIL — `require` error, `class-ole-extras-matcher.php` does not exist yet. (If no local PHP: `ssh -p 6676 root@31.131.26.210 'cd /home/dobavki/public_html/wp-content/plugins/order-list-enhancer && /opt/alt/php-fpm83/usr/bin/php tests/extras/test-matcher.php'` after copying the test up — but locally the missing-file failure is enough to confirm the test runs.)

- [ ] **Step 3: Implement the matcher**

Create `includes/class-ole-extras-matcher.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Чиста логіка співставлення екстри з товаром (без WordPress) — повністю юніт-тестована.
 */
class OLE_Extras_Matcher {

	/** Нормалізує текст ярлика: trim, стиснуті пробіли, нижній регістр. */
	public static function normalize( $s ) {
		$s = preg_replace( '/\s+/u', ' ', (string) $s );
		$s = trim( $s );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/** Будує індекс normalized_label => product_id з рядків мапінгу. */
	public static function index( $map ) {
		$idx = array();
		if ( ! is_array( $map ) ) {
			return $idx;
		}
		foreach ( $map as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$m = isset( $row['match'] ) ? self::normalize( $row['match'] ) : '';
			$p = isset( $row['product'] ) ? (int) $row['product'] : 0;
			if ( '' !== $m && $p > 0 ) {
				$idx[ $m ] = $p;
			}
		}
		return $idx;
	}

	/** Шукає ярлик в індексі. Повертає product id або 0. */
	public static function match( $index, $label ) {
		$n = self::normalize( $label );
		return ( is_array( $index ) && isset( $index[ $n ] ) ) ? (int) $index[ $n ] : 0;
	}

	/** Розбирає масив _pao_ids у список екстр. */
	public static function parse_addons( $pao_ids ) {
		$out = array();
		if ( ! is_array( $pao_ids ) ) {
			return $out;
		}
		foreach ( $pao_ids as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$out[] = array(
				'label'      => isset( $a['value'] ) ? (string) $a['value'] : '',
				'field'      => isset( $a['key'] ) ? (string) $a['key'] : '',
				'price'      => isset( $a['raw_price'] ) ? (float) $a['raw_price'] : 0.0,
				'price_type' => isset( $a['price_type'] ) ? (string) $a['price_type'] : '',
				'id'         => isset( $a['id'] ) ? (string) $a['id'] : '',
			);
		}
		return $out;
	}

	/** Перевірка балансу: сума цін екстр == _pao_total (в межах epsilon). */
	public static function prices_balance( $addons, $pao_total, $epsilon = 0.01 ) {
		$sum = 0.0;
		foreach ( (array) $addons as $a ) {
			$sum += isset( $a['price'] ) ? (float) $a['price'] : 0.0;
		}
		return abs( $sum - (float) $pao_total ) <= $epsilon;
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/extras/test-matcher.php`
Expected: `ALL PASS`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ole-extras-matcher.php tests/extras/test-matcher.php
git commit -m "feat(extras): pure matcher + unit tests

```

---

## Task 3: Settings page — "Extras → products" section

**Files:**
- Modify: `includes/class-ole-settings-page.php`
- Modify: `assets/js/ole-settings.js`

**Interfaces:**
- Consumes: `OLE_Settings::get()['extras_enabled'|'extras_map']`, `OLE_Settings::clean_extras_map()`.
- Produces: POST fields `extras_enabled`, `extra_match[]`, `extra_product[]` saved into `extras_map`.

- [ ] **Step 1: Enqueue WC product search on the settings screen**

In `assets()`, after the existing `wp_enqueue_script( 'ole-settings', ... )` line, add:

```php
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
```

- [ ] **Step 2: Render the section**

In `render()`, immediately before `<h2><?php esc_html_e( 'Phone numbers', ... ); ?></h2>`, insert:

```php
				<h2><?php esc_html_e( 'Extras → products', 'order-list-enhancer' ); ?></h2>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable conversion', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="extras_enabled" <?php echo $cb( 'extras_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'At order creation, turn each mapped add-on extra into a real product line at the price the customer paid. Order total is unchanged.', 'order-list-enhancer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mapping (extra → product)', 'order-list-enhancer' ); ?></th>
						<td>
							<?php
							$emap = $o['extras_map'];
							if ( empty( $emap ) ) {
								$emap = array( array( 'match' => '', 'product' => 0 ) );
							}
							?>
							<table class="widefat ole-extras" style="max-width:680px"><thead><tr>
								<th style="text-align:center"><?php esc_html_e( 'Extra text (as shown on the order)', 'order-list-enhancer' ); ?></th>
								<th style="text-align:center"><?php esc_html_e( 'Product', 'order-list-enhancer' ); ?></th>
								<th></th>
							</tr></thead><tbody>
							<?php
							foreach ( $emap as $row ) :
								$pid     = isset( $row['product'] ) ? (int) $row['product'] : 0;
								$product = $pid ? wc_get_product( $pid ) : null;
								?>
								<tr>
									<td><input type="text" name="extra_match[]" value="<?php echo esc_attr( $row['match'] ); ?>" class="regular-text" placeholder="+ 500 г янтарна киселина"/></td>
									<td>
										<select class="wc-product-search ole-extra-product" name="extra_product[]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'order-list-enhancer' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:320px">
											<?php if ( $product ) : ?>
												<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
											<?php endif; ?>
										</select>
									</td>
									<td><button type="button" class="button ole-extra-remove">&times;</button></td>
								</tr>
							<?php endforeach; ?>
							</tbody></table>
							<p><button type="button" class="button ole-extra-add"><?php esc_html_e( 'Add row', 'order-list-enhancer' ); ?></button></p>
							<p class="description"><?php esc_html_e( 'Match is the exact extra label as it appears on the order/checkout (Product Add-On label like “+ 500 г …”, or the Checkout Add-On name). The product line will be priced at what the customer paid for that extra.', 'order-list-enhancer' ); ?></p>
						</td>
					</tr>
				</tbody></table>

```

- [ ] **Step 3: Save the fields**

In `ajax_save()`, build the map before the `$opts = array( ... )` assignment:

```php
		$extras_map = array();
		if ( isset( $in['extra_match'] ) && is_array( $in['extra_match'] ) ) {
			$eprod = isset( $in['extra_product'] ) ? (array) $in['extra_product'] : array();
			foreach ( $in['extra_match'] as $i => $mtext ) {
				$extras_map[] = array(
					'match'   => $mtext,
					'product' => isset( $eprod[ $i ] ) ? $eprod[ $i ] : 0,
				);
			}
		}
		$extras_map = OLE_Settings::clean_extras_map( $extras_map );
```

Then add these two keys inside the `$opts = array( ... )`:

```php
			'extras_enabled'      => $bool( 'extras_enabled' ),
			'extras_map'          => $extras_map,
```

- [ ] **Step 4: Row add/remove + product-search init (JS)**

Append to `assets/js/ole-settings.js` (inside its existing jQuery ready wrapper; if the file has none, wrap this in `jQuery(function($){ ... });`):

```javascript
	// Extras mapping: add/remove rows + (re)init WC product search.
	function oleInitProductSearch( $scope ) {
		( $scope || jQuery( '.ole-extras' ) ).find( 'select.wc-product-search' ).each( function () {
			var $s = jQuery( this );
			if ( $s.data( 'select2' ) ) { return; }
			if ( jQuery.fn.selectWoo ) {
				$s.selectWoo( {
					ajax: {
						url: ( window.wc_enhanced_select_params && window.ajaxurl ) || window.ajaxurl,
						dataType: 'json',
						delay: 250,
						data: function ( params ) {
							return { term: params.term, action: 'woocommerce_json_search_products_and_variations', security: ( window.wc_enhanced_select_params || {} ).search_products_nonce };
						},
						processResults: function ( data ) {
							var out = [];
							jQuery.each( data, function ( id, text ) { out.push( { id: id, text: text } ); } );
							return { results: out };
						}
					},
					minimumInputLength: 2,
					width: '320px'
				} );
			}
		} );
	}
	jQuery( function () {
		oleInitProductSearch();
		jQuery( document ).on( 'click', '.ole-extra-add', function ( e ) {
			e.preventDefault();
			var $tbody = jQuery( '.ole-extras tbody' );
			var $row = $tbody.find( 'tr' ).first().clone();
			$row.find( 'input' ).val( '' );
			$row.find( 'select' ).val( null ).empty();
			$tbody.append( $row );
			oleInitProductSearch( $row.closest( '.ole-extras' ) );
		} );
		jQuery( document ).on( 'click', '.ole-extra-remove', function ( e ) {
			e.preventDefault();
			var $rows = jQuery( '.ole-extras tbody tr' );
			if ( $rows.length > 1 ) { jQuery( this ).closest( 'tr' ).remove(); }
			else { jQuery( this ).closest( 'tr' ).find( 'input' ).val( '' ); jQuery( this ).closest( 'tr' ).find( 'select' ).val( null ); }
		} );
	} );
```

- [ ] **Step 5: Lint + manual verify (post-deploy, after Task 7)**

Run on server: `/opt/alt/php-fpm83/usr/bin/php -l wp-content/plugins/order-list-enhancer/includes/class-ole-settings-page.php` → `No syntax errors`.
Manual: open WooCommerce → Order List Enhancer → "Extras → products"; add a row, search a product, save, reload → row persists with the product selected.

- [ ] **Step 6: Commit**

```bash
git add includes/class-ole-settings-page.php assets/js/ole-settings.js
git commit -m "feat(extras): settings UI — enable toggle + extra→product mapping table

```

---

## Task 4: Conversion engine — Product Add-Ons path

**Files:**
- Create: `includes/class-ole-extras.php`
- Test: integration via WP-CLI `eval` (server)

**Interfaces:**
- Consumes: `OLE_Extras_Matcher::*`, `OLE_Settings::get()`.
- Produces:
  - `OLE_Extras::convert( WC_Order $order ) : int` — number of extras converted; idempotent.
  - `OLE_Extras::add_product_line( WC_Order $order, int $product_id, float $price, array $origin ) : int` — new item id.

- [ ] **Step 1: Write the integration test (server eval script)**

Create `tests/extras/it-product-addons.php` (run with WP-CLI `eval-file` on the server):

```php
<?php
// Integration test: a synthetic order with a Product Add-On is converted.
// Run: $WP eval-file wp-content/plugins/order-list-enhancer/tests/extras/it-product-addons.php
$fails = 0;
function ck( $c, $m ) { global $fails; echo ( $c ? "ok   - " : "FAIL - " ) . "$m\n"; if ( ! $c ) { $fails++; } }

// Pick a real published product to map to.
$target = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'return' => 'ids' ) );
$target_id = $target ? (int) $target[0] : 0;
ck( $target_id > 0, 'found a target product' );

// Force a mapping in settings (in-memory for this run).
$opts = OLE_Settings::get();
$opts['extras_enabled'] = 'yes';
$opts['extras_map']     = array( array( 'match' => '+ TEST EXTRA pH', 'product' => $target_id ) );
update_option( OLE_Settings::OPTION, $opts );

// Build a draft order: one line item priced 5.50 base + 1.00 add-on = 6.50.
$order = wc_create_order();
$prod  = wc_get_product( $target_id );
$item  = new WC_Order_Item_Product();
$item->set_product( $prod );
$item->set_quantity( 1 );
$item->set_subtotal( 6.50 );
$item->set_total( 6.50 );
$item->add_meta_data( 'Екстри', '+ TEST EXTRA pH', true );
$item->add_meta_data( '_pao_ids', array( array( 'key' => 'Екстри', 'value' => '+ TEST EXTRA pH', 'id' => '1', 'raw_value' => '+ TEST EXTRA pH', 'raw_price' => 1, 'price_type' => 'flat_fee' ) ), true );
$item->add_meta_data( '_pao_total', 1, true );
$order->add_item( $item );
$order->set_total( 6.50 );
$order->save();
$before_total = (float) $order->get_total();

$n = OLE_Extras::convert( $order );
ck( $n === 1, 'convert reports 1 extra' );

$order = wc_get_order( $order->get_id() );
$lines = $order->get_items( 'line_item' );
ck( count( $lines ) === 2, 'order now has 2 line items' );

$parent = null; $child = null;
foreach ( $lines as $li ) {
	if ( $li->get_meta( '_ole_addon_origin' ) ) { $child = $li; } else { $parent = $li; }
}
ck( $parent && abs( (float) $parent->get_total() - 5.50 ) < 0.001, 'parent total reduced to 5.50' );
ck( $parent && '' === $parent->get_meta( 'Екстри' ), 'parent visible add-on meta removed' );
ck( $child && abs( (float) $child->get_total() - 1.00 ) < 0.001, 'new line priced 1.00' );
ck( abs( (float) $order->get_total() - $before_total ) < 0.001, 'order total unchanged' );
ck( (int) $order->get_meta( '_ole_extras_converted' ) === 1, 'idempotency guard set' );

// Idempotent: second run does nothing.
$n2 = OLE_Extras::convert( $order );
ck( $n2 === 0, 'second convert is a no-op' );

// Cleanup.
$order->delete( true );
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
ssh -p 6676 root@31.131.26.210 'PHP=/opt/alt/php-fpm83/usr/bin/php; cd /home/dobavki/public_html && $PHP /usr/local/bin/wp --allow-root eval-file wp-content/plugins/order-list-enhancer/tests/extras/it-product-addons.php'
```
Expected: fatal error — `Class "OLE_Extras" not found` (class not created/deployed yet).

- [ ] **Step 3: Implement `OLE_Extras` (PA path + helpers)**

Create `includes/class-ole-extras.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Перетворює зіставлені екстри (Product Add-Ons / Checkout Add-Ons) на окремі товарні рядки
 * при створенні замовлення. Чиста логіка — в [[OLE_Extras_Matcher]].
 */
class OLE_Extras {

	/** Реєстрація хуків (викликається з OLE_Plugin, якщо фіча увімкнена). */
	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_processed' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order_processed' ), 20, 1 );
	}

	public static function on_order_processed( $order ) {
		$order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order );
		if ( $order ) {
			self::convert( $order );
		}
	}

	/**
	 * Головна конвертація. Повертає к-сть перетворених екстр. Ідемпотентна.
	 */
	public static function convert( WC_Order $order ) {
		if ( $order->get_meta( '_ole_extras_converted' ) ) {
			return 0;
		}
		$opts = OLE_Settings::get();
		if ( ! OLE_Settings::is_yes( $opts, 'extras_enabled' ) ) {
			return 0;
		}
		$index = OLE_Extras_Matcher::index( $opts['extras_map'] );
		if ( empty( $index ) ) {
			return 0;
		}

		$notes = array();
		$count = self::convert_product_addons( $order, $index, $notes );
		// (Checkout Add-Ons handled in Task 5: $count += self::convert_checkout_addons(...).)

		if ( $count > 0 ) {
			$order->add_order_note( __( 'OLE — extras converted to product lines:', 'order-list-enhancer' ) . "\n" . implode( "\n", $notes ) );
			$order->update_meta_data( '_ole_extras_converted', 1 );
			$order->save();
		}
		return $count;
	}

	/** Конвертує Product Add-Ons на товарних рядках. */
	private static function convert_product_addons( WC_Order $order, $index, &$notes ) {
		$count = 0;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$pao = $item->get_meta( '_pao_ids' );
			if ( ! is_array( $pao ) || empty( $pao ) ) {
				continue;
			}
			$addons    = OLE_Extras_Matcher::parse_addons( $pao );
			$pao_total = (float) $item->get_meta( '_pao_total' );

			// Safety: only proceed if the parsed add-on prices reconcile with _pao_total.
			if ( ! OLE_Extras_Matcher::prices_balance( $addons, $pao_total ) ) {
				continue;
			}

			$keep_pao   = array();   // _pao_ids entries to keep (unconverted)
			$moved      = array();   // provenance for the parent
			$drop_label = array();   // visible field=>label rows to drop

			foreach ( $addons as $idx => $a ) {
				$pid = OLE_Extras_Matcher::match( $index, $a['label'] );
				if ( ! $pid || 'flat_fee' !== $a['price_type'] ) {
					$keep_pao[] = $pao[ $idx ];
					continue;
				}
				$price   = (float) $a['price'];
				$new_id  = self::add_product_line( $order, $pid, $price, array(
					'source'   => 'pa',
					'label'    => $a['label'],
					'price'    => $price,
					'src_item' => $item_id,
				) );
				// Reduce parent line by the add-on price.
				$item->set_subtotal( (float) $item->get_subtotal() - $price );
				$item->set_total( (float) $item->get_total() - $price );
				$drop_label[ $a['field'] ][] = $a['label'];
				$moved[] = array( 'label' => $a['label'], 'price' => $price, 'item' => $new_id );
				$notes[] = sprintf( '«%s» → %s (%s)', $a['label'], self::product_name( $pid ), self::money( $price, $order ) );
				++$count;
			}

			if ( empty( $moved ) ) {
				continue;
			}

			// Rewrite _pao_ids / _pao_total to keep only unconverted add-ons.
			$kept_total = 0.0;
			foreach ( $keep_pao as $k ) {
				$kept_total += isset( $k['raw_price'] ) ? (float) $k['raw_price'] : 0.0;
			}
			if ( $keep_pao ) {
				$item->update_meta_data( '_pao_ids', array_values( $keep_pao ) );
				$item->update_meta_data( '_pao_total', $kept_total );
			} else {
				$item->delete_meta_data( '_pao_ids' );
				$item->delete_meta_data( '_pao_total' );
			}

			// Remove the visible field=>label rows for converted add-ons (keep unconverted values).
			foreach ( $drop_label as $field => $converted_vals ) {
				$remaining = array();
				foreach ( $item->get_meta( $field, false ) as $meta ) {
					if ( ! in_array( (string) $meta->value, $converted_vals, true ) ) {
						$remaining[] = (string) $meta->value;
					}
				}
				$item->delete_meta_data( $field );
				foreach ( $remaining as $v ) {
					$item->add_meta_data( $field, $v, false );
				}
			}

			// Provenance for admin display (hidden from invoices).
			$item->update_meta_data( '_ole_extra_moved', $moved );
			$item->save();
		}
		return $count;
	}

	/** Додає новий товарний рядок із заданою ціною та provenance-метою. */
	public static function add_product_line( WC_Order $order, $product_id, $price, $origin ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return 0;
		}
		$line = new WC_Order_Item_Product();
		$line->set_product( $product );
		$line->set_quantity( 1 );
		$line->set_subtotal( (float) $price );
		$line->set_total( (float) $price );
		$line->set_subtotal_tax( 0 );
		$line->set_total_tax( 0 );
		$line->add_meta_data( '_ole_addon_origin', $origin, true );
		$order->add_item( $line );
		$line->save();

		// If stock was already reduced for this order, reduce the new product manually.
		if ( 'yes' === $order->get_meta( '_order_stock_reduced' ) && $product->managing_stock() ) {
			wc_update_product_stock( $product, 1, 'decrease' );
		}
		return $line->get_id();
	}

	private static function product_name( $product_id ) {
		$p = wc_get_product( $product_id );
		return $p ? wp_strip_all_tags( $p->get_formatted_name() ) : ( '#' . (int) $product_id );
	}

	private static function money( $amount, WC_Order $order ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ) );
	}
}
```

- [ ] **Step 4: Deploy the two files + test files to the server and run the integration test**

```bash
rsync -az --no-perms --no-owner --no-group -e "ssh -p 6676" \
  includes/class-ole-extras.php includes/class-ole-extras-matcher.php \
  root@31.131.26.210:/home/dobavki/public_html/wp-content/plugins/order-list-enhancer/includes/
rsync -az --no-perms --no-owner --no-group -e "ssh -p 6676" \
  tests/ root@31.131.26.210:/home/dobavki/public_html/wp-content/plugins/order-list-enhancer/tests/
ssh -p 6676 root@31.131.26.210 'PHP=/opt/alt/php-fpm83/usr/bin/php; cd /home/dobavki/public_html && $PHP /usr/local/bin/wp --allow-root eval "require \"wp-content/plugins/order-list-enhancer/includes/class-ole-extras-matcher.php\"; require \"wp-content/plugins/order-list-enhancer/includes/class-ole-extras.php\";" 2>&1 | head; $PHP /usr/local/bin/wp --allow-root eval-file wp-content/plugins/order-list-enhancer/tests/extras/it-product-addons.php'
```
Expected: `ALL PASS`. (Note: `OLE_Extras`/matcher must be loadable; once Task 7 wires the `require` into the plugin bootstrap, the explicit requires here are unnecessary. Until then run the test after Task 7, or add temporary requires in the eval.)

- [ ] **Step 5: Commit**

```bash
git add includes/class-ole-extras.php tests/extras/it-product-addons.php
git commit -m "feat(extras): convert Product Add-Ons into real product lines at order creation

```

---

## Task 5: Conversion engine — Checkout Add-Ons (fees) path

**Files:**
- Modify: `includes/class-ole-extras.php`
- Test: `tests/extras/it-checkout-addons.php`

**Interfaces:**
- Consumes: `OLE_Extras::add_product_line()`, `OLE_Extras_Matcher::match()`.
- Produces: `OLE_Extras::convert_checkout_addons( WC_Order, array $index, array &$notes ) : int` (private).

- [ ] **Step 1: Write the integration test**

Create `tests/extras/it-checkout-addons.php`:

```php
<?php
// Integration test: a synthetic order with a Checkout Add-On fee is converted.
$fails = 0;
function ck( $c, $m ) { global $fails; echo ( $c ? "ok   - " : "FAIL - " ) . "$m\n"; if ( ! $c ) { $fails++; } }

$target = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'return' => 'ids' ) );
$target_id = $target ? (int) $target[0] : 0;
ck( $target_id > 0, 'found a target product' );

$opts = OLE_Settings::get();
$opts['extras_enabled'] = 'yes';
$opts['extras_map']     = array( array( 'match' => 'TEST FEE EXTRA', 'product' => $target_id ) );
update_option( OLE_Settings::OPTION, $opts );

$order = wc_create_order();
$prod  = wc_get_product( $target_id );
$item  = new WC_Order_Item_Product();
$item->set_product( $prod ); $item->set_quantity( 1 ); $item->set_subtotal( 10.0 ); $item->set_total( 10.0 );
$order->add_item( $item );

$fee = new WC_Order_Item_Fee();
$fee->set_name( 'TEST FEE EXTRA' );
$fee->set_amount( 5.5 );
$fee->set_total( 5.5 );
$fee->set_tax_status( 'none' );
$order->add_item( $fee );
$order->set_total( 15.5 );
$order->save();
$before_total = (float) $order->get_total();

$n = OLE_Extras::convert( $order );
ck( $n === 1, 'convert reports 1 extra' );

$order = wc_get_order( $order->get_id() );
ck( count( $order->get_items( 'fee' ) ) === 0, 'fee removed' );
$lines = $order->get_items( 'line_item' );
ck( count( $lines ) === 2, 'order now has 2 product lines' );
$child = null;
foreach ( $lines as $li ) { if ( $li->get_meta( '_ole_addon_origin' ) ) { $child = $li; } }
ck( $child && abs( (float) $child->get_total() - 5.5 ) < 0.001, 'new line priced 5.50' );
ck( abs( (float) $order->get_total() - $before_total ) < 0.001, 'order total unchanged' );

$order->delete( true );
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
ssh -p 6676 root@31.131.26.210 'PHP=/opt/alt/php-fpm83/usr/bin/php; cd /home/dobavki/public_html && $PHP /usr/local/bin/wp --allow-root eval-file wp-content/plugins/order-list-enhancer/tests/extras/it-checkout-addons.php'
```
Expected: FAIL — `convert reports 1 extra` fails (returns 0; fee path not implemented; fee still present).

- [ ] **Step 3: Implement the fee path**

In `convert()`, replace the comment line with the call:

```php
		$count = self::convert_product_addons( $order, $index, $notes );
		$count += self::convert_checkout_addons( $order, $index, $notes );
```

Add the method to the class:

```php
	/** Конвертує Checkout Add-Ons (fee-рядки). */
	private static function convert_checkout_addons( WC_Order $order, $index, &$notes ) {
		$count = 0;
		foreach ( $order->get_items( 'fee' ) as $fee_id => $fee ) {
			$pid = OLE_Extras_Matcher::match( $index, $fee->get_name() );
			if ( ! $pid ) {
				continue;
			}
			$price  = (float) $fee->get_total();
			$new_id = self::add_product_line( $order, $pid, $price, array(
				'source'   => 'ca',
				'label'    => $fee->get_name(),
				'price'    => $price,
				'src_item' => $fee->get_name(),
			) );
			$order->remove_item( $fee_id );
			$notes[] = sprintf( '«%s» → %s (%s)', $fee->get_name(), self::product_name( $pid ), self::money( $price, $order ) );
			++$count;
		}
		return $count;
	}
```

- [ ] **Step 4: Deploy + run both integration tests**

```bash
rsync -az --no-perms --no-owner --no-group -e "ssh -p 6676" includes/class-ole-extras.php tests/ root@31.131.26.210:/home/dobavki/public_html/wp-content/plugins/order-list-enhancer/
# note: rsync of tests/ targets the plugin tests dir
ssh -p 6676 root@31.131.26.210 'PHP=/opt/alt/php-fpm83/usr/bin/php; cd /home/dobavki/public_html && for t in it-product-addons it-checkout-addons; do echo "== $t =="; $PHP /usr/local/bin/wp --allow-root eval-file wp-content/plugins/order-list-enhancer/tests/extras/$t.php; done'
```
Expected: both `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ole-extras.php tests/extras/it-checkout-addons.php
git commit -m "feat(extras): convert Checkout Add-On fees into product lines

```

---

## Task 6: Admin provenance rendering + highlight

**Files:**
- Modify: `includes/class-ole-extras.php`
- Modify: `assets/css/ole-admin.css`

**Interfaces:**
- Consumes: item meta `_ole_addon_origin`, `_ole_extra_moved`.
- Produces: admin-only rows under order items + `woocommerce_hidden_order_itemmeta` entries.

- [ ] **Step 1: Register admin hooks in `init()`**

Append inside `init()`:

```php
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'render_item_provenance' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_itemmeta' ) );
```

- [ ] **Step 2: Implement rendering + hidden-meta filter**

Add to the class:

```php
	/** Ховає сирі _ole_* ключі зі стандартного відображення метаданих рядка. */
	public static function hidden_itemmeta( $keys ) {
		$keys[] = '_ole_addon_origin';
		$keys[] = '_ole_extra_moved';
		return $keys;
	}

	/** Адмін-підказка під рядком: звідки сконвертовано / що винесено. Лише в адмінці. */
	public static function render_item_provenance( $item_id, $item ) {
		if ( ! is_a( $item, 'WC_Order_Item' ) ) {
			return;
		}
		$origin = $item->get_meta( '_ole_addon_origin' );
		if ( is_array( $origin ) && ! empty( $origin['label'] ) ) {
			printf(
				'<div class="ole-prov ole-prov--from">↩ %s</div>',
				esc_html( sprintf( __( 'Converted from extra: «%1$s» (was %2$s)', 'order-list-enhancer' ), $origin['label'], wc_format_localized_price( isset( $origin['price'] ) ? $origin['price'] : 0 ) ) )
			);
		}
		$moved = $item->get_meta( '_ole_extra_moved' );
		if ( is_array( $moved ) ) {
			foreach ( $moved as $m ) {
				if ( empty( $m['label'] ) ) {
					continue;
				}
				printf(
					'<div class="ole-prov ole-prov--moved">➡ %s</div>',
					esc_html( sprintf( __( 'Extra «%s» moved to its own line', 'order-list-enhancer' ), $m['label'] ) )
				);
			}
		}
	}
```

- [ ] **Step 3: Highlight CSS**

Append to `assets/css/ole-admin.css`:

```css
/* Extras → products provenance (admin order screen, admin-only) */
.ole-prov { margin-top: 4px; font-size: 11px; line-height: 1.5; }
.ole-prov--from { color: #1a7a3c; }
.ole-prov--moved { color: #8a6d00; }
.woocommerce_order_items tr:has(.ole-prov--from) td { background: rgba(26,122,60,.06); }
```

- [ ] **Step 4: Deploy + manual verify**

Deploy (rsync of `includes/` + `assets/`), then open the order created/converted via the Task 4/5 manual run (or a real converted order) on the edit screen. Expect: under the new product line `↩ Converted from extra: «…»`; under the parent `➡ Extra «…» moved…`; converted line subtly green-tinted. Then **print the PIP invoice + pick list** for that order → confirm **no `↩/➡/_ole_*` text**, only the real product lines.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ole-extras.php assets/css/ole-admin.css
git commit -m "feat(extras): admin-only provenance under converted order items

```

---

## Task 7: Wire-up, gate, version bump, deploy, end-to-end

**Files:**
- Modify: `order-list-enhancer.php`
- Modify: `includes/class-ole-plugin.php`

**Interfaces:**
- Consumes: `OLE_Extras::init()`, `OLE_Settings::is_yes()`.

- [ ] **Step 1: Require the new classes**

In `order-list-enhancer.php`, add after the existing `require_once` for `class-ole-order-total.php`:

```php
require_once OLE_DIR . 'includes/class-ole-extras-matcher.php';
require_once OLE_DIR . 'includes/class-ole-extras.php';
```

- [ ] **Step 2: Gate + init in the plugin bootstrap**

In `class-ole-plugin.php` constructor, after the `new OLE_Settings_Page();` line, add:

```php
		$opts = OLE_Settings::get();
		if ( OLE_Settings::is_yes( $opts, 'extras_enabled' ) ) {
			OLE_Extras::init();
		}
```

(If the constructor already calls `OLE_Settings::get()` later for phone normalization, reuse a single `$opts` variable to avoid a duplicate call.)

- [ ] **Step 3: Bump version**

In `order-list-enhancer.php`, change `Version:` header and `OLE_VERSION` from `1.0.14` to `1.0.15`.

- [ ] **Step 4: Deploy + lint + run all tests**

```bash
rsync -az --no-perms --no-owner --no-group --exclude='.git*' --exclude='.wordpress-org' -e "ssh -p 6676" \
  ./ root@31.131.26.210:/home/dobavki/public_html/wp-content/plugins/order-list-enhancer/
ssh -p 6676 root@31.131.26.210 'PHP=/opt/alt/php-fpm83/usr/bin/php; WP="$PHP /usr/local/bin/wp --allow-root"; cd /home/dobavki/public_html && \
  for f in order-list-enhancer.php includes/class-ole-extras.php includes/class-ole-extras-matcher.php includes/class-ole-plugin.php includes/class-ole-settings.php includes/class-ole-settings-page.php; do $PHP -l wp-content/plugins/order-list-enhancer/$f; done && \
  $PHP wp-content/plugins/order-list-enhancer/tests/extras/test-matcher.php && \
  for t in it-product-addons it-checkout-addons; do echo "== $t =="; $WP eval-file wp-content/plugins/order-list-enhancer/tests/extras/$t.php; done && \
  $WP cache flush && $WP transient delete --all && rm -rf wp-content/cache/asset-cleanup/* wp-content/cache/wp-rocket/*'
```
Expected: all `No syntax errors`, matcher `ALL PASS`, both integration tests `ALL PASS`.

- [ ] **Step 5: End-to-end on a real test order**

Enable the feature (WooCommerce → Order List Enhancer → Extras → products: tick Enable; add one mapping for a real extra you can reproduce, e.g. `+ 16 бр pH тест ленти (5 мм х 50 мм)` → its product). Place a **real test order** on the storefront choosing that extra. Then verify on the order edit screen: the extra is a separate product line at the paid price; parent price dropped accordingly; order total equals what the customer paid; admin provenance shows; stock decremented. Print invoice + pick list → only real product lines, no provenance text. Refund/trash the test order afterward.

- [ ] **Step 6: Commit**

```bash
git add order-list-enhancer.php includes/class-ole-plugin.php
git commit -m "feat(extras): wire up + gate the extras-to-products module; bump to 1.0.15

```

---

## Self-Review notes

- **Spec coverage:** mapping table (T1/T3) ✓; convert-at-order PA (T4) ✓; CA fees (T5) ✓; price = charged amount (T4/T5) ✓; net-zero total, no `calculate_totals()` (T4) ✓; remove visible parent add-on meta (T4) ✓; provenance meta + order note (T4) ✓; admin-only rendering + hidden meta + highlight (T6) ✓; invoice safety (verified, asserted in T6 manual + T7 E2E) ✓; stock (T4 `add_product_line`) ✓; idempotency guard (T4) ✓; parity safety skip (T4) ✓; flat_fee-only with skip of other price types (T4) ✓; bundles out of scope ✓.
- **Type consistency:** `convert()→int`, `add_product_line()→int item id`, matcher signatures match across T2/T4/T5. `_ole_addon_origin` is an array everywhere; `_ole_extra_moved` is an array of `{label,price,item}`.
- **Known v1 limitations (from spec):** parent qty>1 uses qty-1 line at exact charged total (money parity, packing qty noted); non-`flat_fee` PA price types are skipped; no retroactive conversion of historical orders (phase 2 button deferred).
