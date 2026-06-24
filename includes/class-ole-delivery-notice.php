<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Підсвітка поля дати доставки (плагін orddd) на чекауті: акцентний блок над полем
 * + опційний банер відпустки. Тільки презентація — нічого не пишемо в замовлення.
 * Тихо нічого не робить, якщо поля orddd на сторінці немає.
 */
class OLE_Delivery_Notice {

	/**
	 * Чи активний банер відпустки на дату $today.
	 * Pure, без WordPress.
	 *
	 * @param string $until Дата закінчення відпустки у форматі YYYY-MM-DD (або порожньо).
	 * @param string $today Поточна дата у форматі YYYY-MM-DD.
	 * @return bool true, якщо $until — реальна дата і $today <= $until.
	 */
	public static function vacation_active( $until, $today ) {
		$until = trim( (string) $until );
		$d     = DateTime::createFromFormat( 'Y-m-d', $until );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $until ) {
			return false;
		}
		return strcmp( (string) $today, $until ) <= 0;
	}
}
