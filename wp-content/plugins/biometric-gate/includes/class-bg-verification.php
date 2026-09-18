<?php
/**
 * Server-to-server call to FaceIO's stateless `faceverify` REST endpoint: compares the
 * student's freshly captured live frame against their stored encrypted reference portrait,
 * with no prior FaceIO enrollment required. This is the actual trust boundary for the whole
 * plugin — the browser never gets to declare "verified"; it only ever submits a raw captured
 * frame, and this class's return value (driven entirely by FaceIO's own response) decides
 * pass/fail.
 *
 * Endpoint confirmed directly against FaceIO's own REST API reference (faceio.net/rest-api):
 * POST https://api.faceio.net/faceverify
 *   key    - the application's REST API Key (FaceIO Console -> Application Manager -> API key
 *            tab). This is a different credential from the client-side "Application Public ID"
 *            and from a generic "secret" — if verification fails with an auth-shaped error,
 *            re-check that the value saved as the FACEIO Secret Key in Tab B is actually this
 *            REST API Key, not the Public ID.
 *   src    - reference image, as a "data:{mime};base64,{data}" URI (also accepts a bare base64
 *            string or a public URL, but a data URI is the most unambiguous of the three).
 *   target - the comparison image, same format.
 * Response: { status, same_person: bool, dist: float, sim: float } on success.
 */

defined( 'ABSPATH' ) || exit;

class BG_Verification {

	const FACEIO_VERIFY_ENDPOINT = 'https://api.faceio.net/faceverify';
	const CLOUD_TIMEOUT_SECONDS  = 5;
	const MAX_RETRY_LOOPS        = 3; // Spec #5: allow up to 3 continuous scan loops before failing.

	/**
	 * @param int    $user_id
	 * @param string $live_frame_binary Raw JPEG/PNG bytes captured from the user's camera just now.
	 * @return true|WP_Error True on a confirmed match. WP_Error with code 'bg_cloud_timeout' on a
	 *                        FaceIO-side timeout/5xx (spec #5's one-loop grace bypass applies to
	 *                        that case only); any other WP_Error means a genuine non-match/failure.
	 */
	public static function verify_against_reference( $user_id, $live_frame_binary ) {
		$reference_b64 = BG_Enrollment::get_reference_portrait( $user_id );

		if ( null === $reference_b64 ) {
			return new WP_Error( 'bg_no_reference', __( 'No biometric reference is on file for this account.', 'biometric-gate' ) );
		}

		$api_key = BG_Settings::get_faceio_secret_key();

		if ( '' === $api_key ) {
			return new WP_Error( 'bg_faceio_not_configured', __( 'FACEIO REST API Key is not configured in Global Settings.', 'biometric-gate' ) );
		}

		if ( empty( $live_frame_binary ) ) {
			return new WP_Error( 'bg_bad_frame', __( 'No usable camera frame was received.', 'biometric-gate' ) );
		}

		$response = wp_remote_post(
			self::FACEIO_VERIFY_ENDPOINT,
			array(
				'timeout' => self::CLOUD_TIMEOUT_SECONDS,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'key'    => $api_key,
						'src'    => 'data:image/jpeg;base64,' . $reference_b64,
						'target' => 'data:image/jpeg;base64,' . base64_encode( $live_frame_binary ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bg_cloud_timeout', __( 'FACEIO service did not respond in time.', 'biometric-gate' ) );
		}

		$http_code = wp_remote_retrieve_response_code( $response );

		if ( $http_code >= 500 ) {
			return new WP_Error( 'bg_cloud_timeout', __( 'FACEIO service returned a server error.', 'biometric-gate' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! array_key_exists( 'same_person', $body ) ) {
			$message = is_array( $body ) && ! empty( $body['error'] )
				? sprintf( /* translators: %s: raw error message returned by FACEIO */ __( 'FACEIO error: %s', 'biometric-gate' ), $body['error'] )
				: __( 'Unexpected response from the verification service.', 'biometric-gate' );
			return new WP_Error( 'bg_unexpected_response', $message );
		}

		if ( true !== $body['same_person'] ) {
			return new WP_Error( 'bg_no_match', __( 'The live scan did not match the enrolled identity.', 'biometric-gate' ) );
		}

		return true;
	}
}
