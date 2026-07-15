<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Шар БД для обліку витратних: 3 таблиці + журнал рухів.
 *
 * Таблиці власні (WP не має API для них), обсяги крихітні й лише в адмінці,
 * тому прямі запити тут доречні; імена таблиць передаються через %i.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated DB layer for the plugin's own tables; no WP API exists and result sets are tiny, admin-side only.
class ORDELIST_Print_Stock_Store {

	const DB_VERSION    = '2';
	const DB_VERSION_OPT = 'ordelist_print_stock_db';

	public static function table_consumable() {
		global $wpdb;
		return $wpdb->prefix . 'ordelist_consumable';
	}
	public static function table_link() {
		global $wpdb;
		return $wpdb->prefix . 'ordelist_consumable_product';
	}
	public static function table_log() {
		global $wpdb;
		return $wpdb->prefix . 'ordelist_consume_log';
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
				attachments TEXT NULL,
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
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $c, (int) $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_sticker( $ref_id ) {
		global $wpdb;
		$c = self::table_consumable();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE type = 'sticker' AND ref_id = %d", $c, (int) $ref_id ), ARRAY_A );
		return $row ?: null;
	}

	/** ID вкладень витратного (JSON-колонка attachments). */
	public static function get_attachments( $consumable_id ) {
		$row = self::get_consumable( (int) $consumable_id );
		return ORDELIST_Print_Stock_Calc::decode_attachments( $row['attachments'] ?? null );
	}

	/** Зберігає повний список ID вкладень (порожній список -> NULL). */
	public static function set_attachments( $consumable_id, $ids ) {
		global $wpdb;
		$ids = ORDELIST_Print_Stock_Calc::sanitize_attachment_ids( $ids );
		$wpdb->update(
			self::table_consumable(),
			array( 'attachments' => $ids ? wp_json_encode( $ids ) : null, 'updated_at' => self::now() ),
			array( 'id' => (int) $consumable_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
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
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET stock = stock + %d, updated_at = %s WHERE id = %d', $c, (int) $amount, self::now(), (int) $id ) );
		self::log( 0, (int) $id, (int) $amount, 'manual' );
		self::rearm_if_above( (int) $id );
	}

	public static function all_consumables() {
		global $wpdb;
		$c = self::table_consumable();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY type ASC, stock ASC', $c ), ARRAY_A );
	}

	public static function sticker_config() {
		global $wpdb;
		$c   = self::table_consumable();
		$out = array();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ref_id, id FROM %i WHERE type = 'sticker'", $c ), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['ref_id'] ] = (int) $r['id'];
		}
		return $out;
	}

	public static function sheets() {
		global $wpdb;
		$c = self::table_consumable();
		$l = self::table_link();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE type = 'instruction' ORDER BY name ASC", $c ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM %i WHERE consumable_id = %d', $l, (int) $row['id'] ) );
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

	public static function delete_sticker( $id ) {
		global $wpdb;
		$wpdb->delete( self::table_consumable(), array( 'id' => (int) $id, 'type' => 'sticker' ), array( '%d', '%s' ) );
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
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET stock = stock + %d, updated_at = %s WHERE id = %d', $c, (int) $delta, self::now(), (int) $consumable_id ) );
		$after  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT stock FROM %i WHERE id = %d', $c, (int) $consumable_id ) );
		$before = $after - (int) $delta;
		self::log( (int) $order_id, (int) $consumable_id, (int) $delta, (string) $reason );
		return array( 'before' => $before, 'after' => $after );
	}

	public static function ledger_net( $order_id ) {
		global $wpdb;
		$g   = self::table_log();
		$out = array();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT consumable_id, SUM(delta) net FROM %i WHERE order_id = %d GROUP BY consumable_id', $g, (int) $order_id ), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['consumable_id'] ] = (int) $r['net'];
		}
		return $out;
	}

	/** Чи вже списане замовлення за журналом (є витратний із сумарною дельтою < 0). */
	public static function is_consumed( $order_id ) {
		foreach ( self::ledger_net( (int) $order_id ) as $net ) {
			if ( (int) $net < 0 ) {
				return true;
			}
		}
		return false;
	}

	public static function set_low_notified( $id, $flag ) {
		global $wpdb;
		$wpdb->update( self::table_consumable(), array( 'low_notified' => (int) $flag ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}

	public static function low_count( $sticker_threshold, $instruction_threshold ) {
		global $wpdb;
		$c = self::table_consumable();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE ( type = 'sticker' AND stock <= %d ) OR ( type = 'instruction' AND stock <= %d )",
			$c,
			(int) $sticker_threshold,
			(int) $instruction_threshold
		) );
	}

	/** Якщо запас піднявся вище порогу свого типу - переозброїти low_notified. */
	private static function rearm_if_above( $id ) {
		$row = self::get_consumable( $id );
		if ( ! $row || (int) $row['low_notified'] === 0 ) {
			return;
		}
		$o         = ORDELIST_Settings::get();
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
