<?php

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the hph_applications table. Extra form answers live in fields_json
 * and come back decoded as $row['fields'].
 */
final class HPH_Application_Repo {
	public const STATUSES = array( 'new', 'needs_info', 'approved', 'rejected' );
	public const OPEN     = array( 'new', 'needs_info' );

	public static function status_labels(): array {
		return array(
			'new'        => __( 'Waiting', 'herb-partner-hub' ),
			'needs_info' => __( 'Need info', 'herb-partner-hub' ),
			'approved'   => __( 'Approved', 'herb-partner-hub' ),
			'rejected'   => __( 'Rejected', 'herb-partner-hub' ),
		);
	}

	/**
	 * Tag colour per status for admin lists (assets/css/admin.css).
	 */
	public static function status_tag( string $status ): string {
		$colour = array(
			'new'        => 'y',
			'needs_info' => 'b',
			'approved'   => 'g',
			'rejected'   => 'r',
		)[ $status ] ?? '';
		$label  = self::status_labels()[ $status ] ?? $status;
		return '<span class="hph-tag' . ( $colour ? ' hph-tag--' . $colour : '' ) . '">' . esc_html( $label ) . '</span>';
	}

	public static function insert( array $data ): int {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$row  = array_merge(
			array(
				'created_at' => $now,
				'updated_at' => $now,
				'status'     => 'new',
			),
			self::prepare( $data )
		);

		return $wpdb->insert( hph_table( 'applications' ), $row ) ? (int) $wpdb->insert_id : 0;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;

		$row               = self::prepare( $data );
		$row['updated_at'] = current_time( 'mysql', true );

		return false !== $wpdb->update( hph_table( 'applications' ), $row, array( 'id' => $id ) );
	}

	public static function get( int $id ): ?array {
		global $wpdb;

		$table = hph_table( 'applications' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	public static function find_open_by_email( string $email ): ?array {
		global $wpdb;

		$table = hph_table( 'applications' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE email = %s AND status IN ('new','needs_info') ORDER BY id DESC LIMIT 1", $email ),
			ARRAY_A
		);

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Args: status, search, per_page, page. Returns array( 'items' => rows, 'total' => int ).
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$table  = hph_table( 'applications' );
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(email LIKE %s OR name LIKE %s OR business_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset   = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );
		$sql_where = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM $table WHERE $sql_where";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE $sql_where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ),
			ARRAY_A
		) ?: array();

		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	public static function counts(): array {
		global $wpdb;

		$table  = hph_table( 'applications' );
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $table GROUP BY status", ARRAY_A ) ?: array() as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['n'];
			}
		}
		return $counts;
	}

	private static function prepare( array $data ): array {
		if ( array_key_exists( 'fields', $data ) ) {
			$data['fields_json'] = wp_json_encode( $data['fields'] );
			unset( $data['fields'] );
		}
		unset( $data['id'] );
		return $data;
	}

	private static function hydrate( array $row ): array {
		$row['id']     = (int) $row['id'];
		$row['fields'] = json_decode( (string) $row['fields_json'], true ) ?: array();
		unset( $row['fields_json'] );
		return $row;
	}
}
