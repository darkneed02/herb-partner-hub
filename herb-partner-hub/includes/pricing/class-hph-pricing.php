<?php
/**
 * Module M2: dropshipper prices and participating products.
 *
 * For a dropshipper (hph_is_dropshipper()) on the storefront:
 * - participating products cost `_hph_price` everywhere (regular = price, no sale price),
 *   shown large in the accent colour with "Retail $X" struck through beside it, and a
 *   bar on every shop page says promotions, coupons and gifts do not apply
 * - every other product is hidden from listings and search, 404s on its own page, and
 *   cannot be bought, not even with a direct ?add-to-cart= link
 * Nobody else is affected, admins included (they use "View as dropshipper").
 *
 * Price filters run at 1000, after stherb-affiliate-butea-pricing.php (999), so during
 * the switch-over a dropshipper who still has the old affiliate role gets our price.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-hph-catalog.php';

final class HPH_Pricing {
	private const PRIORITY = 1000;

	public function __construct() {
		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_get_regular_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_variation_get_regular_price' ) as $hook ) {
			add_filter( $hook, array( $this, 'price' ), self::PRIORITY, 2 );
		}
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'sale_price' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'sale_price' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'variation_range_price' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( $this, 'variation_range_price' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'variation_range_sale_price' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'variation_prices_hash' ), self::PRIORITY );
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), self::PRIORITY, 2 );

		add_filter( 'woocommerce_is_purchasable', array( $this, 'purchasable' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'purchasable' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_variation_is_visible', array( $this, 'variation_visible' ), self::PRIORITY, 4 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), self::PRIORITY, 4 );
		add_filter( 'woocommerce_product_is_visible', array( $this, 'visible' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_related_products', array( $this, 'related' ), self::PRIORITY );
		add_action( 'pre_get_posts', array( $this, 'filter_product_queries' ), self::PRIORITY );

		add_action( 'woocommerce_checkout_create_order', array( $this, 'mark_order' ), 10 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'wp_body_open', array( $this, 'dropshipper_bar' ), 5 );

		add_action( 'added_post_meta', array( 'HPH_Catalog', 'flush_on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( 'HPH_Catalog', 'flush_on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( 'HPH_Catalog', 'flush_on_meta_change' ), 10, 3 );

		if ( is_admin() ) {
			require_once __DIR__ . '/class-hph-product-fields.php';
			require_once __DIR__ . '/class-hph-products-admin.php';
			new HPH_Product_Fields();
			new HPH_Products_Admin();
		}
	}

	public function price( $price, $product ) {
		if ( ! $product instanceof WC_Product || ! hph_is_dropshipper() ) {
			return $price;
		}
		$wholesale = HPH_Catalog::wholesale_price( $product );
		return null === $wholesale ? $price : wc_format_decimal( $wholesale );
	}

	public function sale_price( $price, $product ) {
		if ( ! $product instanceof WC_Product || ! hph_is_dropshipper() ) {
			return $price;
		}
		return null === HPH_Catalog::wholesale_price( $product ) ? $price : '';
	}

	/**
	 * Price ranges of variable products. An empty price leaves the variation out of the range.
	 */
	public function variation_range_price( $price, $variation ) {
		if ( ! hph_is_dropshipper() || ! $variation instanceof WC_Product ) {
			return $price;
		}
		$wholesale = HPH_Catalog::wholesale_price( $variation );
		return null === $wholesale ? '' : wc_format_decimal( $wholesale );
	}

	public function variation_range_sale_price( $price, $variation ) {
		return hph_is_dropshipper() ? '' : $price;
	}

	/**
	 * WooCommerce caches variation price ranges per hash; dropshippers need their own entry.
	 */
	public function variation_prices_hash( $hash ) {
		if ( hph_is_dropshipper() ) {
			$hash[] = 'hph-dropshipper';
		}
		return $hash;
	}

	public function price_html( $html, $product ) {
		if ( ! $product instanceof WC_Product || ! hph_is_dropshipper() ) {
			return $html;
		}

		if ( $product->is_type( 'variable' ) ) {
			$ws     = array();
			$retail = array();
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				$price = $child ? HPH_Catalog::wholesale_price( $child ) : null;
				if ( null !== $price ) {
					$ws[]     = wc_get_price_to_display( $child, array( 'price' => $price ) );
					$retail[] = wc_get_price_to_display( $child, array( 'price' => (float) $child->get_regular_price( 'edit' ) ) );
				}
			}
			return $ws ? self::price_markup( self::range( $ws ), self::range( $retail ), min( $ws ) < min( $retail ) ) : $html;
		}

		$wholesale = HPH_Catalog::wholesale_price( $product );
		if ( null === $wholesale ) {
			return $html;
		}
		$retail = (float) $product->get_regular_price( 'edit' );
		return self::price_markup(
			wc_price( wc_get_price_to_display( $product, array( 'price' => $wholesale ) ) ) . $product->get_price_suffix(),
			wc_price( wc_get_price_to_display( $product, array( 'price' => $retail ) ) ),
			$retail > $wholesale
		);
	}

	private static function range( array $prices ): string {
		$min = min( $prices );
		$max = max( $prices );
		return $min === $max ? wc_price( $min ) : wc_format_price_range( $min, $max );
	}

	private static function price_markup( string $wholesale_html, string $retail_html, bool $show_retail ): string {
		$html = '<span class="hph-price"><span class="hph-price__ws">' . $wholesale_html . '</span>';
		if ( $show_retail ) {
			/* translators: %s: retail price */
			$html .= '<span class="hph-price__retail">' . sprintf( __( 'Retail %s', 'herb-partner-hub' ), $retail_html ) . '</span>';
		}
		return $html . '</span>';
	}

	public function enqueue(): void {
		if ( hph_is_dropshipper() ) {
			HPH_Assets::enqueue();
		}
	}

	/**
	 * Thin bar at the top of shop, product, cart and checkout pages, so nobody wonders
	 * why a running sale does not show up for them.
	 */
	public function dropshipper_bar(): void {
		if ( ! hph_is_dropshipper() || ! ( is_woocommerce() || is_cart() || is_checkout() ) ) {
			return;
		}
		echo '<div class="hph-bar" role="note"><span class="hph-tag">' . esc_html__( 'DROPSHIPPER', 'herb-partner-hub' ) . '</span>'
			. wp_kses( __( 'You are viewing <b>dropshipper wholesale prices</b>. Store promotions, coupons and free gifts do not apply to wholesale orders.', 'herb-partner-hub' ), array( 'b' => array() ) )
			. '</div>';
	}

	public function purchasable( $purchasable, $product ) {
		if ( ! $purchasable || ! $product instanceof WC_Product || ! hph_is_dropshipper() ) {
			return $purchasable;
		}
		return HPH_Catalog::is_participating( $product );
	}

	public function variation_visible( $visible, $variation_id, $parent_id, $variation ) {
		if ( ! $visible || ! hph_is_dropshipper() || ! $variation instanceof WC_Product ) {
			return $visible;
		}
		return HPH_Catalog::is_participating( $variation );
	}

	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
		if ( ! $passed || ! hph_is_dropshipper() ) {
			return $passed;
		}
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product || ! HPH_Catalog::is_participating( $product ) ) {
			wc_add_notice( __( 'This product is not available with dropshipper prices.', 'herb-partner-hub' ), 'error' );
			return false;
		}
		return $passed;
	}

	public function visible( $visible, $product_id ) {
		if ( ! $visible || ! hph_is_dropshipper() ) {
			return $visible;
		}
		$product = wc_get_product( $product_id );
		return $product && HPH_Catalog::is_participating( $product );
	}

	public function related( $ids ) {
		if ( ! hph_is_dropshipper() ) {
			return $ids;
		}
		return array_values(
			array_filter(
				(array) $ids,
				static function ( $id ) {
					$product = wc_get_product( $id );
					return $product && HPH_Catalog::is_participating( $product );
				}
			)
		);
	}

	/**
	 * Any storefront query for products only (shop, categories, search inside the shop,
	 * shortcodes, theme sections, a single product page) gets `_hph_enabled = yes`.
	 * Queries that also include other post types, or variations, are left alone.
	 * Internal lookups can opt out with 'hph_skip' => true.
	 */
	public function filter_product_queries( WP_Query $query ): void {
		if ( $query->get( 'hph_skip' ) || ! hph_is_dropshipper() ) {
			return;
		}
		$types = array_filter( (array) $query->get( 'post_type' ) );
		if ( $types ) {
			if ( array( 'product' ) !== array_values( array_unique( $types ) ) ) {
				return;
			}
		} elseif ( ! $query->is_tax( get_object_taxonomies( 'product' ) ) ) {
			return;
		}

		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'key'   => HPH_Catalog::ENABLED_META,
			'value' => 'yes',
		);
		$query->set( 'meta_query', $meta );
	}

	/**
	 * Remember who ordered at dropshipper prices. The order keeps it even if the role is removed later.
	 */
	public function mark_order( WC_Order $order ): void {
		if ( hph_is_dropshipper() ) {
			$order->update_meta_data( '_hph_dropshipper_id', get_current_user_id() );
			$order->update_meta_data( '_hph_price_list', 'default' );
		}
	}
}
