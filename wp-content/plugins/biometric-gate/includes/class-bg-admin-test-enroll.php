<?php
/**
 * Test page for Live FaceIO Enrollment.
 */

defined( 'ABSPATH' ) || exit;

class BG_Admin_Test_Enroll {

	const PAGE_SLUG = 'bg-test-enroll';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_bg_test_enroll_live', array( __CLASS__, 'ajax_enroll_live' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'biometric-gate',
			__( 'Test FaceIO Enroll', 'biometric-gate' ),
			__( 'Test FaceIO Enroll', 'biometric-gate' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_scripts( $hook ) {
		// Only load on our specific admin page
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_script( 'faceio-js', 'https://cdn.faceio.net/fio.js', array(), null, true );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$faceio_app_id = BG_Settings::get_faceio_app_id();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Test FaceIO Live Enrollment', 'biometric-gate' ); ?></h1>
			<p><?php esc_html_e( 'Use this page to test enrolling a user using the live webcam instead of uploading a photo.', 'biometric-gate' ); ?></p>
			
			<?php if ( empty( $faceio_app_id ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'FACEIO App ID is not configured in Global Settings. You cannot test this until it is set.', 'biometric-gate' ); ?></p></div>
			<?php else : ?>
				<div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-top: 20px; max-width: 500px;">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bg_test_user_id"><?php esc_html_e( 'Select User', 'biometric-gate' ); ?></label></th>
							<td>
								<select id="bg_test_user_id" class="regular-text" style="max-width: 100%;">
									<option value=""><?php esc_html_e( '-- Select a User --', 'biometric-gate' ); ?></option>
									<?php
									$users = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
									foreach ( $users as $user ) {
										$has_enrollment = BG_Enrollment::has_enrollment( $user->ID );
										$status = $has_enrollment ? ' (Already Enrolled - Will Overwrite)' : ' (Not Enrolled)';
										printf(
											'<option value="%d">%s%s</option>',
											$user->ID,
											esc_html( $user->display_name . ' - ' . $user->user_email ),
											esc_html( $status )
										);
									}
									?>
								</select>
								<p class="description"><?php esc_html_e( 'Select the user you want to enroll. If they are already enrolled, the new scan will replace their old one.', 'biometric-gate' ); ?></p>
							</td>
						</tr>
					</table>
					
					<p class="submit">
						<button type="button" id="bg-scan-face-btn" class="button button-primary button-large"><?php esc_html_e( 'Scan Face & Enroll', 'biometric-gate' ); ?></button>
					</p>
					
					<div id="bg-enroll-status" style="margin-top: 15px; font-weight: bold;"></div>
				</div>

				<script>
				document.addEventListener('DOMContentLoaded', function() {
					var btn = document.getElementById('bg-scan-face-btn');
					var statusEl = document.getElementById('bg-enroll-status');
					var faceio = new faceIO("<?php echo esc_js( $faceio_app_id ); ?>");

					btn.addEventListener('click', function() {
						var userId = document.getElementById('bg_test_user_id').value;
						
						if (!userId) {
							alert('Please select a User first.');
							return;
						}

						statusEl.style.color = 'black';
						statusEl.textContent = 'Initializing FaceIO...';
						btn.disabled = true;

						faceio.enroll({
							payload: {
								user_id: userId
							}
						}).then(function(userInfo) {
							statusEl.textContent = 'Face scanned! Saving to database...';
							
							// Send to WordPress backend via AJAX
							var data = new FormData();
							data.append('action', 'bg_test_enroll_live');
							data.append('user_id', userId);
							data.append('facialId', userInfo.facialId);
							data.append('security', '<?php echo esc_js( wp_create_nonce( "bg_test_enroll" ) ); ?>');

							return fetch(ajaxurl, {
								method: 'POST',
								body: data
							});
						}).then(function(response) {
							return response.json();
						}).then(function(result) {
							if (result.success) {
								statusEl.style.color = 'green';
								statusEl.textContent = 'Success! Face enrolled for User ID ' + userId + '.';
							} else {
								statusEl.style.color = 'red';
								statusEl.textContent = 'Error: ' + (result.data || 'Failed to save to database.');
							}
						}).catch(function(err) {
							statusEl.style.color = 'red';
							statusEl.textContent = 'FaceIO Error: ' + err;
						}).finally(function() {
							btn.disabled = false;
						});
					});
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function ajax_enroll_live() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		check_ajax_referer( 'bg_test_enroll', 'security' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$facialId = isset( $_POST['facialId'] ) ? sanitize_text_field( wp_unslash( $_POST['facialId'] ) ) : '';

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_send_json_error( 'Invalid User ID.' );
		}

		if ( empty( $facialId ) ) {
			wp_send_json_error( 'Missing Facial ID.' );
		}

		// Store it using the existing enrollment method so it acts exactly like a normal enrollment
		$result = BG_Enrollment::enroll_from_frontend( $user_id, $facialId );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( 'Enrolled' );
	}
}
