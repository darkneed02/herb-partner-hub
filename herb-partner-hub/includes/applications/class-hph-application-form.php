<?php

defined( 'ABSPATH' ) || exit;

/**
 * [herb_partner_apply] — the public application form.
 *
 * Fields mirror the WPForms form #10091 it replaces, except "Country / market" is a
 * country list instead of free text, so the country can prefill billing on approval.
 *
 * Logged-out visitors get no nonce check: /dropshipper/ is page-cached for them, and a
 * cached nonce would expire and block real applicants. A honeypot, a JS-set field and a
 * per-IP limit stand in for it. Logged-in users (never cached) are nonce-checked.
 */
final class HPH_Application_Form {
	public const SHORTCODE   = 'herb_partner_apply';
	public const ACTION      = 'hph_apply';
	public const RATE_LIMIT  = 5; // submissions per IP per hour
	public const CHANNELS    = array( 'Facebook', 'TikTok', 'Instagram', 'Shopee / Marketplace', 'Website', 'Offline / Retail', 'Not selling yet' );

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Attributes: title and intro ('' hides them), style="plain" drops the cream panel
	 * when the form already sits inside a card of the theme.
	 */
	public function render( $atts = array() ): string {
		HPH_Assets::enqueue();
		$atts = shortcode_atts(
			array(
				'title' => __( 'Apply to become a Dropshipper', 'herb-partner-hub' ),
				'intro' => __( 'We review every application and email you once it is approved.', 'herb-partner-hub' ),
				'style' => 'panel',
			),
			$atts,
			self::SHORTCODE
		);

		$user = wp_get_current_user();
		if ( $user->exists() && HPH_Context::is_partner( $user ) ) {
			return $this->notice(
				sprintf(
					/* translators: %s: My Account URL */
					__( 'Your account is already an approved dropshipper. <a href="%s">Go to My Account</a>.', 'herb-partner-hub' ),
					esc_url( wc_get_page_permalink( 'myaccount' ) )
				),
				'success'
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only, after our own redirect.
		if ( isset( $_GET['hph_applied'] ) ) {
			return $this->notice( __( '<strong>Application received.</strong> Thank you for applying to become a dropship partner. Our team will review your information and contact you by email.', 'herb-partner-hub' ), 'success' );
		}

		$state  = array();
		$token  = isset( $_GET['hph_apply'] ) ? sanitize_key( wp_unslash( $_GET['hph_apply'] ) ) : '';
		// phpcs:enable
		if ( $token ) {
			$state = get_transient( 'hph_apply_' . $token ) ?: array();
		}
		$errors = $state['errors'] ?? array();
		$old    = $state['old'] ?? array();

		if ( $user->exists() ) {
			$open = HPH_Application_Repo::find_open_by_email( $user->user_email );
			if ( $open ) {
				return $this->notice(
					sprintf(
						/* translators: %s: date the application was received */
						__( 'We received your application on %s. Our team will contact you by email.', 'herb-partner-hub' ),
						esc_html( wp_date( get_option( 'date_format' ), strtotime( $open['created_at'] . ' UTC' ) ) )
					),
					'info'
				);
			}
			$old += array(
				'name'  => trim( $user->first_name . ' ' . $user->last_name ),
				'email' => $user->user_email,
				'phone' => get_user_meta( $user->ID, 'billing_phone', true ),
			);
		}

		ob_start();
		include locate_template( 'herb-partner-hub/form/apply.php' ) ?: HPH_PATH . 'templates/form/apply.php';
		return (string) ob_get_clean();
	}

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked below for logged-in users; see class comment.
		$input  = wp_unslash( $_POST );
		// phpcs:enable
		// wp_validate_redirect() returns '' (not the fallback) for an empty value.
		$return = wp_validate_redirect( (string) ( $input['hph_return'] ?? '' ), '' ) ?: home_url( '/' );

		if ( is_user_logged_in() && ! wp_verify_nonce( (string) ( $input['_hph_nonce'] ?? '' ), self::ACTION ) ) {
			$this->back_with_errors( $return, array( 'form' => __( 'Your session expired. Please submit the form again.', 'herb-partner-hub' ) ), $input );
		}

		// Bots: pretend it worked, store nothing.
		if ( '' !== (string) ( $input['hph_website'] ?? '' ) || '1' !== (string) ( $input['hph_js'] ?? '' ) ) {
			$this->redirect( add_query_arg( 'hph_applied', '1', $return ) );
		}

		$rate_key = 'hph_apply_rl_' . HPH_Log::ip_hash();
		$count    = (int) get_transient( $rate_key );
		if ( $count >= self::RATE_LIMIT ) {
			$this->back_with_errors( $return, array( 'form' => __( 'Too many applications from this connection. Please try again later.', 'herb-partner-hub' ) ), $input );
		}

		$result = self::submit( $input );
		if ( isset( $result['errors'] ) ) {
			$this->back_with_errors( $return, $result['errors'], $input );
		}

		set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );
		$this->redirect( add_query_arg( 'hph_applied', '1', $return ) );
	}

	/**
	 * Validate and store one application. Returns array( 'id' => int ) or array( 'errors' => field => message ).
	 * No HTTP here, so tests can call it directly.
	 */
	public static function submit( array $input ): array {
		$user   = wp_get_current_user();
		$values = array(
			'name'           => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
			'email'          => $user->exists() ? $user->user_email : sanitize_email( (string) ( $input['email'] ?? '' ) ),
			'phone'          => sanitize_text_field( (string) ( $input['phone'] ?? '' ) ),
			'sales_channels' => array_values( array_intersect( self::CHANNELS, array_map( 'sanitize_text_field', (array) ( $input['sales_channels'] ?? array() ) ) ) ),
			'store_name'     => sanitize_text_field( (string) ( $input['store_name'] ?? '' ) ),
			'channel_url'    => esc_url_raw( trim( (string) ( $input['channel_url'] ?? '' ) ), array( 'http', 'https' ) ),
			'country'        => strtoupper( sanitize_text_field( (string) ( $input['country'] ?? '' ) ) ),
			'contact'        => sanitize_text_field( (string) ( $input['contact'] ?? '' ) ),
			'experience'     => sanitize_textarea_field( (string) ( $input['experience'] ?? '' ) ),
			'agree'          => ! empty( $input['agree'] ),
		);

		$errors = array();
		$need   = __( 'This field is required.', 'herb-partner-hub' );
		foreach ( array( 'name', 'phone', 'store_name', 'contact' ) as $field ) {
			if ( '' === $values[ $field ] ) {
				$errors[ $field ] = $need;
			} elseif ( mb_strlen( $values[ $field ] ) > 190 ) {
				$errors[ $field ] = __( 'Please keep this under 190 characters.', 'herb-partner-hub' );
			}
		}
		if ( ! is_email( $values['email'] ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'herb-partner-hub' );
		}
		if ( ! $values['sales_channels'] ) {
			$errors['sales_channels'] = __( 'Please choose at least one.', 'herb-partner-hub' );
		}
		if ( '' !== trim( (string) ( $input['channel_url'] ?? '' ) ) && '' === $values['channel_url'] ) {
			$errors['channel_url'] = __( 'Please enter a full link starting with http:// or https://', 'herb-partner-hub' );
		}
		if ( ! array_key_exists( $values['country'], WC()->countries->get_countries() ) ) {
			$errors['country'] = __( 'Please choose a country.', 'herb-partner-hub' );
		}
		if ( mb_strlen( $values['experience'] ) > 2000 ) {
			$errors['experience'] = __( 'Please keep this under 2,000 characters.', 'herb-partner-hub' );
		}
		if ( ! $values['agree'] ) {
			$errors['agree'] = __( 'Please accept the terms to apply.', 'herb-partner-hub' );
		}

		if ( ! isset( $errors['email'] ) ) {
			$owner = get_user_by( 'email', $values['email'] );
			if ( $owner && ( ! $user->exists() || $owner->ID !== $user->ID ) ) {
				$errors['email'] = sprintf(
					/* translators: %s: My Account URL */
					__( 'An account already exists for this email. Please <a href="%s">log in</a> first, then apply.', 'herb-partner-hub' ),
					esc_url( wc_get_page_permalink( 'myaccount' ) )
				);
			} elseif ( $owner && HPH_Context::is_partner( $owner ) ) {
				$errors['email'] = __( 'This account is already an approved dropshipper.', 'herb-partner-hub' );
			} elseif ( HPH_Application_Repo::find_open_by_email( $values['email'] ) ) {
				$errors['email'] = __( 'We already have an application for this email. Our team will contact you.', 'herb-partner-hub' );
			}
		}

		if ( $errors ) {
			return array( 'errors' => $errors );
		}

		$id = HPH_Application_Repo::insert(
			array(
				'email'         => $values['email'],
				'name'          => $values['name'],
				'business_name' => $values['store_name'],
				'country'       => $values['country'],
				'phone'         => $values['phone'],
				'message'       => $values['experience'],
				'fields'        => array(
					'sales_channels' => $values['sales_channels'],
					'channel_url'    => $values['channel_url'],
					'contact'        => $values['contact'],
				),
				'terms_version' => (string) HPH_Settings::get( 'terms_version' ),
				'ip_hash'       => HPH_Log::ip_hash(),
				'user_id'       => $user->exists() ? $user->ID : null,
			)
		);

		if ( ! $id ) {
			return array( 'errors' => array( 'form' => __( 'Sorry, something went wrong. Please try again.', 'herb-partner-hub' ) ) );
		}

		HPH_Log::add( 'application_submitted', 'application', $id, null, array( 'email' => $values['email'] ) );
		do_action( 'hph_application_submitted', $id );

		return array( 'id' => $id );
	}

	public static function terms_url(): string {
		$page = (int) HPH_Settings::get( 'terms_page_id' );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			return (string) get_permalink( $page );
		}
		return (string) get_privacy_policy_url();
	}

	private function back_with_errors( string $return, array $errors, array $input ): void {
		$token = strtolower( wp_generate_password( 12, false ) );
		unset( $input['_hph_nonce'], $input['hph_website'], $input['hph_js'] );
		set_transient( 'hph_apply_' . $token, array( 'errors' => $errors, 'old' => $input ), 10 * MINUTE_IN_SECONDS );
		$this->redirect( add_query_arg( 'hph_apply', $token, $return ) );
	}

	private function redirect( string $url ): void {
		wp_safe_redirect( $url . '#hph-apply' );
		exit;
	}

	private function notice( string $html, string $type ): string {
		return '<div class="hph" id="hph-apply"><div class="hph-notice hph-notice--' . esc_attr( $type ) . '">' . wp_kses_post( $html ) . '</div></div>';
	}
}
