<?php

defined( 'ABSPATH' ) || exit;

/**
 * Rows of {prefix}hph_end_customers. Every read and write takes the owner's user id and
 * filters on it in SQL, so one dropshipper can never load or change another's customer,
 * whatever id they send.
 *
 * Field names match WooCommerce's shipping_* keys without the prefix, so a row can be
 * copied straight onto an order's shipping address.
 */
final class HPH_Customer_Repo {
	public const ADDRESS_FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );
	public const FIELDS         = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email', 'note' );
	public const PER_PAGE       = 20;

	/** Statuses that count as an order the customer received (or was refunded). */
	public const COUNTED_STATUSES = array( 'processing', 'completed', 'on-hold', 'refunded' );

	public static function get( int $id, int $owner_id ): ?array {
		global $wpdb;
		if ( $id <= 0 || $owner_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . hph_table( 'end_customers' ) . ' WHERE id = %d AND owner_user_id = %d AND deleted_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from hph_table().
				$id,
				$owner_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * @return array{rows: array[], total: int}
	 */
	public static function for_owner( int $owner_id, string $search = '', int $page = 1, int $per_page = self::PER_PAGE ): array {
		global $wpdb;
		$table = hph_table( 'end_customers' );
		$where = $wpdb->prepare( 'owner_user_id = %d AND deleted_at IS NULL', $owner_id );

		$search = trim( $search );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare(
				" AND ( CONCAT_WS( ' ', first_name, last_name ) LIKE %s OR company LIKE %s OR phone LIKE %s OR email LIKE %s OR city LIKE %s )",
				$like,
				$like,
				$like,
				$like,
				$like
			);
		}

		$offset = max( 0, ( max( 1, $page ) - 1 ) * $per_page );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built with prepare() above.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY first_name, last_name, id LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Every customer of one owner, for the checkout picker. Most recently used first.
	 */
	public static function all_for_owner( int $owner_id ): array {
		$rows  = self::for_owner( $owner_id, '', 1, 500 )['rows'];
		$stats = self::order_stats( $owner_id );
		usort(
			$rows,
			static function ( $a, $b ) use ( $stats ) {
				$la = $stats[ (int) $a['id'] ]['last'] ?? '';
				$lb = $stats[ (int) $b['id'] ]['last'] ?? '';
				return strcmp( $lb, $la ) ?: strcasecmp( self::name( $a ), self::name( $b ) );
			}
		);
		return $rows;
	}

	/**
	 * @return int|WP_Error new id
	 */
	public static function insert( int $owner_id, array $input ) {
		global $wpdb;
		$data = self::clean( $input );
		$err  = self::validate( $data );
		if ( $err->has_errors() ) {
			return $err;
		}
		$now                   = current_time( 'mysql', true );
		$data['owner_user_id'] = $owner_id;
		$data['created_at']    = $now;
		$data['updated_at']    = $now;
		$wpdb->insert( hph_table( 'end_customers' ), $data );
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			HPH_Log::add( 'end_customer_added', 'end_customer', $id );
		}
		return $id ?: new WP_Error( 'db', __( 'The customer could not be saved. Please try again.', 'herb-partner-hub' ) );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function update( int $id, int $owner_id, array $input ) {
		global $wpdb;
		if ( ! self::get( $id, $owner_id ) ) {
			return new WP_Error( 'not_found', __( 'Customer not found.', 'herb-partner-hub' ) );
		}
		$data = self::clean( $input );
		$err  = self::validate( $data );
		if ( $err->has_errors() ) {
			return $err;
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update(
			hph_table( 'end_customers' ),
			$data,
			array(
				'id'            => $id,
				'owner_user_id' => $owner_id,
			)
		);
		HPH_Log::add( 'end_customer_updated', 'end_customer', $id );
		return true;
	}

	/**
	 * Delete on request (PDPA/GDPR): the personal details in our table are wiped, the row
	 * stays as an empty marker so old orders' _hph_end_customer_id still points somewhere.
	 * The address on past orders is not touched — it is a tax and DHL record.
	 */
	public static function erase( int $id, int $owner_id ): bool {
		global $wpdb;
		if ( ! self::get( $id, $owner_id ) ) {
			return false;
		}
		$blank               = array_fill_keys( self::FIELDS, '' );
		$blank['note']       = null;
		$blank['updated_at'] = current_time( 'mysql', true );
		$blank['deleted_at'] = $blank['updated_at'];
		$wpdb->update(
			hph_table( 'end_customers' ),
			$blank,
			array(
				'id'            => $id,
				'owner_user_id' => $owner_id,
			)
		);
		HPH_Log::add( 'end_customer_erased', 'end_customer', $id );
		return true;
	}

	/**
	 * Trimmed, length-capped values for every field; unknown keys are dropped.
	 */
	public static function clean( array $input ): array {
		$out = array();
		foreach ( self::FIELDS as $f ) {
			$v = is_scalar( $input[ $f ] ?? null ) ? (string) $input[ $f ] : '';
			if ( 'note' === $f ) {
				$out[ $f ] = mb_substr( sanitize_textarea_field( $v ), 0, 1000 );
			} elseif ( 'email' === $f ) {
				// An invalid address is kept as typed so validate() can say so, not silently dropped.
				$out[ $f ] = is_email( sanitize_email( $v ) ) ? sanitize_email( $v ) : mb_substr( sanitize_text_field( $v ), 0, 190 );
			} elseif ( 'country' === $f ) {
				$out[ $f ] = strtoupper( substr( sanitize_key( $v ), 0, 2 ) );
			} elseif ( 'phone' === $f ) {
				$out[ $f ] = wc_sanitize_phone_number( $v ) === '' ? '' : mb_substr( sanitize_text_field( $v ), 0, 50 );
			} else {
				$out[ $f ] = mb_substr( sanitize_text_field( $v ), 0, 'postcode' === $f ? 20 : 190 );
			}
		}
		if ( '' !== $out['postcode'] && '' !== $out['country'] ) {
			$out['postcode'] = wc_format_postcode( $out['postcode'], $out['country'] );
		}
		return $out;
	}

	/**
	 * Required fields follow WooCommerce's own address rules for the chosen country
	 * (e.g. Malaysia needs a state, Hong Kong has no postcode). Phone is always required:
	 * it goes into the order's shipping phone for the courier.
	 */
	public static function validate( array $data ): WP_Error {
		$err       = new WP_Error();
		$countries = WC()->countries->get_shipping_countries();

		if ( '' === $data['country'] || ! isset( $countries[ $data['country'] ] ) ) {
			$err->add( 'country', __( 'Choose a country we ship to.', 'herb-partner-hub' ) );
			return $err;
		}

		$fields = WC()->countries->get_address_fields( $data['country'], '' );
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode' ) as $f ) {
			if ( ! empty( $fields[ $f ]['required'] ) && empty( $fields[ $f ]['hidden'] ) && '' === $data[ $f ] ) {
				/* translators: %s: field label, e.g. "Town / City" */
				$err->add( $f, sprintf( __( '%s is required.', 'herb-partner-hub' ), $fields[ $f ]['label'] ?? $f ) );
			}
		}

		$states = WC()->countries->get_states( $data['country'] );
		if ( is_array( $states ) && $states && '' !== $data['state'] && ! isset( $states[ $data['state'] ] ) ) {
			$err->add( 'state', __( 'Choose a state from the list.', 'herb-partner-hub' ) );
		}

		if ( '' !== $data['postcode'] && ! WC_Validation::is_postcode( $data['postcode'], $data['country'] ) ) {
			$err->add( 'postcode', __( 'Please enter a valid postcode.', 'herb-partner-hub' ) );
		}

		if ( '' === $data['phone'] ) {
			$err->add( 'phone', __( 'Phone is required — the courier uses it to reach the customer.', 'herb-partner-hub' ) );
		} elseif ( ! WC_Validation::is_phone( $data['phone'] ) ) {
			$err->add( 'phone', __( 'Please enter a valid phone number.', 'herb-partner-hub' ) );
		}

		if ( '' !== $data['email'] && ! is_email( $data['email'] ) ) {
			$err->add( 'email', __( 'Please enter a valid email address.', 'herb-partner-hub' ) );
		}

		return $err;
	}

	public static function name( array $row ): string {
		$name = trim( $row['first_name'] . ' ' . $row['last_name'] );
		return '' !== $name ? $name : (string) $row['company'];
	}

	/**
	 * "Kuala Lumpur, Malaysia" — the short form used in lists.
	 */
	public static function place( array $row ): string {
		$countries = WC()->countries->get_countries();
		$country   = $countries[ $row['country'] ] ?? $row['country'];
		$city      = 0 === strcasecmp( (string) $row['city'], (string) $country ) ? '' : $row['city']; // "Singapore", not "Singapore, Singapore"
		return implode( ', ', array_filter( array( $city, $country ) ) );
	}

	/**
	 * One-line full address, formatted the WooCommerce way for the country.
	 */
	public static function address_line( array $row ): string {
		$parts = WC()->countries->get_formatted_address(
			array(
				'address_1' => $row['address_1'],
				'address_2' => $row['address_2'],
				'city'      => $row['city'],
				'state'     => $row['state'],
				'postcode'  => $row['postcode'],
				'country'   => $row['country'],
			),
			', '
		);
		// Drop a part that repeats the one before it ("Kuala Lumpur, Kuala Lumpur").
		$out = array();
		foreach ( explode( ', ', wp_strip_all_tags( $parts ) ) as $part ) {
			if ( ! $out || 0 !== strcasecmp( end( $out ), $part ) ) {
				$out[] = $part;
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * Order count and last order date per end customer, for one dropshipper.
	 * Uses wc_get_orders so it works with HPOS and with the posts table alike.
	 *
	 * @return array<int, array{count: int, last: string}> keyed by end customer id; last is Y-m-d H:i:s UTC
	 */
	public static function order_stats( int $owner_id ): array {
		$ids = wc_get_orders(
			array(
				'customer_id' => $owner_id,
				'status'      => self::COUNTED_STATUSES,
				'limit'       => -1,
				'return'      => 'ids',
			) + HPH_Sales::meta_args( HPH_Checkout_Recipient::META_CUSTOMER )
		);

		$stats = array();
		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$cid  = (int) $order->get_meta( HPH_Checkout_Recipient::META_CUSTOMER );
			$date = $order->get_date_created();
			$when = $date ? gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ) : '';
			if ( $cid <= 0 ) {
				continue;
			}
			$stats[ $cid ]['count'] = ( $stats[ $cid ]['count'] ?? 0 ) + 1;
			$stats[ $cid ]['last']  = max( $stats[ $cid ]['last'] ?? '', $when );
		}
		return $stats;
	}
}
