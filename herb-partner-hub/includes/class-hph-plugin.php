<?php

defined( 'ABSPATH' ) || exit;

final class HPH_Plugin {
	private static ?self $instance = null;

	/** @var array<string, object> Loaded module objects, keyed by module key. */
	private array $modules = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		HPH_Install::maybe_upgrade();
		HPH_Settings::init();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_required_notice' ) );
			return;
		}

		new HPH_Context();
		new HPH_Assets();

		if ( is_admin() ) {
			new HPH_Admin();
		}

		$this->load_modules();
	}

	/**
	 * A module loads only when its switch is on and its file exists, so a half-finished
	 * install never shows anything to customers.
	 */
	private function load_modules(): void {
		foreach ( HPH_Settings::modules() as $key ) {
			if ( ! HPH_Settings::module_enabled( $key ) ) {
				continue;
			}
			$file = HPH_PATH . "includes/{$key}/class-hph-{$key}.php";
			if ( ! is_readable( $file ) ) {
				continue;
			}
			require_once $file;
			$class = 'HPH_' . ucfirst( $key );
			if ( class_exists( $class ) ) {
				$this->modules[ $key ] = new $class();
			}
		}
	}

	public function module( string $key ): ?object {
		return $this->modules[ $key ] ?? null;
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'herb-partner-hub', false, dirname( plugin_basename( HPH_FILE ) ) . '/languages' );
	}

	public function declare_hpos_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				HPH_FILE,
				true
			);
		}
	}

	public function woocommerce_required_notice(): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Herb Partner Hub requires WooCommerce to be installed and active.', 'herb-partner-hub' );
		echo '</p></div>';
	}
}
