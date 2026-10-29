<?php

defined( 'ABSPATH' ) || exit;

/**
 * Which products dropshippers can buy, and at what price. Reads raw meta, so it is the
 * same answer in wp-admin, cron and the storefront.
 *
 * - simple: `_hph_enabled = yes` and a `_hph_price` above 0
 * - variable: `_hph_enabled = yes` on the parent and at least one variation with a price
 * - variation: parent enabled and its own `_hph_price` (a variation without a price is hidden)
 * - any other product type: never
 */
final class HPH_Catalog {
	public const ENABLED_META = '_hph_enabled';
	public const PRICE_META   = '_hph_price';

	private static array $priced_children = array();

	public static function price_meta( int $product_id ): ?float {
		$raw = get_post_meta( $product_id, self::PRICE_META, true );
		return is_numeric( $raw ) && (float) $raw > 0 ? (float) $raw : null;
	}

	public static function enabled_meta( int $product_id ): bool {
		return 'yes' === get_post_meta( $product_id, self::ENABLED_META, true );
	}

	public static function has_priced_variation( int $parent_id ): bool {
		if ( ! isset( self::$priced_children[ $parent_id ] ) ) {
			$children = get_posts(
				array(
					'post_type'      => 'product_variation',
					'post_parent'    => $parent_id,
					'post_status'    => array( 'publish', 'private' ),
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'hph_skip'       => true,
				)
			);
			self::$priced_children[ $parent_id ] = (bool) array_filter( $children, static fn( $id ) => null !== self::price_meta( (int) $id ) );
		}
		return self::$priced_children[ $parent_id ];
	}

	public static function is_participating( WC_Product $product ): bool {
		$id = $product->get_id();
		switch ( $product->get_type() ) {
			case 'simple':
				return self::enabled_meta( $id ) && null !== self::price_meta( $id );
			case 'variable':
				return self::enabled_meta( $id ) && self::has_priced_variation( $id );
			case 'variation':
				return self::enabled_meta( $product->get_parent_id() ) && null !== self::price_meta( $id );
		}
		return false;
	}

	/**
	 * The dropshipper price, or null when the product is not open to dropshippers.
	 * Variable parents have no single price; ask each variation.
	 */
	public static function wholesale_price( WC_Product $product ): ?float {
		if ( $product->is_type( 'variable' ) || ! self::is_participating( $product ) ) {
			return null;
		}
		return self::price_meta( $product->get_id() );
	}

	/**
	 * Change the dropshipper price and/or the participating flag through product CRUD.
	 * Pass null to leave a value as it is, '' as price to remove it.
	 * Returns WP_Error when the product cannot be enabled (no price), otherwise the
	 * before/after pair, which is also written to the log. Caller saves the product.
	 */
	public static function apply( WC_Product $product, ?string $price, ?bool $enabled ) {
		$id     = $product->get_id();
		$before = array(
			'price'   => $product->get_meta( self::PRICE_META, true, 'edit' ),
			'enabled' => $product->get_meta( self::ENABLED_META, true, 'edit' ),
		);
		$after  = $before;
		$error  = null;

		if ( null !== $price ) {
			$price = trim( $price );
			if ( '' === $price ) {
				$after['price'] = '';
			} elseif ( ! is_numeric( wc_format_decimal( $price ) ) || (float) wc_format_decimal( $price ) <= 0 ) {
				/* translators: %s: product name */
				$error = new WP_Error( 'hph_bad_price', sprintf( __( '%s: the dropshipper price must be a number above 0.', 'herb-partner-hub' ), $product->get_name() ) );
			} else {
				$after['price'] = wc_format_decimal( $price );
			}
		}

		if ( null !== $enabled && ! $product->is_type( 'variation' ) ) {
			$after['enabled'] = $enabled ? 'yes' : 'no';
		}

		if ( 'yes' === $after['enabled'] ) {
			$priced = $product->is_type( 'variable' )
				? self::variable_has_price_after( $product )
				: ( is_numeric( $after['price'] ) && (float) $after['price'] > 0 );
			if ( ! $priced ) {
				$after['enabled'] = 'no';
				$error            = $error ?? new WP_Error(
					'hph_no_price',
					/* translators: %s: product name */
					sprintf( __( '%s was not opened to dropshippers because it has no dropshipper price yet.', 'herb-partner-hub' ), $product->get_name() )
				);
			}
		}

		if ( '' === $after['price'] ) {
			$product->delete_meta_data( self::PRICE_META );
		} else {
			$product->update_meta_data( self::PRICE_META, $after['price'] );
		}
		if ( ! $product->is_type( 'variation' ) ) {
			$product->update_meta_data( self::ENABLED_META, '' === $after['enabled'] ? 'no' : $after['enabled'] );
		}

		if ( $before != $after ) { // phpcs:ignore Universal.Operators.StrictComparisons -- '' vs missing meta are the same here.
			HPH_Log::add( 'product_pricing_changed', 'product', $id, $before, $after );
		}
		unset( self::$priced_children[ $product->get_parent_id() ], self::$priced_children[ $id ] );

		return $error ?? array( 'before' => $before, 'after' => $after );
	}

	private static function variable_has_price_after( WC_Product $product ): bool {
		unset( self::$priced_children[ $product->get_id() ] );
		return self::has_priced_variation( $product->get_id() );
	}

	/**
	 * Variable products cache their price ranges in transients. Any change to our meta
	 * must drop them, or dropshippers keep seeing the old range.
	 */
	public static function flush_on_meta_change( $meta_id, $object_id, $meta_key ): void {
		if ( self::PRICE_META !== $meta_key && self::ENABLED_META !== $meta_key ) {
			return;
		}
		$object_id = (int) $object_id;
		$parent    = (int) wp_get_post_parent_id( $object_id );
		wc_delete_product_transients( $object_id );
		if ( $parent ) {
			wc_delete_product_transients( $parent );
		}
		unset( self::$priced_children[ $object_id ], self::$priced_children[ $parent ] );
	}
}
