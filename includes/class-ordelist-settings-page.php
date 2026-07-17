<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Власна сторінка налаштувань (WooCommerce → Order List Enhancer) з AJAX-збереженням.
 * Кожна фіча має свій перемикач; зберігається без перезавантаження сторінки.
 */
class ORDELIST_Settings_Page {

	const SLUG = 'ordelist-settings';

	/** Дозволені теги для wp_kses при echo готових контролів (escape late). */
	private const KSES_SWITCH = array(
		'label' => array( 'class' => true ),
		'span'  => array( 'class' => true ),
		'input' => array(
			'type'    => true,
			'name'    => true,
			'checked' => true,
		),
	);
	private const KSES_HELP = array(
		'a' => array(
			'href'       => true,
			'class'      => true,
			'title'      => true,
			'aria-label' => true,
		),
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_ordelist_save_settings', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_ordelist_refresh_nonce', array( $this, 'ajax_refresh_nonce' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ORDELIST_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Бърза връзка „Настройки" на екрана с плъгините.
	 */
	public function action_links( $links ) {
		$url      = admin_url( 'admin.php?page=' . self::SLUG );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'ordelist' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Order List Enhancer', 'ordelist' ),
			__( 'Order List Enhancer', 'ordelist' ),
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
		wp_enqueue_style( 'ordelist-settings', ORDELIST_URL . 'assets/css/ole-settings.css', array(), ORDELIST_VERSION );
		wp_enqueue_script( 'ordelist-datepicker', ORDELIST_URL . 'assets/js/ole-datepicker.js', array( 'jquery', 'jquery-ui-datepicker' ), ORDELIST_VERSION, true );
		wp_enqueue_script( 'ordelist-settings', ORDELIST_URL . 'assets/js/ole-settings.js', array( 'jquery', 'wp-color-picker', 'ordelist-datepicker' ), ORDELIST_VERSION, true );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_localize_script(
			'ordelist-settings',
			'ORDELIST_SETTINGS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ordelist_save_settings' ),
				'i18n'    => array(
					'saving'  => __( 'Saving…', 'ordelist' ),
					'saved'   => __( 'Saved.', 'ordelist' ),
					'error'   => __( 'Save failed.', 'ordelist' ),
					'expired' => __( 'Session expired - reload the page and try again.', 'ordelist' ),
				),
			)
		);
	}

	/**
	 * Короткий ярлик товару для мапінгу: для варіацій - спершу розмір (щоб було видно,
	 * який обрано, навіть коли довгу назву обрізає; напр. «500 г - Янтарна …»).
	 */
	private static function extra_product_label( $product ) {
		if ( $product->is_type( 'variation' ) ) {
			$size   = wc_get_formatted_variation( $product, true, false );
			$parent = wp_strip_all_tags( get_the_title( $product->get_parent_id() ) );
			return ( '' !== $size ? $size . ' - ' : '' ) . $parent;
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
		if ( is_array( $switch ) ) {
			printf( '<div class="ole-card" data-switch="%s">', esc_attr( $switch['name'] ) );
		} else {
			echo '<div class="ole-card">';
		}
		echo '<div class="ole-card-head">';
		echo '<h2 class="ole-card-title">' . esc_html( $title ) . '</h2>';
		echo wp_kses( self::help_html( $help ), self::KSES_HELP );
		if ( is_array( $switch ) ) {
			echo wp_kses( self::switch_html( $switch['name'], (bool) $switch['checked'], $title ), self::KSES_SWITCH );
		}
		echo '</div><div class="ole-card-body">';
	}

	private static function card_close() {
		echo '</div></div>';
	}

	public function render() {
		$o = ORDELIST_Settings::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Order List Enhancer', 'ordelist' ); ?></h1>
			<form id="ole-settings-form">
				<div class="ole-settings-shell">
					<?php
					self::tab_nav(
						array(
							array( 'id' => 'orders',    'label' => __( 'Orders', 'ordelist' ) ),
							array( 'id' => 'checkout',  'label' => __( 'Checkout', 'ordelist' ) ),
							array( 'id' => 'inventory', 'label' => __( 'Inventory', 'ordelist' ) ),
							array( 'id' => 'phone',     'label' => __( 'Phone', 'ordelist' ) ),
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
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'ordelist' ); ?></button>
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
			__( 'Repeat customers', 'ordelist' ),
			__( 'Outline & badge orders from the same customer in the list, with a details modal. Choose how a match is decided and how far back to scan.', 'ordelist' ),
			array( 'name' => 'dup_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'dup_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Match mode', 'ordelist' ); ?></th>
				<td>
					<?php $mode = $o['match_mode']; ?>
					<select name="match_mode">
						<option value="phone" <?php selected( $mode, 'phone' ); ?>><?php esc_html_e( 'By phone', 'ordelist' ); ?></option>
						<option value="names" <?php selected( $mode, 'names' ); ?>><?php esc_html_e( 'By name', 'ordelist' ); ?></option>
						<option value="name_phone" <?php selected( $mode, 'name_phone' ); ?>><?php esc_html_e( 'By name + phone', 'ordelist' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Scan limit', 'ordelist' ); ?></th>
				<td><input type="number" name="scan_limit" min="100" max="5000" step="100" value="<?php echo esc_attr( $o['scan_limit'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'How many of the newest orders (all statuses) are scanned on every list load. Allowed range 100-5000, default 1500. An empty or out-of-range value is clamped when saving - empty becomes 100, not unlimited.', 'ordelist' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Duplicate window (days)', 'ordelist' ); ?></th>
				<td><input type="number" name="dup_window_days" min="1" max="60" step="1" value="<?php echo esc_attr( $o['dup_window_days'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Two orders from the same customer within this many days (or 2+ still in processing) are flagged as likely duplicates. Allowed range 1-60, default 3; empty or out-of-range values are clamped when saving.', 'ordelist' ); ?></p></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Shipping coloring', 'ordelist' ),
			__( 'Color the "Ship to" cell in the list and the address block on the order screen, by keyword rules.', 'ordelist' ),
			null
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Color in orders list', 'ordelist' ); ?></th>
				<td><?php echo wp_kses( self::switch_html( 'ship_enabled', ORDELIST_Settings::is_yes( $o, 'ship_enabled' ), __( 'Color the “Ship to” cell in the orders list.', 'ordelist' ) ), self::KSES_SWITCH ); ?> <?php esc_html_e( 'Color the “Ship to” cell in the orders list.', 'ordelist' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Color on edit page', 'ordelist' ); ?></th>
				<td><?php echo wp_kses( self::switch_html( 'ship_color_edit', ORDELIST_Settings::is_yes( $o, 'ship_color_edit' ), __( 'Color the address block on the single order edit screen.', 'ordelist' ) ), self::KSES_SWITCH ); ?> <?php esc_html_e( 'Color the address block on the single order edit screen.', 'ordelist' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Coloring rules', 'ordelist' ); ?></th>
				<td>
					<table class="widefat ole-rules" style="max-width:680px"><thead><tr>
						<th style="text-align:center"><?php esc_html_e( 'Keyword (in shipping address)', 'ordelist' ); ?></th>
						<th style="text-align:center"><?php esc_html_e( 'Color', 'ordelist' ); ?></th>
						<th style="text-align:center"><?php esc_html_e( 'Label', 'ordelist' ); ?></th>
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
					<p><button type="button" class="button ole-rule-add"><?php esc_html_e( 'Add rule', 'ordelist' ); ?></button></p>
					<p class="description"><?php esc_html_e( 'Rows without a keyword are removed when saving; an invalid color value is cleared.', 'ordelist' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default color', 'ordelist' ); ?></th>
				<td><input type="text" name="ship_default_color" value="<?php echo esc_attr( $o['ship_default_color'] ); ?>" class="ole-color" placeholder="#f7eec6"/>
				<p class="description"><?php esc_html_e( 'Used when no rule matches. Leave empty to not color unmatched rows.', 'ordelist' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default label', 'ordelist' ); ?></th>
				<td><input type="text" name="ship_default_label" value="<?php echo esc_attr( $o['ship_default_label'] ); ?>" class="regular-text"/></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Order-total coloring', 'ordelist' ),
			__( 'Ring an order (row + address panel) when its total reaches a threshold; the highest matching threshold wins. Drawn on top of any shipping color - both stay visible.', 'ordelist' ),
			array( 'name' => 'total_color_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'total_color_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Threshold rules', 'ordelist' ); ?></th>
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
						<th style="text-align:center"><?php esc_html_e( 'Order total ≥', 'ordelist' ); ?></th>
						<th style="text-align:center"><?php esc_html_e( 'Color', 'ordelist' ); ?></th>
						<th style="text-align:center"><?php esc_html_e( 'Label', 'ordelist' ); ?></th>
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
					<p><button type="button" class="button ole-rule-add"><?php esc_html_e( 'Add rule', 'ordelist' ); ?></button></p>
					<p class="description"><?php esc_html_e( 'Only rows with a threshold above 0 and a valid color are kept when saving; the rest are removed.', 'ordelist' ); ?></p>
				</td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Order total on the edit screen', 'ordelist' ),
			__( 'Show the total near the billing address on the order screen, with copy buttons.', 'ordelist' ),
			array( 'name' => 'total_on_edit', 'checked' => ORDELIST_Settings::is_yes( $o, 'total_on_edit' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Copy button: name', 'ordelist' ); ?></th>
				<td><?php echo wp_kses( self::switch_html( 'copy_name', ORDELIST_Settings::is_yes( $o, 'copy_name' ), __( 'Show a copy-to-clipboard button for the customer name on the order edit screen.', 'ordelist' ) ), self::KSES_SWITCH ); ?> <?php esc_html_e( 'Show a copy-to-clipboard button for the customer name on the order edit screen.', 'ordelist' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Copy button: phone', 'ordelist' ); ?></th>
				<td><?php echo wp_kses( self::switch_html( 'copy_phone', ORDELIST_Settings::is_yes( $o, 'copy_phone' ), __( 'Show a copy-to-clipboard button for the phone number on the order edit screen.', 'ordelist' ) ), self::KSES_SWITCH ); ?> <?php esc_html_e( 'Show a copy-to-clipboard button for the phone number on the order edit screen.', 'ordelist' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Copy button: total', 'ordelist' ); ?></th>
				<td><?php echo wp_kses( self::switch_html( 'copy_total', ORDELIST_Settings::is_yes( $o, 'copy_total' ), __( 'Show a copy-to-clipboard button for the order total on the order edit screen.', 'ordelist' ) ), self::KSES_SWITCH ); ?> <?php esc_html_e( 'Show a copy-to-clipboard button for the order total on the order edit screen.', 'ordelist' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Decimal separator', 'ordelist' ); ?></th>
				<td>
					<?php $dsep = $o['total_decimal_sep']; ?>
					<select name="total_decimal_sep">
						<option value="," <?php selected( $dsep, ',' ); ?>><?php esc_html_e( 'Comma (,)', 'ordelist' ); ?></option>
						<option value="." <?php selected( $dsep, '.' ); ?>><?php esc_html_e( 'Dot (.)', 'ordelist' ); ?></option>
					</select>
				</td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Default bulk action', 'ordelist' ),
			__( 'Pre-select an entry in the orders-list bulk-actions menu on page load.', 'ordelist' ),
			null
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Pre-selected action', 'ordelist' ); ?></th>
				<td>
					<?php
					$bulk_actions = ORDELIST_Settings::bulk_actions();
					$bulk_cur     = $o['bulk_default_action'];
					// Keep the saved value selectable even before the menu has been captured.
					if ( '' !== $bulk_cur && ! isset( $bulk_actions[ $bulk_cur ] ) ) {
						$bulk_actions[ $bulk_cur ] = $bulk_cur;
					}
					?>
					<select name="bulk_default_action">
						<option value="" <?php selected( $bulk_cur, '' ); ?>><?php esc_html_e( '- (none)', 'ordelist' ); ?></option>
						<?php foreach ( $bulk_actions as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $bulk_cur, (string) $val ); ?>><?php echo esc_html( '' !== $label ? $label : $val ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The list is filled from your Orders screen - open the Orders list once if it looks empty.', 'ordelist' ); ?></p>
				</td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Open selected one-by-one', 'ordelist' ),
			__( 'Add a button that opens each checkbox-selected order in its own tab, one at a time, waiting a configurable interval between tabs.', 'ordelist' ),
			array( 'name' => 'seq_open_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'seq_open_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default interval (seconds)', 'ordelist' ); ?></th>
				<td><input type="number" name="seq_open_interval" min="1" max="300" step="1" value="<?php echo esc_attr( $o['seq_open_interval'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Editable on the button too. Allowed range 1-300 seconds, default 7; empty or out-of-range values are clamped when saving. Your browser must allow pop-ups for this site, or only the first tab opens.', 'ordelist' ); ?></p></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Order comment in the list', 'ordelist' ),
			__( 'Show the customer note left at checkout and the most recent internal admin note right under the order number in the orders list, so you never miss them. Click a note to expand it.', 'ordelist' ),
			array( 'name' => 'list_comments_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'list_comments_enabled' ) )
		);
		self::card_close();
	}

	private function render_tab_checkout( $o ) {
		self::card_open(
			__( 'Checkout phone validation', 'ordelist' ),
			__( 'Validate the billing phone (Bulgarian numbers) at checkout and flag invalid ones in admin. Country code comes from the Phone tab (default 359); orders are flagged either way.', 'ordelist' ),
			array( 'name' => 'phone_validate_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'phone_validate_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'When invalid', 'ordelist' ); ?></th>
				<td>
					<?php $pmode = $o['phone_validate_mode']; ?>
					<select name="phone_validate_mode">
						<option value="warn" <?php selected( $pmode, 'warn' ); ?>><?php esc_html_e( 'Warn only (allow the order, flag it)', 'ordelist' ); ?></option>
						<option value="block" <?php selected( $pmode, 'block' ); ?>><?php esc_html_e( 'Block the order until fixed', 'ordelist' ); ?></option>
					</select>
				</td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Duplicate-order guard', 'ordelist' ),
			__( 'Detect an identical recent order (same phone + cart) at checkout and confirm or block it; also disables the place-order button after the first tap.', 'ordelist' ),
			array( 'name' => 'dup_guard_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'dup_guard_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'When a duplicate is found', 'ordelist' ); ?></th>
				<td>
					<?php $dgmode = $o['dup_guard_mode']; ?>
					<select name="dup_guard_mode">
						<option value="confirm" <?php selected( $dgmode, 'confirm' ); ?>><?php esc_html_e( 'Ask the customer to confirm in a popup', 'ordelist' ); ?></option>
						<option value="block" <?php selected( $dgmode, 'block' ); ?>><?php esc_html_e( 'Block the duplicate order', 'ordelist' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Window (minutes)', 'ordelist' ); ?></th>
				<td>
					<input type="number" name="dup_guard_window_min" min="1" max="120" step="1" value="<?php echo esc_attr( (string) $o['dup_guard_window_min'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Same phone + same cart within this many minutes counts as a duplicate. Allowed range 1-120, default 5; empty or out-of-range values are clamped when saving.', 'ordelist' ); ?></p>
				</td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Delivery-date notice', 'ordelist' ),
			__( 'Show a highlighted note above the delivery-date field at checkout; optional vacation banner. Requires the Order Delivery Date plugin field on checkout; does nothing if that field is absent.', 'ordelist' ),
			array( 'name' => 'delivery_notice_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'delivery_notice_enabled' ) )
		);
		?>
		<?php
		// Show the effective checkout texts instead of empty fields, so what the
		// customer sees is explicit; an emptied field still falls back to the default.
		$dn_def   = ORDELIST_Delivery_Notice::defaults_copy();
		$dn_title = '' !== trim( (string) $o['delivery_notice_title'] ) ? $o['delivery_notice_title'] : $dn_def['title'];
		$dn_body  = '' !== trim( (string) $o['delivery_notice_body'] ) ? $o['delivery_notice_body'] : $dn_def['body'];
		$dn_vac   = '' !== trim( (string) $o['delivery_vacation_text'] ) ? $o['delivery_vacation_text'] : $dn_def['vacation'];
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Notice title', 'ordelist' ); ?></th>
				<td><input type="text" name="delivery_notice_title" value="<?php echo esc_attr( $dn_title ); ?>" class="regular-text" style="width:100%;max-width:680px"/>
				<p class="description"><?php esc_html_e( 'Bold first line. Leave empty for the default.', 'ordelist' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Notice text', 'ordelist' ); ?></th>
				<td><textarea name="delivery_notice_body" rows="2" class="large-text" style="max-width:680px"><?php echo esc_textarea( $dn_body ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Explanation under the title. Leave empty for the default.', 'ordelist' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Vacation banner', 'ordelist' ); ?></th>
				<td><?php echo wp_kses( self::switch_html( 'delivery_vacation_enabled', ORDELIST_Settings::is_yes( $o, 'delivery_vacation_enabled' ), __( 'Also show a red "we are away" banner above the notice, until the date below.', 'ordelist' ) ), self::KSES_SWITCH ); ?> <?php esc_html_e( 'Also show a red "we are away" banner above the notice, until the date below.', 'ordelist' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Away until', 'ordelist' ); ?></th>
				<td><input type="hidden" class="ole-date" name="delivery_vacation_until" value="<?php echo esc_attr( $o['delivery_vacation_until'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'The banner hides automatically after this date; with no date set it never shows.', 'ordelist' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Vacation text', 'ordelist' ); ?></th>
				<td><textarea name="delivery_vacation_text" rows="2" class="large-text" style="max-width:680px"><?php echo esc_textarea( $dn_vac ); ?></textarea>
				<p class="description"><?php /* translators: %s is a literal token the admin types into their text; it is not substituted here. */ esc_html_e( 'Use %s where the date should appear. Leave empty for the default.', 'ordelist' ); ?></p></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Extras → products', 'ordelist' ),
			__( 'At order creation, turn each mapped add-on extra into a real product line at the price paid; order total stays unchanged.', 'ordelist' ),
			array( 'name' => 'extras_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'extras_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Mapping (extra → product)', 'ordelist' ); ?></th>
				<td>
					<?php
					$emap = $o['extras_map'];
					if ( empty( $emap ) ) {
						$emap = array( array( 'match' => '', 'product' => 0 ) );
					}
					?>
					<table class="widefat ole-extras" style="width:100%;max-width:1000px"><thead><tr>
						<th style="text-align:center;width:38%"><?php esc_html_e( 'Extra text (as shown on the order)', 'ordelist' ); ?></th>
						<th style="text-align:center;width:54%"><?php esc_html_e( 'Product', 'ordelist' ); ?></th>
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
								<select class="wc-product-search ole-extra-product" name="extra_product[]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'ordelist' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%">
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
					<p><button type="button" class="button ole-extra-add"><?php esc_html_e( 'Add row', 'ordelist' ); ?></button></p>
					<p class="description"><?php esc_html_e( 'Rows without both an extra text and a product are removed when saving.', 'ordelist' ); ?></p>
					<p class="description"><?php esc_html_e( 'Match is the exact extra label as it appears on the order/checkout (Product Add-On label like "+ 500 г …", or the Checkout Add-On name).', 'ordelist' ); ?></p>
				</td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();
	}

	private function render_tab_inventory( $o ) {
		self::card_open(
			__( 'Print consumables', 'ordelist' ),
			__( 'Track sticker & instruction-sheet stock, auto-decrement at order placement, and warn when low.', 'ordelist' ),
			array( 'name' => 'print_stock_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'print_stock_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sticker low threshold', 'ordelist' ); ?></th>
				<td><input type="number" name="print_stock_threshold_sticker" min="0" max="100000" step="1" value="<?php echo esc_attr( (string) $o['print_stock_threshold_sticker'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Warn ("time to print") when a sticker stock drops to this or below. Allowed range 0-100000, default 20; empty or out-of-range values are clamped when saving.', 'ordelist' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Instruction low threshold', 'ordelist' ); ?></th>
				<td><input type="number" name="print_stock_threshold_instruction" min="0" max="100000" step="1" value="<?php echo esc_attr( (string) $o['print_stock_threshold_instruction'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Warn when an instruction sheet stock drops to this or below. Allowed range 0-100000, default 5; empty or out-of-range values are clamped when saving.', 'ordelist' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Stock page', 'ordelist' ); ?></th>
				<td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ordelist-print-stock' ) ); ?>"><?php esc_html_e( 'Open consumables stock', 'ordelist' ); ?></a></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Warranty dates', 'ordelist' ),
			__( 'Track stock batches with their "valid until" dates, auto-consume the oldest batch as orders come in, and get an email + admin banner before dates arrive.', 'ordelist' ),
			array( 'name' => 'warranty_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'warranty_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Warn ahead, days', 'ordelist' ); ?></th>
				<td><input type="number" name="warranty_days" min="1" max="365" step="1" value="<?php echo esc_attr( (string) $o['warranty_days'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Email + banner when a batch is within this many days of its date. Allowed range 1-365, default 30; empty or out-of-range values are clamped when saving.', 'ordelist' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Batches page', 'ordelist' ); ?></th>
				<td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ordelist-warranty' ) ); ?>"><?php esc_html_e( 'Open warranty dates', 'ordelist' ); ?></a></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();

		self::card_open(
			__( 'Purchase planning', 'ordelist' ),
			__( 'Multi-year sales chart with a purchase recommendation for a chosen period. Uses WooCommerce sales history; subtracts warranty-batch stock when batches are tracked.', 'ordelist' ),
			array( 'name' => 'forecast_enabled', 'checked' => ORDELIST_Settings::is_yes( $o, 'forecast_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Safety margin, %', 'ordelist' ); ?></th>
				<td><input type="number" name="forecast_margin" min="0" max="100" step="1" value="<?php echo esc_attr( (string) $o['forecast_margin'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Added on top of the forecast. Allowed range 0-100, default 20; empty or out-of-range values are clamped when saving. Adjustable on the page per calculation.', 'ordelist' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Planning page', 'ordelist' ); ?></th>
				<td><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ordelist-forecast' ) ); ?>"><?php esc_html_e( 'Open purchase planning', 'ordelist' ); ?></a></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();
	}

	private function render_tab_phone( $o ) {
		self::card_open(
			__( 'Phone numbers', 'ordelist' ),
			__( 'Tidy phone numbers for display (leading 00 → +, add country code when missing). Never changes the database.', 'ordelist' ),
			array( 'name' => 'normalize_phone', 'checked' => ORDELIST_Settings::is_yes( $o, 'normalize_phone' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default country code', 'ordelist' ); ?></th>
				<td><input type="text" name="phone_cc" value="<?php echo esc_attr( $o['phone_cc'] ); ?>" placeholder="359" style="max-width:120px"/>
				<p class="description"><?php esc_html_e( 'Digits only, e.g. 359 - anything else is stripped when saving. Added to numbers that have no country code.', 'ordelist' ); ?></p></td>
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
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'ordelist_save_settings' ) ) );
	}

	public function ajax_save() {
		check_ajax_referer( 'ordelist_save_settings', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		// Кожне поле unslash+sanitize у момент читання (sanitize early); типи й межі - тут же.
		$str = function ( $k ) {
			return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		};
		$ta = function ( $k ) {
			return isset( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : '';
		};
		$list = function ( $k ) {
			if ( ! isset( $_POST[ $k ] ) || ! is_array( $_POST[ $k ] ) ) {
				return array();
			}
			return array_map( 'sanitize_text_field', wp_unslash( $_POST[ $k ] ) );
		};
		$bool = function ( $k ) use ( $str ) {
			$v = $str( $k );
			return ( '' !== $v && '0' !== $v && 'false' !== $v ) ? 'yes' : 'no';
		};
		$int = function ( $k, $min, $max, $def ) use ( $str ) {
			$v = $str( $k );
			return ( '' === $v ) ? $def : max( $min, min( $max, (int) $v ) );
		};

		$rules    = array();
		$keywords = $list( 'rule_keyword' );
		if ( $keywords ) {
			$co = $list( 'rule_color' );
			$la = $list( 'rule_label' );
			foreach ( $keywords as $i => $k ) {
				if ( '' === $k ) {
					continue;
				}
				$rules[] = array(
					'keyword' => $k,
					'color'   => isset( $co[ $i ] ) ? (string) sanitize_hex_color( $co[ $i ] ) : '',
					'label'   => isset( $la[ $i ] ) ? $la[ $i ] : '',
				);
			}
		}

		$extras_map = array();
		$matches    = $list( 'extra_match' );
		if ( $matches ) {
			$eprod = $list( 'extra_product' );
			foreach ( $matches as $i => $mtext ) {
				$extras_map[] = array(
					'match'   => $mtext,
					'product' => isset( $eprod[ $i ] ) ? absint( $eprod[ $i ] ) : 0,
				);
			}
		}
		$extras_map = ORDELIST_Settings::clean_extras_map( $extras_map );

		$total_color_rules = array();
		$thresholds        = $list( 'total_threshold' );
		if ( $thresholds ) {
			$tc = $list( 'total_color' );
			$tl = $list( 'total_label' );
			foreach ( $thresholds as $i => $th ) {
				$total_color_rules[] = array(
					'threshold' => (float) $th,
					'color'     => isset( $tc[ $i ] ) ? $tc[ $i ] : '',
					'label'     => isset( $tl[ $i ] ) ? $tl[ $i ] : '',
				);
			}
		}
		$total_color_rules = ORDELIST_Settings::clean_total_color_rules( $total_color_rules );

		$match_mode = $str( 'match_mode' );
		$vac_until  = $str( 'delivery_vacation_until' );

		$opts = array(
			'extras_enabled'         => $bool( 'extras_enabled' ),
			'extras_map'             => $extras_map,
			'dup_enabled'           => $bool( 'dup_enabled' ),
			'match_mode'            => in_array( $match_mode, array( 'phone', 'names', 'name_phone' ), true ) ? $match_mode : 'phone',
			'scan_limit'            => $int( 'scan_limit', 100, 5000, 1500 ),
			'dup_window_days'       => $int( 'dup_window_days', 1, 60, 3 ),
			'ship_enabled'          => $bool( 'ship_enabled' ),
			'ship_color_edit'       => $bool( 'ship_color_edit' ),
			'ship_rules'            => $rules,
			'ship_default_color'    => (string) sanitize_hex_color( $str( 'ship_default_color' ) ),
			'ship_default_label'    => $str( 'ship_default_label' ),
			'total_on_edit'         => $bool( 'total_on_edit' ),
			'total_decimal_sep'     => ( '.' === $str( 'total_decimal_sep' ) ) ? '.' : ',',
			'copy_name'             => $bool( 'copy_name' ),
			'copy_phone'            => $bool( 'copy_phone' ),
			'copy_total'            => $bool( 'copy_total' ),
			'normalize_phone'       => $bool( 'normalize_phone' ),
			'phone_cc'              => preg_replace( '/\D+/', '', $str( 'phone_cc' ) ),
			'phone_validate_enabled' => $bool( 'phone_validate_enabled' ),
			'phone_validate_mode'    => ( 'block' === $str( 'phone_validate_mode' ) ) ? 'block' : 'warn',
			'dup_guard_enabled'      => $bool( 'dup_guard_enabled' ),
			'dup_guard_mode'         => ( 'block' === $str( 'dup_guard_mode' ) ) ? 'block' : 'confirm',
			'dup_guard_window_min'   => $int( 'dup_guard_window_min', 1, 120, 5 ),
			'bulk_default_action'    => $str( 'bulk_default_action' ),
			'total_color_enabled'    => $bool( 'total_color_enabled' ),
			'total_color_rules'      => $total_color_rules,
			'seq_open_enabled'       => $bool( 'seq_open_enabled' ),
			'seq_open_interval'      => $int( 'seq_open_interval', 1, 300, 7 ),
			'list_comments_enabled'  => $bool( 'list_comments_enabled' ),
			'delivery_notice_enabled'   => $bool( 'delivery_notice_enabled' ),
			'delivery_notice_title'     => $str( 'delivery_notice_title' ),
			'delivery_notice_body'      => $ta( 'delivery_notice_body' ),
			'delivery_vacation_enabled' => $bool( 'delivery_vacation_enabled' ),
			'delivery_vacation_until'   => ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $vac_until ) ) ? $vac_until : '',
			'delivery_vacation_text'    => $ta( 'delivery_vacation_text' ),
			'print_stock_enabled'               => $bool( 'print_stock_enabled' ),
			'print_stock_threshold_sticker'     => $int( 'print_stock_threshold_sticker', 0, 100000, 20 ),
			'print_stock_threshold_instruction' => $int( 'print_stock_threshold_instruction', 0, 100000, 5 ),
			'warranty_enabled'                  => $bool( 'warranty_enabled' ),
			'warranty_days'                     => $int( 'warranty_days', 1, 365, 30 ),
			'forecast_enabled'                  => $bool( 'forecast_enabled' ),
			'forecast_margin'                   => $int( 'forecast_margin', 0, 100, 20 ),
		);
		update_option( ORDELIST_Settings::OPTION, $opts );
		ORDELIST_Warranty::sync_schedule( $opts );
		wp_send_json_success( array( 'message' => 'saved' ) );
	}
}
