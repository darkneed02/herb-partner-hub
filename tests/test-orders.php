<?php
/**
 * T5 tests, data side: the shared sales definition (HPH_Sales) behind the partner
 * dashboard, the order history and the admin Overview. The pages themselves are tested
 * over HTTP in http-orders.sh, which reuses the orders made here.
 *
 *   wp eval-file test-orders.php
 *
 * Needs the customers, orders and reports modules on (run-all.sh does that) and the
 * fixture product from t4-fixtures.php. Never run it against a real shop.
 */

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check( string $name, $actual, $expected ) {
	if ( $actual === $expected ) { $GLOBALS['pass']++; echo "PASS  $name\n"; }
	else { $GLOBALS['fail']++; echo "FAIL  $name\n      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) . "\n"; }
}

function t5_user( string $login, bool $dropshipper ): int {
	$u  = get_user_by( 'login', $login );
	$id = $u ? $u->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'pw-' . $login . '-123', 'user_email' => $login . '@example.com', 'role' => 'customer' ) );
	wp_set_password( 'pw-' . $login . '-123', $id );
	if ( $dropshipper ) {
		( new WP_User( $id ) )->add_role( HPH_Install::ROLE );
		update_user_meta( $id, HPH_Context::STATUS_META, 'active' );
	}
	foreach ( array( 'first_name' => 'Mali', 'last_name' => 'Srisuk', 'company' => $dropshipper ? ucfirst( $login ) . ' Shop' : '', 'address_1' => '99 Sukhumvit Rd', 'city' => 'Bangkok', 'postcode' => '10110', 'country' => 'TH', 'state' => 'TH-10', 'phone' => '+66810000000', 'email' => $login . '@example.com' ) as $k => $v ) {
		update_user_meta( $id, 'billing_' . $k, $v );
		delete_user_meta( $id, 'shipping_' . $k );
	}
	return (int) $id;
}

/**
 * $when is local site time 'Y-m-d H:i'. $ship: 'self', 'legacy' (no meta) or an end customer row.
 */
function t5_order( int $user, float $total, string $status, string $when, $ship = 'self', bool $as_dropshipper = true ): WC_Order {
	$product = get_page_by_path( 'hph-fixture-product', OBJECT, 'product' );
	$order   = wc_create_order( array( 'customer_id' => $user ) );
	$order->add_product( wc_get_product( $product->ID ), 1, array( 'subtotal' => $total, 'total' => $total ) );
	$order->set_billing_first_name( 'Mali' );
	$order->set_billing_last_name( 'Srisuk' );
	$order->set_billing_address_1( '99 Sukhumvit Rd' );
	$order->set_billing_country( 'TH' );
	if ( is_array( $ship ) ) {
		foreach ( HPH_Customer_Repo::ADDRESS_FIELDS as $f ) {
			$order->{'set_shipping_' . $f}( (string) $ship[ $f ] );
		}
		$order->update_meta_data( HPH_Sales::META_SHIP_TO, 'customer' );
		if ( ! empty( $ship['id'] ) ) {
			$order->update_meta_data( HPH_Sales::META_CUSTOMER, (int) $ship['id'] );
		}
	} else {
		$order->set_shipping_first_name( 'Mali' );
		$order->set_shipping_last_name( 'Srisuk' );
		$order->set_shipping_address_1( '99 Sukhumvit Rd' );
		$order->set_shipping_country( 'TH' );
		if ( 'self' === $ship ) {
			$order->update_meta_data( HPH_Sales::META_SHIP_TO, 'self' );
		}
	}
	if ( $as_dropshipper ) {
		$order->update_meta_data( HPH_Sales::META_DROPSHIPPER, $user );
	}
	$order->set_date_created( ( new DateTimeImmutable( $when, wp_timezone() ) )->getTimestamp() );
	$order->calculate_totals( false );
	$order->set_status( $status );
	$order->save();
	return $order;
}

check( 'orders module loaded', null !== HPH_Plugin::instance()->module( 'orders' ), true );
check( 'reports module loaded', null !== HPH_Plugin::instance()->module( 'reports' ), true );

// Bangkok is UTC+7: 1 Oct 00:30 local is still 30 Sep in UTC, the case that breaks UTC months.
update_option( 'timezone_string', 'Asia/Bangkok' );

$p = t5_user( 'hph_t5_p', true );
$q = t5_user( 'hph_t5_q', true );
$c = t5_user( 'hph_t5_c', false );

global $wpdb;
foreach ( array( $p, $q, $c ) as $uid ) {
	foreach ( wc_get_orders( array( 'customer_id' => $uid, 'limit' => -1, 'status' => array_keys( wc_get_order_statuses() ) ) ) as $old ) {
		$old->delete( true );
	}
	$wpdb->delete( hph_table( 'end_customers' ), array( 'owner_user_id' => $uid ) );
}

$cust = array( 'first_name' => 'Customer', 'last_name' => 'B', 'company' => '', 'address_1' => '1 Orchard Rd', 'address_2' => '', 'city' => 'Singapore', 'state' => '', 'postcode' => '238801', 'country' => 'SG', 'phone' => '+65 8000 0000' );
$cid  = HPH_Customer_Repo::insert( $p, $cust );
$cust = HPH_Customer_Repo::get( $cid, $p );
$evil = HPH_Customer_Repo::insert( $p, array_merge( $cust, array( 'first_name' => '=HYPERLINK("http://x")', 'last_name' => 'Evil', 'phone' => '+65 8000 0001' ) ) );
$evil = HPH_Customer_Repo::get( $evil, $p );
$qc   = HPH_Customer_Repo::insert( $q, array_merge( $cust, array( 'first_name' => 'Q-only' ) ) );

$now  = HPH_Sales::current_month();
$prev = HPH_Sales::months( 2 )[0];
list( $y, $m ) = array_map( 'intval', explode( '-', $now ) );
$prev_last = ( new DateTimeImmutable( $now . '-01 00:00', wp_timezone() ) )->modify( '-1 day' )->format( 'Y-m-d' );

echo "-- fixtures\n";
$o = array();
$o['proc']      = t5_order( $p, 100, 'processing', "$now-01 00:30", $cust );        // first minute of the month, local
$o['done']      = t5_order( $p, 50, 'completed', "$now-02 10:00", 'self' );
$o['refunded']  = t5_order( $p, 40, 'refunded', "$now-03 10:00", 'self' );
wc_create_refund( array( 'order_id' => $o['refunded']->get_id(), 'amount' => 40 ) );
$o['partial']   = t5_order( $p, 80, 'processing', "$now-03 11:00", $evil );
wc_create_refund( array( 'order_id' => $o['partial']->get_id(), 'amount' => 30 ) );
$o['cancelled'] = t5_order( $p, 999, 'cancelled', "$now-04 10:00", 'self' );
$o['retail']    = t5_order( $p, 70, 'processing', "$now-04 11:00", 'self', false ); // before approval
$o['late']      = t5_order( $p, 20, 'processing', "$prev_last 23:30", 'self' );    // last evening of last month
$o['hold']      = t5_order( $p, 25, 'on-hold', "$prev-10 10:00", 'legacy' );
$o['q']         = t5_order( $q, 300, 'processing', "$now-05 10:00", 'self' );
$o['partial']->add_order_note( 'This order has been shipped with: DHL Express. The Tracking Number is: 1234567890. The Url is: https://www.dhl.com/', 1 );
$o['partial']->add_order_note( 'This order has been shipped with: DHL Express. The Tracking Number is: 5550001111. The Url is: https://www.dhl.com/', 1 );
check( 'fixtures created', count( array_filter( $o ) ), 9 );

echo "-- one sales definition\n";
check( 'month bounds in site time: 1st 00:30 local belongs to this month', HPH_Sales::month_bounds( $now )[0] <= $o['proc']->get_date_created()->getTimestamp(), true );
check( 'last evening of last month is not this month', HPH_Sales::month_bounds( $now )[0] > $o['late']->get_date_created()->getTimestamp(), true );

$mine = HPH_Sales::month( $p, $now );
// proc 100 + done 50 + partial (80-30) = 3 orders, 200. Refunded adds 0 and no count; cancelled and the retail order are out.
check( 'this month: order count', $mine['orders'], 3 );
check( 'this month: total (incl. shipping, after refunds)', $mine['total'], 200.0 );
check( 'last month: late-evening + on-hold', HPH_Sales::month( $p, $prev ), array( 'orders' => 2, 'total' => 45.0 ) );

$by = HPH_Sales::by_month( $p, 6 );
check( 'by_month has 6 months, oldest first, ending now', array( count( $by ), array_key_last( $by ) ), array( 6, $now ) );
check( 'by_month matches month() for this and last month', array( $by[ $now ], $by[ $prev ] ), array( $mine, HPH_Sales::month( $p, $prev ) ) );

// Other test files leave dropshipper orders behind, so compare the totals with each other.
$all  = HPH_Sales::month( 0, $now );
$rank = HPH_Sales::by_dropshipper( $now );
check( 'all dropshippers = sum of the ranking', array( $all['orders'], round( $all['total'], 2 ) ), array( array_sum( array_column( $rank, 'orders' ) ), round( array_sum( array_column( $rank, 'total' ) ), 2 ) ) );
check( 'ranking per dropshipper', array( $rank[ $p ] ?? null, $rank[ $q ] ?? null ), array( $mine, array( 'orders' => 1, 'total' => 300.0 ) ) );
check( 'Q ranks above P', array_search( $q, array_keys( $rank ), true ) < array_search( $p, array_keys( $rank ), true ), true );
list( $from, $to ) = HPH_Sales::month_bounds( $now );
$in_all = array_map( static fn( $x ) => $x->get_id(), HPH_Sales::orders( 0, $from, $to ) );
check( 'order placed before approval is not a dropshipper sale', in_array( $o['retail']->get_id(), $in_all, true ), false );
check( 'cancelled order is not a dropshipper sale', in_array( $o['cancelled']->get_id(), $in_all, true ), false );

echo "-- ship to (from the order, not the saved customer)\n";
check( 'saved customer', HPH_Sales::ship_to( $o['proc'] ), array( 'self' => false, 'name' => 'Customer B', 'country' => 'SG' ) );
check( 'self', HPH_Sales::ship_to( $o['done'] )['name'], 'Myself' );
check( 'order from before the box existed, same address as billing', HPH_Sales::ship_to( $o['hold'] )['self'], true );
HPH_Customer_Repo::erase( $cid, $p );
check( 'erasing the customer keeps the name on the old order', HPH_Sales::ship_to( wc_get_order( $o['proc']->get_id() ) )['name'], 'Customer B' );
$cid  = HPH_Customer_Repo::insert( $p, $cust );          // fresh copy for the HTTP reorder test
$cust = HPH_Customer_Repo::get( $cid, $p );
$o['proc']->update_meta_data( HPH_Sales::META_CUSTOMER, $cid );
$o['proc']->save();

echo "-- tracking\n";
$t = HPH_Sales::tracking( array( $o['partial']->get_id(), $o['done']->get_id() ) );
check( 'latest tracking note wins', $t[ $o['partial']->get_id() ] ?? '', '5550001111' );
check( 'no note, no tracking', isset( $t[ $o['done']->get_id() ] ), false );

echo "-- CSV safety\n";
check( 'formula cell neutralised', HPH_Sales::csv_cell( '=HYPERLINK("http://x")' ), "'=HYPERLINK(\"http://x\")" );
check( 'plus / at / minus neutralised', array_map( array( 'HPH_Sales', 'csv_cell' ), array( '+1', '@a', '-2' ) ), array( "'+1", "'@a", "'-2" ) );
check( 'plain value untouched', HPH_Sales::csv_cell( '150.00' ), '150.00' );

echo "-- history filters (always scoped to the logged-in dropshipper)\n";
$ids = static fn( array $args ) => array_map( 'intval', wc_get_orders( $args + array( 'limit' => -1, 'return' => 'ids' ) ) );
$f   = array( 'month' => '', 'customer' => '', 'status' => '', 'pg' => 1 );
check( 'all of P\'s orders, including the one placed before approval', count( $ids( HPH_Orders::query_args( $p, $f ) ) ), 8 );
check( 'month filter', count( $ids( HPH_Orders::query_args( $p, array( 'month' => $prev ) + $f ) ) ), 2 );
check( 'customer filter', $ids( HPH_Orders::query_args( $p, array( 'customer' => (string) $cid ) + $f ) ), array( $o['proc']->get_id() ) );
check( 'myself filter', count( $ids( HPH_Orders::query_args( $p, array( 'customer' => 'self' ) + $f ) ) ), 5 );
check( 'status filter', $ids( HPH_Orders::query_args( $p, array( 'status' => 'cancelled' ) + $f ) ), array( $o['cancelled']->get_id() ) );
check( 'Q\'s customer id gives P nothing', $ids( HPH_Orders::query_args( $p, array( 'customer' => (string) $qc ) + $f ) ), array() );

echo "-- reorder statuses\n";
add_filter( 'hph_is_storefront', '__return_true' ); // WP-CLI is not the storefront.
wp_set_current_user( $p );
check( 'dropshipper: processing and on-hold can be reordered', array_values( array_intersect( array( 'completed', 'processing', 'on-hold' ), apply_filters( 'woocommerce_valid_order_statuses_for_order_again', array( 'completed' ) ) ) ), array( 'completed', 'processing', 'on-hold' ) );
check( 'reorder link on a processing order', '' !== HPH_Orders::reorder_url( $o['proc'] ), true );
check( 'no reorder link on a cancelled order', HPH_Orders::reorder_url( $o['cancelled'] ), '' );
$before = did_action( 'woocommerce_before_account_orders' );
$after  = did_action( 'woocommerce_after_account_orders' );
ob_start();
HPH_Plugin::instance()->module( 'orders' )->render_orders();
$html = ob_get_clean();
check( 'history fires WooCommerce\'s before/after orders hooks', array( did_action( 'woocommerce_before_account_orders' ) - $before, did_action( 'woocommerce_after_account_orders' ) - $after ), array( 1, 1 ) );
check( 'history rendered', str_contains( $html, 'hph-orders' ), true );
$dash = did_action( 'woocommerce_account_dashboard' );
ob_start();
wc_get_template( 'myaccount/dashboard.php', array( 'current_user' => get_user_by( 'id', $p ) ) );
$html = ob_get_clean();
check( 'partner dashboard replaces WooCommerce\'s and still fires woocommerce_account_dashboard', array( str_contains( $html, 'hph-dash' ), did_action( 'woocommerce_account_dashboard' ) - $dash ), array( true, 1 ) );
wp_set_current_user( $c );
check( 'normal customer: WooCommerce default (completed only)', apply_filters( 'woocommerce_valid_order_statuses_for_order_again', array( 'completed' ) ), array( 'completed' ) );
wp_set_current_user( 0 );
remove_filter( 'hph_is_storefront', '__return_true' );

echo "-- admin overview data\n";
$r = array_column( HPH_Reports::ranking( $now ), null, 'id' );
check( 'ranking row for P', array( $r[ $p ]['orders'], $r[ $p ]['total'], $r[ $p ]['customers'] ), array( 3, 200.0, 2 ) );
check( 'active dropshippers include P and Q', count( array_intersect( array( $p, $q ), HPH_Reports::active_dropshippers() ) ), 2 );

// Hand the order ids to http-orders.sh.
update_option( 'hph_t5_fixture', array_map( static fn( $x ) => $x->get_id(), $o ) + array( 'cid' => $cid, 'qc' => $qc ), false );

$total = $GLOBALS['pass'] + $GLOBALS['fail'];
echo "TOTAL $total  passed {$GLOBALS['pass']}  failed {$GLOBALS['fail']}\n";
