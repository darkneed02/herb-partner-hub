<?php

defined( 'ABSPATH' ) || exit;

/**
 * Dropshipper fields on the product edit screen: an "Available to dropshippers" box for
 * simple and variable products, a price for simple products, and a price per variation.
 */
final class HPH_Product_Fields {
	public function __construct() {
		// Priority 1: straight under "Sale price", above Tier Pricing Table's fields (10).
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_simple' ), 1 );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_variable' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ), 20 );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'save_variation' ), 20, 2 );
	}

	private static function bulk_note(): string {
		return sprintf(
			/* translators: %s: link to Dropshippers > Products */
			__( 'Set many products at once in %s.', 'herb-partner-hub' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . HPH_Products_Admin::PAGE ) ) . '">' . esc_html__( 'Dropshippers › Products', 'herb-partner-hub' ) . '</a>'
		);
	}

	/**
	 * Simple products: "Available for dropshippers" and the price, inside the pricing group.
	 * Field names differ from the variable block so a hidden field never overrides the visible one.
	 */
	public function render_simple(): void {
		global $product_object;
		if ( ! current_user_can( 'hph_manage' ) || ! $product_object instanceof WC_Product ) {
			return;
		}
		woocommerce_wp_checkbox(
			array(
				'id'            => '_hph_enabled',
				'label'         => __( 'Available for dropshippers', 'herb-partner-hub' ),
				'description'   => __( 'Joined', 'herb-partner-hub' ),
				'value'         => $product_object->get_meta( HPH_Catalog::ENABLED_META, true, 'edit' ) ?: 'no',
				'wrapper_class' => 'show_if_simple',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => '_hph_price',
				'label'         => __( 'Dropshipper price', 'herb-partner-hub' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'data_type'     => 'price',
				'value'         => $product_object->get_meta( HPH_Catalog::PRICE_META, true, 'edit' ),
				'wrapper_class' => 'show_if_simple',
				'description'   => __( 'Shown only to approved dropshippers. Required when the product is joined.', 'herb-partner-hub' ) . ' ' . self::bulk_note(),
			)
		);
	}

	public function render_variable(): void {
		global $product_object;
		if ( ! current_user_can( 'hph_manage' ) || ! $product_object instanceof WC_Product ) {
			return;
		}
		echo '<div class="options_group show_if_variable">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_hph_enabled_variable',
				'label'       => __( 'Available for dropshippers', 'herb-partner-hub' ),
				'description' => __( 'Joined. Set the dropshipper price on each variation; variations without one stay hidden.', 'herb-partner-hub' ),
				'value'       => $product_object->get_meta( HPH_Catalog::ENABLED_META, true, 'edit' ) ?: 'no',
			)
		);
		echo '<p class="form-field"><span class="description">' . wp_kses_post( self::bulk_note() ) . '</span></p>';
		echo '</div>';
	}

	public function save( WC_Product $product ): void {
		if ( ! current_user_can( 'hph_manage' ) || ! $product->is_type( array( 'simple', 'variable' ) ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product form nonce.
		$simple  = $product->is_type( 'simple' );
		$enabled = ! empty( $_POST[ $simple ? '_hph_enabled' : '_hph_enabled_variable' ] );
		$price   = $simple && isset( $_POST['_hph_price'] ) ? wc_clean( wp_unslash( $_POST['_hph_price'] ) ) : null;
		// phpcs:enable
		$result = HPH_Catalog::apply( $product, $price, $enabled );
		if ( is_wp_error( $result ) ) {
			WC_Admin_Meta_Boxes::add_error( $result->get_error_message() );
		}
	}

	public function render_variation( $loop, $variation_data, $variation ): void {
		if ( ! current_user_can( 'hph_manage' ) ) {
			return;
		}
		woocommerce_wp_text_input(
			array(
				'id'            => "hph_variation_price{$loop}",
				'name'          => "hph_variation_price[{$loop}]",
				'label'         => __( 'Dropshipper price', 'herb-partner-hub' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'data_type'     => 'price',
				'value'         => get_post_meta( $variation->ID, HPH_Catalog::PRICE_META, true ),
				'wrapper_class' => 'form-row form-row-full',
				'placeholder'   => __( 'Leave empty to hide from dropshippers', 'herb-partner-hub' ),
			)
		);
	}

	public function save_variation( WC_Product_Variation $variation, $i ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the variations nonce.
		if ( ! current_user_can( 'hph_manage' ) || ! isset( $_POST['hph_variation_price'][ $i ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = HPH_Catalog::apply( $variation, wc_clean( wp_unslash( $_POST['hph_variation_price'][ $i ] ) ), null );
		if ( is_wp_error( $result ) ) {
			WC_Admin_Meta_Boxes::add_error( $result->get_error_message() );
		}
	}
}
