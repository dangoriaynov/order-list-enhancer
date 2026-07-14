<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-glue термінів придатності: FIFO-списання партій за станом замовлення,
 * щоденна перевірка (WP-Cron) з email + банером, синхронізація розкладу.
 */
class ORDELIST_Warranty {

	const STATE_META    = '_ordelist_wr_state';       // '' | 'consumed' | 'restored'
	const CONSUMED_META = '_ordelist_batch_consumed'; // [ batch_id => qty ]
	const CRON_HOOK     = 'ordelist_warranty_daily';

	public static function init() {
		ORDELIST_Warranty_Store::maybe_upgrade();

		// Створення замовлення + переходи статусів — той самий набір хуків, що й у витратних.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order' ), 40, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order' ), 40, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 40, 4 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'on_order' ), 40, 1 );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'on_order' ), 40, 1 );

		add_action( self::CRON_HOOK, array( __CLASS__, 'run_check' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_scheduled' ) ); // самолікування пропущеного розкладу
		add_action( 'admin_notices', array( __CLASS__, 'banner' ) );

		ORDELIST_Warranty_Admin::init();
	}

	/* ---------- розклад ---------- */

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Після збереження налаштувань (спрацьовує і коли фічу щойно вимкнули — клас завантажений завжди). */
	public static function sync_schedule( array $opts ) {
		if ( ORDELIST_Settings::is_yes( $opts, 'warranty_enabled' ) ) {
			self::ensure_scheduled();
		} else {
			self::unschedule();
		}
	}

	/* ---------- списання / повернення ---------- */

	public static function on_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			self::reconcile( $order );
		}
	}

	public static function on_status_changed( $order_id, $from, $to, $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof WC_Order ) {
			self::reconcile( $order );
		}
	}

	/** Єдина точка: приводить партії у відповідність до стану замовлення (ідемпотентно). */
	public static function reconcile( WC_Order $order ) {
		$live  = ORDELIST_Print_Stock_Calc::is_live( $order->get_status() ); // той самий словник «живих» статусів
		$state = (string) $order->get_meta( self::STATE_META );

		if ( $live && 'consumed' !== $state ) {
			self::consume( $order );
		} elseif ( ! $live && 'consumed' === $state ) {
			self::restore( $order );
		}
	}

	private static function consume( WC_Order $order ) {
		// Бекстоп ідемпотентності: мапа вже записана (прапорець стану загубився через збій) — не списувати вдруге.
		$existing = $order->get_meta( self::CONSUMED_META );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			$order->update_meta_data( self::STATE_META, 'consumed' );
			$order->save();
			return;
		}

		// Спочатку рахуємо повну мапу списання в пам'яті — жодного take_qty() тут ще нема.
		$map = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$qty = (int) $item->get_quantity();
			if ( $qty <= 0 ) {
				continue;
			}
			$vid     = (int) $item->get_variation_id();
			$target  = $vid > 0 ? $vid : (int) $item->get_product_id();
			$batches = ORDELIST_Warranty_Store::batches_for_target( $target, $vid > 0 );
			$takes   = ORDELIST_Warranty_Calc::allocate( $qty, $batches ); // без партій — порожньо, нічого не пишемо
			foreach ( $takes as $bid => $take ) {
				$map[ (int) $bid ] = ( $map[ (int) $bid ] ?? 0 ) + (int) $take;
			}
		}

		$order->update_meta_data( self::STATE_META, 'consumed' );
		if ( $map ) {
			$order->update_meta_data( self::CONSUMED_META, $map );
		}
		$order->save();

		// Тільки після save() списуємо партії. Якщо процес впаде між save() і цим циклом —
		// партії залишаться НЕсписаними (недосписання видно на сторінці і виправляється вручну),
		// замість подвійного списання при повторі хука.
		foreach ( $map as $bid => $take ) {
			ORDELIST_Warranty_Store::take_qty( (int) $bid, (int) $take );
		}
	}

	private static function restore( WC_Order $order ) {
		$map = $order->get_meta( self::CONSUMED_META );
		if ( is_array( $map ) ) {
			foreach ( $map as $bid => $qty ) {
				ORDELIST_Warranty_Store::give_back( (int) $bid, (int) $qty ); // видалена партія — мовчки пропускається
			}
		}
		$order->update_meta_data( self::STATE_META, 'restored' );
		$order->delete_meta_data( self::CONSUMED_META );
		$order->save();
	}

	/* ---------- щоденна перевірка + сповіщення ---------- */

	/** Викликається кроном і як фолбек із сторінки партій; шле ОДИН зведений email. */
	public static function run_check() {
		$o = ORDELIST_Settings::get();
		if ( ! ORDELIST_Settings::is_yes( $o, 'warranty_enabled' ) ) {
			return;
		}
		$today   = current_time( 'Y-m-d' );
		$window  = (int) $o['warranty_days'];
		$horizon = ORDELIST_Warranty_Calc::add_days( $today, $window );
		$lists   = ORDELIST_Warranty_Calc::classify( ORDELIST_Warranty_Store::due_rows( $horizon ), $today, $window );

		foreach ( $lists['soon'] as $r ) {
			ORDELIST_Warranty_Store::set_notified( (int) $r['id'], ORDELIST_Warranty_Calc::NOTIFIED_SOON );
		}
		foreach ( $lists['expired'] as $r ) {
			ORDELIST_Warranty_Store::set_notified( (int) $r['id'], ORDELIST_Warranty_Calc::NOTIFIED_EXPIRED );
		}
		if ( $lists['soon'] || $lists['expired'] ) {
			self::send_email( $lists, $window );
		}
	}

	/** Назва товару/варіації партії; видалений товар → «#id». */
	public static function target_name( $row ) {
		$id = (int) ( (int) $row['variation_id'] > 0 ? $row['variation_id'] : $row['product_id'] );
		$p  = $id ? wc_get_product( $id ) : null;
		return $p ? wp_strip_all_tags( $p->get_formatted_name() ) : ( '#' . $id );
	}

	private static function send_email( $lists, $window ) {
		$subject = __( 'OLE — product expiry dates need attention', 'order-list-enhancer' );
		$lines   = array();
		if ( $lists['expired'] ) {
			$lines[] = __( 'Expired:', 'order-list-enhancer' );
			foreach ( $lists['expired'] as $r ) {
				$lines[] = self::email_line( $r );
			}
			$lines[] = '';
		}
		if ( $lists['soon'] ) {
			/* translators: %d: warning window in days. */
			$lines[] = sprintf( __( 'Expiring within %d days:', 'order-list-enhancer' ), (int) $window );
			foreach ( $lists['soon'] as $r ) {
				$lines[] = self::email_line( $r );
			}
		}
		wp_mail( get_option( 'admin_email' ), $subject, implode( "\n", $lines ) );
	}

	private static function email_line( $row ) {
		$name = self::target_name( $row );
		if ( '' !== (string) $row['note'] ) {
			$name .= ' (' . $row['note'] . ')';
		}
		/* translators: 1: product name, 2: expiry date, 3: quantity left. */
		return sprintf( __( '%1$s — %2$s, %3$d left', 'order-list-enhancer' ), $name, $row['expiry'], (int) $row['qty'] );
	}

	/* ---------- банер ---------- */

	public static function banner() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// Не докучати на самій сторінці партій — там усе й так видно.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, ORDELIST_Warranty_Admin::SLUG ) ) {
			return;
		}
		$o       = ORDELIST_Settings::get();
		$today   = current_time( 'Y-m-d' );
		$horizon = ORDELIST_Warranty_Calc::add_days( $today, (int) $o['warranty_days'] );
		$counts  = ORDELIST_Warranty_Store::due_counts( $today, $horizon );
		if ( $counts['expired'] < 1 && $counts['soon'] < 1 ) {
			return;
		}
		$bits = array();
		if ( $counts['soon'] ) {
			/* translators: 1: number of batches, 2: warning window in days. */
			$bits[] = sprintf( _n( '%1$d product batch expires within %2$d days', '%1$d product batches expire within %2$d days', $counts['soon'], 'order-list-enhancer' ), (int) $counts['soon'], (int) $o['warranty_days'] );
		}
		if ( $counts['expired'] ) {
			/* translators: %d: number of batches. */
			$bits[] = sprintf( _n( '%d batch already expired', '%d batches already expired', $counts['expired'], 'order-list-enhancer' ), (int) $counts['expired'] );
		}
		printf(
			'<div class="notice notice-warning"><p>%s — <a href="%s">%s</a></p></div>',
			esc_html( implode( ', ', $bits ) ),
			esc_url( admin_url( 'admin.php?page=' . ORDELIST_Warranty_Admin::SLUG ) ),
			esc_html__( 'Open warranty dates', 'order-list-enhancer' )
		);
	}
}
