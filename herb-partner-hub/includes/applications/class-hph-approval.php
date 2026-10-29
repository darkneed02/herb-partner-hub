<?php

defined( 'ABSPATH' ) || exit;

/**
 * Approve / ask for info / reject, and the set-password step for new accounts.
 */
final class HPH_Approval {
	public const SETPW_META = '_hph_setpw_until';
	public const SETPW_DAYS = 7;

	public function __construct() {
		add_filter( 'password_reset_expiration', array( $this, 'extend_reset_expiration' ) );
		add_action( 'woocommerce_customer_reset_password', array( $this, 'after_set_password' ), 5 );
		add_filter( 'wc_get_template', array( $this, 'set_password_template' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_on_reset_page' ) );
	}

	/**
	 * Load the stylesheet in the head of the reset page; deciding inside the template
	 * would print it in the footer and flash an unstyled form first.
	 */
	public function enqueue_on_reset_page(): void {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'lost-password' ) ) {
			HPH_Assets::enqueue();
		}
	}

	/**
	 * Newly approved dropshippers get the plugin's "Set your new password" page;
	 * everyone else keeps WooCommerce's reset form.
	 */
	public function set_password_template( $template, $template_name, $args ) {
		if ( 'myaccount/form-reset-password.php' !== $template_name || empty( $args['login'] ) ) {
			return $template;
		}
		$user = get_user_by( 'login', (string) $args['login'] );
		if ( ! $user || '' === (string) get_user_meta( $user->ID, self::SETPW_META, true ) ) {
			return $template;
		}
		return locate_template( 'herb-partner-hub/myaccount/form-set-password.php' ) ?: HPH_PATH . 'templates/myaccount/form-set-password.php';
	}

	/**
	 * The account an application belongs to: the user who applied while logged in,
	 * otherwise whoever owns the email now.
	 */
	public static function account_for( array $application ): ?WP_User {
		if ( ! empty( $application['user_id'] ) ) {
			$user = get_userdata( (int) $application['user_id'] );
			if ( $user ) {
				return $user;
			}
		}
		$user = get_user_by( 'email', $application['email'] );
		return $user ?: null;
	}

	/**
	 * Returns the dropshipper's user ID, or WP_Error. Adds the role to an existing account
	 * or creates a customer account (without WooCommerce's own welcome email).
	 */
	public static function approve( int $application_id, string $note = '', bool $notify = true ) {
		$app = HPH_Application_Repo::get( $application_id );
		if ( ! $app ) {
			return new WP_Error( 'hph_not_found', __( 'Application not found.', 'herb-partner-hub' ) );
		}
		if ( ! in_array( $app['status'], HPH_Application_Repo::OPEN, true ) ) {
			return new WP_Error( 'hph_already_decided', __( 'This application has already been decided.', 'herb-partner-hub' ) );
		}

		$user        = self::account_for( $app );
		$new_account = false;

		if ( $user && user_can( $user, 'hph_manage' ) ) {
			return new WP_Error( 'hph_staff_account', __( 'This email belongs to a staff account. Staff cannot be dropshippers; use "View as dropshipper" instead.', 'herb-partner-hub' ) );
		}

		if ( $user ) {
			if ( ! in_array( HPH_Install::ROLE, (array) $user->roles, true ) ) {
				$user->add_role( HPH_Install::ROLE );
			}
		} else {
			$names = preg_split( '/\s+/', trim( $app['name'] ), 2 );
			$args  = array(
				'first_name' => $names[0] ?? '',
				'last_name'  => $names[1] ?? '',
			);

			add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
			$user_id = wc_create_new_customer( $app['email'], '', wp_generate_password( 24 ), $args );
			remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			$user = new WP_User( $user_id );
			$user->add_role( HPH_Install::ROLE );
			$new_account = true;

			$customer = new WC_Customer( $user_id );
			$customer->set_billing_first_name( $args['first_name'] );
			$customer->set_billing_last_name( $args['last_name'] );
			$customer->set_billing_email( $app['email'] );
			$customer->set_billing_phone( $app['phone'] );
			if ( '' !== $app['country'] ) {
				$customer->set_billing_country( $app['country'] );
			}
			$customer->save();

			update_user_meta( $user_id, self::SETPW_META, time() + self::SETPW_DAYS * DAY_IN_SECONDS );
		}

		update_user_meta( $user->ID, HPH_Context::STATUS_META, 'active' );
		update_user_meta( $user->ID, '_hph_terms_version', $app['terms_version'] );
		update_user_meta( $user->ID, '_hph_terms_at', $app['created_at'] );
		update_user_meta( $user->ID, '_hph_application_id', $application_id );
		clean_user_cache( $user->ID );

		HPH_Application_Repo::update(
			$application_id,
			array(
				'status'        => 'approved',
				'user_id'       => $user->ID,
				'reviewer_id'   => get_current_user_id(),
				'reviewed_at'   => current_time( 'mysql', true ),
				'internal_note' => $note,
			)
		);
		HPH_Log::add(
			'application_approved',
			'application',
			$application_id,
			array( 'status' => $app['status'] ),
			array( 'status' => 'approved', 'user_id' => $user->ID, 'new_account' => $new_account )
		);

		do_action( 'hph_application_approved', $application_id, $user->ID, $new_account, $notify );

		return $user->ID;
	}

	/**
	 * $decision is needs_info or rejected. $message goes to the applicant by email.
	 */
	public static function decide( int $application_id, string $decision, string $message, string $note = '' ) {
		if ( ! in_array( $decision, array( 'needs_info', 'rejected' ), true ) ) {
			return new WP_Error( 'hph_bad_decision', __( 'Unknown decision.', 'herb-partner-hub' ) );
		}
		$app = HPH_Application_Repo::get( $application_id );
		if ( ! $app ) {
			return new WP_Error( 'hph_not_found', __( 'Application not found.', 'herb-partner-hub' ) );
		}
		if ( ! in_array( $app['status'], HPH_Application_Repo::OPEN, true ) ) {
			return new WP_Error( 'hph_already_decided', __( 'This application has already been decided.', 'herb-partner-hub' ) );
		}
		if ( 'needs_info' === $decision && '' === trim( $message ) ) {
			return new WP_Error( 'hph_message_required', __( 'Write what information you need from the applicant.', 'herb-partner-hub' ) );
		}

		HPH_Application_Repo::update(
			$application_id,
			array(
				'status'        => $decision,
				'reviewer_id'   => get_current_user_id(),
				'reviewed_at'   => current_time( 'mysql', true ),
				'internal_note' => $note,
			)
		);
		HPH_Log::add( 'application_' . $decision, 'application', $application_id, array( 'status' => $app['status'] ), array( 'status' => $decision, 'message' => $message ) );

		do_action( 'hph_application_' . $decision, $application_id, $message );

		return true;
	}

	/**
	 * Send the approval email again with a fresh set-password link (new link, new 7 days).
	 */
	public static function resend_approval( int $application_id ) {
		$app  = HPH_Application_Repo::get( $application_id );
		$user = $app && 'approved' === $app['status'] ? self::account_for( $app ) : null;
		if ( ! $user ) {
			return new WP_Error( 'hph_not_approved', __( 'Only approved applications can be resent.', 'herb-partner-hub' ) );
		}

		$needs_password = '' !== (string) get_user_meta( $user->ID, self::SETPW_META, true );
		if ( $needs_password ) {
			update_user_meta( $user->ID, self::SETPW_META, time() + self::SETPW_DAYS * DAY_IN_SECONDS );
		}
		HPH_Log::add( 'approval_resent', 'application', $application_id );
		do_action( 'hph_application_approved', $application_id, $user->ID, $needs_password );

		return true;
	}

	/**
	 * A WooCommerce lost-password link for this user. Makes a new key, which cancels older ones.
	 */
	public static function set_password_url( WP_User $user ): string {
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'key'   => $key,
				'id'    => $user->ID,
				'login' => rawurlencode( $user->user_login ),
			),
			wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
		);
	}

	/**
	 * WordPress gives reset keys one day. Newly approved dropshippers get SETPW_DAYS, and
	 * only them: the filter has no user argument, so the user is read from the request.
	 */
	public function extend_reset_expiration( $expiration ) {
		$user = self::reset_request_user();
		if ( $user && (int) get_user_meta( $user->ID, self::SETPW_META, true ) > time() ) {
			return max( (int) $expiration, self::SETPW_DAYS * DAY_IN_SECONDS );
		}
		return $expiration;
	}

	/**
	 * After a new dropshipper sets their password: log them in and send them to My Account.
	 * Runs before WooCommerce's own redirect to the login form.
	 */
	public function after_set_password( WP_User $user ): void {
		if ( '' === (string) get_user_meta( $user->ID, self::SETPW_META, true ) ) {
			return;
		}
		delete_user_meta( $user->ID, self::SETPW_META );
		HPH_Log::add( 'password_set', 'user', $user->ID );

		wc_set_customer_auth_cookie( $user->ID );
		wp_safe_redirect( apply_filters( 'hph_after_set_password_redirect', wc_get_page_permalink( 'myaccount' ), $user ) );
		exit;
	}

	/**
	 * Reset requests identify the user in one of three places, depending on the step:
	 * the emailed link (?id= or ?login=), the reset form (reset_login), or the cookie
	 * WooCommerce sets in between ("<id>:<key>"; WordPress core uses "<login>:<key>").
	 */
	private static function reset_request_user(): ?WP_User {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only lookup; the reset key itself is the credential.
		if ( ! empty( $_POST['reset_login'] ) ) {
			$user = get_user_by( 'login', sanitize_user( wp_unslash( $_POST['reset_login'] ) ) );
		} elseif ( ! empty( $_GET['id'] ) ) {
			$user = get_user_by( 'id', absint( $_GET['id'] ) );
		} elseif ( ! empty( $_GET['login'] ) ) {
			$user = get_user_by( 'login', sanitize_user( wp_unslash( $_GET['login'] ) ) );
		} else {
			$cookie = 'wp-resetpass-' . COOKIEHASH;
			$value  = isset( $_COOKIE[ $cookie ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) : '';
			$who    = '' === $value ? '' : strtok( $value, ':' );
			$user   = '' === $who ? false : ( ctype_digit( $who ) ? get_user_by( 'id', (int) $who ) : get_user_by( 'login', $who ) );
		}
		// phpcs:enable
		return $user ?: null;
	}
}
