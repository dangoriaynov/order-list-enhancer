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
		add_action( 'wp_ajax_ole_ps_add_sticker', array( __CLASS__, 'ajax_add_sticker' ) );
		add_action( 'wp_ajax_ole_ps_delete_sticker', array( __CLASS__, 'ajax_delete_sticker' ) );
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
		$o = OLE_Settings::get();
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
					'saved'       => __( 'Saved.', 'order-list-enhancer' ),
					'error'       => __( 'Failed.', 'order-list-enhancer' ),
					'confirm'     => __( 'Delete this instruction sheet?', 'order-list-enhancer' ),
					'confirmSticker' => __( 'Delete this sticker?', 'order-list-enhancer' ),
					'addQ'        => __( 'How many printed copies to add?', 'order-list-enhancer' ),
					'save'        => __( 'Save', 'order-list-enhancer' ),
					'instruction' => __( 'Instruction', 'order-list-enhancer' ),
					'set'         => __( 'Set', 'order-list-enhancer' ),
					'addPrinted'  => __( '+ printed', 'order-list-enhancer' ),
				),
				'thresholds' => array(
					'sticker'     => (int) $o['print_stock_threshold_sticker'],
					'instruction' => (int) $o['print_stock_threshold_instruction'],
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

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every AJAX handler below verifies the nonce first via self::guard() (check_ajax_referer).
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
		OLE_Print_Stock::maybe_notify_low( $id, (int) $row['stock'], $stock );
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
		$after = $fresh ? (int) $fresh['stock'] : 0;
		OLE_Print_Stock::maybe_notify_low( $id, (int) $row['stock'], $after );
		wp_send_json_success( array( 'stock' => $after ) );
	}

	public static function ajax_save_sheet() {
		self::guard();
		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$stock    = isset( $_POST['stock'] ) ? (int) $_POST['stock'] : 0;
		$products = isset( $_POST['products'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['products'] ) ) : array();
		$products = array_values( array_unique( array_filter( array_map(
			function ( $pid ) {
				$p = wc_get_product( (int) $pid );
				if ( $p && $p->is_type( 'variation' ) ) {
					return (int) $p->get_parent_id();
				}
				return (int) $pid;
			},
			$products
		) ) ) );
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => 'name_required' ), 400 );
		}
		if ( $id ) {
			OLE_Print_Stock_Store::update_sheet( $id, $name, $products, $stock );
		} else {
			$id = OLE_Print_Stock_Store::create_sheet( $name, $products, $stock );
		}
		wp_send_json_success( array( 'id' => $id, 'name' => $name, 'stock' => $stock ) );
	}

	public static function ajax_delete_sheet() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			OLE_Print_Stock_Store::delete_sheet( $id );
		}
		wp_send_json_success();
	}

	public static function ajax_add_sticker() {
		self::guard();
		$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$stock      = isset( $_POST['stock'] ) ? (int) $_POST['stock'] : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => 'product_required' ), 400 );
		}
		$existing = OLE_Print_Stock_Store::get_sticker( $product_id );
		$before   = $existing ? (int) $existing['stock'] : PHP_INT_MAX; // new sticker: treat as "was above" so a low initial value notifies once
		$name     = wp_strip_all_tags( $product->get_formatted_name() );
		$id       = OLE_Print_Stock_Store::upsert_sticker( $product_id, $name, $stock );
		OLE_Print_Stock::maybe_notify_low( $id, $before, $stock );
		wp_send_json_success( array( 'id' => $id, 'name' => $name, 'stock' => $stock ) );
	}

	public static function ajax_delete_sticker() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			OLE_Print_Stock_Store::delete_sticker( $id );
		}
		wp_send_json_success();
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

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
		$rows     = OLE_Print_Stock_Store::all_consumables();
		$stickers = array_filter( $rows, function ( $r ) { return 'sticker' === $r['type']; } );
		$sheets   = OLE_Print_Stock_Store::sheets();
		?>
		<div class="wrap ole-ps-wrap">
			<h1><?php esc_html_e( 'Print consumables', 'order-list-enhancer' ); ?></h1>

			<h2><?php esc_html_e( 'Stickers', 'order-list-enhancer' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Sticker stock per product or variation — decreases by the ordered quantity. Add a product below: pick a variation for per-variation stock, or the product for one shared count.', 'order-list-enhancer' ); ?></p>
			<table class="widefat striped ole-ps-stickers">
				<thead><tr>
					<th><?php esc_html_e( 'Name', 'order-list-enhancer' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'Stock', 'order-list-enhancer' ); ?></th>
					<th style="width:300px"><?php esc_html_e( 'Actions', 'order-list-enhancer' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $stickers as $r ) : ?>
					<tr class="<?php echo esc_attr( self::status_class( $r ) ); ?>" data-id="<?php echo esc_attr( $r['id'] ); ?>">
						<td><?php echo esc_html( $r['name'] ); ?></td>
						<td><input type="number" step="1" class="ole-ps-stock" value="<?php echo esc_attr( (string) (int) $r['stock'] ); ?>" style="width:90px"/></td>
						<td>
							<button type="button" class="button ole-ps-save"><?php esc_html_e( 'Set', 'order-list-enhancer' ); ?></button>
							<button type="button" class="button ole-ps-add"><?php esc_html_e( '+ printed', 'order-list-enhancer' ); ?></button>
							<button type="button" class="button ole-ps-sticker-delete" aria-label="<?php esc_attr_e( 'Delete', 'order-list-enhancer' ); ?>">&times;</button>
						</td>
					</tr>
				<?php endforeach; ?>
					<tr class="ole-ps-sticker-new">
						<td>
							<select class="wc-product-search ole-ps-sticker-product" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'order-list-enhancer' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%"></select>
						</td>
						<td><input type="number" step="1" class="ole-ps-sticker-stock" value="0" style="width:90px"/></td>
						<td><button type="button" class="button button-primary ole-ps-sticker-add"><?php esc_html_e( 'Add', 'order-list-enhancer' ); ?></button></td>
					</tr>
				</tbody>
			</table>

			<h2 style="margin-top:2em"><?php esc_html_e( 'Instruction sheets', 'order-list-enhancer' ); ?></h2>
			<p class="description"><?php esc_html_e( 'One sheet can cover several products. Each order containing any of its products consumes one copy.', 'order-list-enhancer' ); ?></p>
			<table class="widefat ole-ps-sheets">
				<thead><tr>
					<th style="width:22%"><?php esc_html_e( 'Name', 'order-list-enhancer' ); ?></th>
					<th><?php esc_html_e( 'Products', 'order-list-enhancer' ); ?></th>
					<th style="width:100px"><?php esc_html_e( 'Stock', 'order-list-enhancer' ); ?></th>
					<th style="width:160px"><?php esc_html_e( 'Actions', 'order-list-enhancer' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $sheets as $s ) : ?>
					<tr class="ole-ps-sheet <?php echo esc_attr( self::status_class( $s ) ); ?>" data-id="<?php echo esc_attr( $s['id'] ); ?>">
						<td><input type="text" class="ole-ps-sheet-name regular-text" value="<?php echo esc_attr( $s['name'] ); ?>"/></td>
						<td>
							<select multiple class="wc-product-search ole-ps-sheet-products" data-placeholder="<?php esc_attr_e( 'Search for products…', 'order-list-enhancer' ); ?>" data-action="woocommerce_json_search_products" style="width:100%">
								<?php foreach ( $s['product_ids'] as $pid ) : ?>
									<?php $p = wc_get_product( $pid ); if ( ! $p ) { continue; } ?>
									<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="number" step="1" class="ole-ps-sheet-stock" value="<?php echo esc_attr( (string) (int) $s['stock'] ); ?>" style="width:90px"/></td>
						<td>
							<button type="button" class="button ole-ps-sheet-save"><?php esc_html_e( 'Save', 'order-list-enhancer' ); ?></button>
							<button type="button" class="button ole-ps-sheet-delete">&times;</button>
						</td>
					</tr>
				<?php endforeach; ?>
					<tr class="ole-ps-sheet ole-ps-sheet-new" data-id="0">
						<td><input type="text" class="ole-ps-sheet-name regular-text" placeholder="<?php esc_attr_e( 'New sheet name', 'order-list-enhancer' ); ?>"/></td>
						<td>
							<select multiple class="wc-product-search ole-ps-sheet-products" data-placeholder="<?php esc_attr_e( 'Search for products…', 'order-list-enhancer' ); ?>" data-action="woocommerce_json_search_products" style="width:100%"></select>
						</td>
						<td><input type="number" step="1" class="ole-ps-sheet-stock" value="0" style="width:90px"/></td>
						<td><button type="button" class="button button-primary ole-ps-sheet-save"><?php esc_html_e( 'Add', 'order-list-enhancer' ); ?></button></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
