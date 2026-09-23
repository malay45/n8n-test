<?php
/**
 * Conditionally enqueues the frontend gate script/styles — only for logged-in users on an
 * admin-configured protected path, and only while the master gate switch is on (spec #1: no
 * hardcoded course paths, no wasted asset loads on public/checkout pages).
 */

defined( 'ABSPATH' ) || exit;

class BG_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	public static function maybe_enqueue() {
		if ( is_admin() || ! BG_Settings::is_gate_enabled() || ! is_user_logged_in() ) {
			return;
		}

		if ( ! BG_Route_Matcher::path_is_protected( BG_Route_Matcher::current_path() ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( BG_Session::is_bypassed( $user_id ) ) {
			return;
		}

		$css_path = BG_PLUGIN_DIR . 'public/css/bg-gate.css';
		$js_path  = BG_PLUGIN_DIR . 'public/js/bg-gate.js';

		wp_enqueue_style(
			'bg-gate',
			BG_PLUGIN_URL . 'public/css/bg-gate.css',
			array(),
			file_exists( $css_path ) ? filemtime( $css_path ) : BG_PLUGIN_VERSION
		);

		if ( empty( BG_Settings::get()['disable_frontend_js'] ) ) {
			wp_enqueue_script(
				'bg-gate',
				BG_PLUGIN_URL . 'public/js/bg-gate.js',
				array(),
				file_exists( $js_path ) ? filemtime( $js_path ) : BG_PLUGIN_VERSION,
				true
			);
		}

		$settings = BG_Settings::get();

		wp_localize_script(
			'bg-gate',
			'BiometricGateConfig',
			array(
				'restUrl'          => esc_url_raw( rest_url( BG_REST_NAMESPACE ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'hasValidSession'  => BG_Session::is_within_guard_window( $user_id ),
				'hasEnrollment'    => BG_Enrollment::has_enrollment( $user_id ),
				'pageTitle'        => wp_strip_all_tags( get_the_title() ? get_the_title() : wp_get_document_title() ),
				'pageUrl'          => home_url( BG_Route_Matcher::current_path() ),
				'scanThresholdSec' => (int) $settings['scan_threshold_secs'],
				'guardWindowSec'   => (int) $settings['guard_window_secs'],
				'noCameraMessage'  => wp_kses_post( $settings['no_camera_message'] ),
				'isAdmin'          => current_user_can( 'manage_options' ),

				// Only whether each anti-cheat check should even run client-side (gates whether
				// the JS attaches that check at all). The actual logout-vs-redirect action for
				// whichever one fires is resolved server-side from the same Tab B settings, in
				// the /session/killswitch response — one source of truth, never duplicated here.
				'killSwitchesEnabled' => array(
					'virtualCamera' => BG_Settings::is_kill_switch_enabled( 'virtual_camera' ),
					'domTamper'     => BG_Settings::is_kill_switch_enabled( 'dom_tamper' ),
					'devtools'      => BG_Settings::is_kill_switch_enabled( 'devtools' ),
				),

				'blockDevtoolsShortcuts' => (bool) $settings['block_devtools_shortcuts'],
				'blockedKeysCustom'      => wp_parse_list( $settings['blocked_keys_custom'] ),
				'closeButtonRedirectUrl' => esc_url_raw( $settings['close_button_redirect_url'] ),

				'i18n'             => array(
					'startScan'      => __( 'Start Face Scan', 'biometric-gate' ),
					'verifying'      => __( 'Verifying your identity…', 'biometric-gate' ),
					'scanFailed'     => __( 'We could not verify your identity. Please try again.', 'biometric-gate' ),
					'noCamera'       => __( 'No webcam was detected on this device.', 'biometric-gate' ),
					'noEnrollment'   => __( 'No biometric profile is on file. Contact your administrator.', 'biometric-gate' ),
					'connectionLost' => __( 'Your internet connection appears to be offline. Please reconnect to continue.', 'biometric-gate' ),
					'tooDark'        => __( 'Environment Too Dark. Please turn on a light to continue.', 'biometric-gate' ),
					'tooBright'      => __( 'Too much light/glare detected. Please reduce backlighting and try again.', 'biometric-gate' ),
					'centerFace'     => __( 'Center your profile in the frame.', 'biometric-gate' ),
					'moveCloser'     => __( 'Move closer to the lens.', 'biometric-gate' ),
					'closeConfirm'   => $settings['close_confirm_message'],
					'closeButton'    => __( 'Close', 'biometric-gate' ),
				),
			)
		);
	}
}
