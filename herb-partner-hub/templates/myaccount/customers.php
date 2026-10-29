<?php
/**
 * My Account › My Customers. Copy to yourtheme/herb-partner-hub/myaccount/customers.php to override.
 *
 * @var array      $list     array( 'rows' => array[], 'total' => int ) for this page
 * @var array      $stats    end customer id => array( 'count', 'last' )
 * @var int        $total    all customers of this dropshipper
 * @var int        $pages
 * @var int        $page
 * @var string     $search
 * @var array|null $form     array( 'id', 'old', 'errors' ) when the add/edit panel is open
 * @var array|null $deleting row awaiting delete confirmation
 */

defined( 'ABSPATH' ) || exit;

$hph_countries = WC()->countries->get_shipping_countries();
$hph_date      = static fn( string $utc ): string => '' === $utc ? '—' : wp_date( 'j M Y', strtotime( $utc . ' UTC' ) );
?>
<div class="hph hph-acc hph-customers">
	<div class="hph-acc__head">
		<div>
			<h2 class="hph-acc__title"><?php esc_html_e( 'My Customers', 'herb-partner-hub' ); ?></h2>
			<p class="hph-muted hph-small">
				<?php
				/* translators: %d: number of customers */
				echo esc_html( sprintf( _n( '%d customer', '%d customers', $total, 'herb-partner-hub' ), $total ) );
				echo ' · ';
				esc_html_e( 'only you can see this list', 'herb-partner-hub' );
				?>
			</p>
		</div>
		<a class="hph-btn" href="<?php echo esc_url( HPH_Customers_Account::url( array( 'add' => 1 ) ) . '#hph-customer-form' ); ?>">+ <?php esc_html_e( 'Add customer', 'herb-partner-hub' ); ?></a>
	</div>

	<?php if ( $deleting ) : ?>
		<div class="hph-card hph-card--danger" id="hph-customer-delete">
			<p>
				<strong>
					<?php
					/* translators: %s: customer name */
					echo esc_html( sprintf( __( 'Delete %s?', 'herb-partner-hub' ), HPH_Customer_Repo::name( $deleting ) ) );
					?>
				</strong>
				<br><?php esc_html_e( 'Their name, address, phone and note are erased from My Customers. Orders you already placed keep the address they were shipped to.', 'herb-partner-hub' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( HPH_Customers_Account::url() ); ?>" class="hph-actions">
				<input type="hidden" name="hph_action" value="<?php echo esc_attr( HPH_Customers_Account::ACTION_DELETE ); ?>">
				<input type="hidden" name="hph_customer_id" value="<?php echo esc_attr( (string) $deleting['id'] ); ?>">
				<?php wp_nonce_field( HPH_Customers_Account::ACTION_DELETE . '_' . $deleting['id'], '_hph_nonce' ); ?>
				<button type="submit" class="hph-btn hph-btn--danger"><?php esc_html_e( 'Delete customer', 'herb-partner-hub' ); ?></button>
				<a class="hph-btn hph-btn--ghost" href="<?php echo esc_url( HPH_Customers_Account::url() ); ?>"><?php esc_html_e( 'Cancel', 'herb-partner-hub' ); ?></a>
			</form>
		</div>
	<?php endif; ?>

	<?php if ( $total > HPH_Customer_Repo::PER_PAGE || '' !== $search ) : ?>
		<form class="hph-search" method="get" action="<?php echo esc_url( HPH_Customers_Account::url() ); ?>" role="search">
			<label class="screen-reader-text" for="hph-customer-q"><?php esc_html_e( 'Search customers', 'herb-partner-hub' ); ?></label>
			<input type="search" id="hph-customer-q" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, phone, email or city', 'herb-partner-hub' ); ?>">
			<button type="submit" class="hph-btn hph-btn--ghost hph-btn--sm"><?php esc_html_e( 'Search', 'herb-partner-hub' ); ?></button>
			<?php if ( '' !== $search ) : ?>
				<a href="<?php echo esc_url( HPH_Customers_Account::url() ); ?>" class="hph-small"><?php esc_html_e( 'Clear', 'herb-partner-hub' ); ?></a>
			<?php endif; ?>
		</form>
	<?php endif; ?>

	<?php if ( $list['rows'] ) : ?>
		<div class="hph-card hph-card--flush">
			<table class="hph-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Customer', 'herb-partner-hub' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Ship to', 'herb-partner-hub' ); ?></th>
						<th scope="col" class="hph-r"><?php esc_html_e( 'Orders', 'herb-partner-hub' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last order', 'herb-partner-hub' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'herb-partner-hub' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $list['rows'] as $hph_row ) : ?>
						<?php $hph_s = $stats[ (int) $hph_row['id'] ] ?? array(); ?>
						<tr<?php echo ( $form && (int) $form['id'] === (int) $hph_row['id'] ) ? ' class="is-current"' : ''; ?>>
							<td data-title="<?php esc_attr_e( 'Customer', 'herb-partner-hub' ); ?>">
								<b><?php echo esc_html( HPH_Customer_Repo::name( $hph_row ) ); ?></b>
								<span class="hph-muted hph-block"><?php echo esc_html( $hph_row['phone'] ); ?></span>
							</td>
							<td data-title="<?php esc_attr_e( 'Ship to', 'herb-partner-hub' ); ?>"><?php echo esc_html( HPH_Customer_Repo::place( $hph_row ) ); ?></td>
							<td data-title="<?php esc_attr_e( 'Orders', 'herb-partner-hub' ); ?>" class="hph-r hph-num"><?php echo esc_html( (string) ( $hph_s['count'] ?? 0 ) ); ?></td>
							<td data-title="<?php esc_attr_e( 'Last order', 'herb-partner-hub' ); ?>"><?php echo esc_html( $hph_date( $hph_s['last'] ?? '' ) ); ?></td>
							<td class="hph-r">
								<a class="hph-btn hph-btn--ghost hph-btn--sm" href="<?php echo esc_url( HPH_Customers_Account::url( array( 'edit' => $hph_row['id'] ) ) . '#hph-customer-form' ); ?>">
									<?php esc_html_e( 'Edit', 'herb-partner-hub' ); ?><span class="screen-reader-text"> <?php echo esc_html( HPH_Customer_Repo::name( $hph_row ) ); ?></span>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $pages > 1 ) : ?>
			<nav class="hph-pager" aria-label="<?php esc_attr_e( 'Customer pages', 'herb-partner-hub' ); ?>">
				<?php for ( $hph_p = 1; $hph_p <= $pages; $hph_p++ ) : ?>
					<?php if ( $hph_p === $page ) : ?>
						<span class="is-current" aria-current="page"><?php echo esc_html( (string) $hph_p ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( HPH_Customers_Account::url( array_filter( array( 'pg' => $hph_p, 'q' => $search ) ) ) ); ?>"><?php echo esc_html( (string) $hph_p ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>
	<?php elseif ( '' !== $search ) : ?>
		<p class="hph-card hph-muted"><?php esc_html_e( 'No customers match your search.', 'herb-partner-hub' ); ?></p>
	<?php elseif ( ! $form ) : ?>
		<div class="hph-card hph-empty">
			<p><strong><?php esc_html_e( 'No customers yet.', 'herb-partner-hub' ); ?></strong></p>
			<p class="hph-muted"><?php esc_html_e( 'Save the people you ship to and pick them at checkout. You can also save a new customer while placing an order.', 'herb-partner-hub' ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	if ( $form ) :
		$hph_old  = $form['old'];
		$hph_errs = $form['errors'];
		$hph_val  = static fn( string $k ): string => is_scalar( $hph_old[ $k ] ?? null ) ? (string) $hph_old[ $k ] : '';
		$hph_err  = static function ( string $k ) use ( $hph_errs ): void {
			if ( ! empty( $hph_errs[ $k ] ) ) {
				echo '<p class="hph-error" id="hph-cerr-' . esc_attr( $k ) . '">' . esc_html( $hph_errs[ $k ] ) . '</p>';
			}
		};
		$hph_inv  = static fn( string $k ): string => empty( $hph_errs[ $k ] ) ? '' : ' aria-invalid="true" aria-describedby="hph-cerr-' . esc_attr( $k ) . '"';
		$hph_ctry = $hph_val( 'country' );
		$hph_sts  = $hph_ctry ? WC()->countries->get_states( $hph_ctry ) : array();
		$hph_req  = '<span class="hph-req" aria-hidden="true">*</span>';
		?>
		<div class="hph-card" id="hph-customer-form">
			<h3 class="hph-card__title">
				<?php
				echo esc_html(
					$form['id']
						/* translators: %s: customer name */
						? sprintf( __( 'Edit: %s', 'herb-partner-hub' ), HPH_Customer_Repo::name( $hph_old ) )
						: __( 'Add customer', 'herb-partner-hub' )
				);
				?>
			</h3>

			<?php if ( $hph_errs ) : ?>
				<div class="hph-notice hph-notice--error" role="alert"><?php esc_html_e( 'Please check the highlighted fields.', 'herb-partner-hub' ); ?></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( HPH_Customers_Account::url( $form['id'] ? array( 'edit' => $form['id'] ) : array( 'add' => 1 ) ) ); ?>" class="hph-form" novalidate>
				<input type="hidden" name="hph_action" value="<?php echo esc_attr( HPH_Customers_Account::ACTION_SAVE ); ?>">
				<input type="hidden" name="hph_customer_id" value="<?php echo esc_attr( (string) $form['id'] ); ?>">
				<?php wp_nonce_field( HPH_Customers_Account::ACTION_SAVE . '_' . $form['id'], '_hph_nonce' ); ?>

				<div class="hph-grid3">
					<div class="hph-fld">
						<label for="hph-c-first_name"><?php esc_html_e( 'First name', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
						<input type="text" id="hph-c-first_name" name="hph_customer[first_name]" value="<?php echo esc_attr( $hph_val( 'first_name' ) ); ?>" autocomplete="off"<?php echo $hph_inv( 'first_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php $hph_err( 'first_name' ); ?>
					</div>
					<div class="hph-fld">
						<label for="hph-c-last_name"><?php esc_html_e( 'Last name', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
						<input type="text" id="hph-c-last_name" name="hph_customer[last_name]" value="<?php echo esc_attr( $hph_val( 'last_name' ) ); ?>" autocomplete="off"<?php echo $hph_inv( 'last_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php $hph_err( 'last_name' ); ?>
					</div>
					<div class="hph-fld">
						<label for="hph-c-phone"><?php esc_html_e( 'Phone (for the courier)', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
						<input type="tel" id="hph-c-phone" name="hph_customer[phone]" value="<?php echo esc_attr( $hph_val( 'phone' ) ); ?>" autocomplete="off" placeholder="+60 12 345 6789"<?php echo $hph_inv( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php $hph_err( 'phone' ); ?>
					</div>
				</div>

				<div class="hph-grid2">
					<div class="hph-fld">
						<label for="hph-c-address_1"><?php esc_html_e( 'Address', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
						<input type="text" id="hph-c-address_1" name="hph_customer[address_1]" value="<?php echo esc_attr( $hph_val( 'address_1' ) ); ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'House number and street name', 'herb-partner-hub' ); ?>"<?php echo $hph_inv( 'address_1' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php $hph_err( 'address_1' ); ?>
					</div>
					<div class="hph-fld">
						<label for="hph-c-address_2"><?php esc_html_e( 'Apartment, unit, building (optional)', 'herb-partner-hub' ); ?></label>
						<input type="text" id="hph-c-address_2" name="hph_customer[address_2]" value="<?php echo esc_attr( $hph_val( 'address_2' ) ); ?>" autocomplete="off">
					</div>
				</div>

				<div class="hph-grid3">
					<div class="hph-fld">
						<label for="hph-c-city"><?php esc_html_e( 'City', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
						<input type="text" id="hph-c-city" name="hph_customer[city]" value="<?php echo esc_attr( $hph_val( 'city' ) ); ?>" autocomplete="off"<?php echo $hph_inv( 'city' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php $hph_err( 'city' ); ?>
					</div>
					<div class="hph-fld">
						<label for="hph-c-postcode"><?php esc_html_e( 'Postcode', 'herb-partner-hub' ); ?></label>
						<input type="text" id="hph-c-postcode" name="hph_customer[postcode]" value="<?php echo esc_attr( $hph_val( 'postcode' ) ); ?>" autocomplete="off"<?php echo $hph_inv( 'postcode' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php $hph_err( 'postcode' ); ?>
					</div>
					<div class="hph-fld" data-hph-state>
						<label for="hph-c-state"><?php esc_html_e( 'State / Province', 'herb-partner-hub' ); ?></label>
						<?php if ( is_array( $hph_sts ) && $hph_sts ) : ?>
							<select id="hph-c-state" name="hph_customer[state]"<?php echo $hph_inv( 'state' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<option value=""><?php esc_html_e( 'Choose…', 'herb-partner-hub' ); ?></option>
								<?php foreach ( $hph_sts as $hph_code => $hph_name ) : ?>
									<option value="<?php echo esc_attr( $hph_code ); ?>" <?php selected( $hph_val( 'state' ), $hph_code ); ?>><?php echo esc_html( $hph_name ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="text" id="hph-c-state" name="hph_customer[state]" value="<?php echo esc_attr( $hph_val( 'state' ) ); ?>" autocomplete="off"<?php echo $hph_inv( 'state' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php endif; ?>
						<?php $hph_err( 'state' ); ?>
					</div>
				</div>

				<div class="hph-grid2">
					<div class="hph-fld">
						<label for="hph-c-country"><?php esc_html_e( 'Country', 'herb-partner-hub' ); ?> <?php echo $hph_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
						<select id="hph-c-country" name="hph_customer[country]" data-hph-country<?php echo $hph_inv( 'country' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
							<option value=""><?php esc_html_e( 'Choose a country…', 'herb-partner-hub' ); ?></option>
							<?php foreach ( $hph_countries as $hph_code => $hph_name ) : ?>
								<option value="<?php echo esc_attr( $hph_code ); ?>" <?php selected( $hph_ctry, $hph_code ); ?>><?php echo esc_html( $hph_name ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php $hph_err( 'country' ); ?>
					</div>
					<div class="hph-fld">
						<label for="hph-c-email"><?php esc_html_e( 'Email (optional)', 'herb-partner-hub' ); ?></label>
						<input type="email" id="hph-c-email" name="hph_customer[email]" value="<?php echo esc_attr( $hph_val( 'email' ) ); ?>" autocomplete="off"<?php echo $hph_inv( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php $hph_err( 'email' ); ?>
					</div>
				</div>

				<div class="hph-fld">
					<label for="hph-c-note"><?php esc_html_e( 'Private note (only you)', 'herb-partner-hub' ); ?></label>
					<textarea id="hph-c-note" name="hph_customer[note]" rows="2" placeholder="<?php esc_attr_e( 'e.g. Prefers delivery after 5 pm', 'herb-partner-hub' ); ?>"><?php echo esc_textarea( $hph_val( 'note' ) ); ?></textarea>
					<p class="hph-hint"><?php esc_html_e( 'Not printed on the parcel or sent with the order.', 'herb-partner-hub' ); ?></p>
				</div>

				<div class="hph-actions">
					<button type="submit" class="hph-btn"><?php esc_html_e( 'Save customer', 'herb-partner-hub' ); ?></button>
					<a class="hph-btn hph-btn--ghost" href="<?php echo esc_url( HPH_Customers_Account::url() ); ?>"><?php esc_html_e( 'Cancel', 'herb-partner-hub' ); ?></a>
					<?php if ( $form['id'] ) : ?>
						<a class="hph-link-danger" href="<?php echo esc_url( HPH_Customers_Account::url( array( 'delete' => $form['id'] ) ) . '#hph-customer-delete' ); ?>"><?php esc_html_e( 'Delete customer', 'herb-partner-hub' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		</div>
	<?php endif; ?>
</div>
