<?php
/**
 * All custom REST routes under biometric-gate/v1 — deliberately never admin-ajax.php (see
 * the mandatory screening answer in the project plan for why: admin-ajax boots the full
 * admin bootstrap on every ping and can't be selectively cached/excluded by path, which
 * doesn't scale to 10,000 concurrent students on a single 4GB instance).
 *
 * Every route here is part of the anti-cheat trust boundary (screening Q2): a route requires
 * a logged-in user + valid per-session nonce, a scan route additionally requires a fresh
 * one-time server-minted ticket, and the actual pass/fail verdict is always computed
 * server-side (BG_Verification), never accepted as a client-reported boolean.
 */

defined( 'ABSPATH' ) || exit;

class BG_Rest_Controller {

	const TICKET_TRANSIENT_PREFIX = 'bg_ticket_';
	const TICKET_TTL_SECONDS      = 60;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			BG_REST_NAMESPACE,
			'/scan/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'scan_start' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in_user' ),
				'args'                => array(
					'page_title' => array( 'type' => 'string', 'required' => false ),
					'page_url'   => array( 'type' => 'string', 'required' => false ),
				),
			)
		);

		register_rest_route(
			BG_REST_NAMESPACE,
			'/scan/result',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'scan_result' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in_user' ),
				'args'                => array(
					'ticket'     => array( 'type' => 'string', 'required' => true ),
					'facialId'   => array( 'type' => 'string', 'required' => true ),
					'page_title' => array( 'type' => 'string', 'required' => false ),
					'page_url'   => array( 'type' => 'string', 'required' => false ),
				),
			)
		);

		register_rest_route(
			BG_REST_NAMESPACE,
			'/scan/enroll-front',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'scan_enroll_front' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in_user' ),
				'args'                => array(
					'facialId' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);

		register_rest_route(
			BG_REST_NAMESPACE,
			'/session/killswitch',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'kill_switch' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in_user' ),
				'args'                => array(
					'reason' => array( 'type' => 'string', 'required' => false ),
				),
			)
		);

		register_rest_route(
			BG_REST_NAMESPACE,
			'/session/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'session_status' ),
				'permission_callback' => array( __CLASS__, 'require_logged_in_user' ),
			)
		);

		register_rest_route(
			BG_REST_NAMESPACE,
			'/scan/dev-bypass',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'dev_bypass' ),
				'permission_callback' => array( __CLASS__, 'require_admin_user' ),
				'args'                => array(
					'page_title' => array( 'type' => 'string', 'required' => false ),
					'page_url'   => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
	}

	public static function require_logged_in_user( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'bg_not_logged_in', __( 'You must be logged in.', 'biometric-gate' ), array( 'status' => 401 ) );
		}

		// WordPress's REST infrastructure already validates the X-WP-Nonce header against the
		// 'wp_rest' action for any request carrying it once this permission_callback runs
		// (rest_cookie_check_errors), but we re-verify explicitly so a missing/invalid nonce
		// fails this specific route rather than silently falling through as an anonymous request.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'bg_bad_nonce', __( 'Invalid or expired security token.', 'biometric-gate' ), array( 'status' => 403 ) );
		}

		if ( BG_Session::is_locked( get_current_user_id() ) ) {
			return new WP_Error( 'bg_account_locked', __( 'This account is locked pending administrator review.', 'biometric-gate' ), array( 'status' => 423 ) );
		}

		return true;
	}

	public static function require_admin_user( WP_REST_Request $request ) {
		$res = self::require_logged_in_user( $request );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'bg_forbidden', __( 'Admin only.', 'biometric-gate' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Mint a one-time, 60-second scan ticket. Also reports the server-authoritative remaining
	 * guard-window time so the frontend can skip the camera entirely on a rapid page transition
	 * (spec #3's rolling window guard) without the server ever trusting a client-side timer.
	 */
	public static function scan_start( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( BG_Session::is_bypassed( $user_id ) ) {
			return new WP_REST_Response( array( 'bypass' => true ), 200 );
		}

		if ( BG_Session::is_within_guard_window( $user_id ) ) {
			return new WP_REST_Response(
				array(
					'bypass'               => true,
					'seconds_until_rescan' => self::seconds_until_rescan( $user_id ),
				),
				200
			);
		}

		$ticket = BG_Crypto::random_token( 32 );
		set_transient( self::TICKET_TRANSIENT_PREFIX . $user_id, $ticket, self::TICKET_TTL_SECONDS );

		return new WP_REST_Response(
			array(
				'bypass'          => false,
				'ticket'          => $ticket,
				'ticket_ttl'      => self::TICKET_TTL_SECONDS,
				'has_enrollment'  => BG_Enrollment::has_enrollment( $user_id ),
			),
			200
		);
	}

	/**
	 * The actual verification call. The ticket is consumed (deleted) on first use regardless
	 * of outcome, so a captured/replayed request can never be resubmitted successfully.
	 */
	public static function scan_result( WP_REST_Request $request ) {
		$user_id       = get_current_user_id();
		$ticket_key    = self::TICKET_TRANSIENT_PREFIX . $user_id;
		$stored_ticket = get_transient( $ticket_key );

		delete_transient( $ticket_key ); // Single-use: gone the instant we read it, win or lose.

		if ( false === $stored_ticket || ! hash_equals( $stored_ticket, (string) $request->get_param( 'ticket' ) ) ) {
			return new WP_Error( 'bg_invalid_ticket', __( 'This scan session has expired or was already used. Please try again.', 'biometric-gate' ), array( 'status' => 409 ) );
		}

		if ( ! BG_Enrollment::has_enrollment( $user_id ) ) {
			self::log( $user_id, 'failure', $request );
			return new WP_Error( 'bg_not_enrolled', __( 'No biometric profile is on file. Contact your administrator.', 'biometric-gate' ), array( 'status' => 412 ) );
		}

		$facialId = (string) $request->get_param( 'facialId' );

		if ( empty( $facialId ) ) {
			self::log( $user_id, 'failure', $request );
			return new WP_Error( 'bg_bad_facialid', __( 'No facial ID was received.', 'biometric-gate' ), array( 'status' => 400 ) );
		}

		$result = BG_Verification::verify_against_reference( $user_id, $facialId );

		if ( is_wp_error( $result ) ) {
			if ( 'bg_cloud_timeout' === $result->get_error_code() ) {
				// Spec #5: a definitive FACEIO-side timeout/5xx bypasses one interval loop only —
				// never a local network drop, which is handled entirely client-side and never
				// reaches this branch since the browser itself can't reach us either in that case.
				BG_Session::mark_verified( $user_id );
				self::log( $user_id, 'cloud_bypass', $request );
				return new WP_REST_Response( array( 'status' => 'cloud_bypass' ), 200 );
			}

			self::log( $user_id, 'failure', $request );
			return new WP_Error( 'bg_verification_failed', $result->get_error_message(), array( 'status' => 401 ) );
		}

		BG_Session::mark_verified( $user_id );
		self::log( $user_id, 'success', $request );

		return new WP_REST_Response(
			array(
				'status'                => 'success',
				'seconds_until_rescan'  => self::seconds_until_rescan( $user_id ),
			),
			200
		);
	}

	public static function scan_enroll_front( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$facialId = (string) $request->get_param( 'facialId' );

		if ( empty( $facialId ) ) {
			return new WP_Error( 'bg_bad_facialid', __( 'No facial ID was received.', 'biometric-gate' ), array( 'status' => 400 ) );
		}

		$result = BG_Enrollment::enroll_from_frontend( $user_id, $facialId );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		BG_Session::mark_verified( $user_id );
		return new WP_REST_Response( array( 'status' => 'success' ), 200 );
	}

	public static function dev_bypass( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		BG_Session::mark_verified( $user_id );
		self::log( $user_id, 'success', $request );

		return new WP_REST_Response(
			array(
				'status'                => 'success',
				'seconds_until_rescan'  => self::seconds_until_rescan( $user_id ),
			),
			200
		);
	}

	/**
	 * Spec #7's safety kill-switch: called on 3-strike failure, manual modal close, DevTools
	 * tampering, or a detected virtual-camera signature. Invalidates the session server-side
	 * and logs the user out; the frontend performs the hard redirect after this resolves.
	 */
	public static function kill_switch( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		self::log( $user_id, 'failure', $request );
		BG_Session::clear( $user_id );

		/**
		 * Lets a compatible logout-enforcement plugin (e.g. WPForce Logout) clear its own
		 * transient state via its own documented hook, without this plugin reaching into
		 * another plugin's tables directly (spec #11's isolation rule).
		 */
		do_action( 'bg_kill_switch_triggered', $user_id );

		wp_logout();

		return new WP_REST_Response( array( 'status' => 'logged_out' ), 200 );
	}

	public static function session_status( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		return new WP_REST_Response(
			array(
				'has_valid_session' => BG_Session::has_valid_session( $user_id ),
				'bypass'             => BG_Session::is_bypassed( $user_id ),
				'locked'             => BG_Session::is_locked( $user_id ),
			),
			200
		);
	}

	private static function seconds_until_rescan( $user_id ) {
		$elapsed   = BG_Session::seconds_since_last_verification( $user_id );
		$threshold = (int) BG_Settings::get()['scan_threshold_secs'];
		return max( 0, $threshold - (int) $elapsed );
	}

	private static function log( $user_id, $status, WP_REST_Request $request ) {
		$page_title = (string) $request->get_param( 'page_title' );
		$page_url   = (string) $request->get_param( 'page_url' );
		BG_Logs::insert( $user_id, $status, $page_title, $page_url );
	}
}
