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
