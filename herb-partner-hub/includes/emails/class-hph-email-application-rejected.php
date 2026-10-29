<?php

defined( 'ABSPATH' ) || exit;

final class HPH_Email_Application_Rejected extends HPH_Email {
	public function __construct() {
		$this->id             = 'hph_application_rejected';
		$this->customer_email = true;
		$this->title          = __( 'Dropshipper: not approved', 'herb-partner-hub' );
		$this->description    = __( 'Sent when an application is not approved. Includes the message written on the review screen, if any.', 'herb-partner-hub' );
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Update on your {site_title} dropship partner application', 'herb-partner-hub' );
	}

	public function get_default_heading() {
		return __( 'Application update', 'herb-partner-hub' );
	}

	public function trigger( int $application_id, string $message ): void {
		if ( $this->load( $application_id ) ) {
			$this->hph_data = array( 'message' => $message );
			$this->dispatch();
		}
	}

	protected function blocks(): array {
		$blocks = array(
			$this->greeting(),
			/* translators: %s: site name */
			array( 'p', sprintf( __( 'Thank you for your interest in becoming a %s dropship partner. After reviewing your application, we are not able to approve it at this time.', 'herb-partner-hub' ), $this->get_blogname() ) ),
		);
		if ( '' !== trim( $this->hph_data['message'] ?? '' ) ) {
			$blocks[] = array( 'quote', $this->hph_data['message'] );
		}
		$blocks[] = array( 'p', __( 'Thank you again for your interest.', 'herb-partner-hub' ) );
		return $blocks;
	}
}
