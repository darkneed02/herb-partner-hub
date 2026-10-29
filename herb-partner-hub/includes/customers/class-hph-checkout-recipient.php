<?php

defined( 'ABSPATH' ) || exit;

/**
 * "Ship this order to" at checkout, for dropshippers only.
 *
 * The box sits above the customer details. Picking a saved customer fills WooCommerce's
 * standard shipping_* fields, so DHL Express Commerce and the shipping manager read the
 * recipient the way they always have — no new meta that they need to know about.
 *
 * An order to an end customer is billed to that customer too (name, address, phone), so
 * the customs declaration names the person abroad who receives it. Only billing_email
 * stays the dropshipper's: the receipt, at wholesale prices, goes to them, never to the
 * end customer. The dropshipper's own billing address in their profile is never changed.
 *
 * The server decides the address, not the browser: on submit the chosen customer is
 * loaded again with the current user as owner and copied over whatever the form posted.
 * That is what stops one dropshipper shipping to another's customer by editing the id,
 * and it also covers a stale form that CartFlows restored from localStorage.
 *
 * update_checkout fires only when the recipient changes, never on page load (the site's
 * autoselect.php recalculates inside woocommerce_after_shipping_rate; an extra refresh
 * on load is what that code is sensitive to).
 *
 * Reorder: WooCommerce's "order again" refills the cart, and this class remembers who the
 * old order went to. The address goes into the checkout session (never the profile), so
 * the page renders with that recipient chosen and the right shipping price, no refresh.
 */
final class HPH_Checkout_Recipient {
	public const FIELD         = 'hph_ship_to';
	public const SAVE_FIELD    = 'hph_save_customer';
	public const META_SHIP_TO  = '_hph_ship_to';
	public const META_CUSTOMER = '_hph_end_customer_id';
	public const SESSION_KEY   = 'hph_reorder_recipient';

	/** The recipient resolved for the order being placed in this request. */
	private ?array $choice = null;

	/** Recipient carried over from "Reorder": array( 'choice', 'order' ) or null. */
	private ?array $reorder = null;

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'adjust_address_book' ), 20 );
		// 25: after CartFlows' modern layout opens its left column (priority 20), so the box sits
		// above the customer details with the order summary beside it, not across the page.
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'render' ), 25 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		// After CartFlows (1000), which rebuilds labels and placeholders from its own field settings.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'shipping_phone_field' ), 1001 );
		add_action( 'woocommerce_before_checkout_shipping_form', array( $this, 'shipping_heading' ), 1 );
		add_filter( 'woocommerce_country_locale_field_selectors', array( $this, 'keep_shipping_phone_required' ) );

		add_action( 'woocommerce_checkout_process', array( $this, 'before_process' ) );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'posted_data' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 20, 2 );
		add_action( 'woocommerce_checkout_update_customer', array( $this, 'keep_own_addresses' ), 20, 2 );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'billing_value' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'create_order' ), 15, 2 );

		add_action( 'woocommerce_ordered_again', array( $this, 'remember_reorder' ) );
		add_filter( 'hph_checkout_default_recipient', array( $this, 'reorder_default' ), 5, 2 );
		add_filter( 'woocommerce_ship_to_different_address_checked', array( $this, 'reorder_ship_different' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'forget_reorder' ) );
		add_action( 'woocommerce_cart_emptied', array( $this, 'forget_reorder' ) );
	}

	private static function applies(): bool {
		return hph_is_dropshipper() && function_exists( 'WC' ) && WC()->cart && WC()->cart->needs_shipping();
	}

	/**
	 * The site's address book (novamira-sandbox/stherb-address-book.php) has a second
	 * picker inside the shipping fields. For a dropshipper that would be a rival
	 * "ship to" control, so it goes; the billing picker stays for their own address.
	 */
	public function adjust_address_book(): void {
		if ( class_exists( 'Stherb_Address_Book' ) && is_checkout() && hph_is_dropshipper() ) {
			remove_action( 'woocommerce_before_checkout_shipping_form', array( 'Stherb_Address_Book', 'selector_shipping' ) );
		}
	}

	/**
	 * Couriers need the recipient's phone. stherb.com already has a shipping phone field;
	 * a site without one gets it, for dropshippers only.
	 */
	public function shipping_phone_field( array $fields ): array {
		if ( ! hph_is_dropshipper() ) {
			return $fields;
		}
		if ( empty( $fields['shipping']['shipping_phone'] ) ) {
			$fields['shipping']['shipping_phone'] = array(
				'type'         => 'tel',
				'label'        => __( 'Phone', 'herb-partner-hub' ),
				'autocomplete' => 'shipping tel',
				'validate'     => array( 'phone' ),
				'priority'     => 100,
			);
		}
		$label = __( 'Recipient phone (for the courier)', 'herb-partner-hub' );
		$fields['shipping']['shipping_phone']['required'] = true;
		$fields['shipping']['shipping_phone']['label']    = $label;
		// CartFlows' modern layout shows the placeholder instead of the label.
		if ( ! empty( $fields['shipping']['shipping_phone']['placeholder'] ) ) {
			$fields['shipping']['shipping_phone']['placeholder'] = $label . ' *';
		}
		return $fields;
	}

	/**
	 * WooCommerce's address-i18n.js rewrites the phone label and required flag from the
	 * country's address rules every time the country changes, which would turn the
	 * recipient phone back into "Phone (optional)". Leave the shipping phone alone.
	 */
	public function keep_shipping_phone_required( array $selectors ): array {
		if ( isset( $selectors['phone'] ) && hph_is_dropshipper() ) {
			$selectors['phone'] = '#billing_phone_field';
		}
		return $selectors;
	}

	/**
	 * WooCommerce's "Ship to a different address?" heading is hidden for dropshippers (the
	 * box decides), so the new customer's fields get a heading of their own, otherwise
	 * they read as more billing fields.
	 */
	public function shipping_heading(): void {
		if ( self::applies() ) {
			echo '<h3 class="hph-ship-heading">' . esc_html__( "New customer's delivery address", 'herb-partner-hub' ) . '</h3>';
		}
	}

	public function enqueue(): void {
		if ( ! is_checkout() || is_wc_endpoint_url() || ! self::applies() ) {
			return;
		}
		HPH_Assets::enqueue();
		wp_enqueue_script( 'hph-checkout-recipient', HPH_URL . 'assets/js/checkout-recipient.js', array( 'jquery' ), (string) filemtime( HPH_PATH . 'assets/js/checkout-recipient.js' ), true );

		$book = array();
		foreach ( HPH_Customer_Repo::all_for_owner( get_current_user_id() ) as $row ) {
			$book[ 'customer-' . $row['id'] ] = array_intersect_key( $row, array_flip( HPH_Customer_Repo::ADDRESS_FIELDS ) );
		}
		$own = self::own_billing( get_current_user_id() );
		unset( $own['email'] );
		wp_localize_script(
			'hph-checkout-recipient',
			'hphRecipients',
			array(
				'book' => $book,
				'self' => $own,
			)
		);
	}

	public function render(): void {
		if ( ! self::applies() ) {
			return;
		}
		$owner     = get_current_user_id();
		$customers = HPH_Customer_Repo::all_for_owner( $owner );
		$stats     = HPH_Customer_Repo::order_stats( $owner );
		$selected  = (string) apply_filters( 'hph_checkout_default_recipient', 'self', $owner );
		$prefilled = null !== $this->reorder && $selected === $this->reorder['choice'] && 'self' !== $selected;
		$email     = self::own_billing( $owner )['email'];

		include locate_template( 'herb-partner-hub/checkout/recipient.php' ) ?: HPH_PATH . 'templates/checkout/recipient.php';
	}

	/**
	 * Runs at the start of the order submit. An order to an end customer must not be
	 * saved into the dropshipper's personal address book (it would then be offered as
	 * their own billing address).
	 */
	public function before_process(): void {
		if ( ! self::applies() ) {
			return;
		}
		$posted = self::posted_choice();
		if ( 'self' !== $posted && class_exists( 'Stherb_Address_Book' ) ) {
			remove_action( 'woocommerce_checkout_create_order', array( 'Stherb_Address_Book', 'maybe_save_after_order' ), 20 );
		}
		self::align_request( $posted );
	}

	/**
	 * WooCommerce decides which address fields are required (a state for Thailand, none
	 * for Singapore) from the countries in $_POST, before woocommerce_checkout_posted_data
	 * runs. So the recipient's address goes into the request itself, or a browser that
	 * still sent the dropshipper's own billing (script failed, old form) would be asked
	 * for a state the customer's country does not have. posted_data() sets the same values.
	 */
	private static function align_request( string $posted ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked the checkout nonce before woocommerce_checkout_process.
		$address = null;
		if ( str_starts_with( $posted, 'customer-' ) ) {
			$row = HPH_Customer_Repo::get( (int) substr( $posted, 9 ), get_current_user_id() );
			if ( $row ) {
				$address = array_intersect_key( $row, array_flip( HPH_Customer_Repo::ADDRESS_FIELDS ) );
				foreach ( $address as $f => $v ) {
					$_POST[ 'shipping_' . $f ] = wp_slash( (string) $v );
				}
			}
		} elseif ( 'new' === $posted ) {
			$address = array();
			foreach ( HPH_Customer_Repo::ADDRESS_FIELDS as $f ) {
				$address[ $f ] = wc_clean( wp_unslash( $_POST[ 'shipping_' . $f ] ?? '' ) );
			}
		}
		if ( null === $address ) {
			return;
		}
		foreach ( $address as $f => $v ) {
			$_POST[ 'billing_' . $f ] = wp_slash( (string) $v );
		}
		// phpcs:enable
	}

	/**
	 * Resolve the recipient and make the posted data say the same, before WooCommerce
	 * stores it in the session, recalculates shipping and validates.
	 */
	public function posted_data( array $data ): array {
		if ( ! self::applies() ) {
			return $data;
		}
		$posted       = self::posted_choice();
		$this->choice = array(
			'type' => 'self',
			'row'  => null,
		);

		if ( str_starts_with( $posted, 'customer-' ) ) {
			$row = HPH_Customer_Repo::get( (int) substr( $posted, 9 ), get_current_user_id() );
			$this->choice = array(
				'type' => $row ? 'customer' : 'invalid',
				'row'  => $row,
			);
			if ( $row ) {
				$data['ship_to_different_address'] = 1;
				foreach ( HPH_Customer_Repo::ADDRESS_FIELDS as $f ) {
					$data[ 'shipping_' . $f ] = (string) $row[ $f ];
					$data[ 'billing_' . $f ]  = (string) $row[ $f ];
				}
			}
		} elseif ( 'new' === $posted ) {
			$this->choice['type']              = 'new';
			$data['ship_to_different_address'] = 1;
			if ( ! isset( $data['shipping_phone'] ) ) {
				$data['shipping_phone'] = wc_clean( wp_unslash( $_POST['shipping_phone'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked the checkout nonce.
			}
			foreach ( HPH_Customer_Repo::ADDRESS_FIELDS as $f ) {
				$data[ 'billing_' . $f ] = (string) ( $data[ 'shipping_' . $f ] ?? '' );
			}
		} elseif ( 'self' === $posted ) {
			$data['ship_to_different_address'] = 0;
			foreach ( HPH_Customer_Repo::ADDRESS_FIELDS as $f ) {
				$data[ 'shipping_' . $f ] = (string) ( $data[ 'billing_' . $f ] ?? '' );
			}
		} else {
			$this->choice['type'] = 'missing';
		}

		return $data;
	}

	public function validate( array $data, WP_Error $errors ): void {
		if ( ! self::applies() || null === $this->choice ) {
			return;
		}
		switch ( $this->choice['type'] ) {
			case 'missing':
				$errors->add( 'hph_ship_to', __( 'Choose who this order ships to.', 'herb-partner-hub' ) );
				break;
			case 'invalid':
				$errors->add( 'hph_ship_to', __( 'That customer is not in your list any more. Please choose the recipient again.', 'herb-partner-hub' ) );
				break;
			case 'new':
				if ( '' === trim( (string) ( $data['shipping_phone'] ?? '' ) ) ) {
					$errors->add( 'shipping_phone', __( 'Please enter the recipient phone — the courier uses it to reach your customer.', 'herb-partner-hub' ) );
				}
				break;
		}
	}

	/**
	 * WooCommerce copies the posted billing and shipping addresses onto the user's profile.
	 * For an order to an end customer that would make the next checkout prefill someone
	 * else's address as the dropshipper's own, and the Overview and order exports read the
	 * shop name and country from the profile. So the profile keeps its saved addresses.
	 */
	public function keep_own_addresses( WC_Customer $customer, array $data ): void {
		if ( null === $this->choice || in_array( $this->choice['type'], array( 'self', 'missing' ), true ) ) {
			return;
		}
		$user_id = $customer->get_id();
		foreach ( array( 'shipping', 'billing' ) as $type ) {
			foreach ( HPH_Customer_Repo::ADDRESS_FIELDS as $f ) {
				$setter = 'set_' . $type . '_' . $f;
				if ( is_callable( array( $customer, $setter ) ) ) {
					$customer->$setter( (string) get_user_meta( $user_id, $type . '_' . $f, true ) );
				}
			}
		}
	}

	/**
	 * Billing fields as the checkout page first renders them. The session's billing address
	 * may still be the last end customer's (WooCommerce stores the location on every price
	 * refresh), so render from the choice the box starts with: the dropshipper's own profile
	 * for "Ship to myself", the customer's for a Reorder to them. Email is never touched.
	 * Page render only: on submit and on price refreshes WooCommerce reads the billing
	 * country back through get_value() to decide which fields are required, and it has to
	 * get the posted one (a customer in Singapore has no state; Thailand requires one).
	 */
	public function billing_value( $value, $input ) {
		if ( null !== $value || ! is_string( $input ) || ! str_starts_with( $input, 'billing_' ) || 'billing_email' === $input ) {
			return $value;
		}
		if ( wp_doing_ajax() || did_action( 'woocommerce_before_checkout_process' ) || ! self::applies() ) {
			return $value;
		}
		$field = substr( $input, 8 );
		if ( ! in_array( $field, HPH_Customer_Repo::ADDRESS_FIELDS, true ) ) {
			return $value;
		}
		$owner    = get_current_user_id();
		$selected = (string) apply_filters( 'hph_checkout_default_recipient', 'self', $owner );

		if ( str_starts_with( $selected, 'customer-' ) ) {
			$row = HPH_Customer_Repo::get( (int) substr( $selected, 9 ), $owner );
			return $row ? (string) $row[ $field ] : $value;
		}
		if ( 'new' === $selected && WC()->customer ) {
			$getter = 'get_shipping_' . $field;
			return is_callable( array( WC()->customer, $getter ) ) ? (string) WC()->customer->$getter() : $value;
		}
		return self::own_billing( $owner )[ $field ] ?? $value;
	}

	/** The dropshipper's own billing details from their profile, plus the receipt email. */
	private static function own_billing( int $user_id ): array {
		$out = array();
		foreach ( HPH_Customer_Repo::ADDRESS_FIELDS as $f ) {
			$out[ $f ] = (string) get_user_meta( $user_id, 'billing_' . $f, true );
		}
		$user         = get_userdata( $user_id );
		$out['email'] = (string) get_user_meta( $user_id, 'billing_email', true ) ?: ( $user ? $user->user_email : '' );
		return $out;
	}

	public function create_order( WC_Order $order, array $data ): void {
		if ( null === $this->choice || ! hph_is_dropshipper() ) {
			return;
		}
		$type = $this->choice['type'];
		$row  = $this->choice['row'];

		if ( 'new' === $type ) {
			$input = array();
			foreach ( HPH_Customer_Repo::ADDRESS_FIELDS as $f ) {
				$input[ $f ] = (string) ( $data[ 'shipping_' . $f ] ?? '' );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked the checkout nonce.
			if ( ! empty( $_POST[ self::SAVE_FIELD ] ) ) {
				$row = self::find_same( $order->get_customer_id(), $input );
				if ( ! $row ) {
					$id  = HPH_Customer_Repo::insert( $order->get_customer_id(), $input );
					$row = is_int( $id ) ? HPH_Customer_Repo::get( $id, $order->get_customer_id() ) : null;
				}
			}
			$order->set_shipping_phone( $input['phone'] );
		}

		if ( 'customer' === $type && $row ) {
			foreach ( HPH_Customer_Repo::ADDRESS_FIELDS as $f ) {
				$order->{'set_shipping_' . $f}( (string) $row[ $f ] );
			}
		}

		$order->update_meta_data( self::META_SHIP_TO, 'self' === $type ? 'self' : 'customer' );
		if ( $row ) {
			$order->update_meta_data( self::META_CUSTOMER, (int) $row['id'] );
		} else {
			$order->delete_meta_data( self::META_CUSTOMER );
		}
	}

	/**
	 * A retried checkout (failed payment, double click) must not save the same new
	 * customer twice.
	 */
	private static function find_same( int $owner, array $input ): ?array {
		$clean = HPH_Customer_Repo::clean( $input );
		foreach ( HPH_Customer_Repo::all_for_owner( $owner ) as $row ) {
			$same = true;
			foreach ( array( 'first_name', 'last_name', 'address_1', 'postcode', 'country' ) as $f ) {
				if ( 0 !== strcasecmp( trim( (string) $row[ $f ] ), trim( $clean[ $f ] ) ) ) {
					$same = false;
					break;
				}
			}
			if ( $same ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * After "Reorder" refilled the cart: choose the same recipient at checkout.
	 * - saved customer, still in the list → that customer, address put in the session
	 * - new customer that was not saved   → "New customer" with the old address filled in
	 * - saved customer since deleted      → nothing chosen, so the dropshipper picks again
	 * - shipped to themselves              → "Ship to myself" (the normal default)
	 */
	public function remember_reorder( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! hph_is_dropshipper() || $order->get_customer_id() !== get_current_user_id() || ! WC()->session || ! WC()->customer ) {
			return;
		}
		$type = (string) $order->get_meta( self::META_SHIP_TO );
		if ( '' === $type ) {
			return;
		}

		$choice  = 'self';
		$address = null;
		if ( 'customer' === $type ) {
			$cid = (int) $order->get_meta( self::META_CUSTOMER );
			if ( $cid ) {
				$row = HPH_Customer_Repo::get( $cid, get_current_user_id() );
				if ( $row ) {
					$choice  = 'customer-' . $cid;
					$address = $row;
				} else {
					$choice = '';
					wc_add_notice(
						/* translators: %s: order number */
						sprintf( __( 'Order #%s went to a customer who is no longer in My Customers. Choose who this order ships to at checkout.', 'herb-partner-hub' ), $order->get_order_number() ),
						'notice'
					);
				}
			} else {
				$choice  = 'new';
				$address = array();
				foreach ( HPH_Customer_Repo::ADDRESS_FIELDS as $f ) {
					$getter        = 'get_shipping_' . $f;
					$address[ $f ] = is_callable( array( $order, $getter ) ) ? (string) $order->$getter() : '';
				}
			}
		}

		if ( $address ) {
			// The session customer only; WC_Customer saves nothing to the profile from here.
			foreach ( HPH_Customer_Repo::ADDRESS_FIELDS as $f ) {
				$setter = 'set_shipping_' . $f;
				if ( is_callable( array( WC()->customer, $setter ) ) ) {
					WC()->customer->$setter( (string) ( $address[ $f ] ?? '' ) );
				}
			}
		}

		WC()->session->set(
			self::SESSION_KEY,
			array(
				'choice' => $choice,
				'order'  => $order->get_id(),
			)
		);
	}

	private function reorder_state(): ?array {
		if ( null === $this->reorder && WC()->session ) {
			$saved         = WC()->session->get( self::SESSION_KEY );
			$this->reorder = is_array( $saved ) && isset( $saved['choice'] ) ? $saved : null;
		}
		return $this->reorder;
	}

	public function reorder_default( string $selected, int $owner ): string {
		$state = $this->reorder_state();
		if ( ! $state ) {
			return $selected;
		}
		// The customer may have been deleted between "Reorder" and opening checkout.
		if ( str_starts_with( $state['choice'], 'customer-' ) && ! HPH_Customer_Repo::get( (int) substr( $state['choice'], 9 ), $owner ) ) {
			return '';
		}
		return $state['choice'];
	}

	/** Server-side, so the shipping price on first render is already the recipient's. */
	public function reorder_ship_different( $checked ) {
		$state = self::applies() ? $this->reorder_state() : null;
		if ( $state && ( 'new' === $state['choice'] || str_starts_with( $state['choice'], 'customer-' ) ) ) {
			return true;
		}
		return $checked;
	}

	public function forget_reorder(): void {
		$this->reorder = null;
		if ( WC()->session ) {
			WC()->session->__unset( self::SESSION_KEY );
		}
	}

	private static function posted_choice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checks the checkout nonce before these hooks.
		return isset( $_POST[ self::FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
	}
}
