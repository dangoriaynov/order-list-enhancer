# Warranty Dates (Batch Expiry Tracking) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Per-product/variation stock batches with "valid until" dates: FIFO auto-consumption on orders, restore on cancel/refund, daily email + admin-banner warnings, and a central admin page to see and manage everything.

**Architecture:** Mirrors the existing print-stock (consumables) subsystem: a pure calc class (unit-tested without WordPress), a store class owning one custom table, a WP-glue class hooked to the same order events, and an admin page class with inline-AJAX editing. Spec: `docs/superpowers/specs/2026-07-13-warranty-dates-design.md`.

**Tech Stack:** WordPress 6.2+ / WooCommerce 8+ plugin, PHP 7.4-compatible, jQuery admin JS, framework-free PHP tests run with the CLI `php` binary (no local WordPress — WP-glue is verified by `php -l` + greps; runtime behavior on the live site later).

## Global Constraints

- Prefix everything: classes/constants `ORDELIST_*`, options/hooks/AJAX/meta `ordelist_*` / `_ordelist_*`, script+style handles `ordelist-*`. New CSS classes and new asset FILENAMES use `ole-` (`ole-wr-*`, `assets/js/ole-warranty.js`) — that is the repo's deliberate convention, do not "fix" it.
- Text domain is exactly `order-list-enhancer` in every gettext call (a rename may come later from the WP.org review — out of scope here).
- SQL: table names only via `%i` placeholders inside `$wpdb->prepare()`; the custom table is queried ONLY in `ORDELIST_Warranty_Store`.
- Every new PHP file starts with the `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard (test files instead `define( 'ABSPATH', true );`).
- PHP 7.4 syntax only: no enums, no arrow-fn `fn()`, no readonly, no named args. `??` is fine.
- Comments in the plugin source are Ukrainian (match the existing files); translator comments `/* translators: ... */` before every gettext call with placeholders.
- Commit after each task, message style `feat(warranty): …`. NEVER add Co-Authored-By/AI-attribution lines.
- Do NOT bump the plugin version or touch readme.txt/languages except where a task says so (release packaging happens outside this plan).
- Run every command from the repo root `/Users/danko/PycharmProjects/order-list-enhancer`.

---

### Task 1: Pure calc class (FIFO, status, notification gating) — TDD

**Files:**
- Create: `tests/warranty/test-calc.php`
- Create: `includes/class-ordelist-warranty-calc.php`

**Interfaces:**
- Consumes: nothing (pure PHP).
- Produces (used by Tasks 3 and 4):
  - `ORDELIST_Warranty_Calc::allocate( int $qty, array $batches ): array` — batches `[ ['id'=>int,'qty'=>int], … ]` pre-sorted oldest-expiry-first; returns `[ batch_id => taken>0 ]`, empty array if `$batches` is empty or `$qty <= 0`.
  - `ORDELIST_Warranty_Calc::status( string $expiry, string $today, int $window_days ): string` — `'expired' | 'soon' | 'ok'` (dates are `Y-m-d`).
  - `ORDELIST_Warranty_Calc::add_days( string $ymd, int $days ): string`
  - `ORDELIST_Warranty_Calc::classify( array $rows, string $today, int $window_days ): array` — rows `[ ['id','expiry','qty','notified'], … ]`; returns `[ 'soon' => [rows], 'expired' => [rows] ]` honoring the qty>0 and notified gates.
  - Constants `NOTIFIED_NONE = 0`, `NOTIFIED_SOON = 1`, `NOTIFIED_EXPIRED = 2`.

- [ ] **Step 1: Write the failing test**

Create `tests/warranty/test-calc.php`:

```php
<?php
// Standalone unit tests for ORDELIST_Warranty_Calc (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ordelist-warranty-calc.php';

$fails = 0;
function check( $cond, $msg ) {
	global $fails;
	if ( $cond ) { echo "ok   - $msg\n"; } else { echo "FAIL - $msg\n"; $fails++; }
}

// ---- allocate ----
$b = array(
	array( 'id' => 1, 'qty' => 5 ),  // oldest expiry
	array( 'id' => 2, 'qty' => 3 ),
	array( 'id' => 3, 'qty' => 4 ),  // newest expiry
);
check( ORDELIST_Warranty_Calc::allocate( 2, $b ) === array( 1 => 2 ), 'single batch covers demand' );
check( ORDELIST_Warranty_Calc::allocate( 5, $b ) === array( 1 => 5 ), 'exact fit drains oldest batch only' );
check( ORDELIST_Warranty_Calc::allocate( 7, $b ) === array( 1 => 5, 2 => 2 ), 'cascade into second batch' );
check( ORDELIST_Warranty_Calc::allocate( 12, $b ) === array( 1 => 5, 2 => 3, 3 => 4 ), 'exact fit across all batches' );
check( ORDELIST_Warranty_Calc::allocate( 15, $b ) === array( 1 => 5, 2 => 3, 3 => 7 ), 'over-demand pushes newest batch negative (4-7=-3)' );
check( ORDELIST_Warranty_Calc::allocate( 3, array() ) === array(), 'no batches -> empty map' );
check( ORDELIST_Warranty_Calc::allocate( 0, $b ) === array(), 'zero demand -> empty map' );
$holey = array(
	array( 'id' => 4, 'qty' => 0 ),   // already sold out
	array( 'id' => 5, 'qty' => -2 ),  // already negative
	array( 'id' => 6, 'qty' => 3 ),
);
check( ORDELIST_Warranty_Calc::allocate( 2, $holey ) === array( 6 => 2 ), 'skips qty<=0 batches' );
$dead = array(
	array( 'id' => 7, 'qty' => 0 ),
	array( 'id' => 8, 'qty' => 0 ),
);
check( ORDELIST_Warranty_Calc::allocate( 4, $dead ) === array( 8 => 4 ), 'all-zero batches: newest takes the whole demand' );

// ---- add_days / status ----
check( ORDELIST_Warranty_Calc::add_days( '2026-07-13', 30 ) === '2026-08-12', 'add_days crosses a month' );
check( ORDELIST_Warranty_Calc::add_days( '2026-12-25', 10 ) === '2027-01-04', 'add_days crosses a year' );
check( ORDELIST_Warranty_Calc::status( '2026-07-12', '2026-07-13', 30 ) === 'expired', 'yesterday is expired' );
check( ORDELIST_Warranty_Calc::status( '2026-07-13', '2026-07-13', 30 ) === 'soon',    'today itself is soon (not yet expired)' );
check( ORDELIST_Warranty_Calc::status( '2026-08-12', '2026-07-13', 30 ) === 'soon',    'today+window boundary is soon' );
check( ORDELIST_Warranty_Calc::status( '2026-08-13', '2026-07-13', 30 ) === 'ok',      'today+window+1 is ok' );

// ---- classify ----
$rows = array(
	array( 'id' => 1, 'expiry' => '2026-07-01', 'qty' => 5,  'notified' => 0 ), // expired, unwarned
	array( 'id' => 2, 'expiry' => '2026-07-01', 'qty' => 5,  'notified' => 2 ), // expired, already warned
	array( 'id' => 3, 'expiry' => '2026-07-01', 'qty' => 0,  'notified' => 0 ), // expired but sold out -> silent
	array( 'id' => 4, 'expiry' => '2026-08-01', 'qty' => 9,  'notified' => 0 ), // soon, unwarned
	array( 'id' => 5, 'expiry' => '2026-08-01', 'qty' => 9,  'notified' => 1 ), // soon, already warned
	array( 'id' => 6, 'expiry' => '2026-07-05', 'qty' => 9,  'notified' => 1 ), // was warned soon, NOW expired -> warn again
	array( 'id' => 7, 'expiry' => '2026-12-01', 'qty' => 9,  'notified' => 0 ), // far future -> silent
	array( 'id' => 8, 'expiry' => '2026-08-01', 'qty' => -1, 'notified' => 0 ), // negative qty -> silent
);
$c = ORDELIST_Warranty_Calc::classify( $rows, '2026-07-13', 30 );
$soon_ids    = array_map( function ( $r ) { return $r['id']; }, $c['soon'] );
$expired_ids = array_map( function ( $r ) { return $r['id']; }, $c['expired'] );
check( $soon_ids === array( 4 ), 'soon: only unwarned positive-qty rows inside the window' );
check( $expired_ids === array( 1, 6 ), 'expired: unwarned + soon-warned-now-expired rows' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/warranty/test-calc.php`
Expected: fatal error — `Failed opening required '…/includes/class-ordelist-warranty-calc.php'`, exit code non-zero.

- [ ] **Step 3: Write the implementation**

Create `includes/class-ordelist-warranty-calc.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Чиста логіка партій з термінами придатності (без WordPress).
 * Тестується ізольовано — див. tests/warranty/test-calc.php.
 */
class ORDELIST_Warranty_Calc {

	const NOTIFIED_NONE    = 0;
	const NOTIFIED_SOON    = 1;
	const NOTIFIED_EXPIRED = 2;

	/**
	 * FIFO-розподіл списання по партіях.
	 *
	 * @param int   $qty     скільки одиниць списати (>0)
	 * @param array $batches [ ['id'=>int,'qty'=>int], ... ] ВЖЕ відсортовані expiry ASC, id ASC
	 * @return array [ batch_id => taken>0 ]; порожньо без партій або без попиту
	 */
	public static function allocate( $qty, array $batches ) {
		$qty = (int) $qty;
		if ( $qty <= 0 || empty( $batches ) ) {
			return array();
		}
		$takes = array();
		$left  = $qty;
		foreach ( $batches as $b ) {
			if ( $left <= 0 ) {
				break;
			}
			$avail = (int) $b['qty'];
			if ( $avail <= 0 ) {
				continue;
			}
			$take                    = min( $left, $avail );
			$takes[ (int) $b['id'] ] = ( $takes[ (int) $b['id'] ] ?? 0 ) + $take;
			$left                   -= $take;
		}
		if ( $left > 0 ) {
			// Решта попиту — в найновішу партію: нехай іде в мінус, це сигнал розбіжності з полицею.
			$last                       = $batches[ count( $batches ) - 1 ];
			$takes[ (int) $last['id'] ] = ( $takes[ (int) $last['id'] ] ?? 0 ) + $left;
		}
		return $takes;
	}

	/** 'expired' | 'soon' | 'ok' для дати Y-m-d (лексикографічне порівняння дат коректне). */
	public static function status( $expiry, $today, $window_days ) {
		if ( $expiry < $today ) {
			return 'expired';
		}
		if ( $expiry <= self::add_days( $today, $window_days ) ) {
			return 'soon';
		}
		return 'ok';
	}

	/** Y-m-d + N днів (чистий PHP). */
	public static function add_days( $ymd, $days ) {
		$d = DateTime::createFromFormat( 'Y-m-d', (string) $ymd );
		if ( ! $d ) {
			return (string) $ymd;
		}
		$d->modify( '+' . (int) $days . ' days' );
		return $d->format( 'Y-m-d' );
	}

	/**
	 * Кому слати попередження: qty>0 і email для цього стану ще не йшов.
	 *
	 * @param array $rows [ ['id','expiry','qty','notified',...], ... ]
	 * @return array [ 'soon'=>[rows], 'expired'=>[rows] ]
	 */
	public static function classify( array $rows, $today, $window_days ) {
		$out = array(
			'soon'    => array(),
			'expired' => array(),
		);
		foreach ( $rows as $r ) {
			if ( (int) $r['qty'] <= 0 ) {
				continue;
			}
			$status   = self::status( (string) $r['expiry'], $today, $window_days );
			$notified = (int) $r['notified'];
			if ( 'expired' === $status && $notified < self::NOTIFIED_EXPIRED ) {
				$out['expired'][] = $r;
			} elseif ( 'soon' === $status && $notified < self::NOTIFIED_SOON ) {
				$out['soon'][] = $r;
			}
		}
		return $out;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/warranty/test-calc.php`
Expected: every line `ok   - …`, last line `ALL PASS`, exit 0.
Also run: `php -l includes/class-ordelist-warranty-calc.php` → `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add tests/warranty/test-calc.php includes/class-ordelist-warranty-calc.php
git commit -m "feat(warranty): pure FIFO/status/notification calc with standalone tests"
```

---

### Task 2: Store class (one custom table, version-gated dbDelta)

**Files:**
- Create: `includes/class-ordelist-warranty-store.php`

**Interfaces:**
- Consumes: `ORDELIST_Warranty_Calc` (nothing at runtime; sorted-order contract only).
- Produces (used by Tasks 3 and 4):
  - `maybe_upgrade(): void` — creates/updates the table when option `ordelist_warranty_db` ≠ `'1'`.
  - `add_batch( int $product_id, int $variation_id, string $expiry, int $qty, string $note ): int` (new id)
  - `update_batch( int $id, string $expiry, int $qty, string $note ): bool` — false if row missing; resets `notified` to 0 when the expiry changed.
  - `delete_batch( int $id ): void`
  - `get_batch( int $id ): ?array` (assoc row or null)
  - `batches_for_target( int $target_id, bool $is_variation ): array` — rows sorted `expiry ASC, id ASC`.
  - `all_batches(): array` — all rows, same sort.
  - `take_qty( int $id, int $take ): void` — `qty = qty - take`.
  - `give_back( int $id, int $amount ): bool` — `qty = qty + amount`; false when the row no longer exists.
  - `set_notified( int $id, int $state ): void`
  - `due_rows( string $horizon_ymd ): array` — rows `qty > 0 AND expiry <= horizon`, sorted.
  - `due_counts( string $today, string $horizon_ymd ): array` — `[ 'expired' => int, 'soon' => int ]` over `qty > 0` rows.
  - `covered_targets(): array` — set keyed `'v<variation_id>'` / `'p<product_id>'` → true.

- [ ] **Step 1: Write the store class**

Create `includes/class-ordelist-warranty-store.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Шар БД для партій з термінами придатності (одна таблиця).
 * Запити до таблиці — ЛИШЕ тут; імена таблиць — через %i (Plugin Check).
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated DB layer for the plugin's own table; tiny result sets, admin/cron only.
class ORDELIST_Warranty_Store {

	const DB_VERSION     = '1';
	const DB_VERSION_OPT = 'ordelist_warranty_db';

	public static function table_batches() {
		global $wpdb;
		return $wpdb->prefix . 'ordelist_batch';
	}

	/** Створити/оновити таблицю (dbDelta), якщо версія БД не збігається. Деплой — rsync, тому НЕ покладаємось на activation hook. */
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
		$t       = self::table_batches();
		dbDelta(
			"CREATE TABLE $t (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				expiry DATE NOT NULL,
				qty INT NOT NULL DEFAULT 0,
				note VARCHAR(200) NOT NULL DEFAULT '',
				notified TINYINT NOT NULL DEFAULT 0,
				created DATETIME NULL,
				PRIMARY KEY  (id),
				KEY product (product_id, variation_id),
				KEY expiry (expiry)
			) $charset;"
		);
	}

	private static function now() {
		return current_time( 'mysql' );
	}

	public static function get_batch( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_batches(), (int) $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function add_batch( $product_id, $variation_id, $expiry, $qty, $note ) {
		global $wpdb;
		$wpdb->insert(
			self::table_batches(),
			array(
				'product_id'   => (int) $product_id,
				'variation_id' => (int) $variation_id,
				'expiry'       => (string) $expiry,
				'qty'          => (int) $qty,
				'note'         => (string) $note,
				'notified'     => 0,
				'created'      => self::now(),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/** Оновлення партії; зміна дати переозброює notified (попередження підуть заново). */
	public static function update_batch( $id, $expiry, $qty, $note ) {
		$row = self::get_batch( $id );
		if ( ! $row ) {
			return false;
		}
		$data = array(
			'expiry' => (string) $expiry,
			'qty'    => (int) $qty,
			'note'   => (string) $note,
		);
		$fmt  = array( '%s', '%d', '%s' );
		if ( (string) $row['expiry'] !== (string) $expiry ) {
			$data['notified'] = 0;
			$fmt[]            = '%d';
		}
		global $wpdb;
		$wpdb->update( self::table_batches(), $data, array( 'id' => (int) $id ), $fmt, array( '%d' ) );
		return true;
	}

	public static function delete_batch( $id ) {
		global $wpdb;
		$wpdb->delete( self::table_batches(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/** Партії цілі (варіації або простого товару), відсортовані для FIFO. */
	public static function batches_for_target( $target_id, $is_variation ) {
		global $wpdb;
		$t = self::table_batches();
		if ( $is_variation ) {
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE variation_id = %d ORDER BY expiry ASC, id ASC', $t, (int) $target_id ), ARRAY_A );
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE product_id = %d AND variation_id = 0 ORDER BY expiry ASC, id ASC', $t, (int) $target_id ), ARRAY_A );
	}

	public static function all_batches() {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY expiry ASC, id ASC', self::table_batches() ), ARRAY_A );
	}

	/** FIFO-списання конкретної партії. */
	public static function take_qty( $id, $take ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET qty = qty - %d WHERE id = %d', self::table_batches(), (int) $take, (int) $id ) );
	}

	/** Повернення у конкретну партію; false — якщо партію вже видалили вручну. */
	public static function give_back( $id, $amount ) {
		global $wpdb;
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET qty = qty + %d WHERE id = %d', self::table_batches(), (int) $amount, (int) $id ) );
		return (bool) $n;
	}

	public static function set_notified( $id, $state ) {
		global $wpdb;
		$wpdb->update( self::table_batches(), array( 'notified' => (int) $state ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}

	/** Кандидати на попередження: є залишок і дата не далі за горизонт. */
	public static function due_rows( $horizon_ymd ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE qty > 0 AND expiry <= %s ORDER BY expiry ASC, id ASC', self::table_batches(), (string) $horizon_ymd ), ARRAY_A );
	}

	/** Лічильники для банера (лише партії із залишком). */
	public static function due_counts( $today, $horizon_ymd ) {
		global $wpdb;
		$t       = self::table_batches();
		$expired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE qty > 0 AND expiry < %s', $t, (string) $today ) );
		$soon    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE qty > 0 AND expiry >= %s AND expiry <= %s', $t, (string) $today, (string) $horizon_ymd ) );
		return array(
			'expired' => $expired,
			'soon'    => $soon,
		);
	}

	/** Всі цілі, що мають хоч одну партію: ключі 'v<id>' (варіація) / 'p<id>' (простий товар). */
	public static function covered_targets() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT product_id, variation_id FROM %i', self::table_batches() ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$key         = ( (int) $r['variation_id'] > 0 ) ? 'v' . (int) $r['variation_id'] : 'p' . (int) $r['product_id'];
			$out[ $key ] = true;
		}
		return $out;
	}
}
```

- [ ] **Step 2: Lint**

Run: `php -l includes/class-ordelist-warranty-store.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verify the Plugin Check constraints hold**

Run: `grep -n '\$wpdb->prepare' includes/class-ordelist-warranty-store.php | grep -v '%i' ; echo "exit=$?"`
Expected: no lines printed, `exit=1` (every prepare passes the table name via `%i`).
Run: `grep -rn "ordelist_batch" includes/*.php | grep -v class-ordelist-warranty-store.php ; echo "exit=$?"`
Expected: no lines printed, `exit=1` (table referenced only in the store).

- [ ] **Step 4: Commit**

```bash
git add includes/class-ordelist-warranty-store.php
git commit -m "feat(warranty): batch table store layer (version-gated dbDelta, %i identifiers)"
```

---

### Task 3: WP-glue — consume/restore, cron, email, banner

**Files:**
- Create: `includes/class-ordelist-warranty.php`

**Interfaces:**
- Consumes: `ORDELIST_Warranty_Store` (Task 2), `ORDELIST_Warranty_Calc` (Task 1), `ORDELIST_Print_Stock_Calc::is_live()` (existing, always loaded), `ORDELIST_Settings::get()/is_yes()` keys `warranty_enabled`/`warranty_days` (added in Task 5 — until then `is_yes` is false and `run_check()` exits before reading `warranty_days`), `ORDELIST_Warranty_Admin::SLUG` and `::init()` (Task 4 — `init()` is not reachable until Task 5 wires the feature on, so the forward reference is safe at every commit; PHP only resolves it when called).
- Produces (used by Tasks 4 and 5):
  - `ORDELIST_Warranty::init(): void` — full hook registration (call only when enabled).
  - `ORDELIST_Warranty::run_check(): void` — daily check; no-op when disabled.
  - `ORDELIST_Warranty::sync_schedule( array $opts ): void` — schedule/unschedule per saved options.
  - `ORDELIST_Warranty::unschedule(): void` — for the deactivation hook.
  - `ORDELIST_Warranty::target_name( array $row ): string` — display name for a batch row.
  - Constants `STATE_META = '_ordelist_wr_state'`, `CONSUMED_META = '_ordelist_batch_consumed'`, `CRON_HOOK = 'ordelist_warranty_daily'`.

- [ ] **Step 1: Write the glue class**

Create `includes/class-ordelist-warranty.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-glue термінів придатності: FIFO-списання партій за станом замовлення,
 * щоденна перевірка (WP-Cron) з email + банером, синхронізація розкладу.
 */
class ORDELIST_Warranty {

	const STATE_META    = '_ordelist_wr_state';       // '' | 'consumed' | 'restored'
	const CONSUMED_META = '_ordelist_batch_consumed'; // [ batch_id => qty ]
	const CRON_HOOK     = 'ordelist_warranty_daily';

	public static function init() {
		ORDELIST_Warranty_Store::maybe_upgrade();

		// Створення замовлення + переходи статусів — той самий набір хуків, що й у витратних.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order' ), 40, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order' ), 40, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 40, 4 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'on_order' ), 40, 1 );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'on_order' ), 40, 1 );

		add_action( self::CRON_HOOK, array( __CLASS__, 'run_check' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_scheduled' ) ); // самолікування пропущеного розкладу
		add_action( 'admin_notices', array( __CLASS__, 'banner' ) );

		ORDELIST_Warranty_Admin::init();
	}

	/* ---------- розклад ---------- */

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Після збереження налаштувань (спрацьовує і коли фічу щойно вимкнули — клас завантажений завжди). */
	public static function sync_schedule( array $opts ) {
		if ( ORDELIST_Settings::is_yes( $opts, 'warranty_enabled' ) ) {
			self::ensure_scheduled();
		} else {
			self::unschedule();
		}
	}

	/* ---------- списання / повернення ---------- */

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

	/** Єдина точка: приводить партії у відповідність до стану замовлення (ідемпотентно). */
	public static function reconcile( WC_Order $order ) {
		$live  = ORDELIST_Print_Stock_Calc::is_live( $order->get_status() ); // той самий словник «живих» статусів
		$state = (string) $order->get_meta( self::STATE_META );

		if ( $live && 'consumed' !== $state ) {
			self::consume( $order );
		} elseif ( ! $live && 'consumed' === $state ) {
			self::restore( $order );
		}
	}

	private static function consume( WC_Order $order ) {
		// Бекстоп ідемпотентності: мапа вже записана (прапорець стану загубився через збій) — не списувати вдруге.
		$existing = $order->get_meta( self::CONSUMED_META );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			$order->update_meta_data( self::STATE_META, 'consumed' );
			$order->save();
			return;
		}

		$map = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$qty = (int) $item->get_quantity();
			if ( $qty <= 0 ) {
				continue;
			}
			$vid     = (int) $item->get_variation_id();
			$target  = $vid > 0 ? $vid : (int) $item->get_product_id();
			$batches = ORDELIST_Warranty_Store::batches_for_target( $target, $vid > 0 );
			$takes   = ORDELIST_Warranty_Calc::allocate( $qty, $batches ); // без партій — порожньо, нічого не пишемо
			foreach ( $takes as $bid => $take ) {
				ORDELIST_Warranty_Store::take_qty( (int) $bid, (int) $take );
				$map[ (int) $bid ] = ( $map[ (int) $bid ] ?? 0 ) + (int) $take;
			}
		}

		$order->update_meta_data( self::STATE_META, 'consumed' );
		if ( $map ) {
			$order->update_meta_data( self::CONSUMED_META, $map );
		}
		$order->save();
	}

	private static function restore( WC_Order $order ) {
		$map = $order->get_meta( self::CONSUMED_META );
		if ( is_array( $map ) ) {
			foreach ( $map as $bid => $qty ) {
				ORDELIST_Warranty_Store::give_back( (int) $bid, (int) $qty ); // видалена партія — мовчки пропускається
			}
		}
		$order->update_meta_data( self::STATE_META, 'restored' );
		$order->delete_meta_data( self::CONSUMED_META );
		$order->save();
	}

	/* ---------- щоденна перевірка + сповіщення ---------- */

	/** Викликається кроном і як фолбек із сторінки партій; шле ОДИН зведений email. */
	public static function run_check() {
		$o = ORDELIST_Settings::get();
		if ( ! ORDELIST_Settings::is_yes( $o, 'warranty_enabled' ) ) {
			return;
		}
		$today   = current_time( 'Y-m-d' );
		$window  = (int) $o['warranty_days'];
		$horizon = ORDELIST_Warranty_Calc::add_days( $today, $window );
		$lists   = ORDELIST_Warranty_Calc::classify( ORDELIST_Warranty_Store::due_rows( $horizon ), $today, $window );

		foreach ( $lists['soon'] as $r ) {
			ORDELIST_Warranty_Store::set_notified( (int) $r['id'], ORDELIST_Warranty_Calc::NOTIFIED_SOON );
		}
		foreach ( $lists['expired'] as $r ) {
			ORDELIST_Warranty_Store::set_notified( (int) $r['id'], ORDELIST_Warranty_Calc::NOTIFIED_EXPIRED );
		}
		if ( $lists['soon'] || $lists['expired'] ) {
			self::send_email( $lists, $window );
		}
	}

	/** Назва товару/варіації партії; видалений товар → «#id». */
	public static function target_name( $row ) {
		$id = (int) ( (int) $row['variation_id'] > 0 ? $row['variation_id'] : $row['product_id'] );
		$p  = $id ? wc_get_product( $id ) : null;
		return $p ? wp_strip_all_tags( $p->get_formatted_name() ) : ( '#' . $id );
	}

	private static function send_email( $lists, $window ) {
		$subject = __( 'OLE — product expiry dates need attention', 'order-list-enhancer' );
		$lines   = array();
		if ( $lists['expired'] ) {
			$lines[] = __( 'Expired:', 'order-list-enhancer' );
			foreach ( $lists['expired'] as $r ) {
				$lines[] = self::email_line( $r );
			}
			$lines[] = '';
		}
		if ( $lists['soon'] ) {
			/* translators: %d: warning window in days. */
			$lines[] = sprintf( __( 'Expiring within %d days:', 'order-list-enhancer' ), (int) $window );
			foreach ( $lists['soon'] as $r ) {
				$lines[] = self::email_line( $r );
			}
		}
		wp_mail( get_option( 'admin_email' ), $subject, implode( "\n", $lines ) );
	}

	private static function email_line( $row ) {
		$name = self::target_name( $row );
		if ( '' !== (string) $row['note'] ) {
			$name .= ' (' . $row['note'] . ')';
		}
		/* translators: 1: product name, 2: expiry date, 3: quantity left. */
		return sprintf( __( '%1$s — %2$s, %3$d left', 'order-list-enhancer' ), $name, $row['expiry'], (int) $row['qty'] );
	}

	/* ---------- банер ---------- */

	public static function banner() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// Не докучати на самій сторінці партій — там усе й так видно.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, ORDELIST_Warranty_Admin::SLUG ) ) {
			return;
		}
		$o       = ORDELIST_Settings::get();
		$today   = current_time( 'Y-m-d' );
		$horizon = ORDELIST_Warranty_Calc::add_days( $today, (int) $o['warranty_days'] );
		$counts  = ORDELIST_Warranty_Store::due_counts( $today, $horizon );
		if ( $counts['expired'] < 1 && $counts['soon'] < 1 ) {
			return;
		}
		$bits = array();
		if ( $counts['soon'] ) {
			/* translators: 1: number of batches, 2: warning window in days. */
			$bits[] = sprintf( _n( '%1$d product batch expires within %2$d days', '%1$d product batches expire within %2$d days', $counts['soon'], 'order-list-enhancer' ), (int) $counts['soon'], (int) $o['warranty_days'] );
		}
		if ( $counts['expired'] ) {
			/* translators: %d: number of batches. */
			$bits[] = sprintf( _n( '%d batch already expired', '%d batches already expired', $counts['expired'], 'order-list-enhancer' ), (int) $counts['expired'] );
		}
		printf(
			'<div class="notice notice-warning"><p>%s — <a href="%s">%s</a></p></div>',
			esc_html( implode( ', ', $bits ) ),
			esc_url( admin_url( 'admin.php?page=' . ORDELIST_Warranty_Admin::SLUG ) ),
			esc_html__( 'Open warranty dates', 'order-list-enhancer' )
		);
	}
}
```

- [ ] **Step 2: Lint and re-run the calc tests**

Run: `php -l includes/class-ordelist-warranty.php && php tests/warranty/test-calc.php | tail -1`
Expected: `No syntax errors detected` then `ALL PASS`.

- [ ] **Step 3: Commit**

```bash
git add includes/class-ordelist-warranty.php
git commit -m "feat(warranty): FIFO consume/restore glue, daily cron check, email + admin banner"
```

---

### Task 4: Admin page, AJAX endpoints, JS, CSS

**Files:**
- Create: `includes/class-ordelist-warranty-admin.php`
- Create: `assets/js/ole-warranty.js`
- Create: `assets/css/ole-warranty.css`

**Interfaces:**
- Consumes: `ORDELIST_Warranty` (`run_check`, `target_name`), `ORDELIST_Warranty_Store`, `ORDELIST_Warranty_Calc::status()`, `ORDELIST_Settings::get()` (key `warranty_days`, Task 5), constants `ORDELIST_URL`, `ORDELIST_VERSION`.
- Produces: `ORDELIST_Warranty_Admin::SLUG = 'ordelist-warranty'`, `::init()` (called from `ORDELIST_Warranty::init()`), AJAX actions `wp_ajax_ordelist_wr_add|save|delete`, nonce `ordelist_wr`, JS global `ORDELIST_WR`.

- [ ] **Step 1: Write the admin class**

Create `includes/class-ordelist-warranty-admin.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Сторінка партій з термінами придатності + AJAX (додати / зберегти / видалити).
 */
class ORDELIST_Warranty_Admin {

	const SLUG = 'ordelist-warranty';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_ordelist_wr_add', array( __CLASS__, 'ajax_add' ) );
		add_action( 'wp_ajax_ordelist_wr_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_ordelist_wr_delete', array( __CLASS__, 'ajax_delete' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Warranty dates', 'order-list-enhancer' ),
			__( 'Warranty dates', 'order-list-enhancer' ),
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
		wp_enqueue_style( 'ordelist-warranty', ORDELIST_URL . 'assets/css/ole-warranty.css', array(), ORDELIST_VERSION );
		wp_enqueue_script( 'ordelist-warranty', ORDELIST_URL . 'assets/js/ole-warranty.js', array( 'jquery', 'wc-enhanced-select' ), ORDELIST_VERSION, true );
		wp_localize_script(
			'ordelist-warranty',
			'ORDELIST_WR',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ordelist_wr' ),
				'i18n'    => array(
					'error'   => __( 'Failed.', 'order-list-enhancer' ),
					'confirm' => __( 'Delete this batch?', 'order-list-enhancer' ),
					'save'    => __( 'Save', 'order-list-enhancer' ),
					'del'     => __( 'Delete', 'order-list-enhancer' ),
				),
			)
		);
	}

	private static function guard() {
		check_ajax_referer( 'ordelist_wr', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
	}

	/** '' або валідна дата Y-m-d (той самий підхід, що й delivery_vacation_until). */
	private static function clean_date( $raw ) {
		$raw = (string) $raw;
		return ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) ? $raw : '';
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- every handler verifies the nonce first via self::guard() (check_ajax_referer).
	public static function ajax_add() {
		self::guard();
		$picked = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$expiry = isset( $_POST['expiry'] ) ? self::clean_date( wp_unslash( $_POST['expiry'] ) ) : '';
		$qty    = isset( $_POST['qty'] ) ? (int) $_POST['qty'] : 0;
		$note   = isset( $_POST['note'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['note'] ) ), 0, 200 ) : '';
		$p      = $picked ? wc_get_product( $picked ) : null;
		if ( ! $p || '' === $expiry ) {
			wp_send_json_error( array( 'message' => 'bad_input' ), 400 );
		}
		if ( $p->is_type( 'variation' ) ) {
			$product_id   = (int) $p->get_parent_id();
			$variation_id = (int) $p->get_id();
		} else {
			$product_id   = (int) $p->get_id();
			$variation_id = 0;
		}
		$id = ORDELIST_Warranty_Store::add_batch( $product_id, $variation_id, $expiry, $qty, $note );
		ORDELIST_Warranty::run_check(); // нова партія може вже бути у вікні — маркуємо/шлемо одразу
		$row = ORDELIST_Warranty_Store::get_batch( $id );
		wp_send_json_success(
			array(
				'id'     => $id,
				'name'   => ORDELIST_Warranty::target_name( $row ),
				'url'    => get_edit_post_link( $product_id, 'raw' ),
				'expiry' => $row['expiry'],
				'qty'    => (int) $row['qty'],
				'note'   => $row['note'],
				'status' => self::status_class( $row ),
			)
		);
	}

	public static function ajax_save() {
		self::guard();
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$expiry = isset( $_POST['expiry'] ) ? self::clean_date( wp_unslash( $_POST['expiry'] ) ) : '';
		$qty    = isset( $_POST['qty'] ) ? (int) $_POST['qty'] : 0;
		$note   = isset( $_POST['note'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['note'] ) ), 0, 200 ) : '';
		if ( ! $id || '' === $expiry ) {
			wp_send_json_error( array( 'message' => 'bad_input' ), 400 );
		}
		if ( ! ORDELIST_Warranty_Store::update_batch( $id, $expiry, $qty, $note ) ) {
			wp_send_json_error( array( 'message' => 'not_found' ), 404 );
		}
		ORDELIST_Warranty::run_check(); // зміна дати могла переозброїти сповіщення
		$row = ORDELIST_Warranty_Store::get_batch( $id );
		wp_send_json_success( array( 'status' => self::status_class( $row ) ) );
	}

	public static function ajax_delete() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			ORDELIST_Warranty_Store::delete_batch( $id );
		}
		wp_send_json_success();
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/** CSS-клас рядка: мінус/прострочено → червоний, нуль → сірий, у вікні → жовтий. */
	public static function status_class( $row ) {
		if ( (int) $row['qty'] < 0 ) {
			return 'ole-wr-expired';
		}
		if ( 0 === (int) $row['qty'] ) {
			return 'ole-wr-zero';
		}
		$o      = ORDELIST_Settings::get();
		$status = ORDELIST_Warranty_Calc::status( (string) $row['expiry'], current_time( 'Y-m-d' ), (int) $o['warranty_days'] );
		if ( 'expired' === $status ) {
			return 'ole-wr-expired';
		}
		if ( 'soon' === $status ) {
			return 'ole-wr-soon';
		}
		return '';
	}

	public static function render() {
		ORDELIST_Warranty::run_check(); // фолбек, якщо cron не спрацював
		$rows = ORDELIST_Warranty_Store::all_batches();
		$gaps = self::targets_without_batches();
		?>
		<div class="wrap ole-wr-wrap">
			<h1><?php esc_html_e( 'Warranty dates', 'order-list-enhancer' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Batches of stock with their "valid until" dates. Sales reduce the oldest batch first; cancelled or refunded orders put the units back.', 'order-list-enhancer' ); ?></p>
			<table class="widefat striped ole-wr-table">
				<thead><tr>
					<th><?php esc_html_e( 'Product', 'order-list-enhancer' ); ?></th>
					<th style="width:160px"><?php esc_html_e( 'Valid until', 'order-list-enhancer' ); ?></th>
					<th style="width:110px"><?php esc_html_e( 'Quantity', 'order-list-enhancer' ); ?></th>
					<th><?php esc_html_e( 'Note', 'order-list-enhancer' ); ?></th>
					<th style="width:170px"><?php esc_html_e( 'Actions', 'order-list-enhancer' ); ?></th>
				</tr></thead>
				<tbody>
					<tr class="ole-wr-new">
						<td><select class="wc-product-search ole-wr-product" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'order-list-enhancer' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%"></select></td>
						<td><input type="date" class="ole-wr-expiry"/></td>
						<td><input type="number" step="1" class="ole-wr-qty" value="0" style="width:80px"/></td>
						<td><input type="text" class="ole-wr-note regular-text" maxlength="200" placeholder="<?php esc_attr_e( 'Note (lot number…)', 'order-list-enhancer' ); ?>"/></td>
						<td><button type="button" class="button button-primary ole-wr-add" disabled><?php esc_html_e( 'Add', 'order-list-enhancer' ); ?></button></td>
					</tr>
				<?php foreach ( $rows as $r ) : ?>
					<tr class="<?php echo esc_attr( self::status_class( $r ) ); ?>" data-id="<?php echo esc_attr( $r['id'] ); ?>">
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $r['product_id'], 'raw' ) ); ?>"><?php echo esc_html( ORDELIST_Warranty::target_name( $r ) ); ?></a></td>
						<td><input type="date" class="ole-wr-expiry" value="<?php echo esc_attr( $r['expiry'] ); ?>"/></td>
						<td><input type="number" step="1" class="ole-wr-qty" value="<?php echo esc_attr( (string) (int) $r['qty'] ); ?>" style="width:80px"/></td>
						<td><input type="text" class="ole-wr-note regular-text" maxlength="200" value="<?php echo esc_attr( $r['note'] ); ?>"/></td>
						<td>
							<button type="button" class="button ole-wr-save"><?php esc_html_e( 'Save', 'order-list-enhancer' ); ?></button>
							<button type="button" class="button ole-wr-delete" aria-label="<?php esc_attr_e( 'Delete', 'order-list-enhancer' ); ?>">&times;</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<details class="ole-wr-gaps">
				<summary><?php echo esc_html( sprintf( /* translators: %d: number of products/variations without batches. */ __( 'Products without batch data (%d)', 'order-list-enhancer' ), count( $gaps ) ) ); ?></summary>
				<ul>
					<?php foreach ( $gaps as $g ) : ?>
						<li><a href="<?php echo esc_url( $g['url'] ); ?>"><?php echo esc_html( $g['name'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</details>
		</div>
		<?php
	}

	/** Опубліковані НЕваріативні товари та окремі опубліковані варіації без жодної партії. */
	private static function targets_without_batches() {
		$covered  = ORDELIST_Warranty_Store::covered_targets();
		$out      = array();
		$products = wc_get_products(
			array(
				'limit'  => -1,
				'status' => 'publish',
			)
		);
		foreach ( $products as $p ) {
			if ( $p->is_type( 'variable' ) ) {
				foreach ( $p->get_children() as $vid ) {
					$v = wc_get_product( $vid );
					if ( ! $v || 'publish' !== $v->get_status() || isset( $covered[ 'v' . (int) $vid ] ) ) {
						continue;
					}
					$out[] = array(
						'name' => wp_strip_all_tags( $v->get_formatted_name() ),
						'url'  => (string) get_edit_post_link( (int) $p->get_id(), 'raw' ),
					);
				}
			} elseif ( ! isset( $covered[ 'p' . (int) $p->get_id() ] ) ) {
				$out[] = array(
					'name' => wp_strip_all_tags( $p->get_formatted_name() ),
					'url'  => (string) get_edit_post_link( (int) $p->get_id(), 'raw' ),
				);
			}
		}
		return $out;
	}
}
```

- [ ] **Step 2: Write the JS**

Create `assets/js/ole-warranty.js`:

```js
/* global ORDELIST_WR, jQuery */
( function ( $ ) {
	'use strict';
	if ( 'undefined' === typeof ORDELIST_WR ) { return; }

	function post( action, data ) {
		return $.post( ORDELIST_WR.ajaxUrl, $.extend( { action: action, nonce: ORDELIST_WR.nonce }, data ) );
	}

	// Короткий зелений спалах після збереження (клас, не inline-стиль).
	function flash( $row ) {
		$row.addClass( 'ole-wr-flash' );
		setTimeout( function () { $row.removeClass( 'ole-wr-flash' ); }, 700 );
	}

	function setStatus( $row, cls ) {
		$row.removeClass( 'ole-wr-expired ole-wr-soon ole-wr-zero' );
		if ( cls ) { $row.addClass( cls ); }
	}

	// «Add» активна лише коли вибрано товар і вказано дату.
	function syncAddBtn() {
		var $row = $( '.ole-wr-new' );
		var ok   = $row.find( '.ole-wr-product' ).val() && $row.find( '.ole-wr-expiry' ).val();
		$row.find( '.ole-wr-add' ).prop( 'disabled', ! ok );
	}
	$( syncAddBtn );
	$( document ).on( 'change input', '.ole-wr-new .ole-wr-product, .ole-wr-new .ole-wr-expiry', syncAddBtn );

	// Додати партію — рядок з'являється без перезавантаження сторінки.
	$( document ).on( 'click', '.ole-wr-add', function () {
		var $btn = $( this );
		var $row = $btn.closest( 'tr' );
		$btn.prop( 'disabled', true );
		post( 'ordelist_wr_add', {
			product: $row.find( '.ole-wr-product' ).val(),
			expiry:  $row.find( '.ole-wr-expiry' ).val(),
			qty:     $row.find( '.ole-wr-qty' ).val(),
			note:    $row.find( '.ole-wr-note' ).val()
		} ).done( function ( r ) {
			appendRow( ( r && r.data ) || {} );
			$row.find( '.ole-wr-product' ).val( null ).trigger( 'change' );
			$row.find( '.ole-wr-expiry' ).val( '' );
			$row.find( '.ole-wr-qty' ).val( 0 );
			$row.find( '.ole-wr-note' ).val( '' );
		} ).fail( function () {
			window.alert( ORDELIST_WR.i18n.error );
		} ).always( function () {
			syncAddBtn();
		} );
	} );

	// Зберегти рядок.
	$( document ).on( 'click', '.ole-wr-save', function () {
		var $row = $( this ).closest( 'tr' );
		if ( ! $row.find( '.ole-wr-expiry' ).val() ) { $row.find( '.ole-wr-expiry' ).focus(); return; }
		post( 'ordelist_wr_save', {
			id:     $row.data( 'id' ),
			expiry: $row.find( '.ole-wr-expiry' ).val(),
			qty:    $row.find( '.ole-wr-qty' ).val(),
			note:   $row.find( '.ole-wr-note' ).val()
		} ).done( function ( r ) {
			setStatus( $row, ( r && r.data && r.data.status ) || '' );
			flash( $row );
		} ).fail( function () {
			window.alert( ORDELIST_WR.i18n.error );
		} );
	} );

	// Видалити партію.
	$( document ).on( 'click', '.ole-wr-delete', function () {
		if ( ! window.confirm( ORDELIST_WR.i18n.confirm ) ) { return; }
		var $row = $( this ).closest( 'tr' );
		post( 'ordelist_wr_delete', { id: $row.data( 'id' ) } )
			.done( function () { $row.remove(); } )
			.fail( function () { window.alert( ORDELIST_WR.i18n.error ); } );
	} );

	// Новий збережений рядок (текст — через .text()/val(), XSS-safe); стане на місце за датою після перезавантаження.
	function appendRow( d ) {
		if ( ! d || ! d.id ) { return; }
		var $tr = $(
			'<tr data-id="' + parseInt( d.id, 10 ) + '">' +
			'<td><a></a></td>' +
			'<td><input type="date" class="ole-wr-expiry"/></td>' +
			'<td><input type="number" step="1" class="ole-wr-qty" style="width:80px"/></td>' +
			'<td><input type="text" class="ole-wr-note regular-text" maxlength="200"/></td>' +
			'<td><button type="button" class="button ole-wr-save">' + ORDELIST_WR.i18n.save + '</button> ' +
			'<button type="button" class="button ole-wr-delete" aria-label="' + ORDELIST_WR.i18n.del + '">×</button></td>' +
			'</tr>'
		);
		$tr.find( 'a' ).text( d.name || ( '#' + d.id ) ).attr( 'href', d.url || '#' );
		$tr.find( '.ole-wr-expiry' ).val( d.expiry || '' );
		$tr.find( '.ole-wr-qty' ).val( parseInt( d.qty, 10 ) || 0 );
		$tr.find( '.ole-wr-note' ).val( d.note || '' );
		setStatus( $tr, d.status || '' );
		$( '.ole-wr-new' ).after( $tr );
		flash( $tr );
	}
} )( jQuery );
```

- [ ] **Step 3: Write the CSS**

Create `assets/css/ole-warranty.css`:

```css
.ole-wr-table input[type="date"] { min-width: 140px; }
.ole-wr-expired > td { background: #fbeaea !important; }
.ole-wr-soon > td { background: #fff8e5 !important; }
.ole-wr-zero > td { background: #f0f0f1 !important; color: #757575; }
.ole-wr-flash > td { background: #e6f6e6 !important; transition: background 0.3s; }
.ole-wr-gaps { margin-top: 2em; }
.ole-wr-gaps summary { cursor: pointer; font-weight: 600; }
.ole-wr-gaps ul { margin: 0.5em 0 0 1.5em; list-style: disc; }
```

- [ ] **Step 4: Lint + prefix audit**

Run: `php -l includes/class-ordelist-warranty-admin.php`
Expected: `No syntax errors detected`.
Run: `node --check assets/js/ole-warranty.js && echo JS-OK`
Expected: `JS-OK`.
Run: `grep -nE "wp_ajax_|wp_create_nonce|check_ajax_referer|add_submenu_page|wp_enqueue_(script|style)|wp_localize_script" includes/class-ordelist-warranty-admin.php | grep -v "ordelist" ; echo "exit=$?"`
Expected: only lines that contain no registered name of our own (the two core handles `wc-enhanced-select` / `woocommerce_admin_styles` are allowed); every `ordelist_wr_*`, `ordelist_wr`, `ordelist-warranty` name carries the prefix. Manually confirm nothing unprefixed remains.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ordelist-warranty-admin.php assets/js/ole-warranty.js assets/css/ole-warranty.css
git commit -m "feat(warranty): batches admin page with inline AJAX editing and coverage-gap list"
```

---

### Task 5: Settings, wiring, cron lifecycle — feature becomes live

**Files:**
- Modify: `includes/class-ordelist-settings.php:51-54` (defaults) and `:158-159` area (clamps in `get()`)
- Modify: `includes/class-ordelist-settings-page.php:547-572` (`render_tab_inventory`) and `:692-697` (`ajax_save` tail)
- Modify: `order-list-enhancer.php:44-49` (requires) and end of file (deactivation hook)
- Modify: `includes/class-ordelist-plugin.php:49-54` (feature gating)

**Interfaces:**
- Consumes: `ORDELIST_Warranty::init()/sync_schedule()/unschedule()` (Task 3).
- Produces: settings keys `warranty_enabled` (default `'no'`) and `warranty_days` (default `30`, clamped 1–365) available via `ORDELIST_Settings::get()`.

- [ ] **Step 1: Add defaults**

In `includes/class-ordelist-settings.php`, `defaults()`, right after the `print_stock_threshold_instruction` line, add:

```php
			'warranty_enabled'                   => 'no',  // партії з термінами придатності + сповіщення
			'warranty_days'                      => 30,    // вікно попередження, днів
```

- [ ] **Step 2: Clamp in `get()`**

Same file, right after the `print_stock_threshold_instruction` clamp line, add:

```php
		$opts['warranty_days'] = max( 1, min( 365, (int) $opts['warranty_days'] ) );
```

- [ ] **Step 3: Settings card in the Inventory tab**

In `includes/class-ordelist-settings-page.php`, `render_tab_inventory()`, immediately after the existing `self::card_close();` (line ~571, still inside the method), add:

```php
		self::card_open(
			__( 'Warranty dates', 'order-list-enhancer' ),
			__( 'Track stock batches with their "valid until" dates, auto-consume the oldest batch as orders come in, and get an email + admin banner before dates arrive.', 'order-list-enhancer' ),
			array( 'name' => 'warranty_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'warranty_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Warn ahead, days', 'order-list-enhancer' ); ?></th>
				<td><input type="number" name="warranty_days" min="1" max="365" step="1" value="<?php echo esc_attr( (string) $o['warranty_days'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Email + banner when a batch is within this many days of its date. Allowed range 1–365, default 30; empty or out-of-range values are clamped when saving.', 'order-list-enhancer' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Batches page', 'order-list-enhancer' ); ?></th>
				<td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ordelist-warranty' ) ); ?>"><?php esc_html_e( 'Open warranty dates', 'order-list-enhancer' ); ?></a></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();
```

- [ ] **Step 4: Persist the new keys + sync the cron schedule on save**

Same file, in `ajax_save()`: inside the `$opts = array( … );` literal, after the `'print_stock_threshold_instruction'` entry, add:

```php
			'warranty_enabled'                  => $bool( 'warranty_enabled' ),
			'warranty_days'                     => isset( $in['warranty_days'] ) ? max( 1, min( 365, (int) $in['warranty_days'] ) ) : 30,
```

And between `update_option( ORDELIST_Settings::OPTION, $opts );` and `wp_send_json_success( … );` add:

```php
		ORDELIST_Warranty::sync_schedule( $opts );
```

- [ ] **Step 5: Requires, gating, deactivation hook**

In `order-list-enhancer.php`, after the `class-ordelist-print-stock-admin.php` require, add:

```php
require_once ORDELIST_DIR . 'includes/class-ordelist-warranty-calc.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-warranty-store.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-warranty.php';
require_once ORDELIST_DIR . 'includes/class-ordelist-warranty-admin.php';
```

At the end of the same file (after the `plugins_loaded` block), add:

```php
// Прибрати щоденну перевірку термінів придатності при деактивації плагіна.
register_deactivation_hook( __FILE__, array( 'ORDELIST_Warranty', 'unschedule' ) );
```

In `includes/class-ordelist-plugin.php`, `__construct()`, after the `print_stock_enabled` block, add:

```php
		if ( ORDELIST_Settings::is_yes( $opts, 'warranty_enabled' ) ) {
			ORDELIST_Warranty::init();
		}
```

- [ ] **Step 6: Lint everything + full test run**

Run: `for f in order-list-enhancer.php includes/*.php; do php -l "$f" >/dev/null || echo "LINT FAIL: $f"; done; echo LINT-DONE`
Expected: only `LINT-DONE`.
Run: `for t in tests/*/test-*.php; do php "$t" >/dev/null 2>&1 || echo "FAIL $t"; done; echo TESTS-DONE`
Expected: only `TESTS-DONE` (all 8 suites pass, including `tests/warranty/test-calc.php`).

- [ ] **Step 7: Commit**

```bash
git add includes/class-ordelist-settings.php includes/class-ordelist-settings-page.php includes/class-ordelist-plugin.php order-list-enhancer.php
git commit -m "feat(warranty): settings card (enable + warn-ahead days), wiring, cron schedule lifecycle"
```

---

### Task 6: Bulgarian i18n + final verification

**Files:**
- Modify: `languages/order-list-enhancer.pot` (append new template entries)
- Modify: `languages/order-list-enhancer-bg_BG.po` (append translated entries)
- Rebuild: `languages/order-list-enhancer-bg_BG.mo`

**Interfaces:**
- Consumes: every gettext string added in Tasks 3–5.
- Produces: 100% translated bg_BG catalog (repo rule: never ship untranslated admin strings).

Already-existing msgids reused by this feature need NO new entries (`Failed.`, `Save`, `Delete`, `Add`, `Actions`, `Search for a product…`). The NEW msgids are exactly the 21 below.

- [ ] **Step 1: Append template entries to the POT**

Append to `languages/order-list-enhancer.pot` (before any trailing newline; `#:` references may be approximate — they are comments):

```
#: includes/class-ordelist-warranty.php
msgid "OLE — product expiry dates need attention"
msgstr ""

#: includes/class-ordelist-warranty.php
msgid "Expired:"
msgstr ""

#. translators: %d: warning window in days.
#: includes/class-ordelist-warranty.php
msgid "Expiring within %d days:"
msgstr ""

#. translators: 1: product name, 2: expiry date, 3: quantity left.
#: includes/class-ordelist-warranty.php
msgid "%1$s — %2$s, %3$d left"
msgstr ""

#. translators: 1: number of batches, 2: warning window in days.
#: includes/class-ordelist-warranty.php
msgid "%1$d product batch expires within %2$d days"
msgid_plural "%1$d product batches expire within %2$d days"
msgstr[0] ""
msgstr[1] ""

#. translators: %d: number of batches.
#: includes/class-ordelist-warranty.php
msgid "%d batch already expired"
msgid_plural "%d batches already expired"
msgstr[0] ""
msgstr[1] ""

#: includes/class-ordelist-warranty.php includes/class-ordelist-settings-page.php
msgid "Open warranty dates"
msgstr ""

#: includes/class-ordelist-warranty-admin.php includes/class-ordelist-settings-page.php
msgid "Warranty dates"
msgstr ""

#: includes/class-ordelist-warranty-admin.php
msgid "Delete this batch?"
msgstr ""

#: includes/class-ordelist-warranty-admin.php
msgid "Batches of stock with their \"valid until\" dates. Sales reduce the oldest batch first; cancelled or refunded orders put the units back."
msgstr ""

#: includes/class-ordelist-warranty-admin.php
msgid "Product"
msgstr ""

#: includes/class-ordelist-warranty-admin.php
msgid "Valid until"
msgstr ""

#: includes/class-ordelist-warranty-admin.php
msgid "Quantity"
msgstr ""

#: includes/class-ordelist-warranty-admin.php
msgid "Note"
msgstr ""

#: includes/class-ordelist-warranty-admin.php
msgid "Note (lot number…)"
msgstr ""

#. translators: %d: number of products/variations without batches.
#: includes/class-ordelist-warranty-admin.php
msgid "Products without batch data (%d)"
msgstr ""

#: includes/class-ordelist-settings-page.php
msgid "Track stock batches with their \"valid until\" dates, auto-consume the oldest batch as orders come in, and get an email + admin banner before dates arrive."
msgstr ""

#: includes/class-ordelist-settings-page.php
msgid "Warn ahead, days"
msgstr ""

#: includes/class-ordelist-settings-page.php
msgid "Email + banner when a batch is within this many days of its date. Allowed range 1–365, default 30; empty or out-of-range values are clamped when saving."
msgstr ""

#: includes/class-ordelist-settings-page.php
msgid "Batches page"
msgstr ""
```

(That is 20 blocks; "Open warranty dates" and "Warranty dates" are each used in two files — one entry each. If any msgid above already exists in the POT — `grep` first — skip the duplicate block; `msgfmt` fails on duplicates.)

- [ ] **Step 2: Append Bulgarian translations to the .po**

First check for duplicates: `for s in "Product" "Quantity" "Note" "Valid until"; do grep -n "^msgid \"$s\"$" languages/order-list-enhancer-bg_BG.po; done` — skip any block whose msgid already exists.

Append to `languages/order-list-enhancer-bg_BG.po`:

```
msgid "OLE — product expiry dates need attention"
msgstr "OLE — срокове на годност изискват внимание"

msgid "Expired:"
msgstr "Изтекли:"

#. translators: %d: warning window in days.
msgid "Expiring within %d days:"
msgstr "Изтичат до %d дни:"

#. translators: 1: product name, 2: expiry date, 3: quantity left.
msgid "%1$s — %2$s, %3$d left"
msgstr "%1$s — %2$s, остават %3$d"

#. translators: 1: number of batches, 2: warning window in days.
msgid "%1$d product batch expires within %2$d days"
msgid_plural "%1$d product batches expire within %2$d days"
msgstr[0] "%1$d партида изтича до %2$d дни"
msgstr[1] "%1$d партиди изтичат до %2$d дни"

#. translators: %d: number of batches.
msgid "%d batch already expired"
msgid_plural "%d batches already expired"
msgstr[0] "%d партида вече е изтекла"
msgstr[1] "%d партиди вече са изтекли"

msgid "Open warranty dates"
msgstr "Отвори сроковете на годност"

msgid "Warranty dates"
msgstr "Срокове на годност"

msgid "Delete this batch?"
msgstr "Да изтрия ли тази партида?"

msgid "Batches of stock with their \"valid until\" dates. Sales reduce the oldest batch first; cancelled or refunded orders put the units back."
msgstr "Партиди стока със срок на годност („валидно до“). Продажбите намаляват първо най-старата партида; отменени или възстановени поръчки връщат бройките."

msgid "Product"
msgstr "Продукт"

msgid "Valid until"
msgstr "Валидно до"

msgid "Quantity"
msgstr "Количество"

msgid "Note"
msgstr "Бележка"

msgid "Note (lot number…)"
msgstr "Бележка (партиден номер…)"

#. translators: %d: number of products/variations without batches.
msgid "Products without batch data (%d)"
msgstr "Продукти без партиди (%d)"

msgid "Track stock batches with their \"valid until\" dates, auto-consume the oldest batch as orders come in, and get an email + admin banner before dates arrive."
msgstr "Следи партиди стока с дата „валидно до“, изписва автоматично най-старата партида при нова поръчка и предупреждава с имейл + банер преди датата."

msgid "Warn ahead, days"
msgstr "Предупреждение, дни"

msgid "Email + banner when a batch is within this many days of its date. Allowed range 1–365, default 30; empty or out-of-range values are clamped when saving."
msgstr "Имейл + банер, когато партида е на толкова дни от датата си. Позволен диапазон 1–365, по подразбиране 30; празни или извън диапазона стойности се ограничават при запис."

msgid "Batches page"
msgstr "Страница с партиди"
```

- [ ] **Step 3: Rebuild the .mo and verify 100%**

Run: `msgfmt --statistics -o languages/order-list-enhancer-bg_BG.mo languages/order-list-enhancer-bg_BG.po`
Expected: `N translated messages.` with **no** `untranslated` / `fuzzy` counts (N = previous count + 21).
If msgfmt reports duplicates or syntax errors, fix the .po before proceeding.

- [ ] **Step 4: Final sweep — tests, lint, prefix audit**

```bash
for t in tests/*/test-*.php; do php "$t" | tail -1; done
for f in order-list-enhancer.php uninstall.php includes/*.php; do php -l "$f" >/dev/null || echo "LINT FAIL: $f"; done; echo LINT-DONE
grep -rnE "OLE_[A-Za-z]|[^a-zA-Z_]ole_" includes/class-ordelist-warranty*.php assets/js/ole-warranty.js | grep -vE "ORDELIST_|ole-wr-" ; echo "prefix-audit exit=$?"
```

Expected: eight `ALL PASS` lines; `LINT-DONE` with no failures; prefix audit prints nothing and `exit=1`.

- [ ] **Step 5: Commit**

```bash
git add languages/order-list-enhancer.pot languages/order-list-enhancer-bg_BG.po languages/order-list-enhancer-bg_BG.mo
git commit -m "i18n(warranty): Bulgarian translations for the batch expiry feature, rebuild .mo"
```

---

## Out of scope for this plan (deliberate)

- Version bump, readme.txt changelog, and the release zip — done in the release pass, whose version number depends on the in-flight WP.org review (slug/text-domain outcome).
- The optional live-WP integration test (`tests/warranty/it-consume.php`) — can be added later like the extras `it-*` scripts.
- Live-site deploy: the table auto-creates on first admin load via `maybe_upgrade()`; feature starts disabled, so no data migration is needed.
