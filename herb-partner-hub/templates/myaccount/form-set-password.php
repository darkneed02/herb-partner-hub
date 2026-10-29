<?php
/**
 * Set-password page for a newly approved dropshipper. Replaces WooCommerce's
 * myaccount/form-reset-password.php (11.2.1) for that one user only, and keeps every
 * field, hook and nonce of the original so the reset still goes through WooCommerce.
 *
 * @var array $args key, login
 */

defined( 'ABSPATH' ) || exit;

$hph_user = get_user_by( 'login', $args['login'] );

do_action( 'woocommerce_before_reset_password_form' );
?>
<div class="hph hph-panel hph-setpw">
	<div class="hph-head">
		<h2 class="hph-title"><?php esc_html_e( 'Set your new password', 'herb-partner-hub' ); ?></h2>
		<?php if ( $hph_user ) : ?>
			<p class="hph-muted">
				<?php
				/* translators: %s: account email */
				echo esc_html( sprintf( __( 'Account: %s', 'herb-partner-hub' ), $hph_user->user_email ) );
				?>
			</p>
		<?php endif; ?>
	</div>

	<form method="post" class="woocommerce-ResetPassword lost_reset_password hph-form">
		<div class="hph-fld">
			<label for="password_1"><?php esc_html_e( 'New password', 'herb-partner-hub' ); ?> <span class="hph-req" aria-hidden="true">*</span></label>
			<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password_1" id="password_1" autocomplete="new-password" required aria-required="true">
		</div>
		<div class="hph-fld">
			<label for="password_2"><?php esc_html_e( 'Re-enter new password', 'herb-partner-hub' ); ?> <span class="hph-req" aria-hidden="true">*</span></label>
			<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password_2" id="password_2" autocomplete="new-password" required aria-required="true">
		</div>

		<input type="hidden" name="reset_key" value="<?php echo esc_attr( $args['key'] ); ?>">
		<input type="hidden" name="reset_login" value="<?php echo esc_attr( $args['login'] ); ?>">

		<?php do_action( 'woocommerce_resetpassword_form' ); ?>

		<input type="hidden" name="wc_reset_password" value="true">
		<button type="submit" class="hph-btn woocommerce-Button button" value="<?php esc_attr_e( 'Save', 'woocommerce' ); ?>"><?php esc_html_e( 'Save and sign in', 'herb-partner-hub' ); ?></button>

		<?php wp_nonce_field( 'reset_password', 'woocommerce-reset-password-nonce' ); ?>
	</form>
</div>
<?php
do_action( 'woocommerce_after_reset_password_form' );
