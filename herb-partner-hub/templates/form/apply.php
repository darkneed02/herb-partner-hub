<?php
/**
 * Application form. Copy to yourtheme/herb-partner-hub/form/apply.php to override.
 *
 * @var array   $errors field => message
 * @var array   $old    previously submitted values
 * @var WP_User $user
 * @var array   $atts   shortcode attributes: title, intro, style (panel|plain)
 */

defined( 'ABSPATH' ) || exit;

$hph_return = is_singular() ? get_permalink( get_queried_object_id() ) : home_url( '/' );
$hph_val    = static fn( string $key ): string => is_scalar( $old[ $key ] ?? null ) ? (string) $old[ $key ] : '';
$hph_err    = static function ( string $key ) use ( $errors ): void {
	if ( ! empty( $errors[ $key ] ) ) {
		echo '<p class="hph-error" id="hph-err-' . esc_attr( $key ) . '">' . wp_kses_post( $errors[ $key ] ) . '</p>';
	}
};
$hph_inv    = static fn( string $key ): string => empty( $errors[ $key ] ) ? '' : ' aria-invalid="true" aria-describedby="hph-err-' . esc_attr( $key ) . '"';
$hph_chans  = array_map( 'strval', (array) ( $old['sales_channels'] ?? array() ) );
$hph_ctry   = $hph_val( 'country' );
$hph_req    = '<span class="hph-req" aria-hidden="true">*</span>';
?>
<div class="hph hph-apply <?php echo 'plain' === $atts['style'] ? 'hph-plain' : 'hph-panel'; ?>" id="hph-apply">
	<?php if ( '' !== $atts['title'] || '' !== $atts['intro'] ) : ?>
		<div class="hph-head">
			<?php if ( '' !== $atts['title'] ) : ?>
				<h2 class="hph-title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== $atts['intro'] ) : ?>
				<p class="hph-intro"><?php echo esc_html( $atts['intro'] ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form class="hph-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( HPH_Application_Form::ACTION ); ?>">
		<input type="hidden" name="hph_return" value="<?php echo esc_url( $hph_return ); ?>">
		<input type="hidden" name="hph_js" value="">
		<?php if ( $user->exists() ) : ?>
			<?php wp_nonce_field( HPH_Application_Form::ACTION, '_hph_nonce' ); ?>
		<?php endif; ?>
		<div class="hph-hp" aria-hidden="true">
			<label for="hph-website"><?php esc_html_e( 'Leave this empty', 'herb-partner-hub' ); ?></label>
			<input type="text" id="hph-website" name="hph_website" value="" tabindex="-1" autocomplete="off">
		</div>

		<?php if ( ! empty( $errors ) ) : ?>
			<div class="hph-notice hph-notice--error" role="alert">
				<?php echo wp_kses_post( $errors['form'] ?? __( 'Please check the highlighted fields.', 'herb-partner-hub' ) ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! $user->exists() ) : ?>
			<p class="hph-login">
				<?php
				printf(
					/* translators: %s: My Account URL */
					wp_kses_post( __( 'Already a customer? <a href="%s">Log in</a> first and apply with your account email.', 'herb-partner-hub' ) ),
					esc_url( wc_get_page_permalink( 'myaccount' ) )
				);
				?>
			</p>
		<?php endif; ?>

		<div class="hph-grid2">
			<div class="hph-fld">
				<label for="hph-name"><?php esc_html_e( 'Full name', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				<input type="text" id="hph-name" name="name" required maxlength="190" autocomplete="name" value="<?php echo esc_attr( $hph_val( 'name' ) ); ?>"<?php echo $hph_inv( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php $hph_err( 'name' ); ?>
			</div>
			<div class="hph-fld">
				<label for="hph-email"><?php esc_html_e( 'Email address', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				<input type="email" id="hph-email" name="email" required maxlength="190" autocomplete="email" placeholder="name@example.com" value="<?php echo esc_attr( $hph_val( 'email' ) ); ?>"<?php echo $user->exists() ? ' readonly' : ''; ?><?php echo $hph_inv( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php if ( $user->exists() ) : ?>
					<p class="hph-hint"><?php esc_html_e( 'Your account email. We add dropshipper access to this account.', 'herb-partner-hub' ); ?></p>
				<?php endif; ?>
				<?php $hph_err( 'email' ); ?>
			</div>
			<div class="hph-fld">
				<label for="hph-phone"><?php esc_html_e( 'Phone number', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				<input type="tel" id="hph-phone" name="phone" required maxlength="50" autocomplete="tel" placeholder="+66 81 234 5678" value="<?php echo esc_attr( $hph_val( 'phone' ) ); ?>"<?php echo $hph_inv( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php $hph_err( 'phone' ); ?>
			</div>
			<div class="hph-fld">
				<label for="hph-contact"><?php esc_html_e( 'LINE ID or WhatsApp', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				<input type="text" id="hph-contact" name="contact" required maxlength="190" placeholder="@yourline or +66 ..." value="<?php echo esc_attr( $hph_val( 'contact' ) ); ?>"<?php echo $hph_inv( 'contact' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php $hph_err( 'contact' ); ?>
			</div>
			<div class="hph-fld">
				<label for="hph-store"><?php esc_html_e( 'Store or channel name', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				<input type="text" id="hph-store" name="store_name" required maxlength="190" value="<?php echo esc_attr( $hph_val( 'store_name' ) ); ?>"<?php echo $hph_inv( 'store_name' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php $hph_err( 'store_name' ); ?>
			</div>
			<div class="hph-fld">
				<label for="hph-url"><?php esc_html_e( 'Store or social URL', 'herb-partner-hub' ); ?></label>
				<input type="url" id="hph-url" name="channel_url" maxlength="255" placeholder="https://facebook.com/yourshop" value="<?php echo esc_attr( $hph_val( 'channel_url' ) ); ?>"<?php echo $hph_inv( 'channel_url' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php $hph_err( 'channel_url' ); ?>
			</div>
			<div class="hph-fld">
				<label for="hph-country"><?php esc_html_e( 'Country / market', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				<select id="hph-country" name="country" required<?php echo $hph_inv( 'country' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
					<option value=""><?php esc_html_e( 'Choose a country', 'herb-partner-hub' ); ?></option>
					<?php foreach ( WC()->countries->get_countries() as $hph_code => $hph_name ) : ?>
						<option value="<?php echo esc_attr( $hph_code ); ?>" <?php selected( $hph_ctry, $hph_code ); ?>><?php echo esc_html( html_entity_decode( $hph_name ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php $hph_err( 'country' ); ?>
			</div>
		</div>

		<fieldset class="hph-fld">
			<legend><?php esc_html_e( 'Where do you currently sell?', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput ?></legend>
			<div class="hph-chips">
				<?php foreach ( HPH_Application_Form::CHANNELS as $hph_i => $hph_channel ) : ?>
					<label class="hph-chip">
						<input type="checkbox" name="sales_channels[]" value="<?php echo esc_attr( $hph_channel ); ?>" <?php checked( in_array( $hph_channel, $hph_chans, true ) ); ?>>
						<span><?php echo esc_html( $hph_channel ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<?php $hph_err( 'sales_channels' ); ?>
		</fieldset>

		<div class="hph-fld">
			<label for="hph-exp"><?php esc_html_e( 'Sales experience and target customers', 'herb-partner-hub' ); ?></label>
			<textarea id="hph-exp" name="experience" rows="4" maxlength="2000" placeholder="<?php esc_attr_e( 'How long you have been selling, and who your customers are.', 'herb-partner-hub' ); ?>"<?php echo $hph_inv( 'experience' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_textarea( $hph_val( 'experience' ) ); ?></textarea>
			<?php $hph_err( 'experience' ); ?>
		</div>

		<div class="hph-fld">
			<label class="hph-agree" for="hph-agree">
				<input type="checkbox" id="hph-agree" name="agree" value="1" required <?php checked( ! empty( $old['agree'] ) ); ?>>
				<span>
					<?php
					printf(
						/* translators: 1: terms page URL, 2: privacy policy URL */
						wp_kses_post( __( 'I agree to the <a href="%1$s" target="_blank" rel="noopener">Dropshipper Terms</a> and the <a href="%2$s" target="_blank" rel="noopener">Privacy Policy</a>.', 'herb-partner-hub' ) ),
						esc_url( HPH_Application_Form::terms_url() ),
						esc_url( get_privacy_policy_url() ?: home_url( '/' ) )
					);
					?>
					<?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</span>
			</label>
			<?php $hph_err( 'agree' ); ?>
		</div>

		<button type="submit" class="hph-btn"><?php esc_html_e( 'Submit application', 'herb-partner-hub' ); ?></button>
	</form>
</div>
<script>
document.querySelector('#hph-apply form').addEventListener('submit', function (e) { e.target.elements.hph_js.value = '1'; });
</script>
