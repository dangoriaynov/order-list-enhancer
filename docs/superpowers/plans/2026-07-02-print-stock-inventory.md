# Print-consumables Inventory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track printed **stickers** (per product/variation, −qty) and **instruction sheets** (shared across products, −1 per order) as their own inventory, auto-decremented at order placement and restored when an order dies, with per-type low thresholds surfaced via a stock page, admin banner, order-list badge, and email.

**Architecture:** Three custom tables (`ole_consumable`, `ole_consumable_product`, `ole_consume_log`) hold consumables, sheet↔product links, and a movement ledger. A pure calculator (`OLE_Print_Stock_Calc`) turns order line items + config into per-consumable deltas; a DB layer (`OLE_Print_Stock_Store`) applies them atomically and logs them; a WP-glue class (`OLE_Print_Stock`) reconciles consume/restore on order-status transitions and drives the four notification channels; an admin class (`OLE_Print_Stock_Admin`) renders the stock page.

**Tech Stack:** PHP 7.4+, WordPress/WooCommerce (HPOS-compatible), `$wpdb` + `dbDelta`, vanilla-JS admin assets, standalone PHP unit tests (no framework), gettext for bg_BG.

## Global Constraints

- Text domain: `order-list-enhancer` — every user-facing string wrapped in `__()`/`esc_html__()` etc.
- New admin/notification strings + email copy MUST be translated to `bg_BG` and the `.mo` rebuilt (`languages/order-list-enhancer-bg_BG.po` → `.mo`).
- Feature ships **off**: master toggle `print_stock_enabled` default `no`; wired in `OLE_Plugin::__construct()` exactly like the other features.
- HPOS-safe: never assume the posts table for orders; use `wc_get_order`, order objects, and both HPOS + legacy column hooks.
- Deploy is manual rsync **without** deactivate/activate, so schema install MUST be driven by a DB-version option check on every enabled request — never rely on `register_activation_hook` alone.
- No local WordPress is available: only the pure `OLE_Print_Stock_Calc` is unit-tested locally (`php tests/print-stock/test-calc.php`); every WP-glue task is verified on the live site after deploy per the OLE deploy procedure.
- Class prefix `OLE_`, file names `includes/class-ole-*.php`, matching the existing code style. Bump `OLE_VERSION` when assets change (cache-bust).
- Stock is a signed integer and **may go negative**. Thresholds are per type: stickers default `20`, instructions default `5`.
- Consumption snapshot is taken at first consume; later manual edits to an order's line items do NOT re-compute (documented v1 limitation). Restore reverses whatever the ledger currently has applied for that order.

---

## File Structure

- **Create `includes/class-ole-print-stock-calc.php`** — `OLE_Print_Stock_Calc`: pure functions `compute()`, `crosses_low()`, `is_live()`. No WordPress. Unit-tested.
- **Create `includes/class-ole-print-stock-store.php`** — `OLE_Print_Stock_Store`: table names, `install()`/`maybe_upgrade()`, consumable + sheet CRUD, `apply_delta()`, `ledger_net()`, config + query helpers. All `$wpdb`.
- **Create `includes/class-ole-print-stock.php`** — `OLE_Print_Stock`: `init()` (hook wiring + `maybe_upgrade`), `reconcile()`, order-item extraction, config builder, notification handlers (banner, order-list badge column, email), product/variation sticker fields.
- **Create `includes/class-ole-print-stock-admin.php`** — `OLE_Print_Stock_Admin`: stock submenu page render + AJAX endpoints (inline stock set, "+N printed", sheet CRUD).
- **Create `assets/js/ole-print-stock-admin.js`**, **`assets/css/ole-print-stock-admin.css`** — stock-page interactivity + styles.
- **Create `tests/print-stock/test-calc.php`** — standalone unit tests for `OLE_Print_Stock_Calc`.
- **Modify `includes/class-ole-settings.php`** — defaults + clamps for the toggle and two thresholds.
- **Modify `includes/class-ole-settings-page.php`** — settings section (toggle + two thresholds + link to stock page) and matching keys in `ajax_save()`.
- **Modify `includes/class-ole-plugin.php`** — enable the feature when the toggle is on.
- **Modify `order-list-enhancer.php`** — `require_once` the new classes; bump `OLE_VERSION`.
- **Modify `languages/order-list-enhancer-bg_BG.po` (+ recompile `.mo`)** — Bulgarian for all new strings.
- **Modify `readme.txt`** — changelog entry.

---

## Task 1: Pure consumption calculator (`OLE_Print_Stock_Calc`)

**Files:**
- Create: `includes/class-ole-print-stock-calc.php`
- Test: `tests/print-stock/test-calc.php`

**Interfaces:**
- Produces:
  - `OLE_Print_Stock_Calc::compute( array $lines, array $config ) : array` — `$lines` = `[ ['product_id'=>int,'variation_id'=>int,'qty'=>int], … ]`; `$config` = `[ 'stickers' => [ int $ref_id => int $consumable_id ], 'instructions' => [ ['id'=>int,'product_ids'=>[int,…]], … ] ]`. Returns `[ int $consumable_id => int $delta ]` where every delta is **negative** (amount to subtract). Sticker ref = `variation_id` when `> 0` else `product_id`.
  - `OLE_Print_Stock_Calc::crosses_low( int $before, int $after, int $threshold ) : bool` — true iff `$before > $threshold && $after <= $threshold`.
  - `OLE_Print_Stock_Calc::is_live( string $status ) : bool` — false iff status ∈ `{cancelled, failed, refunded, trash}`; true otherwise.

- [ ] **Step 1: Write the failing test**

Create `tests/print-stock/test-calc.php`:

```php
<?php
// Standalone unit tests for OLE_Print_Stock_Calc (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ole-print-stock-calc.php';

$fails = 0;
function check( $cond, $msg ) {
	global $fails;
	if ( $cond ) { echo "ok   - $msg\n"; } else { echo "FAIL - $msg\n"; $fails++; }
}

// Config: product 10 -> sticker cid 100, variation 21 -> sticker cid 101.
// Sheet cid 200 covers products 10 and 30; sheet cid 201 covers product 99.
$config = array(
	'stickers'     => array( 10 => 100, 21 => 101 ),
	'instructions' => array(
		array( 'id' => 200, 'product_ids' => array( 10, 30 ) ),
		array( 'id' => 201, 'product_ids' => array( 99 ) ),
	),
);

// Case A: 3x product 10 (simple) + 1x variation 21 (parent 30).
$lines = array(
	array( 'product_id' => 10, 'variation_id' => 0,  'qty' => 3 ),
	array( 'product_id' => 30, 'variation_id' => 21, 'qty' => 1 ),
);
$d = OLE_Print_Stock_Calc::compute( $lines, $config );
check( ( $d[100] ?? 0 ) === -3, 'sticker for product 10 = -3 (by qty)' );
check( ( $d[101] ?? 0 ) === -1, 'sticker for variation 21 = -1 (keyed on variation_id)' );
check( ( $d[200] ?? 0 ) === -1, 'sheet 200 = -1 once (covers products 10 and 30, both present)' );
check( ! isset( $d[201] ), 'sheet 201 untouched (its product 99 absent)' );

// Case B: same product twice as two lines -> sticker sums, sheet still -1.
$lines2 = array(
	array( 'product_id' => 10, 'variation_id' => 0, 'qty' => 2 ),
	array( 'product_id' => 10, 'variation_id' => 0, 'qty' => 4 ),
);
$d2 = OLE_Print_Stock_Calc::compute( $lines2, $config );
check( ( $d2[100] ?? 0 ) === -6, 'sticker sums across duplicate lines (-6)' );
check( ( $d2[200] ?? 0 ) === -1, 'sheet still -1 for duplicate product lines' );

// Case C: untracked product (no sticker row, in no sheet) -> no deltas.
$d3 = OLE_Print_Stock_Calc::compute( array( array( 'product_id' => 500, 'variation_id' => 0, 'qty' => 9 ) ), $config );
check( $d3 === array(), 'untracked product yields no deltas' );

// Case D: empty lines / empty config.
check( OLE_Print_Stock_Calc::compute( array(), $config ) === array(), 'no lines -> no deltas' );
check( OLE_Print_Stock_Calc::compute( $lines, array() ) === array(), 'empty config -> no deltas' );

// crosses_low: edge only.
check( OLE_Print_Stock_Calc::crosses_low( 21, 20, 20 ) === true,  'above->at threshold crosses' );
check( OLE_Print_Stock_Calc::crosses_low( 25, 19, 20 ) === true,  'above->below threshold crosses' );
check( OLE_Print_Stock_Calc::crosses_low( 20, 19, 20 ) === false, 'already at threshold does not re-cross' );
check( OLE_Print_Stock_Calc::crosses_low( 5, -2, 20 ) === false,  'staying below does not re-cross' );
check( OLE_Print_Stock_Calc::crosses_low( 1, 21, 20 ) === false,  'rising above does not cross' );

// is_live.
check( OLE_Print_Stock_Calc::is_live( 'processing' ) === true,  'processing is live' );
check( OLE_Print_Stock_Calc::is_live( 'completed' ) === true,   'completed is live' );
check( OLE_Print_Stock_Calc::is_live( 'pending' ) === true,     'pending is live' );
check( OLE_Print_Stock_Calc::is_live( 'on-hold' ) === true,     'on-hold is live' );
check( OLE_Print_Stock_Calc::is_live( 'cancelled' ) === false,  'cancelled is dead' );
check( OLE_Print_Stock_Calc::is_live( 'failed' ) === false,     'failed is dead' );
check( OLE_Print_Stock_Calc::is_live( 'refunded' ) === false,   'refunded is dead' );
check( OLE_Print_Stock_Calc::is_live( 'trash' ) === false,      'trash is dead' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/print-stock/test-calc.php`
Expected: FAIL — `require` fatal / "Class 'OLE_Print_Stock_Calc' not found".

- [ ] **Step 3: Write the implementation**

Create `includes/class-ole-print-stock-calc.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Чиста логіка обліку витратних матеріалів (без WordPress).
 * Тестується ізольовано — див. tests/print-stock/test-calc.php.
 */
class OLE_Print_Stock_Calc {

	/** Статуси, за яких замовлення НЕ споживає витратні (можна повернути). */
	const DEAD_STATUSES = array( 'cancelled', 'failed', 'refunded', 'trash' );

	/**
	 * Дельти списання по витратних для позицій замовлення.
	 *
	 * @param array $lines  [ ['product_id'=>int,'variation_id'=>int,'qty'=>int], ... ]
	 * @param array $config [ 'stickers'=>[ref_id=>cid], 'instructions'=>[ ['id'=>cid,'product_ids'=>[..]], ... ] ]
	 * @return array [ cid => delta<0 ]
	 */
	public static function compute( array $lines, array $config ) {
		$stickers     = isset( $config['stickers'] ) && is_array( $config['stickers'] ) ? $config['stickers'] : array();
		$instructions = isset( $config['instructions'] ) && is_array( $config['instructions'] ) ? $config['instructions'] : array();

		$deltas   = array();
		$products = array(); // унікальні батьківські product_id у замовленні

		foreach ( $lines as $line ) {
			$pid = (int) ( $line['product_id'] ?? 0 );
			$vid = (int) ( $line['variation_id'] ?? 0 );
			$qty = (int) ( $line['qty'] ?? 0 );
			if ( $qty <= 0 ) {
				continue;
			}
			if ( $pid > 0 ) {
				$products[ $pid ] = true;
			}
			$ref = $vid > 0 ? $vid : $pid;
			if ( $ref > 0 && isset( $stickers[ $ref ] ) ) {
				$cid            = (int) $stickers[ $ref ];
				$deltas[ $cid ] = ( $deltas[ $cid ] ?? 0 ) - $qty;
			}
		}

		foreach ( $instructions as $sheet ) {
			$cid  = (int) ( $sheet['id'] ?? 0 );
			$pids = isset( $sheet['product_ids'] ) && is_array( $sheet['product_ids'] ) ? $sheet['product_ids'] : array();
			if ( $cid <= 0 ) {
				continue;
			}
			foreach ( $pids as $p ) {
				if ( isset( $products[ (int) $p ] ) ) {
					$deltas[ $cid ] = ( $deltas[ $cid ] ?? 0 ) - 1;
					break; // -1 на замовлення, незалежно від к-сті збігів
				}
			}
		}

		return $deltas;
	}

	/** Чи перетнув залишок поріг «згори вниз» саме цим рухом (edge-тригер). */
	public static function crosses_low( $before, $after, $threshold ) {
		return (int) $before > (int) $threshold && (int) $after <= (int) $threshold;
	}

	/** Чи «живе» замовлення (споживає витратні) за його статусом. */
	public static function is_live( $status ) {
		return ! in_array( (string) $status, self::DEAD_STATUSES, true );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/print-stock/test-calc.php`
Expected: `ALL PASS` and exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ole-print-stock-calc.php tests/print-stock/test-calc.php
git commit -m "feat(print-stock): pure OLE_Print_Stock_Calc + unit tests

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Settings — toggle + two thresholds

**Files:**
- Modify: `includes/class-ole-settings.php` (defaults + clamps)
- Modify: `includes/class-ole-settings-page.php` (render section + `ajax_save()` keys)

**Interfaces:**
- Produces: settings keys `print_stock_enabled` (`yes`/`no`), `print_stock_threshold_sticker` (int), `print_stock_threshold_instruction` (int), read via `OLE_Settings::get()` / `OLE_Settings::is_yes()`.

**Note:** `OLE_Settings_Page::ajax_save()` rebuilds the **whole** option and calls `update_option()`, so any key it omits reverts to default on every save. New keys MUST be added to the `$opts` array there.

- [ ] **Step 1: Add defaults**

In `includes/class-ole-settings.php`, in `defaults()`, after the `delivery_vacation_text` line (before the closing `);`), add:

```php
			'print_stock_enabled'                => 'no',  // track printed stickers + instruction sheets
			'print_stock_threshold_sticker'      => 20,    // low threshold for stickers
			'print_stock_threshold_instruction'  => 5,     // low threshold for instruction sheets
```

- [ ] **Step 2: Add clamps in `get()`**

In `includes/class-ole-settings.php`, in `get()`, just before `if ( ! is_array( $opts['ship_rules'] ) ) {`, add:

```php
		$opts['print_stock_threshold_sticker']     = max( 0, min( 100000, (int) $opts['print_stock_threshold_sticker'] ) );
		$opts['print_stock_threshold_instruction'] = max( 0, min( 100000, (int) $opts['print_stock_threshold_instruction'] ) );
```

- [ ] **Step 3: Render the settings section**

In `includes/class-ole-settings-page.php`, in `render()`, immediately after the "Delivery-date notice" `</tbody></table>` block (right before `<h2>...Phone numbers...`), add:

```php
				<h2><?php esc_html_e( 'Print consumables (stickers & instructions)', 'order-list-enhancer' ); ?></h2>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable tracking', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="print_stock_enabled" <?php echo $cb( 'print_stock_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'Count printed stickers (per product/variation, by quantity) and instruction sheets (one per order) as orders come in, and warn when they run low.', 'order-list-enhancer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sticker low threshold', 'order-list-enhancer' ); ?></th>
						<td><input type="number" name="print_stock_threshold_sticker" min="0" max="100000" step="1" value="<?php echo esc_attr( (string) $o['print_stock_threshold_sticker'] ); ?>"/>
						<p class="description"><?php esc_html_e( 'Warn ("time to print") when a sticker stock drops to this or below.', 'order-list-enhancer' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Instruction low threshold', 'order-list-enhancer' ); ?></th>
						<td><input type="number" name="print_stock_threshold_instruction" min="0" max="100000" step="1" value="<?php echo esc_attr( (string) $o['print_stock_threshold_instruction'] ); ?>"/>
						<p class="description"><?php esc_html_e( 'Warn when an instruction sheet stock drops to this or below.', 'order-list-enhancer' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stock page', 'order-list-enhancer' ); ?></th>
						<td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ole-print-stock' ) ); ?>"><?php esc_html_e( 'Open consumables stock', 'order-list-enhancer' ); ?></a></td>
					</tr>
				</tbody></table>
```

- [ ] **Step 4: Persist the keys in `ajax_save()`**

In `includes/class-ole-settings-page.php`, in `ajax_save()`, inside the `$opts = array( … )` literal, after the `'delivery_vacation_text' => …,` line, add:

```php
			'print_stock_enabled'               => $bool( 'print_stock_enabled' ),
			'print_stock_threshold_sticker'     => isset( $in['print_stock_threshold_sticker'] ) ? max( 0, min( 100000, (int) $in['print_stock_threshold_sticker'] ) ) : 20,
			'print_stock_threshold_instruction' => isset( $in['print_stock_threshold_instruction'] ) ? max( 0, min( 100000, (int) $in['print_stock_threshold_instruction'] ) ) : 5,
```

- [ ] **Step 5: Commit**

```bash
git add includes/class-ole-settings.php includes/class-ole-settings-page.php
git commit -m "feat(print-stock): settings toggle + sticker/instruction thresholds

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

**Live verification (after the feature is wired in Task 4 + deployed):** the "Print consumables" section appears on the OLE settings page; toggling it and setting thresholds to 20/5 survives Save + reload.

---

## Task 3: DB layer (`OLE_Print_Stock_Store`) — schema, CRUD, ledger

**Files:**
- Create: `includes/class-ole-print-stock-store.php`
- Modify: `order-list-enhancer.php` (require the class — add near the other `require_once` lines)

**Interfaces:**
- Consumes: nothing (self-contained `$wpdb`).
- Produces (all `public static`):
  - `install() : void`, `maybe_upgrade() : void`, `DB_VERSION` const.
  - `table_consumable() : string`, `table_link() : string`, `table_log() : string`.
  - `get_sticker( int $ref_id ) : ?array` — consumable row (`id,type,name,ref_id,stock,low_notified`) or null.
  - `upsert_sticker( int $ref_id, string $name, int $stock ) : int` — creates/updates the sticker row for `$ref_id`, sets absolute `stock`, returns consumable id.
  - `get_consumable( int $id ) : ?array`.
  - `set_stock_absolute( int $id, int $stock, int $actor_delta ) : void` — sets stock and writes a `manual` ledger row of `$actor_delta`.
  - `add_stock( int $id, int $amount ) : void` — `stock += amount`, `manual` ledger row.
  - `all_consumables() : array` — every row, for the admin page.
  - `sheets() : array` — instruction rows only, each with `product_ids` array.
  - `create_sheet( string $name, array $product_ids, int $stock ) : int`.
  - `update_sheet( int $id, string $name, array $product_ids, int $stock ) : void`.
  - `delete_sheet( int $id ) : void`.
  - `sticker_config() : array` — `[ ref_id => cid ]` for all sticker rows.
  - `apply_delta( int $consumable_id, int $delta, int $order_id, string $reason ) : array` — atomic `stock += delta` + ledger row; returns `['before'=>int,'after'=>int]`.
  - `ledger_net( int $order_id ) : array` — `[ cid => sum(delta) ]` for the order.
  - `set_low_notified( int $id, int $flag ) : void`.
  - `low_count( int $sticker_threshold, int $instruction_threshold ) : int` — rows at/below their type threshold.

- [ ] **Step 1: Write the class**

Create `includes/class-ole-print-stock-store.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Шар БД для обліку витратних: 3 таблиці + журнал рухів.
 */
class OLE_Print_Stock_Store {

	const DB_VERSION    = '1';
	const DB_VERSION_OPT = 'ole_print_stock_db';

	public static function table_consumable() {
		global $wpdb;
		return $wpdb->prefix . 'ole_consumable';
	}
	public static function table_link() {
		global $wpdb;
		return $wpdb->prefix . 'ole_consumable_product';
	}
	public static function table_log() {
		global $wpdb;
		return $wpdb->prefix . 'ole_consume_log';
	}

	/** Створити/оновити таблиці (dbDelta) якщо версія БД не збігається. */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPT ) === self::DB_VERSION ) {
			return;
		}
		self::install();
		update_option( self::DB_VERSION_OPT, self::DB_VERSION, false );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$c = self::table_consumable();
		$l = self::table_link();
		$g = self::table_log();

		dbDelta(
			"CREATE TABLE $c (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				type VARCHAR(20) NOT NULL,
				name VARCHAR(191) NOT NULL DEFAULT '',
				ref_id BIGINT NOT NULL DEFAULT 0,
				stock INT NOT NULL DEFAULT 0,
				low_notified TINYINT(1) NOT NULL DEFAULT 0,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY type_ref (type, ref_id)
			) $charset;"
		);
		dbDelta(
			"CREATE TABLE $l (
				consumable_id BIGINT UNSIGNED NOT NULL,
				product_id BIGINT NOT NULL,
				PRIMARY KEY  (consumable_id, product_id),
				KEY product_id (product_id)
			) $charset;"
		);
		dbDelta(
			"CREATE TABLE $g (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT NOT NULL DEFAULT 0,
				consumable_id BIGINT UNSIGNED NOT NULL,
				delta INT NOT NULL DEFAULT 0,
				reason VARCHAR(20) NOT NULL DEFAULT '',
				created_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY consumable_id (consumable_id)
			) $charset;"
		);
	}

	private static function now() {
		return current_time( 'mysql' );
	}

	public static function get_consumable( $id ) {
		global $wpdb;
		$c = self::table_consumable();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $c WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function get_sticker( $ref_id ) {
		global $wpdb;
		$c = self::table_consumable();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $c WHERE type = 'sticker' AND ref_id = %d", (int) $ref_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	/** Створює/оновлює наліпку для товару/варіації, встановлює абсолютний запас. */
	public static function upsert_sticker( $ref_id, $name, $stock ) {
		global $wpdb;
		$c   = self::table_consumable();
		$row = self::get_sticker( $ref_id );
		if ( $row ) {
			$wpdb->update(
				$c,
				array( 'name' => $name, 'stock' => (int) $stock, 'updated_at' => self::now() ),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			return (int) $row['id'];
		}
		$wpdb->insert(
			$c,
			array( 'type' => 'sticker', 'name' => $name, 'ref_id' => (int) $ref_id, 'stock' => (int) $stock, 'low_notified' => 0, 'updated_at' => self::now() ),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function set_stock_absolute( $id, $stock, $actor_delta ) {
		global $wpdb;
		$c = self::table_consumable();
		$wpdb->update( $c, array( 'stock' => (int) $stock, 'updated_at' => self::now() ), array( 'id' => (int) $id ), array( '%d', '%s' ), array( '%d' ) );
		self::log( 0, (int) $id, (int) $actor_delta, 'manual' );
		self::rearm_if_above( (int) $id );
	}

	public static function add_stock( $id, $amount ) {
		global $wpdb;
		$c = self::table_consumable();
		$wpdb->query( $wpdb->prepare( "UPDATE $c SET stock = stock + %d, updated_at = %s WHERE id = %d", (int) $amount, self::now(), (int) $id ) ); // phpcs:ignore WordPress.DB
		self::log( 0, (int) $id, (int) $amount, 'manual' );
		self::rearm_if_above( (int) $id );
	}

	public static function all_consumables() {
		global $wpdb;
		$c = self::table_consumable();
		return $wpdb->get_results( "SELECT * FROM $c ORDER BY type ASC, stock ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function sticker_config() {
		global $wpdb;
		$c   = self::table_consumable();
		$out = array();
		$rows = $wpdb->get_results( "SELECT ref_id, id FROM $c WHERE type = 'sticker'", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['ref_id'] ] = (int) $r['id'];
		}
		return $out;
	}

	public static function sheets() {
		global $wpdb;
		$c = self::table_consumable();
		$l = self::table_link();
		$rows = $wpdb->get_results( "SELECT * FROM $c WHERE type = 'instruction' ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as &$row ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM $l WHERE consumable_id = %d", (int) $row['id'] ) ); // phpcs:ignore WordPress.DB
			$row['product_ids'] = array_map( 'intval', (array) $ids );
		}
		return $rows;
	}

	public static function create_sheet( $name, $product_ids, $stock ) {
		global $wpdb;
		$wpdb->insert(
			self::table_consumable(),
			array( 'type' => 'instruction', 'name' => $name, 'ref_id' => 0, 'stock' => (int) $stock, 'low_notified' => 0, 'updated_at' => self::now() ),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);
		$id = (int) $wpdb->insert_id;
		self::set_sheet_products( $id, $product_ids );
		return $id;
	}

	public static function update_sheet( $id, $name, $product_ids, $stock ) {
		global $wpdb;
		$wpdb->update(
			self::table_consumable(),
			array( 'name' => $name, 'stock' => (int) $stock, 'updated_at' => self::now() ),
			array( 'id' => (int) $id, 'type' => 'instruction' ),
			array( '%s', '%d', '%s' ),
			array( '%d', '%s' )
		);
		self::set_sheet_products( (int) $id, $product_ids );
	}

	public static function delete_sheet( $id ) {
		global $wpdb;
		$wpdb->delete( self::table_consumable(), array( 'id' => (int) $id, 'type' => 'instruction' ), array( '%d', '%s' ) );
		$wpdb->delete( self::table_link(), array( 'consumable_id' => (int) $id ), array( '%d' ) );
	}

	private static function set_sheet_products( $id, $product_ids ) {
		global $wpdb;
		$l = self::table_link();
		$wpdb->delete( $l, array( 'consumable_id' => (int) $id ), array( '%d' ) );
		$seen = array();
		foreach ( (array) $product_ids as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 || isset( $seen[ $pid ] ) ) {
				continue;
			}
			$seen[ $pid ] = true;
			$wpdb->insert( $l, array( 'consumable_id' => (int) $id, 'product_id' => $pid ), array( '%d', '%d' ) );
		}
	}

	/** Атомарне списання/повернення + рядок журналу. Повертає before/after. */
	public static function apply_delta( $consumable_id, $delta, $order_id, $reason ) {
		global $wpdb;
		$c = self::table_consumable();
		$wpdb->query( $wpdb->prepare( "UPDATE $c SET stock = stock + %d, updated_at = %s WHERE id = %d", (int) $delta, self::now(), (int) $consumable_id ) ); // phpcs:ignore WordPress.DB
		$after  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT stock FROM $c WHERE id = %d", (int) $consumable_id ) ); // phpcs:ignore WordPress.DB
		$before = $after - (int) $delta;
		self::log( (int) $order_id, (int) $consumable_id, (int) $delta, (string) $reason );
		return array( 'before' => $before, 'after' => $after );
	}

	public static function ledger_net( $order_id ) {
		global $wpdb;
		$g   = self::table_log();
		$out = array();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT consumable_id, SUM(delta) net FROM $g WHERE order_id = %d GROUP BY consumable_id", (int) $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['consumable_id'] ] = (int) $r['net'];
		}
		return $out;
	}

	public static function set_low_notified( $id, $flag ) {
		global $wpdb;
		$wpdb->update( self::table_consumable(), array( 'low_notified' => (int) $flag ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}

	public static function low_count( $sticker_threshold, $instruction_threshold ) {
		global $wpdb;
		$c = self::table_consumable();
		return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT COUNT(*) FROM $c WHERE ( type = 'sticker' AND stock <= %d ) OR ( type = 'instruction' AND stock <= %d )",
			(int) $sticker_threshold,
			(int) $instruction_threshold
		) );
	}

	/** Якщо запас піднявся вище порогу свого типу — переозброїти low_notified. */
	private static function rearm_if_above( $id ) {
		$row = self::get_consumable( $id );
		if ( ! $row || (int) $row['low_notified'] === 0 ) {
			return;
		}
		$o         = OLE_Settings::get();
		$threshold = ( 'instruction' === $row['type'] )
			? (int) $o['print_stock_threshold_instruction']
			: (int) $o['print_stock_threshold_sticker'];
		if ( (int) $row['stock'] > $threshold ) {
			self::set_low_notified( (int) $id, 0 );
		}
	}

	private static function log( $order_id, $consumable_id, $delta, $reason ) {
		global $wpdb;
		$wpdb->insert(
			self::table_log(),
			array( 'order_id' => (int) $order_id, 'consumable_id' => (int) $consumable_id, 'delta' => (int) $delta, 'reason' => (string) $reason, 'created_at' => self::now() ),
			array( '%d', '%d', '%d', '%s', '%s' )
		);
	}
}
```

- [ ] **Step 2: Require the class in the main plugin file**

In `order-list-enhancer.php`, after the `require_once OLE_DIR . 'includes/class-ole-dup-guard.php';` line, add:

```php
require_once OLE_DIR . 'includes/class-ole-print-stock-calc.php';
require_once OLE_DIR . 'includes/class-ole-print-stock-store.php';
require_once OLE_DIR . 'includes/class-ole-print-stock.php';
require_once OLE_DIR . 'includes/class-ole-print-stock-admin.php';
```

(The last two files are created in Tasks 4 and 6; add all four requires now so the load order is fixed. If executing strictly task-by-task and PHP would fatal on the missing files before Task 4, add the `print-stock.php` and `print-stock-admin.php` requires in Task 4/6 instead — but since deploy is atomic, adding them together here is fine as long as Tasks 4 and 6 land before deploy.)

- [ ] **Step 3: Commit**

```bash
git add includes/class-ole-print-stock-store.php order-list-enhancer.php
git commit -m "feat(print-stock): DB store — 3 tables, CRUD, atomic apply_delta + ledger

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

**Live verification (after Task 4 wiring + deploy):** enabling the feature and loading any admin page creates `{prefix}ole_consumable`, `{prefix}ole_consumable_product`, `{prefix}ole_consume_log`; `get_option('ole_print_stock_db')` returns `1`.

---

## Task 4: Reconcile engine + order hooks (`OLE_Print_Stock`)

**Files:**
- Create: `includes/class-ole-print-stock.php`
- Modify: `includes/class-ole-plugin.php` (enable when toggle on)

**Interfaces:**
- Consumes: `OLE_Print_Stock_Calc::compute/crosses_low/is_live`, all of `OLE_Print_Stock_Store`, `OLE_Settings::get()`.
- Produces (used by Tasks 5–7):
  - `OLE_Print_Stock::init() : void`.
  - `OLE_Print_Stock::threshold_for( string $type ) : int` — sticker/instruction threshold from settings.
  - `OLE_Print_Stock::reconcile( WC_Order $order ) : void`.
  - `OLE_Print_Stock::build_config() : array` — `['stickers'=>…,'instructions'=>…]` for `compute()`.
  - `OLE_Print_Stock::lines_from_order( WC_Order $order ) : array`.
  - constant `STATE_META = '_ole_ps_state'`, `DEPLETED_META = '_ole_depleted'`.

- [ ] **Step 1: Write the class (reconcile + order hooks + email; banner/badge added in Task 7)**

Create `includes/class-ole-print-stock.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-glue обліку витратних: звірка consume/restore за станом замовлення + сповіщення.
 */
class OLE_Print_Stock {

	const STATE_META    = '_ole_ps_state';    // '' | 'consumed' | 'restored'
	const DEPLETED_META = '_ole_depleted';    // [ ['name'=>str,'stock'=>int], ... ]

	public static function init() {
		OLE_Print_Stock_Store::maybe_upgrade();

		// Створення замовлення (класичний + Store API checkout).
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order' ), 30, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order' ), 30, 1 );
		// Переходи статусів (скасовано/повернуто/невдало/кошик і назад).
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 30, 4 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'on_order' ), 30, 1 );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'on_order' ), 30, 1 );

		// Адмін-UI реєструється в своїх задачах (поля товару — Task 5; сторінка — Task 6;
		// банер/значок — Task 7). Тут лише споживання/повернення + email.
	}

	public static function threshold_for( $type ) {
		$o = OLE_Settings::get();
		return ( 'instruction' === $type )
			? (int) $o['print_stock_threshold_instruction']
			: (int) $o['print_stock_threshold_sticker'];
	}

	public static function on_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			self::reconcile( $order );
		}
	}

	public static function on_status_changed( $order_id, $from, $to, $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof WC_Order ) {
			self::reconcile( $order );
		}
	}

	/** Єдина точка: приводить облік у відповідність до стану замовлення. */
	public static function reconcile( WC_Order $order ) {
		$live  = OLE_Print_Stock_Calc::is_live( $order->get_status() );
		$state = (string) $order->get_meta( self::STATE_META );

		if ( $live && 'consumed' !== $state ) {
			self::consume( $order );
		} elseif ( ! $live && 'consumed' === $state ) {
			self::restore( $order );
		}
	}

	private static function consume( WC_Order $order ) {
		$config = self::build_config();
		$deltas = OLE_Print_Stock_Calc::compute( self::lines_from_order( $order ), $config );
		if ( empty( $deltas ) ) {
			$order->update_meta_data( self::STATE_META, 'consumed' );
			$order->save();
			return;
		}
		$order_id = $order->get_id();
		$depleted = array();
		$crossed  = array(); // for email
		foreach ( $deltas as $cid => $delta ) {
			if ( $delta >= 0 ) {
				continue;
			}
			$row = OLE_Print_Stock_Store::get_consumable( $cid );
			if ( ! $row ) {
				continue;
			}
			$res       = OLE_Print_Stock_Store::apply_delta( $cid, (int) $delta, $order_id, 'order' );
			$threshold = self::threshold_for( $row['type'] );
			if ( OLE_Print_Stock_Calc::crosses_low( $res['before'], $res['after'], $threshold ) ) {
				OLE_Print_Stock_Store::set_low_notified( (int) $cid, 1 );
				$depleted[] = array( 'name' => $row['name'], 'stock' => $res['after'] );
				$crossed[]  = array( 'name' => $row['name'], 'stock' => $res['after'], 'type' => $row['type'] );
			}
		}
		$order->update_meta_data( self::STATE_META, 'consumed' );
		if ( $depleted ) {
			$order->update_meta_data( self::DEPLETED_META, $depleted );
		}
		$order->save();

		if ( $crossed ) {
			self::send_low_email( $crossed );
		}
	}

	private static function restore( WC_Order $order ) {
		$order_id = $order->get_id();
		$net      = OLE_Print_Stock_Store::ledger_net( $order_id );
		foreach ( $net as $cid => $sum ) {
			if ( 0 === (int) $sum ) {
				continue;
			}
			// Реверс поточного застосованого footprint замовлення.
			$res = OLE_Print_Stock_Store::apply_delta( (int) $cid, -(int) $sum, $order_id, 'restore' );
			$row = OLE_Print_Stock_Store::get_consumable( $cid );
			if ( $row && (int) $row['low_notified'] === 1 && $res['after'] > self::threshold_for( $row['type'] ) ) {
				OLE_Print_Stock_Store::set_low_notified( (int) $cid, 0 );
			}
		}
		$order->update_meta_data( self::STATE_META, 'restored' );
		$order->delete_meta_data( self::DEPLETED_META );
		$order->save();
	}

	/** [ ['product_id'=>,'variation_id'=>,'qty'=>], ... ] з рядків замовлення. */
	public static function lines_from_order( WC_Order $order ) {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$lines[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'qty'          => (int) $item->get_quantity(),
			);
		}
		return $lines;
	}

	public static function build_config() {
		$instructions = array();
		foreach ( OLE_Print_Stock_Store::sheets() as $sheet ) {
			$instructions[] = array(
				'id'          => (int) $sheet['id'],
				'product_ids' => $sheet['product_ids'],
			);
		}
		return array(
			'stickers'     => OLE_Print_Stock_Store::sticker_config(),
			'instructions' => $instructions,
		);
	}

	private static function send_low_email( $crossed ) {
		$to      = get_option( 'admin_email' );
		$subject = __( 'OLE — time to print more consumables', 'order-list-enhancer' );
		$lines   = array( __( 'These items dropped to their low threshold:', 'order-list-enhancer' ), '' );
		foreach ( $crossed as $c ) {
			$label = ( 'instruction' === $c['type'] )
				? __( 'Instruction', 'order-list-enhancer' )
				: __( 'Sticker', 'order-list-enhancer' );
			/* translators: 1: type, 2: name, 3: remaining stock. */
			$lines[] = sprintf( __( '%1$s "%2$s" — %3$d left', 'order-list-enhancer' ), $label, $c['name'], (int) $c['stock'] );
		}
		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
```

- [ ] **Step 2: Enable the feature in `OLE_Plugin`**

In `includes/class-ole-plugin.php`, in `__construct()`, after the `if ( OLE_Settings::is_yes( $opts, 'dup_guard_enabled' ) ) { OLE_Dup_Guard::init(); }` block, add:

```php
		if ( OLE_Settings::is_yes( $opts, 'print_stock_enabled' ) ) {
			OLE_Print_Stock::init();
		}
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-ole-print-stock.php includes/class-ole-plugin.php
git commit -m "feat(print-stock): reconcile engine (consume/restore) + order hooks + low email

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

**Live verification (after Task 5/6 give a way to set stock + deploy):** with a sheet + sticker stock set, placing an order drops the sticker by qty and the sheet by 1; cancelling restores both exactly; a second place consumes again; the ledger table shows matching `order`/`restore` rows.

---

## Task 5: Sticker-stock fields on product & variation screens

**Files:**
- Modify: `includes/class-ole-print-stock.php` (register + render + save)

**Interfaces:**
- Consumes: `OLE_Print_Stock_Store::get_sticker/upsert_sticker`.
- Produces: sticker rows created/updated from the product editor. Field key `_ole_sticker_stock` (posted form field, not persisted as product meta — it is written straight to the consumable table).

- [ ] **Step 1: Register the admin hooks in `init()`**

In `includes/class-ole-print-stock.php`, inside `init()`, after the trash/untrash hooks, add:

```php
		// Поле «Запас наліпок» на простому товарі та на кожній варіації.
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'render_simple_field' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_simple_field' ), 20, 1 );
		add_action( 'woocommerce_variation_options_inventory', array( __CLASS__, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_field' ), 20, 2 );
```

- [ ] **Step 2: Add the render + save methods**

In `includes/class-ole-print-stock.php`, add these methods to the class:

```php
	/** Поточний запас наліпки товару/варіації (для відображення в полі). */
	private static function sticker_stock_value( $ref_id ) {
		$row = OLE_Print_Stock_Store::get_sticker( (int) $ref_id );
		return $row ? (string) (int) $row['stock'] : '';
	}

	public static function render_simple_field() {
		global $post;
		$val = self::sticker_stock_value( (int) $post->ID );
		woocommerce_wp_text_input(
			array(
				'id'                => '_ole_sticker_stock',
				'label'             => __( 'Sticker stock', 'order-list-enhancer' ),
				'desc_tip'          => true,
				'description'       => __( 'Printed stickers on hand for this product. Decreases by the quantity ordered. Leave blank to not track.', 'order-list-enhancer' ),
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '1' ),
				'value'             => $val,
			)
		);
	}

	public static function save_simple_field( $post_id ) {
		if ( ! isset( $_POST['_ole_sticker_stock'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$raw = wp_unslash( $_POST['_ole_sticker_stock'] ); // phpcs:ignore WordPress.Security
		if ( '' === trim( (string) $raw ) ) {
			return; // не трекаємо / не чіпаємо існуючий запас
		}
		$product = wc_get_product( $post_id );
		$name    = $product ? wp_strip_all_tags( $product->get_name() ) : ( '#' . (int) $post_id );
		OLE_Print_Stock_Store::upsert_sticker( (int) $post_id, $name, (int) $raw );
	}

	public static function render_variation_field( $loop, $variation_data, $variation ) {
		$vid = (int) $variation->ID;
		$val = self::sticker_stock_value( $vid );
		woocommerce_wp_text_input(
			array(
				'id'                => '_ole_sticker_stock_' . $loop,
				'name'              => '_ole_sticker_stock_var[' . $loop . ']',
				'label'             => __( 'Sticker stock', 'order-list-enhancer' ),
				'wrapper_class'     => 'form-row form-row-first',
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '1' ),
				'value'             => $val,
			)
		);
	}

	public static function save_variation_field( $variation_id, $i ) {
		if ( ! isset( $_POST['_ole_sticker_stock_var'][ $i ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$raw = wp_unslash( $_POST['_ole_sticker_stock_var'][ $i ] ); // phpcs:ignore WordPress.Security
		if ( '' === trim( (string) $raw ) ) {
			return;
		}
		$variation = wc_get_product( $variation_id );
		$name      = $variation ? wp_strip_all_tags( $variation->get_name() ) : ( '#' . (int) $variation_id );
		OLE_Print_Stock_Store::upsert_sticker( (int) $variation_id, $name, (int) $raw );
	}
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-ole-print-stock.php
git commit -m "feat(print-stock): sticker-stock fields on product & variation inventory tabs

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

**Live verification (after deploy):** the Inventory tab of a simple product shows "Sticker stock"; setting `50` and saving creates a sticker row with stock 50 (visible on the stock page from Task 6); a variable product shows the field per variation, each keyed on the variation id.

---

## Task 6: Stock admin page + AJAX + assets (`OLE_Print_Stock_Admin`)

**Files:**
- Create: `includes/class-ole-print-stock-admin.php`
- Create: `assets/js/ole-print-stock-admin.js`, `assets/css/ole-print-stock-admin.css`
- Modify: `includes/class-ole-print-stock.php` (`init()` → `OLE_Print_Stock_Admin::init()`)

**Interfaces:**
- Consumes: `OLE_Print_Stock_Store` CRUD, `OLE_Print_Stock::threshold_for`.
- Produces: submenu page `admin.php?page=ole-print-stock`; AJAX actions `ole_ps_set_stock`, `ole_ps_add_stock`, `ole_ps_save_sheet`, `ole_ps_delete_sheet` (nonce `ole_ps`, cap `manage_woocommerce`).

- [ ] **Step 1: Write the admin controller**

Create `includes/class-ole-print-stock-admin.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Сторінка запасу витратних + AJAX (інлайн-редагування, +N, CRUD аркушів).
 */
class OLE_Print_Stock_Admin {

	const SLUG = 'ole-print-stock';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_ole_ps_set_stock', array( __CLASS__, 'ajax_set_stock' ) );
		add_action( 'wp_ajax_ole_ps_add_stock', array( __CLASS__, 'ajax_add_stock' ) );
		add_action( 'wp_ajax_ole_ps_save_sheet', array( __CLASS__, 'ajax_save_sheet' ) );
		add_action( 'wp_ajax_ole_ps_delete_sheet', array( __CLASS__, 'ajax_delete_sheet' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Print consumables', 'order-list-enhancer' ),
			__( 'Print consumables', 'order-list-enhancer' ),
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
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'ole-print-stock-admin', OLE_URL . 'assets/css/ole-print-stock-admin.css', array(), OLE_VERSION );
		wp_enqueue_script( 'ole-print-stock-admin', OLE_URL . 'assets/js/ole-print-stock-admin.js', array( 'jquery', 'wc-enhanced-select' ), OLE_VERSION, true );
		wp_localize_script(
			'ole-print-stock-admin',
			'OLE_PS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ole_ps' ),
				'i18n'    => array(
					'saved'   => __( 'Saved.', 'order-list-enhancer' ),
					'error'   => __( 'Failed.', 'order-list-enhancer' ),
					'confirm' => __( 'Delete this instruction sheet?', 'order-list-enhancer' ),
					'addQ'    => __( 'How many printed copies to add?', 'order-list-enhancer' ),
				),
			)
		);
	}

	private static function guard() {
		check_ajax_referer( 'ole_ps', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
	}

	public static function ajax_set_stock() {
		self::guard();
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$stock = isset( $_POST['stock'] ) ? (int) $_POST['stock'] : 0;
		$row   = $id ? OLE_Print_Stock_Store::get_consumable( $id ) : null;
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'not_found' ), 404 );
		}
		$diff = $stock - (int) $row['stock'];
		OLE_Print_Stock_Store::set_stock_absolute( $id, $stock, $diff );
		wp_send_json_success( array( 'stock' => $stock ) );
	}

	public static function ajax_add_stock() {
		self::guard();
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$amount = isset( $_POST['amount'] ) ? (int) $_POST['amount'] : 0;
		$row    = $id ? OLE_Print_Stock_Store::get_consumable( $id ) : null;
		if ( ! $row || 0 === $amount ) {
			wp_send_json_error( array( 'message' => 'bad' ), 400 );
		}
		OLE_Print_Stock_Store::add_stock( $id, $amount );
		$fresh = OLE_Print_Stock_Store::get_consumable( $id );
		wp_send_json_success( array( 'stock' => (int) $fresh['stock'] ) );
	}

	public static function ajax_save_sheet() {
		self::guard();
		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$stock    = isset( $_POST['stock'] ) ? (int) $_POST['stock'] : 0;
		$products = isset( $_POST['products'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['products'] ) ) : array();
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => 'name_required' ), 400 );
		}
		if ( $id ) {
			OLE_Print_Stock_Store::update_sheet( $id, $name, $products, $stock );
		} else {
			$id = OLE_Print_Stock_Store::create_sheet( $name, $products, $stock );
		}
		wp_send_json_success( array( 'id' => $id ) );
	}

	public static function ajax_delete_sheet() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			OLE_Print_Stock_Store::delete_sheet( $id );
		}
		wp_send_json_success();
	}

	private static function status_class( $row ) {
		$threshold = OLE_Print_Stock::threshold_for( $row['type'] );
		if ( (int) $row['stock'] < 0 ) {
			return 'ole-ps-neg';
		}
		if ( (int) $row['stock'] <= $threshold ) {
			return 'ole-ps-low';
		}
		return '';
	}

	public static function render() {
		$rows   = OLE_Print_Stock_Store::all_consumables();
		$sheets = OLE_Print_Stock_Store::sheets();
		?>
		<div class="wrap ole-ps-wrap">
			<h1><?php esc_html_e( 'Print consumables', 'order-list-enhancer' ); ?></h1>

			<h2><?php esc_html_e( 'Current stock', 'order-list-enhancer' ); ?></h2>
			<table class="widefat striped ole-ps-table">
				<thead><tr>
					<th><?php esc_html_e( 'Name', 'order-list-enhancer' ); ?></th>
					<th><?php esc_html_e( 'Type', 'order-list-enhancer' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'Stock', 'order-list-enhancer' ); ?></th>
					<th style="width:220px"><?php esc_html_e( 'Actions', 'order-list-enhancer' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No consumables yet. Set a product\'s "Sticker stock" or create an instruction sheet below.', 'order-list-enhancer' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr class="<?php echo esc_attr( self::status_class( $r ) ); ?>" data-id="<?php echo esc_attr( $r['id'] ); ?>">
							<td><?php echo esc_html( $r['name'] ); ?></td>
							<td><?php echo esc_html( 'instruction' === $r['type'] ? __( 'Instruction', 'order-list-enhancer' ) : __( 'Sticker', 'order-list-enhancer' ) ); ?></td>
							<td><input type="number" step="1" class="ole-ps-stock" value="<?php echo esc_attr( (string) (int) $r['stock'] ); ?>" style="width:90px"/></td>
							<td>
								<button type="button" class="button ole-ps-save"><?php esc_html_e( 'Set', 'order-list-enhancer' ); ?></button>
								<button type="button" class="button ole-ps-add"><?php esc_html_e( '+ printed', 'order-list-enhancer' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:2em"><?php esc_html_e( 'Instruction sheets', 'order-list-enhancer' ); ?></h2>
			<p class="description"><?php esc_html_e( 'One sheet can cover several products. Each order containing any of its products consumes one copy.', 'order-list-enhancer' ); ?></p>
			<table class="widefat ole-ps-sheets">
				<thead><tr>
					<th style="width:22%"><?php esc_html_e( 'Name', 'order-list-enhancer' ); ?></th>
					<th><?php esc_html_e( 'Products', 'order-list-enhancer' ); ?></th>
					<th style="width:100px"><?php esc_html_e( 'Stock', 'order-list-enhancer' ); ?></th>
					<th style="width:160px"></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $sheets as $s ) : ?>
					<tr class="ole-ps-sheet" data-id="<?php echo esc_attr( $s['id'] ); ?>">
						<td><input type="text" class="ole-ps-sheet-name regular-text" value="<?php echo esc_attr( $s['name'] ); ?>"/></td>
						<td>
							<select multiple class="wc-product-search ole-ps-sheet-products" data-placeholder="<?php esc_attr_e( 'Search for products…', 'order-list-enhancer' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%">
								<?php foreach ( $s['product_ids'] as $pid ) : ?>
									<?php $p = wc_get_product( $pid ); if ( ! $p ) { continue; } ?>
									<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="number" step="1" class="ole-ps-sheet-stock" value="<?php echo esc_attr( (string) (int) $s['stock'] ); ?>" style="width:80px"/></td>
						<td>
							<button type="button" class="button ole-ps-sheet-save"><?php esc_html_e( 'Save', 'order-list-enhancer' ); ?></button>
							<button type="button" class="button ole-ps-sheet-delete">&times;</button>
						</td>
					</tr>
				<?php endforeach; ?>
					<tr class="ole-ps-sheet ole-ps-sheet-new" data-id="0">
						<td><input type="text" class="ole-ps-sheet-name regular-text" placeholder="<?php esc_attr_e( 'New sheet name', 'order-list-enhancer' ); ?>"/></td>
						<td>
							<select multiple class="wc-product-search ole-ps-sheet-products" data-placeholder="<?php esc_attr_e( 'Search for products…', 'order-list-enhancer' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%"></select>
						</td>
						<td><input type="number" step="1" class="ole-ps-sheet-stock" value="0" style="width:80px"/></td>
						<td><button type="button" class="button button-primary ole-ps-sheet-save"><?php esc_html_e( 'Add', 'order-list-enhancer' ); ?></button></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
```

- [ ] **Step 2: Write the admin JS**

Create `assets/js/ole-print-stock-admin.js`:

```js
/* global OLE_PS, jQuery */
( function ( $ ) {
	'use strict';
	if ( 'undefined' === typeof OLE_PS ) { return; }

	function post( action, data ) {
		return $.post( OLE_PS.ajaxUrl, $.extend( { action: action, nonce: OLE_PS.nonce }, data ) );
	}

	// Enhance product search selects.
	$( '.ole-ps-sheet-products' ).each( function () {
		if ( $.fn.selectWoo ) { $( this ).selectWoo(); }
	} );

	// Set absolute stock.
	$( document ).on( 'click', '.ole-ps-save', function () {
		var row = $( this ).closest( 'tr' );
		post( 'ole_ps_set_stock', { id: row.data( 'id' ), stock: row.find( '.ole-ps-stock' ).val() } )
			.done( function () { flash( row ); } )
			.fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	// Add printed copies.
	$( document ).on( 'click', '.ole-ps-add', function () {
		var row = $( this ).closest( 'tr' );
		var n = window.prompt( OLE_PS.i18n.addQ, '100' );
		if ( null === n || '' === n ) { return; }
		post( 'ole_ps_add_stock', { id: row.data( 'id' ), amount: parseInt( n, 10 ) || 0 } )
			.done( function ( r ) { row.find( '.ole-ps-stock' ).val( r.data.stock ); flash( row ); } )
			.fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	// Save / add a sheet.
	$( document ).on( 'click', '.ole-ps-sheet-save', function () {
		var row = $( this ).closest( 'tr' );
		post( 'ole_ps_save_sheet', {
			id: row.data( 'id' ),
			name: row.find( '.ole-ps-sheet-name' ).val(),
			stock: row.find( '.ole-ps-sheet-stock' ).val(),
			products: row.find( '.ole-ps-sheet-products' ).val() || []
		} ).done( function () { window.location.reload(); } )
		   .fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	// Delete a sheet.
	$( document ).on( 'click', '.ole-ps-sheet-delete', function () {
		if ( ! window.confirm( OLE_PS.i18n.confirm ) ) { return; }
		var row = $( this ).closest( 'tr' );
		post( 'ole_ps_delete_sheet', { id: row.data( 'id' ) } )
			.done( function () { row.remove(); } )
			.fail( function () { window.alert( OLE_PS.i18n.error ); } );
	} );

	function flash( row ) {
		row.css( 'background-color', '#e6ffed' );
		setTimeout( function () { row.css( 'background-color', '' ); }, 700 );
	}
} )( jQuery );
```

- [ ] **Step 3: Write the admin CSS**

Create `assets/css/ole-print-stock-admin.css`:

```css
.ole-ps-table tr.ole-ps-low  td { background: #fff4e5; }
.ole-ps-table tr.ole-ps-neg  td { background: #fde7e9; font-weight: 600; }
.ole-ps-table td, .ole-ps-sheets td { vertical-align: middle; }
.ole-ps-sheets .select2-container { min-width: 260px; }
.ole-ps-wrap h2 { margin-top: 1.5em; }
```

- [ ] **Step 4: Wire the admin class in `OLE_Print_Stock::init()`**

In `includes/class-ole-print-stock.php`, inside `init()`, after the product/variation field hooks (Task 5), add:

```php
		OLE_Print_Stock_Admin::init();
```

- [ ] **Step 5: Commit**

```bash
git add includes/class-ole-print-stock-admin.php assets/js/ole-print-stock-admin.js assets/css/ole-print-stock-admin.css includes/class-ole-print-stock.php
git commit -m "feat(print-stock): stock admin page — inline stock, +printed, instruction-sheet CRUD

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

**Live verification (after deploy):** WooCommerce → "Print consumables" lists all stickers + sheets, lowest first, low rows amber / negative red; "Set" and "+ printed" update stock via AJAX and write `manual` ledger rows; creating a sheet with 2–3 products and stock 30, then editing/deleting it, works without reload errors.

---

## Task 7: Notifications — admin banner + order-list badge

**Files:**
- Modify: `includes/class-ole-print-stock.php` (banner + order-list column; email already added in Task 4)

**Interfaces:**
- Consumes: `OLE_Print_Stock_Store::low_count`, `OLE_Print_Stock::threshold_for`, order meta `DEPLETED_META`.

- [ ] **Step 1: Register banner + column hooks in `init()`**

In `includes/class-ole-print-stock.php`, inside `init()`, after `OLE_Print_Stock_Admin::init();`, add:

```php
		// Банер «пора друкувати».
		add_action( 'admin_notices', array( __CLASS__, 'low_banner' ) );
		// Значок у списку замовлень (HPOS + legacy).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_order_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_order_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_column_legacy' ), 10, 2 );
```

- [ ] **Step 2: Add the banner + column methods**

In `includes/class-ole-print-stock.php`, add:

```php
	public static function low_banner() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$o     = OLE_Settings::get();
		$count = OLE_Print_Stock_Store::low_count(
			(int) $o['print_stock_threshold_sticker'],
			(int) $o['print_stock_threshold_instruction']
		);
		if ( $count < 1 ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . OLE_Print_Stock_Admin::SLUG );
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( sprintf( /* translators: %d: number of low items. */ _n( '%d print consumable is low — time to print more.', '%d print consumables are low — time to print more.', $count, 'order-list-enhancer' ), $count ) ),
			esc_url( $url ),
			esc_html__( 'Open stock', 'order-list-enhancer' )
		);
	}

	public static function add_order_column( $columns ) {
		$columns['ole_depleted'] = __( 'Print', 'order-list-enhancer' );
		return $columns;
	}

	private static function badge_html( $depleted ) {
		if ( ! is_array( $depleted ) || empty( $depleted ) ) {
			return '';
		}
		$names = array();
		$neg   = false;
		foreach ( $depleted as $d ) {
			$stock   = (int) ( $d['stock'] ?? 0 );
			$neg     = $neg || $stock < 0;
			$names[] = ( $d['name'] ?? '' ) . ': ' . $stock;
		}
		$title = esc_attr( implode( ' | ', $names ) );
		$style = $neg ? 'background:#d63638' : 'background:#b26a00';
		return '<span title="' . $title . '" style="display:inline-block;padding:1px 7px;border-radius:9px;color:#fff;font-size:11px;' . $style . '">🖨️ ' . count( $names ) . '</span>';
	}

	public static function render_order_column( $column, $order ) {
		if ( 'ole_depleted' !== $column ) {
			return;
		}
		echo self::badge_html( $order->get_meta( self::DEPLETED_META ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function render_order_column_legacy( $column, $post_id ) {
		if ( 'ole_depleted' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order ) {
			echo self::badge_html( $order->get_meta( self::DEPLETED_META ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-ole-print-stock.php
git commit -m "feat(print-stock): low-stock admin banner + order-list depleted badge

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

**Live verification (after deploy):** when any consumable is ≤ its threshold, a warning banner appears on admin screens linking to the stock page; an order that pushed a consumable to/below threshold shows a "🖨️ N" badge in the orders list (red when negative), tooltip listing item names + remaining stock; a low-crossing order sends one email to the admin address listing the crossed items.

---

## Task 8: i18n (bg_BG) + version bump + changelog

**Files:**
- Modify: `languages/order-list-enhancer-bg_BG.po` (+ recompile `.mo`)
- Modify: `order-list-enhancer.php` (bump `OLE_VERSION` + header `Version`)
- Modify: `readme.txt` (changelog)

**Interfaces:** none (packaging).

- [ ] **Step 1: Bump the version**

In `order-list-enhancer.php`: change the header `* Version:` and the `define( 'OLE_VERSION', … )` from `1.0.31` to `1.0.32`.

- [ ] **Step 2: Add Bulgarian translations**

Append msgid/msgstr pairs to `languages/order-list-enhancer-bg_BG.po` for every new string introduced in Tasks 2–7. Use these translations:

```po
msgid "Print consumables (stickers & instructions)"
msgstr "Консумативи за печат (стикери и инструкции)"

msgid "Enable tracking"
msgstr "Включи проследяване"

msgid "Count printed stickers (per product/variation, by quantity) and instruction sheets (one per order) as orders come in, and warn when they run low."
msgstr "Отчитай печатните стикери (за продукт/вариация, по количество) и листовките с инструкции (по една на поръчка) при постъпване на поръчки и предупреждавай, когато свършват."

msgid "Sticker low threshold"
msgstr "Праг за стикери"

msgid "Warn (\"time to print\") when a sticker stock drops to this or below."
msgstr "Предупреди („време е за печат“), когато наличността от стикери падне до тази стойност или под нея."

msgid "Instruction low threshold"
msgstr "Праг за инструкции"

msgid "Warn when an instruction sheet stock drops to this or below."
msgstr "Предупреди, когато наличността от листовка падне до тази стойност или под нея."

msgid "Stock page"
msgstr "Страница с наличности"

msgid "Open consumables stock"
msgstr "Отвори наличностите"

msgid "Print consumables"
msgstr "Консумативи за печат"

msgid "Sticker stock"
msgstr "Наличност стикери"

msgid "Printed stickers on hand for this product. Decreases by the quantity ordered. Leave blank to not track."
msgstr "Налични печатни стикери за този продукт. Намалява с поръчаното количество. Оставете празно, за да не се проследява."

msgid "Current stock"
msgstr "Текуща наличност"

msgid "Name"
msgstr "Име"

msgid "Type"
msgstr "Тип"

msgid "Stock"
msgstr "Наличност"

msgid "Actions"
msgstr "Действия"

msgid "Instruction"
msgstr "Инструкция"

msgid "Sticker"
msgstr "Стикер"

msgid "No consumables yet. Set a product's \"Sticker stock\" or create an instruction sheet below."
msgstr "Все още няма консумативи. Задайте „Наличност стикери“ на продукт или създайте листовка по-долу."

msgid "Set"
msgstr "Задай"

msgid "+ printed"
msgstr "+ отпечатани"

msgid "Instruction sheets"
msgstr "Листовки с инструкции"

msgid "One sheet can cover several products. Each order containing any of its products consumes one copy."
msgstr "Една листовка може да покрива няколко продукта. Всяка поръчка, съдържаща някой от тях, изразходва едно копие."

msgid "Products"
msgstr "Продукти"

msgid "Search for products…"
msgstr "Търсене на продукти…"

msgid "New sheet name"
msgstr "Име на нова листовка"

msgid "Add"
msgstr "Добави"

msgid "Save"
msgstr "Запази"

msgid "Saved."
msgstr "Запазено."

msgid "Failed."
msgstr "Неуспешно."

msgid "Delete this instruction sheet?"
msgstr "Да изтрия ли тази листовка?"

msgid "How many printed copies to add?"
msgstr "Колко отпечатани копия да добавя?"

msgid "%d print consumable is low — time to print more."
msgid_plural "%d print consumables are low — time to print more."
msgstr[0] "%d консуматив за печат е на изчерпване — време е за печат."
msgstr[1] "%d консуматива за печат са на изчерпване — време е за печат."

msgid "Open stock"
msgstr "Отвори наличностите"

msgid "Print"
msgstr "Печат"

msgid "OLE — time to print more consumables"
msgstr "OLE — време е за печат на консумативи"

msgid "These items dropped to their low threshold:"
msgstr "Тези артикули достигнаха ниския си праг:"

msgid "%1$s \"%2$s\" — %3$d left"
msgstr "%1$s „%2$s“ — остават %3$d"
```

- [ ] **Step 3: Recompile the `.mo`**

Run: `msgfmt languages/order-list-enhancer-bg_BG.po -o languages/order-list-enhancer-bg_BG.mo`
Expected: no output, exit 0; `.mo` mtime updates.

- [ ] **Step 4: Changelog**

In `readme.txt`, add a `= 1.0.32 =` changelog entry under Changelog: `Print consumables: track sticker + instruction-sheet stock, auto-decrement at order placement with restore, per-type low thresholds, stock page, admin banner, order-list badge and email.`

- [ ] **Step 5: Commit**

```bash
git add order-list-enhancer.php languages/order-list-enhancer-bg_BG.po languages/order-list-enhancer-bg_BG.mo readme.txt
git commit -m "chore(print-stock): bg_BG translations, bump 1.0.32, changelog

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Final verification (live, after deploy)

Deploy per [[deploy-procedure]] (rsync whole plugin dir, `opcache_reset()`, flush caches). Then:

1. Enable "Print consumables" in OLE settings; thresholds 20 / 5. Confirm the three tables exist and `ole_print_stock_db = 1`.
2. Set a simple product's "Sticker stock" = 3; a variable product's variation sticker = 2; create an instruction sheet covering both products, stock 1.
3. Place an order (3× simple, 1× variation): sticker rows drop to 0 and 1; the sheet drops to 0; the order shows a 🖨️ badge; the banner appears; one email arrives.
4. Cancel the order: all three restore exactly (3 / 2 / 1); badge and banner clear; ledger shows matching `order` + `restore` rows.
5. Re-set the order to processing: consumes again.
6. Feature off → no fields, page, banner, badge, or decrements.

## Notes

Independent of the other OLE features; on branch `feature/print-stock-inventory`. The order-list **quick tags** feature from the same original request is a separate spec/plan.
