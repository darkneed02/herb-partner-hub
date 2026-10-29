<?php
/**
 * Module M1: application form, review screen, approval and the five emails.
 *
 * Unlike the old WPForms flow (novamira-sandbox/stherb-dropship-registration.php), nothing
 * is created when someone applies. The account is created or upgraded only on approval,
 * so an applicant never gets dropshipper prices before a person has looked at them.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-hph-application-repo.php';
require_once __DIR__ . '/class-hph-application-form.php';
require_once __DIR__ . '/class-hph-approval.php';

final class HPH_Applications {
	public const EMAILS = array(
		'HPH_Email_New_Application'        => 'class-hph-email-new-application.php',
		'HPH_Email_Application_Received'   => 'class-hph-email-application-received.php',
		'HPH_Email_Application_Approved'   => 'class-hph-email-application-approved.php',
		'HPH_Email_Application_Needs_Info' => 'class-hph-email-application-needs-info.php',
		'HPH_Email_Application_Rejected'   => 'class-hph-email-application-rejected.php',
	);

	public function __construct() {
		new HPH_Application_Form();
		new HPH_Approval();

		add_filter( 'woocommerce_email_classes', array( $this, 'register_emails' ) );
		add_action( 'hph_application_submitted', array( $this, 'send_submitted' ) );
		add_action( 'hph_application_approved', array( $this, 'send_approved' ), 10, 4 );
		add_action( 'hph_application_needs_info', array( $this, 'send_needs_info' ), 10, 2 );
		add_action( 'hph_application_rejected', array( $this, 'send_rejected' ), 10, 2 );

		if ( is_admin() ) {
			require_once __DIR__ . '/class-hph-applications-admin.php';
			new HPH_Applications_Admin();
		}
	}

	/**
	 * WC_Email only exists once WooCommerce builds its mailer, so the classes load here.
	 */
	public function register_emails( array $emails ): array {
		require_once HPH_PATH . 'includes/emails/class-hph-email.php';
		foreach ( self::EMAILS as $class => $file ) {
			require_once HPH_PATH . 'includes/emails/' . $file;
			$emails[ $class ] = new $class();
		}
		return $emails;
	}

	public function send_submitted( int $application_id ): void {
		self::send( 'HPH_Email_New_Application', $application_id );
		self::send( 'HPH_Email_Application_Received', $application_id );
	}

	public function send_approved( int $application_id, int $user_id, bool $new_account, bool $notify = true ): void {
		if ( ! $notify ) {
			return;
		}
		self::send( 'HPH_Email_Application_Approved', $application_id, $user_id, $new_account );
	}

	public function send_needs_info( int $application_id, string $message ): void {
		self::send( 'HPH_Email_Application_Needs_Info', $application_id, $message );
	}

	public function send_rejected( int $application_id, string $message ): void {
		self::send( 'HPH_Email_Application_Rejected', $application_id, $message );
	}

	private static function send( string $class, ...$args ): void {
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails[ $class ] ) ) {
			$emails[ $class ]->trigger( ...$args );
		}
	}
}
