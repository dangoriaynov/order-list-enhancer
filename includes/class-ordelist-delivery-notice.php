<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Підсвітка поля дати доставки (плагін orddd) на чекауті: акцентний блок над полем
 * + опційний банер відпустки. Тільки презентація - нічого не пишемо в замовлення.
 * Тихо нічого не робить, якщо поля orddd на сторінці немає.
 */
class ORDELIST_Delivery_Notice {

	/**
	 * Чи активний банер відпустки на дату $today.
	 * Pure, без WordPress.
	 *
	 * @param string $until Дата закінчення відпустки у форматі YYYY-MM-DD (або порожньо).
	 * @param string $today Поточна дата у форматі YYYY-MM-DD.
	 * @return bool true, якщо $until - реальна дата і $today <= $until.
	 */
	public static function vacation_active( string $until, string $today ) : bool {
		$until = trim( (string) $until );
		$d     = DateTime::createFromFormat( 'Y-m-d', $until );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $until ) {
			return false;
		}
		return strcmp( (string) $today, $until ) <= 0;
	}

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/** Перекладні дефолти текстів (фолбек, коли налаштування порожні). */
	public static function defaults_copy() {
		return array(
			'title'    => __( '📦 This is the SHIPPING date', 'ordelist' ),
			'body'     => __( 'Not the date you receive it. Delivery to the courier office usually takes about 1 working day.', 'ordelist' ),
			/* translators: %s: vacation end date. */
			'vacation' => __( '🌴 We are on vacation until %s. Orders placed now will be shipped after that date.', 'ordelist' ),
		);
	}

	/** Дані для фронтенду: тексти + (опційно) банер відпустки. */
	public static function payload() {
		$o   = ORDELIST_Settings::get();
		$def = self::defaults_copy();

		$title = trim( (string) $o['delivery_notice_title'] );
		$title = '' !== $title ? $title : $def['title'];
		$body  = trim( (string) $o['delivery_notice_body'] );
		$body  = '' !== $body ? $body : $def['body'];

		$vacation = null;
		if ( ORDELIST_Settings::is_yes( $o, 'delivery_vacation_enabled' )
			&& self::vacation_active( (string) $o['delivery_vacation_until'], current_time( 'Y-m-d' ) ) ) {
			$tpl  = '' !== trim( (string) $o['delivery_vacation_text'] ) ? (string) $o['delivery_vacation_text'] : $def['vacation'];
			$date = date_i18n( get_option( 'date_format' ), strtotime( (string) $o['delivery_vacation_until'] . ' 12:00:00' ) );
			// str_replace (not sprintf) so stray % in the owner's text can't break it.
			$vacation = array( 'text' => str_replace( '%s', $date, $tpl ) );
		}

		return array(
			'title'    => $title,
			'body'     => $body,
			'vacation' => $vacation,
		);
	}

	public static function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_style( 'ordelist-delivery-notice', ORDELIST_URL . 'assets/css/ole-delivery-notice.css', array(), ORDELIST_VERSION );
		wp_enqueue_script( 'ordelist-delivery-notice', ORDELIST_URL . 'assets/js/ole-delivery-notice.js', array(), ORDELIST_VERSION, true );
		wp_localize_script( 'ordelist-delivery-notice', 'ORDELIST_DELIVERY', self::payload() );
	}
}
