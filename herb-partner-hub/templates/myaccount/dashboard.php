<?php
/**
 * My Account front page for dropshippers (replaces WooCommerce's dashboard.php for them only).
 * Copy to yourtheme/herb-partner-hub/myaccount/dashboard.php to override.
 *
 * Totals are what the dropshipper paid, shipping included, after refunds (HPH_Sales).
 */

defined( 'ABSPATH' ) || exit;

$hph       = HPH_Orders::dashboard_data( get_current_user_id() );
$hph_money = static fn( float $v ): string => wp_strip_all_tags( wc_price( $v ) );
?>
<div class="hph hph-acc hph-dash">
	<div class="hph-acc__head">
		<div>
			<h2 class="hph-acc__title">
				<?php
				/* translators: %s: shop or person name */
				echo esc_html( sprintf( __( 'Hello, %s', 'herb-partner-hub' ), $hph['name'] ) );
				?>
			</h2>
			<p class="hph-muted hph-small"><?php echo esc_html( HPH_Sales::month_label( $hph['month'] ) ); ?> · <?php esc_html_e( 'dropshipper wholesale prices', 'herb-partner-hub' ); ?></p>
		</div>
		<a class="hph-btn" href="<?php echo esc_url( $hph['shop'] ); ?>">+ <?php esc_html_e( 'New order', 'herb-partner-hub' ); ?></a>
	</div>

	<div class="hph-tiles">
		<div class="hph-tile">
			<span class="hph-tile__k"><?php esc_html_e( 'Orders this month', 'herb-partner-hub' ); ?></span>
			<span class="hph-tile__v"><?php echo esc_html( number_format_i18n( $hph['this']['orders'] ) ); ?></span>
			<span class="hph-tile__d">
				<?php
				/* translators: 1: signed difference, e.g. +4, 2: short month name */
				echo esc_html( sprintf( __( '%1$s vs %2$s', 'herb-partner-hub' ), ( $hph['diff'] > 0 ? '+' : '' ) . $hph['diff'], $hph['prev_name'] ) );
				?>
			</span>
		</div>
		<div class="hph-tile">
			<span class="hph-tile__k"><?php esc_html_e( 'Order total this month', 'herb-partner-hub' ); ?></span>
			<span class="hph-tile__v"><?php echo esc_html( $hph_money( $hph['this']['total'] ) ); ?></span>
			<span class="hph-tile__d"><?php esc_html_e( 'incl. shipping, after refunds', 'herb-partner-hub' ); ?></span>
		</div>
		<div class="hph-tile">
			<span class="hph-tile__k"><?php esc_html_e( 'Last 6 months', 'herb-partner-hub' ); ?></span>
			<span class="hph-tile__v"><?php echo esc_html( $hph_money( $hph['six'] ) ); ?></span>
			<span class="hph-tile__d"><?php esc_html_e( 'order total', 'herb-partner-hub' ); ?></span>
		</div>
		<?php if ( null !== $hph['customers'] ) : ?>
			<div class="hph-tile">
				<span class="hph-tile__k"><?php esc_html_e( 'My customers', 'herb-partner-hub' ); ?></span>
				<span class="hph-tile__v"><?php echo esc_html( number_format_i18n( $hph['customers'] ) ); ?></span>
				<span class="hph-tile__d"><a href="<?php echo esc_url( HPH_Customers_Account::url() ); ?>"><?php esc_html_e( 'manage list', 'herb-partner-hub' ); ?></a></span>
			</div>
		<?php endif; ?>
	</div>

	<section class="hph-card" aria-labelledby="hph-months-title">
		<div class="hph-card__row">
			<h3 class="hph-card__title" id="hph-months-title"><?php esc_html_e( 'Order total by month', 'herb-partner-hub' ); ?></h3>
			<span class="hph-small hph-muted"><?php esc_html_e( 'last 6 months', 'herb-partner-hub' ); ?></span>
		</div>
		<div class="hph-bars" aria-hidden="true">
			<?php foreach ( $hph['months'] as $hph_key => $hph_m ) : ?>
				<div class="hph-bars__col<?php echo $hph_key === $hph['month'] ? ' is-now' : ''; ?>">
					<i style="height:<?php echo esc_attr( (string) max( 2, round( $hph_m['total'] / $hph['max'] * 100 ) ) ); ?>%"></i>
					<span><?php echo esc_html( HPH_Sales::month_label( $hph_key, 'M' ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<table class="hph-table hph-table--plain">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Month', 'herb-partner-hub' ); ?></th>
					<th scope="col" class="hph-r"><?php esc_html_e( 'Orders', 'herb-partner-hub' ); ?></th>
					<th scope="col" class="hph-r"><?php esc_html_e( 'Order total', 'herb-partner-hub' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_reverse( $hph['months'], true ) as $hph_key => $hph_m ) : ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Month', 'herb-partner-hub' ); ?>">
							<a href="<?php echo esc_url( HPH_Orders::url( array( 'month' => $hph_key ) ) ); ?>"><?php echo esc_html( HPH_Sales::month_label( $hph_key, 'M Y' ) ); ?></a>
						</td>
						<td data-title="<?php esc_attr_e( 'Orders', 'herb-partner-hub' ); ?>" class="hph-r hph-num"><?php echo esc_html( number_format_i18n( $hph_m['orders'] ) ); ?></td>
						<td data-title="<?php esc_attr_e( 'Order total', 'herb-partner-hub' ); ?>" class="hph-r hph-num"><?php echo esc_html( $hph_money( $hph_m['total'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<section class="hph-card hph-card--flush" aria-labelledby="hph-recent-title">
		<div class="hph-card__row hph-card__row--pad">
			<h3 class="hph-card__title" id="hph-recent-title"><?php esc_html_e( 'Recent orders', 'herb-partner-hub' ); ?></h3>
			<a class="hph-small" href="<?php echo esc_url( $hph['orders'] ); ?>"><?php esc_html_e( 'All orders', 'herb-partner-hub' ); ?> →</a>
		</div>
		<?php if ( $hph['recent'] ) : ?>
			<table class="hph-table">
				<tbody>
					<?php foreach ( $hph['recent'] as $hph_r ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( $hph_r['url'] ); ?>"><b>#<?php echo esc_html( $hph_r['number'] ); ?></b></a><span class="hph-muted hph-block hph-small hph-nowrap"><?php echo esc_html( $hph_r['date'] ); ?></span></td>
							<td><?php echo esc_html( $hph_r['ship']['name'] ); ?></td>
							<td><span class="hph-st hph-st--<?php echo esc_attr( HPH_Orders::status_tone( $hph_r['status'] ) ); ?>"><?php echo esc_html( $hph_r['label'] ); ?></span></td>
							<td class="hph-r hph-num"><?php echo esc_html( $hph_money( $hph_r['total'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="hph-muted hph-pad"><?php esc_html_e( 'No orders yet. Your first order at wholesale prices shows up here.', 'herb-partner-hub' ); ?></p>
		<?php endif; ?>
	</section>
</div>
<?php
// Same hooks as WooCommerce's dashboard.php, for plugins that add to the dashboard.
do_action( 'woocommerce_account_dashboard' );
do_action( 'woocommerce_before_my_account' ); // Deprecated in WooCommerce 2.6, still fired there.
do_action( 'woocommerce_after_my_account' );
