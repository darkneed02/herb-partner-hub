<?php

defined( 'ABSPATH' ) || exit;

/**
 * Shared base for the plugin's WooCommerce emails. Each email returns content "blocks"
 * and one template (templates/emails/hph-email.php, plus a plain-text twin) renders them:
 *   array( 'p', 'text' )               paragraph
 *   array( 'button', 'label', 'url' )  call-to-action link
 *   array( 'quote', 'text' )           a message written by the team
 *   array( 'note', 'text' )            small grey print
 *   array( 'details', label => value, 'heading' ) two-column table, optional heading
 */
abstract class HPH_Email extends WC_Email {
	protected ?array $application = null;
	protected array $hph_data     = array();

	public function __construct() {
		$this->template_base  = HPH_PATH . 'templates/';
		$this->template_html  = 'emails/hph-email.php';
		$this->template_plain = 'emails/plain/hph-email.php';
		$this->placeholders   = array_merge(
			$this->placeholders,
			array(
				'{applicant_name}' => '',
				'{first_name}'     => '',
			)
		);
		parent::__construct();
	}

	abstract protected function blocks(): array;

	protected function load( int $application_id ): bool {
		$this->application = HPH_Application_Repo::get( $application_id );
		if ( ! $this->application ) {
			return false;
		}
		$this->placeholders['{applicant_name}'] = $this->application['name'];
		$this->placeholders['{first_name}']     = strtok( $this->application['name'], ' ' ) ?: $this->application['name'];
		if ( $this->is_customer_email() ) {
			$this->recipient = $this->application['email'];
		}
		return true;
	}

	protected function dispatch(): void {
		$this->setup_locale();
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	protected function greeting(): array {
		/* translators: %s: applicant first name */
		return array( 'p', sprintf( __( 'Hi %s,', 'herb-partner-hub' ), $this->placeholders['{first_name}'] ) );
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	private function template_args( bool $plain ): array {
		return array(
			'email_heading'      => $this->get_heading(),
			'blocks'             => $this->application ? $this->blocks() : array(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => ! $this->is_customer_email(),
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}
}
