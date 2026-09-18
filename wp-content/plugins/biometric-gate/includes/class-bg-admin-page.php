<?php
/**
 * The 3-tab admin dashboard (spec #9) plus the per-user "Bypass Biometric Verification"
 * checkbox on the standard WordPress user-profile screen (spec #5).
 *
 * Tab A (User Directory) and Tab C (Live Audit Log) are small JS-driven views backed by the
 * REST routes in BG_Admin_Rest_Controller, since both need live search/sort/filter
 * interactivity. Tab B (Global Settings) is a conventional server-rendered WP settings form
 * — it needs wp_editor()'s rich-text control for the "No-Camera Block Message" field, which
 * is simplest and most robust as a normal PHP-rendered form rather than reimplementing a
 * rich-text editor client-side.
 */

defined( 'ABSPATH' ) || exit;

class BG_Admin_Page {

	const MENU_SLUG = 'biometric-gate';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_user_profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_user_profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_profile_fields' ) );
		add_action( 'admin_post_bg_download_backup', array( __CLASS__, 'handle_download_backup' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_config_notice' ) );
	}

	/**
	 * Enrollment and verification fail silently (as WP_Error returns deep in a REST call)
	 * without these wp-config.php constants. Surface that loudly on our own admin pages
	 * rather than leaving a site owner to guess why every scan is failing.
	 */
	public static function maybe_render_missing_config_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::MENU_SLUG ) ) {
			return;
		}

		

		$missing = array();

		if ( ! defined( 'BIOMETRIC_GATE_ENCRYPTION_KEY' ) || '' === constant( 'BIOMETRIC_GATE_ENCRYPTION_KEY' ) ) {
			$missing[] = "<code>BIOMETRIC_GATE_ENCRYPTION_KEY</code> (" . __( 'used to encrypt stored reference portraits, must be in wp-config.php', 'biometric-gate' ) . ")";
		}
		if ( '' === BG_Settings::get_pixlab_api_key() ) {
			$missing[] = "<code>PixLab API Key</code> (" . __( 'used for ID-photo face cropping, must be set in Global Settings', 'biometric-gate' ) . ")";
		}
		if ( '' === BG_Settings::get_faceio_secret_key() ) {
			$missing[] = "<code>FACEIO REST API Key</code> (" . __( 'used to call FACEIO\'s faceverify REST API — find it in the FACEIO Console under Application Manager → API key tab, NOT the client-side Application/Public ID; must be set in Global Settings', 'biometric-gate' ) . ")";
		}

		if ( empty( $missing ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p><ul><li>%s</li></ul></div>',
			esc_html__( 'Biometric Gate: add these constants to wp-config.php before enabling the gate — enrollment and verification will fail without them:', 'biometric-gate' ),
			wp_kses( implode( '</li><li>', $missing ), array( 'code' => array() ) )
		);
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Biometric Gate', 'biometric-gate' ),
			__( 'Biometric Gate', 'biometric-gate' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-shield',
			80
		);
	}

	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'a'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, array( 'a', 'b', 'c' ), true ) ? $tab : 'a';
	}

	public static function maybe_enqueue( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		$tab = self::current_tab();

		wp_enqueue_style( 'bg-admin', BG_PLUGIN_URL . 'admin/css/admin-dashboard.css', array(), BG_PLUGIN_VERSION );

		if ( in_array( $tab, array( 'a', 'c' ), true ) ) {
			wp_enqueue_script( 'bg-admin', BG_PLUGIN_URL . 'admin/js/admin-dashboard.js', array(), BG_PLUGIN_VERSION, true );

			wp_localize_script(
				'bg-admin',
				'BiometricGateAdmin',
				array(
					'restUrl'         => esc_url_raw( rest_url( BG_REST_NAMESPACE ) ),
					'nonce'           => wp_create_nonce( 'wp_rest' ),
					'tab'             => $tab,
					'initialUserId'   => isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'downloadBaseUrl' => admin_url( 'admin-post.php' ),
					'downloadNonce'   => wp_create_nonce( 'bg_download_backup' ),
					'logsTabUrl'      => admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=c' ),
					'i18n'            => array(
						'confirmReset'      => __( 'Reset this student\'s biometric enrollment? They will need to be re-enrolled before they can access protected pages again.', 'biometric-gate' ),
						'confirmExportWipe' => __( 'This will export every log row to a CSV backup, then permanently erase the live log table. Continue?', 'biometric-gate' ),
						'confirmDeleteFile' => __( 'Permanently delete this backup file from the server?', 'biometric-gate' ),
						'idTokenLoaded'     => __( 'ID Token Loaded', 'biometric-gate' ),
						'idMissing'         => __( 'ID Missing', 'biometric-gate' ),
						'locked'            => __( 'Locked', 'biometric-gate' ),
						'exporting'         => __( 'Exporting…', 'biometric-gate' ),
						'done'              => __( 'Done', 'biometric-gate' ),
					),
				)
			);
		}
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = self::current_tab();
		?>
		<div class="wrap bg-admin-wrap">
			<h1><?php esc_html_e( 'Biometric Gate', 'biometric-gate' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( self::tab_url( 'a' ) ); ?>" class="nav-tab <?php echo 'a' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'User Directory', 'biometric-gate' ); ?></a>
				<a href="<?php echo esc_url( self::tab_url( 'b' ) ); ?>" class="nav-tab <?php echo 'b' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Global Settings', 'biometric-gate' ); ?></a>
				<a href="<?php echo esc_url( self::tab_url( 'c' ) ); ?>" class="nav-tab <?php echo 'c' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Live Audit Log', 'biometric-gate' ); ?></a>
			</h2>

			<?php if ( 'b' === $tab ) : ?>
				<?php self::render_settings_tab(); ?>
			<?php else : ?>
				<div id="bg-admin-root" data-tab="<?php echo esc_attr( $tab ); ?>">
					<p><?php esc_html_e( 'Loading…', 'biometric-gate' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function tab_url( $tab ) {
		return add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
	}

	// ---------------------------------------------------------------------
	// Tab B: server-rendered settings form
	// ---------------------------------------------------------------------

	private static function render_settings_tab() {
		$notice = '';

		if ( isset( $_POST['bg_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bg_settings_nonce'] ) ), 'bg_save_settings' ) ) {
			$input = array(
				'gate_status'         => isset( $_POST['gate_status'] ) ? sanitize_text_field( wp_unslash( $_POST['gate_status'] ) ) : 'off',
				'scan_threshold_secs' => isset( $_POST['scan_threshold_secs'] ) ? wp_unslash( $_POST['scan_threshold_secs'] ) : '',
				'guard_window_secs'   => isset( $_POST['guard_window_secs'] ) ? wp_unslash( $_POST['guard_window_secs'] ) : '',
				'path_rules'          => isset( $_POST['path_rules'] ) ? wp_unslash( $_POST['path_rules'] ) : '',
				'retention'           => isset( $_POST['retention'] ) ? wp_unslash( $_POST['retention'] ) : '90',
				'no_camera_message'   => isset( $_POST['no_camera_message'] ) ? wp_unslash( $_POST['no_camera_message'] ) : '',
				'pixlab_api_key'      => isset( $_POST['pixlab_api_key'] ) ? wp_unslash( $_POST['pixlab_api_key'] ) : '',
				'faceio_secret_key'   => isset( $_POST['faceio_secret_key'] ) ? wp_unslash( $_POST['faceio_secret_key'] ) : '',
			);

			$clean = BG_Settings::sanitize( $input );

			if ( is_wp_error( $clean ) ) {
				$notice = '<div class="notice notice-error"><p>' . esc_html( $clean->get_error_message() ) . '</p></div>';
			} else {
				update_option( BG_Settings::OPTION_KEY, $clean );
				$notice = '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'biometric-gate' ) . '</p></div>';
			}
		}

		$settings = BG_Settings::get();

		echo wp_kses_post( $notice );
		?>
		<form method="post">
			<?php wp_nonce_field( 'bg_save_settings', 'bg_settings_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Biometric Gate Status', 'biometric-gate' ); ?></th>
					<td>
						<label><input type="radio" name="gate_status" value="on" <?php checked( 'on', $settings['gate_status'] ); ?> /> <?php esc_html_e( 'Enabled', 'biometric-gate' ); ?></label><br>
						<label><input type="radio" name="gate_status" value="off" <?php checked( 'off', $settings['gate_status'] ); ?> /> <?php esc_html_e( 'Completely Off', 'biometric-gate' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="scan_threshold_secs"><?php esc_html_e( 'Scan Frequency Threshold (Seconds)', 'biometric-gate' ); ?></label></th>
					<td><input type="number" min="1" step="1" name="scan_threshold_secs" id="scan_threshold_secs" value="<?php echo esc_attr( $settings['scan_threshold_secs'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="guard_window_secs"><?php esc_html_e( 'Consecutive Scan Bypass Guard Window (Seconds)', 'biometric-gate' ); ?></label></th>
					<td>
						<input type="number" min="1" step="1" name="guard_window_secs" id="guard_window_secs" value="<?php echo esc_attr( $settings['guard_window_secs'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Must be strictly less than the Scan Frequency Threshold above.', 'biometric-gate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="path_rules"><?php esc_html_e( 'Targeted Script Loading Path Rules', 'biometric-gate' ); ?></label></th>
					<td>
						<textarea name="path_rules" id="path_rules" rows="3" class="large-text" placeholder="/courses/, /lessons/, /topic/"><?php echo esc_textarea( $settings['path_rules'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Comma-separated path fragments. Only pages under these paths are gated.', 'biometric-gate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="retention"><?php esc_html_e( 'Log Retention Purge Limit', 'biometric-gate' ); ?></label></th>
					<td>
						<select name="retention" id="retention">
							<?php foreach ( array( '30' => '30 Days', '90' => '90 Days', '180' => '180 Days', '365' => '365 Days', 'forever' => 'Keep Forever' ) as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['retention'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="no_camera_message"><?php esc_html_e( 'No-Camera Block Message', 'biometric-gate' ); ?></label></th>
					<td>
						<?php
						wp_editor(
							$settings['no_camera_message'],
							'no_camera_message',
							array(
								'textarea_name' => 'no_camera_message',
								'media_buttons' => false,
								'textarea_rows' => 6,
								'teeny'         => true,
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pixlab_api_key"><?php esc_html_e( 'PixLab API Key', 'biometric-gate' ); ?></label></th>
					<td>
						<input type="text" name="pixlab_api_key" id="pixlab_api_key" value="<?php echo esc_attr( BG_Settings::get_pixlab_api_key() ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="faceio_secret_key"><?php esc_html_e( 'FACEIO REST API Key', 'biometric-gate' ); ?></label></th>
					<td>
						<input type="password" name="faceio_secret_key" id="faceio_secret_key" value="<?php echo esc_attr( BG_Settings::get_faceio_secret_key() ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'FACEIO Console → Application Manager → API key tab. This is NOT the client-side Application/Public ID — it is a separate REST API credential used only server-side.', 'biometric-gate' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'biometric-gate' ) ); ?>
		</form>
		<?php
	}

	// ---------------------------------------------------------------------
	// User profile screen: per-user bypass checkbox (spec #5)
	// ---------------------------------------------------------------------

	public static function render_user_profile_fields( $user ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Biometric Gate', 'biometric-gate' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="bg_bypass_biometric"><?php esc_html_e( 'Bypass Biometric Verification', 'biometric-gate' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="bg_bypass_biometric" id="bg_bypass_biometric" value="1" <?php checked( BG_Session::is_bypassed( $user->ID ) ); ?> />
						<?php esc_html_e( 'Completely exempt this user from biometric verification.', 'biometric-gate' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_user_profile_fields( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( isset( $_POST['bg_bypass_biometric'] ) ) {
			update_user_meta( $user_id, 'bg_bypass_biometric', 1 );
		} else {
			delete_user_meta( $user_id, 'bg_bypass_biometric' );
		}
	}

	// ---------------------------------------------------------------------
	// Backup CSV download (admin-post.php — a one-off, capability-gated admin file download,
	// not the high-frequency logging traffic spec #6 requires off admin-ajax.php).
	// ---------------------------------------------------------------------

	public static function handle_download_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'biometric-gate' ), 403 );
		}

		check_admin_referer( 'bg_download_backup' );

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$path     = BG_Logs::resolve_backup_path( $filename );

		if ( null === $path ) {
			wp_die( esc_html__( 'File not found.', 'biometric-gate' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}
}
