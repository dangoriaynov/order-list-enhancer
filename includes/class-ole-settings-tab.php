<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Вкладка налаштувань у WooCommerce → Settings.
 */
class OLE_Settings_Tab extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'ole';
		$this->label = __( 'Order List Enhancer', 'order-list-enhancer' );
		parent::__construct();
		add_action( 'woocommerce_admin_field_ole_rules', array( $this, 'render_rules_field' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save_rules' ) );
	}

	public function get_settings_for_default_section() {
		$o = OLE_Settings::OPTION;
		return array(
			array(
				'type' => 'title',
				'id'   => 'ole_dup_title',
				'name' => __( 'Repeat customer highlighting', 'order-list-enhancer' ),
				'desc' => __( 'Outline and badge orders that belong to the same customer in the orders list.', 'order-list-enhancer' ),
			),
			array(
				'type'    => 'checkbox',
				'id'      => $o . '[dup_enabled]',
				'name'    => __( 'Enable highlighting', 'order-list-enhancer' ),
				'default' => 'yes',
			),
			array(
				'type'    => 'checkbox',
				'id'      => $o . '[match_phone]',
				'name'    => __( 'Match by phone', 'order-list-enhancer' ),
				'default' => 'yes',
			),
			array(
				'type'    => 'checkbox',
				'id'      => $o . '[match_email]',
				'name'    => __( 'Match by e-mail', 'order-list-enhancer' ),
				'default' => 'yes',
			),
			array(
				'type'    => 'checkbox',
				'id'      => $o . '[match_name]',
				'name'    => __( 'Match by name', 'order-list-enhancer' ),
				'default' => 'yes',
			),
			array(
				'type'    => 'checkbox',
				'id'      => $o . '[match_address]',
				'name'    => __( 'Match by shipping address', 'order-list-enhancer' ),
				'default' => 'yes',
			),
			array(
				'type'              => 'number',
				'id'                => $o . '[scan_limit]',
				'name'              => __( 'Scan limit', 'order-list-enhancer' ),
				'desc'              => __( 'Maximum number of recent orders to scan across all statuses.', 'order-list-enhancer' ),
				'default'           => 1500,
				'custom_attributes' => array(
					'min'  => 100,
					'max'  => 5000,
					'step' => 100,
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ole_dup_end',
			),

			array(
				'type' => 'title',
				'id'   => 'ole_ship_title',
				'name' => __( 'Shipping column coloring', 'order-list-enhancer' ),
				'desc' => __( 'Color the shipping cell from the rules below. The first rule whose keyword is found in the shipping address wins.', 'order-list-enhancer' ),
			),
			array(
				'type'    => 'checkbox',
				'id'      => $o . '[ship_enabled]',
				'name'    => __( 'Enable shipping coloring', 'order-list-enhancer' ),
				'default' => 'yes',
			),
			array(
				'type' => 'ole_rules',
				'id'   => 'ole_rules_field',
			),
			array(
				'type'    => 'color',
				'id'      => $o . '[ship_default_color]',
				'name'    => __( 'Default color', 'order-list-enhancer' ),
				'desc'    => __( 'Used when no rule matches. Leave empty to not color unmatched rows.', 'order-list-enhancer' ),
				'default' => '',
			),
			array(
				'type'    => 'text',
				'id'      => $o . '[ship_default_label]',
				'name'    => __( 'Default label', 'order-list-enhancer' ),
				'default' => '',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ole_ship_end',
			),

			array(
				'type' => 'title',
				'id'   => 'ole_total_title',
				'name' => __( 'Order total on edit page', 'order-list-enhancer' ),
				'desc' => __( 'Show the order total near the billing address on the single order edit screen.', 'order-list-enhancer' ),
			),
			array(
				'type'    => 'checkbox',
				'id'      => $o . '[total_on_edit]',
				'name'    => __( 'Show order total on edit page', 'order-list-enhancer' ),
				'default' => 'yes',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ole_total_end',
			),
		);
	}

	public function render_rules_field() {
		$opts  = OLE_Settings::get();
		$rules = $opts['ship_rules'];
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
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Coloring rules', 'order-list-enhancer' ); ?></th>
			<td class="forminp">
				<table class="widefat ole-rules" style="max-width:680px">
					<thead><tr>
						<th><?php esc_html_e( 'Keyword (in shipping address)', 'order-list-enhancer' ); ?></th>
						<th><?php esc_html_e( 'Color (hex)', 'order-list-enhancer' ); ?></th>
						<th><?php esc_html_e( 'Label', 'order-list-enhancer' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rules as $r ) : ?>
						<tr>
							<td><input type="text" name="ole_rules[keyword][]" value="<?php echo esc_attr( $r['keyword'] ); ?>" class="regular-text" /></td>
							<td><input type="text" name="ole_rules[color][]" value="<?php echo esc_attr( $r['color'] ); ?>" placeholder="#dcefd2" /></td>
							<td><input type="text" name="ole_rules[label][]" value="<?php echo esc_attr( $r['label'] ); ?>" class="regular-text" /></td>
							<td><button type="button" class="button ole-rule-remove">&times;</button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button ole-rule-add"><?php esc_html_e( 'Add rule', 'order-list-enhancer' ); ?></button></p>
				<script>
				( function () {
					var td = document.currentScript.closest( 'td' );
					var tb = td.querySelector( 'tbody' );
					td.querySelector( '.ole-rule-add' ).addEventListener( 'click', function () {
						var tr = tb.rows[0].cloneNode( true );
						Array.prototype.forEach.call( tr.querySelectorAll( 'input' ), function ( i ) { i.value = ''; } );
						tb.appendChild( tr );
					} );
					td.addEventListener( 'click', function ( e ) {
						if ( e.target.classList.contains( 'ole-rule-remove' ) && tb.rows.length > 1 ) {
							e.target.closest( 'tr' ).remove();
						}
					} );
				} )();
				</script>
			</td>
		</tr>
		<?php
	}

	public function save_rules() {
		$opts = get_option( OLE_Settings::OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		$rules = array();
		if ( isset( $_POST['ole_rules']['keyword'] ) && is_array( $_POST['ole_rules']['keyword'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by WC settings save.
			$kw = wp_unslash( $_POST['ole_rules']['keyword'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$co = isset( $_POST['ole_rules']['color'] ) ? wp_unslash( $_POST['ole_rules']['color'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$la = isset( $_POST['ole_rules']['label'] ) ? wp_unslash( $_POST['ole_rules']['label'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( $kw as $i => $k ) {
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
		$opts['ship_rules'] = $rules;
		update_option( OLE_Settings::OPTION, $opts );
	}
}
