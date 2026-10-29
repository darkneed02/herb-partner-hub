<?php

defined( 'ABSPATH' ) || exit;

/**
 * Dropshippers › Products: every simple and variable product (with its variations) in one
 * table, editable in place, with bulk actions and CSV export/import.
 * All writes go through HPH_Catalog::apply() and WC_Product::save().
 */
final class HPH_Products_Admin {
	public const PAGE     = 'hph-products';
	private const PER_PAGE = 30;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_hph_products_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_hph_products_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_hph_products_import', array( $this, 'handle_import' ) );
	}

	public function menu(): void {
		add_submenu_page( HPH_Admin::PARENT, __( 'Dropshipper products', 'herb-partner-hub' ), __( 'Products', 'herb-partner-hub' ), 'hph_manage', self::PAGE, array( $this, 'render' ), 2 );
	}

	/**
	 * joined: dropshippers can buy it · missing: ticked but no price yet, so still hidden · not: everything else.
	 */
	public static function status( WC_Product $product ): string {
		if ( HPH_Catalog::is_participating( $product ) ) {
			return 'joined';
		}
		if ( ! $product->is_type( 'variation' ) && HPH_Catalog::enabled_meta( $product->get_id() ) ) {
			return 'missing';
		}
		return 'not';
	}

	private static function status_tag( string $status ): string {
		$map = array(
			'joined'  => array( 'g', '● ' . __( 'Joined', 'herb-partner-hub' ) ),
			'missing' => array( 'y', '! ' . __( 'Missing price', 'herb-partner-hub' ) ),
			'not'     => array( 'r', '○ ' . __( 'Not joined', 'herb-partner-hub' ) ),
		);
		return '<span class="hph-tag hph-tag--' . $map[ $status ][0] . '">' . esc_html( $map[ $status ][1] ) . '</span>';
	}

	/**
	 * Every simple and variable product (title order) with its status, then the filter and page.
	 */
	private function parents( string $filter, string $search, int $page ): array {
		$args = array(
			'type'     => array( 'simple', 'variable' ),
			'status'   => array( 'publish', 'private', 'draft' ),
			'orderby'  => 'title',
			'order'    => 'ASC',
			'limit'    => -1,
			'return'   => 'objects',
			'hph_skip' => true,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$all = wc_get_products( $args );
		if ( '' !== $search ) {
			$by_sku = wc_get_products( array_merge( $args, array( 's' => '', 'sku' => $search ) ) );
			$all    = array_values( array_unique( array_merge( $all, $by_sku ), SORT_REGULAR ) );
		}

		$counts = array( 'joined' => 0, 'not' => 0, 'missing' => 0 );
		$rows   = array();
		foreach ( $all as $product ) {
			$status = self::status( $product );
			++$counts[ $status ];
			if ( '' === $filter || $filter === $status ) {
				$rows[] = $product;
			}
		}

		return array(
			'items'  => array_slice( $rows, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ),
			'total'  => count( $rows ),
			'counts' => $counts + array( 'all' => count( $all ) ),
		);
	}

	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list navigation.
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page   = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:enable
		$data    = $this->parents( $filter, $search, $page );
		$base    = admin_url( 'admin.php?page=' . self::PAGE );
		$notice  = get_transient( 'hph_products_notice_' . get_current_user_id() );
		$filters = array(
			''        => array( __( 'All', 'herb-partner-hub' ), $data['counts']['all'] ),
			'joined'  => array( __( 'Joined', 'herb-partner-hub' ), $data['counts']['joined'] ),
			'not'     => array( __( 'Not joined', 'herb-partner-hub' ), $data['counts']['not'] ),
			'missing' => array( __( 'Missing price', 'herb-partner-hub' ), $data['counts']['missing'] ),
		);
		delete_transient( 'hph_products_notice_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Dropshipper products', 'herb-partner-hub' ); ?></h1>
			<button type="button" class="page-title-action" aria-expanded="false" aria-controls="hph-import" onclick="var b=document.getElementById('hph-import');b.hidden=!b.hidden;this.setAttribute('aria-expanded',String(!b.hidden));"><?php esc_html_e( 'Import CSV', 'herb-partner-hub' ); ?></button>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hph_products_export' ), 'hph_products_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'herb-partner-hub' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['errors'] ? 'warning' : 'success' ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
					<?php if ( $notice['errors'] ) : ?>
						<ul style="list-style:disc;margin-left:20px">
							<?php foreach ( array_slice( $notice['errors'], 0, 20 ) as $error ) : ?>
								<li><?php echo esc_html( $error ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="hph-import" id="hph-import" hidden>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="hph_products_import">
					<?php wp_nonce_field( 'hph_products_import' ); ?>
					<p><strong><?php esc_html_e( 'Import prices from CSV', 'herb-partner-hub' ); ?></strong></p>
					<p class="description"><?php esc_html_e( 'Use a file from Export CSV. Only the id, dropshipper_price and open columns are read. An empty dropshipper_price removes the price.', 'herb-partner-hub' ); ?></p>
					<p><input type="file" name="csv" accept=".csv,text/csv" required> <button class="button"><?php esc_html_e( 'Import', 'herb-partner-hub' ); ?></button></p>
				</form>
			</div>

			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $filters as $key => $info ) {
					$url     = '' === $key ? $base : add_query_arg( 'filter', $key, $base );
					$current = $filter === $key ? ' class="current" aria-current="page"' : '';
					$links[] = '<li><a href="' . esc_url( $url ) . '"' . $current . '>' . esc_html( $info[0] ) . ' <span class="count">(' . (int) $info[1] . ')</span></a>';
				}
				echo wp_kses_post( implode( ' | </li>', $links ) . '</li>' );
				?>
			</ul>
			<br class="clear">

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hph_products_save">
				<?php wp_nonce_field( 'hph_products_save' ); ?>
				<input type="hidden" name="return" value="<?php echo esc_url( add_query_arg( array( 'filter' => $filter, 's' => $search, 'paged' => $page ), $base ) ); ?>">

				<div class="hph-toolbar">
					<select name="bulk_action" aria-label="<?php esc_attr_e( 'Bulk action', 'herb-partner-hub' ); ?>">
						<option value=""><?php esc_html_e( 'Bulk actions', 'herb-partner-hub' ); ?></option>
						<option value="open"><?php esc_html_e( 'Join', 'herb-partner-hub' ); ?></option>
						<option value="close"><?php esc_html_e( 'Remove', 'herb-partner-hub' ); ?></option>
						<option value="percent"><?php esc_html_e( 'Set price as % of retail', 'herb-partner-hub' ); ?></option>
					</select>
					<input type="number" name="bulk_percent" min="1" max="100" step="0.01" class="small-text" placeholder="%" aria-label="<?php esc_attr_e( 'Percent of retail', 'herb-partner-hub' ); ?>">
					<button class="button" name="do" value="bulk"><?php esc_html_e( 'Apply', 'herb-partner-hub' ); ?></button>
					<span class="hph-search">
						<input type="search" name="s" form="hph-products-search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search products…', 'herb-partner-hub' ); ?>" aria-label="<?php esc_attr_e( 'Search products', 'herb-partner-hub' ); ?>">
						<button class="button" form="hph-products-search"><?php esc_html_e( 'Search', 'herb-partner-hub' ); ?></button>
					</span>
				</div>

				<table class="widefat striped hph-table">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.hph-row-cb').forEach(c => c.checked = this.checked)" aria-label="<?php esc_attr_e( 'Select all', 'herb-partner-hub' ); ?>"></td>
							<th><?php esc_html_e( 'Product', 'herb-partner-hub' ); ?></th>
							<th class="column-joined"><?php esc_html_e( 'Joined', 'herb-partner-hub' ); ?></th>
							<th><?php esc_html_e( 'Retail price', 'herb-partner-hub' ); ?></th>
							<th><?php esc_html_e( 'Dropshipper price', 'herb-partner-hub' ); ?></th>
							<th><?php esc_html_e( 'Off retail', 'herb-partner-hub' ); ?></th>
							<th><?php esc_html_e( 'Stock', 'herb-partner-hub' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( ! $data['items'] ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No products found.', 'herb-partner-hub' ); ?></td></tr>
					<?php endif; ?>
					<?php
					foreach ( $data['items'] as $parent ) {
						$this->row( $parent, null );
						if ( $parent->is_type( 'variable' ) ) {
							foreach ( $parent->get_children() as $child_id ) {
								$child = wc_get_product( $child_id );
								if ( $child ) {
									$this->row( $child, $parent );
								}
							}
						}
					}
					?>
					</tbody>
				</table>

				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => add_query_arg( 'paged', '%#%' ),
									'format'  => '',
									'current' => $page,
									'total'   => max( 1, (int) ceil( $data['total'] / self::PER_PAGE ) ),
								)
							) ?? ''
						);
						?>
					</div>
				</div>

				<div class="hph-save-row">
					<button class="button button-primary" name="do" value="save"><?php esc_html_e( 'Save changes', 'herb-partner-hub' ); ?></button>
					<span class="description"><?php esc_html_e( 'Changes apply to new carts right away. Orders already placed keep their price.', 'herb-partner-hub' ); ?></span>
				</div>
			</form>
			<form method="get" id="hph-products-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php if ( $filter ) : ?>
					<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
				<?php endif; ?>
			</form>
		</div>
		<script>
		document.querySelectorAll('.hph-toggle-variations').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var open = btn.getAttribute('aria-expanded') !== 'true';
				btn.setAttribute('aria-expanded', String(open));
				btn.querySelector('span').textContent = open ? '▾' : '▸';
				document.querySelectorAll('tr[data-parent="' + btn.dataset.parent + '"]').forEach(function (r) { r.hidden = !open; });
			});
		});
		</script>
		<?php
	}

	private static function money_range( array $values ): string {
		$values = array_filter( $values );
		if ( ! $values ) {
			return '—';
		}
		return min( $values ) === max( $values ) ? wc_price( min( $values ) ) : wc_format_price_range( min( $values ), max( $values ) );
	}

	private static function stock_label( WC_Product $product ): string {
		$options = wc_get_product_stock_status_options();
		$label   = $options[ $product->get_stock_status() ] ?? $product->get_stock_status();
		if ( $product->managing_stock() ) {
			$label .= ' (' . (int) $product->get_stock_quantity() . ')';
		}
		return $label;
	}

	private function price_history( int $id ): string {
		$entries = HPH_Log::query( array( 'action' => 'product_pricing_changed', 'object_id' => $id, 'limit' => 5 ) );
		if ( ! $entries ) {
			return '';
		}
		$items = '';
		foreach ( $entries as $entry ) {
			$before = json_decode( (string) $entry['before_json'], true ) ?: array();
			$after  = json_decode( (string) $entry['after_json'], true ) ?: array();
			$who    = $entry['actor_id'] ? get_userdata( (int) $entry['actor_id'] ) : null;
			$items .= '<li>' . esc_html(
				sprintf(
					/* translators: 1: date, 2: user, 3: old price, 4: new price */
					__( '%1$s · %2$s · %3$s → %4$s', 'herb-partner-hub' ),
					wp_date( get_option( 'date_format' ), strtotime( $entry['created_at'] . ' UTC' ) ),
					$who ? $who->display_name : '—',
					'' === (string) ( $before['price'] ?? '' ) ? '—' : $before['price'],
					'' === (string) ( $after['price'] ?? '' ) ? '—' : $after['price']
				)
			) . '</li>';
		}
		return '<details class="hph-history"><summary>' . esc_html__( 'Price history', 'herb-partner-hub' ) . '</summary><ul>' . $items . '</ul></details>';
	}

	private function row( WC_Product $product, ?WC_Product $parent ): void {
		$id       = $product->get_id();
		$variable = $product->is_type( 'variable' );
		$status   = self::status( $product );
		$price    = HPH_Catalog::price_meta( $id );
		$label    = $parent ? '↳ ' . wc_get_formatted_variation( $product, true, false, false ) : $product->get_name();

		if ( $variable ) {
			$kids   = array_filter( array_map( 'wc_get_product', $product->get_children() ) );
			$retail = self::money_range( array_map( static fn( $c ) => (float) $c->get_regular_price( 'edit' ), $kids ) );
			$offs   = array();
			foreach ( $kids as $kid ) {
				$kid_price  = HPH_Catalog::price_meta( $kid->get_id() );
				$kid_retail = (float) $kid->get_regular_price( 'edit' );
				if ( $kid_price && $kid_retail ) {
					$offs[] = (int) round( ( 1 - $kid_price / $kid_retail ) * 100 );
				}
			}
			$off = $offs ? ( min( $offs ) === max( $offs ) ? min( $offs ) . '%' : min( $offs ) . '–' . max( $offs ) . '%' ) : '—';
		} else {
			$retail_value = (float) $product->get_regular_price( 'edit' );
			$retail       = $retail_value ? wc_price( $retail_value ) : '—';
			$off          = $retail_value && $price ? round( ( 1 - $price / $retail_value ) * 100 ) . '%' : '—';
		}
		?>
		<tr<?php echo $parent ? ' class="hph-child" data-parent="' . esc_attr( (string) $parent->get_id() ) . '"' : ''; ?>>
			<th scope="row" class="check-column">
				<?php if ( ! $parent ) : ?>
					<input type="checkbox" class="hph-row-cb" name="selected[]" value="<?php echo esc_attr( (string) $id ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
				<?php endif; ?>
				<input type="hidden" name="rows[]" value="<?php echo esc_attr( (string) $id ); ?>">
			</th>
			<td>
				<?php if ( $parent ) : ?>
					<?php echo esc_html( $label ); ?>
				<?php else : ?>
					<strong><a class="row-title" href="<?php echo esc_url( get_edit_post_link( $id ) ?? '' ); ?>"><?php echo esc_html( $label ); ?></a></strong>
					<?php if ( 'publish' !== $product->get_status() ) : ?>
						<span class="post-state">— <?php echo esc_html( get_post_status_object( $product->get_status() )->label ?? $product->get_status() ); ?></span>
					<?php endif; ?>
					<div class="row-actions visible">
						<a href="<?php echo esc_url( get_edit_post_link( $id ) ?? '' ); ?>"><?php esc_html_e( 'Edit product', 'herb-partner-hub' ); ?></a>
						<?php if ( $variable ) : ?>
							| <button type="button" class="button-link hph-toggle-variations" aria-expanded="true" data-parent="<?php echo esc_attr( (string) $id ); ?>">
								<?php
								/* translators: %d: number of variations */
								echo esc_html( sprintf( _n( '%d variation', '%d variations', count( $product->get_children() ), 'herb-partner-hub' ), count( $product->get_children() ) ) );
								?>
								<span aria-hidden="true">▾</span>
							</button>
						<?php endif; ?>
					</div>
					<?php echo $this->price_history( $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in price_history(). ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $parent ) : ?>
					<?php echo self::status_tag( $status ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php else : ?>
					<label class="hph-switch">
						<input type="checkbox" role="switch" name="enabled[<?php echo esc_attr( (string) $id ); ?>]" value="1" <?php checked( HPH_Catalog::enabled_meta( $id ) ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name */ __( '%s joined', 'herb-partner-hub' ), $label ) ); ?>">
						<span class="hph-switch__track" aria-hidden="true"></span>
						<span class="hph-switch__on" aria-hidden="true"><?php esc_html_e( 'Enabled', 'herb-partner-hub' ); ?></span>
						<span class="hph-switch__off" aria-hidden="true"><?php esc_html_e( 'Disabled', 'herb-partner-hub' ); ?></span>
					</label>
					<?php if ( 'missing' === $status ) : ?>
						<span class="hph-switch-note"><?php esc_html_e( 'Needs a price to show', 'herb-partner-hub' ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</td>
			<td><?php echo wp_kses_post( $retail ); ?></td>
			<td<?php echo 'missing' === $status && ! $variable ? ' class="hph-missing"' : ''; ?>>
				<?php if ( $variable ) : ?>
					<span class="description"><?php esc_html_e( 'set per variation', 'herb-partner-hub' ); ?></span>
				<?php else : ?>
					<span class="hph-money"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
						<input type="text" class="wc_input_price" name="price[<?php echo esc_attr( (string) $id ); ?>]" value="<?php echo esc_attr( null === $price ? '' : wc_format_localized_price( (string) $price ) ); ?>" placeholder="–" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name */ __( 'Dropshipper price for %s', 'herb-partner-hub' ), $label ) ); ?>">
					</span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $off ); ?></td>
			<td><?php echo esc_html( self::stock_label( $product ) ); ?></td>
		</tr>
		<?php
	}

	public function handle_save(): void {
		$this->guard( 'hph_products_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$rows     = array_map( 'absint', (array) ( $_POST['rows'] ?? array() ) );
		$selected = array_map( 'absint', (array) ( $_POST['selected'] ?? array() ) );
		$prices   = (array) wp_unslash( $_POST['price'] ?? array() );
		$enabled  = (array) ( $_POST['enabled'] ?? array() );
		$bulk     = 'bulk' === ( $_POST['do'] ?? '' ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) ) : '';
		$percent  = (float) ( $_POST['bulk_percent'] ?? 0 );
		// wp_validate_redirect() returns '' (not the fallback) for an empty value.
		$return   = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return'] ?? '' ) ), '' ) ?: admin_url( 'admin.php?page=' . self::PAGE );
		// phpcs:enable

		$changed = 0;
		$errors  = array();

		if ( 'bulk' === ( $_POST['do'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! $selected || ! in_array( $bulk, array( 'open', 'close', 'percent' ), true ) ) {
				$errors[] = __( 'Tick some rows and choose a bulk action.', 'herb-partner-hub' );
			} elseif ( 'percent' === $bulk && ( $percent <= 0 || $percent > 100 ) ) {
				$errors[] = __( 'Enter a percentage between 1 and 100.', 'herb-partner-hub' );
			} else {
				foreach ( $selected as $id ) {
					$product = wc_get_product( $id );
					if ( ! $product ) {
						continue;
					}
					if ( 'percent' === $bulk ) {
						$targets = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
						foreach ( $targets as $target ) {
							$retail = (float) $target->get_regular_price( 'edit' );
							if ( $retail <= 0 ) {
								/* translators: %s: product name */
								$errors[] = sprintf( __( '%s has no retail price; skipped.', 'herb-partner-hub' ), $target->get_name() );
								continue;
							}
							$changed += $this->apply_and_save( $target, (string) round( $retail * $percent / 100, 2 ), null, $errors );
						}
					} elseif ( ! $product->is_type( 'variation' ) ) {
						$changed += $this->apply_and_save( $product, null, 'open' === $bulk, $errors );
					}
				}
			}
		} else {
			foreach ( $rows as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product ) {
					continue;
				}
				$new_price = $product->is_type( 'variable' ) ? null : (string) ( $prices[ $id ] ?? '' );
				$old_price = HPH_Catalog::price_meta( $id );
				if ( null !== $new_price && '' !== trim( $new_price ) && null !== $old_price && (float) wc_format_decimal( $new_price ) === $old_price ) {
					$new_price = null;
				} elseif ( null !== $new_price && '' === trim( $new_price ) && null === $old_price ) {
					$new_price = null;
				}
				$new_enabled = $product->is_type( 'variation' ) ? null : ! empty( $enabled[ $id ] );
				if ( null !== $new_enabled && $new_enabled === HPH_Catalog::enabled_meta( $id ) ) {
					$new_enabled = null;
				}
				if ( null === $new_price && null === $new_enabled ) {
					continue;
				}
				$changed += $this->apply_and_save( $product, $new_price, $new_enabled, $errors );
			}
		}

		$this->notice(
			/* translators: %d: number of products changed */
			sprintf( _n( '%d product updated.', '%d products updated.', $changed, 'herb-partner-hub' ), $changed ),
			$errors
		);
		wp_safe_redirect( $return );
		exit;
	}

	private function apply_and_save( WC_Product $product, ?string $price, ?bool $enabled, array &$errors ): int {
		$result = HPH_Catalog::apply( $product, $price, $enabled );
		$product->save();
		if ( is_wp_error( $result ) ) {
			$errors[] = $result->get_error_message();
			return 0;
		}
		return $result['before'] == $result['after'] ? 0 : 1; // phpcs:ignore Universal.Operators.StrictComparisons
	}

	public function handle_export(): void {
		$this->guard( 'hph_products_export', 'GET' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=dropshipper-prices-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'parent_id', 'type', 'sku', 'name', 'retail_price', 'dropshipper_price', 'open' ) );
		$parents = wc_get_products(
			array(
				'type'     => array( 'simple', 'variable' ),
				'status'   => array( 'publish', 'private', 'draft' ),
				'orderby'  => 'title',
				'order'    => 'ASC',
				'limit'    => -1,
				'hph_skip' => true,
			)
		);
		foreach ( $parents as $parent ) {
			$products = array( $parent );
			if ( $parent->is_type( 'variable' ) ) {
				$products = array_merge( $products, array_filter( array_map( 'wc_get_product', $parent->get_children() ) ) );
			}
			foreach ( $products as $p ) {
				$price = HPH_Catalog::price_meta( $p->get_id() );
				fputcsv(
					$out,
					array_map(
						array( $this, 'csv_cell' ),
						array(
							$p->get_id(),
							$p->get_parent_id() ?: '',
							$p->get_type(),
							$p->get_sku( 'edit' ),
							$p->is_type( 'variation' ) ? wp_strip_all_tags( $p->get_name() ) : $p->get_name(),
							$p->is_type( 'variable' ) ? '' : $p->get_regular_price( 'edit' ),
							null === $price ? '' : wc_format_decimal( $price ),
							$p->is_type( 'variation' ) ? '' : ( HPH_Catalog::enabled_meta( $p->get_id() ) ? 'yes' : 'no' ),
						)
					)
				);
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		HPH_Log::add( 'products_exported', 'product', 0 );
		exit;
	}

	public function handle_import(): void {
		$this->guard( 'hph_products_import' );

		$file   = $_FILES['csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- checked in guard(); only tmp_name/size/error are read.
		$errors = array();
		$done   = 0;

		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 2 * MB_IN_BYTES || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$errors[] = __( 'Upload a CSV file smaller than 2 MB.', 'herb-partner-hub' );
		} else {
			$fh     = fopen( $file['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$header = $fh ? fgetcsv( $fh ) : false;
			$header = $header ? array_map( static fn( $h ) => strtolower( trim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) ), $header ) : array();
			$col    = array_flip( $header );
			if ( ! isset( $col['id'], $col['dropshipper_price'] ) ) {
				$errors[] = __( 'The file needs at least the columns id and dropshipper_price.', 'herb-partner-hub' );
			} else {
				$line = 1;
				while ( ( $cells = fgetcsv( $fh ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
					++$line;
					$product = wc_get_product( absint( $cells[ $col['id'] ] ?? 0 ) );
					if ( ! $product || ! $product->is_type( array( 'simple', 'variable', 'variation' ) ) ) {
						/* translators: %d: CSV line number */
						$errors[] = sprintf( __( 'Line %d: product not found.', 'herb-partner-hub' ), $line );
						continue;
					}
					$price   = $product->is_type( 'variable' ) ? null : ltrim( trim( (string) ( $cells[ $col['dropshipper_price'] ] ?? '' ) ), "'" );
					$enabled = null;
					if ( isset( $col['open'] ) && ! $product->is_type( 'variation' ) ) {
						$raw     = strtolower( trim( (string) ( $cells[ $col['open'] ] ?? '' ) ) );
						$enabled = '' === $raw ? null : in_array( $raw, array( 'yes', '1', 'y', 'true' ), true );
					}
					$done += $this->apply_and_save( $product, $price, $enabled, $errors );
				}
			}
			if ( $fh ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}

		HPH_Log::add( 'products_imported', 'product', 0, null, array( 'changed' => $done, 'errors' => count( $errors ) ) );
		/* translators: %d: number of products changed */
		$this->notice( sprintf( _n( 'Import finished: %d product updated.', 'Import finished: %d products updated.', $done, 'herb-partner-hub' ), $done ), $errors );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Spreadsheet apps run cells that start with = + - @ as formulas.
	 */
	public function csv_cell( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) && ! is_numeric( $value ) ? "'" . $value : $value;
	}

	private function guard( string $action, string $method = 'POST' ): void {
		if ( ! current_user_can( 'hph_manage' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'herb-partner-hub' ), 403 );
		}
		check_admin_referer( $action );
	}

	private function notice( string $message, array $errors ): void {
		set_transient( 'hph_products_notice_' . get_current_user_id(), array( 'message' => $message, 'errors' => $errors ), MINUTE_IN_SECONDS * 5 );
	}
}
