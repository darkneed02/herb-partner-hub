<?php

defined( 'ABSPATH' ) || exit;

/**
 * Module "reports": sales figures on Dropshippers › Overview, plus a CSV of the month.
 * Commission parts of the mockup are gone with the scope change (2026-10-28).
 * Numbers come from HPH_Sales, the same definition the partner dashboard uses.
 */
final class HPH_Reports {
	public const CSV_ACTION = 'hph_overview_csv';

	public function __construct() {
		add_action( 'hph_admin_overview', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::CSV_ACTION, array( $this, 'export' ) );
	}

	public const PER_PAGE_CHOICES = array( 10, 20, 50, 100 );

	public static function per_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view switch only.
		$n = absint( $_GET['per_page'] ?? 0 );
		return in_array( $n, self::PER_PAGE_CHOICES, true ) ? $n : 20;
	}

	public static function selected_month(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view switch only.
		$month = sanitize_text_field( wp_unslash( $_GET['month'] ?? '' ) );
		return HPH_Sales::valid_month( $month ) ? $month : HPH_Sales::current_month();
	}

	/** Saved end customers per dropshipper (deleted ones not counted). */
	public static function customer_counts(): array {
		global $wpdb;
		$table = hph_table( 'end_customers' );
		$out   = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from hph_table().
		foreach ( $wpdb->get_results( "SELECT owner_user_id AS id, COUNT(*) AS n FROM $table WHERE deleted_at IS NULL GROUP BY owner_user_id", ARRAY_A ) ?: array() as $row ) {
			$out[ (int) $row['id'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** @return array{count: int, oldest_days: int} */
	public static function waiting_applications(): array {
		global $wpdb;
		$table = hph_table( 'applications' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from hph_table().
		$row = $wpdb->get_row( "SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM $table WHERE status IN ('new','needs_info')", ARRAY_A );
		$n   = (int) ( $row['n'] ?? 0 );
		return array(
			'count'       => $n,
			'oldest_days' => $n && ! empty( $row['oldest'] ) ? (int) floor( ( time() - strtotime( $row['oldest'] . ' UTC' ) ) / DAY_IN_SECONDS ) : 0,
		);
	}

	public static function active_dropshippers(): array {
		$ids = get_users(
			array(
				'role'       => HPH_Install::ROLE,
				'fields'     => 'ID',
				'meta_key'   => HPH_Context::STATUS_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- dropshipper role only.
				'meta_value' => 'active', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_map( 'intval', $ids );
	}

	public static function partner_name( int $user_id ): string {
		$company = (string) get_user_meta( $user_id, 'billing_company', true );
		$user    = get_userdata( $user_id );
		if ( '' !== $company ) {
			return $company;
		}
		return $user ? $user->display_name : sprintf( '#%d', $user_id );
	}

	/**
	 * Rows for the ranking table and the CSV: every active dropshipper, plus anyone with
	 * orders this month who is no longer active.
	 */
	public static function ranking( string $month ): array {
		$sales     = HPH_Sales::by_dropshipper( $month );
		$customers = self::customer_counts();
		$ids       = array_unique( array_merge( array_keys( $sales ), self::active_dropshippers() ) );
		$rows      = array();
		foreach ( $ids as $id ) {
			$user   = get_userdata( $id );
			$rows[] = array(
				'id'        => $id,
				'name'      => self::partner_name( $id ),
				'email'     => $user ? $user->user_email : '',
				'country'   => (string) get_user_meta( $id, 'billing_country', true ),
				'orders'    => $sales[ $id ]['orders'] ?? 0,
				'total'     => $sales[ $id ]['total'] ?? 0.0,
				'customers' => $customers[ $id ] ?? 0,
			);
		}
		usort( $rows, static fn( $a, $b ) => array( $b['total'], $b['orders'] ) <=> array( $a['total'], $a['orders'] ) );
		return $rows;
	}

	public function render(): void {
		$month    = self::selected_month();
		$summary  = HPH_Sales::month( 0, $month );
		$months   = HPH_Sales::by_month( 0, 6, $month );
		$max      = max( 1.0, max( array_column( $months, 'total' ) ) );
		$ranking  = self::ranking( $month );
		$active   = count( self::active_dropshippers() );
		$ordered  = count( array_filter( $ranking, static fn( $r ) => $r['orders'] > 0 ) );
		$saved    = array_sum( self::customer_counts() );
		$waiting  = self::waiting_applications();
		$csv      = wp_nonce_url( add_query_arg( array( 'action' => self::CSV_ACTION, 'month' => $month ), admin_url( 'admin-post.php' ) ), self::CSV_ACTION );
		$apps_url = admin_url( 'admin.php?page=hph-applications' );
		$money    = static fn( float $v ): string => wp_strip_all_tags( wc_price( $v ) );
		$per_page = self::per_page();
		$pages    = max( 1, (int) ceil( count( $ranking ) / $per_page ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view switch only.
		$paged    = min( $pages, max( 1, absint( $_GET['paged'] ?? 1 ) ) );
		$offset   = ( $paged - 1 ) * $per_page;
		$shown    = array_slice( $ranking, $offset, $per_page );
		$base     = array( 'page' => HPH_Admin::PARENT, 'month' => $month );
		if ( 20 !== $per_page ) {
			$base['per_page'] = $per_page;
		}
		?>
		<form method="get" class="hph-ov-head">
			<input type="hidden" name="page" value="<?php echo esc_attr( HPH_Admin::PARENT ); ?>">
			<?php if ( 20 !== $per_page ) : ?>
				<input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $per_page ); ?>">
			<?php endif; ?>
			<label for="hph-ov-month" class="screen-reader-text"><?php esc_html_e( 'Month', 'herb-partner-hub' ); ?></label>
			<select id="hph-ov-month" name="month" onchange="this.form.submit()">
				<?php foreach ( array_reverse( HPH_Sales::months( 12 ) ) as $m ) : ?>
					<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $month, $m ); ?>><?php echo esc_html( HPH_Sales::month_label( $m ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<noscript><button type="submit" class="button"><?php esc_html_e( 'Show', 'herb-partner-hub' ); ?></button></noscript>
			<a class="button" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Export CSV', 'herb-partner-hub' ); ?></a>
		</form>

		<div class="hph-cards">
			<div class="hph-box">
				<span class="description"><?php esc_html_e( 'Dropshipper sales', 'herb-partner-hub' ); ?></span>
				<span class="hph-cards__v"><?php echo esc_html( $money( $summary['total'] ) ); ?></span>
				<span class="description">
					<?php
					/* translators: %d: number of orders */
					echo esc_html( sprintf( _n( '%d order · incl. shipping, after refunds', '%d orders · incl. shipping, after refunds', $summary['orders'], 'herb-partner-hub' ), $summary['orders'] ) );
					?>
				</span>
			</div>
			<div class="hph-box">
				<span class="description"><?php esc_html_e( 'Active dropshippers', 'herb-partner-hub' ); ?></span>
				<span class="hph-cards__v"><?php echo esc_html( number_format_i18n( $active ) ); ?></span>
				<span class="description">
					<?php
					/* translators: %d: number of dropshippers */
					echo esc_html( sprintf( _n( '%d ordered this month', '%d ordered this month', $ordered, 'herb-partner-hub' ), $ordered ) );
					?>
				</span>
			</div>
			<div class="hph-box">
				<span class="description"><?php esc_html_e( 'End customers saved', 'herb-partner-hub' ); ?></span>
				<span class="hph-cards__v"><?php echo esc_html( number_format_i18n( $saved ) ); ?></span>
				<span class="description"><?php esc_html_e( 'in all dropshippers\' lists', 'herb-partner-hub' ); ?></span>
			</div>
			<div class="hph-box">
				<span class="description"><?php esc_html_e( 'Applications waiting', 'herb-partner-hub' ); ?></span>
				<a class="hph-cards__v<?php echo $waiting['count'] ? ' is-alert' : ''; ?>" href="<?php echo esc_url( $apps_url ); ?>"><?php echo esc_html( number_format_i18n( $waiting['count'] ) ); ?></a>
				<span class="description">
					<?php
					if ( $waiting['count'] ) {
						/* translators: %d: days */
						echo esc_html( sprintf( _n( 'oldest %d day', 'oldest %d days', $waiting['oldest_days'], 'herb-partner-hub' ), $waiting['oldest_days'] ) );
					} else {
						esc_html_e( 'nothing to review', 'herb-partner-hub' );
					}
					?>
				</span>
			</div>
		</div>

		<div class="hph-box">
			<h2><?php esc_html_e( 'Sales by month (dropshipper channel)', 'herb-partner-hub' ); ?></h2>
			<div class="hph-chart">
				<?php foreach ( $months as $key => $m ) : ?>
					<div class="hph-chart__col<?php echo $key === $month ? ' is-now' : ''; ?>">
						<span class="hph-chart__v"><?php echo esc_html( $money( $m['total'] ) ); ?></span>
						<i style="height:<?php echo esc_attr( (string) max( 2, round( $m['total'] / $max * 100 ) ) ); ?>%"></i>
						<a href="<?php echo esc_url( add_query_arg( array_merge( $base, array( 'month' => $key ) ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( HPH_Sales::month_label( $key, 'M Y' ) ); ?></a>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<table class="widefat striped hph-rank">
			<thead>
				<tr>
					<th scope="col">
						<?php
						/* translators: %s: month name */
						echo esc_html( sprintf( __( 'Dropshippers — %s', 'herb-partner-hub' ), HPH_Sales::month_label( $month ) ) );
						?>
					</th>
					<th scope="col" class="num"><?php esc_html_e( 'Orders', 'herb-partner-hub' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Sales', 'herb-partner-hub' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'End customers', 'herb-partner-hub' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $ranking ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No dropshippers yet.', 'herb-partner-hub' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $shown as $r ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( get_edit_user_link( $r['id'] ) ); ?>"><b><?php echo esc_html( $r['name'] ); ?></b></a>
							<span class="description"><?php echo esc_html( trim( $r['email'] . ( $r['country'] ? ' · ' . $r['country'] : '' ) ) ); ?></span>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( $r['orders'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $money( $r['total'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $r['customers'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $ranking ) : ?>
			<?php $this->render_pager( $base, count( $ranking ), $per_page, $paged, $pages, $offset, count( $shown ) ); ?>
		<?php endif; ?>
		<?php
	}

	/** Rows-per-page choice and page links under the ranking table, in WP list-table markup. */
	private function render_pager( array $base, int $total, int $per_page, int $paged, int $pages, int $offset, int $count ): void {
		$link   = static fn( int $p ): string => add_query_arg( array_merge( $base, array( 'paged' => $p ) ), admin_url( 'admin.php' ) );
		$arrows = array(
			array( 1, '&laquo;', __( 'First page', 'herb-partner-hub' ), 'first-page', $paged > 1 ),
			array( $paged - 1, '&lsaquo;', __( 'Previous page', 'herb-partner-hub' ), 'prev-page', $paged > 1 ),
			array( $paged + 1, '&rsaquo;', __( 'Next page', 'herb-partner-hub' ), 'next-page', $paged < $pages ),
			array( $pages, '&raquo;', __( 'Last page', 'herb-partner-hub' ), 'last-page', $paged < $pages ),
		);
		?>
		<div class="tablenav bottom hph-rank-nav">
			<form method="get" class="hph-per-page">
				<input type="hidden" name="page" value="<?php echo esc_attr( HPH_Admin::PARENT ); ?>">
				<input type="hidden" name="month" value="<?php echo esc_attr( $base['month'] ); ?>">
				<label for="hph-per-page"><?php esc_html_e( 'Show', 'herb-partner-hub' ); ?></label>
				<select id="hph-per-page" name="per_page" onchange="this.form.submit()">
					<?php foreach ( self::PER_PAGE_CHOICES as $n ) : ?>
						<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $per_page, $n ); ?>><?php echo esc_html( (string) $n ); ?></option>
					<?php endforeach; ?>
				</select>
				<span><?php esc_html_e( 'per page', 'herb-partner-hub' ); ?></span>
				<noscript><button type="submit" class="button"><?php esc_html_e( 'Show', 'herb-partner-hub' ); ?></button></noscript>
			</form>
			<div class="tablenav-pages<?php echo $pages < 2 ? ' one-page' : ''; ?>">
				<span class="displaying-num">
					<?php
					/* translators: 1: first row shown, 2: last row shown, 3: total dropshippers */
					echo esc_html( sprintf( __( '%1$d–%2$d of %3$d', 'herb-partner-hub' ), $offset + 1, $offset + $count, $total ) );
					?>
				</span>
				<?php if ( $pages > 1 ) : ?>
					<span class="pagination-links">
						<?php foreach ( $arrows as $i => list( $p, $glyph, $label, $class, $on ) ) : ?>
							<?php if ( 2 === $i ) : ?>
								<span class="paging-input">
									<?php
									/* translators: 1: current page, 2: total pages */
									echo esc_html( sprintf( __( '%1$d of %2$d', 'herb-partner-hub' ), $paged, $pages ) );
									?>
								</span>
							<?php endif; ?>
							<?php if ( $on ) : ?>
								<a class="<?php echo esc_attr( $class ); ?> button" href="<?php echo esc_url( $link( $p ) ); ?>"><span class="screen-reader-text"><?php echo esc_html( $label ); ?></span><span aria-hidden="true"><?php echo $glyph; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed entity. ?></span></a>
							<?php else : ?>
								<span class="tablenav-pages-navspan button disabled" aria-hidden="true"><?php echo $glyph; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed entity. ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function export(): void {
		if ( ! current_user_can( 'hph_view_reports' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'herb-partner-hub' ), 403 );
		}
		check_admin_referer( self::CSV_ACTION );
		$month = self::selected_month();

		$rows = array();
		foreach ( self::ranking( $month ) as $r ) {
			$rows[] = array( $month, $r['name'], $r['email'], $r['country'], $r['orders'], wc_format_decimal( $r['total'], wc_get_price_decimals() ), $r['customers'] );
		}
		HPH_Log::add( 'report_exported', 'report', 0, null, array( 'month' => $month ) );
		HPH_Sales::send_csv(
			'dropshippers-' . $month . '.csv',
			array(
				__( 'Month', 'herb-partner-hub' ),
				__( 'Dropshipper', 'herb-partner-hub' ),
				__( 'Email', 'herb-partner-hub' ),
				__( 'Country', 'herb-partner-hub' ),
				__( 'Orders', 'herb-partner-hub' ),
				__( 'Sales (incl. shipping, after refunds)', 'herb-partner-hub' ),
				__( 'End customers', 'herb-partner-hub' ),
			),
			$rows
		);
	}
}
