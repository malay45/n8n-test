<?php
/**
 * Admin-uploaded ID photo -> cropped/compressed reference portrait -> encrypted user_meta.
 *
 * FaceIO's REST API has no endpoint for enrolling a facial ID from a static uploaded photo at
 * any plan tier (confirmed against FaceIO's own REST API reference: enrollment is exclusively
 * a client-side fio.enroll() operation against a live webcam). So this plugin never calls
 * FaceIO during enrollment — it keeps a small encrypted reference portrait server-side and
 * defers the actual FaceIO call to verification time (BG_Verification::verify_against_reference()),
 * where the live captured frame and this stored reference are compared via FaceIO's stateless
 * `faceverify` REST endpoint (server-to-server image-vs-image match, no enrollment involved).
 */

defined( 'ABSPATH' ) || exit;

class BG_Enrollment {

	const META_REFERENCE_PORTRAIT = 'bg_reference_portrait'; // Encrypted, base64-encoded JPEG.
	const MAX_UPLOAD_BYTES        = 8 * MB_IN_BYTES;
	const ALLOWED_MIME_TYPES      = array( 'image/jpeg', 'image/png' );

	/**
	 * Handle an admin's ID-photo upload for a given user (Tab A). Runs the full pipeline:
	 * validate -> crop portrait via PixLab -> compress -> encrypt -> store -> purge temp file.
	 *
	 * @param int   $user_id
	 * @param array $file A single entry from $_FILES (already capability-checked by the caller).
	 * @return true|WP_Error
	 */
	public static function enroll_from_upload( $user_id, array $file ) {
		$user_id = absint( $user_id );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'bg_invalid_user', __( 'That user does not exist.', 'biometric-gate' ) );
		}

		$validated = self::validate_upload( $file );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$tmp_path = self::move_to_private_tmp( $file['tmp_name'] );
		if ( is_wp_error( $tmp_path ) ) {
			return $tmp_path;
		}

		try {
			$cropped = self::crop_portrait_via_pixlab( $tmp_path );
			if ( is_wp_error( $cropped ) ) {
				return $cropped;
			}

			$compressed = self::compress_to_small_jpeg( $cropped );
			if ( is_wp_error( $compressed ) ) {
				return $compressed;
			}

			$encrypted = BG_Crypto::encrypt( base64_encode( $compressed ) );
			update_user_meta( $user_id, self::META_REFERENCE_PORTRAIT, $encrypted );
			delete_user_meta( $user_id, 'bg_account_locked' ); // A fresh enrollment supersedes any prior lock.

			return true;
		} finally {
			// The original full ID graphic (and any intermediate crop) is purged from disk
			// unconditionally — success or failure — so nothing ever lingers (spec #2).
			self::safe_unlink( $tmp_path );
		}
	}

	/**
	 * @param int $user_id
	 * @return string|null Decrypted, base64-encoded JPEG string (NOT raw binary — callers that
	 *                      need a data URI, e.g. BG_Verification, can use this value directly
	 *                      after the "data:image/jpeg;base64," prefix), or null if none/tampered.
	 */
	public static function get_reference_portrait( $user_id ) {
		$encrypted = get_user_meta( absint( $user_id ), self::META_REFERENCE_PORTRAIT, true );
		if ( ! $encrypted ) {
			return null;
		}

		$decrypted_base64 = BG_Crypto::decrypt( $encrypted );
		if ( null === $decrypted_base64 ) {
			// Tampering detected — fail closed and lock the profile (spec #2).
			BG_Session::lock_account( $user_id );
			return null;
		}

		return $decrypted_base64;
	}

	/**
	 * @param int $user_id
	 * @return bool Whether this user has a reference portrait on file at all.
	 */
	public static function has_enrollment( $user_id ) {
		return (bool) get_user_meta( absint( $user_id ), self::META_REFERENCE_PORTRAIT, true );
	}

	/**
	 * Tab A "Reset Biometrics": clear both the reference portrait and any lock/session state.
	 *
	 * @param int $user_id
	 */
	public static function reset( $user_id ) {
		$user_id = absint( $user_id );
		delete_user_meta( $user_id, self::META_REFERENCE_PORTRAIT );
		delete_user_meta( $user_id, 'bg_account_locked' );
		BG_Session::clear( $user_id );
	}

	// ---------------------------------------------------------------------
	// Internal pipeline steps
	// ---------------------------------------------------------------------

	private static function validate_upload( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'bg_no_file', __( 'No valid uploaded file was received.', 'biometric-gate' ) );
		}

		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'bg_upload_error', __( 'The file upload failed.', 'biometric-gate' ) );
		}

		if ( $file['size'] > self::MAX_UPLOAD_BYTES ) {
			return new WP_Error( 'bg_file_too_large', __( 'The uploaded ID photo is too large (8MB max).', 'biometric-gate' ) );
		}

		$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( empty( $filetype['type'] ) || ! in_array( $filetype['type'], self::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error( 'bg_invalid_type', __( 'Only JPEG or PNG images are accepted.', 'biometric-gate' ) );
		}

		if ( false === @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'bg_not_an_image', __( 'The uploaded file is not a readable image.', 'biometric-gate' ) );
		}

		return true;
	}

	/**
	 * Copy the upload into the plugin's private, non-web-accessible temp directory under a
	 * random name (never the original filename, which could contain path-unsafe characters).
	 *
	 * @return string|WP_Error Absolute path to the private temp copy.
	 */
	private static function move_to_private_tmp( $uploaded_tmp_name ) {
		if ( ! file_exists( BG_TMP_DIR ) ) {
			wp_mkdir_p( BG_TMP_DIR );
		}

		$dest = trailingslashit( BG_TMP_DIR ) . 'upload-' . wp_generate_password( 20, false ) . '.tmp';

		if ( ! @copy( $uploaded_tmp_name, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'bg_tmp_copy_failed', __( 'Could not stage the uploaded file for processing.', 'biometric-gate' ) );
		}

		return $dest;
	}

	private static function safe_unlink( $path ) {
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * PixLab facedetect -> crop pipeline: detect the face rectangle in the uploaded ID photo,
	 * then crop to just that region so the stored reference is a face portrait, not a whole
	 * passport/ID scan (spec #2). Images are sent as base64 (never a public URL) since the
	 * source is a sensitive government ID photo.
	 *
	 * @param string $image_path
	 * @return string|WP_Error Raw cropped image binary.
	 */
	private static function crop_portrait_via_pixlab( $image_path ) {
		$key = BG_Settings::get_pixlab_api_key();

		if ( '' === $key ) {
			return new WP_Error( 'bg_pixlab_not_configured', __( 'PixLab API key is not configured in Global Settings.', 'biometric-gate' ) );
		}

		$image_data = file_get_contents( $image_path );
		if ( false === $image_data ) {
			return new WP_Error( 'bg_read_failed', __( 'Could not read the uploaded image.', 'biometric-gate' ) );
		}

		$b64 = base64_encode( $image_data );

		$detect_response = wp_remote_post(
			'https://api.pixlab.io/facedetect',
			array(
				'timeout' => 15,
				'body'    => array(
					'img' => $b64,
					'key' => $key,
				),
			)
		);

		if ( is_wp_error( $detect_response ) ) {
			return new WP_Error( 'bg_pixlab_unreachable', __( 'Could not reach the face-detection service.', 'biometric-gate' ) );
		}

		$detect_body = json_decode( wp_remote_retrieve_body( $detect_response ), true );

		if ( empty( $detect_body['faces'][0] ) ) {
			return new WP_Error( 'bg_no_face_detected', __( 'No face could be detected in the uploaded ID photo.', 'biometric-gate' ) );
		}

		$face = $detect_body['faces'][0];

		$crop_response = wp_remote_post(
			'https://api.pixlab.io/crop',
			array(
				'timeout' => 15,
				'body'    => array(
					'img'    => $b64,
					'key'    => $key,
					'left'   => $face['left'],
					'top'    => $face['top'],
					'width'  => $face['width'],
					'height' => $face['height'],
				),
			)
		);

		if ( is_wp_error( $crop_response ) ) {
			return new WP_Error( 'bg_pixlab_crop_unreachable', __( 'Could not reach the crop service.', 'biometric-gate' ) );
		}

		$crop_body = json_decode( wp_remote_retrieve_body( $crop_response ), true );

		if ( empty( $crop_body['link'] ) ) {
			return new WP_Error( 'bg_crop_failed', __( 'The crop service did not return a cropped image.', 'biometric-gate' ) );
		}

		$cropped_response = wp_remote_get( $crop_body['link'], array( 'timeout' => 15 ) );
		if ( is_wp_error( $cropped_response ) ) {
			return new WP_Error( 'bg_crop_fetch_failed', __( 'Could not retrieve the cropped portrait.', 'biometric-gate' ) );
		}

		return wp_remote_retrieve_body( $cropped_response );
	}

	/**
	 * Resize to 200x200 and re-encode as JPEG q80 (GD, with Imagick preferred when available).
	 * Typical output for a cropped portrait is well under 15KB.
	 *
	 * @param string $binary Raw image binary.
	 * @return string|WP_Error Compressed JPEG binary.
	 */
	private static function compress_to_small_jpeg( $binary ) {
		if ( extension_loaded( 'imagick' ) ) {
			try {
				$img = new Imagick();
				$img->readImageBlob( $binary );
				$img->setImageFormat( 'jpeg' );
				$img->cropThumbnailImage( 200, 200 );
				$img->setImageCompressionQuality( 80 );
				$img->stripImage();
				return $img->getImageBlob();
			} catch ( \Throwable $e ) {
				// Fall through to GD.
			}
		}

		$src = @imagecreatefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $src ) {
			return new WP_Error( 'bg_image_decode_failed', __( 'Could not decode the cropped portrait image.', 'biometric-gate' ) );
		}

		$dest = imagecreatetruecolor( 200, 200 );
		imagecopyresampled( $dest, $src, 0, 0, 0, 0, 200, 200, imagesx( $src ), imagesy( $src ) );

		ob_start();
		imagejpeg( $dest, null, 80 );
		$out = ob_get_clean();

		imagedestroy( $src );
		imagedestroy( $dest );

		if ( false === $out || '' === $out ) {
			return new WP_Error( 'bg_compress_failed', __( 'Could not compress the portrait image.', 'biometric-gate' ) );
		}

		return $out;
	}
}
