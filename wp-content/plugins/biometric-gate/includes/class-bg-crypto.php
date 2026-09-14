<?php
/**
 * AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC) helpers.
 *
 * Bare CBC is malleable — an attacker who can write to user_meta directly (e.g. via a
 * separate SQL-injection bug elsewhere, or direct DB access) could flip ciphertext bytes
 * and get *some* plaintext back out without detection. Spec #2 requires that any manual
 * tampering with the stored token cause decryption to fail and lock the account, so we
 * authenticate the ciphertext with HMAC-SHA256 before ever attempting to decrypt it.
 */

defined( 'ABSPATH' ) || exit;

class BG_Crypto {

	const CIPHER = 'aes-256-cbc';
	const MAC_LENGTH = 32; // sha256 output length in bytes.

	/**
	 * Derive a fixed-length 32-byte key from the wp-config.php constant. Hashing lets the
	 * site owner declare any high-entropy string rather than hand-crafting exactly 32 bytes.
	 */
	private static function get_key() {
		if ( ! defined( 'BIOMETRIC_GATE_ENCRYPTION_KEY' ) || '' === BIOMETRIC_GATE_ENCRYPTION_KEY ) {
			throw new RuntimeException(
				'BIOMETRIC_GATE_ENCRYPTION_KEY is not defined in wp-config.php. Add: define( \'BIOMETRIC_GATE_ENCRYPTION_KEY\', \'<a long random string>\' );'
			);
		}

		return hash( 'sha256', BIOMETRIC_GATE_ENCRYPTION_KEY, true );
	}

	/**
	 * Independent MAC key derived from the same secret via HKDF-style domain separation,
	 * so the encryption key and MAC key are never literally the same bytes.
	 */
	private static function get_mac_key() {
		return hash_hmac( 'sha256', 'biometric-gate-mac', self::get_key(), true );
	}

	/**
	 * Encrypt-then-MAC a plaintext string. Returns a base64 string: iv || hmac || ciphertext.
	 *
	 * @param string $plaintext
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		$key       = self::get_key();
		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv        = random_bytes( $iv_length );

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			throw new RuntimeException( 'Biometric Gate: encryption failed.' );
		}

		$mac = hash_hmac( 'sha256', $iv . $ciphertext, self::get_mac_key(), true );

		return base64_encode( $iv . $mac . $ciphertext );
	}

	/**
	 * Verify-then-decrypt. Never throws — any corruption, tampering, or missing key
	 * returns null so callers can treat null as "fail closed" (spec #2's account lock).
	 *
	 * @param string $encoded
	 * @return string|null
	 */
	public static function decrypt( $encoded ) {
		try {
			if ( ! is_string( $encoded ) || '' === $encoded ) {
				return null;
			}

			$raw = base64_decode( $encoded, true );
			if ( false === $raw ) {
				return null;
			}

			$iv_length = openssl_cipher_iv_length( self::CIPHER );

			if ( strlen( $raw ) <= $iv_length + self::MAC_LENGTH ) {
				return null;
			}

			$iv         = substr( $raw, 0, $iv_length );
			$mac        = substr( $raw, $iv_length, self::MAC_LENGTH );
			$ciphertext = substr( $raw, $iv_length + self::MAC_LENGTH );

			$expected_mac = hash_hmac( 'sha256', $iv . $ciphertext, self::get_mac_key(), true );

			if ( ! hash_equals( $expected_mac, $mac ) ) {
				return null; // Tampered ciphertext — caller must fail closed.
			}

			$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv );

			return ( false === $plaintext ) ? null : $plaintext;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Generate a cryptographically random, URL-safe token (used for one-time scan tickets).
	 *
	 * @param int $bytes
	 * @return string
	 */
	public static function random_token( $bytes = 32 ) {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}

	/**
	 * HMAC-sign an arbitrary (non-confidential) string, e.g. for the session cookie.
	 * Unlike encrypt()/decrypt(), this does not hide the data — it only authenticates it.
	 *
	 * @param string $data
	 * @return string Hex-encoded signature.
	 */
	public static function sign( $data ) {
		return bin2hex( hash_hmac( 'sha256', $data, self::get_key(), true ) );
	}

	/**
	 * @param string $data
	 * @param string $signature Hex-encoded signature to check.
	 * @return bool
	 */
	public static function verify_signature( $data, $signature ) {
		if ( ! is_string( $signature ) || '' === $signature ) {
			return false;
		}
		return hash_equals( self::sign( $data ), $signature );
	}
}
