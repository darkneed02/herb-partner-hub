<?php

defined( 'ABSPATH' ) || exit;

final class HPH_Email_New_Application extends HPH_Email {
	public function __construct() {
		$this->id          = 'hph_new_application';
		$this->title       = __( 'Dropshipper: new application', 'herb-partner-hub' );
		$this->description = __( 'Sent to the team when someone applies to become a dropshipper.', 'herb-partner-hub' );
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	public function get_default_subject() {
		return __( '[{site_title}] New dropshipper application from {applicant_name}', 'herb-partner-hub' );
	}

	public function get_default_heading() {
		return __( 'New dropshipper application', 'herb-partner-hub' );
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields = array_merge(
			array_slice( $this->form_fields, 0, 1, true ),
			array(
				'recipient' => array(
					'title'       => __( 'Recipient(s)', 'herb-partner-hub' ),
					'type'        => 'text',
					/* translators: %s: admin email */
					'description' => sprintf( __( 'Comma separated. Defaults to %s.', 'herb-partner-hub' ), esc_html( get_option( 'admin_email' ) ) ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
				),
			),
			array_slice( $this->form_fields, 1, null, true )
		);
	}

	public function trigger( int $application_id ): void {
		if ( $this->load( $application_id ) ) {
			$this->dispatch();
		}
	}

	/**
	 * Replying to this email goes to the applicant.
	 */
	public function get_headers() {
		$headers = parent::get_headers();
		if ( $this->application ) {
			$headers .= 'Reply-to: ' . $this->application['name'] . ' <' . $this->application['email'] . ">\r\n";
		}
		return $headers;
	}

	protected function blocks(): array {
		$app       = $this->application;
		$countries = WC()->countries->get_countries();

		return array(
			/* translators: %s: applicant name */
			array( 'p', sprintf( __( '%s applied to become a dropshipper.', 'herb-partner-hub' ), $app['name'] ) ),
			array(
				'details',
				array(
					__( 'Email', 'herb-partner-hub' )                 => $app['email'],
					__( 'Phone', 'herb-partner-hub' )                 => $app['phone'],
					__( 'LINE / WhatsApp', 'herb-partner-hub' )       => $app['fields']['contact'] ?? '',
					__( 'Country / market', 'herb-partner-hub' )      => html_entity_decode( $countries[ $app['country'] ] ?? $app['country'] ),
					__( 'Store or channel', 'herb-partner-hub' )      => $app['business_name'],
					__( 'Channel URL', 'herb-partner-hub' )           => $app['fields']['channel_url'] ?? '',
					__( 'Sells on', 'herb-partner-hub' )              => implode( ', ', (array) ( $app['fields']['sales_channels'] ?? array() ) ),
					__( 'Experience', 'herb-partner-hub' )            => $app['message'],
					__( 'Existing account', 'herb-partner-hub' )      => get_user_by( 'email', $app['email'] ) ? __( 'Yes', 'herb-partner-hub' ) : __( 'No', 'herb-partner-hub' ),
				),
			),
			array( 'button', __( 'Review application', 'herb-partner-hub' ), admin_url( 'admin.php?page=hph-applications&id=' . $app['id'] ) ),
		);
	}
}
