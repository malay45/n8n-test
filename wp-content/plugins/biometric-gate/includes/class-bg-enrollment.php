<?php
/**
 * Admin-uploaded photo -> optional PixLab crop -> quality guardrails -> compressed JPEG ->
 * encrypted flat file in a secure vault directory -> encrypted path in user_meta.
 *
 * FaceIO's REST API has no endpoint for enrolling a facial ID from a static uploaded photo at
 * any plan tier (confirmed against FaceIO's own REST API reference: enrollment is exclusively
 * a client-side fio.enroll() operation against a live webcam). So this plugin never calls
 * FaceIO during enrollment — it keeps a small encrypted reference portrait server-side and
 * defers the actual FaceIO call to verification time (BG_Verification::verify_against_reference()),
 * where the live captured frame and this stored reference are compared via FaceIO's stateless
 * `faceverify` REST endpoint (server-to-server image-vs-image match, no enrollment involved).
 *
 * The reference portrait itself is stored as a flat file on disk in an admin-configurable
 * "vault" directory (never the public Media Library, never the DB) under a randomized
 * filename; only the AES-256-CBC-encrypted absolute file path is kept in user_meta
 * (_secure_face_reference_path). PixLab cropping is optional and budget-gated: an admin
 * chooses per-upload whether a file is an "Official ID" (routed through PixLab's
 * facedetect/crop APIs to isolate just the portrait) or a "Standard Student Image" (used
 * as-is). Both tracks pass through the same resolution/luminance/compression guardrails
 * before being written to the vault.
 */

defined( 'ABSPATH' ) || exit;

class BG_Enrollment {

	const META_REFERENCE_PATH   = '_secure_face_reference_path'; // Encrypted absolute path to a vault file.
	const MAX_UPLOAD_BYTES      = 8 * MB_IN_BYTES;
	const ALLOWED_MIME_TYPES    = array( 'image/jpeg', 'image/png' );

	const UPLOAD_TYPE_OFFICIAL_ID    = 'official_id';
	const UPLOAD_TYPE_STANDARD_IMAGE = 'standard_image';

	const MIN_DIMENSION_PX = 600; // Reject uploads smaller than this on either side.
	const MAX_DIMENSION_PX = 600; // Downscale (never upscale) larger uploads to fit this bound.
	const JPEG_QUALITY     = 80;

	// Average luminance (0-255 scale) thresholds. Heuristic defaults — worth tuning once
	// real enrollment photos have been seen on staging.
	const LUMINANCE_REJECT_BELOW    = 40;
	const LUMINANCE_AUTOLEVEL_BELOW = 90;

	/**
	 * Handle an admin's photo upload for a given user (Tab A). Runs the full pipeline:
	 * validate -> optional PixLab crop -> quality guardrails -> compress -> write to vault ->
	 * encrypt path -> store -> purge temp file.
	 *
	 * @param int    $user_id
	 * @param array  $file        A single entry from $_FILES (already capability-checked by the caller).
	 * @param string $upload_type self::UPLOAD_TYPE_OFFICIAL_ID or self::UPLOAD_TYPE_STANDARD_IMAGE.
	 * @return true|WP_Error
	 */
	public static function enroll_from_upload( $user_id, array $file, $upload_type = self::UPLOAD_TYPE_STANDARD_IMAGE ) {
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

		// Keep the previous vault file's (encrypted) path so it can be removed *after* the new
		// one is safely written and pointed to — never delete the old enrollment first.
		$previous_encrypted_path = get_user_meta( $user_id, self::META_REFERENCE_PATH, true );

		try {
			if ( self::UPLOAD_TYPE_OFFICIAL_ID === $upload_type ) {
				if ( '' === BG_Settings::get_pixlab_api_key() ) {
					return new WP_Error(
						'bg_pixlab_not_configured',
						__( "PixLab isn't configured yet, so Official ID cropping can't run. Choose Standard Student Image, or add a PixLab API key in Global Settings first.", 'biometric-gate' )
					);
				}

				$working = self::crop_portrait_via_pixlab( $tmp_path );
				if ( is_wp_error( $working ) ) {
					return $working;
				}
			} else {
				$working = file_get_contents( $tmp_path );
				if ( false === $working ) {
					return new WP_Error( 'bg_read_failed', __( 'Could not read the uploaded image.', 'biometric-gate' ) );
				}
			}

			$guarded = self::apply_quality_guardrails( $working );
			if ( is_wp_error( $guarded ) ) {
				return $guarded;
			}

			$compressed = self::compress_to_small_jpeg( $guarded );
			if ( is_wp_error( $compressed ) ) {
				return $compressed;
			}

			$vault_path = self::write_to_vault( $compressed );
			if ( is_wp_error( $vault_path ) ) {
				return $vault_path;
			}

			update_user_meta( $user_id, self::META_REFERENCE_PATH, BG_Crypto::encrypt( $vault_path ) );
			delete_user_meta( $user_id, 'bg_account_locked' ); // A fresh enrollment supersedes any prior lock.

			if ( $previous_encrypted_path ) {
				self::unlink_vault_path( BG_Crypto::decrypt( $previous_encrypted_path ) );
			}

			return true;
		} finally {
			// The original full upload (and any intermediate crop) is purged from the private
			// temp dir unconditionally — success or failure — so nothing ever lingers.
			self::safe_unlink( $tmp_path );
		}
	}

	/**
	 * @param int $user_id
	 * @return string|null Base64-encoded JPEG (for a "data:image/jpeg;base64," URI, e.g. in
	 *                      BG_Verification), or null if there's no enrollment / it's tampered /
	 *                      the vault file is missing.
	 */
	public static function get_reference_portrait( $user_id ) {
		$encrypted_path = get_user_meta( absint( $user_id ), self::META_REFERENCE_PATH, true );
		if ( ! $encrypted_path ) {
			return null;
		}

		$path = BG_Crypto::decrypt( $encrypted_path );
		if ( null === $path ) {
			// Tampering detected — fail closed and lock the profile (spec #2).
			BG_Session::lock_account( $user_id );
			return null;
		}

		if ( ! file_exists( $path ) ) {
			return null; // Vault file missing out-of-band (e.g. manually moved) — needs re-enrollment, not a lock.
		}

		$binary = file_get_contents( $path );
		return ( false === $binary ) ? null : base64_encode( $binary );
	}

	/**
	 * @param int $user_id
	 * @return bool Whether this user has a reference portrait on file at all.
	 */
	public static function has_enrollment( $user_id ) {
		return (bool) get_user_meta( absint( $user_id ), self::META_REFERENCE_PATH, true );
	}

	/**
	 * Tab A "Reset Biometrics": delete the vault file, clear the meta pointer, and clear any
	 * lock/session state.
	 *
	 * @param int $user_id
	 */
	public static function reset( $user_id ) {
		$user_id        = absint( $user_id );
		$encrypted_path = get_user_meta( $user_id, self::META_REFERENCE_PATH, true );

		if ( $encrypted_path ) {
			self::unlink_vault_path( BG_Crypto::decrypt( $encrypted_path ) );
		}

		delete_user_meta( $user_id, self::META_REFERENCE_PATH );
		delete_user_meta( $user_id, 'bg_account_locked' );
		BG_Session::clear( $user_id );
	}

	// ---------------------------------------------------------------------
	// Vault storage
	// ---------------------------------------------------------------------

	/**
	 * Write a compressed reference portrait to the configured vault directory under a random,
	 * non-guessable filename (never the original filename or anything derived from the user).
	 *
	 * @param string $binary Compressed JPEG binary.
	 * @return string|WP_Error Absolute path of the written file.
	 */
	private static function write_to_vault( $binary ) {
		$dir = BG_Settings::get_vault_dir();
		self::ensure_vault_protected( $dir );

		if ( ! wp_is_writable( $dir ) ) {
			return new WP_Error(
				'bg_vault_not_writable',
				sprintf(
					/* translators: %s: absolute directory path */
					__( 'The secure storage directory (%s) is not writable by the web server.', 'biometric-gate' ),
					$dir
				)
			);
		}

		$filename = 'face_' . md5( wp_generate_password( 32, false ) . microtime( true ) ) . '.jpg';
		$path     = $dir . $filename;

		if ( false === file_put_contents( $path, $binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'bg_vault_write_failed', __( 'Could not write the reference portrait to secure storage.', 'biometric-gate' ) );
		}

		return $path;
	}

	/**
	 * Lock down the vault directory the same way BG_Activator protects its own directories:
	 * an index.php stub plus a hard-deny .htaccess rule. Called lazily on every write so a
	 * changed (Tab B) or externally-recreated path is always self-healing, not just at activation.
	 *
	 * @param string $dir
	 */
	private static function ensure_vault_protected( $dir ) {
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index_file = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$htaccess_file = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			// The spec asks for exactly "Deny from all" (Apache <2.4 syntax). Apache 2.4+ ignores
			// that directive and requires "Require all denied" instead, so both are written for
			// actual protection regardless of server version. Note this file has no effect if the
			// vault sits behind Nginx (or Nginx-in-front-of-Apache) without .htaccess honored —
			// worth a one-time manual check against the real Cloudways server config.
			file_put_contents( $htaccess_file, "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	private static function unlink_vault_path( $path ) {
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	// ---------------------------------------------------------------------
	// Upload validation & temp staging
	// ---------------------------------------------------------------------

	private static function validate_upload( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'bg_no_file', __( 'No valid uploaded file was received.', 'biometric-gate' ) );
		}

		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'bg_upload_error', __( 'The file upload failed.', 'biometric-gate' ) );
		}

		if ( $file['size'] > self::MAX_UPLOAD_BYTES ) {
			return new WP_Error( 'bg_file_too_large', __( 'The uploaded photo is too large (8MB max).', 'biometric-gate' ) );
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

	// ---------------------------------------------------------------------
	// Optional PixLab crop ("Official ID" track only)
	// ---------------------------------------------------------------------

	/**
	 * PixLab facedetect -> crop pipeline: detect the face rectangle in the uploaded ID photo,
	 * then crop to just that region so the stored reference is a face portrait, not a whole
	 * passport/ID scan. Images are sent as base64 (never a public URL) since the source is a
	 * sensitive government ID photo.
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

	// ---------------------------------------------------------------------
	// Quality guardrails (both upload tracks)
	// ---------------------------------------------------------------------

	/**
	 * Resolution floor, luminance floor, and a mild-darkness auto-level pass — run identically
	 * whether the image came from the PixLab crop or straight from a "Standard Student Image"
	 * upload.
	 *
	 * @param string $binary
	 * @return string|WP_Error Possibly auto-leveled binary, or a WP_Error with the exact
	 *                         client-specified rejection message.
	 */
	private static function apply_quality_guardrails( $binary ) {
		$info = @getimagesizefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $info ) {
			return new WP_Error( 'bg_not_an_image', __( 'The image could not be read for quality checks.', 'biometric-gate' ) );
		}

		list( $width, $height ) = $info;

		if ( $width < self::MIN_DIMENSION_PX || $height < self::MIN_DIMENSION_PX ) {
			return new WP_Error(
				'bg_resolution_too_small',
				__( 'Upload Failed: The image resolution is too small. Files must be at least 600x600 pixels to ensure accurate biometric mapping.', 'biometric-gate' )
			);
		}

		$luminance = self::average_luminance( $binary );

		if ( null === $luminance ) {
			return $binary; // Couldn't measure — don't block the upload over a measurement failure.
		}

		if ( $luminance < self::LUMINANCE_REJECT_BELOW ) {
			return new WP_Error(
				'bg_too_dark',
				__( 'Upload Failed: The image environment is too dark for accurate biometric mapping. Please request a clearer, well-lit photo.', 'biometric-gate' )
			);
		}

		if ( $luminance < self::LUMINANCE_AUTOLEVEL_BELOW ) {
			return self::auto_level( $binary );
		}

		return $binary;
	}

	/**
	 * Grid-sampled (not full-image) perceptual luminance average, 0-255 scale. Sampling a
	 * ~40x40 grid rather than every pixel keeps this fast even on a several-megapixel upload.
	 *
	 * @param string $binary
	 * @return float|null Null if the image couldn't be decoded.
	 */
	private static function average_luminance( $binary ) {
		$img = @imagecreatefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $img ) {
			return null;
		}

		$width  = imagesx( $img );
		$height = imagesy( $img );
		$step_x = max( 1, (int) ( $width / 40 ) );
		$step_y = max( 1, (int) ( $height / 40 ) );

		$total = 0.0;
		$count = 0;

		for ( $x = 0; $x < $width; $x += $step_x ) {
			for ( $y = 0; $y < $height; $y += $step_y ) {
				$rgb = imagecolorat( $img, $x, $y );
				$r   = ( $rgb >> 16 ) & 0xFF;
				$g   = ( $rgb >> 8 ) & 0xFF;
				$b   = $rgb & 0xFF;
				// ITU-R BT.601 perceptual luminance weighting.
				$total += ( 0.299 * $r + 0.587 * $g + 0.114 * $b );
				++$count;
			}
		}

		imagedestroy( $img );

		return $count > 0 ? ( $total / $count ) : null;
	}

	/**
	 * Brighten a mildly underexposed photo. Imagick's autoLevelImage() is the direct match for
	 * what the spec calls auto_level_image() (not an actual GD/Imagick function name); GD has
	 * no auto-level primitive at all, so its fallback approximates one via brightness/contrast
	 * filters rather than a true per-pixel histogram stretch, which would be far too slow in
	 * pure PHP for a multi-megapixel image.
	 *
	 * @param string $binary
	 * @return string
	 */
	private static function auto_level( $binary ) {
		if ( extension_loaded( 'imagick' ) ) {
			try {
				$img = new Imagick();
				$img->readImageBlob( $binary );
				$img->autoLevelImage();
				$img->setImageFormat( 'jpeg' );
				$out = $img->getImageBlob();
				$img->destroy();
				return $out;
			} catch ( \Throwable $e ) {
				// Fall through to the GD approximation.
			}
		}

		return self::gd_brightness_contrast_stretch( $binary );
	}

	/**
	 * @param string $binary
	 * @return string
	 */
	private static function gd_brightness_contrast_stretch( $binary ) {
		$img = @imagecreatefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $img ) {
			return $binary; // Couldn't decode — return the original rather than fail the whole upload.
		}

		$width  = imagesx( $img );
		$height = imagesy( $img );
		$step_x = max( 1, (int) ( $width / 60 ) );
		$step_y = max( 1, (int) ( $height / 60 ) );

		$min = 255;
		$max = 0;

		for ( $x = 0; $x < $width; $x += $step_x ) {
			for ( $y = 0; $y < $height; $y += $step_y ) {
				$rgb = imagecolorat( $img, $x, $y );
				foreach ( array( ( $rgb >> 16 ) & 0xFF, ( $rgb >> 8 ) & 0xFF, $rgb & 0xFF ) as $channel ) {
					$min = min( $min, $channel );
					$max = max( $max, $channel );
				}
			}
		}

		if ( $max <= $min ) {
			imagedestroy( $img );
			return $binary; // Flat image (e.g. solid color) — nothing to stretch.
		}

		$scale = 255 / ( $max - $min );
		imagefilter( $img, IMG_FILTER_BRIGHTNESS, (int) ( -$min * $scale * 0.5 ) );
		imagefilter( $img, IMG_FILTER_CONTRAST, (int) ( -( ( $scale - 1 ) * 50 ) ) );

		ob_start();
		imagejpeg( $img, null, 90 );
		$out = ob_get_clean();
		imagedestroy( $img );

		return ( false === $out || '' === $out ) ? $binary : $out;
	}

	// ---------------------------------------------------------------------
	// Final compression: fit within 600x600 (never upscale), JPEG q80, ~40-60KB typical
	// ---------------------------------------------------------------------

	/**
	 * @param string $binary Raw (already guardrail-passed) image binary.
	 * @return string|WP_Error Compressed JPEG binary.
	 */
	private static function compress_to_small_jpeg( $binary ) {
		if ( extension_loaded( 'imagick' ) ) {
			try {
				$img = new Imagick();
				$img->readImageBlob( $binary );
				$img->setImageFormat( 'jpeg' );

				if ( $img->getImageWidth() > self::MAX_DIMENSION_PX || $img->getImageHeight() > self::MAX_DIMENSION_PX ) {
					// bestfit=true preserves aspect ratio within the given bounding box.
					$img->resizeImage( self::MAX_DIMENSION_PX, self::MAX_DIMENSION_PX, Imagick::FILTER_LANCZOS, 1, true );
				}

				$img->setImageCompressionQuality( self::JPEG_QUALITY );
				$img->stripImage();
				$out = $img->getImageBlob();
				$img->destroy();
				return $out;
			} catch ( \Throwable $e ) {
				// Fall through to GD.
			}
		}

		$src = @imagecreatefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $src ) {
			return new WP_Error( 'bg_image_decode_failed', __( 'Could not decode the portrait image.', 'biometric-gate' ) );
		}

		$src_w = imagesx( $src );
		$src_h = imagesy( $src );

		if ( $src_w > self::MAX_DIMENSION_PX || $src_h > self::MAX_DIMENSION_PX ) {
			$ratio  = min( self::MAX_DIMENSION_PX / $src_w, self::MAX_DIMENSION_PX / $src_h );
			$dest_w = max( 1, (int) round( $src_w * $ratio ) );
			$dest_h = max( 1, (int) round( $src_h * $ratio ) );
		} else {
			$dest_w = $src_w;
			$dest_h = $src_h;
		}

		$dest = imagecreatetruecolor( $dest_w, $dest_h );
		imagecopyresampled( $dest, $src, 0, 0, 0, 0, $dest_w, $dest_h, $src_w, $src_h );

		ob_start();
		imagejpeg( $dest, null, self::JPEG_QUALITY );
		$out = ob_get_clean();

		imagedestroy( $src );
		imagedestroy( $dest );

		if ( false === $out || '' === $out ) {
			return new WP_Error( 'bg_compress_failed', __( 'Could not compress the portrait image.', 'biometric-gate' ) );
		}

		return $out;
	}
}
