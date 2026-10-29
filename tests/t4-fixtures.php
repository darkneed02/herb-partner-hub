<?php
// T4 fixtures: shipping zones per country, the CartFlows store checkout, the joined product.
// Safe to run again.
foreach ( WC_Shipping_Zones::get_zones() as $z ) { if ( in_array( $z['zone_name'], array( 'Malaysia', 'Singapore', 'Thailand' ), true ) ) { WC_Shipping_Zones::delete_zone( $z['id'] ); } }
foreach ( array( array( 'MY', 'Malaysia', 30 ), array( 'SG', 'Singapore', 20 ), array( 'TH', 'Thailand', 5 ) ) as $d ) {
	$z = new WC_Shipping_Zone();
	$z->set_zone_name( $d[1] );
	$z->add_location( $d[0], 'country' );
	$z->save();
	$i = $z->add_shipping_method( 'flat_rate' );
	update_option( 'woocommerce_flat_rate_' . $i . '_settings', array( 'title' => 'Flat ' . $d[1], 'cost' => (string) $d[2], 'tax_status' => 'none', 'enabled' => 'yes' ) );
	echo $d[1], ' zone ', $z->get_id(), "\n";
}
if ( ! ( new WC_Shipping_Zone( 0 ) )->get_shipping_methods() ) {
	$i = ( new WC_Shipping_Zone( 0 ) )->add_shipping_method( "flat_rate" );
	update_option( "woocommerce_flat_rate_" . $i . "_settings", array( "title" => "Flat World", "cost" => "50", "tax_status" => "none", "enabled" => "yes" ) );
}
if ( ! get_option( "_cartflows_store_checkout" ) ) {
	$flow = wp_insert_post( array( 'post_type' => 'cartflows_flow', 'post_title' => 'Store Checkout', 'post_name' => 'store-checkout', 'post_status' => 'publish' ) );
	$co   = wp_insert_post( array( 'post_type' => 'cartflows_step', 'post_title' => 'Checkout', 'post_name' => 'checkout-2', 'post_status' => 'publish', 'post_content' => '[cartflows_checkout]' ) );
	$ty   = wp_insert_post( array( 'post_type' => 'cartflows_step', 'post_title' => 'Thank You', 'post_name' => 'thank-you', 'post_status' => 'publish', 'post_content' => '[cartflows_order_details]' ) );
	foreach ( array( $co => 'checkout', $ty => 'thankyou' ) as $id => $type ) {
		update_post_meta( $id, 'wcf-flow-id', $flow );
		update_post_meta( $id, 'wcf-step-type', $type );
		update_post_meta( $id, '_wp_page_template', 'cartflows-default' );
		wp_set_object_terms( $id, $type, 'cartflows_step_type' );
		wp_set_object_terms( $id, 'store-checkout', 'cartflows_step_flow' );
	}
	update_post_meta( $co, 'wcf-checkout-layout', 'modern-checkout' );
	update_post_meta( $flow, 'wcf-steps', array( array( 'id' => $co, 'title' => 'Checkout', 'type' => 'checkout' ), array( 'id' => $ty, 'title' => 'Thank You', 'type' => 'thankyou' ) ) );
	update_option( '_cartflows_common', array( 'global_checkout' => $flow, 'override_global_checkout' => 'enable', 'disallow_indexing' => 'disable', 'default_page_builder' => 'other' ) );
	update_option( '_cartflows_store_checkout', $flow );
	flush_rewrite_rules( false );
	echo "flow $flow co $co ty $ty ", get_permalink( $co ), "\n";
}
$p = get_page_by_path( "hph-fixture-product", OBJECT, "product" );
update_post_meta( $p->ID, "_hph_enabled", "yes" );
update_post_meta( $p->ID, "_hph_price", "30" );
