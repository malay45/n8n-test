<?php
/**
 * Server-to-server call to FACEIO's stateless `face-verify` REST endpoint: compares the
 * student's freshly captured live frame against their stored encrypted reference portrait,
 * with no prior FACEIO enrollment required. This is the actual trust boundary for the whole
 * plugin — the browser never gets to declare "verified"; only this class's return value does,
 * and it only returns true after FACEIO's own cloud renders a match verdict.
 *
 * IMPORTANT — confirm before go-live: the exact endpoint URL and JSON field names below are
 * built from FACEIO's public REST API reference (faceio.net/rest-api) and the PixLab
 * engineering blog describing the same "face-verify" endpoint, since this sandbox's network
 * policy blocks fetching faceio.net directly to double-check field-for-field. Everything
 * FACEIO-specific is isolated to this one file/method so correcting the exact request/response
 * shape against a live account is a small, contained change.
 */

defined( 'ABSPATH' ) || exit;

class BG_Verification {

	// Confirm this exact path against the FACEIO console/docs for the live account before go-live.
	const FACEIO_VERIFY_ENDPOINT = 'https://api.faceio.net/face-verify';

	const CLOUD_TIMEOUT_SECONDS = 5;
	const MAX_RETRY_LOOPS       = 3; // Spec #5: allow up to 3 continuous scan loops before failing.

	/**
	 * @param int    $user_id
	 * @param string $live_frame_binary Raw JPEG/PNG bytes captured from the user's camera just now.
	 * @return true|WP_Error True on a confirmed match. WP_Error with code 'bg_cloud_timeout' on a
	 *                        FACEIO-side timeout/5xx (spec #5's one-loop grace bypass applies to
	 *                        that case only); any other WP_Error means a genuine non-match/failure.
	 */
	public static function verify_against_reference( $user_id, $live_frame_binary ) {
		$reference = BG_Enrollment::get_reference_portrait( $user_id );

		if ( null === $reference ) {
			return new WP_Error( 'bg_no_reference', __( 'No biometric reference is on file for this account.', 'biometric-gate' ) );
		}

		if ( ! defined( 'BIOMETRIC_GATE_FACEIO_SECRET_KEY' ) || '' === BIOMETRIC_GATE_FACEIO_SECRET_KEY ) {
			return new WP_Error( 'bg_faceio_not_configured', __( 'FACEIO secret key is not configured in wp-config.php.', 'biometric-gate' ) );
		}

		if ( ! defined( 'BIOMETRIC_GATE_FACEIO_APP_ID' ) || '' === BIOMETRIC_GATE_FACEIO_APP_ID ) {
			return new WP_Error( 'bg_faceio_not_configured', __( 'FACEIO application ID is not configured in wp-config.php.', 'biometric-gate' ) );
		}

		$response = wp_remote_post(
			self::FACEIO_VERIFY_ENDPOINT,
			array(
				'timeout' => self::CLOUD_TIMEOUT_SECONDS,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						// Secret key stays server-side only — never sent to the browser (screening Q2).
						'application_id' => BIOMETRIC_GATE_FACEIO_APP_ID,
						'secret_key'     => BIOMETRIC_GATE_FACEIO_SECRET_KEY,
						'image1'         => base64_encode( $reference ),
						'image2'         => base64_encode( $live_frame_binary ),
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

		// Assumed contract: {"status":200,"result":{"is_identical":true,"confidence":0.93}} per
		// FACEIO's documented face-verify response shape. Treat any deviation as a hard failure
		// (fail closed) rather than guessing at a field name.
		if ( ! is_array( $body ) || ! isset( $body['result']['is_identical'] ) ) {
			return new WP_Error( 'bg_unexpected_response', __( 'Unexpected response from the verification service.', 'biometric-gate' ) );
		}

		if ( true !== $body['result']['is_identical'] ) {
			return new WP_Error( 'bg_no_match', __( 'The live scan did not match the enrolled identity.', 'biometric-gate' ) );
		}

		return true;
	}
}
