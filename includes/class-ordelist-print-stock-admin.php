<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Сторінка запасу витратних + AJAX (інлайн-редагування, +N, CRUD аркушів).
 */
class ORDELIST_Print_Stock_Admin {

	const SLUG = 'ordelist-print-stock';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_ordelist_ps_set_stock', array( __CLASS__, 'ajax_set_stock' ) );
		add_action( 'wp_ajax_ordelist_ps_add_stock', array( __CLASS__, 'ajax_add_stock' ) );
		add_action( 'wp_ajax_ordelist_ps_save_sheet', array( __CLASS__, 'ajax_save_sheet' ) );
		add_action( 'wp_ajax_ordelist_ps_delete_sheet', array( __CLASS__, 'ajax_delete_sheet' ) );
		add_action( 'wp_ajax_ordelist_ps_add_sticker', array( __CLASS__, 'ajax_add_sticker' ) );
		add_action( 'wp_ajax_ordelist_ps_delete_sticker', array( __CLASS__, 'ajax_delete_sticker' ) );
		add_action( 'wp_ajax_ordelist_ps_set_files', array( __CLASS__, 'ajax_set_files' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Print consumables', 'ordelist' ),
			__( 'Print consumables', 'ordelist' ),
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
		wp_enqueue_media();
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'ordelist-print-stock-admin', ORDELIST_URL . 'assets/css/ole-print-stock-admin.css', array(), ORDELIST_VERSION );
		wp_enqueue_script( 'ordelist-print-stock-admin', ORDELIST_URL . 'assets/js/ole-print-stock-admin.js', array( 'jquery', 'wc-enhanced-select' ), ORDELIST_VERSION, true );
		wp_localize_script(
			'ordelist-print-stock-admin',
			'ORDELIST_PS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ordelist_ps' ),
				'i18n'    => array(
					'saved'       => __( 'Saved.', 'ordelist' ),
					'error'       => __( 'Failed.', 'ordelist' ),
					'confirm'     => __( 'Delete this instruction sheet?', 'ordelist' ),
					'confirmSticker' => __( 'Delete this sticker?', 'ordelist' ),
					'addQ'        => __( 'How many printed copies to add?', 'ordelist' ),
					'save'        => __( 'Save', 'ordelist' ),
					'instruction' => __( 'Instruction', 'ordelist' ),
					'set'         => __( 'Set', 'ordelist' ),
					'addPrinted'  => __( '+ printed', 'ordelist' ),
					'addFile'     => __( '+ File', 'ordelist' ),
					'filesTitle'  => __( 'Choose printable files', 'ordelist' ),
					'filesButton' => __( 'Attach', 'ordelist' ),
				),
				'thresholds' => array(
					'sticker'     => (int) $o['print_stock_threshold_sticker'],
					'instruction' => (int) $o['print_stock_threshold_instruction'],
				),
			)
		);
	}

	private static function guard() {
		check_ajax_referer( 'ordelist_ps', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every AJAX handler below verifies the nonce first via self::guard() (check_ajax_referer).
	public static function ajax_set_stock() {
		self::guard();
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$stock = isset( $_POST['stock'] ) ? (int) $_POST['stock'] : 0;
		$row   = $id ? ORDELIST_Print_Stock_Store::get_consumable( $id ) : null;
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'not_found' ), 404 );
		}
		$diff = $stock - (int) $row['stock'];
		ORDELIST_Print_Stock_Store::set_stock_absolute( $id, $stock, $diff );
		ORDELIST_Print_Stock::maybe_notify_low( $id, (int) $row['stock'], $stock );
		wp_send_json_success( array( 'stock' => $stock ) );
	}

	public static function ajax_add_stock() {
		self::guard();
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$amount = isset( $_POST['amount'] ) ? (int) $_POST['amount'] : 0;
		$row    = $id ? ORDELIST_Print_Stock_Store::get_consumable( $id ) : null;
		if ( ! $row || 0 === $amount ) {
			wp_send_json_error( array( 'message' => 'bad' ), 400 );
		}
		ORDELIST_Print_Stock_Store::add_stock( $id, $amount );
		$fresh = ORDELIST_Print_Stock_Store::get_consumable( $id );
		$after = $fresh ? (int) $fresh['stock'] : 0;
		ORDELIST_Print_Stock::maybe_notify_low( $id, (int) $row['stock'], $after );
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
			ORDELIST_Print_Stock_Store::update_sheet( $id, $name, $products, $stock );
		} else {
			$id = ORDELIST_Print_Stock_Store::create_sheet( $name, $products, $stock );
		}
		wp_send_json_success( array( 'id' => $id, 'name' => $name, 'stock' => $stock ) );
	}

	public static function ajax_delete_sheet() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			ORDELIST_Print_Stock_Store::delete_sheet( $id );
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
		$existing = ORDELIST_Print_Stock_Store::get_sticker( $product_id );
		$before   = $existing ? (int) $existing['stock'] : PHP_INT_MAX; // new sticker: treat as "was above" so a low initial value notifies once
		$name     = wp_strip_all_tags( $product->get_formatted_name() );
		$id       = ORDELIST_Print_Stock_Store::upsert_sticker( $product_id, $name, $stock );
		ORDELIST_Print_Stock::maybe_notify_low( $id, $before, $stock );
		wp_send_json_success( array( 'id' => $id, 'name' => $name, 'stock' => $stock ) );
	}

	public static function ajax_delete_sticker() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			ORDELIST_Print_Stock_Store::delete_sticker( $id );
		}
		wp_send_json_success();
	}

	const ALLOWED_MIMES = array( 'application/pdf', 'image/jpeg', 'image/png' );

	/** Внутрішній HTML комірки "Files": чіпи + кнопка додавання. */
	public static function files_cell_html( $ids ) {
		$html = '';
		foreach ( ORDELIST_Print_Stock_Calc::sanitize_attachment_ids( $ids ) as $att_id ) {
			$url = wp_get_attachment_url( $att_id );
			if ( $url ) {
				$name  = get_the_title( $att_id );
				$name  = '' !== $name ? $name : wp_basename( $url );
				$html .= '<span class="ole-ps-file" data-att="' . esc_attr( (string) $att_id ) . '">'
					. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $name ) . '</a>'
					. ' <button type="button" class="ole-ps-file-remove" aria-label="' . esc_attr__( 'Remove file', 'ordelist' ) . '">&times;</button></span> ';
			} else {
				$html .= '<span class="ole-ps-file ole-ps-file-missing" data-att="' . esc_attr( (string) $att_id ) . '">'
					. esc_html__( '(file deleted)', 'ordelist' )
					. ' <button type="button" class="ole-ps-file-remove" aria-label="' . esc_attr__( 'Remove file', 'ordelist' ) . '">&times;</button></span> ';
			}
		}
		$html .= '<button type="button" class="button ole-ps-file-add">' . esc_html__( '+ File', 'ordelist' ) . '</button>';
		return $html;
	}

	public static function ajax_set_files() {
		self::guard();
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$row = $id ? ORDELIST_Print_Stock_Store::get_consumable( $id ) : null;
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'not_found' ), 404 );
		}
		$csv = isset( $_POST['attachments'] ) ? sanitize_text_field( wp_unslash( $_POST['attachments'] ) ) : '';
		$ids = ORDELIST_Print_Stock_Calc::sanitize_attachment_ids( '' === $csv ? array() : explode( ',', $csv ) );
		// Тільки реальні вкладення дозволених типів; невалідні мовчки відкидаються.
		$ids = array_values( array_filter( $ids, function ( $att_id ) {
			return 'attachment' === get_post_type( $att_id )
				&& in_array( (string) get_post_mime_type( $att_id ), self::ALLOWED_MIMES, true );
		} ) );
		ORDELIST_Print_Stock_Store::set_attachments( $id, $ids );
		wp_send_json_success( array( 'html' => self::files_cell_html( $ids ) ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	private static function status_class( $row ) {
		$threshold = ORDELIST_Print_Stock::threshold_for( $row['type'] );
		if ( (int) $row['stock'] < 0 ) {
			return 'ole-ps-neg';
		}
		if ( (int) $row['stock'] <= $threshold ) {
			return 'ole-ps-low';
		}
		return '';
	}

	public static function render() {
		$rows     = ORDELIST_Print_Stock_Store::all_consumables();
		$stickers = array_filter( $rows, function ( $r ) { return 'sticker' === $r['type']; } );
		$sheets   = ORDELIST_Print_Stock_Store::sheets();
		?>
		<div class="wrap ole-ps-wrap">
			<h1><?php esc_html_e( 'Print consumables', 'ordelist' ); ?></h1>

			<h2><?php esc_html_e( 'Stickers', 'ordelist' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Sticker stock per product or variation - decreases by the ordered quantity. Add a product below: pick a variation for per-variation stock, or the product for one shared count.', 'ordelist' ); ?></p>
			<table class="widefat striped ole-ps-stickers">
				<thead><tr>
					<th><?php esc_html_e( 'Name', 'ordelist' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'Stock', 'ordelist' ); ?></th>
					<th style="width:22%"><?php esc_html_e( 'Files', 'ordelist' ); ?></th>
					<th style="width:300px"><?php esc_html_e( 'Actions', 'ordelist' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $stickers as $r ) : ?>
					<tr class="<?php echo esc_attr( self::status_class( $r ) ); ?>" data-id="<?php echo esc_attr( $r['id'] ); ?>">
						<td><?php echo esc_html( $r['name'] ); ?></td>
						<td><input type="number" step="1" class="ole-ps-stock" value="<?php echo esc_attr( (string) (int) $r['stock'] ); ?>" style="width:90px"/></td>
						<td class="ole-ps-files"><?php echo self::files_cell_html( ORDELIST_Print_Stock_Calc::decode_attachments( $r['attachments'] ?? null ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts ?></td>
						<td>
							<button type="button" class="button ole-ps-save"><?php esc_html_e( 'Set', 'ordelist' ); ?></button>
							<button type="button" class="button ole-ps-add"><?php esc_html_e( '+ printed', 'ordelist' ); ?></button>
							<button type="button" class="button ole-ps-sticker-delete" aria-label="<?php esc_attr_e( 'Delete', 'ordelist' ); ?>">&times;</button>
						</td>
					</tr>
				<?php endforeach; ?>
					<tr class="ole-ps-sticker-new">
						<td>
							<select class="wc-product-search ole-ps-sticker-product" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'ordelist' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%"></select>
						</td>
						<td><input type="number" step="1" class="ole-ps-sticker-stock" value="0" style="width:90px"/></td>
						<td class="ole-ps-files"></td>
						<td><button type="button" class="button button-primary ole-ps-sticker-add"><?php esc_html_e( 'Add', 'ordelist' ); ?></button></td>
					</tr>
				</tbody>
			</table>

			<h2 style="margin-top:2em"><?php esc_html_e( 'Instruction sheets', 'ordelist' ); ?></h2>
			<p class="description"><?php esc_html_e( 'One sheet can cover several products. Each order containing any of its products consumes one copy.', 'ordelist' ); ?></p>
			<table class="widefat ole-ps-sheets">
				<thead><tr>
					<th style="width:22%"><?php esc_html_e( 'Name', 'ordelist' ); ?></th>
					<th><?php esc_html_e( 'Products', 'ordelist' ); ?></th>
					<th style="width:100px"><?php esc_html_e( 'Stock', 'ordelist' ); ?></th>
					<th style="width:20%"><?php esc_html_e( 'Files', 'ordelist' ); ?></th>
					<th style="width:160px"><?php esc_html_e( 'Actions', 'ordelist' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $sheets as $s ) : ?>
					<tr class="ole-ps-sheet <?php echo esc_attr( self::status_class( $s ) ); ?>" data-id="<?php echo esc_attr( $s['id'] ); ?>">
						<td><input type="text" class="ole-ps-sheet-name regular-text" value="<?php echo esc_attr( $s['name'] ); ?>"/></td>
						<td>
							<select multiple class="wc-product-search ole-ps-sheet-products" data-placeholder="<?php esc_attr_e( 'Search for products…', 'ordelist' ); ?>" data-action="woocommerce_json_search_products" style="width:100%">
								<?php foreach ( $s['product_ids'] as $pid ) : ?>
									<?php $p = wc_get_product( $pid ); if ( ! $p ) { continue; } ?>
									<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="number" step="1" class="ole-ps-sheet-stock" value="<?php echo esc_attr( (string) (int) $s['stock'] ); ?>" style="width:90px"/></td>
						<td class="ole-ps-files"><?php echo self::files_cell_html( ORDELIST_Print_Stock_Calc::decode_attachments( $s['attachments'] ?? null ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts ?></td>
						<td>
							<button type="button" class="button ole-ps-sheet-save"><?php esc_html_e( 'Save', 'ordelist' ); ?></button>
							<button type="button" class="button ole-ps-sheet-delete">&times;</button>
						</td>
					</tr>
				<?php endforeach; ?>
					<tr class="ole-ps-sheet ole-ps-sheet-new" data-id="0">
						<td><input type="text" class="ole-ps-sheet-name regular-text" placeholder="<?php esc_attr_e( 'New sheet name', 'ordelist' ); ?>"/></td>
						<td>
							<select multiple class="wc-product-search ole-ps-sheet-products" data-placeholder="<?php esc_attr_e( 'Search for products…', 'ordelist' ); ?>" data-action="woocommerce_json_search_products" style="width:100%"></select>
						</td>
						<td><input type="number" step="1" class="ole-ps-sheet-stock" value="0" style="width:90px"/></td>
						<td class="ole-ps-files"></td>
						<td><button type="button" class="button button-primary ole-ps-sheet-save"><?php esc_html_e( 'Add', 'ordelist' ); ?></button></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
