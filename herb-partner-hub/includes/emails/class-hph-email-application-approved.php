<?php

defined( 'ABSPATH' ) || exit;

final class HPH_Email_Application_Approved extends HPH_Email {
	public function __construct() {
		$this->id             = 'hph_application_approved';
		$this->customer_email = true;
		$this->title          = __( 'Dropshipper: approved', 'herb-partner-hub' );
		$this->description    = __( 'Sent when an application is approved. New accounts get a link to set their password.', 'herb-partner-hub' );
		parent::__construct();
	}

	public function get_default_subject() {
		return __( "You're approved as a {site_title} Dropshipper", 'herb-partner-hub' );
	}

	public function get_default_heading() {
		return __( 'Welcome aboard, {first_name}', 'herb-partner-hub' );
	}

	/**
	 * $needs_password: true for an account created on approval that has not set a password yet.
	 * The link is made here once, because each new key cancels the previous one.
	 */
	public function trigger( int $application_id, int $user_id, bool $needs_password ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $this->load( $application_id ) ) {
			return;
		}
		$this->recipient = $user->user_email;
		$this->hph_data      = array(
			'login'        => $user->user_login,
			'password_url' => $needs_password ? HPH_Approval::set_password_url( $user ) : '',
		);
		$this->dispatch();
	}

	protected function blocks(): array {
		$store  = trim( (string) ( $this->application['business_name'] ?? '' ) );
		$blocks = array(
			array(
				'p',
				'' === $store
					? __( 'Your application has been approved. Your dropshipper account is ready.', 'herb-partner-hub' )
					/* translators: %s: store name from the application */
					: sprintf( __( 'Your application for %s has been approved. Your dropshipper account is ready.', 'herb-partner-hub' ), $store ),
			),
		);

		if ( ! empty( $this->hph_data['password_url'] ) ) {
			$blocks[] = array( 'p', __( 'Set your password to sign in. After that, the shop shows you wholesale prices whenever you are logged in.', 'herb-partner-hub' ) );
			$blocks[] = array( 'button', __( 'Set my password', 'herb-partner-hub' ), $this->hph_data['password_url'] );
			/* translators: %d: number of days */
			$blocks[] = array( 'note', sprintf( __( 'This link works for %d days. If it expires, use "Lost your password?" on the login page with this email address.', 'herb-partner-hub' ), HPH_Approval::SETPW_DAYS ) );
		} else {
			$blocks[] = array( 'p', __( 'Your existing account now has dropshipper access. Log in to see wholesale prices.', 'herb-partner-hub' ) );
			$blocks[] = array( 'button', __( 'Log in', 'herb-partner-hub' ), wc_get_page_permalink( 'myaccount' ) );
		}

		$blocks[] = array(
			'details',
			array(
				__( 'Login email', 'herb-partner-hub' ) => $this->recipient,
				__( 'Username', 'herb-partner-hub' )    => $this->hph_data['login'] ?? '',
				__( 'Your account', 'herb-partner-hub' ) => wc_get_page_permalink( 'myaccount' ),
			),
			__( 'Your account', 'herb-partner-hub' ),
		);

		return $blocks;
	}
}
