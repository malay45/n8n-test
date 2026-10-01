<?php
/**
 * Cache-bypass integration (spec #10). Rather than depending on any single cache plugin's
 * proprietary API — which would break portability the moment this plugin lands on a site
 * without Breeze/Object Cache Pro — this uses the two most universal WordPress conventions:
 * explicit no-store HTTP headers on our own REST responses, and the de facto DONOTCACHEPAGE
 * constant that Breeze, WP Rocket, W3 Total Cache, and most other full-page cache plugins
 * all honor for content that must never be served from a shared page cache.
 */

defined( 'ABSPATH' ) || exit;

class BG_Cache_Compat {

	public static function init() {
		add_filter( 'rest_pre_echo_response', array( __CLASS__, 'no_store_rest_responses' ), 9999, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_disable_page_cache' ), 1 );
		
		// Proactively scrub global admin-ajax.php requests as well, since caching plugins often 
		// leak duplicate s-maxage rules there, affecting video progress tracking for other plugins.
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			add_action( 'admin_init', array( __CLASS__, 'scrub_ajax_headers' ), 999 );
		}
	}

	/**
	 * Globally scrub duplicate Cache-Control headers from admin-ajax.php responses.
	 */
	public static function scrub_ajax_headers() {
		if ( headers_sent() ) {
			return;
		}
		$headers = headers_list();
		foreach ( $headers as $header ) {
			if ( stripos( $header, 'Cache-Control' ) === 0 || stripos( $header, 'Pragma' ) === 0 || stripos( $header, 'Expires' ) === 0 ) {
				$parts = explode( ':', $header, 2 );
				header_remove( trim( $parts[0] ) );
			}
		}
		// Restore WordPress core's exact intended ajax headers, but strictly without duplicates
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private', true );
		header( 'Pragma: no-cache', true );
		header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );
		header( 'X-Accel-Expires: 0', true );
	}

	/**
	 * @param array            $response_data
	 * @param WP_REST_Server   $server
	 * @param WP_REST_Request  $request
	 * @return array
	 */
	public static function no_store_rest_responses( $response_data, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . BG_REST_NAMESPACE ) && ! headers_sent() ) {
			// Forcefully clear the entire pre-queued header array right before outputting the final stream.
			// This native PHP array loop scrubs out any trailing or appended 'Cache-Control' keys
			// injected by Nginx proxies, WP Rocket, or Breeze before we set our definitive rule.
			$headers = headers_list();
			foreach ( $headers as $header ) {
				if ( stripos( $header, 'Cache-Control' ) === 0 || stripos( $header, 'Pragma' ) === 0 || stripos( $header, 'Expires' ) === 0 ) {
					$parts = explode( ':', $header, 2 );
					header_remove( trim( $parts[0] ) );
				}
			}

			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // Tell Breeze/Cloudways Varnish not to forcefully strip our headers.
			}

			// Output one clean, single Cache-Control header with no duplicates
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0, s-maxage=0', true );
			header( 'Pragma: no-cache', true );
			header( 'Expires: Thu, 01 Jan 1970 00:00:00 GMT', true );
			header( 'X-Accel-Expires: 0', true ); // Nginx proxy cache.
			header( 'Surrogate-Control: no-store', true ); // Varnish / CDN edge caches.
		}
		return $response_data;
	}

	/**
	 * Protected-path responses are inherently per-user/session state and must never be served
	 * from a shared page cache, regardless of which caching plugin (or none) is active.
	 */
	public static function maybe_disable_page_cache() {
		if ( ! BG_Route_Matcher::path_is_protected( BG_Route_Matcher::current_path() ) ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
	}
}
