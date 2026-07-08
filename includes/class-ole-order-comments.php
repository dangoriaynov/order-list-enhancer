<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Показує коментар клієнта та останню людську адмін-нотатку прямо в колонці замовлення
 * у списку замовлень. Рендер іде в приховану колонку-носій (per-row хук дає $order),
 * а JS переносить блок у клітинку номера замовлення. Вмикається окремим тоглом.
 */
class OLE_Order_Comments {

	const COL = 'ole_comment';

	public static function init() {
		// HPOS (custom order tables) orders screen.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		// Legacy posts-based orders screen.
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_legacy' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/** Прихована колонка-носій одразу після номера замовлення (JS переносить її вміст у номер). */
	public static function add_column( $columns ) {
		if ( ! is_array( $columns ) || isset( $columns[ self::COL ] ) ) {
			return $columns;
		}
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_number' === $key ) {
				$out[ self::COL ] = __( 'Comment', 'order-list-enhancer' );
			}
		}
		if ( ! isset( $out[ self::COL ] ) ) {
			$out[ self::COL ] = __( 'Comment', 'order-list-enhancer' );
		}
		return $out;
	}

	public static function render_column( $column, $order ) {
		if ( self::COL === $column && $order instanceof WC_Order ) {
			echo self::note_block( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in note_line().
		}
	}

	public static function render_column_legacy( $column, $post_id ) {
		if ( self::COL !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order ) {
			echo self::note_block( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in note_line().
		}
	}

	/** HTML-блок нотаток; порожньо, якщо нема ні коментаря клієнта, ні адмін-нотатки. */
	private static function note_block( WC_Order $order ) {
		$cust  = trim( (string) $order->get_customer_note() );
		$admin = self::last_admin_note( $order );
		if ( '' === $cust && '' === $admin ) {
			return '';
		}
		$html = '<div class="ole-oc-wrap">';
		if ( '' !== $cust ) {
			$html .= self::note_line( 'cust', '💬', $cust );
		}
		if ( '' !== $admin ) {
			$html .= self::note_line( 'admin', '📝', $admin );
		}
		$html .= '</div>';
		return $html;
	}

	private static function note_line( $kind, $icon, $text ) {
		return sprintf(
			'<div class="ole-oc-note ole-oc-%1$s" title="%2$s"><span class="ole-oc-ic" aria-hidden="true">%3$s</span> <span class="ole-oc-txt">%4$s</span></div>',
			esc_attr( $kind ),
			esc_attr( $text ),
			$icon,
			esc_html( $text )
		);
	}

	/** Найновіша внутрішня нотатка, додана людиною (не системна, не клієнтська). */
	private static function last_admin_note( WC_Order $order ) {
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			return '';
		}
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'internal',
				'orderby'  => 'date_created',
				'order'    => 'DESC',
			)
		);
		foreach ( (array) $notes as $note ) {
			if ( 'system' !== $note->added_by ) {
				return trim( (string) $note->content );
			}
		}
		return '';
	}

	public static function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$id = (string) $screen->id;
		if ( ( 'woocommerce_page_wc-orders' !== $id && 'edit-shop_order' !== $id ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		wp_enqueue_style( 'ole-order-comments', OLE_URL . 'assets/css/ole-order-comments.css', array(), OLE_VERSION );
		wp_enqueue_script( 'ole-order-comments', OLE_URL . 'assets/js/ole-order-comments.js', array( 'jquery' ), OLE_VERSION, true );
	}
}
