<?php
/**
 * Public helpers. Other code (theme, other plugins, later modules) should call
 * these rather than the classes, so the internals can move without breaking callers.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Should this request get dropshipper behaviour (wholesale prices, no promotions)?
 * Meant for the logged-in user on the storefront. For a past order use the
 * `_hph_dropshipper_id` order meta instead, which is snapshotted at checkout.
 */
function hph_is_dropshipper( ?int $user_id = null ): bool {
	return HPH_Context::is_dropshipper( $user_id );
}

/**
 * One setting from the `hph_settings` option, with defaults filled in.
 */
function hph_setting( string $key ) {
	return HPH_Settings::get( $key );
}

/**
 * Full table name for one of the plugin's tables, e.g. hph_table( 'end_customers' ).
 */
function hph_table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'hph_' . $name;
}
