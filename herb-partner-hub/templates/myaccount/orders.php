<?php
/**
 * My Account › Orders for dropshippers (WooCommerce's list for everyone else).
 * Copy to yourtheme/herb-partner-hub/myaccount/orders.php to override.
 *
 * @var array  $rows    from HPH_Orders::rows()
 * @var array  $f       filters: month, customer, status, pg
 * @var int    $total   orders matching the filters
 * @var int    $pages
 * @var array  $months  'Y-m' keys, newest first
 * @var array  $people  'self' / end customer id => name
 * @var array  $active  filters in use
 * @var string $export  CSV link for the current filters
 */

defined( 'ABSPATH' ) || exit;

$has_orders = $total > 0;
$hph_money = static fn( float $v, string $cur ): string => wp_strip_all_tags( wc_price( $v, array( 'currency' => $cur ) ) );
?>
<?php
// Same hooks as WooCommerce's orders.php: WooCommerce's email verification prompt and
// payment plugins use them.
do_action( 'woocommerce_before_account_orders', $has_orders );
?>
<div class="hph hph-acc hph-orders">
	<div class="hph-acc__head">
		<div>
			<h2 class="hph-acc__title"><?php esc_html_e( 'Orders', 'herb-partner-hub' ); ?></h2>
			<p class="hph-muted hph-small">
				<?php
				/* translators: %d: number of orders */
				echo esc_html( sprintf( _n( '%d order', '%d orders', $total, 'herb-partner-hub' ), $total ) );
				?>
			</p>
		</div>
	</div>

	<form class="hph-filters" method="get" action="<?php echo esc_url( HPH_Orders::url() ); ?>">
		<label class="screen-reader-text" for="hph-f-month"><?php esc_html_e( 'Month', 'herb-partner-hub' ); ?></label>
		<select id="hph-f-month" name="month">
			<option value=""><?php esc_html_e( 'All months', 'herb-partner-hub' ); ?></option>
			<?php foreach ( $months as $hph_m ) : ?>
				<option value="<?php echo esc_attr( $hph_m ); ?>" <?php selected( $f['month'], $hph_m ); ?>><?php echo esc_html( HPH_Sales::month_label( $hph_m ) ); ?></option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="hph-f-customer"><?php esc_html_e( 'Ship to', 'herb-partner-hub' ); ?></label>
		<select id="hph-f-customer" name="customer">
			<option value=""><?php esc_html_e( 'All customers', 'herb-partner-hub' ); ?></option>
			<?php foreach ( $people as $hph_id => $hph_name ) : ?>
				<option value="<?php echo esc_attr( (string) $hph_id ); ?>" <?php selected( $f['customer'], (string) $hph_id ); ?>><?php echo esc_html( $hph_name ); ?></option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="hph-f-status"><?php esc_html_e( 'Status', 'herb-partner-hub' ); ?></label>
		<select id="hph-f-status" name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'herb-partner-hub' ); ?></option>
			<?php foreach ( HPH_Orders::statuses() as $hph_key => $hph_label ) : ?>
				<option value="<?php echo esc_attr( substr( $hph_key, 3 ) ); ?>" <?php selected( $f['status'], substr( $hph_key, 3 ) ); ?>><?php echo esc_html( $hph_label ); ?></option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="hph-btn hph-btn--ghost hph-btn--sm"><?php esc_html_e( 'Filter', 'herb-partner-hub' ); ?></button>
		<?php if ( $active ) : ?>
			<a class="hph-small" href="<?php echo esc_url( HPH_Orders::url() ); ?>"><?php esc_html_e( 'Clear', 'herb-partner-hub' ); ?></a>
		<?php endif; ?>
		<a class="hph-btn hph-btn--ghost hph-btn--sm hph-filters__end" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'herb-partner-hub' ); ?></a>
	</form>

	<?php if ( $rows ) : ?>
		<div class="hph-card hph-card--flush">
			<table class="hph-table hph-table--wide">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Order', 'herb-partner-hub' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Ship to', 'herb-partner-hub' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'herb-partner-hub' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tracking', 'herb-partner-hub' ); ?></th>
						<th scope="col" class="hph-r"><?php esc_html_e( 'Total', 'herb-partner-hub' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'herb-partner-hub' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $hph_r ) : ?>
						<tr>
							<td data-title="<?php esc_attr_e( 'Order', 'herb-partner-hub' ); ?>">
								<a href="<?php echo esc_url( $hph_r['url'] ); ?>"><b>#<?php echo esc_html( $hph_r['number'] ); ?></b></a>
								<span class="hph-muted hph-block hph-small hph-nowrap"><?php echo esc_html( $hph_r['date'] ); ?></span>
							</td>
							<td data-title="<?php esc_attr_e( 'Ship to', 'herb-partner-hub' ); ?>">
								<?php echo esc_html( $hph_r['ship']['name'] ); ?>
								<?php if ( '' !== $hph_r['ship']['country'] ) : ?>
									<span class="hph-muted hph-block hph-small"><?php echo esc_html( WC()->countries->countries[ $hph_r['ship']['country'] ] ?? $hph_r['ship']['country'] ); ?></span>
								<?php endif; ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Status', 'herb-partner-hub' ); ?>"><span class="hph-st hph-st--<?php echo esc_attr( HPH_Orders::status_tone( $hph_r['status'] ) ); ?>"><?php echo esc_html( $hph_r['label'] ); ?></span></td>
							<td data-title="<?php esc_attr_e( 'Tracking', 'herb-partner-hub' ); ?>">
								<?php if ( '' !== $hph_r['tracking'] ) : ?>
									<a href="<?php echo esc_url( 'https://www.dhl.com/global-en/home/tracking/tracking-express.html?tracking-id=' . rawurlencode( $hph_r['tracking'] ) ); ?>" target="_blank" rel="noopener">DHL <?php echo esc_html( $hph_r['tracking'] ); ?></a>
								<?php else : ?>
									<span class="hph-muted">—</span>
								<?php endif; ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Total', 'herb-partner-hub' ); ?>" class="hph-r hph-num">
								<?php if ( null !== $hph_r['gross'] ) : ?>
									<s class="hph-muted"><?php echo esc_html( $hph_money( $hph_r['gross'], $hph_r['currency'] ) ); ?></s>
								<?php endif; ?>
								<?php echo esc_html( $hph_money( $hph_r['total'], $hph_r['currency'] ) ); ?>
							</td>
							<td class="hph-r">
								<span class="hph-row-actions">
									<?php foreach ( $hph_r['actions'] as $hph_key => $hph_action ) : ?>
										<a class="hph-btn hph-btn--sm<?php echo 'pay' === $hph_key ? '' : ' hph-btn--ghost'; ?> <?php echo esc_attr( sanitize_html_class( $hph_key ) ); ?>" href="<?php echo esc_url( $hph_action['url'] ); ?>">
											<?php echo esc_html( $hph_action['name'] ); ?><span class="screen-reader-text"> #<?php echo esc_html( $hph_r['number'] ); ?></span>
										</a>
									<?php endforeach; ?>
									<?php if ( '' !== $hph_r['reorder'] ) : ?>
										<a class="hph-btn hph-btn--ghost hph-btn--sm" href="<?php echo esc_url( $hph_r['reorder'] ); ?>">
											<?php esc_html_e( 'Reorder', 'herb-partner-hub' ); ?><span class="screen-reader-text"> #<?php echo esc_html( $hph_r['number'] ); ?></span>
										</a>
									<?php endif; ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $pages > 1 ) : ?>
			<nav class="hph-pager" aria-label="<?php esc_attr_e( 'Order pages', 'herb-partner-hub' ); ?>">
				<?php for ( $hph_p = 1; $hph_p <= $pages; $hph_p++ ) : ?>
					<?php if ( $hph_p === $f['pg'] ) : ?>
						<span class="is-current" aria-current="page"><?php echo esc_html( (string) $hph_p ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( HPH_Orders::url( $active + array( 'pg' => $hph_p ) ) ); ?>"><?php echo esc_html( (string) $hph_p ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>
	<?php elseif ( $active ) : ?>
		<p class="hph-card hph-muted"><?php esc_html_e( 'No orders match these filters.', 'herb-partner-hub' ); ?></p>
	<?php else : ?>
		<div class="hph-card hph-empty">
			<p><strong><?php esc_html_e( 'No orders yet.', 'herb-partner-hub' ); ?></strong></p>
			<p class="hph-muted"><?php esc_html_e( 'Orders you place at wholesale prices appear here with who they shipped to.', 'herb-partner-hub' ); ?></p>
		</div>
	<?php endif; ?>
</div>
<?php do_action( 'woocommerce_after_account_orders', $has_orders ); ?>
