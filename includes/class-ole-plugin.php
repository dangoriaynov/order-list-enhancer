<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Точка входу: реєстрація налаштувань + підвантаження ассетів на екрані замовлень.
 */
class OLE_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		new OLE_Settings_Page();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( 'OLE_Order_Total', 'render' ) );
	}

	/**
	 * Повертає контекст екрана: 'list' (список), 'edit' (редагування) або '' (інше).
	 * HPOS використовує один screen id для обох — розрізняємо за ?action=edit.
	 */
	private function screen_context() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return '';
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return '';
		}
		if ( 'woocommerce_page_wc-orders' === $screen->id ) {
			$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return ( 'edit' === $action || 'new' === $action ) ? 'edit' : 'list';
		}
		if ( 'edit-shop_order' === $screen->id ) {
			return 'list';
		}
		if ( 'shop_order' === $screen->id ) {
			return 'edit';
		}
		return '';
	}

	public function enqueue() {
		$context = $this->screen_context();
		if ( '' === $context || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$opts        = OLE_Settings::get();
		$dup_on      = OLE_Settings::is_yes( $opts, 'dup_enabled' );
		$ship_active = ( 'list' === $context )
			? OLE_Settings::is_yes( $opts, 'ship_enabled' )
			: OLE_Settings::is_yes( $opts, 'ship_color_edit' );

		// На редагуванні з JS працює лише кольорування адреси (дублі — тільки в списку).
		if ( 'edit' === $context && ! $ship_active ) {
			return;
		}
		if ( 'list' === $context && ! $dup_on && ! $ship_active ) {
			return;
		}

		$data = array(
			'context'  => $context,
			'flags'    => array(
				'duplicates' => ( $dup_on && 'list' === $context ),
				'shipping'   => $ship_active,
			),
			'map'      => new stdClass(),
			'groups'   => new stdClass(),
			'shipping' => array(
				'rules'   => array(),
				'default' => array(
					'color' => '',
					'label' => '',
				),
			),
			'palette'  => array( '#d63638', '#b26a00', '#7a4ce0', '#1a7a3c', '#0a6b9c', '#c2185b', '#00796b', '#5d4037' ),
			'i18n'     => array(
				'badge'      => __( 'customer #%1$s · %2$s orders', 'order-list-enhancer' ),
				'badgeTitle' => __( "Show this customer's orders. Matches: %s", 'order-list-enhancer' ),
				'modalTitle' => __( 'Customer #%1$s · %2$s orders%3$s', 'order-list-enhancer' ),
				'close'      => __( 'Close', 'order-list-enhancer' ),
				'noItems'    => __( '—', 'order-list-enhancer' ),
				'shipTitle'  => __( 'Delivery: %s', 'order-list-enhancer' ),
			),
		);

		if ( $dup_on && 'list' === $context ) {
			$built = OLE_Duplicates::build( $opts );
			if ( ! empty( $built['map'] ) ) {
				$data['map'] = $built['map'];
			}
			if ( ! empty( $built['groups'] ) ) {
				$data['groups'] = $built['groups'];
			}
		}
		if ( $ship_active ) {
			$data['shipping'] = OLE_Shipping::for_js( $opts );
		}

		wp_enqueue_style( 'ole-admin', OLE_URL . 'assets/css/ole-admin.css', array(), OLE_VERSION );
		wp_enqueue_script( 'ole-admin', OLE_URL . 'assets/js/ole-admin.js', array(), OLE_VERSION, true );
		wp_localize_script( 'ole-admin', 'OLE_DATA', $data );
	}
}
