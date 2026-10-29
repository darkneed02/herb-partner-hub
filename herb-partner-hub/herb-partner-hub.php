<?php
/**
 * Plugin Name: Herb Partner Hub
 * Description: Dropshipper programme for WooCommerce: applications and approval, wholesale prices, and orders shipped straight to the dropshipper's own customers.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * Text Domain: herb-partner-hub
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'HPH_VERSION', '0.1.0' );
define( 'HPH_DB_VERSION', '1' );
define( 'HPH_FILE', __FILE__ );
define( 'HPH_PATH', plugin_dir_path( __FILE__ ) );
define( 'HPH_URL', plugin_dir_url( __FILE__ ) );

require_once HPH_PATH . 'includes/functions.php';
require_once HPH_PATH . 'includes/class-hph-settings.php';
require_once HPH_PATH . 'includes/class-hph-install.php';
require_once HPH_PATH . 'includes/class-hph-log.php';
require_once HPH_PATH . 'includes/class-hph-context.php';
require_once HPH_PATH . 'includes/class-hph-sales.php';
require_once HPH_PATH . 'includes/class-hph-assets.php';
require_once HPH_PATH . 'includes/class-hph-admin.php';
require_once HPH_PATH . 'includes/class-hph-plugin.php';

register_activation_hook( __FILE__, array( 'HPH_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HPH_Install', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'HPH_Plugin', 'instance' ) );
