<?php
/**
 * Module M3: no site promotion or coupon ever applies to a dropshipper (dq5-a).
 * Dropshipper prices are already low; a sale on top would sell at a loss.
 *
 * Nothing here edits the other plugins. Each block uses a filter the plugin itself
 * offers, so on a site without that plugin the filter simply never runs.
 *
 * | Source                  | How                                                                 |
 * |-------------------------|---------------------------------------------------------------------|
 * | Stherb Promo Engine     | its settings read as "off" (base + campaign + preview + banners)     |
 * |   + BF 2026 connector   |   the connector and home promo slide follow the engine, so they hide |
 * | Woo Discount Rules      | no rule matches a product, cart rules don't run, no discount price  |
 * | Tier Pricing Table      | empty tier rules, no role/global price, no tier table (as its own   |
 * |                         | layout preview does)                                                |
 * | Coupons / Power Coupons | coupons switched off and every coupon invalid                       |
 */

defined( 'ABSPATH' ) || exit;

final class HPH_Discounts {
	private const LAST = PHP_INT_MAX;

	public function __construct() {
		add_filter( 'woocommerce_coupons_enabled', array( $this, 'off' ), self::LAST );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'off' ), self::LAST );

		add_filter( 'option_stherb_promo_settings', array( $this, 'promo_engine_settings' ), self::LAST );

		add_filter( 'advanced_woo_discount_rules_filter_passed', array( $this, 'off' ), self::LAST );
		add_filter( 'advanced_woo_discount_rules_run_discount_rules', array( $this, 'off' ), self::LAST );
		add_filter( 'advanced_woo_discount_rules_calculate_discount_for_cart_item', array( $this, 'off' ), self::LAST );
		add_filter( 'advanced_woo_discount_rules_discount_prices_of_product', array( $this, 'off' ), self::LAST );

		add_filter( 'tiered_pricing_table/price/pricing_rule', array( $this, 'tier_pricing_rule' ), self::LAST );
		add_filter( 'tiered_pricing_table/services/regular_pricing/price', array( $this, 'tier_regular_price' ), self::LAST );
		add_filter( 'tiered_pricing_table/should_render_pricing_table', array( $this, 'off' ), self::LAST );
	}

	/**
	 * False for dropshippers, the original value for everyone else.
	 */
	public function off( $value ) {
		return hph_is_dropshipper() ? false : $value;
	}

	public function promo_engine_settings( $settings ) {
		if ( ! is_array( $settings ) || ! hph_is_dropshipper() ) {
			return $settings;
		}
		$settings['enabled']              = 0;
		$settings['product_hint_enabled'] = 0;
		if ( isset( $settings['campaign'] ) && is_array( $settings['campaign'] ) ) {
			$settings['campaign']['enabled']       = 0;
			$settings['campaign']['preview_token'] = '';
		}
		if ( isset( $settings['placements'] ) && is_array( $settings['placements'] ) ) {
			foreach ( $settings['placements'] as $slug => $placement ) {
				if ( is_array( $placement ) ) {
					$settings['placements'][ $slug ]['enabled'] = 0;
				}
			}
		}
		return $settings;
	}

	public function tier_pricing_rule( $rule ) {
		if ( is_object( $rule ) && method_exists( $rule, 'setRules' ) && hph_is_dropshipper() ) {
			$rule->setRules( array() );
			if ( method_exists( $rule, 'setMinimum' ) ) {
				$rule->setMinimum( null );
			}
		}
		return $rule;
	}

	public function tier_regular_price( $price ) {
		return hph_is_dropshipper() ? null : $price;
	}
}
