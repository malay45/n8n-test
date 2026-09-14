<?php
/**
 * Admin-only REST routes backing the 3-tab dashboard (Tab A user directory/enrollment, Tab B
 * settings, Tab C audit log + export/backup management). Every route requires manage_options
 * plus a valid nonce — separate from the public-facing scan routes in BG_Rest_Controller,
 * which have their own ticket-based anti-replay model appropriate to an untrusted browser.
 */

defined( 'ABSPATH' ) || exit;

class BG_Admin_Rest_Controller {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$routes = array(
			array( 'POST', '/admin/enroll', 'enroll' ),
			array( 'POST', '/admin/reset', 'reset' ),
			array( 'POST', '/admin/bypass', 'toggle_bypass' ),
			array( 'POST', '/admin/toggle-gate-user', 'toggle_user_enabled' ),
			array( 'GET', '/admin/users', 'list_users' ),
			array( 'GET', '/admin/settings', 'get_settings' ),
			array( 'POST', '/admin/settings', 'save_settings' ),
			array( 'GET', '/admin/logs', 'list_logs' ),
			array( 'POST', '/admin/export-wipe', 'export_and_wipe' ),
			array( 'POST', '/admin/export-user', 'export_user' ),
			array( 'GET', '/admin/export-progress', 'export_progress' ),
			array( 'GET', '/admin/backups', 'list_backups' ),
			array( 'POST', '/admin/backups/delete', 'delete_backup' ),
		);

		foreach ( $routes as list( $method, $path, $callback ) ) {
			register_rest_route(
				BG_REST_NAMESPACE,
				$path,
				array(
					'methods'             => $method,
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => array( __CLASS__, 'require_admin' ),
				)
			);
		}
	}

	public static function require_admin( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'bg_forbidden', __( 'You do not have permission to do this.', 'biometric-gate' ), array( 'status' => 403 ) );
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'bg_bad_nonce', __( 'Invalid or expired security token.', 'biometric-gate' ), array( 'status' => 403 ) );
		}

		return true;
	}

	// -- Tab A: user directory & enrollment -----------------------------------------------

	public static function enroll( WP_REST_Request $request ) {
		$user_id = absint( $request->get_param( 'user_id' ) );
		$files   = $request->get_file_params();

		if ( empty( $files['id_photo'] ) ) {
			return new WP_Error( 'bg_no_file', __( 'No ID photo was uploaded.', 'biometric-gate' ), array( 'status' => 400 ) );
		}

		$result = BG_Enrollment::enroll_from_upload( $user_id, $files['id_photo'] );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 422 ) );
		}

		return new WP_REST_Response( array( 'status' => 'enrolled' ), 200 );
	}

	public static function reset( WP_REST_Request $request ) {
		$user_id = absint( $request->get_param( 'user_id' ) );
		BG_Enrollment::reset( $user_id );
		return new WP_REST_Response( array( 'status' => 'reset' ), 200 );
	}

	public static function toggle_bypass( WP_REST_Request $request ) {
		$user_id = absint( $request->get_param( 'user_id' ) );
		$bypass  = (bool) $request->get_param( 'bypass' );

		if ( $bypass ) {
			update_user_meta( $user_id, 'bg_bypass_biometric', 1 );
		} else {
			delete_user_meta( $user_id, 'bg_bypass_biometric' );
		}

		return new WP_REST_Response( array( 'bypass' => $bypass ), 200 );
	}

	/**
	 * Tab A's per-user ON/OFF toggle. Deliberately mirrors the same bg_bypass_biometric flag
	 * used by the admin "Bypass Biometric Verification" profile checkbox (spec #5), so there
	 * is exactly one source of truth for "is this user currently exempt from scanning" rather
	 * than two flags that could drift out of sync. Tab A passes enabled=false to mean "bypass".
	 */
	public static function toggle_user_enabled( WP_REST_Request $request ) {
		$enabled = (bool) $request->get_param( 'enabled' );
		$request->set_param( 'bypass', ! $enabled );
		return self::toggle_bypass( $request );
	}

	public static function list_users( WP_REST_Request $request ) {
		$search = (string) $request->get_param( 'search' );

		$query_args = array(
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 200,
		);

		if ( '' !== trim( $search ) ) {
			$query_args['search']         = '*' . $search . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$users = get_users( $query_args );
		$out   = array();

		foreach ( $users as $user ) {
			$out[] = array(
				'id'             => $user->ID,
				'name'           => $user->display_name,
				'email'          => $user->user_email,
				'has_enrollment' => BG_Enrollment::has_enrollment( $user->ID ),
				'bypass'         => BG_Session::is_bypassed( $user->ID ),
				'locked'         => BG_Session::is_locked( $user->ID ),
			);
		}

		return new WP_REST_Response( $out, 200 );
	}

	// -- Tab B: global settings -------------------------------------------------------------

	public static function get_settings( WP_REST_Request $request ) {
		return new WP_REST_Response( BG_Settings::get(), 200 );
	}

	public static function save_settings( WP_REST_Request $request ) {
		$clean = BG_Settings::sanitize( (array) $request->get_json_params() );

		if ( is_wp_error( $clean ) ) {
			return new WP_Error( $clean->get_error_code(), $clean->get_error_message(), array( 'status' => 422 ) );
		}

		update_option( BG_Settings::OPTION_KEY, $clean );

		return new WP_REST_Response( $clean, 200 );
	}

	// -- Tab C: audit log + export/backups ---------------------------------------------------

	public static function list_logs( WP_REST_Request $request ) {
		$result = BG_Logs::query(
			array(
				'search'   => (string) $request->get_param( 'search' ),
				'user_id'  => absint( $request->get_param( 'user_id' ) ),
				'orderby'  => (string) ( $request->get_param( 'orderby' ) ?: 'time' ),
				'order'    => (string) ( $request->get_param( 'order' ) ?: 'DESC' ),
				'page'     => max( 1, absint( $request->get_param( 'page' ) ) ),
				'per_page' => 50,
			)
		);

		return new WP_REST_Response( $result, 200 );
	}

	public static function export_and_wipe( WP_REST_Request $request ) {
		wp_schedule_single_event( time(), 'bg_job_export_and_wipe' );
		return new WP_REST_Response( array( 'status' => 'scheduled' ), 202 );
	}

	public static function export_user( WP_REST_Request $request ) {
		$user_id      = absint( $request->get_param( 'user_id' ) );
		$progress_key = 'bg_export_progress_user_' . $user_id;

		wp_schedule_single_event( time(), 'bg_job_export_user', array( $user_id, $progress_key ) );

		return new WP_REST_Response( array( 'status' => 'scheduled', 'progress_key' => $progress_key ), 202 );
	}

	public static function export_progress( WP_REST_Request $request ) {
		$key = (string) $request->get_param( 'key' );
		$key = $key ? $key : BG_Logs::PROGRESS_TRANSIENT;

		return new WP_REST_Response( get_transient( $key ) ?: array( 'status' => 'idle' ), 200 );
	}

	public static function list_backups( WP_REST_Request $request ) {
		return new WP_REST_Response( BG_Logs::list_backup_files(), 200 );
	}

	public static function delete_backup( WP_REST_Request $request ) {
		$filename = (string) $request->get_param( 'file' );
		$deleted  = BG_Logs::delete_backup_file( $filename );

		if ( ! $deleted ) {
			return new WP_Error( 'bg_delete_failed', __( 'Could not delete that file.', 'biometric-gate' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'status' => 'deleted' ), 200 );
	}
}
