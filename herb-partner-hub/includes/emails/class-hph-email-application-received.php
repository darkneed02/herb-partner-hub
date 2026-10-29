<?php

defined( 'ABSPATH' ) || exit;

final class HPH_Email_Application_Received extends HPH_Email {
	public function __construct() {
		$this->id             = 'hph_application_received';
		$this->customer_email = true;
		$this->title          = __( 'Dropshipper: application received', 'herb-partner-hub' );
		$this->description    = __( 'Sent to the applicant right after they apply.', 'herb-partner-hub' );
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'We received your {site_title} dropship partner application', 'herb-partner-hub' );
	}

	public function get_default_heading() {
		return __( 'Application received', 'herb-partner-hub' );
	}

	public function trigger( int $application_id ): void {
		if ( $this->load( $application_id ) ) {
			$this->dispatch();
		}
	}

	protected function blocks(): array {
		return array(
			$this->greeting(),
			/* translators: %s: site name */
			array( 'p', sprintf( __( 'Thank you for applying to become a %s dropship partner. Our team will review your information and contact you by email.', 'herb-partner-hub' ), $this->get_blogname() ) ),
			array( 'p', __( 'You do not need to do anything else for now.', 'herb-partner-hub' ) ),
		);
	}
}
