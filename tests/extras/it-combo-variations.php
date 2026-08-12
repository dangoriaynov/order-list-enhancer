<?php
// Integration test: a combo line is split into the base product + its component.
// Run: $WP eval-file wp-content/plugins/ordelist/tests/extras/it-combo-variations.php
$GLOBALS['fails'] = 0; // $GLOBALS so the counter survives WP-CLI eval-file include scope
function ck( $c, $m ) { global $fails; echo ( $c ? "ok   - " : "FAIL - " ) . "$m\n"; if ( ! $c ) { $fails++; } }

// Three throwaway products: the combo as sold, the base it becomes, the component it carries.
function mk_product( $name, $price, $stock = null, $cost = null ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_price( $price );
	$p->set_status( 'publish' );
	if ( null !== $stock ) {
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $stock );
	}
	$p->save();
	if ( null !== $cost ) {
		update_post_meta( $p->get_id(), '_wc_cog_cost', $cost );
	}
	return $p->get_id();
}
$combo_id = mk_product( 'OLE TEST combo', 10.00, 50, 5.73 );
$base_id  = mk_product( 'OLE TEST base', 7.00, 50, 5.68 );
$comp_id  = mk_product( 'OLE TEST component', 2.05, 100, 0.05 );
ck( $combo_id && $base_id && $comp_id, 'test products created' );

$opts = ORDELIST_Settings::get();
$opts['extras_enabled'] = 'yes';
$opts['combo_map']      = array( array( 'combo' => $combo_id, 'base' => $base_id, 'product' => $comp_id, 'qty' => 1 ) );
update_option( ORDELIST_Settings::OPTION, $opts );

// An order whose stock was already reduced, so the component must be decremented too.
$order = wc_create_order();
$item  = new WC_Order_Item_Product();
$item->set_product( wc_get_product( $combo_id ) );
$item->set_quantity( 1 );
$item->set_subtotal( 10.00 );
$item->set_total( 10.00 );
$item->add_meta_data( '_wc_cog_item_cost', '5.73', true );
$item->add_meta_data( '_wc_cog_item_total_cost', '5.73', true );
$item->add_meta_data( '_reduced_stock', 1, true );
$order->add_item( $item );
$order->set_total( 10.00 );
$order->update_meta_data( '_order_stock_reduced', 'yes' );
$order->update_meta_data( '_wc_cog_order_total_cost', '5.73' );
$order->save();
$before_total = (float) $order->get_total();
$comp_stock   = (int) wc_get_product( $comp_id )->get_stock_quantity();

$n = ORDELIST_Extras::convert( $order );
ck( 1 === $n, 'convert reports 1 component split out' );

$order = wc_get_order( $order->get_id() );
$lines = $order->get_items( 'line_item' );
ck( count( $lines ) === 2, 'combo line became 2 lines' );

$base_line = null; $comp_line = null;
foreach ( $lines as $li ) {
	if ( $li->get_meta( '_ordelist_combo_split' ) ) { $base_line = $li; } else { $comp_line = $li; }
}
ck( $base_line && (int) $base_line->get_product_id() === $base_id, 'the sold line now points at the base product' );
ck( $base_line && abs( (float) $base_line->get_total() - 7.73 ) < 0.001, 'base line priced 7.73 (catalogue share)' );
ck( $comp_line && (int) $comp_line->get_product_id() === $comp_id, 'component line added for the mapped product' );
ck( $comp_line && abs( (float) $comp_line->get_total() - 2.27 ) < 0.001, 'component line priced 2.27 (catalogue share)' );
ck( $base_line && $comp_line && abs( ( (float) $base_line->get_total() + (float) $comp_line->get_total() ) - 10.00 ) < 0.001, 'the two lines still sum to the original line' );
ck( abs( (float) $order->get_total() - $before_total ) < 0.001, 'order total unchanged' );

// Stock: the component is decremented, the base/combo share of stock is untouched.
ck( (int) wc_get_product( $comp_id )->get_stock_quantity() === $comp_stock - 1, 'component stock decremented by 1' );
ck( $comp_line && (int) $comp_line->get_meta( '_reduced_stock' ) === 1, 'component line tagged _reduced_stock for restock-on-cancel' );

// Cost comes from each product's own catalogue field, and the order cost still adds up.
ck( $base_line && abs( (float) $base_line->get_meta( '_wc_cog_item_total_cost' ) - 5.68 ) < 0.001, 'base line cost read from the catalogue' );
ck( $comp_line && abs( (float) $comp_line->get_meta( '_wc_cog_item_total_cost' ) - 0.05 ) < 0.001, 'component line cost read from the catalogue' );
ck( abs( (float) $order->get_meta( '_wc_cog_order_total_cost' ) - 5.73 ) < 0.001, 'order cost still equals the sum of line costs' );

// Provenance for the admin: "was -> now" on the base line, "split out of" on the component.
$split = $base_line ? $base_line->get_meta( '_ordelist_combo_split' ) : null;
ck( is_array( $split ) && 'OLE TEST combo' === $split['label'] && abs( (float) $split['price'] - 10.00 ) < 0.001, 'base line records what it was' );
ck( is_array( $split ) && count( $split['parts'] ) === 1 && (int) $split['parts'][0]['item'] === $comp_line->get_id(), 'base line points at the line it spawned' );
$origin = $comp_line ? $comp_line->get_meta( '_ordelist_addon_origin' ) : null;
ck( is_array( $origin ) && 'combo' === $origin['source'] && 'OLE TEST combo' === $origin['label'], 'component line records the combo it came from' );

// Idempotent per line: a forced re-run does not split it a second time.
$n2 = ORDELIST_Extras::convert( $order, true );
ck( 0 === $n2, 'forced re-run is a no-op on an already split line' );
ck( count( wc_get_order( $order->get_id() )->get_items( 'line_item' ) ) === 2, 'still 2 lines after the re-run' );
$order->delete( true );

// $force is what lets an order fixed after the fact convert at all.
$o2 = wc_create_order();
$i2 = new WC_Order_Item_Product();
$i2->set_product( wc_get_product( $combo_id ) );
$i2->set_quantity( 1 ); $i2->set_subtotal( 10.00 ); $i2->set_total( 10.00 );
$o2->add_item( $i2 ); $o2->set_total( 10.00 );
$o2->update_meta_data( '_ordelist_extras_converted', 1 ); // e.g. a checkout add-on converted earlier
$o2->save();
ck( 0 === ORDELIST_Extras::convert( $o2 ), 'an already-flagged order is skipped without $force' );
ck( 1 === ORDELIST_Extras::convert( $o2, true ), '$force converts an already-flagged order' );
$o2->delete( true );

// Quantity: 2 combos -> 2 components on one line, money still conserved.
$o3 = wc_create_order();
$i3 = new WC_Order_Item_Product();
$i3->set_product( wc_get_product( $combo_id ) );
$i3->set_quantity( 2 ); $i3->set_subtotal( 20.00 ); $i3->set_total( 20.00 );
$o3->add_item( $i3 ); $o3->set_total( 20.00 ); $o3->save();
ORDELIST_Extras::convert( $o3 );
$o3    = wc_get_order( $o3->get_id() );
$sum3  = 0.0; $qty3 = 0;
foreach ( $o3->get_items( 'line_item' ) as $li ) {
	$sum3 += (float) $li->get_total();
	if ( (int) $li->get_product_id() === $comp_id ) { $qty3 = (int) $li->get_quantity(); }
}
ck( 2 === $qty3, 'qty 2 combo yields 2 components' );
ck( abs( $sum3 - 20.00 ) < 0.001, 'qty 2 combo still sums to the line total' );
$o3->delete( true );

// Safety: a mapping whose component product is gone leaves the line completely alone.
$opts2 = ORDELIST_Settings::get();
$opts2['extras_enabled'] = 'yes';
$opts2['combo_map']      = array( array( 'combo' => $combo_id, 'base' => $base_id, 'product' => 999999999, 'qty' => 1 ) );
update_option( ORDELIST_Settings::OPTION, $opts2 );
$o4 = wc_create_order();
$i4 = new WC_Order_Item_Product();
$i4->set_product( wc_get_product( $combo_id ) );
$i4->set_quantity( 1 ); $i4->set_subtotal( 10.00 ); $i4->set_total( 10.00 );
$o4->add_item( $i4 ); $o4->set_total( 10.00 ); $o4->save();
ck( 0 === ORDELIST_Extras::convert( $o4 ), 'missing component: convert reports 0' );
$o4    = wc_get_order( $o4->get_id() );
$only4 = array_values( $o4->get_items( 'line_item' ) )[0];
ck( count( $o4->get_items( 'line_item' ) ) === 1 && (int) $only4->get_product_id() === $combo_id, 'missing component: line left untouched' );
ck( abs( (float) $only4->get_total() - 10.00 ) < 0.001, 'missing component: price left untouched' );
$o4->delete( true );

// Cleanup.
foreach ( array( $combo_id, $base_id, $comp_id ) as $pid ) {
	$p = wc_get_product( $pid );
	if ( $p ) { $p->delete( true ); }
}

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
