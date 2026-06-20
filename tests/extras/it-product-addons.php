<?php
// Integration test: a synthetic order with a Product Add-On is converted.
// Run: $WP eval-file wp-content/plugins/order-list-enhancer/tests/extras/it-product-addons.php
$GLOBALS['fails'] = 0; // $GLOBALS so the counter survives WP-CLI eval-file include scope
function ck( $c, $m ) { global $fails; echo ( $c ? "ok   - " : "FAIL - " ) . "$m\n"; if ( ! $c ) { $fails++; } }

// Pick a real published product to map to.
$target = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'return' => 'ids' ) );
$target_id = $target ? (int) $target[0] : 0;
ck( $target_id > 0, 'found a target product' );

// Force a mapping in settings (in-memory for this run).
$opts = OLE_Settings::get();
$opts['extras_enabled'] = 'yes';
$opts['extras_map']     = array( array( 'match' => '+ TEST EXTRA pH', 'product' => $target_id ) );
update_option( OLE_Settings::OPTION, $opts );

// Build a draft order: one line item priced 5.50 base + 1.00 add-on = 6.50.
$order = wc_create_order();
$prod  = wc_get_product( $target_id );
$item  = new WC_Order_Item_Product();
$item->set_product( $prod );
$item->set_quantity( 1 );
$item->set_subtotal( 6.50 );
$item->set_total( 6.50 );
$item->add_meta_data( 'Екстри', '+ TEST EXTRA pH', true );
$item->add_meta_data( '_pao_ids', array( array( 'key' => 'Екстри', 'value' => '+ TEST EXTRA pH', 'id' => '1', 'raw_value' => '+ TEST EXTRA pH', 'raw_price' => 1, 'price_type' => 'flat_fee' ) ), true );
$item->add_meta_data( '_pao_total', 1, true );
$order->add_item( $item );
$order->set_total( 6.50 );
$order->save();
$before_total = (float) $order->get_total();

$n = OLE_Extras::convert( $order );
ck( $n === 1, 'convert reports 1 extra' );

$order = wc_get_order( $order->get_id() );
$lines = $order->get_items( 'line_item' );
ck( count( $lines ) === 2, 'order now has 2 line items' );

$parent = null; $child = null;
foreach ( $lines as $li ) {
	if ( $li->get_meta( '_ole_addon_origin' ) ) { $child = $li; } else { $parent = $li; }
}
ck( $parent && abs( (float) $parent->get_total() - 5.50 ) < 0.001, 'parent total reduced to 5.50' );
ck( $parent && '' === $parent->get_meta( 'Екстри' ), 'parent visible add-on meta removed' );
ck( $child && abs( (float) $child->get_total() - 1.00 ) < 0.001, 'new line priced 1.00' );
ck( abs( (float) $order->get_total() - $before_total ) < 0.001, 'order total unchanged' );
ck( (int) $order->get_meta( '_ole_extras_converted' ) === 1, 'idempotency guard set' );

// Idempotent: second run does nothing.
$n2 = OLE_Extras::convert( $order );
ck( $n2 === 0, 'second convert is a no-op' );

// Cleanup.
$order->delete( true );

// Regression (C1): mapping points to a non-existent product → nothing converts, parent untouched.
$opts2 = OLE_Settings::get();
$opts2['extras_enabled'] = 'yes';
$opts2['extras_map']     = array( array( 'match' => '+ GHOST EXTRA', 'product' => 999999999 ) );
update_option( OLE_Settings::OPTION, $opts2 );

$o2   = wc_create_order();
$it2  = new WC_Order_Item_Product();
$it2->set_product( $prod ); $it2->set_quantity( 1 ); $it2->set_subtotal( 7.50 ); $it2->set_total( 7.50 );
$it2->add_meta_data( 'Екстри', '+ GHOST EXTRA', true );
$it2->add_meta_data( '_pao_ids', array( array( 'key' => 'Екстри', 'value' => '+ GHOST EXTRA', 'id' => '2', 'raw_value' => '+ GHOST EXTRA', 'raw_price' => 1.5, 'price_type' => 'flat_fee' ) ), true );
$it2->add_meta_data( '_pao_total', 1.5, true );
$o2->add_item( $it2 ); $o2->set_total( 7.50 ); $o2->save();

$n3 = OLE_Extras::convert( $o2 );
ck( $n3 === 0, 'product-not-found: convert reports 0' );
$o2 = wc_get_order( $o2->get_id() );
ck( count( $o2->get_items( 'line_item' ) ) === 1, 'product-not-found: no extra line added' );
$only = array_values( $o2->get_items( 'line_item' ) )[0];
ck( abs( (float) $only->get_total() - 7.50 ) < 0.001, 'product-not-found: parent total untouched' );
$o2->delete( true );

$f = (int) $GLOBALS['fails']; echo $f ? "\n{$f} FAILED\n" : "\nALL PASS\n";
