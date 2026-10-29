<?php

defined( 'ABSPATH' ) || exit;

/**
 * Who did what, to what, and when. Approvals, prices and permissions must be traceable
 * when a dropshipper asks why their price or access changed.
 */
final class HPH_Log {
	public static function add( string $action, string $object_type = '', int $object_id = 0, $before = null, $after = null ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			hph_table( 'log' ),
			array(
				'actor_id'    => get_current_user_id(),
				'action'      => substr( $action, 0, 50 ),
				'object_type' => substr( $object_type, 0, 30 ),
				'object_id'   => max( 0, $object_id ),
				'before_json' => null === $before ? null : wp_json_encode( $before ),
				'after_json'  => null === $after ? null : wp_json_encode( $after ),
				'ip_hash'     => self::ip_hash(),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Newest first. Filters: actor_id, action, object_type, object_id. Paging: limit, offset.
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'actor_id' => '%d', 'action' => '%s', 'object_type' => '%s', 'object_id' => '%d' ) as $field => $format ) {
			if ( isset( $args[ $field ] ) && '' !== $args[ $field ] ) {
				$where[]  = "$field = $format";
				$params[] = $args[ $field ];
			}
		}
		$params[] = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$params[] = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$table = hph_table( 'log' );
		$sql   = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
	}

	/**
	 * Keyed hash so the raw IP is never stored but repeat visitors can still be matched.
	 */
	public static function ip_hash(): string {
		$ip = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return '' === $ip ? '' : md5( wp_salt( 'auth' ) . $ip );
	}
}
