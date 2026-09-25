<?php
/**
 * Server-side authority for "is this user currently verified, and for how much longer".
 *
 * A WP Transient (Redis-backed via Object Cache Pro on this stack) is the single source of
 * truth — it is keyed by user_id and only ever written by server-side code after an
 * independent, server-confirmed FACEIO match (see BG_Rest_Controller::scan_result). An
 * HttpOnly, non-JS-readable cookie mirrors the same fact for defense in depth, but every
 * authority check below reads the transient; the cookie is never trusted on its own.
 * A client tampering with localStorage or the cookie value therefore cannot extend or forge
 * a session — spec #3's "manual alteration must be spotted and denied" requirement.
 */

defined( 'ABSPATH' ) || exit;

class BG_Session {

	const COOKIE_NAME = 'bg_verified';

	public static function init() {
		add_action( 'wp_logout', array( __CLASS__, 'clear_current_user' ) );
	}

	private static function transient_key( $user_id ) {
		return 'bg_verified_' . absint( $user_id );
	}

	/**
	 * Record a fresh, server-confirmed successful verification.
	 *
	 * @param int $user_id
	 */
	public static function mark_verified( $user_id ) {
		$user_id = absint( $user_id );
		$ttl     = self::threshold_seconds();
		$now     = time();

		set_transient( self::transient_key( $user_id ), $now, $ttl );
		update_user_meta( $user_id, 'bg_last_verified_at', $now );
		self::set_cookie( $user_id, $now + $ttl );
	}

	/**
	 * @param int $user_id
	 * @return int|null Seconds elapsed since the last server-confirmed verification, or null
	 *                   if there is no current (unexpired) verification on record.
	 */
	public static function seconds_since_last_verification( $user_id ) {
		$verified_at = get_transient( self::transient_key( absint( $user_id ) ) );

		if ( false === $verified_at ) {
			return null;
		}

		return max( 0, time() - (int) $verified_at );
	}

	/**
	 * Spec #3's "rolling validation guard window": true when the user scanned recently enough
	 * that a brand-new camera scan can be gracefully skipped.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_within_guard_window( $user_id ) {
		$elapsed = self::seconds_since_last_verification( $user_id );
		if ( null === $elapsed ) {
			return false;
		}
		return $elapsed < self::guard_window_seconds();
	}

	/**
	 * @param int $user_id
	 * @return bool True when the user has a currently-valid (unexpired) verification.
	 */
	public static function has_valid_session( $user_id ) {
		return null !== self::seconds_since_last_verification( $user_id );
	}

	/**
	 * Invalidate a user's session (kill-switch, logout, manual admin reset).
	 *
	 * @param int $user_id
	 */
	public static function clear( $user_id ) {
		delete_transient( self::transient_key( absint( $user_id ) ) );
		self::clear_cookie();
	}

	public static function clear_current_user() {
		if ( is_user_logged_in() ) {
			self::clear( get_current_user_id() );
		}
	}

	/**
	 * @param int $user_id
	 * @return bool Whether the admin has flipped "Bypass Biometric Verification" for this user.
	 */
	public static function is_bypassed( $user_id ) {
		return (bool) get_user_meta( absint( $user_id ), 'bg_bypass_biometric', true );
	}

	/**
	 * @param int $user_id
	 * @return bool Whether tampering with the encrypted token was previously detected.
	 */
	public static function is_locked( $user_id ) {
		$user_id = absint( $user_id );
		return (bool) get_user_meta( $user_id, 'bg_account_locked', true ) || (bool) get_user_meta( $user_id, 'locked_tampered', true );
	}

	/**
	 * Spec #2: if decryption of a stored token fails (indicating manual tampering with
	 * user_meta), lock the profile until an admin explicitly resets biometrics.
	 *
	 * @param int $user_id
	 */
	public static function lock_account( $user_id ) {
		$user_id = absint( $user_id );
		update_user_meta( $user_id, 'bg_account_locked', 1 );
		self::clear( $user_id );
	}

	public static function lock_tampered_account( $user_id ) {
		$user_id = absint( $user_id );
		update_user_meta( $user_id, 'locked_tampered', current_time('mysql', true) );
		self::clear( $user_id );
		wp_logout();
	}

	/**
	 * @param int $user_id
	 */
	public static function unlock_account( $user_id ) {
		delete_user_meta( absint( $user_id ), 'bg_account_locked' );
	}

	public static function unlock_tampered_account( $user_id ) {
		delete_user_meta( absint( $user_id ), 'locked_tampered' );
		delete_user_meta( absint( $user_id ), 'bg_account_locked' );
	}

	private static function threshold_seconds() {
		return max( 1, (int) BG_Settings::get()['scan_threshold_secs'] );
	}

	private static function guard_window_seconds() {
		return max( 1, (int) BG_Settings::get()['guard_window_secs'] );
	}

	/**
	 * Set the HttpOnly mirror cookie. Not readable by JavaScript and not itself trusted for
	 * authorization decisions — see class docblock.
	 *
	 * @param int $user_id
	 * @param int $expires_at Unix timestamp.
	 */
	private static function set_cookie( $user_id, $expires_at ) {
		if ( headers_sent() ) {
			return;
		}

		$payload   = $user_id . '|' . $expires_at;
		$signature = BG_Crypto::sign( $payload );
		$value     = $payload . '|' . $signature;

		setcookie(
			self::COOKIE_NAME,
			$value,
			array(
				'expires'  => $expires_at,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private static function clear_cookie() {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE_NAME,
			'',
			array(
				'expires'  => time() - YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
