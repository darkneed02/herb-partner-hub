<?php

defined( 'ABSPATH' ) || exit;

/**
 * Module "orders": the dropshipper's dashboard and order history in My Account.
 *
 * Both reuse WooCommerce's own pages instead of adding endpoints: the My Account front page
 * shows the partner dashboard, and the Orders page shows the history with "Ship to",
 * tracking and Reorder. Order detail pages (view-order) stay WooCommerce's. Normal
 * customers see the usual pages.
 *
 * Numbers come from HPH_Sales, the same definition the admin Overview uses.
 */
final class HPH_Orders {
	public const PER_PAGE = 20;
	public const EXPORT   = 'hph_orders_csv';

	public function __construct() {
		add_filter( 'wc_get_template', array( $this, 'dashboard_template' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'take_over_orders_page' ), 5 );
		add_filter( 'woocommerce_valid_order_statuses_for_order_again', array( $this, 'reorder_statuses' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	public function enqueue(): void {
		if ( is_account_page() && hph_is_dropshipper() ) {
			HPH_Assets::enqueue();
		}
	}

	public function dashboard_template( $template, $template_name ) {
		if ( 'myaccount/dashboard.php' !== $template_name || ! hph_is_dropshipper() ) {
			return $template;
		}
		return locate_template( 'herb-partner-hub/myaccount/dashboard.php' ) ?: HPH_PATH . 'templates/myaccount/dashboard.php';
	}

	/**
	 * stherb.com marks almost no order Completed (all 40 orders since February 2026 are still
	 * Processing), and WooCommerce only allows "order again" on Completed. For dropshippers,
	 * any order that went through can be reordered. WooCommerce still checks the order is theirs.
	 */
	public function reorder_statuses( $statuses ) {
		if ( hph_is_dropshipper() ) {
			$statuses = array_values( array_unique( array_merge( (array) $statuses, HPH_Sales::COUNTED ) ) );
		}
		return $statuses;
	}

	public static function reorder_url( WC_Order $order ): string {
		$allowed = apply_filters( 'woocommerce_valid_order_statuses_for_order_again', array( 'completed' ) );
		if ( ! $order->has_status( $allowed ) || ! $order->get_item_count() ) {
			return '';
		}
		return wp_nonce_url( add_query_arg( 'order_again', $order->get_id(), wc_get_cart_url() ), 'woocommerce-order_again' );
	}

	public function take_over_orders_page(): void {
		if ( ! is_account_page() || ! is_wc_endpoint_url( 'orders' ) || ! hph_is_dropshipper() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the export checks its own nonce below.
		if ( isset( $_GET[ self::EXPORT ] ) ) {
			$this->export();
		}
		remove_action( 'woocommerce_account_orders_endpoint', 'woocommerce_account_orders' );
		add_action( 'woocommerce_account_orders_endpoint', array( $this, 'render_orders' ) );
	}

	/**
	 * Filters from the query string, cleaned. Every query also has customer_id = current user.
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$month    = sanitize_text_field( wp_unslash( $_GET['month'] ?? '' ) );
		$customer = sanitize_key( wp_unslash( $_GET['customer'] ?? '' ) );
		$status   = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		$page     = max( 1, absint( $_GET['pg'] ?? 1 ) );
		// phpcs:enable

		return array(
			'month'    => HPH_Sales::valid_month( $month ) ? $month : '',
			'customer' => ( 'self' === $customer || ctype_digit( $customer ) ) ? $customer : '',
			'status'   => array_key_exists( 'wc-' . $status, self::statuses() ) ? $status : '',
			'pg'       => $page,
		);
	}

	/** Statuses a dropshipper can filter by: WooCommerce's list without checkout drafts. */
	public static function statuses(): array {
		$all = wc_get_order_statuses();
		unset( $all['wc-checkout-draft'] );
		return $all;
	}

	public static function query_args( int $user_id, array $f ): array {
		$args = array(
			'customer_id' => $user_id,
			'type'        => 'shop_order',
			'status'      => $f['status'] ? array( $f['status'] ) : array_map( static fn( $s ) => substr( $s, 3 ), array_keys( self::statuses() ) ),
			'orderby'     => 'date',
			'order'       => 'DESC',
		);
		if ( $f['month'] ) {
			list( $from, $to )    = HPH_Sales::month_bounds( $f['month'] );
			$args['date_created'] = $from . '...' . $to;
		}
		if ( 'self' === $f['customer'] ) {
			$args += HPH_Sales::meta_args( HPH_Sales::META_SHIP_TO, 'self' );
		} elseif ( '' !== $f['customer'] ) {
			$args += HPH_Sales::meta_args( HPH_Sales::META_CUSTOMER, (string) absint( $f['customer'] ) );
		}
		return $args;
	}

	/**
	 * What the table and the CSV show for each order.
	 *
	 * @param WC_Order[] $orders
	 */
	public static function rows( array $orders ): array {
		$tracking = HPH_Sales::tracking( array_map( static fn( $o ) => $o->get_id(), $orders ) );
		$rows     = array();
		foreach ( $orders as $order ) {
			$created  = $order->get_date_created();
			$refunded = (float) $order->get_total_refunded();
			$rows[]   = array(
				'order'    => $order,
				'number'   => $order->get_order_number(),
				'date'     => $created ? wp_date( 'j M Y', $created->getTimestamp() ) : '',
				'iso_date' => $created ? wp_date( 'Y-m-d', $created->getTimestamp() ) : '',
				'url'      => $order->get_view_order_url(),
				'ship'     => HPH_Sales::ship_to( $order ),
				'status'   => $order->get_status(),
				'label'    => wc_get_order_status_name( $order->get_status() ),
				'tracking' => $tracking[ $order->get_id() ] ?? '',
				'total'    => HPH_Sales::net_total( $order ),
				'gross'    => $refunded > 0 ? (float) $order->get_total() : null,
				'currency' => $order->get_currency(),
				'reorder'  => self::reorder_url( $order ),
				'actions'  => self::order_actions( $order ),
			);
		}
		return $rows;
	}

	/**
	 * WooCommerce's own buttons for the order (Pay for a failed or pending order, Cancel,
	 * and whatever payment plugins add through woocommerce_my_account_my_orders_actions,
	 * e.g. Stripe on stherb.com). View is the order number link; Reorder has its own button.
	 */
	public static function order_actions( WC_Order $order ): array {
		$actions = wc_get_account_orders_actions( $order );
		unset( $actions['view'], $actions['order-again'] );
		return $actions;
	}

	/** CSS modifier for a status pill. */
	public static function status_tone( string $status ): string {
		$tones = array(
			'completed'  => 'ok',
			'processing' => 'info',
			'on-hold'    => 'warn',
			'pending'    => 'warn',
			'refunded'   => 'bad',
			'cancelled'  => 'bad',
			'failed'     => 'bad',
		);
		return $tones[ $status ] ?? 'info';
	}

	public static function customer_options( int $user_id ): array {
		$out = array( 'self' => __( 'Myself', 'herb-partner-hub' ) );
		if ( class_exists( 'HPH_Customer_Repo' ) ) {
			foreach ( HPH_Customer_Repo::all_for_owner( $user_id ) as $row ) {
				$out[ (string) $row['id'] ] = HPH_Customer_Repo::name( $row );
			}
		}
		return $out;
	}

	public static function url( array $args = array() ): string {
		$url = wc_get_account_endpoint_url( 'orders' );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	public function render_orders(): void {
		$user_id = get_current_user_id();
		$f       = self::filters();
		$args    = self::query_args( $user_id, $f ) + array(
			'paginate' => true,
			'limit'    => self::PER_PAGE,
			'paged'    => $f['pg'],
		);
		$result  = wc_get_orders( $args );
		$rows    = self::rows( $result->orders );
		$pages   = (int) $result->max_num_pages;
		$total   = (int) $result->total;
		$months  = array_reverse( HPH_Sales::months( 12 ) );
		$people  = self::customer_options( $user_id );
		$active  = array_filter( array_intersect_key( $f, array_flip( array( 'month', 'customer', 'status' ) ) ) );
		$export  = wp_nonce_url( self::url( $active + array( self::EXPORT => 1 ) ), self::EXPORT );

		include locate_template( 'herb-partner-hub/myaccount/orders.php' ) ?: HPH_PATH . 'templates/myaccount/orders.php';
	}

	private function export(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce value.
		if ( ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ?? '' ), self::EXPORT ) ) {
			wc_add_notice( __( 'The export link expired. Please try again.', 'herb-partner-hub' ), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}
		$f      = self::filters();
		$orders = wc_get_orders( self::query_args( get_current_user_id(), $f ) + array( 'limit' => -1 ) );
		$out    = array();
		foreach ( self::rows( $orders ) as $r ) {
			$out[] = array(
				$r['number'],
				$r['iso_date'],
				$r['ship']['name'],
				$r['ship']['country'],
				$r['label'],
				$r['tracking'],
				$r['order']->get_item_count(),
				wc_format_decimal( $r['total'], wc_get_price_decimals() ),
				$r['currency'],
			);
		}
		HPH_Sales::send_csv(
			'orders-' . ( $f['month'] ?: 'all' ) . '.csv',
			array(
				__( 'Order', 'herb-partner-hub' ),
				__( 'Date', 'herb-partner-hub' ),
				__( 'Ship to', 'herb-partner-hub' ),
				__( 'Country', 'herb-partner-hub' ),
				__( 'Status', 'herb-partner-hub' ),
				__( 'Tracking', 'herb-partner-hub' ),
				__( 'Items', 'herb-partner-hub' ),
				__( 'Total after refunds', 'herb-partner-hub' ),
				__( 'Currency', 'herb-partner-hub' ),
			),
			$out
		);
	}

	/**
	 * Everything the dashboard shows, for one dropshipper.
	 */
	public static function dashboard_data( int $user_id ): array {
		$now     = HPH_Sales::current_month();
		$months  = HPH_Sales::by_month( $user_id, 6, $now );
		$keys    = array_keys( $months );
		$this_m  = $months[ $now ];
		$prev_m  = $months[ $keys[ count( $keys ) - 2 ] ];
		$six     = array_sum( array_column( $months, 'total' ) );
		$recent  = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'type'        => 'shop_order',
				'status'      => array_map( static fn( $s ) => substr( $s, 3 ), array_keys( self::statuses() ) ),
				'limit'       => 5,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$user    = get_userdata( $user_id );
		$company = (string) get_user_meta( $user_id, 'billing_company', true );

		return array(
			'name'      => '' !== $company ? $company : ( $user ? $user->display_name : '' ),
			'month'     => $now,
			'this'      => $this_m,
			'diff'      => $this_m['orders'] - $prev_m['orders'],
			'prev_name' => HPH_Sales::month_label( $keys[ count( $keys ) - 2 ], 'M' ),
			'months'    => $months,
			'max'       => max( 1.0, max( array_column( $months, 'total' ) ) ),
			'six'       => $six,
			'customers' => class_exists( 'HPH_Customer_Repo' ) ? HPH_Customer_Repo::for_owner( $user_id, '', 1, 1 )['total'] : null,
			'recent'    => self::rows( $recent ),
			'shop'      => wc_get_page_permalink( 'shop' ),
			'orders'    => self::url(),
		);
	}
}
