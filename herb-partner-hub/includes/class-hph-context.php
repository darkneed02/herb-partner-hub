<?php

defined( 'ABSPATH' ) || exit;

/**
 * The one place that decides whether the current request gets dropshipper behaviour.
 * Every module asks hph_is_dropshipper() instead of checking roles itself.
 */
final class HPH_Context {
	public const STATUS_META  = '_hph_status';
	public const VIEW_AS_META = '_hph_view_as';

	public function __construct() {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'admin_post_hph_view_as', array( $this, 'toggle_view_as' ) );
	}

	/**
	 * True only when all of these hold:
	 * - storefront request (not wp-admin screens, cron, WP-CLI or REST other than the Store API)
	 * - the user has the dropshipper role, status "active", and cannot manage the programme
	 * - soft launch is off, or the user is on the soft launch list
	 * An admin who switched on "View as dropshipper" also gets true, for their own requests only.
	 */
	public static function is_dropshipper( ?int $user_id = null ): bool {
		// Before init the logged-in user is not settled yet; nothing prices a cart that early.
		if ( ! did_action( 'init' ) ) {
			return false;
		}

		$current = get_current_user_id();
		$user_id = $user_id ?? $current;
		$result  = false;

		if ( $user_id > 0 && self::is_storefront() ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				if ( $user_id === $current && self::is_viewing_as( $user ) ) {
					$result = true;
				} else {
					$result = self::is_partner( $user ) && ! user_can( $user, 'hph_manage' ) && self::passes_soft_launch( $user_id );
				}
			}
		}

		return (bool) apply_filters( 'hph_is_dropshipper', $result, $user_id );
	}

	/**
	 * Role plus active status, regardless of request or soft launch.
	 * Use this for admin lists and reports.
	 */
	public static function is_partner( WP_User|int $user ): bool {
		$user = $user instanceof WP_User ? $user : get_userdata( $user );
		if ( ! $user || ! in_array( HPH_Install::ROLE, (array) $user->roles, true ) ) {
			return false;
		}
		return 'active' === get_user_meta( $user->ID, self::STATUS_META, true );
	}

	public static function is_viewing_as( WP_User $user ): bool {
		return user_can( $user, 'hph_manage' ) && '1' === get_user_meta( $user->ID, self::VIEW_AS_META, true );
	}

	public static function passes_soft_launch( int $user_id ): bool {
		if ( ! HPH_Settings::get( 'soft_launch' ) ) {
			return true;
		}
		return in_array( $user_id, (array) HPH_Settings::get( 'soft_launch_users' ), true );
	}

	/**
	 * admin-ajax counts as storefront: some checkout plugins post there from the shop.
	 * Product edit screens read prices in "edit" context, which skips price filters anyway.
	 */
	public static function is_storefront(): bool {
		$storefront = true;

		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$storefront = false;
		} elseif ( is_admin() && ! wp_doing_ajax() ) {
			$storefront = false;
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$storefront = self::is_store_api_request();
		}

		return (bool) apply_filters( 'hph_is_storefront', $storefront );
	}

	private static function is_store_api_request(): bool {
		$route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
		if ( '' === $route ) {
			$route = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
		}
		return str_contains( $route, '/wc/store/' );
	}

	public function admin_bar( WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! current_user_can( 'hph_manage' ) ) {
			return;
		}

		$on  = self::is_viewing_as( wp_get_current_user() );
		$url = wp_nonce_url( add_query_arg( 'action', 'hph_view_as', admin_url( 'admin-post.php' ) ), 'hph_view_as' );

		$bar->add_node(
			array(
				'id'    => 'hph-view-as',
				'title' => $on ? __( 'Viewing as dropshipper: ON', 'herb-partner-hub' ) : __( 'View as dropshipper', 'herb-partner-hub' ),
				'href'  => $url,
				'meta'  => array( 'title' => __( 'Show the shop the way an approved dropshipper sees it. Only affects your own account.', 'herb-partner-hub' ) ),
			)
		);
	}

	public function toggle_view_as(): void {
		if ( ! current_user_can( 'hph_manage' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'herb-partner-hub' ), 403 );
		}
		check_admin_referer( 'hph_view_as' );

		$user_id = get_current_user_id();
		$on      = '1' === get_user_meta( $user_id, self::VIEW_AS_META, true );

		if ( $on ) {
			delete_user_meta( $user_id, self::VIEW_AS_META );
		} else {
			update_user_meta( $user_id, self::VIEW_AS_META, '1' );
		}
		HPH_Log::add( 'view_as_toggled', 'user', $user_id, $on, ! $on );

		// Back to the page the toggle was clicked on.
		wp_safe_redirect( wp_get_referer() ?: home_url( '/' ) );
		exit;
	}
}
