<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Сторінка «Планування закупівлі»: графік продажів по роках + рекомендація
 * скільки взяти на період. Уся математика — на клієнті; тут меню, ассети й AJAX.
 */
class ORDELIST_Forecast_Admin {

	const SLUG = 'ordelist-forecast';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_ordelist_fc_series', array( __CLASS__, 'ajax_series' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Purchase planning', 'order-list-enhancer' ),
			__( 'Purchase planning', 'order-list-enhancer' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	private static function is_screen() {
		$s = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $s && false !== strpos( (string) $s->id, self::SLUG );
	}

	public static function assets( $hook ) {
		if ( ! self::is_screen() ) {
			return;
		}
		$o = ORDELIST_Settings::get();
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'ordelist-forecast', ORDELIST_URL . 'assets/css/ole-forecast.css', array(), ORDELIST_VERSION );
		wp_enqueue_script( 'ordelist-chartjs', ORDELIST_URL . 'assets/vendor/chartjs/chart.umd.js', array(), '4.5.1', true );
		wp_enqueue_script( 'ordelist-forecast-calc', ORDELIST_URL . 'assets/js/ole-forecast-calc.js', array(), ORDELIST_VERSION, true );
		wp_enqueue_script( 'ordelist-forecast', ORDELIST_URL . 'assets/js/ole-forecast.js', array( 'jquery', 'wc-enhanced-select', 'ordelist-chartjs', 'ordelist-forecast-calc' ), ORDELIST_VERSION, true );
		wp_localize_script(
			'ordelist-forecast',
			'ORDELIST_FC',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ordelist_fc' ),
				'today'   => current_time( 'Y-m-d' ),
				'margin'  => (int) $o['forecast_margin'],
				'i18n'    => array(
					'error'     => __( 'Failed.', 'order-list-enhancer' ),
					'kg'        => __( 'kg', 'order-list-enhancer' ),
					'pcs'       => __( 'pcs', 'order-list-enhancer' ),
					'year'      => __( 'Year', 'order-list-enhancer' ),
					'noWeight'  => __( 'no weight set — pieces only', 'order-list-enhancer' ),
					'noBatches' => __( 'Stock not subtracted — no batches are tracked for this product.', 'order-list-enhancer' ),
					'refZero'   => __( 'Reference year has no sales in this slice — coefficient set to 1.', 'order-list-enhancer' ),
					/* translators: %s: amount with unit, e.g. "3 kg". */
					'expiring'  => __( 'of which %s expires before the period ends', 'order-list-enhancer' ),
					'forecastL' => __( 'Forecast demand', 'order-list-enhancer' ),
					'stockL'    => __( 'Sellable stock', 'order-list-enhancer' ),
					'buyL'      => __( 'Recommended purchase', 'order-list-enhancer' ),
				),
			)
		);
	}

	private static function guard() {
		check_ajax_referer( 'ordelist_fc', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified first via self::guard() (check_ajax_referer).
	public static function ajax_series() {
		self::guard();
		$picked  = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$payload = $picked ? ORDELIST_Forecast_Data::payload( $picked ) : null;
		if ( ! $payload ) {
			wp_send_json_error( array( 'message' => 'not_found' ), 404 );
		}
		wp_send_json_success( $payload );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	public static function render() {
		?>
		<div class="wrap ole-fc-wrap">
			<h1><?php esc_html_e( 'Purchase planning', 'order-list-enhancer' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Pick a product or a single variation: yearly sales curves overlay on the chart, the table compares the selected slice across years, and the panel computes how much to order for the chosen period.', 'order-list-enhancer' ); ?></p>

			<div class="ole-fc-controls">
				<select class="wc-product-search ole-fc-product" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'order-list-enhancer' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:360px"></select>
				<label><input type="radio" name="ole-fc-unit" value="kg" checked/> <?php esc_html_e( 'kg', 'order-list-enhancer' ); ?></label>
				<label><input type="radio" name="ole-fc-unit" value="pcs"/> <?php esc_html_e( 'pcs', 'order-list-enhancer' ); ?></label>
			</div>

			<div class="ole-fc-chart"><canvas id="ole-fc-canvas"></canvas></div>

			<div class="ole-fc-controls">
				<label><?php esc_html_e( 'Period', 'order-list-enhancer' ); ?> <input type="date" class="ole-fc-start"/> — <input type="date" class="ole-fc-end"/></label>
				<button type="button" class="button ole-fc-preset" data-days="30"><?php esc_html_e( 'Month', 'order-list-enhancer' ); ?></button>
				<button type="button" class="button ole-fc-preset" data-days="91"><?php esc_html_e( 'Quarter', 'order-list-enhancer' ); ?></button>
				<button type="button" class="button ole-fc-preset" data-days="182"><?php esc_html_e( 'Half-year', 'order-list-enhancer' ); ?></button>
				<label><?php esc_html_e( 'Reference year', 'order-list-enhancer' ); ?> <select class="ole-fc-ref"></select></label>
				<label><?php esc_html_e( 'Coefficient', 'order-list-enhancer' ); ?> <input type="number" step="0.01" min="0" class="ole-fc-coef" style="width:90px"/></label>
				<button type="button" class="button ole-fc-coef-auto"><?php esc_html_e( 'auto', 'order-list-enhancer' ); ?></button>
				<label><?php esc_html_e( 'Margin, %', 'order-list-enhancer' ); ?> <input type="number" step="1" min="0" max="100" class="ole-fc-margin" style="width:80px"/></label>
			</div>

			<div class="ole-fc-result" hidden></div>

			<h2><?php esc_html_e( 'Sold in the selected slice', 'order-list-enhancer' ); ?></h2>
			<table class="widefat striped ole-fc-totals"><thead></thead><tbody></tbody></table>
		</div>
		<?php
	}
}
