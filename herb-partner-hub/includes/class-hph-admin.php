<?php

defined( 'ABSPATH' ) || exit;

/**
 * The Dropshippers menu. Later modules add their own submenus under PARENT.
 * Screens use WP core markup plus assets/css/admin.css (tags, boxes, sidebar layout
 * from the plan's mockups).
 */
final class HPH_Admin {
	public const PARENT = 'herb-partner-hub';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_filter( 'option_page_capability_' . HPH_Settings::OPTION_KEY, array( $this, 'settings_capability' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Only on the plugin's own screens and the product editor.
	 */
	public function enqueue( $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PARENT === $page || str_starts_with( $page, 'hph-' ) || in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_style( 'hph-admin', HPH_URL . 'assets/css/admin.css', array(), (string) filemtime( HPH_PATH . 'assets/css/admin.css' ) );
		}
	}

	public function menu(): void {
		add_menu_page(
			__( 'Dropshippers', 'herb-partner-hub' ),
			__( 'Dropshippers', 'herb-partner-hub' ),
			'hph_view_reports',
			self::PARENT,
			array( $this, 'render_status' ),
			'dashicons-groups',
			56
		);
		add_submenu_page( self::PARENT, __( 'Overview', 'herb-partner-hub' ), __( 'Overview', 'herb-partner-hub' ), 'hph_view_reports', self::PARENT, array( $this, 'render_status' ) );
		add_submenu_page( self::PARENT, __( 'Settings', 'herb-partner-hub' ), __( 'Settings', 'herb-partner-hub' ), 'hph_manage', 'hph-settings', array( $this, 'render_settings' ) );
	}

	public function settings_capability(): string {
		return 'hph_manage';
	}

	public function render_status(): void {
		$settings = HPH_Settings::all();
		$labels   = HPH_Settings::module_labels();
		$missing  = HPH_Install::missing_tables();
		$partners = count( get_users( array( 'role' => HPH_Install::ROLE, 'fields' => 'ID' ) ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dropshipper overview', 'herb-partner-hub' ); ?></h1>

			<?php if ( $missing ) : ?>
				<div class="notice notice-error"><p>
					<?php
					/* translators: %s: comma-separated table names */
					echo esc_html( sprintf( __( 'Missing database tables: %s. Deactivate and activate the plugin again.', 'herb-partner-hub' ), implode( ', ', $missing ) ) );
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( $settings['modules']['pricing'] && ! $settings['modules']['discounts'] ) : ?>
				<div class="notice notice-error inline"><p>
					<?php esc_html_e( 'Dropshipper prices are on but "Block site promotions and coupons" is off: dropshippers can get sale discounts on top of their price. Switch both on together.', 'herb-partner-hub' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( $settings['soft_launch'] ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					/* translators: %d: number of user IDs on the soft launch list */
					echo esc_html( sprintf( _n( 'Soft launch is on: dropshipper features work for %d listed account only.', 'Soft launch is on: dropshipper features work for %d listed accounts only.', count( $settings['soft_launch_users'] ), 'herb-partner-hub' ), count( $settings['soft_launch_users'] ) ) );
					?>
				</p></div>
			<?php endif; ?>

			<?php
			if ( has_action( 'hph_admin_overview' ) ) {
				/**
				 * Sales figures (reports module). The notices above stay first: they matter most at cutover.
				 */
				do_action( 'hph_admin_overview' );
			} else {
				echo '<p class="description">' . esc_html__( 'Switch on "Reports" under Settings › Rollout to see dropshipper sales here.', 'herb-partner-hub' ) . '</p>';
			}
			?>

			<h2><?php esc_html_e( 'System status', 'herb-partner-hub' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<thead><tr><th><?php esc_html_e( 'Module', 'herb-partner-hub' ); ?></th><th><?php esc_html_e( 'State', 'herb-partner-hub' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( HPH_Settings::modules() as $key ) : ?>
					<tr>
						<td><?php echo esc_html( $labels[ $key ] ?? $key ); ?></td>
						<td><?php echo $settings['modules'][ $key ] ? esc_html__( 'On', 'herb-partner-hub' ) : esc_html__( 'Off', 'herb-partner-hub' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<?php
				/* translators: %d: number of users with the dropshipper role */
				echo esc_html( sprintf( __( 'Accounts with the Dropshipper role: %d', 'herb-partner-hub' ), $partners ) );
				?>
				<br>
				<?php
				/* translators: 1: plugin version, 2: database schema version */
				echo esc_html( sprintf( __( 'Plugin %1$s · database schema %2$s', 'herb-partner-hub' ), HPH_VERSION, (string) get_option( HPH_Install::DB_OPTION ) ) );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Discount sources the plugin switches off for dropshippers, and whether each is installed here.
	 */
	private static function discount_sources(): array {
		return array(
			__( 'Stherb Promo Engine (ladder, free gift tiers, campaign banners)', 'herb-partner-hub' ) => class_exists( 'Stherb_Promo_Plugin' ),
			__( 'Woo Discount Rules', 'herb-partner-hub' )                                           => defined( 'WDR_VERSION' ),
			__( 'Tier Pricing Table', 'herb-partner-hub' )                                           => class_exists( '\TierPricingTable\TierPricingTablePlugin' ),
			__( 'WooCommerce coupons', 'herb-partner-hub' )                                          => true,
			__( 'Power Coupons', 'herb-partner-hub' )                                                => defined( 'POWER_COUPONS_VERSION' ),
		);
	}

	public function render_settings(): void {
		$s       = HPH_Settings::all();
		$name    = HPH_Settings::OPTION_KEY;
		$guard   = ! empty( $s['modules']['discounts'] );
		$emails  = WC()->mailer()->get_emails();
		$notify  = isset( $emails['HPH_Email_New_Application'] ) ? $emails['HPH_Email_New_Application']->get_recipient() : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Settings', 'herb-partner-hub' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( $name ); ?>

				<h2><?php esc_html_e( 'Prices and promotions', 'herb-partner-hub' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Products not joined', 'herb-partner-hub' ); ?></th>
						<td><?php esc_html_e( 'Hidden from dropshippers: not in the shop, search or category pages, the product page shows "not found", and they cannot be added to the cart.', 'herb-partner-hub' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Blocked for dropshippers', 'herb-partner-hub' ); ?></th>
						<td>
							<ul class="hph-sources">
								<?php foreach ( self::discount_sources() as $label => $installed ) : ?>
									<li>
										<?php if ( ! $installed ) : ?>
											<span class="hph-tag"><?php esc_html_e( 'Not installed', 'herb-partner-hub' ); ?></span>
										<?php elseif ( $guard ) : ?>
											<span class="hph-tag hph-tag--g"><?php esc_html_e( 'Blocked', 'herb-partner-hub' ); ?></span>
										<?php else : ?>
											<span class="hph-tag hph-tag--r"><?php esc_html_e( 'Not blocked', 'herb-partner-hub' ); ?></span>
										<?php endif; ?>
										<?php echo esc_html( $label ); ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="description"><?php esc_html_e( 'Every source is blocked together by the "Block site promotions and coupons" switch under Rollout.', 'herb-partner-hub' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Applications', 'herb-partner-hub' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Notify', 'herb-partner-hub' ); ?></th>
						<td>
							<code><?php echo esc_html( $notify ?: get_option( 'admin_email' ) ); ?></code>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=hph_email_new_application' ) ); ?>"><?php esc_html_e( 'Change', 'herb-partner-hub' ); ?></a>
							<p class="description"><?php esc_html_e( 'Who gets the "new application" email. Set in WooCommerce › Settings › Emails, like every other store email.', 'herb-partner-hub' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hph-terms-page"><?php esc_html_e( 'Terms page', 'herb-partner-hub' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => "{$name}[terms_page_id]",
									'id'                => 'hph-terms-page',
									'selected'          => (int) $s['terms_page_id'],
									'show_option_none'  => __( '— Not set —', 'herb-partner-hub' ),
									'option_none_value' => 0,
									'post_status'       => array( 'publish', 'draft' ),
								)
							);
							?>
							<label for="hph-terms-version" style="margin-left:12px"><?php esc_html_e( 'version', 'herb-partner-hub' ); ?></label>
							<input type="text" id="hph-terms-version" class="small-text" maxlength="20" name="<?php echo esc_attr( "{$name}[terms_version]" ); ?>" value="<?php echo esc_attr( $s['terms_version'] ); ?>">
							<p class="description"><?php esc_html_e( 'Linked from the application form. Change the version when the terms change; the version each dropshipper accepted is kept.', 'herb-partner-hub' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Rollout', 'herb-partner-hub' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Each part can be switched on separately. Everything is off after installing.', 'herb-partner-hub' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Parts switched on', 'herb-partner-hub' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( HPH_Settings::module_labels() as $key => $label ) : ?>
									<label style="display:block;margin-bottom:6px"><input type="checkbox" name="<?php echo esc_attr( "{$name}[modules][{$key}]" ); ?>" value="1" <?php checked( $s['modules'][ $key ] ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Soft launch', 'herb-partner-hub' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( "{$name}[soft_launch]" ); ?>" value="1" <?php checked( $s['soft_launch'] ); ?>> <?php esc_html_e( 'Only these user IDs get dropshipper prices and features:', 'herb-partner-hub' ); ?></label><br>
							<input type="text" id="hph-soft-users" class="regular-text" style="margin-top:6px" name="<?php echo esc_attr( "{$name}[soft_launch_users]" ); ?>" value="<?php echo esc_attr( implode( ', ', $s['soft_launch_users'] ) ); ?>" aria-label="<?php esc_attr_e( 'User IDs', 'herb-partner-hub' ); ?>">
							<p class="description"><?php esc_html_e( 'Comma separated. Use a test dropshipper account, not an administrator.', 'herb-partner-hub' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'When deleting the plugin', 'herb-partner-hub' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( "{$name}[delete_data_on_uninstall]" ); ?>" value="1" <?php checked( $s['delete_data_on_uninstall'] ); ?>> <?php esc_html_e( 'Also delete all dropshipper data (applications, end customers, wholesale prices, log)', 'herb-partner-hub' ); ?></label>
							<p class="description"><?php esc_html_e( 'Leave off. Deactivating never deletes anything.', 'herb-partner-hub' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
