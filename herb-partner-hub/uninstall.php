<?php
/**
 * Runs when the plugin is deleted from Plugins (not on deactivate).
 * Deletes data only if "Also delete all dropshipper data" was ticked in Settings.
 * Order meta (_hph_dropshipper_id etc.) is always kept: orders are tax and shipping records.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$hph_settings = get_option( 'hph_settings', array() );
if ( empty( $hph_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

foreach ( array( 'applications', 'end_customers', 'log' ) as $hph_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hph_{$hph_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

foreach ( array( '_hph_status', '_hph_view_as', '_hph_terms_version', '_hph_terms_at' ) as $hph_key ) {
	delete_metadata( 'user', 0, $hph_key, '', true );
}

foreach ( array( '_hph_enabled', '_hph_price' ) as $hph_key ) {
	delete_post_meta_by_key( $hph_key );
}

remove_role( 'herb_dropshipper' );
foreach ( array( 'administrator', 'shop_manager' ) as $hph_role_name ) {
	$hph_role = get_role( $hph_role_name );
	if ( $hph_role ) {
		foreach ( array( 'hph_manage', 'hph_view_reports' ) as $hph_cap ) {
			$hph_role->remove_cap( $hph_cap );
		}
	}
}

delete_option( 'hph_settings' );
delete_option( 'hph_db_version' );
