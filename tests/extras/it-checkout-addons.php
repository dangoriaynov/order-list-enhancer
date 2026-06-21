<?php
// Integration test: a synthetic order with a Checkout Add-On fee is converted.
$GLOBALS['fails'] = 0; // $GLOBALS so the counter survives WP-CLI eval-file include scope
function ck( $c, $m ) { global $fails; echo ( $c ? "ok   - " : "FAIL - " ) . "$m\n"; if ( ! $c ) { $fails++; } }

$target = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'return' => 'ids' ) );
$target_id = $target ? (int) $target[0] : 0;
ck( $target_id > 0, 'found a target product' );

$opts = OLE_Settings::get();
$opts['extras_enabled'] = 'yes';
$opts['extras_map']     = array( array( 'match' => 'TEST FEE EXTRA', 'product' => $target_id ) );
update_option( OLE_Settings::OPTION, $opts );

$order = wc_create_order();
$prod  = wc_get_product( $target_id );
$item  = new WC_Order_Item_Product();
$item->set_product( $prod ); $item->set_quantity( 1 ); $item->set_subtotal( 10.0 ); $item->set_total( 10.0 );
$order->add_item( $item );

$fee = new WC_Order_Item_Fee();
$fee->set_name( 'TEST FEE EXTRA' );
$fee->set_amount( 5.5 );
$fee->set_total( 5.5 );
$fee->set_tax_status( 'none' );
$order->add_item( $fee );
$order->set_total( 15.5 );
$order->save();
$before_total = (float) $order->get_total();

$n = OLE_Extras::convert( $order );
ck( $n === 1, 'convert reports 1 extra' );

$order = wc_get_order( $order->get_id() );
ck( count( $order->get_items( 'fee' ) ) === 0, 'fee removed' );
$lines = $order->get_items( 'line_item' );
ck( count( $lines ) === 2, 'order now has 2 product lines' );
$child = null;
foreach ( $lines as $li ) { if ( $li->get_meta( '_ole_addon_origin' ) ) { $child = $li; } }
ck( $child && abs( (float) $child->get_total() - 5.5 ) < 0.001, 'new line priced 5.50' );
ck( abs( (float) $order->get_total() - $before_total ) < 0.001, 'order total unchanged' );

$order->delete( true );

// --- Scenario 2: ghost product (deleted) — fee must survive, net-zero guard ---
update_option( OLE_Settings::OPTION, array(
	'extras_enabled' => 'yes',
	'extras_map'     => array( array( 'match' => 'GHOST FEE', 'product' => 999999999 ) ),
) );

$order2 = wc_create_order();
$item2  = new WC_Order_Item_Product();
$item2->set_product( $prod ); $item2->set_quantity( 1 ); $item2->set_subtotal( 10.0 ); $item2->set_total( 10.0 );
$order2->add_item( $item2 );

$fee2 = new WC_Order_Item_Fee();
$fee2->set_name( 'GHOST FEE' );
$fee2->set_amount( 5.5 );
$fee2->set_total( 5.5 );
$fee2->set_tax_status( 'none' );
$order2->add_item( $fee2 );
$order2->set_total( 15.5 );
$order2->save();

$n2 = OLE_Extras::convert( $order2 );
ck( $n2 === 0, 'ghost: convert reports 0 conversions' );

$order2 = wc_get_order( $order2->get_id() );
ck( count( $order2->get_items( 'fee' ) ) === 1, 'ghost: fee item preserved' );
ck( count( $order2->get_items( 'line_item' ) ) === 1, 'ghost: still exactly 1 product line' );
ck( abs( (float) $order2->get_total() - 15.5 ) < 0.001, 'ghost: order total unchanged (15.50)' );

$order2->delete( true );

// Quantity: a checkout fee with _wc_checkout_add_on_label "2 бр" -> line qty 2.
update_option( OLE_Settings::OPTION, array(
	'extras_enabled' => 'yes',
	'extras_map'     => array( array( 'match' => 'QTY FEE', 'product' => $target_id ) ),
) );
$order3 = wc_create_order();
$item3  = new WC_Order_Item_Product();
$item3->set_product( $prod ); $item3->set_quantity( 1 ); $item3->set_subtotal( 10.0 ); $item3->set_total( 10.0 );
$order3->add_item( $item3 );
$fee3 = new WC_Order_Item_Fee();
$fee3->set_name( 'QTY FEE' ); $fee3->set_amount( 9.0 ); $fee3->set_total( 9.0 ); $fee3->set_tax_status( 'none' );
$fee3->add_meta_data( '_wc_checkout_add_on_label', '2 бр', true );
$order3->add_item( $fee3 ); $order3->set_total( 19.0 ); $order3->save();
OLE_Extras::convert( $order3 );
$order3 = wc_get_order( $order3->get_id() );
$child3 = null;
foreach ( $order3->get_items( 'line_item' ) as $li ) { if ( $li->get_meta( '_ole_addon_origin' ) ) { $child3 = $li; } }
ck( $child3 && (int) $child3->get_quantity() === 2, 'qty: checkout «2 бр» -> line qty 2' );
ck( $child3 && abs( (float) $child3->get_total() - 9.0 ) < 0.001, 'qty: line total = paid 9.00' );
$order3->delete( true );

$f = (int) $GLOBALS['fails']; echo $f ? "\n{$f} FAILED\n" : "\nALL PASS\n";
