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
		wp_enqueue_script( 'ole-settings', OLE_URL . 'assets/js/ole-settings.js', array( 'jquery' ), OLE_VERSION, true );
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
								<th><?php esc_html_e( 'Keyword (in shipping address)', 'order-list-enhancer' ); ?></th>
								<th><?php esc_html_e( 'Color (hex)', 'order-list-enhancer' ); ?></th>
								<th><?php esc_html_e( 'Label', 'order-list-enhancer' ); ?></th>
								<th></th>
							</tr></thead><tbody>
							<?php foreach ( $rules as $r ) : ?>
								<tr>
									<td><input type="text" name="rule_keyword[]" value="<?php echo esc_attr( $r['keyword'] ); ?>" class="regular-text"/></td>
									<td><input type="text" name="rule_color[]" value="<?php echo esc_attr( $r['color'] ); ?>" placeholder="#dcefd2"/></td>
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
						<td><input type="text" name="ship_default_color" value="<?php echo esc_attr( $o['ship_default_color'] ); ?>" placeholder="#f7eec6"/>
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

		$opts = array(
			'dup_enabled'        => $bool( 'dup_enabled' ),
			'match_mode'         => ( isset( $in['match_mode'] ) && in_array( $in['match_mode'], array( 'phone', 'names', 'name_phone' ), true ) ) ? $in['match_mode'] : 'phone',
			'scan_limit'         => isset( $in['scan_limit'] ) ? max( 100, min( 5000, (int) $in['scan_limit'] ) ) : 1500,
			'dup_window_days'    => isset( $in['dup_window_days'] ) ? max( 1, min( 60, (int) $in['dup_window_days'] ) ) : 3,
			'ship_enabled'       => $bool( 'ship_enabled' ),
			'ship_color_edit'    => $bool( 'ship_color_edit' ),
			'ship_rules'         => $rules,
			'ship_default_color' => isset( $in['ship_default_color'] ) ? (string) sanitize_hex_color( $in['ship_default_color'] ) : '',
			'ship_default_label' => isset( $in['ship_default_label'] ) ? sanitize_text_field( $in['ship_default_label'] ) : '',
			'total_on_edit'      => $bool( 'total_on_edit' ),
			'total_decimal_sep'  => ( isset( $in['total_decimal_sep'] ) && '.' === $in['total_decimal_sep'] ) ? '.' : ',',
			'copy_buttons'       => $bool( 'copy_buttons' ),
			'normalize_phone'    => $bool( 'normalize_phone' ),
			'phone_cc'           => isset( $in['phone_cc'] ) ? preg_replace( '/\D+/', '', (string) $in['phone_cc'] ) : '',
		);
		update_option( OLE_Settings::OPTION, $opts );
		wp_send_json_success( array( 'message' => 'saved' ) );
	}
}
