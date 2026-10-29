<?php

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's own storefront look (assets/css/partner.css), the same on any theme.
 * Colours and fonts follow the stherb.com design system used in the plan's mockups.
 */
final class HPH_Assets {
	public const STYLE = 'hph-partner';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ), 5 );
	}

	public function register(): void {
		// stherb.com already loads these fonts; elsewhere the plugin brings them.
		$deps = array();
		if ( ! wp_style_is( 'stherb-redesign-fonts', 'registered' ) ) {
			wp_register_style( 'hph-fonts', 'https://fonts.googleapis.com/css2?family=Cormorant:wght@600;700&family=Mulish:wght@400;600;700;800&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts URL.
			$deps[] = 'hph-fonts';
		}
		wp_register_style( self::STYLE, HPH_URL . 'assets/css/partner.css', $deps, (string) filemtime( HPH_PATH . 'assets/css/partner.css' ) );
	}

	public static function enqueue(): void {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			( new self() )->register();
		}
		wp_enqueue_style( self::STYLE );
	}
}
