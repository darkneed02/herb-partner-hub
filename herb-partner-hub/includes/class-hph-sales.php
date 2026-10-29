<?php

defined( 'ABSPATH' ) || exit;

/**
 * One definition of "dropshipper sales", shared by the partner dashboard, the order history
 * and the admin Overview, so the same month gives the same number everywhere.
 *
 * - A dropshipper order is one placed at dropshipper prices: it carries `_hph_dropshipper_id`
 *   (set at checkout by the pricing module). Orders the same person placed as a normal
 *   customer before approval are not counted.
 * - Counted statuses: processing, completed, on-hold. Refunded orders stay in the money total
 *   at what is left after the refund (0 for a full refund) but not in the order count.
 * - Total = what the dropshipper paid, shipping included, minus refunds.
 * - Months are calendar months in the site's time zone.
 *
 * Meta keys are repeated here as strings so reports work with the customers module off.
 */
final class HPH_Sales {
	public const META_DROPSHIPPER = '_hph_dropshipper_id';
	public const META_SHIP_TO     = '_hph_ship_to';
	public const META_CUSTOMER    = '_hph_end_customer_id';

	public const COUNTED  = array( 'processing', 'completed', 'on-hold' );
	public const IN_TOTAL = array( 'processing', 'completed', 'on-hold', 'refunded' );

	/**
	 * Start and end (inclusive) of a 'Y-m' month in the site's time zone, as Unix timestamps.
	 *
	 * @return array{0: int, 1: int}
	 */
	public static function month_bounds( string $ym ): array {
		$start = DateTimeImmutable::createFromFormat( '!Y-m', $ym, wp_timezone() );
		if ( ! $start ) {
			$start = new DateTimeImmutable( 'first day of this month midnight', wp_timezone() );
		}
		return array( $start->getTimestamp(), $start->modify( '+1 month' )->getTimestamp() - 1 );
	}

	public static function current_month(): string {
		return wp_date( 'Y-m' );
	}

	/**
	 * 'Y-m' keys from oldest to newest, ending with $last.
	 */
	public static function months( int $count, string $last = '' ): array {
		$last = self::valid_month( $last ) ? $last : self::current_month();
		$end  = DateTimeImmutable::createFromFormat( '!Y-m', $last, wp_timezone() );
		$out  = array();
		for ( $i = $count - 1; $i >= 0; $i-- ) {
			$out[] = $end->modify( "-{$i} month" )->format( 'Y-m' );
		}
		return $out;
	}

	public static function valid_month( string $ym ): bool {
		return (bool) preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $ym );
	}

	public static function month_label( string $ym, string $format = 'F Y' ): string {
		return wp_date( $format, self::month_bounds( $ym )[0] );
	}

	/**
	 * Dropshipper orders in a time range.
	 *
	 * @param int $dropshipper user id, or 0 for every dropshipper
	 * @return WC_Order[]
	 */
	public static function orders( int $dropshipper, int $from, int $to, array $statuses = self::IN_TOTAL ): array {
		$args = array(
			'status'       => $statuses,
			'limit'        => -1,
			'type'         => 'shop_order',
			'date_created' => $from . '...' . $to,
		) + self::meta_args( self::META_DROPSHIPPER, $dropshipper ? (string) $dropshipper : null );
		if ( $dropshipper ) {
			$args['customer_id'] = $dropshipper;
		}
		return wc_get_orders( $args );
	}

	/**
	 * One meta condition for wc_get_orders(). Top-level meta_key/meta_value work on both
	 * order stores; meta_query is HPOS-only and logs a notice on the posts store, which is
	 * what stherb.com runs (HPOS off).
	 *
	 * @param string|null $value null = the key exists
	 */
	public static function meta_args( string $key, ?string $value = null ): array {
		// phpcs:disable WordPress.DB.SlowDBQuery -- dropshipper orders only, a small share of the shop.
		return null === $value
			? array(
				'meta_key'     => $key,
				'meta_compare' => 'EXISTS',
			)
			: array(
				'meta_key'   => $key,
				'meta_value' => $value,
			);
		// phpcs:enable
	}

	public static function net_total( WC_Order $order ): float {
		return max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
	}

	public static function counts( WC_Order $order ): bool {
		return $order->has_status( self::COUNTED );
	}

	/**
	 * @param WC_Order[] $orders
	 * @return array{orders: int, total: float}
	 */
	public static function summarize( array $orders ): array {
		$out = array(
			'orders' => 0,
			'total'  => 0.0,
		);
		foreach ( $orders as $order ) {
			$out['orders'] += self::counts( $order ) ? 1 : 0;
			$out['total']  += self::net_total( $order );
		}
		$out['total'] = round( $out['total'], wc_get_price_decimals() );
		return $out;
	}

	public static function month( int $dropshipper, string $ym ): array {
		list( $from, $to ) = self::month_bounds( $ym );
		return self::summarize( self::orders( $dropshipper, $from, $to ) );
	}

	/**
	 * Orders and total per month, oldest first, from one query.
	 *
	 * @return array<string, array{orders: int, total: float}>
	 */
	public static function by_month( int $dropshipper, int $count = 6, string $last = '' ): array {
		$keys = self::months( $count, $last );
		$from = self::month_bounds( $keys[0] )[0];
		$to   = self::month_bounds( end( $keys ) )[1];

		$buckets = array_fill_keys( $keys, array() );
		foreach ( self::orders( $dropshipper, $from, $to ) as $order ) {
			$created = $order->get_date_created();
			$key     = $created ? wp_date( 'Y-m', $created->getTimestamp() ) : '';
			if ( isset( $buckets[ $key ] ) ) {
				$buckets[ $key ][] = $order;
			}
		}
		return array_map( array( __CLASS__, 'summarize' ), $buckets );
	}

	/**
	 * Per dropshipper for one month, biggest total first.
	 *
	 * @return array<int, array{orders: int, total: float}> keyed by user id
	 */
	public static function by_dropshipper( string $ym ): array {
		list( $from, $to ) = self::month_bounds( $ym );
		$groups            = array();
		foreach ( self::orders( 0, $from, $to ) as $order ) {
			$groups[ (int) $order->get_meta( self::META_DROPSHIPPER ) ][] = $order;
		}
		$out = array_map( array( __CLASS__, 'summarize' ), $groups );
		uasort( $out, static fn( $a, $b ) => $b['total'] <=> $a['total'] );
		return $out;
	}

	/**
	 * Who the parcel went to, from the order itself rather than the saved customer, which
	 * may have been edited or erased since.
	 *
	 * @return array{self: bool, name: string, country: string}
	 */
	public static function ship_to( WC_Order $order ): array {
		$type = (string) $order->get_meta( self::META_SHIP_TO );
		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );

		if ( '' === $type ) {
			// Placed before "Ship this order to" existed: compare with the billing address.
			$type = ( ! $order->has_shipping_address() || ( $name === trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) && $order->get_shipping_address_1() === $order->get_billing_address_1() ) ) ? 'self' : 'customer';
		}

		$self = 'self' === $type;
		return array(
			'self'    => $self,
			'name'    => $self ? __( 'Myself', 'herb-partner-hub' ) : ( '' !== $name ? $name : $order->get_shipping_company() ),
			'country' => $self ? '' : (string) $order->get_shipping_country(),
		);
	}

	/**
	 * Tracking numbers per order. stherb.com has no tracking meta: DHL Express Commerce
	 * leaves a customer note "The Tracking Number is: N." (checked 2026-10-28). The latest
	 * note wins. Other shops can supply their own through the filter.
	 *
	 * @param int[] $order_ids
	 * @return array<int, string> order id => tracking number
	 */
	public static function tracking( array $order_ids ): array {
		$order_ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );
		$out       = array();

		if ( $order_ids ) {
			global $wpdb;
			$in   = implode( ',', $order_ids );
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of absint() ids.
				"SELECT comment_post_ID AS id, comment_content AS note FROM {$wpdb->comments} WHERE comment_type = 'order_note' AND comment_post_ID IN ($in) AND comment_content LIKE '%Tracking Number is:%' ORDER BY comment_ID ASC",
				ARRAY_A
			) ?: array();
			foreach ( $rows as $row ) {
				if ( preg_match( '/Tracking Number is:\s*([A-Za-z0-9-]{6,40})/', $row['note'], $m ) ) {
					$out[ (int) $row['id'] ] = $m[1];
				}
			}
		}

		return (array) apply_filters( 'hph_order_tracking', $out, $order_ids );
	}

	/**
	 * Applies to every cell of an export: a value starting with = + - @ would run as a
	 * formula in Excel or Sheets, and end-customer names are typed by users.
	 */
	public static function csv_cell( $value ): string {
		$value = (string) $value;
		return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $value : $value;
	}

	public static function send_csv( string $filename, array $header, array $rows ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel reads UTF-8 only with a BOM.
		fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $header ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $row ) );
		}
		fclose( $out );
		exit;
	}
}
