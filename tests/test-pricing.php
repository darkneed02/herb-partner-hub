<?php
/**
 * T3 tests for M2 (dropshipper prices, participating products) and M3 (no promotions).
 *
 * Each viewer runs in its own process, because Tier Pricing Table and Woo Discount Rules
 * cache rules per request; switching users inside one process would test the cache.
 *
 *   wp eval-file test-pricing.php setup         fixtures + the 4 discount sources switched on
 *   wp eval-file test-pricing.php customer      normal customer still gets every discount
 *   wp eval-file test-pricing.php dropshipper   dropshipper gets exactly the dropshipper price
 *   wp eval-file test-pricing.php admin         admin with the dropshipper role still pays retail
 *
 * Needs woo-discount-rules, tier-pricing-table, power-coupons and stherb-promo-engine active,
 * and the pricing + discounts modules on. Never run it against a real shop.
 */

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check( string $name, $actual, $expected ) {
	if ( $actual === $expected ) { $GLOBALS['pass']++; echo "PASS  $name\n"; }
	else { $GLOBALS['fail']++; echo "FAIL  $name\n      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) . "\n"; }
}

function fx(): array {
	return get_option( 'hph_test_fixtures', array() );
}

function simple_product( string $name, float $price ): WC_Product_Simple {
	$found = get_posts( array( 'post_type' => 'product', 'title' => $name, 'fields' => 'ids', 'post_status' => 'any', 'hph_skip' => true ) );
	$p     = $found ? wc_get_product( $found[0] ) : new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( (string) $price );
	$p->set_status( 'publish' );
	$p->save();
	return $p;
}

$mode = $args[0] ?? '';

if ( 'setup' === $mode ) {
	HPH_Settings::update( array( 'modules' => array( 'pricing' => 1, 'discounts' => 1 ), 'soft_launch' => 0 ) );
	require_once HPH_PATH . 'includes/pricing/class-hph-catalog.php'; // the module itself loads from the next request on

	$a = simple_product( 'HPH Price A', 50 );
	$b = simple_product( 'HPH Price B (not open)', 40 );
	$c = simple_product( 'HPH Price C (no price)', 60 );

	$found = get_posts( array( 'post_type' => 'product', 'title' => 'HPH Price V', 'fields' => 'ids', 'post_status' => 'any', 'hph_skip' => true ) );
	$v     = $found ? wc_get_product( $found[0] ) : new WC_Product_Variable();
	if ( ! $found ) {
		$attr = new WC_Product_Attribute();
		$attr->set_name( 'Size' );
		$attr->set_options( array( 'S', 'L' ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$v->set_name( 'HPH Price V' );
		$v->set_attributes( array( $attr ) );
		$v->set_status( 'publish' );
		$v->save();
		foreach ( array( 'S' => 20, 'L' => 25 ) as $size => $price ) {
			$var = new WC_Product_Variation();
			$var->set_parent_id( $v->get_id() );
			$var->set_attributes( array( 'size' => $size ) );
			$var->set_regular_price( (string) $price );
			$var->save();
		}
		$v = wc_get_product( $v->get_id() );
	}
	$kids = $v->get_children();
	$vs   = wc_get_product( $kids[0] );
	$vl   = wc_get_product( $kids[1] );

	// Reset our meta, then set it through the same API the admin screens use.
	foreach ( array( $a, $b, $c, $v, $vs, $vl ) as $p ) {
		$p->delete_meta_data( '_hph_price' );
		$p->delete_meta_data( '_hph_enabled' );
		$p->save();
	}
	check( 'A: open with price', HPH_Catalog::apply( $a, '30', true )['after'], array( 'price' => '30', 'enabled' => 'yes' ) );
	$a->save();
	$r = HPH_Catalog::apply( $c, null, true );
	$c->save();
	check( 'C: cannot open without a price', is_wp_error( $r ) ? $r->get_error_code() : 'no error', 'hph_no_price' );
	check( 'C: stays closed', get_post_meta( $c->get_id(), '_hph_enabled', true ), 'no' );
	$r = HPH_Catalog::apply( $b, '-5', null );
	check( 'B: negative price refused', is_wp_error( $r ), true );
	$r = HPH_Catalog::apply( $v, null, true );
	check( 'V: cannot open before a variation has a price', is_wp_error( $r ), true );
	HPH_Catalog::apply( $vs, '12', null );
	$vs->save();
	HPH_Catalog::apply( $v, null, true );
	$v->save();
	check( 'V: opens once a variation has a price', get_post_meta( $v->get_id(), '_hph_enabled', true ), 'yes' );
	check( 'price change logged', (bool) HPH_Log::query( array( 'action' => 'product_pricing_changed', 'object_id' => $a->get_id(), 'limit' => 1 ) ), true );

	// Tier Pricing Table: A costs 45 from 2 pieces.
	update_post_meta( $a->get_id(), '_tiered_price_rules_type', 'fixed' );
	update_post_meta( $a->get_id(), '_fixed_price_rules', array( 2 => 45 ) );

	// Woo Discount Rules: 10% off everything.
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->prefix}wdr_rules WHERE title = 'HPH test 10%'" );
	$wpdb->insert(
		"{$wpdb->prefix}wdr_rules",
		array(
			'enabled'                 => 1,
			'deleted'                 => 0,
			'exclusive'               => 0,
			'title'                   => 'HPH test 10%',
			'priority'                => 1,
			'filters'                 => '{"1":{"type":"all_products"}}',
			'conditions'              => '[]',
			'product_adjustments'     => '{"type":"percentage","value":"10","cart_label":""}',
			'cart_adjustments'        => '[]',
			'buy_x_get_x_adjustments' => '[]',
			'buy_x_get_y_adjustments' => '[]',
			'bulk_adjustments'        => '{"cart_label":""}',
			'set_adjustments'         => '[]',
			'other_discounts'         => '[]',
			'usage_limits'            => 0,
			'used_limits'             => 0,
			'additional'              => '{"condition_relationship":"and"}',
			'discount_type'           => 'wdr_simple_discount',
			'created_by'              => 1,
			'created_on'              => current_time( 'mysql' ),
			'modified_by'             => 1,
			'modified_on'             => current_time( 'mysql' ),
		)
	);

	// Stherb Promo Engine: base ladder on (2 = 10%, 3-4 = 15%).
	$promo                    = get_option( 'stherb_promo_settings', array() );
	$promo                    = is_array( $promo ) ? $promo : array();
	$promo['enabled']          = 1;
	$promo['eligibility_mode'] = 'all';
	$promo['discount_2']       = 10;
	$promo['discount_3_4']     = 15;
	$promo['discount_5_plus']  = 25;
	update_option( 'stherb_promo_settings', $promo );

	// Coupon TEN: 10% off.
	$coupon_id = wc_get_coupon_id_by_code( 'hphten' );
	$coupon    = new WC_Coupon( $coupon_id ?: 0 );
	$coupon->set_code( 'hphten' );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( 10 );
	$coupon->save();

	$users = array();
	foreach ( array( 'customer' => array( 'customer' ), 'dropshipper' => array( 'customer', 'herb_dropshipper' ), 'admin' => array( 'administrator', 'herb_dropshipper' ) ) as $key => $roles ) {
		$login = 'hph_t3_' . $key;
		$user  = get_user_by( 'login', $login );
		$id    = $user ? $user->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => "$login@example.com", 'role' => $roles[0] ) );
		$u     = new WP_User( $id );
		$u->set_role( $roles[0] );
		foreach ( array_slice( $roles, 1 ) as $role ) {
			$u->add_role( $role );
		}
		update_user_meta( $id, '_hph_status', 'active' );
		$users[ $key ] = (int) $id;
	}

	update_option(
		'hph_test_fixtures',
		array(
			'a'     => $a->get_id(),
			'b'     => $b->get_id(),
			'c'     => $c->get_id(),
			'v'     => $v->get_id(),
			'vs'    => $vs->get_id(),
			'vl'    => $vl->get_id(),
			'users' => $users,
		)
	);
	echo "\nTOTAL: {$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
	return;
}

// ---------- viewer runs ----------
$f = fx();
if ( ! $f || ! isset( $f['users'][ $mode ] ) ) {
	echo "Run setup first, then customer | dropshipper | admin\n";
	return;
}
add_filter( 'hph_is_storefront', '__return_true' );
wp_set_current_user( $f['users'][ $mode ] );
wc_load_cart();
WC()->cart->empty_cart();
WC()->cart->remove_coupons();

$a  = wc_get_product( $f['a'] );
$b  = wc_get_product( $f['b'] );
$v  = wc_get_product( $f['v'] );
$vs = wc_get_product( $f['vs'] );
$vl = wc_get_product( $f['vl'] );

WC()->cart->add_to_cart( $f['a'], 3 );
WC()->cart->add_to_cart( $f['v'], 2, $f['vs'], array( 'attribute_size' => 'S' ) );
$added_b = WC()->cart->add_to_cart( $f['b'], 1 );
$coupon  = WC()->cart->apply_coupon( 'hphten' );
WC()->cart->calculate_totals();
wc_clear_notices();

// Products, fees and discounts only: shipping depends on the test site's zones (T4 adds some).
$total = (float) WC()->cart->get_total( 'edit' ) - (float) WC()->cart->get_shipping_total() - (float) WC()->cart->get_shipping_tax();
$fees  = (float) array_sum( array_map( fn( $fee ) => (float) $fee->amount, WC()->cart->get_fees() ) );
$q     = new WP_Query( array( 'post_type' => 'product', 'posts_per_page' => -1, 'fields' => 'ids', 'post__in' => array( $f['a'], $f['b'], $f['c'], $f['v'] ) ) );
$found = array_map( 'intval', $q->posts );
sort( $found );

$items = array();
foreach ( WC()->cart->get_cart() as $item ) {
	$items[ $item['data']->get_id() ] = array( 'qty' => $item['quantity'], 'price' => (float) $item['data']->get_price() );
}

if ( 'dropshipper' === $mode ) {
	echo "== dropshipper ==\n";
	check( 'is dropshipper', hph_is_dropshipper(), true );
	check( 'A price = dropshipper price', $a->get_price(), '30' );
	check( 'A regular = dropshipper price', $a->get_regular_price(), '30' );
	check( 'A no sale price', $a->get_sale_price(), '' );
	check( 'A not on sale', $a->is_on_sale(), false );
	$html = $a->get_price_html();
	check( 'A shows wholesale price large', str_contains( $html, 'hph-price__ws' ) && str_contains( $html, '30.00' ), true );
	check( 'A shows "Retail" price struck through', str_contains( $html, 'hph-price__retail' ) && str_contains( $html, 'Retail' ) && str_contains( $html, '50.00' ), true );
	$vhtml = $v->get_price_html();
	check( 'V shows only priced variation (12) and its retail (20)', str_contains( $vhtml, '12.00' ) && str_contains( $vhtml, '20.00' ) && ! str_contains( $vhtml, '25.00' ), true );
	check( 'B not purchasable', $b->is_purchasable(), false );
	check( 'B not visible', $b->is_visible(), false );
	check( 'B cannot be added (direct add-to-cart)', $added_b, false );
	check( 'V-S purchasable at 12', array( $vs->is_purchasable(), $vs->get_price() ), array( true, '12' ) );
	check( 'V-L (no price) not purchasable', $vl->is_purchasable(), false );
	$prices = $v->get_variation_prices( true );
	check( 'V price range only has V-S', array_values( array_map( 'floatval', $prices['price'] ) ), array( 12.0 ) );
	check( 'product query shows only open products', $found, array_values( array_filter( array( $f['a'], $f['v'] ) ) ) );
	check( 'coupon refused', $coupon, false );
	check( 'no coupons applied', WC()->cart->get_applied_coupons(), array() );
	check( 'no fees (promo engine off)', $fees, 0.0 );
	check( 'cart A at 30 (no tier price, no WDR)', $items[ $f['a'] ]['price'] ?? null, 30.0 );
	check( 'cart V-S at 12', $items[ $f['vs'] ]['price'] ?? null, 12.0 );
	check( 'cart total = 3x30 + 2x12 exactly', $total, 114.0 );
	check( 'coupons switched off', wc_coupons_enabled(), false );
	check( 'tier table hidden', apply_filters( 'tiered_pricing_table/should_render_pricing_table', true, $f['a'], null, array() ), false );
	$promo = Stherb_Promo_Settings::get();
	check( 'promo engine reads as off', array( $promo['enabled'], $promo['campaign']['enabled'] ), array( 0, 0 ) );

	$order = wc_create_order( array( 'customer_id' => get_current_user_id() ) );
	do_action( 'woocommerce_checkout_create_order', $order, array() );
	check( 'order marked with dropshipper id', (int) $order->get_meta( '_hph_dropshipper_id' ), get_current_user_id() );
	$order->delete( true );
} else {
	echo "== $mode ==\n";
	check( 'not a dropshipper', hph_is_dropshipper(), false );
	check( 'A retail price', $a->get_price(), '50' );
	check( 'B purchasable', $b->is_purchasable(), true );
	check( 'V-L purchasable', $vl->is_purchasable(), true );
	check( 'product query shows every product', $found, ( function () use ( $f ) { $x = array( $f['a'], $f['b'], $f['c'], $f['v'] ); sort( $x ); return $x; } )() );
	check( 'B in cart', isset( $items[ $f['b'] ] ), true );
	check( 'coupon applies', $coupon, true );
	check( 'promo engine fee present', $fees < 0, true );
	check( 'A tier price or WDR applied (below 50)', ( $items[ $f['a'] ]['price'] ?? 50 ) < 50, true );
	check( 'Woo Discount Rules applies (B 40 -> 36)', $items[ $f['b'] ]['price'] ?? null, 36.0 );
	check( 'Tier Pricing applies (A at 45 from 2 pcs)', $items[ $f['a'] ]['price'] ?? null, 45.0 );
	check( 'cart total below retail 3x50 + 2x20 + 40 = 230', $total < 230, true );
	echo "      (info) A cart price " . ( $items[ $f['a'] ]['price'] ?? 'n/a' ) . ", fees $fees, total $total\n";
}

WC()->cart->empty_cart();
echo "\nTOTAL: {$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
