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
		);
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

		$allowed_retention = array( '30', '90', '180', '365', 'forever' );
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
}
