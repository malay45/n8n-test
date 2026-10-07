<?php
/**
 * Admin screen (WooCommerce → Order Import / Export), export download handler, batched import
 * AJAX endpoints and an "Export (CSV)" bulk action on the orders list.
 */

defined( 'ABSPATH' ) || exit;

class DOIE_Admin {

	const CAPABILITY = 'manage_woocommerce';
	const PAGE       = 'doie-order-import-export';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_doie_export', array( $this, 'handle_export' ) );
		add_action( 'wp_ajax_doie_import_start', array( $this, 'ajax_import_start' ) );
		add_action( 'wp_ajax_doie_import_batch', array( $this, 'ajax_import_batch' ) );

		// Orders list bulk action, legacy posts screen and HPOS screen.
		foreach ( array( 'edit-shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_filter( 'bulk_actions-' . $screen, array( $this, 'register_bulk_action' ) );
			add_filter( 'handle_bulk_actions-' . $screen, array( $this, 'handle_bulk_action' ), 10, 3 );
		}
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Order Import / Export', 'dokan-order-import-export' ),
			__( 'Order Import / Export', 'dokan-order-import-export' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'doie-admin', DOIE_URL . 'assets/admin.css', array(), DOIE_VERSION );
		wp_enqueue_script( 'doie-admin', DOIE_URL . 'assets/admin.js', array( 'jquery' ), DOIE_VERSION, true );
		wp_localize_script(
			'doie-admin',
			'doieAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'doie_import' ),
				'i18n'    => array(
					'chooseFile' => __( 'Please choose a file to import.', 'dokan-order-import-export' ),
					'uploading'  => __( 'Reading file…', 'dokan-order-import-export' ),
					'importing'  => __( 'Importing…', 'dokan-order-import-export' ),
					'done'       => __( 'Import finished.', 'dokan-order-import-export' ),
					'failed'     => __( 'Import stopped:', 'dokan-order-import-export' ),
					'summary'    => __( 'Created: %1$s · Updated: %2$s · Skipped: %3$s · Failed: %4$s', 'dokan-order-import-export' ),
				),
			)
		);
	}

	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dokan-order-import-export' ) );
		}
		$tab = isset( $_GET['tab'] ) && 'import' === $_GET['tab'] ? 'import' : 'export'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<div class="wrap doie-wrap">
			<h1><?php esc_html_e( 'Order Import / Export', 'dokan-order-import-export' ); ?></h1>

			<?php if ( DOIE_Dokan::is_active() ) : ?>
				<p class="doie-badge">
					<?php
					echo esc_html(
						DOIE_Dokan::is_pro_active()
							? __( 'Dokan Pro detected: vendors, sub-orders and earnings are included.', 'dokan-order-import-export' )
							: __( 'Dokan detected: vendors, sub-orders and earnings are included.', 'dokan-order-import-export' )
					);
					?>
				</p>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo 'export' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Export', 'dokan-order-import-export' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'import', $url ) ); ?>" class="nav-tab <?php echo 'import' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Import', 'dokan-order-import-export' ); ?></a>
			</nav>

			<?php 'import' === $tab ? $this->render_import() : $this->render_export(); ?>
		</div>
		<?php
	}

	private function render_export() {
		$dokan = DOIE_Dokan::is_active();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="doie-card">
			<input type="hidden" name="action" value="doie_export">
			<?php wp_nonce_field( 'doie_export' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Order date', 'dokan-order-import-export' ); ?></th>
					<td>
						<label><?php esc_html_e( 'From', 'dokan-order-import-export' ); ?> <input type="date" name="date_from"></label>
						<label><?php esc_html_e( 'To', 'dokan-order-import-export' ); ?> <input type="date" name="date_to"></label>
						<p class="description"><?php esc_html_e( 'Leave empty to export all dates.', 'dokan-order-import-export' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Statuses', 'dokan-order-import-export' ); ?></th>
					<td class="doie-statuses">
						<?php foreach ( wc_get_order_statuses() as $key => $label ) : ?>
							<label><input type="checkbox" name="statuses[]" value="<?php echo esc_attr( substr( $key, 3 ) ); ?>"> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'None selected = all statuses.', 'dokan-order-import-export' ); ?></p>
					</td>
				</tr>
				<?php if ( $dokan ) : ?>
					<tr>
						<th scope="row"><label for="doie-vendor"><?php esc_html_e( 'Vendor', 'dokan-order-import-export' ); ?></label></th>
						<td>
							<select name="vendor_id" id="doie-vendor">
								<option value="0"><?php esc_html_e( 'All vendors', 'dokan-order-import-export' ); ?></option>
								<?php foreach ( DOIE_Dokan::get_vendors() as $vendor ) : ?>
									<option value="<?php echo esc_attr( $vendor->ID ); ?>"><?php echo esc_html( DOIE_Dokan::get_store_name( $vendor->ID ) . ' (' . $vendor->user_email . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Orders to include', 'dokan-order-import-export' ); ?></th>
						<td>
							<label><input type="radio" name="scope" value="all" checked> <?php esc_html_e( 'All orders (parent orders and vendor sub-orders) — best for migrating / backing up', 'dokan-order-import-export' ); ?></label><br>
							<label><input type="radio" name="scope" value="vendor"> <?php esc_html_e( 'Vendor orders only (skip parent orders that were split into sub-orders) — best for reporting, no double counting', 'dokan-order-import-export' ); ?></label><br>
							<label><input type="radio" name="scope" value="parent"> <?php esc_html_e( 'Customer orders only (top-level orders, no sub-orders)', 'dokan-order-import-export' ); ?></label>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Format', 'dokan-order-import-export' ); ?></th>
					<td>
						<label><input type="radio" name="format" value="csv" checked> CSV</label>
						<label><input type="radio" name="format" value="json"> JSON</label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Download export', 'dokan-order-import-export' ) ); ?>
		</form>
		<?php
	}

	private function render_import() {
		?>
		<form id="doie-import-form" class="doie-card" enctype="multipart/form-data">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="doie-file"><?php esc_html_e( 'File', 'dokan-order-import-export' ); ?></label></th>
					<td>
						<input type="file" name="file" id="doie-file" accept=".csv,.json">
						<p class="description">
							<?php
							/* translators: %s: max upload size */
							echo esc_html( sprintf( __( 'A CSV or JSON file created by the Export tab. Maximum upload size: %s.', 'dokan-order-import-export' ), size_format( wp_max_upload_size() ) ) );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Already imported orders', 'dokan-order-import-export' ); ?></th>
					<td>
						<label><input type="radio" name="existing" value="skip" checked> <?php esc_html_e( 'Skip them', 'dokan-order-import-export' ); ?></label><br>
						<label><input type="radio" name="existing" value="update"> <?php esc_html_e( 'Update them (items, totals, addresses, status, vendor)', 'dokan-order-import-export' ); ?></label><br>
						<label><input type="radio" name="existing" value="create"> <?php esc_html_e( 'Always create new orders', 'dokan-order-import-export' ); ?></label>
						<p class="description"><?php esc_html_e( 'Imported orders remember their original ID, so re-running the same file does not create duplicates.', 'dokan-order-import-export' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'dokan-order-import-export' ); ?></th>
					<td>
						<label><input type="checkbox" name="match_by_id" value="1"> <?php esc_html_e( 'Same site: treat an existing order with the same order ID as the same order (and keep customer IDs)', 'dokan-order-import-export' ); ?></label><br>
						<?php if ( DOIE_Dokan::is_active() ) : ?>
							<label><input type="checkbox" name="preserve_earnings" value="1" checked> <?php esc_html_e( 'Keep exported Dokan vendor earnings / admin commission (otherwise recalculated with current commission settings)', 'dokan-order-import-export' ); ?></label><br>
						<?php endif; ?>
						<label><input type="checkbox" name="recalculate" value="1"> <?php esc_html_e( 'Recalculate order totals instead of using the exported totals', 'dokan-order-import-export' ); ?></label><br>
						<label><input type="checkbox" name="reduce_stock" value="1"> <?php esc_html_e( 'Reduce product stock', 'dokan-order-import-export' ); ?></label><br>
						<label><input type="checkbox" name="send_emails" value="1"> <?php esc_html_e( 'Send order emails to customers, vendors and admin', 'dokan-order-import-export' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Start import', 'dokan-order-import-export' ), 'primary', 'submit', true, array( 'id' => 'doie-import-submit' ) ); ?>
		</form>

		<div id="doie-progress" class="doie-card" hidden>
			<p class="doie-status"></p>
			<progress max="100" value="0"></progress>
			<p class="doie-summary"></p>
			<ul class="doie-messages"></ul>
		</div>
		<?php
	}

	/**
	 * Reads the export filters from a request.
	 *
	 * @return array
	 */
	private function export_args_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the caller.
		return array(
			'date_from' => isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '',
			'date_to'   => isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '',
			'statuses'  => isset( $_POST['statuses'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['statuses'] ) ) : array(),
			'vendor_id' => isset( $_POST['vendor_id'] ) ? absint( $_POST['vendor_id'] ) : 0,
			'scope'     => isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : 'all',
		);
		// phpcs:enable
	}

	public function handle_export() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export orders.', 'dokan-order-import-export' ), 403 );
		}
		check_admin_referer( 'doie_export' );

		$format = isset( $_POST['format'] ) && 'json' === $_POST['format'] ? 'json' : 'csv';
		$this->stream( new DOIE_Exporter( $this->export_args_from_request() ), $format );
	}

	/**
	 * Sends an export as a download and exits.
	 *
	 * @param DOIE_Exporter $exporter Exporter.
	 * @param string        $format   csv|json.
	 */
	private function stream( DOIE_Exporter $exporter, $format ) {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$filename = 'orders-export-' . wp_date( 'Y-m-d-His' ) . '.' . $format;
		nocache_headers();
		header( 'Content-Type: ' . ( 'json' === $format ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		'json' === $format ? $exporter->write_json( $out ) : $exporter->write_csv( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function register_bulk_action( $actions ) {
		if ( current_user_can( self::CAPABILITY ) ) {
			$actions['doie_export_csv'] = __( 'Export (CSV)', 'dokan-order-import-export' );
		}
		return $actions;
	}

	/**
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Selected order IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect, $action, $ids ) {
		if ( 'doie_export_csv' !== $action || ! current_user_can( self::CAPABILITY ) ) {
			return $redirect;
		}
		// The list table already verified its bulk-action nonce before firing this filter.
		$this->stream( new DOIE_Exporter( array( 'include' => array_map( 'absint', (array) $ids ) ) ), 'csv' );
		return $redirect;
	}

	public function ajax_import_start() {
		check_ajax_referer( 'doie_import', 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import orders.', 'dokan-order-import-export' ) ), 403 );
		}
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_send_json_error( array( 'message' => __( 'No file was uploaded or the file exceeds the maximum upload size.', 'dokan-order-import-export' ) ) );
		}

		$options = array(
			'existing'          => isset( $_POST['existing'] ) ? sanitize_key( $_POST['existing'] ) : 'skip',
			'match_by_id'       => ! empty( $_POST['match_by_id'] ),
			'send_emails'       => ! empty( $_POST['send_emails'] ),
			'reduce_stock'      => ! empty( $_POST['reduce_stock'] ),
			'preserve_earnings' => ! empty( $_POST['preserve_earnings'] ),
			'recalculate'       => ! empty( $_POST['recalculate'] ),
		);

		$name  = isset( $_FILES['file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ) : '';
		$state = DOIE_Job::create( $_FILES['file']['tmp_name'], $name, $options ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}
		wp_send_json_success( $state );
	}

	public function ajax_import_batch() {
		check_ajax_referer( 'doie_import', 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import orders.', 'dokan-order-import-export' ) ), 403 );
		}
		$id    = isset( $_POST['job'] ) ? sanitize_key( $_POST['job'] ) : '';
		$state = DOIE_Job::run_batch( $id, (int) apply_filters( 'doie_import_batch_size', 20 ) );
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}
		wp_send_json_success( $state );
	}
}
