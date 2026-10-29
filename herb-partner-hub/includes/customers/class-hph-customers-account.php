<?php

defined( 'ABSPATH' ) || exit;

/**
 * My Account › My Customers. Only the owner sees or changes a customer: every action
 * checks a nonce and loads the row through HPH_Customer_Repo with the current user id.
 */
final class HPH_Customers_Account {
	public const ENDPOINT        = 'my-customers';
	public const ACTION_SAVE     = 'hph_customer_save';
	public const ACTION_DELETE   = 'hph_customer_delete';
	private const REWRITE_OPTION = 'hph_rewrite_endpoints';

	/** Set by a failed save so the form shows the errors and what was typed. */
	private static array $form_state = array();

	public function __construct() {
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu' ), 20 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	public function query_vars( array $vars ): array {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * The endpoint needs a rewrite rule. Switching the module on in Settings does not run
	 * activation, so flush once whenever the endpoint list changes.
	 */
	public function maybe_flush_rewrites(): void {
		if ( get_option( self::REWRITE_OPTION ) !== self::ENDPOINT ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_OPTION, self::ENDPOINT, false );
		}
	}

	public function menu( array $items ): array {
		if ( ! hph_is_dropshipper() ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$out[ self::ENDPOINT ] = __( 'My Customers', 'herb-partner-hub' );
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out = array( self::ENDPOINT => __( 'My Customers', 'herb-partner-hub' ) ) + $out;
		}
		return $out;
	}

	public function title(): string {
		return __( 'My Customers', 'herb-partner-hub' );
	}

	public static function url( array $args = array() ): string {
		$url = wc_get_account_endpoint_url( self::ENDPOINT );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	public function enqueue(): void {
		if ( ! is_account_page() || ! hph_is_dropshipper() || ! self::is_endpoint() ) {
			return;
		}
		HPH_Assets::enqueue();
		wp_enqueue_script( 'hph-customers', HPH_URL . 'assets/js/customers.js', array(), (string) filemtime( HPH_PATH . 'assets/js/customers.js' ), true );
		wp_localize_script(
			'hph-customers',
			'hphCustomers',
			array(
				'states' => self::states_for_js(),
				'choose' => __( 'Choose…', 'herb-partner-hub' ),
			)
		);
	}

	private static function is_endpoint(): bool {
		global $wp;
		return isset( $wp->query_vars[ self::ENDPOINT ] );
	}

	/**
	 * States of the countries we ship to, for the state field that changes with the country.
	 */
	public static function states_for_js(): array {
		$out = array();
		foreach ( array_keys( WC()->countries->get_shipping_countries() ) as $code ) {
			$states = WC()->countries->get_states( $code );
			if ( is_array( $states ) && $states ) {
				$out[ $code ] = $states;
			}
		}
		return $out;
	}

	public function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['hph_action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['hph_action'] ) );
		if ( ! in_array( $action, array( self::ACTION_SAVE, self::ACTION_DELETE ), true ) ) {
			return;
		}
		$id    = absint( $_POST['hph_customer_id'] ?? 0 );
		$nonce = sanitize_text_field( wp_unslash( $_POST['_hph_nonce'] ?? '' ) );

		if ( ! is_user_logged_in() || ! hph_is_dropshipper() || ! wp_verify_nonce( $nonce, $action . '_' . $id ) ) {
			wc_add_notice( __( 'Your session expired. Please try again.', 'herb-partner-hub' ), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}
		$owner = get_current_user_id();

		if ( self::ACTION_DELETE === $action ) {
			$row = HPH_Customer_Repo::get( $id, $owner );
			if ( $row && HPH_Customer_Repo::erase( $id, $owner ) ) {
				/* translators: %s: customer name */
				wc_add_notice( sprintf( __( '%s was deleted. Past orders keep their shipping address.', 'herb-partner-hub' ), HPH_Customer_Repo::name( $row ) ) );
			} else {
				wc_add_notice( __( 'Customer not found.', 'herb-partner-hub' ), 'error' );
			}
			wp_safe_redirect( self::url() );
			exit;
		}

		$input  = wp_unslash( $_POST['hph_customer'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HPH_Customer_Repo::clean() sanitizes each field.
		$input  = is_array( $input ) ? $input : array();
		$result = $id ? HPH_Customer_Repo::update( $id, $owner, $input ) : HPH_Customer_Repo::insert( $owner, $input );

		if ( is_wp_error( $result ) ) {
			if ( 'not_found' === $result->get_error_code() ) {
				wc_add_notice( $result->get_error_message(), 'error' );
				wp_safe_redirect( self::url() );
				exit;
			}
			$errors = array();
			foreach ( $result->get_error_codes() as $code ) {
				$errors[ $code ] = $result->get_error_message( $code );
			}
			// Shown on this same request by render(), so nothing is stored between requests.
			self::$form_state = array(
				'id'     => $id,
				'old'    => HPH_Customer_Repo::clean( $input ),
				'errors' => $errors,
			);
			return;
		}

		$name = HPH_Customer_Repo::name( HPH_Customer_Repo::clean( $input ) );
		/* translators: %s: customer name */
		wc_add_notice( sprintf( $id ? __( '%s was updated.', 'herb-partner-hub' ) : __( '%s was added to My Customers.', 'herb-partner-hub' ), $name ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	public function render(): void {
		if ( ! hph_is_dropshipper() ) {
			echo '<p>' . esc_html__( 'This page is for approved dropshippers.', 'herb-partner-hub' ) . '</p>';
			return;
		}
		$owner = get_current_user_id();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- view switches only; changes go through handle_post().
		$search  = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$page    = max( 1, absint( $_GET['pg'] ?? 1 ) );
		$edit_id = absint( $_GET['edit'] ?? 0 );
		$del_id  = absint( $_GET['delete'] ?? 0 );
		$adding  = isset( $_GET['add'] );
		// phpcs:enable

		$list  = HPH_Customer_Repo::for_owner( $owner, $search, $page );
		$stats = HPH_Customer_Repo::order_stats( $owner );
		$pages = (int) ceil( $list['total'] / HPH_Customer_Repo::PER_PAGE );

		$form = null; // array( 'id', 'old', 'errors' ) when the add/edit panel is open.
		if ( self::$form_state ) {
			$form = self::$form_state;
		} elseif ( $edit_id ) {
			$row = HPH_Customer_Repo::get( $edit_id, $owner );
			if ( $row ) {
				$form = array(
					'id'     => $edit_id,
					'old'    => $row,
					'errors' => array(),
				);
			} else {
				wc_print_notice( __( 'Customer not found.', 'herb-partner-hub' ), 'error' );
			}
		} elseif ( $adding ) {
			$form = array(
				'id'     => 0,
				'old'    => array( 'country' => self::default_country( $owner ) ),
				'errors' => array(),
			);
		}

		$deleting = $del_id ? HPH_Customer_Repo::get( $del_id, $owner ) : null;
		$total    = self::count_all( $owner, $search, $list['total'] );

		include locate_template( 'herb-partner-hub/myaccount/customers.php' ) ?: HPH_PATH . 'templates/myaccount/customers.php';
	}

	/** Total customers of the owner (not just the ones matching a search). */
	private static function count_all( int $owner, string $search, int $matched ): int {
		return '' === $search ? $matched : HPH_Customer_Repo::for_owner( $owner, '', 1, 1 )['total'];
	}

	private static function default_country( int $owner ): string {
		$country = (string) get_user_meta( $owner, 'billing_country', true );
		return '' !== $country ? $country : WC()->countries->get_base_country();
	}
}
