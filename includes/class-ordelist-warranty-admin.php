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
