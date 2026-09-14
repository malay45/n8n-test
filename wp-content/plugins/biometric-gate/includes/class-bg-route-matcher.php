<?php
/**
 * Abstract path matching against the admin-configured "Targeted Script Loading Path Rules"
 * (Tab B). Deliberately contains zero references to LearnDash post types/IDs or any other
 * plugin — matching is pure string comparison against the current request path (spec #1),
 * so this plugin stays portable to any WordPress site.
 */

defined( 'ABSPATH' ) || exit;

class BG_Route_Matcher {

	/**
	 * @param string $path e.g. '/courses/algebra-101/'
	 * @return bool Whether the given path falls under an admin-configured protected rule.
	 */
	public static function path_is_protected( $path ) {
		$rules = BG_Settings::get_path_rules();

		if ( empty( $rules ) ) {
			return false; // Nothing configured yet — fail open on missing config, never on identity.
		}

		$normalized = '/' . ltrim( (string) $path, '/' );

		foreach ( $rules as $rule ) {
			$needle = '/' . ltrim( $rule, '/' );
			if ( '' !== trim( $needle, '/' ) && false !== stripos( $normalized, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve the current request's path (no query string) for matching.
	 *
	 * @return string
	 */
	public static function current_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}
}
