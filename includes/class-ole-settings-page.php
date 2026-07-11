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
		add_action( 'wp_ajax_ole_refresh_nonce', array( $this, 'ajax_refresh_nonce' ) );
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
		wp_enqueue_style( 'ole-settings', OLE_URL . 'assets/css/ole-settings.css', array(), OLE_VERSION );
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
					'saving'  => __( 'Saving…', 'order-list-enhancer' ),
					'saved'   => __( 'Saved.', 'order-list-enhancer' ),
					'error'   => __( 'Save failed.', 'order-list-enhancer' ),
					'expired' => __( 'Session expired — reload the page and try again.', 'order-list-enhancer' ),
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

	/** Ліва навігація вкладок. $tabs = [ ['id'=>'orders','label'=>'…'], … ]. */
	private static function tab_nav( array $tabs ) {
		echo '<ul class="ole-tabnav" role="tablist">';
		foreach ( $tabs as $t ) {
			printf(
				'<li role="presentation"><a role="tab" href="#%1$s" aria-controls="%1$s" aria-selected="false">%2$s</a></li>',
				esc_attr( $t['id'] ),
				esc_html( $t['label'] )
			);
		}
		echo '</ul>';
	}

	/** Перемикач on/off навколо справжнього чекбокса (той самий name). */
	private static function switch_html( $name, $checked, $label = '' ) {
		return sprintf(
			'<label class="ole-switch">%1$s<input type="checkbox" name="%2$s" %3$s/><span class="ole-slider"></span></label>',
			'' !== $label ? '<span class="screen-reader-text">' . esc_html( $label ) . '</span>' : '',
			esc_attr( $name ),
			$checked ? 'checked' : ''
		);
	}

	/** «?»-іконка з підказкою у title. */
	private static function help_html( $text ) {
		if ( '' === $text ) {
			return '';
		}
		return '<a href="#" class="ole-help" title="' . esc_attr( $text ) . '" aria-label="' . esc_attr( $text ) . '">?</a>';
	}

	/**
	 * Відкриває картку фічі.
	 * @param string     $title
	 * @param string     $help   довгий опис у tooltip ('' = без іконки)
	 * @param array|null $switch ['name'=>string,'checked'=>bool] для перемикача в шапці, або null
	 */
	private static function card_open( $title, $help = '', $switch = null ) {
		$attr = '';
		if ( is_array( $switch ) ) {
			$attr = ' data-switch="' . esc_attr( $switch['name'] ) . '"';
		}
		echo '<div class="ole-card"' . $attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="ole-card-head">';
		echo '<h2 class="ole-card-title">' . esc_html( $title ) . '</h2>';
		echo self::help_html( $help ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( is_array( $switch ) ) {
			echo self::switch_html( $switch['name'], (bool) $switch['checked'], $title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div><div class="ole-card-body">';
	}

	private static function card_close() {
		echo '</div></div>';
	}

	public function render() {
		$o = OLE_Settings::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Order List Enhancer', 'order-list-enhancer' ); ?></h1>
			<form id="ole-settings-form">
				<div class="ole-settings-shell">
					<?php
					self::tab_nav(
						array(
							array( 'id' => 'orders',    'label' => __( 'Orders', 'order-list-enhancer' ) ),
							array( 'id' => 'checkout',  'label' => __( 'Checkout', 'order-list-enhancer' ) ),
							array( 'id' => 'inventory', 'label' => __( 'Inventory', 'order-list-enhancer' ) ),
							array( 'id' => 'phone',     'label' => __( 'Phone', 'order-list-enhancer' ) ),
						)
					);
					?>
					<div class="ole-tabpanels">
						<div class="ole-tabpanel" id="orders" role="tabpanel"><?php $this->render_tab_orders( $o ); ?></div>
						<div class="ole-tabpanel" id="checkout" role="tabpanel"><?php $this->render_tab_checkout( $o ); ?></div>
						<div class="ole-tabpanel" id="inventory" role="tabpanel"><?php $this->render_tab_inventory( $o ); ?></div>
						<div class="ole-tabpanel" id="phone" role="tabpanel"><?php $this->render_tab_phone( $o ); ?></div>
					</div>
				</div>
				<div class="ole-savebar">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'order-list-enhancer' ); ?></button>
					<span class="ole-save-status" style="font-weight:600;"></span>
				</div>
			</form>
		</div>
		<?php
	}

	private function render_tab_orders( $o ) {
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

		self::card_open(
			__( 'Repeat customers', 'order-list-enhancer' ),
			__( 'Outline & badge orders from the same customer in the list, with a details modal. Choose how a match is decided and how far back to scan.', 'order-list-enhancer' ),
			array( 'name' => 'dup_enabled', 'checked' => OLE_Settings::is_yes( $o, 'dup_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Match mode', 'order-list-enhancer' ); ?></th>
				<td>
					<?php $mode = $o['match_mode']; ?>
					<select name="match_mode">
						<option value="phone" <?php selected( $mode, 'phone' ); ?>><?php esc_html_e( 'By phone', 'order-list-enhancer' ); ?></option>
						<option value="names" <?php selected( $mode, 'names' ); ?>><?php esc_html_e( 'By name', 'order-list-enhancer' ); ?></option>
						<option value="name_phone" <?php selected( $mode, 'name_phone' ); ?>><?php esc_html_e( 'By name + phone', 'order-list-enhancer' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Scan limit', 'order-list-enhancer' ); ?></th>
				<td><input type="number" name="scan_limit" min="100" max="5000" step="100" value="<?php echo esc_attr( $o['scan_limit'] ); ?>"/></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Duplicate window (days)', 'order-list-enhancer' ); ?></th>
				<td><input type="number" name="dup_window_days" min="1" max="60" step="1" value="<?php echo esc_attr( $o['dup_window_days'] ); ?>"/></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Shipping coloring', 'order-list-enhancer' ),
			__( 'Color the "Ship to" cell in the list and the address block on the order screen, by keyword rules.', 'order-list-enhancer' ),
			null
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Color in orders list', 'order-list-enhancer' ); ?></th>
				<td><?php echo self::switch_html( 'ship_enabled', OLE_Settings::is_yes( $o, 'ship_enabled' ), __( 'Color the “Ship to” cell in the orders list.', 'order-list-enhancer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Color the “Ship to” cell in the orders list.', 'order-list-enhancer' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Color on edit page', 'order-list-enhancer' ); ?></th>
				<td><?php echo self::switch_html( 'ship_color_edit', OLE_Settings::is_yes( $o, 'ship_color_edit' ), __( 'Color the address block on the single order edit screen.', 'order-list-enhancer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Color the address block on the single order edit screen.', 'order-list-enhancer' ); ?></td>
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
		<?php
		self::card_close();

		self::card_open(
			__( 'Order-total coloring', 'order-list-enhancer' ),
			__( 'Ring an order (row + address panel) when its total reaches a threshold; the highest matching threshold wins. Drawn on top of any shipping color — both stay visible.', 'order-list-enhancer' ),
			array( 'name' => 'total_color_enabled', 'checked' => OLE_Settings::is_yes( $o, 'total_color_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Threshold rules', 'order-list-enhancer' ); ?></th>
				<td>
					<?php
					$trules = $o['total_color_rules'];
					if ( empty( $trules ) ) {
						$trules = array(
							array(
								'threshold' => '',
								'color'     => '',
								'label'     => '',
							),
						);
					}
					?>
					<table class="widefat ole-rules" style="max-width:680px"><thead><tr>
						<th style="text-align:center"><?php esc_html_e( 'Order total ≥', 'order-list-enhancer' ); ?></th>
						<th style="text-align:center"><?php esc_html_e( 'Color', 'order-list-enhancer' ); ?></th>
						<th style="text-align:center"><?php esc_html_e( 'Label', 'order-list-enhancer' ); ?></th>
						<th></th>
					</tr></thead><tbody>
					<?php foreach ( $trules as $r ) : ?>
						<tr>
							<td><input type="number" step="0.01" min="0" name="total_threshold[]" value="<?php echo esc_attr( '' === $r['threshold'] ? '' : $r['threshold'] ); ?>" class="regular-text" placeholder="100"/></td>
							<td><input type="text" name="total_color[]" value="<?php echo esc_attr( $r['color'] ); ?>" class="ole-color" placeholder="#d63638"/></td>
							<td><input type="text" name="total_label[]" value="<?php echo esc_attr( $r['label'] ); ?>" class="regular-text"/></td>
							<td><button type="button" class="button ole-rule-remove">&times;</button></td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
					<p><button type="button" class="button ole-rule-add"><?php esc_html_e( 'Add rule', 'order-list-enhancer' ); ?></button></p>
				</td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Order total on the edit screen', 'order-list-enhancer' ),
			__( 'Show the total near the billing address on the order screen, with copy buttons.', 'order-list-enhancer' ),
			array( 'name' => 'total_on_edit', 'checked' => OLE_Settings::is_yes( $o, 'total_on_edit' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Copy button: name', 'order-list-enhancer' ); ?></th>
				<td><?php echo self::switch_html( 'copy_name', OLE_Settings::is_yes( $o, 'copy_name' ), __( 'Show a copy-to-clipboard button for the customer name on the order edit screen.', 'order-list-enhancer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Show a copy-to-clipboard button for the customer name on the order edit screen.', 'order-list-enhancer' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Copy button: phone', 'order-list-enhancer' ); ?></th>
				<td><?php echo self::switch_html( 'copy_phone', OLE_Settings::is_yes( $o, 'copy_phone' ), __( 'Show a copy-to-clipboard button for the phone number on the order edit screen.', 'order-list-enhancer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Show a copy-to-clipboard button for the phone number on the order edit screen.', 'order-list-enhancer' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Copy button: total', 'order-list-enhancer' ); ?></th>
				<td><?php echo self::switch_html( 'copy_total', OLE_Settings::is_yes( $o, 'copy_total' ), __( 'Show a copy-to-clipboard button for the order total on the order edit screen.', 'order-list-enhancer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Show a copy-to-clipboard button for the order total on the order edit screen.', 'order-list-enhancer' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Decimal separator', 'order-list-enhancer' ); ?></th>
				<td>
					<?php $dsep = $o['total_decimal_sep']; ?>
					<select name="total_decimal_sep">
						<option value="," <?php selected( $dsep, ',' ); ?>><?php esc_html_e( 'Comma (,)', 'order-list-enhancer' ); ?></option>
						<option value="." <?php selected( $dsep, '.' ); ?>><?php esc_html_e( 'Dot (.)', 'order-list-enhancer' ); ?></option>
					</select>
				</td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Default bulk action', 'order-list-enhancer' ),
			__( 'Pre-select an entry in the orders-list bulk-actions menu on page load.', 'order-list-enhancer' ),
			null
		);
		?>
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
					<p class="description"><?php esc_html_e( 'The list is filled from your Orders screen — open the Orders list once if it looks empty.', 'order-list-enhancer' ); ?></p>
				</td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Open selected one-by-one', 'order-list-enhancer' ),
			__( 'Add a button that opens each checkbox-selected order in its own tab, one at a time, waiting a configurable interval between tabs.', 'order-list-enhancer' ),
			array( 'name' => 'seq_open_enabled', 'checked' => OLE_Settings::is_yes( $o, 'seq_open_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default interval (seconds)', 'order-list-enhancer' ); ?></th>
				<td><input type="number" name="seq_open_interval" min="1" max="300" step="1" value="<?php echo esc_attr( $o['seq_open_interval'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Editable on the button too. Your browser must allow pop-ups for this site, or only the first tab opens.', 'order-list-enhancer' ); ?></p></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Order comment in the list', 'order-list-enhancer' ),
			__( 'Show the customer note left at checkout and the most recent internal admin note right under the order number in the orders list, so you never miss them. Click a note to expand it.', 'order-list-enhancer' ),
			array( 'name' => 'list_comments_enabled', 'checked' => OLE_Settings::is_yes( $o, 'list_comments_enabled' ) )
		);
		self::card_close();
	}

	private function render_tab_checkout( $o ) {
		self::card_open(
			__( 'Checkout phone validation', 'order-list-enhancer' ),
			__( 'Validate the billing phone (Bulgarian numbers) at checkout and flag invalid ones in admin. Country code comes from the Phone tab (default 359); orders are flagged either way.', 'order-list-enhancer' ),
			array( 'name' => 'phone_validate_enabled', 'checked' => OLE_Settings::is_yes( $o, 'phone_validate_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'When invalid', 'order-list-enhancer' ); ?></th>
				<td>
					<?php $pmode = $o['phone_validate_mode']; ?>
					<select name="phone_validate_mode">
						<option value="warn" <?php selected( $pmode, 'warn' ); ?>><?php esc_html_e( 'Warn only (allow the order, flag it)', 'order-list-enhancer' ); ?></option>
						<option value="block" <?php selected( $pmode, 'block' ); ?>><?php esc_html_e( 'Block the order until fixed', 'order-list-enhancer' ); ?></option>
					</select>
				</td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Duplicate-order guard', 'order-list-enhancer' ),
			__( 'Detect an identical recent order (same phone + cart) at checkout and confirm or block it; also disables the place-order button after the first tap.', 'order-list-enhancer' ),
			array( 'name' => 'dup_guard_enabled', 'checked' => OLE_Settings::is_yes( $o, 'dup_guard_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'When a duplicate is found', 'order-list-enhancer' ); ?></th>
				<td>
					<?php $dgmode = $o['dup_guard_mode']; ?>
					<select name="dup_guard_mode">
						<option value="confirm" <?php selected( $dgmode, 'confirm' ); ?>><?php esc_html_e( 'Ask the customer to confirm in a popup', 'order-list-enhancer' ); ?></option>
						<option value="block" <?php selected( $dgmode, 'block' ); ?>><?php esc_html_e( 'Block the duplicate order', 'order-list-enhancer' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Window (minutes)', 'order-list-enhancer' ); ?></th>
				<td>
					<input type="number" name="dup_guard_window_min" min="1" max="120" step="1" value="<?php echo esc_attr( (string) $o['dup_guard_window_min'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Same phone + same cart within this many minutes counts as a duplicate.', 'order-list-enhancer' ); ?></p>
				</td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Delivery-date notice', 'order-list-enhancer' ),
			__( 'Show a highlighted note above the delivery-date field at checkout; optional vacation banner. Requires the Order Delivery Date plugin field on checkout; does nothing if that field is absent.', 'order-list-enhancer' ),
			array( 'name' => 'delivery_notice_enabled', 'checked' => OLE_Settings::is_yes( $o, 'delivery_notice_enabled' ) )
		);
		?>
		<?php
		// Show the effective checkout texts instead of empty fields, so what the
		// customer sees is explicit; an emptied field still falls back to the default.
		$dn_def   = OLE_Delivery_Notice::defaults_copy();
		$dn_title = '' !== trim( (string) $o['delivery_notice_title'] ) ? $o['delivery_notice_title'] : $dn_def['title'];
		$dn_body  = '' !== trim( (string) $o['delivery_notice_body'] ) ? $o['delivery_notice_body'] : $dn_def['body'];
		$dn_vac   = '' !== trim( (string) $o['delivery_vacation_text'] ) ? $o['delivery_vacation_text'] : $dn_def['vacation'];
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Notice title', 'order-list-enhancer' ); ?></th>
				<td><input type="text" name="delivery_notice_title" value="<?php echo esc_attr( $dn_title ); ?>" class="regular-text" style="width:100%;max-width:680px"/>
				<p class="description"><?php esc_html_e( 'Bold first line. Leave empty for the default.', 'order-list-enhancer' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Notice text', 'order-list-enhancer' ); ?></th>
				<td><textarea name="delivery_notice_body" rows="2" class="large-text" style="max-width:680px"><?php echo esc_textarea( $dn_body ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Explanation under the title. Leave empty for the default.', 'order-list-enhancer' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Vacation banner', 'order-list-enhancer' ); ?></th>
				<td><?php echo self::switch_html( 'delivery_vacation_enabled', OLE_Settings::is_yes( $o, 'delivery_vacation_enabled' ), __( 'Also show a red "we are away" banner above the notice, until the date below.', 'order-list-enhancer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Also show a red "we are away" banner above the notice, until the date below.', 'order-list-enhancer' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Away until', 'order-list-enhancer' ); ?></th>
				<td><input type="date" name="delivery_vacation_until" value="<?php echo esc_attr( $o['delivery_vacation_until'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'The banner hides automatically after this date.', 'order-list-enhancer' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Vacation text', 'order-list-enhancer' ); ?></th>
				<td><textarea name="delivery_vacation_text" rows="2" class="large-text" style="max-width:680px"><?php echo esc_textarea( $dn_vac ); ?></textarea>
				<p class="description"><?php /* translators: %s is a literal token the admin types into their text; it is not substituted here. */ esc_html_e( 'Use %s where the date should appear. Leave empty for the default.', 'order-list-enhancer' ); ?></p></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Extras → products', 'order-list-enhancer' ),
			__( 'At order creation, turn each mapped add-on extra into a real product line at the price paid; order total stays unchanged.', 'order-list-enhancer' ),
			array( 'name' => 'extras_enabled', 'checked' => OLE_Settings::is_yes( $o, 'extras_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
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
					<p class="description"><?php esc_html_e( 'Match is the exact extra label as it appears on the order/checkout (Product Add-On label like "+ 500 г …", or the Checkout Add-On name).', 'order-list-enhancer' ); ?></p>
				</td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();
	}

	private function render_tab_inventory( $o ) {
		self::card_open(
			__( 'Print consumables', 'order-list-enhancer' ),
			__( 'Track sticker & instruction-sheet stock, auto-decrement at order placement, and warn when low.', 'order-list-enhancer' ),
			array( 'name' => 'print_stock_enabled', 'checked' => OLE_Settings::is_yes( $o, 'print_stock_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sticker low threshold', 'order-list-enhancer' ); ?></th>
				<td><input type="number" name="print_stock_threshold_sticker" min="0" max="100000" step="1" value="<?php echo esc_attr( (string) $o['print_stock_threshold_sticker'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Warn ("time to print") when a sticker stock drops to this or below.', 'order-list-enhancer' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Instruction low threshold', 'order-list-enhancer' ); ?></th>
				<td><input type="number" name="print_stock_threshold_instruction" min="0" max="100000" step="1" value="<?php echo esc_attr( (string) $o['print_stock_threshold_instruction'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Warn when an instruction sheet stock drops to this or below.', 'order-list-enhancer' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Stock page', 'order-list-enhancer' ); ?></th>
				<td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ole-print-stock' ) ); ?>"><?php esc_html_e( 'Open consumables stock', 'order-list-enhancer' ); ?></a></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();
	}

	private function render_tab_phone( $o ) {
		self::card_open(
			__( 'Phone numbers', 'order-list-enhancer' ),
			__( 'Tidy phone numbers for display (leading 00 → +, add country code when missing). Never changes the database.', 'order-list-enhancer' ),
			array( 'name' => 'normalize_phone', 'checked' => OLE_Settings::is_yes( $o, 'normalize_phone' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default country code', 'order-list-enhancer' ); ?></th>
				<td><input type="text" name="phone_cc" value="<?php echo esc_attr( $o['phone_cc'] ); ?>" placeholder="359" style="max-width:120px"/>
				<p class="description"><?php esc_html_e( 'Digits only, e.g. 359. Added to numbers that have no country code.', 'order-list-enhancer' ); ?></p></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();
	}

	/**
	 * Видає свіжий nonce, коли сторінка налаштувань провисіла відкритою довше
	 * за життя nonce (24 год) і збереження впало з 403. Права ті самі, що й у save.
	 */
	public function ajax_refresh_nonce() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'ole_save_settings' ) ) );
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

		$total_color_rules = array();
		if ( isset( $in['total_threshold'] ) && is_array( $in['total_threshold'] ) ) {
			$tc = isset( $in['total_color'] ) ? (array) $in['total_color'] : array();
			$tl = isset( $in['total_label'] ) ? (array) $in['total_label'] : array();
			foreach ( $in['total_threshold'] as $i => $th ) {
				$total_color_rules[] = array(
					'threshold' => $th,
					'color'     => isset( $tc[ $i ] ) ? $tc[ $i ] : '',
					'label'     => isset( $tl[ $i ] ) ? $tl[ $i ] : '',
				);
			}
		}
		$total_color_rules = OLE_Settings::clean_total_color_rules( $total_color_rules );

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
			'copy_name'             => $bool( 'copy_name' ),
			'copy_phone'            => $bool( 'copy_phone' ),
			'copy_total'            => $bool( 'copy_total' ),
			'normalize_phone'       => $bool( 'normalize_phone' ),
			'phone_cc'              => isset( $in['phone_cc'] ) ? preg_replace( '/\D+/', '', (string) $in['phone_cc'] ) : '',
			'phone_validate_enabled' => $bool( 'phone_validate_enabled' ),
			'phone_validate_mode'    => ( isset( $in['phone_validate_mode'] ) && 'block' === $in['phone_validate_mode'] ) ? 'block' : 'warn',
			'dup_guard_enabled'      => $bool( 'dup_guard_enabled' ),
			'dup_guard_mode'         => ( isset( $in['dup_guard_mode'] ) && 'block' === $in['dup_guard_mode'] ) ? 'block' : 'confirm',
			'dup_guard_window_min'   => isset( $in['dup_guard_window_min'] ) ? max( 1, min( 120, (int) $in['dup_guard_window_min'] ) ) : 5,
			'bulk_default_action'    => isset( $in['bulk_default_action'] ) ? sanitize_text_field( (string) $in['bulk_default_action'] ) : '',
			'total_color_enabled'    => $bool( 'total_color_enabled' ),
			'total_color_rules'      => $total_color_rules,
			'seq_open_enabled'       => $bool( 'seq_open_enabled' ),
			'seq_open_interval'      => isset( $in['seq_open_interval'] ) ? max( 1, min( 300, (int) $in['seq_open_interval'] ) ) : 7,
			'list_comments_enabled'  => $bool( 'list_comments_enabled' ),
			'delivery_notice_enabled'   => $bool( 'delivery_notice_enabled' ),
			'delivery_notice_title'     => isset( $in['delivery_notice_title'] ) ? sanitize_text_field( (string) $in['delivery_notice_title'] ) : '',
			'delivery_notice_body'      => isset( $in['delivery_notice_body'] ) ? sanitize_textarea_field( (string) $in['delivery_notice_body'] ) : '',
			'delivery_vacation_enabled' => $bool( 'delivery_vacation_enabled' ),
			'delivery_vacation_until'   => ( isset( $in['delivery_vacation_until'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['delivery_vacation_until'] ) ) ? (string) $in['delivery_vacation_until'] : '',
			'delivery_vacation_text'    => isset( $in['delivery_vacation_text'] ) ? sanitize_textarea_field( (string) $in['delivery_vacation_text'] ) : '',
			'print_stock_enabled'               => $bool( 'print_stock_enabled' ),
			'print_stock_threshold_sticker'     => isset( $in['print_stock_threshold_sticker'] ) ? max( 0, min( 100000, (int) $in['print_stock_threshold_sticker'] ) ) : 20,
			'print_stock_threshold_instruction' => isset( $in['print_stock_threshold_instruction'] ) ? max( 0, min( 100000, (int) $in['print_stock_threshold_instruction'] ) ) : 5,
		);
		update_option( OLE_Settings::OPTION, $opts );
		wp_send_json_success( array( 'message' => 'saved' ) );
	}
}
