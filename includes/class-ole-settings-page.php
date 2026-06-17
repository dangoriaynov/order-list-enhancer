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
						<th scope="row"><?php esc_html_e( 'Match by', 'order-list-enhancer' ); ?></th>
						<td>
							<label><input type="checkbox" name="match_phone" <?php echo $cb( 'match_phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'phone', 'order-list-enhancer' ); ?></label><br>
							<label><input type="checkbox" name="match_email" <?php echo $cb( 'match_email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'e-mail', 'order-list-enhancer' ); ?></label><br>
							<label><input type="checkbox" name="match_name" <?php echo $cb( 'match_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'name', 'order-list-enhancer' ); ?></label><br>
							<label><input type="checkbox" name="match_address" <?php echo $cb( 'match_address' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'shipping address', 'order-list-enhancer' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Scan limit', 'order-list-enhancer' ); ?></th>
						<td><input type="number" name="scan_limit" min="100" max="5000" step="100" value="<?php echo esc_attr( $o['scan_limit'] ); ?>"/>
						<p class="description"><?php esc_html_e( 'Maximum number of recent orders to scan across all statuses.', 'order-list-enhancer' ); ?></p></td>
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
			'match_phone'        => $bool( 'match_phone' ),
			'match_email'        => $bool( 'match_email' ),
			'match_name'         => $bool( 'match_name' ),
			'match_address'      => $bool( 'match_address' ),
			'scan_limit'         => isset( $in['scan_limit'] ) ? max( 100, min( 5000, (int) $in['scan_limit'] ) ) : 1500,
			'ship_enabled'       => $bool( 'ship_enabled' ),
			'ship_color_edit'    => $bool( 'ship_color_edit' ),
			'ship_rules'         => $rules,
			'ship_default_color' => isset( $in['ship_default_color'] ) ? (string) sanitize_hex_color( $in['ship_default_color'] ) : '',
			'ship_default_label' => isset( $in['ship_default_label'] ) ? sanitize_text_field( $in['ship_default_label'] ) : '',
			'total_on_edit'      => $bool( 'total_on_edit' ),
		);
		update_option( OLE_Settings::OPTION, $opts );
		wp_send_json_success( array( 'message' => 'saved' ) );
	}
}
