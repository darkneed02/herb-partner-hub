<?php
/**
 * Checkout: "Ship this order to". Copy to yourtheme/herb-partner-hub/checkout/recipient.php to override.
 *
 * Every input has a name: CartFlows stores checkout fields in localStorage by name, and a
 * nameless field breaks its script (see stherb-address-book.php, 2026-10-27).
 *
 * @var array  $customers rows from HPH_Customer_Repo, most recently used first
 * @var array  $stats     end customer id => array( 'count', 'last' )
 * @var string $selected  'self', 'new', 'customer-<id>', or '' (nothing chosen)
 * @var bool   $prefilled the session already holds this recipient's address (after Reorder)
 * @var string $email     the dropshipper's billing email, where the receipt goes
 */

defined( 'ABSPATH' ) || exit;

$hph_radio = static function ( string $value, string $title, string $detail, string $aside = '' ) use ( $selected ): void {
	$id = 'hph-ship-' . $value;
	?>
	<label class="hph-ro<?php echo $value === $selected ? ' is-on' : ''; ?>" for="<?php echo esc_attr( $id ); ?>">
		<input type="radio" name="<?php echo esc_attr( HPH_Checkout_Recipient::FIELD ); ?>" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $value, $selected ); ?>>
		<span class="hph-ro__dot" aria-hidden="true"></span>
		<span class="hph-ro__body"><b><?php echo esc_html( $title ); ?></b><span class="hph-muted hph-block"><?php echo esc_html( $detail ); ?></span></span>
		<span class="hph-ro__aside hph-muted"><?php echo esc_html( $aside ); ?></span>
	</label>
	<?php
};
?>
<div class="hph hph-recipient" id="hph-recipient"<?php echo $prefilled ? ' data-hph-prefilled' : ''; ?>>
	<fieldset class="hph-card">
		<legend class="hph-card__head">
			<b><?php esc_html_e( 'Ship this order to', 'herb-partner-hub' ); ?></b>
			<?php if ( '' !== $email ) : ?>
				<span class="hph-small hph-muted">
					<?php
					/* translators: %s: dropshipper's email */
					echo esc_html( sprintf( __( 'receipt goes to %s', 'herb-partner-hub' ), $email ) );
					?>
				</span>
			<?php endif; ?>
		</legend>
		<p class="hph-hint hph-recipient__billing">
			<?php esc_html_e( "Billing details stay yours, to match the card you pay with. Your customer's name, address and phone go on the parcel.", 'herb-partner-hub' ); ?>
		</p>

		<div class="hph-radio-list<?php echo count( $customers ) > 6 ? ' is-long' : ''; ?>">
			<?php
			foreach ( $customers as $hph_row ) {
				$hph_count = (int) ( $stats[ (int) $hph_row['id'] ]['count'] ?? 0 );
				$hph_radio(
					'customer-' . $hph_row['id'],
					HPH_Customer_Repo::name( $hph_row ),
					trim( HPH_Customer_Repo::address_line( $hph_row ) . ' · ' . $hph_row['phone'], ' ·' ),
					/* translators: %d: number of orders */
					$hph_count ? sprintf( _n( '%d order', '%d orders', $hph_count, 'herb-partner-hub' ), $hph_count ) : ''
				);
			}
			$hph_radio( 'new', __( 'New customer', 'herb-partner-hub' ), __( 'Type their address below', 'herb-partner-hub' ) );
			?>
			<label class="hph-ro__save" for="hph-save-customer" data-hph-save<?php echo 'new' === $selected ? '' : ' hidden'; ?>>
				<input type="checkbox" name="<?php echo esc_attr( HPH_Checkout_Recipient::SAVE_FIELD ); ?>" id="hph-save-customer" value="1" checked>
				<?php esc_html_e( 'Save to My Customers for next time', 'herb-partner-hub' ); ?>
			</label>
			<?php $hph_radio( 'self', __( 'Ship to myself', 'herb-partner-hub' ), __( 'use my billing address', 'herb-partner-hub' ) ); ?>
		</div>

		<?php if ( ! $customers ) : ?>
			<p class="hph-hint">
				<?php
				printf(
					/* translators: %s: My Customers URL */
					wp_kses_post( __( 'Tip: save the people you ship to in <a href="%s">My Customers</a> and they appear here.', 'herb-partner-hub' ) ),
					esc_url( HPH_Customers_Account::url() )
				);
				?>
			</p>
		<?php endif; ?>
	</fieldset>
</div>
