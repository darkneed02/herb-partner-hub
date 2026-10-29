<?php

defined( 'ABSPATH' ) || exit;

final class HPH_Settings {
	public const OPTION_KEY = 'hph_settings';

	/**
	 * Module keys in load order. Each one maps to includes/<key>/class-hph-<key>.php.
	 * Labels live in module_labels() because translations are not loaded yet at plugins_loaded.
	 */
	public static function modules(): array {
		return array( 'applications', 'pricing', 'discounts', 'customers', 'orders', 'reports' );
	}

	public static function module_labels(): array {
		return array(
			'applications' => __( 'Applications and approval', 'herb-partner-hub' ),
			'pricing'      => __( 'Wholesale prices and participating products', 'herb-partner-hub' ),
			'discounts'    => __( 'Block site promotions and coupons for dropshippers', 'herb-partner-hub' ),
			'customers'    => __( 'End customers and "Ship to" at checkout', 'herb-partner-hub' ),
			'orders'       => __( 'Order history and partner dashboard', 'herb-partner-hub' ),
			'reports'      => __( 'Reports', 'herb-partner-hub' ),
		);
	}

	/**
	 * Everything starts switched off, and soft launch starts on with nobody on the list,
	 * so activating the plugin on a live shop changes nothing until someone opts in.
	 */
	public static function defaults(): array {
		return array(
			'modules'                  => array_fill_keys( self::modules(), 0 ),
			'soft_launch'              => 1,
			'soft_launch_users'        => array(),
			'terms_page_id'            => 0,
			'terms_version'            => '',
			'delete_data_on_uninstall' => 0,
		);
	}

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'log_change' ), 10, 2 );
	}

	/**
	 * Registered on init (not admin_init) so the sanitizer also runs for update() calls
	 * made from the front end, cron or WP-CLI.
	 */
	public static function register(): void {
		register_setting(
			self::OPTION_KEY,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION_KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$all   = array_merge( self::defaults(), $saved );

		$all['modules'] = array_merge( self::defaults()['modules'], is_array( $all['modules'] ) ? $all['modules'] : array() );

		return $all;
	}

	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	public static function module_enabled( string $key ): bool {
		return ! empty( self::all()['modules'][ $key ] );
	}

	/**
	 * Change some settings and keep the rest. Goes through sanitize().
	 */
	public static function update( array $changes ): void {
		$next = array_merge( self::all(), $changes );
		if ( isset( $changes['modules'] ) && is_array( $changes['modules'] ) ) {
			$next['modules'] = array_merge( self::all()['modules'], $changes['modules'] );
		}
		update_option( self::OPTION_KEY, $next );
	}

	/**
	 * Checkboxes that are missing from $input count as off, which is how an HTML form
	 * sends them. Programmatic callers should go through update(), which passes every key.
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$modules = isset( $input['modules'] ) && is_array( $input['modules'] ) ? $input['modules'] : array();
		foreach ( self::modules() as $key ) {
			$out['modules'][ $key ] = empty( $modules[ $key ] ) ? 0 : 1;
		}

		foreach ( array( 'soft_launch', 'delete_data_on_uninstall' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$users = $input['soft_launch_users'] ?? array();
		if ( is_string( $users ) ) {
			$users = preg_split( '/[\s,]+/', $users, -1, PREG_SPLIT_NO_EMPTY );
		}
		$users                    = array_filter( array_map( 'absint', (array) $users ) );
		$out['soft_launch_users'] = array_values( array_unique( $users ) );

		$out['terms_page_id'] = absint( $input['terms_page_id'] ?? 0 );
		$out['terms_version'] = substr( sanitize_text_field( (string) ( $input['terms_version'] ?? '' ) ), 0, 20 );

		return $out;
	}

	public static function log_change( $old, $new ): void {
		HPH_Log::add( 'settings_updated', 'settings', 0, $old, $new );
	}
}
