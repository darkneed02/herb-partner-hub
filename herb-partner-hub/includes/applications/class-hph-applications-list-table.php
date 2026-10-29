<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class HPH_Applications_List_Table extends WP_List_Table {
	private const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'application',
				'plural'   => 'applications',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'applicant' => __( 'Applicant', 'herb-partner-hub' ),
			'business'  => __( 'Store / channel', 'herb-partner-hub' ),
			'channels'  => __( 'Sells on', 'herb-partner-hub' ),
			'country'   => __( 'Country', 'herb-partner-hub' ),
			'existing'  => __( 'Existing customer?', 'herb-partner-hub' ),
			'date'      => __( 'Submitted', 'herb-partner-hub' ),
			'status'    => __( 'Status', 'herb-partner-hub' ),
		);
	}

	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters.
		$result = HPH_Application_Repo::query(
			array(
				'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
				'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'per_page' => self::PER_PAGE,
				'page'     => $this->get_pagenum(),
			)
		);
		// phpcs:enable
		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'applicant' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	protected function get_table_classes() {
		return array_merge( parent::get_table_classes(), array( 'hph-table' ) );
	}

	protected function get_views() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$counts  = HPH_Application_Repo::counts();
		$base    = admin_url( 'admin.php?page=' . HPH_Applications_Admin::PAGE );
		$views   = array(
			'all' => sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $base ), '' === $current ? ' class="current" aria-current="page"' : '', esc_html__( 'All', 'herb-partner-hub' ), array_sum( $counts ) ),
		);
		foreach ( HPH_Application_Repo::status_labels() as $status => $label ) {
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$counts[ $status ]
			);
		}
		return $views;
	}

	public function no_items() {
		esc_html_e( 'No applications yet.', 'herb-partner-hub' );
	}

	protected function column_applicant( $item ) {
		$url = admin_url( 'admin.php?page=' . HPH_Applications_Admin::PAGE . '&id=' . $item['id'] );
		return '<strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $item['name'] ) . '</a></strong>'
			. '<div class="description">' . esc_html( $item['email'] ) . '</div>';
	}

	/**
	 * Review | Approve | Reject under the applicant's name. Keys avoid "approve", which
	 * WP core CSS hides (it belongs to the comments screen).
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary ) {
			return '';
		}
		$url     = admin_url( 'admin.php?page=' . HPH_Applications_Admin::PAGE . '&id=' . $item['id'] );
		$actions = array( 'review' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Review', 'herb-partner-hub' ) . '</a>' );

		if ( in_array( $item['status'], HPH_Application_Repo::OPEN, true ) ) {
			$account = HPH_Approval::account_for( $item );
			if ( ! $account || ! user_can( $account, 'hph_manage' ) ) {
				$actions['hph-approve'] = '<a href="' . esc_url( HPH_Applications_Admin::quick_url( $item['id'], 'approve' ) ) . '">' . esc_html__( 'Approve', 'herb-partner-hub' ) . '</a>';
			}
			$actions['trash'] = '<a class="submitdelete" href="' . esc_url( HPH_Applications_Admin::quick_url( $item['id'], 'rejected' ) ) . '">' . esc_html__( 'Reject', 'herb-partner-hub' ) . '</a>';
		}

		return $this->row_actions( $actions, true );
	}

	protected function column_existing( $item ) {
		$existing = HPH_Applications_Admin::existing_customer( $item );
		if ( ! $existing['user'] ) {
			return '<span class="hph-tag">' . esc_html__( 'No', 'herb-partner-hub' ) . '</span>';
		}
		if ( user_can( $existing['user'], 'hph_manage' ) ) {
			return '<span class="hph-tag hph-tag--r">' . esc_html__( 'Staff account', 'herb-partner-hub' ) . '</span>';
		}
		/* translators: %d: number of orders */
		$label = sprintf( _n( 'Yes · %d order', 'Yes · %d orders', $existing['orders'], 'herb-partner-hub' ), $existing['orders'] );
		return '<span class="hph-tag hph-tag--b">' . esc_html( $label ) . '</span>';
	}

	protected function column_status( $item ) {
		return HPH_Application_Repo::status_tag( $item['status'] );
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'business':
				return '' === $item['business_name'] ? '—' : esc_html( $item['business_name'] );
			case 'country':
				return esc_html( $item['country'] );
			case 'channels':
				return esc_html( implode( ', ', (array) ( $item['fields']['sales_channels'] ?? array() ) ) );
			case 'date':
				return esc_html( wp_date( get_option( 'date_format' ), strtotime( $item['created_at'] . ' UTC' ) ) );
		}
		return '';
	}
}
