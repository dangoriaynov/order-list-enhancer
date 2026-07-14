<?php
// Standalone unit tests for ORDELIST_Forecast_Data::shape_rows (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ordelist-forecast-data.php';

$fails = 0;
function check( $cond, $msg ) {
	global $fails;
	if ( $cond ) { echo "ok   - $msg\n"; } else { echo "FAIL - $msg\n"; $fails++; }
}

$rows = array(
	array( 'variation_id' => 10, 'd' => '2025-03-15', 'qty' => 2 ),
	array( 'variation_id' => 10, 'd' => '2025-03-15', 'qty' => 3 ),  // той самий день — сумується
	array( 'variation_id' => 10, 'd' => '2026-01-02', 'qty' => 1 ),
	array( 'variation_id' => 0,  'd' => '2025-07-01', 'qty' => 4 ),  // простий товар
	array( 'variation_id' => 11, 'd' => 'garbage',    'qty' => 9 ),  // зіпсована дата — пропуск
	array( 'variation_id' => 11, 'd' => '2025-07-01', 'qty' => 0 ),  // нульова кількість — пропуск
	array( 'variation_id' => 12, 'd' => '2024-02-29', 'qty' => 5 ),  // 29 лютого лишається як є (клієнт складає)
	array( 'variation_id' => 13, 'd' => '2025-03-01', 'qty' => 5 ),  // продаж
	array( 'variation_id' => 13, 'd' => '2025-03-05', 'qty' => -2 ), // повернення — від'ємний рядок проходить
);
$out = ORDELIST_Forecast_Data::shape_rows( $rows );

check( $out[10]['2025']['03-15'] === 5, 'same-day rows summed (2+3)' );
check( $out[10]['2026']['01-02'] === 1, 'years separated' );
check( $out[0]['2025']['07-01'] === 4, 'simple product keyed by variation_id 0' );
check( ! isset( $out[11] ), 'garbage date and zero qty rows skipped entirely' );
check( $out[12]['2024']['02-29'] === 5, 'Feb 29 passes through untouched' );
check( $out[13]['2025']['03-01'] === 5, 'refund rows pass through as negatives' );
check( $out[13]['2025']['03-05'] === -2, 'refund rows pass through as negatives' );
check( ORDELIST_Forecast_Data::shape_rows( array() ) === array(), 'empty input -> empty array' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
