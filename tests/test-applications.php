<?php
/**
 * T2 tests for M1 (application form, approval, emails). Needs the applications module on
 * before WordPress loads, so run:
 *
 *   docker compose exec -T cli wp eval 'HPH_Settings::update( array( "modules" => array( "applications" => 1 ) ) );'
 *   docker compose exec -T cli wp eval-file - < test-applications.php
 *
 * Creates users and applications. Never run it against a real shop.
 */

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check( string $name, $actual, $expected ) {
	if ( $actual === $expected ) { $GLOBALS['pass']++; echo "PASS  $name\n"; }
	else { $GLOBALS['fail']++; echo "FAIL  $name\n      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) . "\n"; }
}

// Record mail instead of sending it.
$GLOBALS['mails'] = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) { $GLOBALS['mails'][] = $atts; return true; }, 10, 2 );
function mails_take(): array { $m = $GLOBALS['mails']; $GLOBALS['mails'] = array(); return $m; }
function mail_subjects( array $mails ): array { return array_map( fn( $m ) => $m['subject'], $mails ); }

function valid_input( array $over = array() ): array {
	return array_merge(
		array(
			'name'           => 'Test Applicant',
			'email'          => 'applicant-' . wp_generate_password( 6, false ) . '@example.com',
			'phone'          => '+1 555 0100',
			'sales_channels' => array( 'TikTok', 'Website', 'Not a real channel' ),
			'store_name'     => 'Test Store',
			'channel_url'    => 'https://example.com/shop',
			'country'        => 'us',
			'contact'        => 'wa:+15550100',
			'experience'     => "Two years on TikTok.\nUS customers.",
			'agree'          => '1',
		),
		$over
	);
}

function hph_test_user( string $login, array $roles ): int {
	$user = get_user_by( 'login', $login );
	$id   = $user ? $user->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => "$login@example.com", 'role' => $roles[0] ) );
	$user = new WP_User( $id );
	$user->set_role( $roles[0] );
	foreach ( array_slice( $roles, 1 ) as $role ) { $user->add_role( $role ); }
	delete_user_meta( $id, '_hph_status' );
	clean_user_cache( $id );
	return (int) $id;
}

echo "== module loaded ==\n";
check( 'applications module on', null !== HPH_Plugin::instance()->module( 'applications' ), true );
check( 'shortcode registered', shortcode_exists( 'herb_partner_apply' ), true );
$emails = WC()->mailer()->get_emails();
foreach ( array_keys( HPH_Applications::EMAILS ) as $class ) {
	check( "email registered: $class", isset( $emails[ $class ] ), true );
}

echo "== form render ==\n";
wp_set_current_user( 0 );
$html = do_shortcode( '[herb_partner_apply]' );
check( 'form has name field', str_contains( $html, 'name="name"' ), true );
check( 'form has country select with US', str_contains( $html, 'value="US"' ), true );
check( 'logged out: no nonce field', str_contains( $html, '_hph_nonce' ), false );
check( 'logged out: honeypot present', str_contains( $html, 'name="hph_website"' ), true );
check( 'logged out: login hint', str_contains( $html, 'Log in' ), true );
check( 'form posts to admin-post', str_contains( $html, 'admin-post.php' ), true );

echo "== submit: validation ==\n";
$r = HPH_Application_Form::submit( array() );
check( 'empty submit has errors', isset( $r['errors'] ), true );
foreach ( array( 'name', 'email', 'phone', 'sales_channels', 'store_name', 'country', 'contact', 'agree' ) as $f ) {
	check( "empty submit: $f required", isset( $r['errors'][ $f ] ), true );
}
check( 'empty submit: channel_url optional', isset( $r['errors']['channel_url'] ), false );
$r = HPH_Application_Form::submit( valid_input( array( 'channel_url' => 'javascript:alert(1)', 'country' => 'XX', 'sales_channels' => array( 'Nope' ) ) ) );
check( 'bad url rejected', isset( $r['errors']['channel_url'] ), true );
check( 'bad country rejected', isset( $r['errors']['country'] ), true );
check( 'unknown channels only = none chosen', isset( $r['errors']['sales_channels'] ), true );

echo "== submit: logged-out new applicant ==\n";
mails_take();
$in = valid_input();
$r  = HPH_Application_Form::submit( $in );
check( 'valid submit returns id', isset( $r['id'] ) && $r['id'] > 0, true );
$app = HPH_Application_Repo::get( (int) $r['id'] );
check( 'status new', $app['status'], 'new' );
check( 'country uppercased', $app['country'], 'US' );
check( 'unknown channel dropped', $app['fields']['sales_channels'], array( 'TikTok', 'Website' ) );
check( 'experience kept with newline', $app['message'], "Two years on TikTok.\nUS customers." );
check( 'no user linked', $app['user_id'], null );
check( 'no account created on apply', get_user_by( 'email', $in['email'] ), false );
$m = mails_take();
check( '2 emails sent', count( $m ), 2 );
check( 'team email to admin', $m[0]['to'] ?? '', get_option( 'admin_email' ) );
check( 'team email subject', str_contains( $m[0]['subject'] ?? '', 'New dropshipper application from Test Applicant' ), true );
check( 'team email reply-to applicant', str_contains( implode( "\n", (array) $m[0]['headers'] ), 'Reply-to: Test Applicant <' . $in['email'] . '>' ), true );
check( 'team email has review link', str_contains( $m[0]['message'] ?? '', 'page=hph-applications&amp;id=' . $app['id'] ) || str_contains( $m[0]['message'] ?? '', 'page=hph-applications&id=' . $app['id'] ), true );
check( 'applicant email to applicant', $m[1]['to'] ?? '', $in['email'] );
check( 'applicant email subject', str_contains( $m[1]['subject'] ?? '', 'We received your' ), true );

$r = HPH_Application_Form::submit( valid_input( array( 'email' => $in['email'] ) ) );
check( 'second open application for same email refused', isset( $r['errors']['email'] ), true );

echo "== submit: existing account ==\n";
$cust = hph_test_user( 'hph_t2_customer', array( 'customer' ) );
$r    = HPH_Application_Form::submit( valid_input( array( 'email' => 'hph_t2_customer@example.com' ) ) );
check( 'logged out with existing account email refused', str_contains( $r['errors']['email'] ?? '', 'log in' ), true );

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . hph_table( 'applications' ) . ' WHERE email = %s', 'hph_t2_customer@example.com' ) );
wp_set_current_user( $cust );
$html = do_shortcode( '[herb_partner_apply]' );
check( 'logged in: nonce field', str_contains( $html, '_hph_nonce' ), true );
check( 'logged in: email readonly', str_contains( $html, 'readonly' ), true );
$r = HPH_Application_Form::submit( valid_input( array( 'email' => 'someone-else@example.com' ) ) );
$own = HPH_Application_Repo::get( (int) ( $r['id'] ?? 0 ) );
check( 'logged in: posted email ignored, account email used', $own['email'] ?? '', 'hph_t2_customer@example.com' );
check( 'logged in: user linked', (int) ( $own['user_id'] ?? 0 ), $cust );
$html = do_shortcode( '[herb_partner_apply]' );
check( 'logged in with open application: no form, status note', str_contains( $html, 'We received your application' ) && ! str_contains( $html, '<form' ), true );
wp_set_current_user( 0 );
mails_take();

echo "== approve: new account ==\n";
$admin = hph_test_user( 'hph_t2_admin', array( 'administrator' ) );
wp_set_current_user( $admin );
$uid = HPH_Approval::approve( $app['id'], 'Looks good' );
check( 'approve returns user id', is_int( $uid ) && $uid > 0, true );
$u = get_userdata( $uid );
check( 'account email matches', $u->user_email, $in['email'] );
check( 'roles: customer + dropshipper', array_values( array_intersect( array( 'customer', 'herb_dropshipper' ), $u->roles ) ), array( 'customer', 'herb_dropshipper' ) );
check( 'status active', get_user_meta( $uid, '_hph_status', true ), 'active' );
check( 'is_partner', HPH_Context::is_partner( $uid ), true );
check( 'billing country from application', get_user_meta( $uid, 'billing_country', true ), 'US' );
check( 'billing first name', get_user_meta( $uid, 'billing_first_name', true ), 'Test' );
check( 'set-password window stored', (int) get_user_meta( $uid, '_hph_setpw_until', true ) > time() + 6 * DAY_IN_SECONDS, true );
$after = HPH_Application_Repo::get( $app['id'] );
check( 'application approved', $after['status'], 'approved' );
check( 'application linked to user', (int) $after['user_id'], $uid );
check( 'reviewer recorded', (int) $after['reviewer_id'], $admin );
check( 'internal note saved', $after['internal_note'], 'Looks good' );
$m = mails_take();
check( 'exactly 1 email (no WooCommerce welcome email)', mail_subjects( $m ), array( $m[0]['subject'] ?? '' ) );
check( 'approval email subject', str_contains( $m[0]['subject'] ?? '', 'approved as' ), true );
check( 'approval email names the store', str_contains( $m[0]['message'] ?? '', 'Your application for Test Store has been approved' ), true );
preg_match( '/href="([^"]*lost-password[^"]*)"/', $m[0]['message'] ?? '', $hit );
$link = html_entity_decode( $hit[1] ?? '' );
check( 'approval email has set-password link', '' !== $link, true );
parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $q );
check( 'link carries user id', (int) ( $q['id'] ?? 0 ), $uid );
check( 'link key is valid', check_password_reset_key( $q['key'] ?? '', $u->user_login ) instanceof WP_User, true );
check( 'approve twice refused', is_wp_error( HPH_Approval::approve( $app['id'] ) ), true );

echo "== reset link lifetime ==\n";
$approval = new HPH_Approval();
$_GET['id'] = $uid;
check( 'new dropshipper: 7 days', $approval->extend_reset_expiration( DAY_IN_SECONDS ), 7 * DAY_IN_SECONDS );
$_GET['id'] = $cust;
check( 'anyone else: unchanged', $approval->extend_reset_expiration( DAY_IN_SECONDS ), DAY_IN_SECONDS );
unset( $_GET['id'] );
$_POST['reset_login'] = $u->user_login;
check( 'reset form post: 7 days', $approval->extend_reset_expiration( DAY_IN_SECONDS ), 7 * DAY_IN_SECONDS );
unset( $_POST['reset_login'] );

echo "== approve: existing customer ==\n";
$uid2 = HPH_Approval::approve( $own['id'] );
check( 'existing account reused', $uid2, $cust );
$cu = get_userdata( $cust );
check( 'role added to existing account', in_array( 'herb_dropshipper', $cu->roles, true ), true );
check( 'existing account keeps customer role', in_array( 'customer', $cu->roles, true ), true );
check( 'no set-password window for existing account', get_user_meta( $cust, '_hph_setpw_until', true ), '' );
$m = mails_take();
check( 'existing account email: login link, no reset link', str_contains( $m[0]['message'] ?? '', 'lost-password' ), false );
check( 'existing account email: Log in button', str_contains( $m[0]['message'] ?? '', '>Log in<' ), true );

wp_set_current_user( $cust );
check( 'approved customer sees "already approved"', str_contains( do_shortcode( '[herb_partner_apply]' ), 'already an approved dropshipper' ), true );
wp_set_current_user( $admin );

echo "== staff account ==\n";
$staff_app = HPH_Application_Repo::insert( array( 'email' => 'hph_t2_admin@example.com', 'name' => 'Staff', 'country' => 'TH' ) );
$res       = HPH_Approval::approve( $staff_app );
check( 'staff email cannot be approved', is_wp_error( $res ) ? $res->get_error_code() : 'no-error', 'hph_staff_account' );
check( 'staff did not get role', in_array( 'herb_dropshipper', get_userdata( $admin )->roles, true ), false );

echo "== needs info / reject ==\n";
mails_take();
wp_set_current_user( 0 );
$a3  = HPH_Application_Form::submit( valid_input() )['id'];
wp_set_current_user( $admin );
mails_take();
$res = HPH_Approval::decide( $a3, 'needs_info', '' );
check( 'needs info without message refused', is_wp_error( $res ), true );
$res = HPH_Approval::decide( $a3, 'needs_info', "Which TikTok account?\nPlease send the link." );
check( 'needs info ok', $res, true );
check( 'status needs_info', HPH_Application_Repo::get( $a3 )['status'], 'needs_info' );
$m = mails_take();
check( 'needs info email has the question', str_contains( $m[0]['message'] ?? '', 'Which TikTok account?' ), true );
check( 'can approve after needs info', is_int( HPH_Approval::approve( $a3 ) ), true );
mails_take();

$in4 = valid_input();
wp_set_current_user( 0 );
$a4  = HPH_Application_Form::submit( $in4 )['id'];
wp_set_current_user( $admin );
mails_take();
check( 'reject ok', HPH_Approval::decide( $a4, 'rejected', '' ), true );
$m = mails_take();
check( 'reject email sent', str_contains( $m[0]['subject'] ?? '', 'Update on your' ), true );
wp_set_current_user( 0 );
check( 'rejected applicant may apply again', isset( HPH_Application_Form::submit( array_merge( $in4, array( 'name' => 'Again' ) ) )['id'] ), true );
wp_set_current_user( $admin );
check( 'reject after decided refused', is_wp_error( HPH_Approval::decide( $a4, 'rejected', '' ) ), true );
mails_take();

echo "== approve without email ==\n";
wp_set_current_user( 0 );
$a5 = HPH_Application_Form::submit( valid_input() )['id'];
wp_set_current_user( $admin );
mails_take();
check( 'approve with notify off', is_int( HPH_Approval::approve( $a5, '', false ) ), true );
check( 'notify off: no email sent', mails_take(), array() );
check( 'status labels match the mockup', array( HPH_Application_Repo::status_labels()['new'], HPH_Application_Repo::status_labels()['needs_info'] ), array( 'Waiting', 'Need info' ) );

echo "== resend ==\n";
$res = HPH_Approval::resend_approval( $app['id'] );
check( 'resend ok', $res, true );
$m = mails_take();
check( 'resend has a fresh set-password link', str_contains( $m[0]['message'] ?? '', 'lost-password' ), true );
check( 'resend for non-approved refused', is_wp_error( HPH_Approval::resend_approval( $a4 ) ), true );

echo "== log ==\n";
$actions = array_column( HPH_Log::query( array( 'object_type' => 'application', 'object_id' => $app['id'] ) ), 'action' );
check( 'log: submitted + approved + resent', array_values( array_intersect( array( 'approval_resent', 'application_approved', 'application_submitted' ), $actions ) ), array( 'approval_resent', 'application_approved', 'application_submitted' ) );

wp_set_current_user( 0 );
echo "\nTOTAL: {$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
