<?php
/**
 * T4 tests for M4, data side: the end customer store, owner isolation, validation,
 * delete on request, order counts, and the My Account menu.
 * The checkout itself is tested over HTTP in http-customers.sh.
 *
 *   wp eval-file test-customers.php
 *
 * Needs the customers module on (run-all.sh does that). Never run it against a real shop.
 */

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check( string $name, $actual, $expected ) {
	if ( $actual === $expected ) { $GLOBALS['pass']++; echo "PASS  $name\n"; }
	else { $GLOBALS['fail']++; echo "FAIL  $name\n      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) . "\n"; }
}

function t4_user( string $login, bool $dropshipper ): int {
	$u  = get_user_by( 'login', $login );
	$id = $u ? $u->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.com', 'role' => 'customer' ) );
	if ( $dropshipper ) {
		( new WP_User( $id ) )->add_role( HPH_Install::ROLE );
		update_user_meta( $id, HPH_Context::STATUS_META, 'active' );
	}
	return (int) $id;
}

global $wpdb;
check( 'customers module loaded', null !== HPH_Plugin::instance()->module( 'customers' ), true );

$a = t4_user( 'hph_t4_a', true );
$b = t4_user( 'hph_t4_b', true );
$c = t4_user( 'hph_t4_customer', false );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . hph_table( 'end_customers' ) . ' WHERE owner_user_id IN (%d, %d)', $a, $b ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$good = array(
	'first_name' => 'Customer',
	'last_name'  => 'A',
	'address_1'  => '12 Jalan Ampang',
	'city'       => 'Kuala Lumpur',
	'state'      => 'KUL',
	'postcode'   => '50450',
	'country'    => 'MY',
	'phone'      => '+60 12 345 6789',
	'note'       => 'Prefers delivery after 5 pm',
	'owner_user_id' => 999, // must be ignored
);

echo "-- insert + owner isolation\n";
$id_a = HPH_Customer_Repo::insert( $a, $good );
check( 'insert returns id', is_int( $id_a ) && $id_a > 0, true );
$row = HPH_Customer_Repo::get( $id_a, $a );
check( 'owner can read', $row['first_name'] ?? null, 'Customer' );
check( 'owner_user_id comes from the caller, not the input', (int) $row['owner_user_id'], $a );
check( 'other dropshipper cannot read', HPH_Customer_Repo::get( $id_a, $b ), null );
check( 'id 0 / owner 0 read nothing', array( HPH_Customer_Repo::get( 0, $a ), HPH_Customer_Repo::get( $id_a, 0 ) ), array( null, null ) );

$res = HPH_Customer_Repo::update( $id_a, $b, array_merge( $good, array( 'first_name' => 'Hacked' ) ) );
check( 'other dropshipper cannot update', is_wp_error( $res ) ? $res->get_error_code() : $res, 'not_found' );
check( 'row unchanged after foreign update', HPH_Customer_Repo::get( $id_a, $a )['first_name'], 'Customer' );
check( 'other dropshipper cannot erase', HPH_Customer_Repo::erase( $id_a, $b ), false );

$id_b = HPH_Customer_Repo::insert( $b, array_merge( $good, array( 'first_name' => 'Bee', 'last_name' => 'Only' ) ) );
$list = HPH_Customer_Repo::for_owner( $a );
check( 'A list has only A rows', array_map( 'intval', wp_list_pluck( $list['rows'], 'owner_user_id' ) ), array( $a ) );
check( 'B list total', HPH_Customer_Repo::for_owner( $b )['total'], 1 );

echo "-- update + search\n";
check( 'owner can update', HPH_Customer_Repo::update( $id_a, $a, array_merge( $good, array( 'city' => 'Petaling Jaya', 'postcode' => '46000' ) ) ), true );
check( 'update stored', HPH_Customer_Repo::get( $id_a, $a )['city'], 'Petaling Jaya' );
$id_a2 = HPH_Customer_Repo::insert( $a, array( 'first_name' => 'Customer', 'last_name' => 'B', 'address_1' => '1 Orchard Rd', 'city' => 'Singapore', 'postcode' => '238801', 'country' => 'SG', 'phone' => '+65 8123 4567' ) );
check( 'second insert (Singapore, no state)', is_int( $id_a2 ), true );
check( 'search by phone', wp_list_pluck( HPH_Customer_Repo::for_owner( $a, '8123' )['rows'], 'last_name' ), array( 'B' ) );
check( 'search by full name', wp_list_pluck( HPH_Customer_Repo::for_owner( $a, 'Customer A' )['rows'], 'last_name' ), array( 'A' ) );
check( 'search never crosses owners', HPH_Customer_Repo::for_owner( $a, 'Bee' )['total'], 0 );
check( 'search with SQL wildcard is literal', HPH_Customer_Repo::for_owner( $a, '%' )['total'], 0 );

echo "-- validation\n";
$codes = static function ( array $over ) use ( $good ) {
	$r = HPH_Customer_Repo::insert( 1, array_merge( $good, $over ) );
	return is_wp_error( $r ) ? $r->get_error_codes() : 'saved';
};
check( 'phone required', $codes( array( 'phone' => '' ) ), array( 'phone' ) );
check( 'phone must look like a phone', $codes( array( 'phone' => 'call me' ) ), array( 'phone' ) );
check( 'first name required', $codes( array( 'first_name' => '' ) ), array( 'first_name' ) );
check( 'Malaysia needs a state', $codes( array( 'state' => '' ) ), array( 'state' ) );
check( 'state must be from the list', $codes( array( 'state' => 'XX' ) ), array( 'state' ) );
check( 'country required', $codes( array( 'country' => '' ) ), array( 'country' ) );
check( 'unknown country rejected', $codes( array( 'country' => 'ZZ' ) ), array( 'country' ) );
check( 'bad email rejected', $codes( array( 'email' => 'nope' ) ), array( 'email' ) );
check( 'Hong Kong needs no postcode', $codes( array( 'country' => 'HK', 'state' => 'HONG KONG', 'postcode' => '' ) ), 'saved' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . hph_table( 'end_customers' ) . ' WHERE owner_user_id = %d', 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$clean = HPH_Customer_Repo::clean( array( 'first_name' => '<b>Bob</b>', 'country' => 'gb', 'postcode' => 'sw1a1aa', 'extra' => 'x' ) );
check( 'clean strips tags', $clean['first_name'], 'Bob' );
check( 'clean upper-cases country and formats postcode', array( $clean['country'], $clean['postcode'] ), array( 'GB', 'SW1A 1AA' ) );
check( 'clean drops unknown keys', isset( $clean['extra'] ), false );

echo "-- order counts\n";
$mk = static function ( int $owner, int $cid, string $status ) {
	$o = wc_create_order( array( 'customer_id' => $owner ) );
	$o->update_meta_data( HPH_Checkout_Recipient::META_CUSTOMER, $cid );
	$o->set_status( $status );
	$o->save();
	return $o;
};
$o1 = $mk( $a, $id_a, 'processing' );
$o2 = $mk( $a, $id_a, 'completed' );
$mk( $a, $id_a, 'cancelled' );
$mk( $a, $id_a2, 'pending' );
$mk( $b, $id_a, 'completed' ); // someone else's order pointing at A's id: not A's
$stats = HPH_Customer_Repo::order_stats( $a );
check( 'counts processing + completed only', $stats[ $id_a ]['count'] ?? 0, 2 );
check( 'pending order not counted', isset( $stats[ $id_a2 ] ), false );
check( 'last order date set', strlen( $stats[ $id_a ]['last'] ?? '' ), 19 );

echo "-- erase (PDPA)\n";
$o1->set_shipping_first_name( 'Customer' );
$o1->set_shipping_city( 'Petaling Jaya' );
$o1->save();
check( 'owner can erase', HPH_Customer_Repo::erase( $id_a, $a ), true );
check( 'erased row no longer readable', HPH_Customer_Repo::get( $id_a, $a ), null );
$raw = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . hph_table( 'end_customers' ) . ' WHERE id = %d', $id_a ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
check( 'personal data wiped in the table', array( $raw['first_name'], $raw['phone'], $raw['address_1'], $raw['note'] ), array( '', '', '', null ) );
check( 'deleted_at set', null !== $raw['deleted_at'], true );
check( 'row kept for old orders', (int) $raw['owner_user_id'], $a );
check( 'past order keeps its shipping address', wc_get_order( $o1->get_id() )->get_shipping_city(), 'Petaling Jaya' );
check( 'erased customer gone from list', wp_list_pluck( HPH_Customer_Repo::for_owner( $a )['rows'], 'last_name' ), array( 'B' ) );
check( 'erase logged', (bool) HPH_Log::query( array( 'action' => 'end_customer_erased', 'object_id' => $id_a ) ), true );

echo "-- My Account menu\n";
$menu = array( 'dashboard' => 'Dashboard', 'orders' => 'Orders', 'customer-logout' => 'Log out' );
$acct = new HPH_Customers_Account();
add_filter( 'hph_is_storefront', '__return_true' );
wp_set_current_user( $a );
check( 'dropshipper gets My Customers after Dashboard', array_keys( $acct->menu( $menu ) ), array( 'dashboard', 'my-customers', 'orders', 'customer-logout' ) );
wp_set_current_user( $c );
check( 'customer menu unchanged', array_keys( $acct->menu( $menu ) ), array_keys( $menu ) );
wp_set_current_user( 0 );
remove_filter( 'hph_is_storefront', '__return_true' );
check( 'endpoint registered as query var', in_array( 'my-customers', WC()->query->get_query_vars(), true ), true );

echo "\nTOTAL: {$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
