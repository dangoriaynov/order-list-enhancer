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
