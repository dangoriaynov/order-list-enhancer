<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Сторінка «Планування закупівлі»: графік продажів по роках + рекомендація
 * скільки взяти на період. Уся математика - на клієнті; тут меню, ассети й AJAX.
 */
class ORDELIST_Forecast_Admin {

	const SLUG = 'ordelist-forecast';

	public static function init() {
		// Таблиця партій потрібна для залишків - гарантуємо її наявність (ідемпотентно).
		ORDELIST_Warranty_Store::maybe_upgrade();
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_ordelist_fc_series', array( __CLASS__, 'ajax_series' ) );
		add_action( 'wp_ajax_ordelist_fc_add_batch', array( __CLASS__, 'ajax_add_batch' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Purchase planning', 'ordelist' ),
			__( 'Purchase planning', 'ordelist' ),
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
		wp_enqueue_script( 'ordelist-datepicker', ORDELIST_URL . 'assets/js/ole-datepicker.js', array( 'jquery', 'jquery-ui-datepicker' ), ORDELIST_VERSION, true );
		wp_enqueue_script( 'ordelist-forecast', ORDELIST_URL . 'assets/js/ole-forecast.js', array( 'jquery', 'wc-enhanced-select', 'ordelist-chartjs', 'ordelist-forecast-calc', 'ordelist-datepicker' ), ORDELIST_VERSION, true );
		wp_localize_script(
			'ordelist-forecast',
			'ORDELIST_FC',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ordelist_fc' ),
				'today'   => current_time( 'Y-m-d' ),
				'margin'  => (int) $o['forecast_margin'],
				'i18n'    => array(
					'error'     => __( 'Failed.', 'ordelist' ),
					'kg'        => __( 'kg', 'ordelist' ),
					'pcs'       => __( 'pcs', 'ordelist' ),
					'year'      => __( 'Year', 'ordelist' ),
					'noWeight'  => __( 'no weight set - pieces only', 'ordelist' ),
					/* translators: %s: number of variations. */
					'noWeightMany' => __( '%s variations have no weight - counted in pieces only', 'ordelist' ),
					'noBatches' => __( 'Stock not subtracted - no batches are tracked for this product.', 'ordelist' ),
					'noSales'   => __( 'No sales recorded for this product yet.', 'ordelist' ),
					'projection' => __( 'projection', 'ordelist' ),
					'refZero'   => __( 'Reference year has no sales in this slice - coefficient set to 1.', 'ordelist' ),
					/* translators: %s: amount with unit, e.g. "3 kg". */
					'expiring'  => __( 'of which %s expires before the period ends', 'ordelist' ),
					'forecastL' => __( 'Forecast demand', 'ordelist' ),
					'stockL'    => __( 'Sellable stock', 'ordelist' ),
					'buyL'      => __( 'Recommended purchase', 'ordelist' ),
					'varL'      => __( 'Variation', 'ordelist' ),
					/* translators: %s: year. */
					'soldIn'    => __( 'Sold in %s', 'ordelist' ),
					'stockCol'  => __( 'Stock', 'ordelist' ),
					'goodUntil' => __( 'Good until (optional)', 'ordelist' ),
					'add'       => __( 'Add', 'ordelist' ),
					'periodCapped' => __( 'Period longer than a year - the comparison uses its first 365 days.', 'ordelist' ),
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

	/** Швидкий запис наличності: створює партію (порожня дата - страж 2099-12-31). */
	public static function ajax_add_batch() {
		self::guard();
		$target = isset( $_POST['target'] ) ? absint( $_POST['target'] ) : 0;
		$qty    = isset( $_POST['qty'] ) ? (int) $_POST['qty'] : 0;
		$raw    = isset( $_POST['expiry'] ) ? sanitize_text_field( wp_unslash( $_POST['expiry'] ) ) : '';
		$expiry = ORDELIST_Warranty_Calc::stock_expiry( $raw );
		$p      = $target ? wc_get_product( $target ) : null;
		if ( ! $p || $qty <= 0 || null === $expiry || $p->is_type( 'variable' ) ) {
			wp_send_json_error( array( 'message' => 'bad_input' ), 400 );
		}
		if ( $p->is_type( 'variation' ) ) {
			$product_id   = (int) $p->get_parent_id();
			$variation_id = (int) $p->get_id();
		} else {
			$product_id   = (int) $p->get_id();
			$variation_id = 0;
		}
		ORDELIST_Warranty_Store::add_batch( $product_id, $variation_id, $expiry, $qty, '' );
		ORDELIST_Warranty::run_check(); // нова партія може вже бути у вікні попередження
		wp_send_json_success( array( 'ok' => true ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	public static function render() {
		?>
		<div class="wrap ole-fc-wrap">
			<h1><?php esc_html_e( 'Purchase planning', 'ordelist' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Pick a product or a single variation: yearly sales curves overlay on the chart, the table compares the selected slice across years, and the panel computes how much to order for the chosen period.', 'ordelist' ); ?></p>

			<div class="ole-fc-controls">
				<select class="wc-product-search ole-fc-product" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'ordelist' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:360px"></select>
				<label class="ole-fc-needs-product" hidden><input type="radio" name="ole-fc-unit" value="kg" checked/> <?php esc_html_e( 'kg', 'ordelist' ); ?></label>
				<label class="ole-fc-needs-product" hidden><input type="radio" name="ole-fc-unit" value="pcs"/> <?php esc_html_e( 'pcs', 'ordelist' ); ?></label>
				<span class="ole-fc-loader" hidden></span>
			</div>

			<div class="ole-fc-chart ole-fc-needs-product" hidden><canvas id="ole-fc-canvas"></canvas></div>

			<div class="ole-fc-controls ole-fc-needs-product" hidden>
				<label><?php esc_html_e( 'Period', 'ordelist' ); ?> <input type="hidden" class="ole-fc-start ole-date"/> - <input type="hidden" class="ole-fc-end ole-date"/></label>
				<button type="button" class="button ole-fc-preset" data-days="30"><?php esc_html_e( 'Month', 'ordelist' ); ?></button>
				<button type="button" class="button ole-fc-preset" data-days="91"><?php esc_html_e( 'Quarter', 'ordelist' ); ?></button>
				<button type="button" class="button ole-fc-preset" data-days="182"><?php esc_html_e( 'Half-year', 'ordelist' ); ?></button>
				<label><?php esc_html_e( 'Reference year', 'ordelist' ); ?> <select class="ole-fc-ref"></select></label>
				<label><?php esc_html_e( 'Coefficient', 'ordelist' ); ?> <input type="number" step="0.01" min="0" class="ole-fc-coef" style="width:90px"/></label>
				<button type="button" class="button ole-fc-coef-auto"><?php esc_html_e( 'auto', 'ordelist' ); ?></button>
				<label><?php esc_html_e( 'Margin, %', 'ordelist' ); ?> <input type="number" step="1" min="0" max="100" class="ole-fc-margin" style="width:80px"/></label>
			</div>

			<div class="ole-fc-result" hidden></div>

			<h2 class="ole-fc-needs-product" hidden><?php esc_html_e( 'Sold in the selected slice', 'ordelist' ); ?></h2>
			<table class="widefat striped ole-fc-totals ole-fc-needs-product" hidden><thead></thead><tbody></tbody></table>

			<h2 class="ole-fc-needs-product" hidden><?php esc_html_e( 'Stock and sales by variation', 'ordelist' ); ?></h2>
			<table class="widefat striped ole-fc-vars ole-fc-needs-product" hidden><thead></thead><tbody></tbody></table>
		</div>
		<?php
	}
}
