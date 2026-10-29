<?php

defined( 'ABSPATH' ) || exit;

final class HPH_Email_Application_Needs_Info extends HPH_Email {
	public function __construct() {
		$this->id             = 'hph_application_needs_info';
		$this->customer_email = true;
		$this->title          = __( 'Dropshipper: more information needed', 'herb-partner-hub' );
		$this->description    = __( 'Sent when the team asks the applicant for more information. Includes the message written on the review screen.', 'herb-partner-hub' );
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'About your {site_title} dropship partner application', 'herb-partner-hub' );
	}

	public function get_default_heading() {
		return __( 'We need a little more information', 'herb-partner-hub' );
	}

	public function trigger( int $application_id, string $message ): void {
		if ( $this->load( $application_id ) ) {
			$this->hph_data = array( 'message' => $message );
			$this->dispatch();
		}
	}

	protected function blocks(): array {
		return array(
			$this->greeting(),
			array( 'p', __( 'Thank you for applying. Before we can finish reviewing your application, we need a little more information:', 'herb-partner-hub' ) ),
			array( 'quote', $this->hph_data['message'] ?? '' ),
			array( 'p', __( 'Please reply to this email with the details.', 'herb-partner-hub' ) ),
		);
	}
}
