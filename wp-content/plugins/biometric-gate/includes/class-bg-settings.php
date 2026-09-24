<?php
/**
 * Global plugin settings (Admin Dashboard Tab B) — single autoloaded option, schema +
 * sanitization live here so both the REST layer and the admin page share one source of truth.
 */

defined( 'ABSPATH' ) || exit;

class BG_Settings {

	const OPTION_KEY = 'bg_settings';

	/**
	 * @return array<string,mixed>
	 */
	public static function get_defaults() {
		return array(
			// Off by default: an admin must explicitly flip this on after enrollment data exists,
			// so activating the plugin never instantly locks everyone out of every page.
			'gate_status'         => 'off',
			'scan_threshold_secs' => 900,
			'guard_window_secs'   => 300,
			'path_rules'          => '',
			'retention'           => '90',
			'no_camera_message'   => self::default_no_camera_message(),
			'pixlab_api_key'      => '',
			'faceio_secret_key'   => '',
			'faceio_app_id'       => '',
			'vault_dir_path'      => self::default_vault_dir(),
			'min_confidence_percent' => 92,

			// 3-strike biometric failure routing (distinct from the anti-cheat kill-switch grid
			// below — this is specifically "ran out of retries on a genuine non-match").
			'fail_action'         => 'logout', // 'logout' | 'redirect'
			'fail_redirect_url'   => '',
			'tampered_redirect_url' => home_url(),

			// The "Close" escape-hatch button's Continue destination (spec: lets a stuck user
			// leave gracefully without granting access — never a bypass into protected content).
			'close_button_redirect_url' => '',
			'close_confirm_message'     => __( 'This action will redirect you away from your current lesson course page. Do you want to continue?', 'biometric-gate' ),

			// Per-violation-type anti-cheat toggles. devtools defaults OFF since window-size-based
			// detection is a best-effort heuristic (see bg-gate.js) with real false-positive risk.
			'kill_switches'       => self::default_kill_switches(),

			// Deterrent-only keyboard/context-menu blocking — never a real security boundary
			// (deny-by-default content guard is), just friction against casual snooping.
			'block_devtools_shortcuts' => false,
			'blocked_keys_custom'      => '',
			'disable_frontend_js'      => false,
		);
	}

	/**
	 * @return array<string,array{enabled:bool,action:string,redirect_url:string}>
	 */
	public static function default_kill_switches() {
		return array(
			'virtual_camera' => array( 'enabled' => true, 'action' => 'logout', 'redirect_url' => '' ),
			'dom_tamper'     => array( 'enabled' => true, 'action' => 'logout', 'redirect_url' => '' ),
			'devtools'       => array( 'enabled' => false, 'action' => 'logout', 'redirect_url' => '' ),
		);
	}

	/**
	 * Default flat-file vault directory for encrypted reference portraits (client spec:
	 * "Secure Server Storage Directory Path"). Admin-editable in Tab B; this is only the
	 * fallback used when that field is left blank.
	 *
	 * @return string
	 */
	public static function default_vault_dir() {
		return trailingslashit( WP_CONTENT_DIR . '/secure-student-vault' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::get_defaults() );
	}

	public static function is_gate_enabled() {
		return 'on' === self::get()['gate_status'];
	}

	public static function default_no_camera_message() {
		return sprintf(
			'<p>%s</p>',
			esc_html__(
				'We could not detect a webcam on this device. Please switch to a device with a working camera to continue, or contact support for assistance.',
				'biometric-gate'
			)
		);
	}

	/**
	 * Validate + sanitize an incoming settings array before it is persisted.
	 *
	 * @param array $input Raw input (e.g. from the REST settings route).
	 * @return array|WP_Error Clean settings array, or WP_Error on invalid input.
	 */
	public static function sanitize( array $input ) {
		$clean = self::get_defaults();

		$clean['gate_status'] = ( isset( $input['gate_status'] ) && 'on' === $input['gate_status'] ) ? 'on' : 'off';

		$threshold = isset( $input['scan_threshold_secs'] ) ? absint( $input['scan_threshold_secs'] ) : 0;
		$guard     = isset( $input['guard_window_secs'] ) ? absint( $input['guard_window_secs'] ) : 0;

		if ( $threshold < 1 ) {
			return new WP_Error(
				'bg_invalid_threshold',
				__( 'Scan Frequency Threshold (Seconds) must be a positive integer.', 'biometric-gate' )
			);
		}

		if ( $guard < 1 ) {
			return new WP_Error(
				'bg_invalid_guard',
				__( 'Consecutive Scan Bypass Guard Window (Seconds) must be a positive integer.', 'biometric-gate' )
			);
		}

		// Spec #9: the guard window must represent a duration strictly less than the scan threshold.
		if ( $guard >= $threshold ) {
			return new WP_Error(
				'bg_guard_not_less_than_threshold',
				__( 'The Consecutive Scan Bypass Guard Window must be strictly less than the Scan Frequency Threshold.', 'biometric-gate' )
			);
		}

		$clean['scan_threshold_secs'] = $threshold;
		$clean['guard_window_secs']   = $guard;

		$clean['path_rules'] = isset( $input['path_rules'] )
			? sanitize_textarea_field( wp_unslash( $input['path_rules'] ) )
			: '';

		$allowed_retention = array( '1', '7', '30', '90', '180', '365', 'forever' );
		$clean['retention'] = ( isset( $input['retention'] ) && in_array( $input['retention'], $allowed_retention, true ) )
			? $input['retention']
			: '90';

		$clean['no_camera_message'] = isset( $input['no_camera_message'] )
			? wp_kses_post( wp_unslash( $input['no_camera_message'] ) )
			: self::default_no_camera_message();

		$clean['pixlab_api_key'] = isset( $input['pixlab_api_key'] ) && '' !== trim( $input['pixlab_api_key'] )
			? BG_Crypto::encrypt( sanitize_text_field( wp_unslash( $input['pixlab_api_key'] ) ) )
			: '';

		$clean['faceio_secret_key'] = isset( $input['faceio_secret_key'] ) && '' !== trim( $input['faceio_secret_key'] )
			? BG_Crypto::encrypt( sanitize_text_field( wp_unslash( $input['faceio_secret_key'] ) ) )
			: '';

		// Not consumed by the current server-to-server faceverify call (which only sends
		// key/src/target) — stored for record-keeping/future use. Plain text, not a secret.
		$clean['faceio_app_id'] = isset( $input['faceio_app_id'] )
			? sanitize_text_field( wp_unslash( $input['faceio_app_id'] ) )
			: '';

		$vault_dir = isset( $input['vault_dir_path'] ) ? trim( wp_unslash( $input['vault_dir_path'] ) ) : '';
		$clean['vault_dir_path'] = '' !== $vault_dir ? trailingslashit( wp_normalize_path( $vault_dir ) ) : self::default_vault_dir();

		$confidence = isset( $input['min_confidence_percent'] ) ? absint( $input['min_confidence_percent'] ) : 0;
		if ( $confidence < 1 || $confidence > 100 ) {
			return new WP_Error(
				'bg_invalid_confidence',
				__( 'Minimum Match Confidence Cutoff (%) must be an integer between 1 and 100.', 'biometric-gate' )
			);
		}
		$clean['min_confidence_percent'] = $confidence;

		$clean['fail_action'] = ( isset( $input['fail_action'] ) && 'redirect' === $input['fail_action'] ) ? 'redirect' : 'logout';
		$clean['fail_redirect_url'] = isset( $input['fail_redirect_url'] )
			? esc_url_raw( wp_unslash( $input['fail_redirect_url'] ) )
			: '';

		$clean['tampered_redirect_url'] = ! empty( $input['tampered_redirect_url'] )
			? esc_url_raw( wp_unslash( $input['tampered_redirect_url'] ) )
			: home_url();

		$clean['close_button_redirect_url'] = isset( $input['close_button_redirect_url'] )
			? esc_url_raw( wp_unslash( $input['close_button_redirect_url'] ) )
			: '';

		$clean['close_confirm_message'] = isset( $input['close_confirm_message'] ) && '' !== trim( $input['close_confirm_message'] )
			? sanitize_textarea_field( wp_unslash( $input['close_confirm_message'] ) )
			: __( 'This action will redirect you away from your current lesson course page. Do you want to continue?', 'biometric-gate' );

		$clean['kill_switches'] = self::sanitize_kill_switches(
			isset( $input['kill_switches'] ) && is_array( $input['kill_switches'] ) ? $input['kill_switches'] : array()
		);

		$clean['block_devtools_shortcuts'] = ! empty( $input['block_devtools_shortcuts'] );
		$clean['blocked_keys_custom'] = isset( $input['blocked_keys_custom'] )
			? sanitize_text_field( wp_unslash( $input['blocked_keys_custom'] ) )
			: '';
		$clean['disable_frontend_js'] = ! empty( $input['disable_frontend_js'] );

		return $clean;
	}

	/**
	 * @param array $input Raw per-type kill-switch config, e.g. from a JSON-decoded REST body
	 *                      or the Tab B form's array-shaped field names.
	 * @return array<string,array{enabled:bool,action:string,redirect_url:string}>
	 */
	private static function sanitize_kill_switches( array $input ) {
		$clean = array();

		foreach ( self::default_kill_switches() as $key => $defaults ) {
			$row = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();

			$clean[ $key ] = array(
				'enabled'      => ! empty( $row['enabled'] ),
				'action'       => ( isset( $row['action'] ) && 'redirect' === $row['action'] ) ? 'redirect' : 'logout',
				'redirect_url' => isset( $row['redirect_url'] ) ? esc_url_raw( wp_unslash( (string) $row['redirect_url'] ) ) : '',
			);
		}

		return $clean;
	}

	/**
	 * @return string[] Normalized, trimmed, non-empty path fragments (e.g. "/courses/").
	 */
	public static function get_path_rules() {
		$raw = self::get()['path_rules'];
		if ( '' === trim( (string) $raw ) ) {
			return array();
		}

		$parts = array_map( 'trim', explode( ',', $raw ) );
		return array_values( array_filter( $parts ) );
	}

	public static function get_pixlab_api_key() {
		$encrypted = self::get()['pixlab_api_key'];
		return $encrypted ? BG_Crypto::decrypt( $encrypted ) : '';
	}

	public static function get_faceio_secret_key() {
		$encrypted = self::get()['faceio_secret_key'];
		return $encrypted ? BG_Crypto::decrypt( $encrypted ) : '';
	}

	public static function get_faceio_app_id() {
		return self::get()['faceio_app_id'];
	}

	/**
	 * @return string Absolute, trailing-slashed vault directory path.
	 */
	public static function get_vault_dir() {
		$path = self::get()['vault_dir_path'];
		return $path ? $path : self::default_vault_dir();
	}

	public static function get_min_confidence_percent() {
		return (int) self::get()['min_confidence_percent'];
	}

	/**
	 * Resolve what should happen for a given violation reason — the 3-strike biometric
	 * failure has its own dedicated setting (fail_action/fail_redirect_url), separate from the
	 * per-type anti-cheat kill-switch grid (virtual_camera/dom_tamper/devtools). Anything
	 * unrecognized falls back to a hard logout, matching the plugin's original behavior.
	 *
	 * @param string $reason e.g. 'max_retries_exceeded', 'virtual_camera_detected', 'overlay_tampered', 'devtools_detected'.
	 * @return array{action:string,redirect_url:string}
	 */
	public static function resolve_violation_action( $reason ) {
		$settings = self::get();

		if ( 'max_retries_exceeded' === $reason ) {
			return array(
				'action'       => $settings['fail_action'],
				'redirect_url' => $settings['fail_redirect_url'],
			);
		}

		$type_map = array(
			'virtual_camera_detected' => 'virtual_camera',
			'overlay_tampered'        => 'dom_tamper',
			'devtools_detected'       => 'devtools',
		);

		$key = isset( $type_map[ $reason ] ) ? $type_map[ $reason ] : null;

		if ( $key && isset( $settings['kill_switches'][ $key ] ) ) {
			return array(
				'action'       => $settings['kill_switches'][ $key ]['action'],
				'redirect_url' => $settings['kill_switches'][ $key ]['redirect_url'],
			);
		}

		return array( 'action' => 'logout', 'redirect_url' => '' );
	}

	/**
	 * @param string $type 'virtual_camera' | 'dom_tamper' | 'devtools'.
	 * @return bool Whether that specific anti-cheat check is currently active.
	 */
	public static function is_kill_switch_enabled( $type ) {
		$switches = self::get()['kill_switches'];
		return isset( $switches[ $type ] ) && ! empty( $switches[ $type ]['enabled'] );
	}
}
