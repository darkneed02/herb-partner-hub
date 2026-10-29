<?php
/**
 * T1 tests for Herb Partner Hub core (roles, tables, settings, hph_is_dropshipper, log).
 * Runs inside the Docker WordPress + WooCommerce (see tests/README.md):
 *
 *   docker compose exec -T cli wp eval-file - < test-core.php
 *
 * Creates its own users and overwrites the plugin settings, so never run it against a real shop.
 */

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check( string $name, $actual, $expected ) {
	if ( $actual === $expected ) { $GLOBALS['pass']++; echo "PASS  $name\n"; }
	else { $GLOBALS['fail']++; echo "FAIL  $name\n      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) . "\n"; }
}

function hph_test_user( string $login, array $roles ): int {
	$user = get_user_by( 'login', $login );
	$id   = $user ? $user->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => "$login@example.com", 'role' => $roles[0] ) );
	$user = new WP_User( $id );
	$user->set_role( $roles[0] );
	foreach ( array_slice( $roles, 1 ) as $role ) {
		$user->add_role( $role );
	}
	clean_user_cache( $id );
	return (int) $id;
}

// Switch state the plugin booted with, before this file changes any settings.
$booted_with_pricing = HPH_Settings::module_enabled( 'pricing' );

// WP-CLI is not a storefront request; pretend it is for the context tests.
$storefront = true;
add_filter( 'hph_is_storefront', function () use ( &$storefront ) { return $storefront; } );

echo "== install ==\n";
HPH_Install::install(); // second run must be harmless
check( 'all 3 tables exist', HPH_Install::missing_tables(), array() );
check( 'db version stored', get_option( 'hph_db_version' ), HPH_DB_VERSION );
check( 'role exists', null !== get_role( 'herb_dropshipper' ), true );
check( 'role has hph_dropshipper', get_role( 'herb_dropshipper' )->has_cap( 'hph_dropshipper' ), true );
check( 'role cannot manage', get_role( 'herb_dropshipper' )->has_cap( 'hph_manage' ), false );
foreach ( array( 'hph_manage', 'hph_view_reports' ) as $cap ) {
	check( "administrator has $cap", get_role( 'administrator' )->has_cap( $cap ), true );
}
check( 'shop_manager can view reports', get_role( 'shop_manager' )->has_cap( 'hph_view_reports' ), true );
check( 'shop_manager cannot manage', get_role( 'shop_manager' )->has_cap( 'hph_manage' ), false );

global $wpdb;
$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . hph_table( 'end_customers' ) );
check( 'end_customers has owner + soft delete', in_array( 'owner_user_id', $cols, true ) && in_array( 'deleted_at', $cols, true ), true );

echo "== settings ==\n";
delete_option( 'hph_settings' );
HPH_Install::install();
$s = HPH_Settings::all();
check( 'fresh install: every module off', array_sum( $s['modules'] ), 0 );
check( 'fresh install: soft launch on', $s['soft_launch'], 1 );
check( 'fresh install: soft launch list empty', $s['soft_launch_users'], array() );
check( 'fresh install: no commission settings', array_intersect( array( 'default_rate', 'hold_days', 'payout_min' ), array_keys( $s ) ), array() );
check( 'fresh install: no commission module', in_array( 'commission', HPH_Settings::modules(), true ), false );
check( 'fresh install: uninstall keeps data', $s['delete_data_on_uninstall'], 0 );

$clean = HPH_Settings::sanitize( array( 'terms_page_id' => '-4', 'default_rate' => '5', 'soft_launch_users' => '12, 12 abc,7', 'modules' => array( 'pricing' => '1', 'bogus' => '1' ), 'terms_version' => '<b>2026-11</b>' ) );
check( 'sanitize: page id never negative', $clean['terms_page_id'], 4 );
check( 'sanitize: unknown keys dropped', isset( $clean['default_rate'] ), false );
check( 'sanitize: user list parsed, deduped', $clean['soft_launch_users'], array( 12, 7 ) );
check( 'sanitize: unknown module dropped', isset( $clean['modules']['bogus'] ), false );
check( 'sanitize: pricing on', $clean['modules']['pricing'], 1 );
check( 'sanitize: missing checkbox = off', $clean['soft_launch'], 0 );
check( 'sanitize: tags stripped', $clean['terms_version'], '2026-11' );

HPH_Settings::update( array( 'soft_launch_users' => '3, 3, 8' ) );
check( 'update keeps other keys', HPH_Settings::get( 'soft_launch' ), 1 );
check( 'update applies sanitize', HPH_Settings::get( 'soft_launch_users' ), array( 3, 8 ) );

echo "== hph_is_dropshipper ==\n";
$plain   = hph_test_user( 'hph_t_customer', array( 'customer' ) );
$partner = hph_test_user( 'hph_t_partner', array( 'customer', 'herb_dropshipper' ) );
$admin   = hph_test_user( 'hph_t_admin', array( 'administrator', 'herb_dropshipper' ) );
delete_user_meta( $partner, '_hph_status' );
update_user_meta( $admin, '_hph_status', 'active' );
delete_user_meta( $admin, '_hph_view_as' );
HPH_Settings::update( array( 'soft_launch' => 0, 'soft_launch_users' => array() ) );

check( 'logged out: no', hph_is_dropshipper( 0 ), false );
check( 'plain customer: no', hph_is_dropshipper( $plain ), false );
check( 'role but no status: no', hph_is_dropshipper( $partner ), false );
update_user_meta( $partner, '_hph_status', 'suspended' );
check( 'suspended: no', hph_is_dropshipper( $partner ), false );
update_user_meta( $partner, '_hph_status', 'active' );
check( 'role + active: yes', hph_is_dropshipper( $partner ), true );
check( 'admin with role + active: still no', hph_is_dropshipper( $admin ), false );

HPH_Settings::update( array( 'soft_launch' => 1, 'soft_launch_users' => array() ) );
check( 'soft launch, not listed: no', hph_is_dropshipper( $partner ), false );
HPH_Settings::update( array( 'soft_launch_users' => array( $partner ) ) );
check( 'soft launch, listed: yes', hph_is_dropshipper( $partner ), true );

$storefront = false;
check( 'not a storefront request: no', hph_is_dropshipper( $partner ), false );
$storefront = true;

wp_set_current_user( $partner );
check( 'current user default arg', hph_is_dropshipper(), true );

wp_set_current_user( $admin );
check( 'admin, view-as off: no', hph_is_dropshipper(), false );
update_user_meta( $admin, '_hph_view_as', '1' );
check( 'admin, view-as on: yes for self', hph_is_dropshipper(), true );
check( 'admin view-as does not leak to another admin id', hph_is_dropshipper( $plain ), false );
wp_set_current_user( $plain );
check( 'view-as only applies when that admin is the current user', hph_is_dropshipper( $admin ), false );
update_user_meta( $plain, '_hph_view_as', '1' );
wp_set_current_user( $plain );
check( 'view-as meta on a non-admin is ignored', hph_is_dropshipper(), false );
delete_user_meta( $plain, '_hph_view_as' );
delete_user_meta( $admin, '_hph_view_as' );
wp_set_current_user( 0 );

check( 'is_partner ignores soft launch', HPH_Context::is_partner( $partner ), true );

echo "== log ==\n";
wp_set_current_user( $admin );
$id  = HPH_Log::add( 'test_action', 'user', $partner, array( 'rate' => 3 ), array( 'rate' => 5 ) );
$row = HPH_Log::query( array( 'action' => 'test_action', 'object_id' => $partner, 'limit' => 1 ) )[0] ?? array();
check( 'log row written', $id > 0, true );
check( 'log actor', (int) ( $row['actor_id'] ?? 0 ), $admin );
check( 'log after json', json_decode( $row['after_json'] ?? '', true ), array( 'rate' => 5 ) );
$before_count = count( HPH_Log::query( array( 'action' => 'settings_updated', 'limit' => 500 ) ) );
HPH_Settings::update( array( 'terms_version' => 'v-test' ) );
check( 'settings change is logged', count( HPH_Log::query( array( 'action' => 'settings_updated', 'limit' => 500 ) ) ), $before_count + 1 );
wp_set_current_user( 0 );

echo "== modules ==\n";
check( 'module object exists only when its switch was on at boot', null !== HPH_Plugin::instance()->module( 'pricing' ), $booted_with_pricing );

// Leave the site as a fresh install would be.
delete_option( 'hph_settings' );
HPH_Install::install();

echo "\nTOTAL: {$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
