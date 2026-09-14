<?php
/**
 * Server-side "deny-by-default" gate (spec #11). On a protected path with no currently-valid
 * server-confirmed session, this intercepts before the real template ever renders and sends a
 * minimal, content-free HTML shell instead — no post title, body, shortcodes, or video/iframe
 * markup is transmitted. A student who disables JavaScript or blocks the front-end overlay
 * therefore only ever sees an empty locked container, never the protected content underneath.
 *
 * Once a valid session exists, this gets out of the way entirely and the real template
 * renders normally; the *ongoing* rolling-window re-check (Trigger B) is then a client-side
 * overlay/blur over already-delivered content, per spec #4 — that half of the design is an
 * accepted, spec-directed trade-off (a page already open with a valid session necessarily has
 * its DOM in the browser already), not a gap in this guard.
 */

defined( 'ABSPATH' ) || exit;

class BG_Content_Guard {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_block' ), 0 );
	}

	public static function maybe_block() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! BG_Settings::is_gate_enabled() || ! is_user_logged_in() ) {
			return;
		}

		if ( ! BG_Route_Matcher::path_is_protected( BG_Route_Matcher::current_path() ) ) {
			return;
		}

		$user_id = get_current_user_id();

		// The guard window (not the longer scan threshold) governs whether a *fresh page
		// request* needs a new scan (spec #3's Trigger A: "repetitive page entry scans" are
		// what the guard window skips). The scan threshold instead paces the ongoing
		// client-side interval loop on a page that's already open — see BG_Frontend.
		if ( BG_Session::is_bypassed( $user_id ) || BG_Session::is_within_guard_window( $user_id ) ) {
			return;
		}

		if ( BG_Session::is_locked( $user_id ) ) {
			self::render_shell( 'locked' );
			exit;
		}

		if ( ! BG_Enrollment::has_enrollment( $user_id ) ) {
			self::render_shell( 'not-enrolled' );
			exit;
		}

		self::render_shell( 'verify' );
		exit;
	}

	/**
	 * Hand-rolled minimal document (rather than get_header()/get_footer()) so no theme
	 * sidebar, breadcrumb, or "related lessons" widget can incidentally leak information
	 * about the protected content the student hasn't been cleared to see yet.
	 *
	 * @param string $mode 'verify' | 'not-enrolled' | 'locked'
	 */
	private static function render_shell( $mode ) {
		status_header( 200 );
		nocache_headers();

		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<?php wp_head(); ?>
</head>
<body class="bg-gate-locked-body">
<div id="bg-gate-root" data-bg-mode="<?php echo esc_attr( $mode ); ?>">
	<noscript>
		<div class="bg-gate-noscript-message">
			<?php echo wp_kses_post( self::message_for( $mode ) ); ?>
		</div>
	</noscript>
</div>
<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	private static function message_for( $mode ) {
		switch ( $mode ) {
			case 'locked':
				return __( 'This account is locked pending administrator review.', 'biometric-gate' );
			case 'not-enrolled':
				return __( 'No biometric profile is on file for your account yet. Please contact your administrator.', 'biometric-gate' );
			default:
				return __( 'Identity verification is required to continue. Please enable JavaScript and a webcam to proceed.', 'biometric-gate' );
		}
	}
}
