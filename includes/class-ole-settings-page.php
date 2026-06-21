<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Власна сторінка налаштувань (WooCommerce → Order List Enhancer) з AJAX-збереженням.
 * Кожна фіча має свій перемикач; зберігається без перезавантаження сторінки.
 */
class OLE_Settings_Page {

	const SLUG = 'ole-settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_ole_save_settings', array( $this, 'ajax_save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OLE_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Бърза връзка „Настройки" на екрана с плъгините.
	 */
	public function action_links( $links ) {
		$url      = admin_url( 'admin.php?page=' . self::SLUG );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'order-list-enhancer' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Order List Enhancer', 'order-list-enhancer' ),
			__( 'Order List Enhancer', 'order-list-enhancer' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	private function is_settings_screen() {
		$s = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $s && false !== strpos( (string) $s->id, self::SLUG );
	}

	public function assets() {
		if ( ! $this->is_settings_screen() ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'ole-settings', OLE_URL . 'assets/js/ole-settings.js', array( 'jquery', 'wp-color-picker' ), OLE_VERSION, true );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_localize_script(
			'ole-settings',
			'OLE_SETTINGS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ole_save_settings' ),
				'i18n'    => array(
					'saving' => __( 'Saving…', 'order-list-enhancer' ),
					'saved'  => __( 'Saved.', 'order-list-enhancer' ),
					'error'  => __( 'Save failed.', 'order-list-enhancer' ),
				),
			)
		);
	}

	/**
	 * Короткий ярлик товару для мапінгу: для варіацій — спершу розмір (щоб було видно,
	 * який обрано, навіть коли довгу назву обрізає; напр. «500 г — Янтарна …»).
	 */
	private static function extra_product_label( $product ) {
		if ( $product->is_type( 'variation' ) ) {
			$size   = wc_get_formatted_variation( $product, true, false );
			$parent = wp_strip_all_tags( get_the_title( $product->get_parent_id() ) );
			return ( '' !== $size ? $size . ' — ' : '' ) . $parent;
		}
		return wp_strip_all_tags( $product->get_name() );
	}

	public function render() {
		$o  = OLE_Settings::get();
		$cb = function ( $key ) use ( $o ) {
			return checked( OLE_Settings::is_yes( $o, $key ), true, false );
		};
		$rules = $o['ship_rules'];
		if ( empty( $rules ) ) {
			$rules = array(
				array(
					'keyword' => '',
					'color'   => '',
					'label'   => '',
				),
			);
		}
		?>
		<div class="wrap">
			<style>
				/* Make every settings table fill the same (full) content width, and the
				   mapping fields fill their cells so long product names are visible. */
				#ole-settings-form .form-table { width: 100%; }
				#ole-settings-form .ole-rules,
				#ole-settings-form .ole-extras { width: 100%; max-width: 100% !important; }
				#ole-settings-form .ole-extras td input[type=text],
				#ole-settings-form .ole-extras td .wc-product-search,
				#ole-settings-form .ole-extras td .select2-container { width: 100% !important; }
			</style>
			<h1><?php esc_html_e( 'Order List Enhancer', 'order-list-enhancer' ); ?></h1>
			<form id="ole-settings-form">

				<h2><?php esc_html_e( 'Repeat customer highlighting', 'order-list-enhancer' ); ?></h2>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable highlighting', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="dup_enabled" <?php echo $cb( 'dup_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'Outline & badge same-customer orders in the list (with details modal).', 'order-list-enhancer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Match mode', 'order-list-enhancer' ); ?></th>
						<td>
							<?php $mode = $o['match_mode']; ?>
							<select name="match_mode">
								<option value="phone" <?php selected( $mode, 'phone' ); ?>><?php esc_html_e( 'By phone', 'order-list-enhancer' ); ?></option>
								<option value="names" <?php selected( $mode, 'names' ); ?>><?php esc_html_e( 'By name', 'order-list-enhancer' ); ?></option>
								<option value="name_phone" <?php selected( $mode, 'name_phone' ); ?>><?php esc_html_e( 'By name + phone', 'order-list-enhancer' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'How to decide two orders are from the same customer. Name + phone matches only when both the phone and the name match.', 'order-list-enhancer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Scan limit', 'order-list-enhancer' ); ?></th>
						<td><input type="number" name="scan_limit" min="100" max="5000" step="100" value="<?php echo esc_attr( $o['scan_limit'] ); ?>"/>
						<p class="description"><?php esc_html_e( 'Maximum number of recent orders to scan across all statuses.', 'order-list-enhancer' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Duplicate window (days)', 'order-list-enhancer' ); ?></th>
						<td><input type="number" name="dup_window_days" min="1" max="60" step="1" value="<?php echo esc_attr( $o['dup_window_days'] ); ?>"/>
						<p class="description"><?php esc_html_e( 'Two orders from the same customer within this many days (or 2+ in processing) are flagged as a likely duplicate.', 'order-list-enhancer' ); ?></p></td>
					</tr>
				</tbody></table>

				<h2><?php esc_html_e( 'Shipping coloring', 'order-list-enhancer' ); ?></h2>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Color in orders list', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="ship_enabled" <?php echo $cb( 'ship_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'Color the “Ship to” cell in the orders list.', 'order-list-enhancer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Color on edit page', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="ship_color_edit" <?php echo $cb( 'ship_color_edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'Color the address block on the single order edit screen.', 'order-list-enhancer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Coloring rules', 'order-list-enhancer' ); ?></th>
						<td>
							<table class="widefat ole-rules" style="max-width:680px"><thead><tr>
								<th style="text-align:center"><?php esc_html_e( 'Keyword (in shipping address)', 'order-list-enhancer' ); ?></th>
								<th style="text-align:center"><?php esc_html_e( 'Color', 'order-list-enhancer' ); ?></th>
								<th style="text-align:center"><?php esc_html_e( 'Label', 'order-list-enhancer' ); ?></th>
								<th></th>
							</tr></thead><tbody>
							<?php foreach ( $rules as $r ) : ?>
								<tr>
									<td><input type="text" name="rule_keyword[]" value="<?php echo esc_attr( $r['keyword'] ); ?>" class="regular-text"/></td>
									<td><input type="text" name="rule_color[]" value="<?php echo esc_attr( $r['color'] ); ?>" class="ole-color" placeholder="#dcefd2"/></td>
									<td><input type="text" name="rule_label[]" value="<?php echo esc_attr( $r['label'] ); ?>" class="regular-text"/></td>
									<td><button type="button" class="button ole-rule-remove">&times;</button></td>
								</tr>
							<?php endforeach; ?>
							</tbody></table>
							<p><button type="button" class="button ole-rule-add"><?php esc_html_e( 'Add rule', 'order-list-enhancer' ); ?></button></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default color', 'order-list-enhancer' ); ?></th>
						<td><input type="text" name="ship_default_color" value="<?php echo esc_attr( $o['ship_default_color'] ); ?>" class="ole-color" placeholder="#f7eec6"/>
						<p class="description"><?php esc_html_e( 'Used when no rule matches. Leave empty to not color unmatched rows.', 'order-list-enhancer' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default label', 'order-list-enhancer' ); ?></th>
						<td><input type="text" name="ship_default_label" value="<?php echo esc_attr( $o['ship_default_label'] ); ?>" class="regular-text"/></td>
					</tr>
				</tbody></table>

				<h2><?php esc_html_e( 'Order total on edit page', 'order-list-enhancer' ); ?></h2>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="total_on_edit" <?php echo $cb( 'total_on_edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'Show the order total near the billing address on the order edit screen.', 'order-list-enhancer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Decimal separator', 'order-list-enhancer' ); ?></th>
						<td>
							<?php $dsep = $o['total_decimal_sep']; ?>
							<select name="total_decimal_sep">
								<option value="," <?php selected( $dsep, ',' ); ?>><?php esc_html_e( 'Comma (,)', 'order-list-enhancer' ); ?></option>
								<option value="." <?php selected( $dsep, '.' ); ?>><?php esc_html_e( 'Dot (.)', 'order-list-enhancer' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Decimal separator for the order total shown under the address (and its copy button).', 'order-list-enhancer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Copy buttons', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="copy_buttons" <?php echo $cb( 'copy_buttons' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'Show copy-to-clipboard buttons for name, phone and total on the order edit screen.', 'order-list-enhancer' ); ?></label></td>
					</tr>
				</tbody></table>

				<h2><?php esc_html_e( 'Orders list — default bulk action', 'order-list-enhancer' ); ?></h2>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pre-selected action', 'order-list-enhancer' ); ?></th>
						<td>
							<?php
							$bulk_actions = OLE_Settings::bulk_actions();
							$bulk_cur     = $o['bulk_default_action'];
							// Keep the saved value selectable even before the menu has been captured.
							if ( '' !== $bulk_cur && ! isset( $bulk_actions[ $bulk_cur ] ) ) {
								$bulk_actions[ $bulk_cur ] = $bulk_cur;
							}
							?>
							<select name="bulk_default_action">
								<option value="" <?php selected( $bulk_cur, '' ); ?>><?php esc_html_e( '— (none)', 'order-list-enhancer' ); ?></option>
								<?php foreach ( $bulk_actions as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $bulk_cur, (string) $val ); ?>><?php echo esc_html( '' !== $label ? $label : $val ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Pre-selects this entry in the orders-list bulk-actions menu on page load. The list is filled from your Orders screen — open the Orders list once if it looks empty.', 'order-list-enhancer' ); ?></p>
						</td>
					</tr>
				</tbody></table>

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
							<table class="widefat ole-extras" style="width:100%;max-width:1000px"><thead><tr>
								<th style="text-align:center;width:38%"><?php esc_html_e( 'Extra text (as shown on the order)', 'order-list-enhancer' ); ?></th>
								<th style="text-align:center;width:54%"><?php esc_html_e( 'Product', 'order-list-enhancer' ); ?></th>
								<th style="width:1%"></th>
							</tr></thead><tbody>
							<?php
							foreach ( $emap as $row ) :
								$pid     = isset( $row['product'] ) ? (int) $row['product'] : 0;
								$product = $pid ? wc_get_product( $pid ) : null;
								?>
								<tr>
									<td><input type="text" name="extra_match[]" value="<?php echo esc_attr( $row['match'] ); ?>" class="regular-text" placeholder="+ 500 г янтарна киселина" style="width:100%"/></td>
									<td>
										<select class="wc-product-search ole-extra-product" name="extra_product[]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'order-list-enhancer' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%">
											<?php if ( $product ) : ?>
												<?php $plabel = self::extra_product_label( $product ); ?>
												<option value="<?php echo esc_attr( $pid ); ?>" selected title="<?php echo esc_attr( $plabel ); ?>"><?php echo esc_html( $plabel ); ?></option>
											<?php else : ?>
												<option value="" selected></option>
											<?php endif; ?>
										</select>
									</td>
									<td><button type="button" class="button ole-extra-remove">&times;</button></td>
								</tr>
							<?php endforeach; ?>
							</tbody></table>
							<p><button type="button" class="button ole-extra-add"><?php esc_html_e( 'Add row', 'order-list-enhancer' ); ?></button></p>
							<p class="description"><?php esc_html_e( 'Match is the exact extra label as it appears on the order/checkout (Product Add-On label like "+ 500 г …", or the Checkout Add-On name). The product line will be priced at what the customer paid for that extra.', 'order-list-enhancer' ); ?></p>
						</td>
					</tr>
				</tbody></table>

				<h2><?php esc_html_e( 'Checkout phone validation', 'order-list-enhancer' ); ?></h2>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Validate at checkout', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="phone_validate_enabled" <?php echo $cb( 'phone_validate_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'Validate the billing phone number on the checkout (Bulgarian numbers) and flag invalid numbers in the admin.', 'order-list-enhancer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'When invalid', 'order-list-enhancer' ); ?></th>
						<td>
							<?php $pmode = $o['phone_validate_mode']; ?>
							<select name="phone_validate_mode">
								<option value="warn" <?php selected( $pmode, 'warn' ); ?>><?php esc_html_e( 'Warn only (allow the order, flag it)', 'order-list-enhancer' ); ?></option>
								<option value="block" <?php selected( $pmode, 'block' ); ?>><?php esc_html_e( 'Block the order until fixed', 'order-list-enhancer' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Country code comes from "Default country code" below (default 359). Invalid orders are flagged on the order page and in the orders list regardless of mode.', 'order-list-enhancer' ); ?></p>
						</td>
					</tr>
				</tbody></table>

				<h2><?php esc_html_e( 'Phone numbers', 'order-list-enhancer' ); ?></h2>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Normalize phone (display only)', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="normalize_phone" <?php echo $cb( 'normalize_phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'Tidy phone numbers for display: leading 00 → +, add the country code when missing. Never changes the database.', 'order-list-enhancer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default country code', 'order-list-enhancer' ); ?></th>
						<td><input type="text" name="phone_cc" value="<?php echo esc_attr( $o['phone_cc'] ); ?>" placeholder="359" style="max-width:120px"/>
						<p class="description"><?php esc_html_e( 'Digits only, e.g. 359. Added to numbers that have no country code.', 'order-list-enhancer' ); ?></p></td>
					</tr>
				</tbody></table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'order-list-enhancer' ); ?></button>
					<span class="ole-save-status" style="margin-left:10px;font-weight:600;"></span>
				</p>
			</form>
		</div>
		<?php
	}

	public function ajax_save() {
		check_ajax_referer( 'ole_save_settings', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$in   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$bool = function ( $k ) use ( $in ) {
			return ( ! empty( $in[ $k ] ) && 'false' !== $in[ $k ] ) ? 'yes' : 'no';
		};

		$rules = array();
		if ( isset( $in['rule_keyword'] ) && is_array( $in['rule_keyword'] ) ) {
			$co = isset( $in['rule_color'] ) ? (array) $in['rule_color'] : array();
			$la = isset( $in['rule_label'] ) ? (array) $in['rule_label'] : array();
			foreach ( $in['rule_keyword'] as $i => $k ) {
				$k = sanitize_text_field( $k );
				if ( '' === $k ) {
					continue;
				}
				$rules[] = array(
					'keyword' => $k,
					'color'   => isset( $co[ $i ] ) ? (string) sanitize_hex_color( $co[ $i ] ) : '',
					'label'   => isset( $la[ $i ] ) ? sanitize_text_field( $la[ $i ] ) : '',
				);
			}
		}

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

		$opts = array(
			'extras_enabled'         => $bool( 'extras_enabled' ),
			'extras_map'             => $extras_map,
			'dup_enabled'           => $bool( 'dup_enabled' ),
			'match_mode'            => ( isset( $in['match_mode'] ) && in_array( $in['match_mode'], array( 'phone', 'names', 'name_phone' ), true ) ) ? $in['match_mode'] : 'phone',
			'scan_limit'            => isset( $in['scan_limit'] ) ? max( 100, min( 5000, (int) $in['scan_limit'] ) ) : 1500,
			'dup_window_days'       => isset( $in['dup_window_days'] ) ? max( 1, min( 60, (int) $in['dup_window_days'] ) ) : 3,
			'ship_enabled'          => $bool( 'ship_enabled' ),
			'ship_color_edit'       => $bool( 'ship_color_edit' ),
			'ship_rules'            => $rules,
			'ship_default_color'    => isset( $in['ship_default_color'] ) ? (string) sanitize_hex_color( $in['ship_default_color'] ) : '',
			'ship_default_label'    => isset( $in['ship_default_label'] ) ? sanitize_text_field( $in['ship_default_label'] ) : '',
			'total_on_edit'         => $bool( 'total_on_edit' ),
			'total_decimal_sep'     => ( isset( $in['total_decimal_sep'] ) && '.' === $in['total_decimal_sep'] ) ? '.' : ',',
			'copy_buttons'          => $bool( 'copy_buttons' ),
			'normalize_phone'       => $bool( 'normalize_phone' ),
			'phone_cc'              => isset( $in['phone_cc'] ) ? preg_replace( '/\D+/', '', (string) $in['phone_cc'] ) : '',
			'phone_validate_enabled' => $bool( 'phone_validate_enabled' ),
			'phone_validate_mode'    => ( isset( $in['phone_validate_mode'] ) && 'block' === $in['phone_validate_mode'] ) ? 'block' : 'warn',
			'bulk_default_action'    => isset( $in['bulk_default_action'] ) ? sanitize_text_field( (string) $in['bulk_default_action'] ) : '',
		);
		update_option( OLE_Settings::OPTION, $opts );
		wp_send_json_success( array( 'message' => 'saved' ) );
	}
}
