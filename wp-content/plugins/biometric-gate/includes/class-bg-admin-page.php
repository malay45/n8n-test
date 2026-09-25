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
			$missing[] = "<code>BIOMETRIC_GATE_ENCRYPTION_KEY</code> (" . __( 'used to encrypt stored reference portraits and vault paths, must be in wp-config.php', 'biometric-gate' ) . ")";
		}
		if ( '' === BG_Settings::get_faceio_secret_key() ) {
			$missing[] = "<code>FACEIO REST API Key</code> (" . __( 'used to call FACEIO\'s faceverify REST API — find it in the FACEIO Console under Application Manager → API key tab, NOT the client-side Application/Public ID; must be set in Global Settings', 'biometric-gate' ) . ")";
		}
		if ( ! wp_is_writable( BG_Settings::get_vault_dir() ) && ! wp_mkdir_p( BG_Settings::get_vault_dir() ) ) {
			$missing[] = sprintf(
				/* translators: %s: absolute directory path */
				__( 'Secure Server Storage Directory Path (%s) does not exist and could not be created — check its parent directory\'s permissions', 'biometric-gate' ),
				'<code>' . esc_html( BG_Settings::get_vault_dir() ) . '</code>'
			);
		}

		// PixLab is intentionally optional/budget-gated (client's "Official ID" upload route
		// only) — it is NOT listed here as a hard requirement, unlike the two keys above.

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
		return in_array( $tab, array( 'a', 'a2', 'b', 'c' ), true ) ? $tab : 'a';
	}

	public static function maybe_enqueue( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		$tab = self::current_tab();

		// filemtime()-based versioning (matching BG_Frontend's pattern) so every deploy gets a
		// new query string and browsers/caches never keep serving a stale copy of these assets
		// after an update — a static BG_PLUGIN_VERSION here previously meant the admin JS could
		// silently stay cached indefinitely across releases.
		$admin_css_path = BG_PLUGIN_DIR . 'admin/css/admin-dashboard.css';
		$admin_js_path  = BG_PLUGIN_DIR . 'admin/js/admin-dashboard.js';

		wp_enqueue_style(
			'bg-admin',
			BG_PLUGIN_URL . 'admin/css/admin-dashboard.css',
			array(),
			file_exists( $admin_css_path ) ? filemtime( $admin_css_path ) : BG_PLUGIN_VERSION
		);

		if ( in_array( $tab, array( 'a', 'c' ), true ) ) {
			wp_enqueue_script(
				'bg-admin',
				BG_PLUGIN_URL . 'admin/js/admin-dashboard.js',
				array(),
				file_exists( $admin_js_path ) ? filemtime( $admin_js_path ) : BG_PLUGIN_VERSION,
				true
			);

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
						'confirmClearFilter' => __( 'Clear the user filter and return to the full audit log?', 'biometric-gate' ),
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
				<a href="<?php echo esc_url( self::tab_url( 'a2' ) ); ?>" class="nav-tab <?php echo 'a2' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Lockout Security Center', 'biometric-gate' ); ?></a>
				<a href="<?php echo esc_url( self::tab_url( 'b' ) ); ?>" class="nav-tab <?php echo 'b' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Global Settings', 'biometric-gate' ); ?></a>
				<a href="<?php echo esc_url( self::tab_url( 'c' ) ); ?>" class="nav-tab <?php echo 'c' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Live Audit Log', 'biometric-gate' ); ?></a>
			</h2>

			<?php if ( 'b' === $tab ) : ?>
				<?php self::render_settings_tab(); ?>
			<?php elseif ( 'a2' === $tab ) : ?>
				<?php self::render_lockout_security_center(); ?>
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
				'faceio_app_id'       => isset( $_POST['faceio_app_id'] ) ? wp_unslash( $_POST['faceio_app_id'] ) : '',
				'vault_dir_path'      => isset( $_POST['vault_dir_path'] ) ? wp_unslash( $_POST['vault_dir_path'] ) : '',
				'min_confidence_percent' => isset( $_POST['min_confidence_percent'] ) ? wp_unslash( $_POST['min_confidence_percent'] ) : '',
				'fail_action'         => isset( $_POST['fail_action'] ) ? wp_unslash( $_POST['fail_action'] ) : 'logout',
				'fail_redirect_url'   => isset( $_POST['fail_redirect_url'] ) ? wp_unslash( $_POST['fail_redirect_url'] ) : '',
				'close_button_redirect_url' => ! empty( $_POST['close_button_redirect_url'] ) ? wp_unslash( $_POST['close_button_redirect_url'] ) : home_url(),
				'close_confirm_message' => isset( $_POST['close_confirm_message'] ) ? wp_unslash( $_POST['close_confirm_message'] ) : '',
				'kill_switches'       => isset( $_POST['kill_switches'] ) ? wp_unslash( $_POST['kill_switches'] ) : array(),
				'block_devtools_shortcuts' => isset( $_POST['block_devtools_shortcuts'] ),
				'blocked_keys_custom' => isset( $_POST['blocked_keys_custom'] ) ? wp_unslash( $_POST['blocked_keys_custom'] ) : '',
				'force_native_ios' => isset( $_POST['force_native_ios'] ),
				'bypass_face_scan' => isset( $_POST['bypass_face_scan'] ),
			);

			$clean = BG_Settings::sanitize( $input );

			if ( is_wp_error( $clean ) ) {
				$notice = '<div class="notice notice-error"><p>' . esc_html( $clean->get_error_message() ) . '</p></div>';
			} else {
				update_option( BG_Settings::OPTION_KEY, $clean );

				// Nudge WP-Cron to check immediately rather than waiting for its own next
				// scheduled pass — most useful right after switching to one of the fast-testing
				// retention windows (15 Minutes/1 Hour), so the very next prune isn't a coin
				// flip on when WP-Cron would otherwise have gotten around to it.
				spawn_cron();

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
					<th scope="row"><?php esc_html_e( 'Force Native iOS Player on iPhones', 'biometric-gate' ); ?></th>
					<td>
						<label><input type="checkbox" name="force_native_ios" value="1" <?php checked( ! empty( $settings['force_native_ios'] ) ); ?> /> <?php esc_html_e( 'Force Native iOS Player on iPhones', 'biometric-gate' ); ?></label>
						<p class="description"><?php esc_html_e( 'When checked, forces videos into the native iOS player when viewed in full-screen on iPhones ONLY to hide the browser URL bar. Does not affect iPads or other devices. The scan overlay now auto-exits and restores native iOS video fullscreen so a due scan is never hidden behind it — this covers self-hosted/CDN videos; a YouTube/Vimeo iframe embed still pauses on schedule, but its own native fullscreen player chrome is a different origin the browser does not let this plugin (or any page) see or control.', 'biometric-gate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Bypass Face Scan Overlay (Keep Background Logs Active)', 'biometric-gate' ); ?></th>
					<td>
						<label><input type="checkbox" name="bypass_face_scan" value="1" <?php checked( ! empty( $settings['bypass_face_scan'] ) ); ?> /> <?php esc_html_e( 'Bypass Face Scan Overlay (Keep Background Logs Active)', 'biometric-gate' ); ?></label>
						<p class="description"><?php esc_html_e( 'Emergency override: Suppresses the visual camera popup and blur shield for student troubleshooting. All background security engines, dynamic threshold timer logs, and background tracking matrices remain fully operational.', 'biometric-gate' ); ?></p>
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
							<?php foreach ( array( '15m' => '15 Minutes (testing)', '1h' => '1 Hour (testing)', '1' => '1 Day', '7' => '7 Days', '30' => '30 Days', '90' => '90 Days', '180' => '180 Days', '365' => '365 Days', 'forever' => 'Keep Forever' ) as $value => $label ) : ?>
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
				<tr>
					<th scope="row"><label for="faceio_app_id"><?php esc_html_e( 'FACEIO Public Application ID', 'biometric-gate' ); ?></label></th>
					<td>
						<input type="text" name="faceio_app_id" id="faceio_app_id" value="<?php echo esc_attr( BG_Settings::get_faceio_app_id() ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Stored for record-keeping only — the current server-to-server faceverify call does not send this value anywhere. Not a secret.', 'biometric-gate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vault_dir_path"><?php esc_html_e( 'Secure Server Storage Directory Path', 'biometric-gate' ); ?></label></th>
					<td>
						<input type="text" name="vault_dir_path" id="vault_dir_path" value="<?php echo esc_attr( BG_Settings::get_vault_dir() ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Absolute server path where encrypted reference portraits are stored as flat files. Defaults to wp-content/secure-student-vault/.', 'biometric-gate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="min_confidence_percent"><?php esc_html_e( 'Minimum Match Confidence Cutoff (%)', 'biometric-gate' ); ?></label></th>
					<td>
						<input type="number" min="1" max="100" step="1" name="min_confidence_percent" id="min_confidence_percent" value="<?php echo esc_attr( BG_Settings::get_min_confidence_percent() ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'A live scan must clear both FACEIO\'s same_person match AND this similarity score to pass.', 'biometric-gate' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Granular Admin Controls, Kill-Switches & Redirection Router', 'biometric-gate' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Biometric Fail Routing', 'biometric-gate' ); ?></th>
					<td>
						<label><input type="radio" name="fail_action" value="logout" <?php checked( 'logout', $settings['fail_action'] ); ?> /> <?php esc_html_e( 'Logout on 3-strike failure', 'biometric-gate' ); ?></label><br>
						<label><input type="radio" name="fail_action" value="redirect" <?php checked( 'redirect', $settings['fail_action'] ); ?> /> <?php esc_html_e( 'Redirect to a custom URL instead', 'biometric-gate' ); ?></label>
						<p>
							<input type="url" name="fail_redirect_url" placeholder="https://example.com/support" value="<?php echo esc_attr( $settings['fail_redirect_url'] ); ?>" class="regular-text" />
						</p>
						<p class="description"><?php esc_html_e( 'What happens when a student exhausts all 3 live-scan attempts. Redirect keeps them logged in (useful during testing so you are not repeatedly logged out).', 'biometric-gate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Database Tampering Redirection URL', 'biometric-gate' ); ?></th>
					<td>
						<input type="url" name="tampered_redirect_url" placeholder="<?php echo esc_url( home_url() ); ?>" value="<?php echo esc_attr( $settings['tampered_redirect_url'] ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'If a corrupted or tampered database string is detected, the user is locked out, their session is cleared, and they are redirected to this URL (Home Page by default).', 'biometric-gate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Granular Kill-Switch Settings', 'biometric-gate' ); ?></th>
					<td>
						<table class="widefat" style="max-width:720px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Anti-Cheat Check', 'biometric-gate' ); ?></th>
									<th><?php esc_html_e( 'Enabled', 'biometric-gate' ); ?></th>
									<th><?php esc_html_e( 'Action', 'biometric-gate' ); ?></th>
									<th><?php esc_html_e( 'Redirect URL (if selected)', 'biometric-gate' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								$kill_switch_labels = array(
									'virtual_camera' => __( 'Virtual Webcam Detected', 'biometric-gate' ),
									'dom_tamper'      => __( 'Element Deletion / DOM Manipulation', 'biometric-gate' ),
									'devtools'        => __( 'DevTools / Inspector Open (heuristic, best-effort)', 'biometric-gate' ),
								);
								foreach ( $kill_switch_labels as $ks_key => $ks_label ) :
									$ks = isset( $settings['kill_switches'][ $ks_key ] ) ? $settings['kill_switches'][ $ks_key ] : array( 'enabled' => false, 'action' => 'logout', 'redirect_url' => '' );
									?>
									<tr>
										<td><?php echo esc_html( $ks_label ); ?></td>
										<td><input type="checkbox" name="kill_switches[<?php echo esc_attr( $ks_key ); ?>][enabled]" value="1" <?php checked( ! empty( $ks['enabled'] ) ); ?> /></td>
										<td>
											<select name="kill_switches[<?php echo esc_attr( $ks_key ); ?>][action]">
												<option value="logout" <?php selected( 'logout', $ks['action'] ); ?>><?php esc_html_e( 'Logout', 'biometric-gate' ); ?></option>
												<option value="redirect" <?php selected( 'redirect', $ks['action'] ); ?>><?php esc_html_e( 'Redirect', 'biometric-gate' ); ?></option>
											</select>
										</td>
										<td><input type="url" name="kill_switches[<?php echo esc_attr( $ks_key ); ?>][redirect_url]" value="<?php echo esc_attr( $ks['redirect_url'] ); ?>" class="regular-text" /></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description"><?php esc_html_e( 'DevTools detection is a window-size heuristic and can misfire (e.g. a resized browser window) — left off by default for that reason.', 'biometric-gate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Developer Tools & Input Disabling', 'biometric-gate' ); ?></th>
					<td>
						<label><input type="checkbox" name="block_devtools_shortcuts" value="1" <?php checked( ! empty( $settings['block_devtools_shortcuts'] ) ); ?> /> <?php esc_html_e( 'Block right-click and common DevTools keyboard shortcuts (F12, Ctrl/Cmd+Shift+I/J/C, Ctrl/Cmd+U)', 'biometric-gate' ); ?></label>
						<p>
							<label for="blocked_keys_custom"><?php esc_html_e( 'Additional keys to block (comma-separated, matching the browser key name, e.g. F11,Escape):', 'biometric-gate' ); ?></label><br>
							<input type="text" name="blocked_keys_custom" id="blocked_keys_custom" value="<?php echo esc_attr( $settings['blocked_keys_custom'] ); ?>" class="regular-text" />
						</p>
						<p class="description"><?php esc_html_e( 'Deterrent only, not a real security boundary — some browser/OS-level shortcuts (e.g. Cmd+Option+I in Safari) are intercepted by the browser itself before a webpage can block them.', 'biometric-gate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="close_button_redirect_url"><?php esc_html_e( 'Close Button Redirect URL', 'biometric-gate' ); ?></label></th>
					<td>
						<input type="url" name="close_button_redirect_url" id="close_button_redirect_url" placeholder="https://example.com/dashboard" value="<?php echo esc_attr( $settings['close_button_redirect_url'] ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Where a student lands if they click Close on the scan overlay and confirm they want to leave. This never grants access to protected content — it only lets them exit gracefully.', 'biometric-gate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="close_confirm_message"><?php esc_html_e( 'Close Button Confirmation Message', 'biometric-gate' ); ?></label></th>
					<td>
						<textarea name="close_confirm_message" id="close_confirm_message" rows="3" class="large-text"><?php echo esc_textarea( $settings['close_confirm_message'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'The message shown in the browser confirmation dialog when a student clicks the Close button on the scan overlay.', 'biometric-gate' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'biometric-gate' ) ); ?>
		</form>
		<?php
	}

	// ---------------------------------------------------------------------
	// Tab A2: Lockout Security Center
	// ---------------------------------------------------------------------

	private static function render_lockout_security_center() {
		if ( isset( $_POST['bg_unlock_user_id'] ) && isset( $_POST['bg_unlock_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bg_unlock_nonce'] ) ), 'bg_unlock_tampered' ) ) {
			$unlock_user_id = absint( $_POST['bg_unlock_user_id'] );
			if ( $unlock_user_id ) {
				BG_Session::unlock_tampered_account( $unlock_user_id );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'User profile verified and unlocked.', 'biometric-gate' ) . '</p></div>';
			}
		}

		$locked_users = get_users( array(
			'meta_key' => 'locked_tampered',
			'meta_compare' => 'EXISTS',
		) );
		?>
		<div class="wrap">
			<p><?php esc_html_e( 'These users have been locked out due to a Database String Integrity Failure or Frontend Code Tampering. Their reference photos are safe, but their database paths have been modified or corrupted.', 'biometric-gate' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User ID', 'biometric-gate' ); ?></th>
						<th><?php esc_html_e( 'Student Name / Email', 'biometric-gate' ); ?></th>
						<th><?php esc_html_e( 'Timestamp of Event', 'biometric-gate' ); ?></th>
						<th><?php esc_html_e( 'Lockout Reason Code', 'biometric-gate' ); ?></th>
						<th><?php esc_html_e( 'Security Violations', 'biometric-gate' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'biometric-gate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $locked_users ) ) : ?>
						<tr>
							<td colspan="3"><?php esc_html_e( 'No locked profiles currently.', 'biometric-gate' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $locked_users as $u ) : ?>
							<tr>
								<td><?php echo esc_html( $u->ID ); ?></td>
								<td><?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?></td>
								<td><?php
									$timestamp = get_user_meta( $u->ID, 'locked_tampered', true );
									echo esc_html( $timestamp );
								?></td>
								<td><?php
									$reason = get_user_meta( $u->ID, 'locked_tampered_reason', true );
									echo esc_html( $reason ? $reason : 'Unknown' );
								?></td>
								<td><?php echo (int) get_user_meta( $u->ID, 'biometric_strikes', true ); ?></td>
								<td>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'bg_unlock_tampered', 'bg_unlock_nonce' ); ?>
										<input type="hidden" name="bg_unlock_user_id" value="<?php echo esc_attr( $u->ID ); ?>" />
										<?php
										$reason = get_user_meta( $u->ID, 'locked_tampered_reason', true );
										if ( stripos( (string) $reason, 'Element Deletion' ) !== false ) {
											$confirm_msg = __( 'Are you sure you want to verify and unlock this profile?\n\nThis will clear the frontend tampering lockout and immediately restore their active platform access privileges.', 'biometric-gate' );
										} else {
											$confirm_msg = __( 'Are you sure you want to verify and unlock this profile?\n\nIMPORTANT: After unlocking, you MUST go to the User Directory tab, click Reset Biometrics, and re-upload their ID photo for their face scan to work properly again.', 'biometric-gate' );
										}
										?>
										<button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_attr( $confirm_msg ); ?>');"><?php esc_html_e( 'Verify & Unlock Profile', 'biometric-gate' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<br />
			<div class="card">
				<h3><?php esc_html_e( 'Tampering Redirection Settings', 'biometric-gate' ); ?></h3>
				<p><?php esc_html_e( 'To change the URL that tampered accounts are redirected to, please go to the Global Settings tab.', 'biometric-gate' ); ?></p>
			</div>
		</div>
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
