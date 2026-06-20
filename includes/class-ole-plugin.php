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
		add_action( 'wp_ajax_ole_group_details', array( $this, 'ajax_group_details' ) );
		add_action( 'wp_ajax_ole_save_bulk_actions', array( $this, 'ajax_save_bulk_actions' ) );

		// Нормализация на телефон — само за показване (view context); БД не се пипа.
		$opts = OLE_Settings::get();
		if ( OLE_Settings::is_yes( $opts, 'normalize_phone' ) ) {
			$cc   = preg_replace( '/\D+/', '', (string) $opts['phone_cc'] );
			$norm = function ( $v ) use ( $cc ) {
				return OLE_Phone::normalize( $v, $cc );
			};
			add_filter( 'woocommerce_order_get_billing_phone', $norm, 20 );
			add_filter( 'woocommerce_order_get_shipping_phone', $norm, 20 );
		}
		if ( OLE_Settings::is_yes( $opts, 'extras_enabled' ) ) {
			OLE_Extras::init();
		}
	}

	/**
	 * AJAX: връща детайлите на поръчките от една група (по подадени ID-та).
	 */
	public function ajax_group_details() {
		check_ajax_referer( 'ole_group_details', 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$raw = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
		$ids = array_filter( array_map( 'intval', explode( ',', $raw ) ) );
		$ids = array_slice( $ids, 0, 200 );
		wp_send_json_success( OLE_Duplicates::details_for_ids( $ids ) );
	}

	/**
	 * AJAX: зберігає список групових дій (value=>label), зібраний JS-ом з екрана замовлень,
	 * щоб сторінка налаштувань могла показати їх у випадаючому списку.
	 */
	public function ajax_save_bulk_actions() {
		check_ajax_referer( 'ole_bulk_actions', 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$raw = isset( $_POST['actions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['actions'] ) ) : '';
		$map = json_decode( $raw, true );
		if ( ! is_array( $map ) ) {
			wp_send_json_error( array( 'message' => 'bad' ), 400 );
		}
		$clean = array();
		$i     = 0;
		foreach ( $map as $val => $label ) {
			if ( ++$i > 100 ) {
				break;
			}
			$val = sanitize_text_field( (string) $val );
			if ( '' === $val || '-1' === $val ) {
				continue;
			}
			$clean[ $val ] = sanitize_text_field( (string) $label );
		}
		update_option( OLE_Settings::BULK_ACTIONS, $clean, false );
		wp_send_json_success();
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
		$copy_on     = OLE_Settings::is_yes( $opts, 'copy_buttons' );
		$bulk_def    = ( 'list' === $context ) ? (string) $opts['bulk_default_action'] : '';

		// На екрана за редакция: групата на текущата поръчка (за да отворим същия модал).
		$edit_group = null;
		if ( 'edit' === $context && $dup_on ) {
			$oid = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $oid ) {
				$built = OLE_Duplicates::build( $opts );
				$key   = (string) $oid;
				if ( isset( $built['map'][ $key ] ) ) {
					$g = (int) $built['map'][ $key ]['g'];
					if ( isset( $built['groups'][ $g ] ) ) {
						$edit_group = $built['groups'][ $g ];
					}
				}
			}
		}

		// На редагуванні JS прави: оцветяване на адреса, копи-бутони и/или попъп.
		if ( 'edit' === $context && ! $ship_active && ! $copy_on && ! $edit_group ) {
			return;
		}
		if ( 'list' === $context && ! $dup_on && ! $ship_active && '' === $bulk_def ) {
			return;
		}

		$data = array(
			'context'    => $context,
			'decimalSep' => ( ',' === $opts['total_decimal_sep'] || '.' === $opts['total_decimal_sep'] ) ? $opts['total_decimal_sep'] : ',',
			'phone'      => array(
				'on' => OLE_Settings::is_yes( $opts, 'normalize_phone' ),
				'cc' => preg_replace( '/\D+/', '', (string) $opts['phone_cc'] ),
			),
			'flags'    => array(
				'duplicates' => ( $dup_on && 'list' === $context ),
				'shipping'   => $ship_active,
				'copy'       => ( $copy_on && 'edit' === $context ),
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
			'ajax'     => array(
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'ole_group_details' ),
			),
			'i18n'     => array(
				/* translators: %s: number of orders. */
				'badge'       => __( 'customer · %s orders', 'order-list-enhancer' ),
				/* translators: %s: matching criteria (phone, name, etc.). */
				'badgeTitle'  => __( "Show this customer's orders. Matches: %s", 'order-list-enhancer' ),
				/* translators: %s: number of orders. */
				'dupBadge'    => __( 'duplicate · %s orders', 'order-list-enhancer' ),
				/* translators: %s: number of orders. */
				'ordersCount' => __( '%s orders', 'order-list-enhancer' ),
				/* translators: %s: date of the first order. */
				'since'       => __( 'first on %s', 'order-list-enhancer' ),
				'close'       => __( 'Close', 'order-list-enhancer' ),
				'noItems'     => __( '—', 'order-list-enhancer' ),
				/* translators: %s: delivery method label. */
				'shipTitle'   => __( 'Delivery: %s', 'order-list-enhancer' ),
				'loading'     => __( 'Loading…', 'order-list-enhancer' ),
				'error'       => __( 'Failed to load.', 'order-list-enhancer' ),
				'copy'        => __( 'Copy', 'order-list-enhancer' ),
				'copied'      => __( 'Copied', 'order-list-enhancer' ),
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
		if ( $edit_group ) {
			$data['editGroup'] = $edit_group;
		}
		if ( 'list' === $context ) {
			$data['bulkDefault'] = $bulk_def;
			$data['bulkCache']   = (object) OLE_Settings::bulk_actions();
			$data['bulkNonce']   = wp_create_nonce( 'ole_bulk_actions' );
		}

		wp_enqueue_style( 'ole-admin', OLE_URL . 'assets/css/ole-admin.css', array(), OLE_VERSION );
		wp_enqueue_script( 'ole-admin', OLE_URL . 'assets/js/ole-admin.js', array(), OLE_VERSION, true );
		wp_localize_script( 'ole-admin', 'OLE_DATA', $data );
	}
}
