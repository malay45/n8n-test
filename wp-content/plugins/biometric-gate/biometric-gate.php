<?php
/**
 * Plugin Name: Biometric Gate
 * Description: Enforces live biometric face verification (via the FACEIO REST API) before rendering protected pages, with rolling re-verification, a 3-tab admin dashboard, and scale-ready REST logging for up to 10,000 concurrent users.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Requires at least: 5.9
 * License: GPLv2 or later
 * Text Domain: biometric-gate
 *
 * Portability note: this plugin does not reference LearnDash, PrestoPlayer, or any other
 * specific plugin's classes, hooks, or database IDs anywhere in its code. It targets generic
 * WordPress APIs, global JS media/DOM selectors, and admin-configured path strings only, so
 * it can be exported and installed on any separate WordPress site (spec #11).
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// ULTRA-FAST HEARTBEAT INTERCEPT (Sub-50ms)
// Placed at the absolute top of the plugin execution flow to bypass loading 
// any subsequent plugins, themes, or the heavy WordPress REST API core.
// ============================================================================
if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
	$bg_is_status = false;
	if ( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], 'biometric-gate/v1/session/status' ) !== false ) {
		$bg_is_status = true;
	} elseif ( isset( $_GET['rest_route'] ) && strpos( $_GET['rest_route'], 'biometric-gate/v1/session/status' ) !== false ) {
		$bg_is_status = true;
	}

	if ( $bg_is_status ) {
		// We need pluggable for auth checks
		if ( ! function_exists( 'is_user_logged_in' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			status_header( 403 );
			die( 'Forbidden' );
		}

		if ( ! is_user_logged_in() ) {
			status_header( 401 );
			die( 'Unauthorized' );
		}

		require_once plugin_dir_path( __FILE__ ) . 'includes/class-bg-session.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-bg-settings.php';
		
		$user_id = get_current_user_id();
		$valid   = BG_Session::has_valid_session( $user_id ) ? '1' : '0';
		$bypass  = BG_Session::is_bypassed( $user_id ) ? '1' : '0';
		$locked  = BG_Session::is_locked( $user_id ) ? '1' : '0';

		header( 'Content-Type: text/plain; charset=utf-8' );
		die( "{$valid}|{$bypass}|{$locked}" );
	}
}

define( 'BG_PLUGIN_FILE', __FILE__ );
define( 'BG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BG_PLUGIN_VERSION', '0.1.0' );
define( 'BG_BACKUP_DIR', WP_CONTENT_DIR . '/biometric-gate-backups' );
define( 'BG_TMP_DIR', WP_CONTENT_DIR . '/biometric-gate-tmp' );
define( 'BG_REST_NAMESPACE', 'biometric-gate/v1' );

require_once BG_PLUGIN_DIR . 'includes/class-bg-crypto.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-settings.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-route-matcher.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-activator.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-session.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-logs.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-enrollment.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-verification.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-rest-controller.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-admin-rest-controller.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-content-guard.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-cache-compat.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-frontend.php';
require_once BG_PLUGIN_DIR . 'includes/class-bg-admin-page.php';

register_activation_hook( BG_PLUGIN_FILE, array( 'BG_Activator', 'activate' ) );
register_deactivation_hook( BG_PLUGIN_FILE, array( 'BG_Activator', 'deactivate' ) );

add_filter( 'cron_schedules', array( 'BG_Activator', 'register_cron_schedules' ) );
add_action( 'admin_init', array( 'BG_Activator', 'maybe_upgrade' ) );

BG_Session::init();
BG_Logs::init();
BG_Rest_Controller::init();
BG_Admin_Rest_Controller::init();
BG_Content_Guard::init();
BG_Cache_Compat::init();
BG_Frontend::init();
BG_Admin_Page::init();
