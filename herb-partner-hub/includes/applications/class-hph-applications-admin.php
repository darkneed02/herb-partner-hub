<?php

defined( 'ABSPATH' ) || exit;

/**
 * Dropshippers › Applications: the list (with Approve / Reject in each row), and the
 * review screen (?id=N): applicant details on the left, decisions in the sidebar.
 */
final class HPH_Applications_Admin {
	public const PAGE         = 'hph-applications';
	public const ACTION       = 'hph_application_decide';
	public const QUICK_ACTION = 'hph_application_quick';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::QUICK_ACTION, array( $this, 'handle_quick' ) );
	}

	public function menu(): void {
		$new   = HPH_Application_Repo::counts()['new'];
		$title = __( 'Applications', 'herb-partner-hub' );
		$menu  = $new ? $title . ' <span class="awaiting-mod">' . (int) $new . '</span>' : $title;
		add_submenu_page( HPH_Admin::PARENT, $title, $menu, 'hph_manage', self::PAGE, array( $this, 'render' ), 1 );
	}

	/**
	 * "Yes · 2 orders" for applicants who already have an account.
	 */
	public static function existing_customer( array $app ): array {
		$user = HPH_Approval::account_for( $app );
		if ( ! $user ) {
			return array( 'user' => null, 'orders' => 0 );
		}
		return array( 'user' => $user, 'orders' => (int) wc_get_customer_order_count( $user->ID ) );
	}

	public static function quick_url( int $id, string $decision ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::QUICK_ACTION,
					'id'       => $id,
					'decision' => $decision,
				),
				admin_url( 'admin-post.php' )
			),
			self::QUICK_ACTION . '_' . $id
		);
	}

	private function messages(): array {
		return array(
			'approved'   => __( 'Approved. The applicant has been emailed.', 'herb-partner-hub' ),
			'approved_quiet' => __( 'Approved. No email was sent; use "Send approval email again" when you are ready.', 'herb-partner-hub' ),
			'needs_info' => __( 'Marked as need info. The applicant has been emailed your question.', 'herb-partner-hub' ),
			'rejected'   => __( 'Marked as not approved. The applicant has been emailed.', 'herb-partner-hub' ),
			'resent'     => __( 'Approval email sent again.', 'herb-partner-hub' ),
			'note'       => __( 'Note saved.', 'herb-partner-hub' ),
		);
	}

	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display of our own redirect message.
		$msg = isset( $_GET['hph_msg'] ) ? sanitize_key( wp_unslash( $_GET['hph_msg'] ) ) : '';
		$err = isset( $_GET['hph_err'] ) ? sanitize_text_field( wp_unslash( $_GET['hph_err'] ) ) : '';
		// phpcs:enable
		$messages = $this->messages();
		if ( isset( $messages[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $msg ] ) . '</p></div>';
		}
		if ( $err ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $err ) . '</p></div>';
		}
	}

	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $id ) {
			$this->render_review( $id );
			return;
		}

		require_once __DIR__ . '/class-hph-applications-list-table.php';
		$table = new HPH_Applications_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Applications', 'herb-partner-hub' ); ?></h1>
			<hr class="wp-header-end">
			<?php $this->render_notices(); ?>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php
				$table->search_box( __( 'Search applications', 'herb-partner-hub' ), 'hph-app' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	private function render_review( int $id ): void {
		$app = HPH_Application_Repo::get( $id );
		if ( ! $app ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Application not found', 'herb-partner-hub' ) . '</h1></div>';
			return;
		}

		$countries = WC()->countries->get_countries();
		$existing  = self::existing_customer( $app );
		$account   = $existing['user'];
		$is_staff  = $account && user_can( $account, 'hph_manage' );
		$open      = in_array( $app['status'], HPH_Application_Repo::OPEN, true );
		$history   = HPH_Log::query( array( 'object_type' => 'application', 'object_id' => $id, 'limit' => 50 ) );
		$when      = static fn( ?string $utc ): string => $utc ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $utc . ' UTC' ) ) : '';
		$url       = (string) ( $app['fields']['channel_url'] ?? '' );
		$reviewer  = $app['reviewer_id'] ? get_userdata( (int) $app['reviewer_id'] ) : null;
		?>
		<div class="wrap">
			<h1>
				<?php
				/* translators: %s: applicant name */
				echo esc_html( sprintf( __( 'Application: %s', 'herb-partner-hub' ), $app['name'] ) );
				echo ' ' . HPH_Application_Repo::status_tag( $app['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in status_tag().
				?>
			</h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">&larr; <?php esc_html_e( 'All applications', 'herb-partner-hub' ); ?></a></p>
			<?php $this->render_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
				<?php wp_nonce_field( self::ACTION . '_' . $id ); ?>

				<div class="hph-two">
					<div class="hph-box">
						<h2><?php esc_html_e( 'Applicant details', 'herb-partner-hub' ); ?></h2>
						<dl class="hph-facts">
							<div>
								<dt><?php esc_html_e( 'Email', 'herb-partner-hub' ); ?></dt>
								<dd><a href="mailto:<?php echo esc_attr( $app['email'] ); ?>"><?php echo esc_html( $app['email'] ); ?></a></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Phone', 'herb-partner-hub' ); ?></dt>
								<dd><?php echo esc_html( $app['phone'] ); ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'LINE / WhatsApp', 'herb-partner-hub' ); ?></dt>
								<dd><?php echo esc_html( $app['fields']['contact'] ?? '' ); ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Store', 'herb-partner-hub' ); ?></dt>
								<dd>
									<?php echo esc_html( $app['business_name'] ); ?>
									<?php if ( '' !== $url ) : ?>
										· <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', $url ) ); ?></a>
									<?php endif; ?>
								</dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Sells on', 'herb-partner-hub' ); ?></dt>
								<dd><?php echo esc_html( implode( ', ', (array) ( $app['fields']['sales_channels'] ?? array() ) ) ); ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Country / market', 'herb-partner-hub' ); ?></dt>
								<dd><?php echo esc_html( html_entity_decode( $countries[ $app['country'] ] ?? $app['country'] ) ); ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Received', 'herb-partner-hub' ); ?></dt>
								<dd><?php echo esc_html( $when( $app['created_at'] ) ); ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Terms accepted', 'herb-partner-hub' ); ?></dt>
								<dd><?php echo esc_html( '' === $app['terms_version'] ? __( 'Yes (no version set)', 'herb-partner-hub' ) : sprintf( /* translators: %s: version */ __( 'Version %s', 'herb-partner-hub' ), $app['terms_version'] ) ); ?></dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Existing account', 'herb-partner-hub' ); ?></dt>
								<dd>
									<?php if ( ! $account ) : ?>
										<?php esc_html_e( 'No. A customer account is created on approval.', 'herb-partner-hub' ); ?>
									<?php elseif ( $is_staff ) : ?>
										<span class="hph-tag hph-tag--r"><?php esc_html_e( 'Staff account', 'herb-partner-hub' ); ?></span>
										<?php esc_html_e( 'Staff cannot be dropshippers; use "View as dropshipper" instead.', 'herb-partner-hub' ); ?>
									<?php else : ?>
										<?php
										printf(
											/* translators: 1: month and year, 2: number of orders */
											esc_html( _n( 'Yes, customer since %1$s · %2$d order', 'Yes, customer since %1$s · %2$d orders', $existing['orders'], 'herb-partner-hub' ) ),
											esc_html( wp_date( 'M Y', strtotime( $account->user_registered . ' UTC' ) ) ),
											(int) $existing['orders']
										);
										?>
										· <a href="<?php echo esc_url( get_edit_user_link( $account->ID ) ); ?>"><?php esc_html_e( 'view', 'herb-partner-hub' ); ?></a>
									<?php endif; ?>
								</dd>
							</div>
							<div class="is-wide">
								<dt><?php esc_html_e( 'Experience', 'herb-partner-hub' ); ?></dt>
								<dd><?php echo '' === $app['message'] ? '—' : nl2br( esc_html( $app['message'] ) ); ?></dd>
							</div>
						</dl>

						<h2 class="hph-box__sub"><label for="hph-note"><?php esc_html_e( 'Internal notes', 'herb-partner-hub' ); ?></label></h2>
						<textarea id="hph-note" name="note" rows="3" placeholder="<?php esc_attr_e( 'Only the team sees this.', 'herb-partner-hub' ); ?>"><?php echo esc_textarea( (string) $app['internal_note'] ); ?></textarea>
						<?php if ( ! $open ) : ?>
							<p><button type="submit" name="decision" value="note" class="button hph-button-auto"><?php esc_html_e( 'Save note', 'herb-partner-hub' ); ?></button></p>
						<?php endif; ?>
					</div>

					<div class="hph-side">
						<?php if ( $open ) : ?>
							<div class="hph-box">
								<h2><?php esc_html_e( 'Approve', 'herb-partner-hub' ); ?></h2>
								<p class="description">
									<?php
									echo $account
										? esc_html__( 'Adds dropshipper access to the existing account.', 'herb-partner-hub' )
										: esc_html__( 'Creates a customer account with dropshipper access.', 'herb-partner-hub' );
									?>
								</p>
								<label class="hph-check">
									<input type="checkbox" name="notify" value="1" checked>
									<span><?php echo $account ? esc_html__( 'Send approval email', 'herb-partner-hub' ) : esc_html__( 'Send approval email with set-password link', 'herb-partner-hub' ); ?></span>
								</label>
								<button type="submit" name="decision" value="approve" class="button button-primary" <?php disabled( $is_staff ); ?>><?php esc_html_e( 'Approve dropshipper', 'herb-partner-hub' ); ?></button>
							</div>

							<div class="hph-box">
								<h2><?php esc_html_e( 'Other actions', 'herb-partner-hub' ); ?></h2>
								<label for="hph-message"><?php esc_html_e( 'Message to applicant', 'herb-partner-hub' ); ?></label>
								<textarea id="hph-message" name="message" rows="4"></textarea>
								<p class="description"><?php esc_html_e( 'Required to ask for more info, optional when not approving. Sent by email.', 'herb-partner-hub' ); ?></p>
								<button type="submit" name="decision" value="needs_info" class="button"><?php esc_html_e( 'Ask for more info', 'herb-partner-hub' ); ?></button>
								<button type="submit" name="decision" value="rejected" class="button hph-button-danger"><?php esc_html_e( 'Reject', 'herb-partner-hub' ); ?></button>
							</div>
						<?php else : ?>
							<div class="hph-box">
								<h2><?php esc_html_e( 'Decision', 'herb-partner-hub' ); ?></h2>
								<p>
									<?php echo HPH_Application_Repo::status_tag( $app['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in status_tag(). ?>
									<?php
									/* translators: 1: reviewer name, 2: date */
									echo esc_html( sprintf( __( 'by %1$s, %2$s', 'herb-partner-hub' ), $reviewer ? $reviewer->display_name : '—', $when( $app['reviewed_at'] ) ) );
									?>
								</p>
								<?php if ( 'approved' === $app['status'] ) : ?>
									<?php if ( $account ) : ?>
										<p><a href="<?php echo esc_url( get_edit_user_link( $account->ID ) ); ?>"><?php esc_html_e( 'Open the dropshipper account', 'herb-partner-hub' ); ?></a></p>
									<?php endif; ?>
									<button type="submit" name="decision" value="resend" class="button"><?php esc_html_e( 'Send approval email again', 'herb-partner-hub' ); ?></button>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<?php if ( $history ) : ?>
							<div class="hph-box">
								<h2><?php esc_html_e( 'History', 'herb-partner-hub' ); ?></h2>
								<ul class="hph-log">
									<?php foreach ( $history as $entry ) : ?>
										<?php
										$who   = $entry['actor_id'] ? get_userdata( (int) $entry['actor_id'] ) : null;
										$after = json_decode( (string) $entry['after_json'], true );
										?>
										<li>
											<strong><?php echo esc_html( ucfirst( str_replace( array( 'application_', '_' ), array( '', ' ' ), $entry['action'] ) ) ); ?></strong>
											<span class="description"><?php echo esc_html( ( $who ? $who->display_name : __( 'applicant', 'herb-partner-hub' ) ) . ', ' . $when( $entry['created_at'] ) ); ?></span>
											<?php if ( ! empty( $after['message'] ) ) : ?>
												<em><?php echo nl2br( esc_html( $after['message'] ) ); ?></em>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	public function handle(): void {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! current_user_can( 'hph_manage' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'herb-partner-hub' ), 403 );
		}
		check_admin_referer( self::ACTION . '_' . $id );

		$decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		$message  = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$note     = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

		switch ( $decision ) {
			case 'approve':
				$notify = ! empty( $_POST['notify'] );
				$result = HPH_Approval::approve( $id, $note, $notify );
				$msg    = $notify ? 'approved' : 'approved_quiet';
				break;
			case 'needs_info':
			case 'rejected':
				$result = HPH_Approval::decide( $id, $decision, $message, $note );
				$msg    = $decision;
				break;
			case 'resend':
				$result = HPH_Approval::resend_approval( $id );
				$msg    = 'resent';
				break;
			case 'note':
				$result = HPH_Application_Repo::update( $id, array( 'internal_note' => $note ) );
				HPH_Log::add( 'application_note', 'application', $id );
				$msg    = 'note';
				break;
			default:
				$result = new WP_Error( 'hph_bad_decision', __( 'Unknown decision.', 'herb-partner-hub' ) );
				$msg    = '';
		}

		$this->redirect( admin_url( 'admin.php?page=' . self::PAGE . '&id=' . $id ), $result, $msg );
	}

	/**
	 * Approve / Reject straight from a list row (no message, email sent).
	 */
	public function handle_quick(): void {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( 'hph_manage' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'herb-partner-hub' ), 403 );
		}
		check_admin_referer( self::QUICK_ACTION . '_' . $id );

		$decision = isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '';
		if ( 'approve' === $decision ) {
			$result = HPH_Approval::approve( $id );
			$msg    = 'approved';
		} elseif ( 'rejected' === $decision ) {
			$result = HPH_Approval::decide( $id, 'rejected', '' );
			$msg    = 'rejected';
		} else {
			$result = new WP_Error( 'hph_bad_decision', __( 'Unknown decision.', 'herb-partner-hub' ) );
			$msg    = '';
		}

		$back = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::PAGE );
		$this->redirect( remove_query_arg( array( 'hph_msg', 'hph_err' ), $back ), $result, $msg );
	}

	private function redirect( string $url, $result, string $msg ): void {
		$url = is_wp_error( $result )
			? add_query_arg( 'hph_err', rawurlencode( $result->get_error_message() ), $url )
			: add_query_arg( 'hph_msg', $msg, $url );
		wp_safe_redirect( $url );
		exit;
	}
}
